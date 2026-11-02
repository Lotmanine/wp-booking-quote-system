<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Security\Signer;
use Quillmoor\Booking\Services;

class SignedCallbackTest extends TestCase {

	private function send( string $route, array $signed, string $payload ) {
		$headers = $signed;
		return $this->rest( 'POST', $route, array( 'payload' => $payload ), $headers );
	}

	/** @return array<string,string> */
	private function headers( string $key, string $payload, ?int $time = null, ?string $nonce = null, ?string $secret = null ): array {
		$time   = (string) ( $time ?? time() );
		$nonce  = $nonce ?? bin2hex( random_bytes( 16 ) );
		$secret = $secret ?? str_repeat( 'n' === $key[0] ? 'n' : 'd', 40 );
		return array(
			'X-QHS-Key-Id'    => $key,
			'X-QHS-Timestamp' => $time,
			'X-QHS-Nonce'     => $nonce,
			'X-QHS-Signature' => 'v1=' . Signer::mac( $secret, $time, $nonce, $payload ),
		);
	}

	private function payload(): string {
		return (string) wp_json_encode(
			array(
				'source'  => 'test',
				'message' => 'hello',
			)
		);
	}

	public function test_a_valid_signature_is_accepted(): void {
		$payload = $this->payload();
		$this->assertSame( 201, $this->send( '/n8n/alert', $this->headers( 'n8n', $payload ), $payload )->get_status() );
		$this->assertSame( 1, $this->row_count( 'alerts' ) );
	}

	public function test_a_bad_signature_fails(): void {
		$payload                     = $this->payload();
		$headers                     = $this->headers( 'n8n', $payload );
		$headers['X-QHS-Signature'] = 'v1=' . str_repeat( '0', 64 );

		$this->assertSame( 401, $this->send( '/n8n/alert', $headers, $payload )->get_status() );
		$this->assertSame( 0, $this->row_count( 'alerts' ) );
	}

	public function test_a_tampered_payload_fails(): void {
		$payload = $this->payload();
		$headers = $this->headers( 'n8n', $payload );

		$this->assertSame( 401, $this->send( '/n8n/alert', $headers, str_replace( 'hello', 'owned', $payload ) )->get_status() );
	}

	public function test_a_wrong_secret_fails(): void {
		$payload = $this->payload();
		$headers = $this->headers( 'n8n', $payload, null, null, str_repeat( 'x', 40 ) );

		$this->assertSame( 401, $this->send( '/n8n/alert', $headers, $payload )->get_status() );
	}

	public function test_a_replayed_callback_fails(): void {
		$payload = $this->payload();
		$headers = $this->headers( 'n8n', $payload );

		$this->assertSame( 201, $this->send( '/n8n/alert', $headers, $payload )->get_status() );
		$this->assertSame( 401, $this->send( '/n8n/alert', $headers, $payload )->get_status() );
		$this->assertSame( 1, $this->row_count( 'alerts' ) );
	}

	/** @dataProvider old_times */
	public function test_timestamps_outside_the_window_fail( int $offset ): void {
		$payload = $this->payload();
		$headers = $this->headers( 'n8n', $payload, time() + $offset );

		$this->assertSame( 401, $this->send( '/n8n/alert', $headers, $payload )->get_status() );
	}

	public static function old_times(): array {
		return array(
			'stale'  => array( -301 ),
			'future' => array( 301 ),
		);
	}

	public function test_a_failed_signature_does_not_burn_the_nonce(): void {
		$payload = $this->payload();
		$good    = $this->headers( 'n8n', $payload );
		$bad     = $good;

		$bad['X-QHS-Signature'] = 'v1=' . str_repeat( '0', 64 );
		$this->send( '/n8n/alert', $bad, $payload );

		$this->assertSame( 201, $this->send( '/n8n/alert', $good, $payload )->get_status() );
	}

	public function test_keys_cannot_cross_routes(): void {
		$payload = $this->payload();

		$this->assertSame( 401, $this->send( '/n8n/alert', $this->headers( 'dispatch', $payload ), $payload )->get_status() );
		$this->assertSame( 401, $this->send( '/dispatch/decision', $this->headers( 'n8n', $payload ), $payload )->get_status() );
	}

	/** @dataProvider routes */
	public function test_every_machine_route_refuses_an_unsigned_call( string $route ): void {
		$this->assertSame( 401, $this->rest( 'POST', $route, array( 'payload' => '{}' ) )->get_status() );
	}

	public static function routes(): array {
		return array(
			'decision'  => array( '/dispatch/decision' ),
			'crm'       => array( '/n8n/crm-sync' ),
			'reminders' => array( '/n8n/reminders' ),
			'followups' => array( '/n8n/followups' ),
			'triage'    => array( '/n8n/triage' ),
			'alert'     => array( '/n8n/alert' ),
		);
	}

	public function test_reminder_and_followup_routes_run_when_signed(): void {
		$payload = (string) wp_json_encode( array( 'hours_ahead' => 48 ) );

		$response = $this->send( '/n8n/reminders', $this->headers( 'n8n', $payload ), $payload );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'due'     => 0,
				'sent'    => 0,
				'skipped' => 0,
			),
			$response->get_data()
		);
		$payload = (string) wp_json_encode( array( 'hours_after' => 0 ) );
		$this->assertSame( 200, $this->send( '/n8n/followups', $this->headers( 'n8n', $payload ), $payload )->get_status() );
	}

	public function test_a_short_secret_fails_closed(): void {
		$this->env( 'QHS_HMAC_SECRET_N8N', 'short' );
		$payload = $this->payload();

		$this->assertSame( 401, $this->send( '/n8n/alert', $this->headers( 'n8n', $payload, null, null, 'short' ), $payload )->get_status() );
	}

	public function test_signer_output_verifies(): void {
		$signed  = Services::signer()->sign( 'n8n', 'body' );
		$headers = $signed['headers'];

		$this->assertTrue(
			Services::signer()->verify( $headers['X-QHS-Key-Id'], $headers['X-QHS-Timestamp'], $headers['X-QHS-Nonce'], $headers['X-QHS-Signature'], 'body', array( 'n8n' ) )
		);
	}

	public function test_dispatch_decision_assigns_staff(): void {
		$staff = $this->user( 'qhs_staff' );
		update_user_meta( $staff, 'qhs_staff_ref', 'rowan' );
		$id      = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$booking = Services::bookings()->find( $id );
		$payload = (string) wp_json_encode(
			array(
				'booking_id' => $id,
				'version'    => (int) $booking['version'],
				'outcome'    => 'assigned',
				'staff_ref'  => 'rowan',
				'reason'     => 'test',
			)
		);

		$response = $this->send( '/dispatch/decision', $this->headers( 'dispatch', $payload ), $payload );

		$this->assertSame( 'assigned', $response->get_data()['result'] );
		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( $staff, (int) Services::bookings()->find( $id )['staff_user_id'] );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND reason LIKE 'team member assigned%'" ) );
	}

	public function test_dispatch_decisions_for_requests_or_unknown_staff_change_nothing(): void {
		$staff = $this->user( 'qhs_staff' );
		update_user_meta( $staff, 'qhs_staff_ref', 'rowan' );
		$request = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ) ), $this->day( 10 ), $this->day( 12 ) );
		$id      = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'b@example.com' );
		$version = (int) Services::bookings()->find( $id )['version'];

		$this->assertSame( 'stale', Services::service()->apply_dispatch_decision( $request, (int) Services::bookings()->find( $request )['version'], 'assigned', 'rowan', 'x' ) );
		$this->assertSame( 'unknown_staff', Services::service()->apply_dispatch_decision( $id, $version, 'assigned', 'nobody', 'x' ) );
		$this->assertSame( 'unassigned', Services::service()->apply_dispatch_decision( $id, $version, 'unassigned', '', 'nobody free' ) );
		$this->assertSame( 'not_found', Services::service()->apply_dispatch_decision( 999999, 1, 'assigned', 'rowan', 'x' ) );
		$this->assertSame( 0, (int) Services::bookings()->find( $id )['staff_user_id'] );
		$this->assertSame( 2, $this->row_count( 'alerts', "source = 'dispatch'" ) );
	}

	public function test_stale_dispatch_decision_is_ignored(): void {
		$staff = $this->user( 'qhs_staff' );
		update_user_meta( $staff, 'qhs_staff_ref', 'rowan' );
		$id      = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$payload = (string) wp_json_encode(
			array(
				'booking_id' => $id,
				'version'    => 1,
				'outcome'    => 'assigned',
				'staff_ref'  => 'rowan',
				'reason'     => 'late',
			)
		);

		$response = $this->send( '/dispatch/decision', $this->headers( 'dispatch', $payload ), $payload );

		$this->assertSame( 'stale', $response->get_data()['result'] );
		$this->assertSame( 'confirmed', $this->state( $id ) );
	}
}
