<?php
/**
 * Staff portal endpoints.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Staff see and change only the jobs assigned to them.
 */
final class StaffApi {

	/**
	 * Route-level capability check.
	 */
	public static function can_work(): bool {
		return current_user_can( BookingPolicy::CAP_WORK ) || current_user_can( BookingPolicy::CAP_MANAGE );
	}

	/**
	 * The signed-in staff member's jobs.
	 */
	public static function schedule(): WP_REST_Response {
		$rows = Services::bookings()->for_staff( get_current_user_id() );
		$jobs = array();
		foreach ( $rows as $row ) {
			$customer = Services::bookings()->customer( (int) $row['customer_id'] );
			$jobs[]   = Respond::booking( $row ) + array(
				'customer_name' => (string) ( $customer['full_name'] ?? '' ),
				'customer_note' => (string) $row['customer_note'],
				'triage'        => self::pending_triage( (int) $row['id'] ),
			);
		}
		return new WP_REST_Response( array( 'jobs' => $jobs ) );
	}

	/**
	 * Update job progress.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = self::workable( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		try {
			$updated = Services::service()->update_job( (int) $booking['id'], sanitize_key( (string) $request->get_param( 'status' ) ), 'user:' . get_current_user_id() );
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}
		return new WP_REST_Response( Respond::booking( $updated ) );
	}

	/**
	 * Accept or dismiss an AI suggestion. Only this human step changes the priority.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function triage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = self::workable( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		$pending = self::pending_triage( (int) $booking['id'] );
		if ( null === $pending ) {
			return Respond::error( 'no_suggestion', 'No suggestion is waiting.', 404 );
		}
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );
		if ( ! in_array( $decision, array( 'accepted', 'dismissed' ), true ) ) {
			return Respond::error( 'invalid_decision', 'Unknown decision.', 400 );
		}
		self::review( (int) $pending['id'], (int) $booking['id'], $decision, (string) $pending['suggested_priority'], get_current_user_id() );
		return new WP_REST_Response( array( 'status' => $decision ) );
	}

	/**
	 * Record the review of a suggestion.
	 *
	 * @param int    $triage_id  Suggestion id.
	 * @param int    $booking_id Booking id.
	 * @param string $decision   Either "accepted" or "dismissed".
	 * @param string $priority   Suggested priority.
	 * @param int    $user_id    Reviewer.
	 */
	public static function review( int $triage_id, int $booking_id, string $decision, string $priority, int $user_id ): void {
		global $wpdb;
		$wpdb->update(
			Db::table( 'triage' ),
			array(
				'status'      => $decision,
				'reviewed_by' => $user_id,
			),
			array( 'id' => $triage_id )
		);
		if ( 'accepted' === $decision ) {
			Services::bookings()->update( $booking_id, array( 'priority' => $priority ) );
		}
	}

	/**
	 * Latest pending suggestion for a booking.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>|null
	 */
	public static function pending_triage( int $booking_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, suggested_priority, reason, source FROM %i WHERE booking_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
				Db::table( 'triage' ),
				$booking_id,
				'pending'
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Load a booking the staff member may work on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function workable( WP_REST_Request $request ): array|WP_Error {
		$booking = Services::bookings()->find( (int) $request['id'] );
		if ( null === $booking || ! Services::policy()->can_work( Respond::actor( $request ), $booking ) ) {
			return Respond::error( 'not_found', 'Job not found.', 404 );
		}
		return $booking;
	}
}
