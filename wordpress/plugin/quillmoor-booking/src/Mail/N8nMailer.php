<?php
/**
 * Mail transport through n8n.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Hands a message to an n8n webhook, which sends it over SMTP. The caps in MailService run before
 * this, so n8n never receives more than the daily allowance.
 *
 * The body carries a version, the type, a timestamp and a single-use nonce, and the header carries an
 * HMAC of the raw body, so n8n can refuse forged, stale and replayed messages.
 */
final class N8nMailer implements MailPort {

	public const HEADER = 'X-QHS-Mail-Signature';
	public const TYPE   = 'mail.send';

	/**
	 * Build the mailer.
	 *
	 * @param string $url    Webhook URL.
	 * @param string $secret Shared signing secret.
	 */
	public function __construct( private string $url, private string $secret ) {
	}

	/**
	 * Transport name.
	 */
	public function name(): string {
		return 'n8n';
	}

	/**
	 * Raw body for one message.
	 *
	 * @param array<string,mixed> $data Message data.
	 */
	public static function build( array $data ): string {
		return (string) wp_json_encode(
			array(
				'v'         => 1,
				'type'      => self::TYPE,
				'issued_at' => time(),
				'nonce'     => bin2hex( random_bytes( 16 ) ),
				'data'      => $data,
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Signature header value for a raw body. Empty when the secret is missing or too short.
	 *
	 * @param string $body   Raw body.
	 * @param string $secret Shared secret.
	 */
	public static function sign( string $body, string $secret ): string {
		if ( strlen( $secret ) < 32 ) {
			return '';
		}
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/**
	 * Send one message.
	 *
	 * @param string                                       $to          Recipient.
	 * @param string                                       $subject     Subject.
	 * @param string                                       $html        HTML body.
	 * @param array<int,array{name:string,content:string}> $attachments Base64 attachments.
	 */
	public function send( string $to, string $subject, string $html, array $attachments = array() ): bool {
		$body      = self::build(
			array(
				'to'          => $to,
				'subject'     => $subject,
				'html'        => $html,
				'attachments' => $attachments,
			)
		);
		$signature = self::sign( $body, $this->secret );
		if ( '' === $this->url || '' === $signature ) {
			return false;
		}

		$response = wp_remote_post(
			$this->url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					self::HEADER   => $signature,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
