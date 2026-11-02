<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Outbox;
use Quillmoor\Booking\Security\Signer;
use Quillmoor\Booking\Services;

class OutboxTest extends TestCase {

	/** Stored payload of the latest event of a kind for a booking. */
	private function payload( int $booking_id, string $event ): array {
		$raw = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare( 'SELECT payload FROM %i WHERE booking_id = %d AND event = %s ORDER BY id DESC LIMIT 1', Db::table( 'outbox' ), $booking_id, $event )
		);
		$this->assertIsString( $raw );
		return (array) json_decode( $raw, true );
	}

	public function test_a_stay_event_carries_what_n8n_needs(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$payload = $this->payload( $id, 'booking.confirmed' );

		$this->assertSame(
			array(
				'event'      => 'booking.confirmed',
				'booking_id' => $id,
				'ref'        => 'QM-' . $id,
				'state'      => 'confirmed',
				'version'    => (int) $this->booking( $id )['version'],
				'type'       => 'stay',
				'listing_id' => $listing,
				'area'       => 'north',
				'skill'      => 'cleaning',
				'dispatch'   => true,
				'slot_start' => $this->day( 10 ) . ' 15:00:00',
				'has_note'   => false,
			),
			$payload
		);
	}

	public function test_a_table_event_is_not_sent_to_dispatch(): void {
		$listing = $this->dining_listing();
		$id      = $this->paid_table( $listing, $this->seating( 3 ), 2 );

		$payload = $this->payload( $id, 'booking.confirmed' );

		$this->assertSame( 'dining', $payload['type'] );
		$this->assertFalse( $payload['dispatch'] );
		$this->assertSame( 'none', $payload['skill'] );
		$this->assertSame( $this->seating( 3 ), $payload['slot_start'] );
	}

	public function test_the_area_follows_the_location_zone(): void {
		$listing = $this->stay_listing();
		$this->location( 'harbour-side', 'central' );
		wp_set_object_terms( $listing, array( 'harbour-side' ), 'qm_location' );
		$id = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'central', $this->payload( $id, 'booking.quote' )['area'] );
		$this->assertSame( 'harbour-side', $this->booking( $id )['location'] );
	}

	public function test_a_listing_without_a_location_falls_back_to_central(): void {
		$listing = $this->stay_listing();
		wp_set_object_terms( $listing, array(), 'qm_location' );
		$id = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'anywhere', $this->booking( $id )['location'] );
		$this->assertSame( 'central', $this->payload( $id, 'booking.quote' )['area'] );
	}

	public function test_the_payload_never_carries_personal_data(): void {
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 2, array(), 'private-person@example.com', 0, 'my phone is 555' );

		$raw = (string) $GLOBALS['wpdb']->get_var( 'SELECT payload FROM ' . Db::table( 'outbox' ) . " WHERE booking_id = {$id} AND target = 'events'" );

		$this->assertStringNotContainsString( 'private-person', $raw );
		$this->assertStringNotContainsString( '555', $raw );
		$this->assertTrue( json_decode( $raw, true )['has_note'] );
	}

	public function test_an_unknown_booking_records_nothing(): void {
		Services::outbox()->record( 'booking.quote', 999999 );

		$this->assertSame( 0, $this->row_count( 'outbox' ) );
	}

	public function test_delivery_is_signed_and_marks_the_row(): void {
		$this->env( 'QHS_N8N_EVENTS_URL', 'http://n8n.test/hook' );
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 1, Services::outbox()->deliver_due() );

		$call = end( $this->http );
		$this->assertSame( 'http://n8n.test/hook', $call['url'] );
		$headers = $call['args']['headers'];
		$this->assertSame( 'n8n', $headers['X-QHS-Key-Id'] );
		$body    = json_decode( $call['args']['body'], true );
		$this->assertIsString( $body['payload'] );
		$this->assertSame( 'v1=' . Signer::mac( str_repeat( 'n', 40 ), $headers['X-QHS-Timestamp'], $headers['X-QHS-Nonce'], $body['payload'] ), $headers['X-QHS-Signature'] );
		$inner = json_decode( $body['payload'], true );
		$this->assertSame( 'booking.quote', $inner['event'] );
		$this->assertSame( $id, $inner['booking_id'] );
		$this->assertGreaterThan( 0, $inner['event_id'] );
		$this->assertSame( 1, $this->row_count( 'outbox', 'delivered_at IS NOT NULL' ) );
		$this->assertSame( 0, Services::outbox()->deliver_due() );
	}

	public function test_triage_goes_to_its_own_url(): void {
		$this->env( 'QHS_N8N_EVENTS_URL', 'http://n8n.test/events' );
		$this->env( 'QHS_N8N_TRIAGE_URL', 'http://n8n.test/triage' );
		$this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 2, array(), 'a@example.com', 0, 'Can we bring a dog?' );

		$this->assertSame( 2, Services::outbox()->deliver_due() );

		$this->assertSame( array( 'http://n8n.test/events', 'http://n8n.test/triage' ), array_column( $this->http, 'url' ) );
	}

	public function test_failed_delivery_backs_off_then_alerts(): void {
		global $wpdb;
		$this->env( 'QHS_N8N_EVENTS_URL', 'http://n8n.test/hook' );
		$this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$down = static fn() => new \WP_Error( 'down', 'connection refused' );
		add_filter( 'pre_http_request', $down );

		Services::outbox()->deliver_due();
		$row = $wpdb->get_row( 'SELECT * FROM ' . Db::table( 'outbox' ), ARRAY_A );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertGreaterThan( Db::now( 60 ), $row['next_attempt_at'] );
		$this->assertStringContainsString( 'connection refused', $row['last_error'] );
		$this->assertSame( 0, Services::outbox()->deliver_due() );

		for ( $i = 1; $i < Outbox::MAX_ATTEMPTS; $i++ ) {
			$wpdb->query( 'UPDATE ' . Db::table( 'outbox' ) . " SET next_attempt_at = '2020-01-01 00:00:00'" );
			Services::outbox()->deliver_due();
		}
		remove_filter( 'pre_http_request', $down );

		$this->assertSame( 1, $this->row_count( 'outbox', 'failed_at IS NOT NULL' ) );
		$this->assertSame( 1, $this->row_count( 'alerts', "source = 'outbox'" ) );
		$wpdb->query( 'UPDATE ' . Db::table( 'outbox' ) . " SET next_attempt_at = '2020-01-01 00:00:00'" );
		$this->assertSame( 0, Services::outbox()->deliver_due() );
	}

	public function test_events_wait_when_no_url_is_set(): void {
		$this->unset_env( 'QHS_N8N_EVENTS_URL' );
		$this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 0, Services::outbox()->deliver_due() );
		$this->assertSame( 1, $this->row_count( 'outbox', 'delivered_at IS NULL' ) );
		$this->assertSame( 1, Services::outbox()->pending_count() );
	}

	public function test_a_crm_sync_claim_is_taken_once(): void {
		$this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$event = (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . Db::table( 'outbox' ) . ' LIMIT 1' );

		$this->assertTrue( Services::outbox()->claim_crm_sync( $event ) );
		$this->assertFalse( Services::outbox()->claim_crm_sync( $event ) );
		$this->assertNull( Services::outbox()->find( 999999 ) );
	}
}
