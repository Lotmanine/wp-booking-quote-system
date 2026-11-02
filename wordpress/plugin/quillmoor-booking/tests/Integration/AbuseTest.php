<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Security\ClientIp;
use Quillmoor\Booking\Services;

class AbuseTest extends TestCase {

	private function quote_body(): array {
		return array(
			'service'         => 'cleaning',
			'size'            => 'small',
			'area'            => 'north',
			'email'           => 'p@example.com',
			'full_name'       => 'P',
			'turnstile_token' => 'token',
		);
	}

	public function test_a_rejected_turnstile_token_stops_a_quote(): void {
		$this->turnstile_ok = false;

		$response = $this->rest( 'POST', '/quote', $this->quote_body() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->row_count( 'bookings' ) );
	}

	public function test_a_missing_turnstile_token_stops_a_quote(): void {
		$body = $this->quote_body();
		unset( $body['turnstile_token'] );

		$this->assertSame( 403, $this->rest( 'POST', '/quote', $body )->get_status() );
	}

	public function test_turnstile_fails_closed_without_a_secret(): void {
		$this->env( 'TURNSTILE_SECRET_KEY', '' );
		putenv( 'TURNSTILE_SECRET_KEY' );

		$this->assertSame( 403, $this->rest( 'POST', '/quote', $this->quote_body() )->get_status() );
	}

	public function test_turnstile_runs_on_the_server(): void {
		$this->rest( 'POST', '/quote', $this->quote_body() );

		$this->assertStringContainsString( 'siteverify', $this->http[0]['url'] );
	}

	public function test_turnstile_stops_a_hold(): void {
		$id                 = $this->quote();
		$this->turnstile_ok = false;
		$booking            = Services::bookings()->find( $id );

		$response = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $id,
				'slot_start'      => $this->slot(),
				'token'           => \Quillmoor\Booking\Security\BookingPolicy::token_for( $booking ),
				'turnstile_token' => 'token',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_per_ip_limit_on_quotes(): void {
		$this->env( 'QHS_LIMIT_QUOTE_PER_MIN', '2' );

		$this->assertSame( 201, $this->rest( 'POST', '/quote', $this->quote_body() )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', '/quote', $this->quote_body() )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', '/quote', $this->quote_body() )->get_status() );
	}

	public function test_per_ip_limit_on_holds(): void {
		$this->env( 'QHS_LIMIT_HOLD_PER_MIN', '1' );

		$this->assertSame( 404, $this->rest( 'POST', '/hold', array( 'turnstile_token' => 't' ) )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', '/hold', array( 'turnstile_token' => 't' ) )->get_status() );
	}

	public function test_counter_never_passes_its_limit(): void {
		$results = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = Services::counters()->take( 'unit', 60, 3 );
		}

		$this->assertSame( array( true, true, true, false, false ), $results );
	}

	public function test_invalid_email_is_refused(): void {
		$body          = $this->quote_body();
		$body['email'] = 'not an email';

		$this->assertSame( 400, $this->rest( 'POST', '/quote', $body )->get_status() );
	}

	public function test_forwarded_header_from_an_untrusted_peer_is_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$this->env( 'QHS_TRUSTED_PROXIES', '10.0.0.0/8' );

		$this->assertSame( '203.0.113.9', ClientIp::resolve() );
	}

	public function test_forwarded_header_from_a_trusted_proxy_uses_the_last_hop(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.7';
		$this->env( 'QHS_TRUSTED_PROXIES', '10.0.0.0/8' );

		$this->assertSame( '198.51.100.7', ClientIp::resolve() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}
}
