<?php
/**
 * Event outbox.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Domain\BookingRepository;
use Quillmoor\Booking\Security\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Stores events beside the state change and delivers them to n8n with retries.
 */
final class Outbox {

	public const HOOK         = 'qhs_deliver_outbox';
	public const MAX_ATTEMPTS = 6;

	/**
	 * Build the outbox.
	 *
	 * @param BookingRepository $bookings Bookings.
	 * @param Signer            $signer   Message signer.
	 */
	public function __construct( private BookingRepository $bookings, private Signer $signer ) {
	}

	/**
	 * Record an event and schedule delivery.
	 *
	 * @param string $event      Event name.
	 * @param int    $booking_id Booking id.
	 * @param string $target     Delivery target: "events" or "triage".
	 */
	public function record( string $event, int $booking_id, string $target = 'events' ): void {
		global $wpdb;

		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			return;
		}

		$payload = 'triage' === $target
			? array(
				'booking_id' => $booking_id,
				'note'       => (string) $booking['customer_note'],
			)
			: array(
				'event'      => $event,
				'booking_id' => $booking_id,
				'ref'        => 'QM-' . $booking_id,
				'state'      => (string) $booking['state'],
				'version'    => (int) $booking['version'],
				'type'       => (string) $booking['listing_type'],
				'listing_id' => (int) $booking['listing_id'],
				'area'       => Listings\Locations::zone( (string) $booking['location'] ),
				'skill'      => 'dining' === (string) $booking['listing_type'] ? 'none' : 'cleaning',
				'dispatch'   => 'dining' !== (string) $booking['listing_type'],
				'slot_start' => $booking['slot_start'],
				'has_note'   => '' !== (string) $booking['customer_note'],
			);

		$wpdb->insert(
			Db::table( 'outbox' ),
			array(
				'event'           => $event,
				'booking_id'      => $booking_id,
				'target'          => $target,
				'payload'         => (string) wp_json_encode( $payload ),
				'next_attempt_at' => Db::now(),
				'created_at'      => Db::now(),
			)
		);

		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'qhs' );
		}
	}

	/**
	 * Deliver due events. Runs from Action Scheduler.
	 *
	 * @param int $limit Batch size.
	 * @return int Events delivered.
	 */
	public function deliver_due( int $limit = 25 ): int {
		global $wpdb;
		$table = Db::table( 'outbox' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d',
				$table,
				Db::now(),
				$limit
			),
			ARRAY_A
		);

		$delivered = 0;
		foreach ( $rows as $row ) {
			$url = 'triage' === $row['target'] ? Config::get( 'QHS_N8N_TRIAGE_URL' ) : Config::get( 'QHS_N8N_EVENTS_URL' );
			if ( '' === $url ) {
				continue;
			}

			$payload             = (array) json_decode( (string) $row['payload'], true );
			$payload['event_id'] = (int) $row['id'];
			$result              = $this->post( $url, $payload );

			if ( true === $result ) {
				$wpdb->update( $table, array( 'delivered_at' => Db::now() ), array( 'id' => (int) $row['id'] ) );
				++$delivered;
				continue;
			}

			$attempts = (int) $row['attempts'] + 1;
			$update   = array(
				'attempts'        => $attempts,
				'last_error'      => substr( $result, 0, 190 ),
				'next_attempt_at' => Db::now( 60 * ( 2 ** $attempts ) ),
			);
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$update['failed_at'] = Db::now();
				Alerts::log( 'outbox', 'Event ' . $row['event'] . ' for booking ' . $row['booking_id'] . ' failed after ' . $attempts . ' attempts.' );
			}
			$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) );
		}

		$this->schedule_next();
		return $delivered;
	}

	/**
	 * Pending count, for the admin screen.
	 */
	public function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE delivered_at IS NULL AND failed_at IS NULL', Db::table( 'outbox' ) )
		);
	}

	/**
	 * Find an event by id.
	 *
	 * @param int $id Event id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'outbox' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Claim the CRM sync of an event once, so repeated callbacks are harmless.
	 *
	 * @param int $id Event id.
	 */
	public function claim_crm_sync( int $id ): bool {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET crm_synced_at = %s WHERE id = %d AND crm_synced_at IS NULL', Db::table( 'outbox' ), Db::now(), $id )
		);
		return 1 === $rows;
	}

	/**
	 * Send one signed event.
	 *
	 * @param string              $url     Webhook URL.
	 * @param array<string,mixed> $payload Event payload.
	 * @return true|string True on success, an error string otherwise.
	 */
	private function post( string $url, array $payload ): true|string {
		$signed   = $this->signer->sign( 'n8n', (string) wp_json_encode( $payload ) );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => $signed['headers'] + array( 'Content-Type' => 'application/json' ),
				'body'    => $signed['body'],
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return ( $code >= 200 && $code < 300 ) ? true : 'HTTP ' . $code;
	}

	/**
	 * Schedule the next pass when work remains.
	 */
	private function schedule_next(): void {
		global $wpdb;
		$next = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MIN(next_attempt_at) FROM %i WHERE delivered_at IS NULL AND failed_at IS NULL', Db::table( 'outbox' ) )
		);
		if ( null !== $next && function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::HOOK ) ) {
			as_schedule_single_action( max( time() + 30, (int) strtotime( $next . ' UTC' ) ), self::HOOK, array(), 'qhs' );
		}
	}
}
