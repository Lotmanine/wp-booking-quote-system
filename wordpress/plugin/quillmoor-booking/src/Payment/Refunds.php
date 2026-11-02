<?php
/**
 * Deposit refunds.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Domain\BookingRepository;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Security\StripeKeyGuard;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Refunds go back through the gateway that took the deposit: Stripe in test mode, or the labelled
 * fake gateway in local tests. Only a booking that no longer holds nights or seats can be refunded.
 */
final class Refunds {

	/**
	 * Build the service.
	 *
	 * @param BookingRepository $bookings Bookings.
	 */
	public function __construct( private BookingRepository $bookings ) {
	}

	/**
	 * Whether a refund button makes sense for this booking.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function is_refundable( array $booking ): bool {
		$state = BookingState::from( (string) $booking['state'] );
		return (int) $booking['order_id'] > 0
			&& 'refunded' !== (string) $booking['refund_status']
			&& in_array( $state, array( BookingState::Cancelled, BookingState::Declined, BookingState::NoShow ), true );
	}

	/**
	 * Refund what is left of the deposit.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError When the refund is not possible or the gateway refuses it.
	 */
	public function refund( int $booking_id, string $actor ): array {
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			throw new BookingError( 'not_found', 'Booking not found.', 404 );
		}
		if ( (int) $booking['order_id'] < 1 ) {
			throw new BookingError( 'no_payment', 'There is no payment on this booking.' );
		}
		if ( 'refunded' === (string) $booking['refund_status'] ) {
			throw new BookingError( 'already_refunded', 'This deposit was already refunded.' );
		}
		if ( ! self::is_refundable( $booking ) ) {
			throw new BookingError( 'still_live', 'Cancel the booking before refunding the deposit.' );
		}
		if ( StripeKeyGuard::is_blocked() || ! function_exists( 'wc_create_refund' ) ) {
			throw new BookingError( 'payments_unavailable', 'Refunds are not available right now.', 503 );
		}
		$order = wc_get_order( (int) $booking['order_id'] );
		if ( ! $order instanceof WC_Order ) {
			throw new BookingError( 'no_payment', 'The payment for this booking was not found.' );
		}
		$amount = round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );
		if ( $amount <= 0 ) {
			throw new BookingError( 'already_refunded', 'This deposit was already refunded.' );
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => 'Deposit for ' . BookingView::ref( $booking ) . ', ' . BookingView::state_label( $booking ),
				'refund_payment' => true,
			)
		);
		if ( is_wp_error( $refund ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is data; every screen that shows it escapes it.
			throw new BookingError( 'refund_failed', 'The payment provider refused the refund: ' . $refund->get_error_message() );
		}

		$this->bookings->update(
			$booking_id,
			array(
				'refund_status' => 'refunded',
				'refund_cents'  => (int) round( $amount * 100 ),
				'refunded_at'   => Db::now(),
			)
		);
		$this->bookings->log( $booking_id, (string) $booking['state'], $actor, 'deposit refunded' );
		return (array) $this->bookings->find( $booking_id );
	}
}
