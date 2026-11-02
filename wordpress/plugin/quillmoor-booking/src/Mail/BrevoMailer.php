<?php
/**
 * Brevo transactional transport.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Mail;

use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Sends through Brevo's API, so DKIM aligns with the authenticated oldev.site domain.
 * The sender is fixed; nothing a visitor types reaches a header.
 */
final class BrevoMailer implements MailPort {

	private const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Build the mailer.
	 *
	 * @param string $api_key Brevo API key.
	 */
	public function __construct( private string $api_key ) {
	}

	/**
	 * Transport name.
	 */
	public function name(): string {
		return 'brevo';
	}

	/**
	 * Send one message.
	 *
	 * @param string                                       $to          Recipient.
	 * @param string                                       $subject     Subject.
	 * @param string                                       $html        HTML body.
	 * @param array<int,array{name:string,content:string}> $attachments Attachments.
	 */
	public function send( string $to, string $subject, string $html, array $attachments = array() ): bool {
		$body = array(
			'sender'      => array(
				'name'  => Config::MAIL_FROM_NAME,
				'email' => Config::get( 'QHS_MAIL_FROM', 'lotfi@oldev.site' ),
			),
			'to'          => array( array( 'email' => $to ) ),
			'subject'     => $subject,
			'htmlContent' => $html,
		);
		if ( array() !== $attachments ) {
			$body['attachment'] = $attachments;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array(
					'api-key'      => $this->api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
