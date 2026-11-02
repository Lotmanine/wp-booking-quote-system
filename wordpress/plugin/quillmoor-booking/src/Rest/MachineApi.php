<?php
/**
 * Signed machine endpoints.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Callbacks from n8n and the dispatch service. Handlers only run after the envelope verifies.
 */
final class MachineApi {

	public const PRIORITIES = array( 'low', 'normal', 'high', 'urgent' );

	/**
	 * Verify an n8n message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function verify_n8n( WP_REST_Request $request ): bool|WP_Error {
		return self::verify( $request, array( 'n8n' ) );
	}

	/**
	 * Verify a dispatch message.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function verify_dispatch( WP_REST_Request $request ): bool|WP_Error {
		return self::verify( $request, array( 'dispatch' ) );
	}

	/**
	 * Check the envelope.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string[]        $allowed Accepted key ids.
	 */
	private static function verify( WP_REST_Request $request, array $allowed ): bool|WP_Error {
		$payload = $request->get_param( 'payload' );
		$valid   = is_string( $payload ) && Services::signer()->verify(
			(string) $request->get_header( 'x_qhs_key_id' ),
			(string) $request->get_header( 'x_qhs_timestamp' ),
			(string) $request->get_header( 'x_qhs_nonce' ),
			(string) $request->get_header( 'x_qhs_signature' ),
			$payload,
			$allowed
		);
		return $valid ? true : Respond::error( 'bad_signature', 'Signature check failed.', 401 );
	}

	/**
	 * Decode the signed payload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private static function payload( WP_REST_Request $request ): array {
		$data = json_decode( (string) $request->get_param( 'payload' ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Apply a dispatch decision.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function decision( WP_REST_Request $request ): WP_REST_Response {
		$data   = self::payload( $request );
		$result = Services::service()->apply_dispatch_decision(
			(int) ( $data['booking_id'] ?? 0 ),
			(int) ( $data['version'] ?? 0 ),
			(string) ( $data['outcome'] ?? '' ),
			(string) ( $data['staff_ref'] ?? '' ),
			(string) ( $data['reason'] ?? '' )
		);
		return new WP_REST_Response( array( 'result' => $result ) );
	}

	/**
	 * Update the CRM for one booking event, once.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function crm_sync( WP_REST_Request $request ): WP_REST_Response {
		$data     = self::payload( $request );
		$event_id = (int) ( $data['event_id'] ?? 0 );
		$event    = Services::outbox()->find( $event_id );
		if ( null === $event ) {
			return new WP_REST_Response( array( 'result' => 'unknown_event' ), 404 );
		}
		if ( ! Services::outbox()->claim_crm_sync( $event_id ) ) {
			return new WP_REST_Response( array( 'result' => 'already_synced' ) );
		}
		$done = Services::pipeline()->apply( (string) $event['event'], (int) $event['booking_id'] );
		return new WP_REST_Response( array( 'result' => $done ? 'synced' : 'skipped' ) );
	}

	/**
	 * Send due reminders.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function reminders( WP_REST_Request $request ): WP_REST_Response {
		$data  = self::payload( $request );
		$hours = max( 1, min( 168, (int) ( $data['hours_ahead'] ?? 24 ) ) );
		return new WP_REST_Response( Services::service()->run_reminders( $hours ) );
	}

	/**
	 * Send due follow-ups.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function followups( WP_REST_Request $request ): WP_REST_Response {
		$data  = self::payload( $request );
		$hours = max( 0, min( 168, (int) ( $data['hours_after'] ?? 2 ) ) );
		return new WP_REST_Response( Services::service()->run_followups( $hours ) );
	}

	/**
	 * Store an AI suggestion for staff review. This never touches the booking row.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function triage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$data     = self::payload( $request );
		$booking  = Services::bookings()->find( (int) ( $data['booking_id'] ?? 0 ) );
		$priority = (string) ( $data['priority'] ?? '' );
		if ( null === $booking ) {
			return Respond::error( 'not_found', 'Booking not found.', 404 );
		}
		if ( ! in_array( $priority, self::PRIORITIES, true ) ) {
			return Respond::error( 'invalid_priority', 'Unknown priority.', 400 );
		}
		$wpdb->insert(
			Db::table( 'triage' ),
			array(
				'booking_id'         => (int) $booking['id'],
				'suggested_priority' => $priority,
				'reason'             => mb_substr( sanitize_text_field( (string) ( $data['reason'] ?? '' ) ), 0, 280 ),
				'source'             => 'ai' === ( $data['source'] ?? '' ) ? 'ai' : 'fallback',
				'created_at'         => Db::now(),
			)
		);
		return new WP_REST_Response( array( 'result' => 'stored' ), 201 );
	}

	/**
	 * Store an alert from a workflow.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function alert( WP_REST_Request $request ): WP_REST_Response {
		$data = self::payload( $request );
		Alerts::log( (string) ( $data['source'] ?? 'n8n' ), (string) ( $data['message'] ?? '' ) );
		return new WP_REST_Response( array( 'result' => 'stored' ), 201 );
	}
}
