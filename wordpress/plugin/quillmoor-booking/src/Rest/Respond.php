<?php
/**
 * REST helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Payment\Refunds;
use Quillmoor\Booking\Security\Actor;
use Quillmoor\Booking\Security\StripeKeyGuard;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Shared request and response handling.
 */
final class Respond {

	/**
	 * Build an error response.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Safe message.
	 * @param int    $status  HTTP status.
	 */
	public static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Convert a service failure.
	 *
	 * @param BookingError $error Failure.
	 */
	public static function from( BookingError $error ): WP_Error {
		return self::error( $error->error_code, $error->getMessage(), $error->status );
	}

	/**
	 * Every booking route closes while a live key is present.
	 */
	public static function live_key_block(): ?WP_Error {
		return StripeKeyGuard::is_blocked()
			? self::error( 'payments_refused', 'Booking is off: a live payment key was refused.', 503 )
			: null;
	}

	/**
	 * Identify the requester.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function actor( WP_REST_Request $request ): Actor {
		$token = (string) $request->get_header( 'x_qhs_token' );
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		return new Actor( get_current_user_id(), sanitize_text_field( $token ) );
	}

	/**
	 * Load a booking the actor may read. Missing and forbidden look identical, so ids cannot be probed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function viewable( WP_REST_Request $request ): array|WP_Error {
		$booking = Services::bookings()->find( (int) $request['id'] );
		if ( null === $booking || ! Services::policy()->can_view( self::actor( $request ), $booking ) ) {
			return self::error( 'not_found', 'Booking not found.', 404 );
		}
		return $booking;
	}

	/**
	 * Load a booking the actor may change.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param int             $booking_id Booking id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function viewable_for_change( WP_REST_Request $request, int $booking_id ): array|WP_Error {
		$booking = Services::bookings()->find( $booking_id );
		if ( null === $booking || ! Services::policy()->can_change( self::actor( $request ), $booking ) ) {
			return self::error( 'not_found', 'Booking not found.', 404 );
		}
		return $booking;
	}

	/**
	 * Price breakdown as the card shows it.
	 *
	 * @param array{line_items:array<int,array{label:string,cents:int}>,total_cents:int,deposit_cents:int,nights:int} $price Price.
	 * @return array<string,mixed>
	 */
	public static function price( array $price ): array {
		return array(
			'lines'         => array_map(
				static fn( array $line ): array => array(
					'label'  => $line['label'],
					'amount' => Pricing::money( $line['cents'] ),
				),
				$price['line_items']
			),
			'total'         => Pricing::money( $price['total_cents'] ),
			'deposit'       => Pricing::money( $price['deposit_cents'] ),
			'balance'       => Pricing::money( max( 0, $price['total_cents'] - $price['deposit_cents'] ) ),
			'total_cents'   => $price['total_cents'],
			'deposit_cents' => $price['deposit_cents'],
			'nights'        => $price['nights'],
		);
	}

	/**
	 * Public view of a booking. Internal keys never leave the server, and the street address is
	 * shared only once the booking is confirmed.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @return array<string,mixed>
	 */
	public static function booking( array $booking ): array {
		$quote   = Services::bookings()->quote( (int) $booking['quote_id'] );
		$state   = BookingState::from( (string) $booking['state'] );
		$listing = Listing::find( (int) $booking['listing_id'] );
		$photo   = null === $listing ? array() : $listing->photo_list( 'medium_large' );
		$lines   = json_decode( (string) ( $quote['line_items'] ?? '[]' ), true );
		$host    = null === $listing ? false : get_userdata( $listing->host_id );
		$known   = in_array( $state, array( BookingState::Confirmed, BookingState::Arrived, BookingState::Completed ), true );
		$total   = (int) ( $quote['total_cents'] ?? 0 );
		$deposit = (int) ( $quote['deposit_cents'] ?? 0 );

		return array(
			'id'              => (int) $booking['id'],
			'ref'             => BookingView::ref( $booking ),
			'state'           => $state->value,
			'state_label'     => BookingView::state_label( $booking ),
			'type'            => (string) $booking['listing_type'],
			'listing'         => array(
				'id'     => (int) $booking['listing_id'],
				'title'  => BookingView::listing_title( $booking ),
				'url'    => null === $listing ? '' : $listing->url(),
				'photo'  => $photo[0]['url'] ?? '',
				'kind'   => null === $listing ? '' : $listing->kind_label(),
				'policy' => null === $listing ? '' : $listing->cancellation_text(),
			),
			'host_name'       => $host ? $host->display_name : '',
			'address'         => $known && null !== $listing ? $listing->address : '',
			'when'            => BookingView::when( $booking ),
			'check_in'        => $booking['check_in'],
			'check_out'       => $booking['check_out'],
			'slot_start'      => $booking['slot_start'],
			'guests'          => (int) $booking['guests'],
			'hold_expires_at' => $booking['hold_expires_at'],
			'total'           => Pricing::money( $total ),
			'deposit'         => Pricing::money( $deposit ),
			'balance'         => Pricing::money( max( 0, $total - $deposit ) ),
			'paid'            => (int) $booking['order_id'] > 0,
			'refund'          => BookingView::refund_label( $booking ),
			'line_items'      => array_map(
				static fn( array $line ): array => array(
					'label'  => (string) $line['label'],
					'amount' => Pricing::money( (int) $line['cents'] ),
				),
				is_array( $lines ) ? array_filter( $lines, 'is_array' ) : array()
			),
			'can_change'      => in_array( $state, array( BookingState::Requested, BookingState::Confirmed ), true ),
			'can_cancel'      => $state->can_go_to( BookingState::Cancelled ) && BookingState::Quote !== $state,
			'refundable'      => Refunds::is_refundable( $booking ),
		);
	}
}
