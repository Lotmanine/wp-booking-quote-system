<?php
/**
 * Booking state machine.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * The only code that changes bookings.state.
 */
final class StateMachine {

	/**
	 * Build the machine.
	 *
	 * @param Outbox $outbox Event outbox.
	 */
	public function __construct( private Outbox $outbox ) {
	}

	/**
	 * Move a booking between states.
	 *
	 * The conditional UPDATE makes this safe under concurrency: exactly one caller changes the
	 * row, and a false return means another request got there first.
	 *
	 * @param int          $booking_id Booking id.
	 * @param BookingState $from       Expected current state.
	 * @param BookingState $to         Target state.
	 * @param string       $actor      Who asked.
	 * @param string       $reason     Optional note for the history.
	 * @throws IllegalTransition When the table does not allow the move.
	 */
	public function transition( int $booking_id, BookingState $from, BookingState $to, string $actor, string $reason = '' ): bool {
		global $wpdb;

		if ( ! $from->can_go_to( $to ) ) {
			throw new IllegalTransition( 'Illegal transition from ' . esc_html( $from->value ) . ' to ' . esc_html( $to->value ) );
		}

		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET state = %s, version = version + 1 WHERE id = %d AND state = %s',
				Db::table( 'bookings' ),
				$to->value,
				$booking_id,
				$from->value
			)
		);
		if ( 1 !== $rows ) {
			return false;
		}

		$wpdb->insert(
			Db::table( 'booking_history' ),
			array(
				'booking_id' => $booking_id,
				'from_state' => $from->value,
				'to_state'   => $to->value,
				'actor'      => substr( $actor, 0, 40 ),
				'reason'     => substr( $reason, 0, 190 ),
				'created_at' => Db::now(),
			)
		);

		$event = ( BookingState::Hold === $from && BookingState::Quote === $to ) ? 'booking.hold_expired' : 'booking.' . $to->value;
		$this->outbox->record( $event, $booking_id );

		/**
		 * Fires after a booking changes state.
		 *
		 * @param int          $booking_id Booking id.
		 * @param BookingState $from       Previous state.
		 * @param BookingState $to         New state.
		 */
		do_action( 'quillmoor_booking_transitioned', $booking_id, $from, $to );

		return true;
	}
}
