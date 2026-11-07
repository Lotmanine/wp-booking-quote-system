<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Accounts\GuestAccounts;
use Quillmoor\Booking\Demo\DemoGuard;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Mail\MailPort;
use Quillmoor\Booking\Security\Actor;
use Quillmoor\Booking\Services;

class GuestAccountTest extends TestCase {

	/** @var array<int,array<string,mixed>> Messages handed to the capturing transport. */
	public array $sent = array();

	public function set_up(): void {
		parent::set_up();
		$this->sent = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'quillmoor_mail_port' );
		parent::tear_down();
	}

	/** Swap in a transport that records what it is given. */
	private function capture(): void {
		$test = $this;
		add_filter(
			'quillmoor_mail_port',
			static fn(): MailPort => new class( $test ) implements MailPort {
				public function __construct( private GuestAccountTest $test ) {}
				public function name(): string {
					return 'capture';
				}
				public function send( string $to, string $subject, string $html, array $attachments = array() ): bool {
					$this->test->sent[] = compact( 'to', 'subject', 'html' );
					return true;
				}
			}
		);
		Services::reset();
	}

	/** Messages with a subject. */
	private function sent_with( string $subject ): array {
		return array_values( array_filter( $this->sent, static fn( array $m ): bool => $subject === $m['subject'] ) );
	}

	private function customer_of( int $booking_id ): array {
		return (array) Services::bookings()->customer( (int) $this->booking( $booking_id )['customer_id'] );
	}

	private function users_with_email( string $email ): int {
		return count( get_users( array( 'search' => $email, 'search_columns' => array( 'user_email' ) ) ) );
	}

	public function test_no_account_at_quote_or_hold(): void {
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ), 2, array(), 'early@example.com' );
		$this->assertFalse( get_user_by( 'email', 'early@example.com' ) );

		$this->hold( $id );

		$this->assertSame( 'hold', $this->state( $id ) );
		$this->assertFalse( get_user_by( 'email', 'early@example.com' ) );
		$this->assertSame( 0, (int) $this->customer_of( $id )['user_id'] );
	}

	public function test_an_expired_hold_makes_no_account(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'lapsed@example.com' );
		$this->expire_hold( $id );
		Services::service()->release_expired_holds();

		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertFalse( get_user_by( 'email', 'lapsed@example.com' ) );
	}

	public function test_paying_creates_a_guest_account_linked_to_the_booking(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'new-guest@example.com' );

		$user = get_user_by( 'email', 'new-guest@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( $user->ID, (int) $this->customer_of( $id )['user_id'] );
		$this->assertSame( 'Test Guest', $user->display_name );
		$this->assertStringStartsWith( 'guest-', $user->user_login );
		$this->assertSame( '1', get_user_meta( $user->ID, GuestAccounts::META, true ) );
		$this->assertFalse( DemoGuard::is_demo_user( $user->ID ) );
		// Signed in with the account, the booking is theirs; nobody was signed in on their behalf.
		$this->assertSame( 0, get_current_user_id() );
		$this->assertTrue( Services::policy()->is_owner( new Actor( $user->ID, '' ), $this->booking( $id ) ) );
	}

	public function test_the_new_account_has_the_guest_role_and_nothing_more(): void {
		$this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'plain@example.com' );

		$user = get_user_by( 'email', 'plain@example.com' );
		$this->assertSame( array( Roles::CUSTOMER ), array_values( $user->roles ) );
		$caps = array_keys( array_filter( $user->allcaps ) );
		sort( $caps );
		$this->assertSame( array( Roles::CUSTOMER, 'read' ), $caps );
		$this->assertFalse( user_can( $user, 'edit_posts' ) );
		$this->assertFalse( user_can( $user, 'upload_files' ) );
		$this->assertFalse( (bool) get_option( 'users_can_register' ) );
	}

	public function test_the_guest_token_link_keeps_working(): void {
		$id    = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'token-keeper@example.com' );
		$token = $this->token( $id );

		$this->assertTrue( Services::policy()->is_owner( new Actor( 0, $token ), $this->booking( $id ) ) );
		$response = $this->rest( 'GET', '/bookings/' . $id, array( 'token' => $token ) );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_one_set_password_email_with_a_working_reset_link(): void {
		$this->capture();
		$this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'river@guest-mail.dev' );

		$user  = get_user_by( 'email', 'river@guest-mail.dev' );
		$setup = $this->sent_with( 'Set your password (demo)' );
		$this->assertCount( 1, $setup );
		$this->assertSame( 'river@guest-mail.dev', $setup[0]['to'] );
		$this->assertSame( array(), $this->sent_with( 'Sign in to see your booking (demo)' ) );
		$this->assertSame( 1, preg_match( '#href="([^"]*wp-login\.php\?action=rp[^"]*)"#', $setup[0]['html'], $m ) );
		$url = html_entity_decode( $m[1] );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( $user->user_login, $query['login'] );
		$this->assertInstanceOf( \WP_User::class, check_password_reset_key( $query['key'], $query['login'] ) );
		$this->assertSame( 1, $this->row_count( 'mail_log', "kind = 'account_setup' AND status = 'sent'" ) );
	}

	public function test_reserved_addresses_get_the_account_but_no_email(): void {
		$this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'reserved@example.com' );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'reserved@example.com' ) );
		$this->assertSame( 1, $this->row_count( 'mail_log', "kind = 'account_setup' AND status = 'suppressed_reserved'" ) );
	}

	public function test_an_existing_account_is_linked_and_told_to_sign_in(): void {
		$this->capture();
		$existing = (int) self::factory()->user->create(
			array(
				'role'       => Roles::CUSTOMER,
				'user_email' => 'returning@guest-mail.dev',
				'user_pass'  => 'their-own-password',
			)
		);
		$hash = get_userdata( $existing )->user_pass;

		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'returning@guest-mail.dev' );

		$this->assertSame( $existing, (int) $this->customer_of( $id )['user_id'] );
		$this->assertSame( 1, $this->users_with_email( 'returning@guest-mail.dev' ) );
		$this->assertSame( $hash, get_userdata( $existing )->user_pass );
		$this->assertSame( '', get_user_meta( $existing, GuestAccounts::META, true ) );
		$this->assertCount( 1, $this->sent_with( 'Sign in to see your booking (demo)' ) );
		$this->assertSame( array(), $this->sent_with( 'Set your password (demo)' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_typed_address_never_attaches_to_a_host_or_a_demo_login(): void {
		$host = (int) self::factory()->user->create(
			array(
				'role'       => Roles::HOST,
				'user_email' => 'host-address@example.com',
			)
		);
		$demo = (int) self::factory()->user->create(
			array(
				'role'       => Roles::CUSTOMER,
				'user_email' => 'shared-login@example.com',
			)
		);
		update_user_meta( $demo, DemoGuard::META, '1' );
		$listing = $this->stay_listing();

		$a = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'host-address@example.com' );
		$b = $this->paid_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'shared-login@example.com' );

		$this->assertSame( 0, (int) $this->customer_of( $a )['user_id'] );
		$this->assertSame( 0, (int) $this->customer_of( $b )['user_id'] );
		$this->assertSame( 1, $this->users_with_email( 'host-address@example.com' ) );
		$this->assertSame( 1, $this->users_with_email( 'shared-login@example.com' ) );
		$this->assertSame( 'confirmed', $this->state( $a ) );
		$this->assertSame( array( Roles::HOST ), array_values( get_userdata( $host )->roles ) );
	}

	public function test_a_signed_in_booking_makes_no_new_account(): void {
		$user    = $this->customer_user( 'member@example.com' );
		$listing = $this->stay_listing();
		$before  = count( get_users( array( 'fields' => 'ID' ) ) );

		$id = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'member@example.com', $user );

		$this->assertSame( $user, (int) $this->customer_of( $id )['user_id'] );
		$this->assertSame( $before, count( get_users( array( 'fields' => 'ID' ) ) ) );
		$this->assertSame( 0, $this->row_count( 'mail_log', "kind LIKE 'account%'" ) );
	}

	public function test_the_daily_cap_stops_new_accounts_but_not_bookings(): void {
		$this->env( 'QHS_ACCOUNTS_DAILY_CAP', '2' );
		$listing = $this->stay_listing();

		$a = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 11 ), 'cap-a@example.com' );
		$b = $this->paid_stay( $listing, $this->day( 12 ), $this->day( 13 ), 'cap-b@example.com' );
		$c = $this->paid_stay( $listing, $this->day( 14 ), $this->day( 15 ), 'cap-c@example.com' );

		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'cap-a@example.com' ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'cap-b@example.com' ) );
		$this->assertFalse( get_user_by( 'email', 'cap-c@example.com' ) );
		$this->assertSame( 'confirmed', $this->state( $c ) );
		$this->assertSame( 0, (int) $this->customer_of( $c )['user_id'] );
		$this->assertSame( 1, $this->row_count( 'alerts', "source = 'accounts'" ) );
		$this->assertSame( 'confirmed', $this->state( $a ) );
		$this->assertSame( 'confirmed', $this->state( $b ) );
	}

	public function test_a_second_paid_booking_reuses_the_account(): void {
		$listing = $this->stay_listing();
		$a       = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 11 ), 'twice@example.com' );
		$b       = $this->paid_stay( $listing, $this->day( 12 ), $this->day( 13 ), 'twice@example.com' );

		$this->assertSame( 1, $this->users_with_email( 'twice@example.com' ) );
		$this->assertSame( (int) $this->customer_of( $a )['user_id'], (int) $this->customer_of( $b )['user_id'] );
		$this->assertSame( 1, $this->row_count( 'mail_log', "kind = 'account_setup'" ) );
	}

	public function test_a_request_booking_gets_the_account_when_the_deposit_is_paid(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ) ), $this->day( 10 ), $this->day( 12 ), 'asks@example.com' );

		$this->assertSame( 'requested', $this->state( $id ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'asks@example.com' ) );
	}

	public function test_a_table_paid_through_the_gateway_gets_the_account(): void {
		$id = $this->table_quote( $this->dining_listing(), $this->seating(), 2, 'diner-account@example.com' );
		$this->hold( $id );
		$this->assertFalse( get_user_by( 'email', 'diner-account@example.com' ) );

		$this->order_for( $id, true );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertInstanceOf( \WP_User::class, get_user_by( 'email', 'diner-account@example.com' ) );
	}

	public function test_an_unpaid_order_makes_no_account(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'unpaid@example.com' );

		$this->order_for( $id, false );

		$this->assertSame( 'hold', $this->state( $id ) );
		$this->assertFalse( get_user_by( 'email', 'unpaid@example.com' ) );
	}
}
