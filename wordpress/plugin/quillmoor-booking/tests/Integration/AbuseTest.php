<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Security\ClientIp;
use Quillmoor\Booking\Services;

class AbuseTest extends TestCase {

	private int $listing = 0;

	private function quote_body(): array {
		if ( 0 === $this->listing ) {
			$this->listing = $this->stay_listing();
		}
		return array(
			'listing_id'      => $this->listing,
			'check_in'        => $this->day( 10 ),
			'check_out'       => $this->day( 12 ),
			'guests'          => 2,
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
		$this->unset_env( 'TURNSTILE_SECRET_KEY' );

		$response = $this->rest( 'POST', '/quote', $this->quote_body() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->row_count( 'bookings' ) );
	}

	public function test_the_verifier_itself_refuses_without_a_secret_or_token(): void {
		$this->assertFalse( Services::turnstile()->verify( '', '1.2.3.4' ) );
		$this->assertFalse( Services::turnstile()->verify( str_repeat( 'x', 2049 ), '1.2.3.4' ) );
		$this->unset_env( 'TURNSTILE_SECRET_KEY' );
		$this->assertFalse( Services::turnstile()->verify( 'token', '1.2.3.4' ) );
		$this->assertSame( array(), $this->http );
	}

	public function test_turnstile_runs_on_the_server(): void {
		$this->rest( 'POST', '/quote', $this->quote_body() );

		$this->assertStringContainsString( 'siteverify', $this->http[0]['url'] );
	}

	public function test_turnstile_stops_a_hold(): void {
		$id                 = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->turnstile_ok = false;

		$response = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $id,
				'token'           => $this->token( $id ),
				'turnstile_token' => 'token',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_a_checked_quote_covers_its_first_hold_only(): void {
		$quote = $this->rest( 'POST', '/quote', $this->quote_body() );
		$this->assertSame( 201, $quote->get_status() );
		$id    = (int) $quote->get_data()['booking']['id'];
		$token = (string) $quote->get_data()['token'];
		// Turnstile tokens are single use: Cloudflare refuses the quote's token a second time.
		$this->turnstile_ok = false;
		$this->http         = array();
		$body               = array(
			'booking_id'      => $id,
			'token'           => $token,
			'turnstile_token' => 'token',
		);

		$first = $this->rest( 'POST', '/hold', $body );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( array(), $this->http );

		$this->expire_hold( $id );
		Services::service()->release_expired_holds();
		$again = $this->rest( 'POST', '/hold', $body );

		$this->assertSame( 403, $again->get_status() );
		$this->assertSame( 'turnstile_failed', $this->code( $again ) );
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_a_checked_quote_does_not_cover_another_booking(): void {
		$this->rest( 'POST', '/quote', $this->quote_body() );
		$other              = $this->stay_quote( $this->listing, $this->day( 20 ), $this->day( 22 ) );
		$this->turnstile_ok = false;

		$response = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $other,
				'token'           => $this->token( $other ),
				'turnstile_token' => 'token',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'quote', $this->state( $other ) );
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

	public function test_price_previews_have_their_own_limit(): void {
		$listing = $this->stay_listing();
		for ( $i = 0; $i < 120; $i++ ) {
			Services::counters()->take( 'rl:price:' . ClientIp::hash( ClientIp::resolve() ), MINUTE_IN_SECONDS, 120 );
		}

		$this->assertSame( 429, $this->rest( 'POST', "/listings/{$listing}/price", array( 'check_in' => $this->day( 10 ), 'check_out' => $this->day( 12 ) ) )->get_status() );
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
