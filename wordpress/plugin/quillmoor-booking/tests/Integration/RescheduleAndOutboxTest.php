<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Outbox;
use Quillmoor\Booking\Services;

class RescheduleAndOutboxTest extends TestCase {

	public function test_moving_an_assigned_job_clears_staff_and_returns_to_confirmed(): void {
		$first = $this->far_slot( 0 );
		$next  = $this->far_slot( 1 );
		$id    = $this->assigned( $this->user( 'qhs_staff' ), $first );

		$moved = Services::service()->reschedule( $id, $next, 'test' );

		$this->assertSame( 'confirmed', $moved['state'] );
		$this->assertSame( 0, (int) $moved['staff_user_id'] );
		$this->assertSame( 1, $this->row_count( 'outbox', "event = 'booking.rescheduled'" ) );
	}

	public function test_a_taken_slot_keeps_the_old_one(): void {
		$first = $this->far_slot( 0 );
		$next  = $this->far_slot( 1 );
		$id    = $this->confirmed( 'a@example.com', 0, $first );
		$this->confirmed( 'b@example.com', 0, $next );

		try {
			Services::service()->reschedule( $id, $next, 'test' );
			$this->fail( 'Should refuse.' );
		} catch ( BookingError $e ) {
			$this->assertSame( 'slot_taken', $e->error_code );
		}

		$this->assertSame( $first, Services::bookings()->find( $id )['slot_start'] );
		$this->assertSame( 1, $this->row_count( 'slot_claims', "booking_id = {$id} AND slot_start = '{$first}'" ) );
	}

	public function test_a_decision_for_the_old_slot_goes_stale_after_a_move(): void {
		$first = $this->far_slot( 0 );
		$next  = $this->far_slot( 1 );
		$id    = $this->confirmed( 'a@example.com', 0, $first );
		$old   = (int) Services::bookings()->find( $id )['version'];

		Services::service()->reschedule( $id, $next, 'test' );

		$this->assertSame( 'stale', Services::service()->apply_dispatch_decision( $id, $old, 'assigned', 'rowan', 'late' ) );
	}

	public function test_completion_needs_an_assigned_job(): void {
		$id = $this->confirmed();

		$this->expectException( BookingError::class );
		Services::service()->update_job( $id, 'completed', 'test' );
	}

	public function test_failed_delivery_backs_off_then_alerts(): void {
		global $wpdb;
		$this->env( 'QHS_N8N_EVENTS_URL', 'http://n8n.test/hook' );
		$this->quote();
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$down = static fn() => new \WP_Error( 'down', 'connection refused' );
		add_filter( 'pre_http_request', $down );

		for ( $i = 0; $i < Outbox::MAX_ATTEMPTS; $i++ ) {
			$wpdb->query( 'UPDATE ' . Db::table( 'outbox' ) . " SET next_attempt_at = '2020-01-01 00:00:00'" );
			Services::outbox()->deliver_due();
		}
		remove_filter( 'pre_http_request', $down );

		$this->assertSame( 1, $this->row_count( 'outbox', 'failed_at IS NOT NULL' ) );
		$this->assertSame( 1, $this->row_count( 'alerts' ) );
	}

	public function test_delivery_is_signed_and_marks_the_row(): void {
		$this->env( 'QHS_N8N_EVENTS_URL', 'http://n8n.test/hook' );
		$this->quote();

		$this->assertSame( 1, Services::outbox()->deliver_due() );

		$call = end( $this->http );
		$this->assertStringStartsWith( 'v1=', $call['args']['headers']['X-QHS-Signature'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertIsString( $body['payload'] );
		$this->assertSame( 'booking.quote', json_decode( $body['payload'], true )['event'] );
		$this->assertSame( 1, $this->row_count( 'outbox', 'delivered_at IS NOT NULL' ) );
	}

	public function test_events_wait_when_no_url_is_set(): void {
		$this->env( 'QHS_N8N_EVENTS_URL', '' );
		putenv( 'QHS_N8N_EVENTS_URL' );
		$this->quote();

		$this->assertSame( 0, Services::outbox()->deliver_due() );
		$this->assertSame( 1, $this->row_count( 'outbox', 'delivered_at IS NULL' ) );
	}
}
