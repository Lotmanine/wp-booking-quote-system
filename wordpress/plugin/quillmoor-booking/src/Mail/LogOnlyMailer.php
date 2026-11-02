<?php
/**
 * Stub transport.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Sends nothing. Used until BREVO_API_KEY is set; the mail log records every attempt.
 */
final class LogOnlyMailer implements MailPort {

	/**
	 * Transport name.
	 */
	public function name(): string {
		return 'log-only';
	}

	/**
	 * Accept and discard.
	 *
	 * @param string                                       $to          Recipient.
	 * @param string                                       $subject     Subject.
	 * @param string                                       $html        HTML body.
	 * @param array<int,array{name:string,content:string}> $attachments Attachments.
	 */
	public function send( string $to, string $subject, string $html, array $attachments = array() ): bool {
		return true;
	}
}
