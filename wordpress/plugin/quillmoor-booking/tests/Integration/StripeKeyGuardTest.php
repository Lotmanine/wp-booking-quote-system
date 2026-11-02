<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Security\StripeKeyGuard;
use Quillmoor\Booking\Services;

class StripeKeyGuardTest extends TestCase {

	/** @dataProvider live_keys */
	public function test_live_keys_are_detected( string $key ): void {
		$this->assertTrue( StripeKeyGuard::is_live_key( $key ) );
	}

	public static function live_keys(): array {
		return array(
			'secret'     => array( 'sk_live_abc' ),
			'restricted' => array( 'rk_live_abc' ),
			'public'     => array( 'pk_live_abc' ),
			'padded'     => array( '  sk_live_abc' ),
		);
	}

	/** @dataProvider safe_keys */
	public function test_test_keys_are_not_flagged( string $key ): void {
		$this->assertFalse( StripeKeyGuard::is_live_key( $key ) );
	}

	public static function safe_keys(): array {
		return array(
			'secret' => array( 'sk_test_abc' ),
			'public' => array( 'pk_test_abc' ),
			'empty'  => array( '' ),
		);
	}

	/** @dataProvider env_keys */
	public function test_a_live_key_in_the_environment_blocks_the_site( string $name, string $value ): void {
		$this->env( $name, $value );

		$this->assertTrue( StripeKeyGuard::is_blocked() );
		$this->assertContains( 'env:' . $name, StripeKeyGuard::violations() );
	}

	public static function env_keys(): array {
		return array(
			'test slot holding live secret' => array( 'STRIPE_TEST_SECRET_KEY', 'sk_live_abc' ),
			'test slot holding live public' => array( 'STRIPE_TEST_PUBLISHABLE_KEY', 'pk_live_abc' ),
			'restricted'                    => array( 'STRIPE_SECRET_KEY', 'rk_live_abc' ),
		);
	}

	public function test_violations_never_contain_the_key_value(): void {
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_fixture' );

		$this->assertStringNotContainsString( 'fixture', implode( ' ', StripeKeyGuard::violations() ) );
	}

	public function test_booking_routes_refuse_to_run(): void {
		$id = $this->quote();
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_abc' );

		$hold = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $id,
				'slot_start'      => $this->slot(),
				'turnstile_token' => 'x',
			)
		);
		$quote = $this->rest( 'POST', '/quote', array( 'turnstile_token' => 'x' ) );

		$this->assertSame( 503, $hold->get_status() );
		$this->assertSame( 503, $quote->get_status() );
		$this->assertSame( 'payments_refused', $hold->get_data()['code'] );
	}

	public function test_no_gateway_is_offered(): void {
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_abc' );

		$this->assertSame( array(), StripeKeyGuard::filter_gateways( array( 'stripe' => new \stdClass() ) ) );
		$this->assertFalse( Services::deposit()->is_available() );
	}

	public function test_gateways_stay_with_test_keys(): void {
		$this->assertCount( 1, StripeKeyGuard::filter_gateways( array( 'stripe' => new \stdClass() ) ) );
	}

	public function test_saving_a_live_key_is_refused_and_the_old_value_stays(): void {
		update_option( StripeKeyGuard::OPTION, array( 'title' => 'kept' ) );

		update_option(
			StripeKeyGuard::OPTION,
			array(
				'title'      => 'changed',
				'secret_key' => 'sk_live_abc',
			)
		);

		$stored = $GLOBALS['wpdb']->get_var( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'woocommerce_stripe_settings'" );
		$this->assertStringContainsString( 'kept', $stored );
		$this->assertStringNotContainsString( 'sk_live_abc', $stored );
	}

	public function test_saving_always_forces_test_mode(): void {
		$saved = StripeKeyGuard::on_save( array( 'testmode' => 'no' ), array() );

		$this->assertSame( 'yes', $saved['testmode'] );
	}

	public function test_reading_forces_test_mode_and_hides_live_fields(): void {
		$settings = StripeKeyGuard::on_read(
			array(
				'testmode'   => 'no',
				'secret_key' => 'sk_live_abc',
			)
		);

		$this->assertSame( 'yes', $settings['testmode'] );
		$this->assertSame( '', $settings['secret_key'] );
	}

	public function test_a_live_key_already_stored_blocks_the_site(): void {
		remove_filter( 'pre_update_option_' . StripeKeyGuard::OPTION, array( StripeKeyGuard::class, 'on_save' ) );
		remove_filter( 'option_' . StripeKeyGuard::OPTION, array( StripeKeyGuard::class, 'on_read' ) );
		update_option( StripeKeyGuard::OPTION, array( 'secret_key' => 'sk_live_abc' ) );

		$blocked = StripeKeyGuard::is_blocked();

		add_filter( 'pre_update_option_' . StripeKeyGuard::OPTION, array( StripeKeyGuard::class, 'on_save' ), 10, 2 );
		add_filter( 'option_' . StripeKeyGuard::OPTION, array( StripeKeyGuard::class, 'on_read' ) );
		$this->assertTrue( $blocked );
	}

	public function test_stripe_mode_needs_real_test_keys(): void {
		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );
		$this->assertFalse( Services::deposit()->is_available() );

		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_test_abc' );
		$this->env( 'STRIPE_TEST_PUBLISHABLE_KEY', 'pk_test_abc' );
		$this->assertTrue( Services::deposit()->is_available() );
	}
}
