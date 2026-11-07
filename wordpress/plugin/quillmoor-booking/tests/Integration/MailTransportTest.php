<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Mail\BrevoMailer;
use Quillmoor\Booking\Mail\LogOnlyMailer;
use Quillmoor\Booking\Mail\N8nMailer;
use Quillmoor\Booking\Services;

class MailTransportTest extends TestCase {

	private const URL = 'https://n8n.test/webhook/booking-mail';

	/** @var int Status the fake n8n answers with. */
	private int $n8n_status = 200;

	public function set_up(): void {
		parent::set_up();
		$this->n8n_status = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_n8n' ), 20, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_n8n' ), 20 );
		parent::tear_down();
	}

	public function fake_n8n( $result, array $args, string $url ) {
		if ( self::URL !== $url ) {
			return $result;
		}
		return array(
			'response' => array( 'code' => $this->n8n_status ),
			'body'     => '{}',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	/** Calls made to the n8n webhook. */
	private function n8n_calls(): array {
		return array_values( array_filter( $this->http, static fn( array $call ): bool => self::URL === $call['url'] ) );
	}

	public function test_log_only_when_neither_brevo_nor_n8n_is_set(): void {
		$this->unset_env( 'BREVO_API_KEY' );
		$this->unset_env( 'QHS_N8N_MAIL_URL' );

		$this->assertInstanceOf( LogOnlyMailer::class, Services::mail_port() );
	}

	public function test_n8n_when_brevo_is_empty_and_the_webhook_is_set(): void {
		$this->env( 'BREVO_API_KEY', '' );
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );

		$this->assertInstanceOf( N8nMailer::class, Services::mail_port() );
		$this->assertSame( 'n8n', Services::mail_port()->name() );
	}

	public function test_brevo_wins_when_its_key_is_set(): void {
		$this->env( 'BREVO_API_KEY', 'brevo-test-key' );
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );

		$this->assertInstanceOf( BrevoMailer::class, Services::mail_port() );
	}

	public function test_the_message_is_signed_over_its_raw_body(): void {
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );
		$this->unset_env( 'BREVO_API_KEY' );

		$ok = Services::mail_port()->send(
			'someone@guest-mail.dev',
			'Subject line',
			'<p>Body</p>',
			array(
				array(
					'name'    => 'booking.pdf',
					'content' => 'UERG',
				),
			)
		);

		$this->assertTrue( $ok );
		$calls = $this->n8n_calls();
		$this->assertCount( 1, $calls );
		$args = $calls[0]['args'];
		$body = (string) $args['body'];
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $body, str_repeat( 'n', 40 ) ), $args['headers'][ N8nMailer::HEADER ] );

		$message = json_decode( $body, true );
		$this->assertSame( array( 'v', 'type', 'issued_at', 'nonce', 'data' ), array_keys( $message ) );
		$this->assertSame( 1, $message['v'] );
		$this->assertSame( 'mail.send', $message['type'] );
		$this->assertEqualsWithDelta( time(), $message['issued_at'], 5 );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $message['nonce'] );
		$this->assertSame( 'someone@guest-mail.dev', $message['data']['to'] );
		$this->assertSame( 'Subject line', $message['data']['subject'] );
		$this->assertSame( '<p>Body</p>', $message['data']['html'] );
		$this->assertSame( 'booking.pdf', $message['data']['attachments'][0]['name'] );
	}

	public function test_every_message_has_its_own_nonce(): void {
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );
		$this->unset_env( 'BREVO_API_KEY' );

		Services::mail_port()->send( 'a@guest-mail.dev', 'One', '<p>1</p>' );
		Services::mail_port()->send( 'a@guest-mail.dev', 'Two', '<p>2</p>' );

		$calls = $this->n8n_calls();
		$this->assertNotSame( json_decode( $calls[0]['args']['body'], true )['nonce'], json_decode( $calls[1]['args']['body'], true )['nonce'] );
	}

	public function test_a_short_or_missing_secret_sends_nothing(): void {
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );
		$this->unset_env( 'BREVO_API_KEY' );
		$this->env( 'QHS_HMAC_SECRET_N8N', 'too-short' );

		$this->assertFalse( Services::mail_port()->send( 'a@guest-mail.dev', 'S', '<p>x</p>' ) );
		$this->assertSame( array(), $this->n8n_calls() );
		$this->assertSame( '', N8nMailer::sign( 'body', '' ) );
	}

	public function test_a_refusal_from_n8n_is_a_failed_send(): void {
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );
		$this->unset_env( 'BREVO_API_KEY' );
		$this->n8n_status = 401;

		$this->assertFalse( Services::mail_port()->send( 'a@guest-mail.dev', 'S', '<p>x</p>' ) );
	}

	public function test_the_gate_still_runs_before_n8n(): void {
		$this->env( 'QHS_N8N_MAIL_URL', self::URL );
		$this->unset_env( 'BREVO_API_KEY' );
		$row = Services::bookings()->upsert_customer( 'gate@guest-mail.dev', 'Gate', 0 );

		// Unverified: the verification link is the only message, and it goes through n8n.
		$this->assertSame( 'sent', Services::mail()->send_verification( $row ) );
		$reserved = Services::bookings()->upsert_customer( 'gate@example.com', 'Gate', 0 );
		$this->assertSame( 'suppressed_reserved', Services::mail()->send_verification( $reserved ) );

		$this->assertCount( 1, $this->n8n_calls() );
		$this->assertSame( 1, $this->row_count( 'mail_log', "transport = 'n8n' AND status = 'sent'" ) );
		$this->assertSame( 1, Services::mail()->used_today() );
	}
}
