<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Demo\Banner;
use Quillmoor\Booking\Demo\DemoGuard;
use Quillmoor\Booking\Demo\DemoReset;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Security\BookingPolicy;
use RuntimeException;

class DemoSafetyTest extends TestCase {

	private function demo_user( string $role ): int {
		$user = $this->user( $role );
		update_user_meta( $user, DemoGuard::META, '1' );
		return $user;
	}

	private function allow_reset(): void {
		$this->env( 'QHS_DEMO_SITE', '1' );
		$this->env( 'QHS_DEMO_HOST', 'example.org' );
		$this->env( 'QHS_DEMO_PASSWORD', 'test-password' );
	}

	public function test_reset_refuses_without_the_flag(): void {
		$this->env( 'QHS_DEMO_SITE', '0' );
		$this->env( 'QHS_DEMO_HOST', 'example.org' );
		$id = $this->quote();

		try {
			DemoReset::run();
			$this->fail( 'Reset should refuse.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Refusing', $e->getMessage() );
		}
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_reset_refuses_on_another_host(): void {
		$this->env( 'QHS_DEMO_SITE', '1' );
		$this->env( 'QHS_DEMO_HOST', 'booking.oldev.site' );

		$this->expectException( RuntimeException::class );
		DemoReset::run();
	}

	public function test_scheduled_reset_does_nothing_when_not_allowed(): void {
		$this->env( 'QHS_DEMO_SITE', '0' );
		$id = $this->quote();

		DemoReset::run_scheduled();

		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_reset_removes_demo_data_and_restores_accounts(): void {
		$this->allow_reset();
		$this->confirmed();
		$admin = $this->user( 'administrator' );

		DemoReset::run();

		$this->assertSame( 0, $this->row_count( 'bookings' ) );
		$this->assertSame( 0, $this->row_count( 'slot_claims' ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $admin ) );
		foreach ( array( DemoReset::CUSTOMER_LOGIN, DemoReset::STAFF_LOGIN, DemoReset::MANAGER_LOGIN ) as $login ) {
			$user = get_user_by( 'login', $login );
			$this->assertInstanceOf( \WP_User::class, $user, $login );
			$this->assertTrue( DemoGuard::is_demo_user( $user->ID ), $login );
		}
		$this->assertTrue( wp_check_password( 'test-password', get_user_by( 'login', DemoReset::CUSTOMER_LOGIN )->user_pass ) );
	}

	public function test_reset_restores_a_changed_password(): void {
		$this->allow_reset();
		DemoReset::run();
		$user = get_user_by( 'login', DemoReset::CUSTOMER_LOGIN );
		wp_set_password( 'changed', $user->ID );

		DemoReset::run();

		$this->assertTrue( wp_check_password( 'test-password', get_user_by( 'login', DemoReset::CUSTOMER_LOGIN )->user_pass ) );
	}

	public function test_reset_keeps_the_email_counter(): void {
		$this->allow_reset();
		\Quillmoor\Booking\Services::counters()->take( 'mail:daily', DAY_IN_SECONDS, 50 );

		DemoReset::run();

		$this->assertSame( 1, \Quillmoor\Booking\Services::mail()->used_today() );
	}

	public function test_reset_also_clears_the_dispatch_service(): void {
		$this->allow_reset();
		$this->env( 'QHS_DISPATCH_INTERNAL_URL', 'http://dispatch.test' );
		$this->env( 'DISPATCH_API_KEY', 'key' );

		DemoReset::run();

		$call = end( $this->http );
		$this->assertSame( 'http://dispatch.test/api/v1/assignments', $call['url'] );
		$this->assertSame( 'DELETE', $call['args']['method'] );
		$this->assertSame( 'key', $call['args']['headers']['X-Api-Key'] );
	}

	public function test_a_failed_dispatch_reset_raises_an_alert(): void {
		$this->allow_reset();
		$this->env( 'QHS_DISPATCH_INTERNAL_URL', 'http://dispatch.test' );

		DemoReset::run();

		// The fake transport answers 201 here, which is not the 200 the service sends.
		$this->assertSame( 1, $this->row_count( 'alerts' ) );
	}

	public function test_demo_users_cannot_edit_themselves(): void {
		$user = $this->demo_user( Roles::CUSTOMER );
		$this->assertFalse( user_can( $user, 'edit_user', $user ) );

		$normal = $this->user( Roles::CUSTOMER );
		$this->assertTrue( user_can( $normal, 'edit_user', $normal ) );
	}

	public function test_rest_api_refuses_a_demo_user_changing_their_email(): void {
		$user = $this->demo_user( Roles::CUSTOMER );
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'email', 'stolen@example.com' );
		$request->set_param( 'password', 'stolen' );

		$this->assertGreaterThanOrEqual( 401, rest_do_request( $request )->get_status() );
		$this->assertNotSame( 'stolen@example.com', get_userdata( $user )->user_email );
	}

	public function test_password_reset_is_off_for_demo_users(): void {
		$user = $this->demo_user( Roles::STAFF );
		$this->assertFalse( apply_filters( 'allow_password_reset', true, $user ) );
	}

	public function test_application_passwords_are_off_for_demo_users(): void {
		$user = $this->demo_user( Roles::MANAGER );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $user ) );
	}

	public function test_profile_saves_are_refused(): void {
		$user   = $this->demo_user( Roles::CUSTOMER );
		$errors = new \WP_Error();

		DemoGuard::refuse_profile_update( $errors, true, (object) array( 'ID' => $user ) );

		$this->assertSame( 'qhs_demo_account', $errors->get_error_code() );
	}

	public function test_demo_admin_is_not_an_administrator(): void {
		$user = $this->demo_user( Roles::MANAGER );

		foreach ( array( 'manage_options', 'activate_plugins', 'edit_users', 'install_plugins', 'edit_theme_options', 'edit_posts', 'manage_woocommerce', 'unfiltered_html' ) as $cap ) {
			$this->assertFalse( user_can( $user, $cap ), $cap );
		}
		$this->assertTrue( user_can( $user, BookingPolicy::CAP_MANAGE ) );
	}

	public function test_customer_and_staff_roles_stay_minimal(): void {
		$this->assertFalse( user_can( $this->user( Roles::CUSTOMER ), BookingPolicy::CAP_WORK ) );
		$this->assertFalse( user_can( $this->user( Roles::STAFF ), BookingPolicy::CAP_MANAGE ) );
	}

	public function test_banner_text_is_exact(): void {
		ob_start();
		Banner::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Demo: fictional company, no real services or payments', $html );
	}

	public function test_banner_hooks_cover_front_login_and_admin(): void {
		foreach ( array( 'wp_body_open', 'login_header', 'in_admin_header' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook, array( Banner::class, 'render' ) ), $hook );
		}
	}
}
