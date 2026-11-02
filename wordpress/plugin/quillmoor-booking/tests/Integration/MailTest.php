<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Mail\BrevoMailer;
use Quillmoor\Booking\Mail\MailPort;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Services;

class MailTest extends TestCase {

	private function verified( string $email ): array {
		$row = Services::bookings()->upsert_customer( $email, 'Mail Person', 0 );
		Services::bookings()->update_customer( (int) $row['id'], array( 'email_verified_at' => Db::now() ) );
		return Services::bookings()->customer( (int) $row['id'] );
	}

	private function booking_for( array $customer ): array {
		$id = Services::bookings()->insert(
			array(
				'quote_id'    => 0,
				'customer_id' => (int) $customer['id'],
				'state'       => 'confirmed',
				'service'     => 'cleaning',
				'area'        => 'north',
				'slot_start'  => Db::now( 86400 ),
				'access_key'  => str_repeat( 'a', 32 ),
				'created_at'  => Db::now(),
			)
		);
		return Services::bookings()->find( $id );
	}

	public function test_wp_mail_is_closed_so_nothing_bypasses_the_gate(): void {
		$this->assertFalse( wp_mail( 'x@real-domain.co', 'Subject', 'Body' ) );
		$this->assertSame( array(), $GLOBALS['phpmailer']->mock_sent ?? array() );
	}

	public function test_unverified_addresses_get_no_customer_email(): void {
		$row = Services::bookings()->upsert_customer( 'new@real-domain.test.co', 'New', 0 );

		$this->assertSame( 'blocked_unverified', Services::mail()->send_reminder( $this->booking_for( $row ) ) );
	}

	public function test_a_verified_address_is_sent(): void {
		$this->assertSame( 'sent', Services::mail()->send_reminder( $this->booking_for( $this->verified( 'yes@real-domain.co' ) ) ) );
	}

	public function test_verification_mail_reaches_an_unverified_address(): void {
		$row = Services::bookings()->upsert_customer( 'new@real-domain.co', 'New', 0 );

		$this->assertSame( 'sent', Services::mail()->send_verification( $row ) );
	}

	public function test_reserved_domains_are_never_sent(): void {
		$sent   = 0;
		$filter = static function () use ( &$sent ): MailPort {
			return new class( $sent ) implements MailPort {
				public function __construct( private int &$count ) {}
				public function name(): string {
					return 'count';
				}
				public function send( string $to, string $subject, string $html, array $attachments = array() ): bool {
					++$this->count;
					return true;
				}
			};
		};
		add_filter( 'quillmoor_mail_port', $filter );
		Services::reset();

		$this->assertSame( 'suppressed_reserved', Services::mail()->send_reminder( $this->booking_for( $this->verified( 'x@example.com' ) ) ) );
		$this->assertSame( 0, $sent );
		remove_filter( 'quillmoor_mail_port', $filter );
	}

	public function test_daily_cap_stops_at_fifty(): void {
		$statuses = array();
		for ( $i = 0; $i < 53; $i++ ) {
			$statuses[] = Services::mail()->send_reminder( $this->booking_for( $this->verified( "cap{$i}@real-domain.co" ) ) );
		}

		$this->assertSame( 50, count( array_keys( $statuses, 'sent', true ) ) );
		$this->assertSame( 3, count( array_keys( $statuses, 'blocked_cap', true ) ) );
		$this->assertSame( 50, Services::mail()->used_today() );
	}

	public function test_per_address_limit(): void {
		$booking  = $this->booking_for( $this->verified( 'one@real-domain.co' ) );
		$statuses = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$statuses[] = Services::mail()->send_reminder( $booking );
		}

		$this->assertSame( 5, count( array_keys( $statuses, 'sent', true ) ) );
		$this->assertSame( 2, count( array_keys( $statuses, 'blocked_address', true ) ) );
	}

	public function test_a_blocked_cap_gives_the_address_unit_back(): void {
		$this->env( 'QHS_MAIL_DAILY_CAP', '0' );
		$booking = $this->booking_for( $this->verified( 'back@real-domain.co' ) );

		$this->assertSame( 'blocked_cap', Services::mail()->send_reminder( $booking ) );
		$this->env( 'QHS_MAIL_DAILY_CAP', '50' );
		$this->assertSame( 'sent', Services::mail()->send_reminder( $booking ) );
	}

	public function test_log_stores_a_hash_not_the_address(): void {
		Services::mail()->send_reminder( $this->booking_for( $this->verified( 'private@real-domain.co' ) ) );

		$row = $GLOBALS['wpdb']->get_row( 'SELECT * FROM ' . Db::table( 'mail_log' ) . ' LIMIT 1', ARRAY_A );
		$this->assertStringNotContainsString( 'private', implode( ' ', array_map( 'strval', $row ) ) );
	}

	public function test_brevo_sends_from_the_fixed_authenticated_sender(): void {
		$mailer = new BrevoMailer( 'test-key' );

		$this->assertTrue( $mailer->send( 'x@real-domain.co', 'Subject', '<p>Hi</p>' ) );

		$call = end( $this->http );
		$body = json_decode( $call['args']['body'], true );
		$this->assertStringContainsString( 'api.brevo.com/v3/smtp/email', $call['url'] );
		$this->assertSame( 'lotfi@oldev.site', $body['sender']['email'] );
		$this->assertArrayNotHasKey( 'replyTo', $body );
	}

	public function test_reminders_run_once_per_booking(): void {
		$customer = $this->verified( 'r@real-domain.co' );
		$this->booking_for( $customer );

		$first  = Services::service()->run_reminders( 48 );
		$second = Services::service()->run_reminders( 48 );

		$this->assertSame( 1, $first['sent'] );
		$this->assertSame( 0, $second['due'] );
	}

	public function test_reminders_over_the_cap_stay_due(): void {
		$this->env( 'QHS_MAIL_DAILY_CAP', '0' );
		$this->booking_for( $this->verified( 'later@real-domain.co' ) );

		$this->assertSame( 0, Services::service()->run_reminders( 48 )['sent'] );

		$this->env( 'QHS_MAIL_DAILY_CAP', '50' );
		$this->assertSame( 1, Services::service()->run_reminders( 48 )['sent'] );
	}

	public function test_followups_wait_for_completion(): void {
		$customer = $this->verified( 'f@real-domain.co' );
		$booking  = $this->booking_for( $customer );
		$this->assertSame( 0, Services::service()->run_followups( 0 )['due'] );

		Services::bookings()->update(
			(int) $booking['id'],
			array(
				'state'        => 'completed',
				'completed_at' => Db::now( -60 ),
			)
		);

		$this->assertSame( 1, Services::service()->run_followups( 0 )['sent'] );
		$this->assertSame( 0, Services::service()->run_followups( 0 )['due'] );
	}
}
