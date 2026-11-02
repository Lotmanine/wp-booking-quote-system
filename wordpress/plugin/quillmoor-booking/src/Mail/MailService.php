<?php
/**
 * Customer email rules.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Mail;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Pdf\PdfRenderer;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Security\Counters;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * One gate for every message: verified address, per-address limit and the shared daily cap.
 */
final class MailService {

	private const CAP_KEY = 'mail:daily';

	/**
	 * Build the service.
	 *
	 * @param MailPort $port     Transport.
	 * @param Counters $counters Counters.
	 */
	public function __construct( private MailPort $port, private Counters $counters ) {
	}

	/**
	 * Whether a status means the message went to the transport.
	 *
	 * @param string $status Result of a send.
	 */
	public static function delivered( string $status ): bool {
		return 'sent' === $status;
	}

	/**
	 * Messages counted against today's cap.
	 */
	public function used_today(): int {
		return $this->counters->value( self::CAP_KEY, DAY_IN_SECONDS );
	}

	/**
	 * Send an address verification link. This is the one message an unverified address can get.
	 *
	 * @param array<string,mixed> $customer Customer row.
	 */
	public function send_verification( array $customer ): string {
		$token = bin2hex( random_bytes( 24 ) );
		Services::bookings()->update_customer(
			(int) $customer['id'],
			array(
				'verify_token_hash' => hash( 'sha256', $token ),
				'verify_expires_at' => Db::now( DAY_IN_SECONDS ),
			)
		);
		$url  = add_query_arg( 'verify', $token, home_url( '/trips/' ) );
		$html = '<p>Please confirm this address so we can send your booking updates.</p><p><a href="' . esc_url( $url ) . '">Confirm my email</a></p>' . $this->footer();
		return $this->deliver( $customer, 'verify', 0, 'Confirm your email (demo)', $html );
	}

	/**
	 * Send the booking confirmation with its PDF.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function send_confirmation( array $booking ): string {
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return 'failed';
		}
		$html = $this->summary( $booking, 'Your demo booking is confirmed.' );

		$attachment = array(
			array(
				'name'    => 'booking-' . BookingView::ref( $booking ) . '.pdf',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Brevo requires base64 attachments.
				'content' => base64_encode( PdfRenderer::confirmation( $booking ) ),
			),
		);
		return $this->deliver( $customer, 'confirmation', (int) $booking['id'], 'Booking confirmed (demo)', $html, $attachment );
	}

	/**
	 * Tell the guest the host has the request and answers within the response window.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function send_request_received( array $booking ): string {
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return 'failed';
		}
		$html = $this->summary( $booking, 'Your deposit was received and the host has your request. They answer within ' . Config::REQUEST_HOURS . ' hours; if they do not, the request is declined and the deposit refunded.' );
		return $this->deliver( $customer, 'request', (int) $booking['id'], 'Request sent to the host (demo)', $html );
	}

	/**
	 * Tell the guest the host declined.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function send_declined( array $booking ): string {
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return 'failed';
		}
		$html = $this->summary( $booking, 'The host could not accept this request. Any deposit you paid will be refunded.' );
		return $this->deliver( $customer, 'declined', (int) $booking['id'], 'Request declined (demo)', $html );
	}

	/**
	 * Send a reminder before arrival.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function send_reminder( array $booking ): string {
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return 'failed';
		}
		$html = $this->summary( $booking, BookingView::is_dining( $booking ) ? 'Your table is coming up.' : 'Your stay is coming up.' );
		return $this->deliver( $customer, 'reminder', (int) $booking['id'], 'Coming up soon (demo)', $html );
	}

	/**
	 * Send the follow-up after a stay or a meal.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function send_followup( array $booking ): string {
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return 'failed';
		}
		$html = '<p>Thank you for trying the Quillmoor demo. Nothing was really booked and no money moved.</p>' . $this->footer();
		return $this->deliver( $customer, 'followup', (int) $booking['id'], 'Thanks for trying the demo', $html );
	}

	/**
	 * Booking summary block for a message.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @param string              $lead    Opening sentence.
	 */
	private function summary( array $booking, string $lead ): string {
		return '<p>' . esc_html( $lead ) . '</p>'
			. '<p><strong>' . esc_html( BookingView::listing_title( $booking ) ) . '</strong><br>'
			. esc_html( BookingView::when( $booking ) ) . '<br>'
			. esc_html( 'Reference ' . BookingView::ref( $booking ) ) . '</p>'
			. '<p><a href="' . esc_url( self::portal_link( $booking ) ) . '">View your booking</a></p>' . $this->footer();
	}

	/**
	 * Portal link carrying the guest token.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function portal_link( array $booking ): string {
		return add_query_arg(
			array(
				'booking' => (int) $booking['id'],
				'token'   => BookingPolicy::token_for( $booking ),
			),
			home_url( '/trips/' )
		);
	}

	/**
	 * Apply every rule, then hand off to the transport.
	 *
	 * @param array<string,mixed>                          $customer    Customer row.
	 * @param string                                       $kind        Message kind.
	 * @param int                                          $booking_id  Booking id or 0.
	 * @param string                                       $subject     Subject.
	 * @param string                                       $html        Body.
	 * @param array<int,array{name:string,content:string}> $attachments Attachments.
	 * @return string Status: sent, suppressed_reserved, blocked_unverified, blocked_address, blocked_cap or failed.
	 */
	private function deliver( array $customer, string $kind, int $booking_id, string $subject, string $html, array $attachments = array() ): string {
		$email = (string) $customer['email'];

		// Reserved test domains would hard bounce and hurt the shared sender reputation.
		if ( self::is_reserved( $email ) ) {
			return $this->log( $email, $kind, $booking_id, $subject, 'suppressed_reserved' );
		}
		if ( 'verify' !== $kind && empty( $customer['email_verified_at'] ) ) {
			return $this->log( $email, $kind, $booking_id, $subject, 'blocked_unverified' );
		}

		$address_key = 'mail:addr:' . substr( hash( 'sha256', $email ), 0, 40 );
		if ( ! $this->counters->take( $address_key, DAY_IN_SECONDS, Config::mail_per_address() ) ) {
			return $this->log( $email, $kind, $booking_id, $subject, 'blocked_address' );
		}
		if ( ! $this->counters->take( self::CAP_KEY, DAY_IN_SECONDS, Config::mail_daily_cap() ) ) {
			$this->counters->give_back( $address_key, DAY_IN_SECONDS );
			return $this->log( $email, $kind, $booking_id, $subject, 'blocked_cap' );
		}

		$accepted = $this->port->send( $email, $subject, $html, $attachments );
		return $this->log( $email, $kind, $booking_id, $subject, $accepted ? 'sent' : 'failed' );
	}

	/**
	 * Whether an address uses a reserved domain.
	 *
	 * @param string $email Address.
	 */
	public static function is_reserved( string $email ): bool {
		$domain = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
		return 1 === preg_match( '/(^|\.)(example\.(com|net|org)|test|invalid|localhost)$/', $domain );
	}

	/**
	 * Footer on every message.
	 */
	private function footer(): string {
		return '<p style="color:#666;font-size:12px">Demo: fictional places, no real bookings or payments.</p>';
	}

	/**
	 * Record the attempt. The address is stored as a hash.
	 *
	 * @param string $email      Address.
	 * @param string $kind       Kind.
	 * @param int    $booking_id Booking id.
	 * @param string $subject    Subject.
	 * @param string $status     Outcome.
	 */
	private function log( string $email, string $kind, int $booking_id, string $subject, string $status ): string {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'mail_log' ),
			array(
				'to_hash'    => hash( 'sha256', $email ),
				'kind'       => $kind,
				'booking_id' => $booking_id,
				'transport'  => $this->port->name(),
				'status'     => $status,
				'subject'    => substr( $subject, 0, 190 ),
				'created_at' => Db::now(),
			)
		);
		return $status;
	}
}
