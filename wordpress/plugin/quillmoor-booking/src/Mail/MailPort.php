<?php
/**
 * Mail transport contract.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * A transport that sends one transactional email.
 */
interface MailPort {

	/**
	 * Transport name for the log.
	 */
	public function name(): string;

	/**
	 * Send one message.
	 *
	 * @param string                                       $to          Recipient.
	 * @param string                                       $subject     Subject.
	 * @param string                                       $html        HTML body.
	 * @param array<int,array{name:string,content:string}> $attachments Base64 attachments.
	 * @return bool True when the transport accepted it.
	 */
	public function send( string $to, string $subject, string $html, array $attachments = array() ): bool;
}
