<?php
/**
 * REST helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Domain\BookingState;
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
	 * Public view of a booking. Internal keys never leave the server.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @return array<string,mixed>
	 */
	public static function booking( array $booking ): array {
		$quote = Services::bookings()->quote( (int) $booking['quote_id'] );
		$state = BookingState::from( (string) $booking['state'] );
		$staff = (int) $booking['staff_user_id'] > 0 ? get_userdata( (int) $booking['staff_user_id'] ) : false;
		$slot  = BookingState::Quote === $state ? null : $booking['slot_start'];

		return array(
			'id'              => (int) $booking['id'],
			'ref'             => 'QHS-' . $booking['id'],
			'state'           => $state->value,
			'state_label'     => $state->label(),
			'service'         => Catalog::label( 'services', (string) $booking['service'] ),
			'area'            => Catalog::label( 'areas', (string) $booking['area'] ),
			'area_key'        => (string) $booking['area'],
			'slot_start'      => $slot,
			'hold_expires_at' => $booking['hold_expires_at'],
			'job_status'      => (string) $booking['job_status'],
			'priority'        => (string) $booking['priority'],
			'staff_name'      => $staff ? $staff->display_name : '',
			'total'           => Catalog::money( (int) ( $quote['total_cents'] ?? 0 ) ),
			'deposit'         => Catalog::money( (int) ( $quote['deposit_cents'] ?? 0 ) ),
			'line_items'      => json_decode( (string) ( $quote['line_items'] ?? '[]' ), true ),
			'can_change'      => in_array( $state, array( BookingState::Confirmed, BookingState::Assigned ), true ),
			'can_cancel'      => $state->can_go_to( BookingState::Cancelled ),
		);
	}
}
