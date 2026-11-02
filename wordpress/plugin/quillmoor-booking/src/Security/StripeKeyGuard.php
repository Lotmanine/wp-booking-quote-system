<?php
/**
 * Live Stripe key refusal.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * This site takes test payments only. A live key anywhere stops every payment path.
 */
final class StripeKeyGuard {

	public const OPTION = 'woocommerce_stripe_settings';

	private const ENV_NAMES = array(
		'STRIPE_TEST_SECRET_KEY',
		'STRIPE_TEST_PUBLISHABLE_KEY',
		'STRIPE_SECRET_KEY',
		'STRIPE_PUBLISHABLE_KEY',
		'STRIPE_KEY',
		'STRIPE_SECRET',
	);

	/**
	 * Whether a value is a live Stripe key.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function is_live_key( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\s*(sk|rk|pk)_live_/', $value );
	}

	/**
	 * Find what is wrong, without ever returning a key value.
	 *
	 * @return string[] Names of the offending settings.
	 */
	public static function violations(): array {
		$found = array();
		foreach ( self::ENV_NAMES as $name ) {
			if ( self::is_live_key( getenv( $name ) ) ) {
				$found[] = 'env:' . $name;
			}
		}
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) ) {
			foreach ( $stored as $key => $value ) {
				if ( self::is_live_key( $value ) ) {
					$found[] = 'option:' . $key;
				}
			}
		}
		return $found;
	}

	/**
	 * Whether the site must refuse payments.
	 */
	public static function is_blocked(): bool {
		return array() !== self::violations();
	}

	/**
	 * Register the guards.
	 */
	public static function register(): void {
		add_filter( 'pre_update_option_' . self::OPTION, array( self::class, 'on_save' ), 10, 2 );
		add_filter( 'option_' . self::OPTION, array( self::class, 'on_read' ) );
		add_filter( 'default_option_' . self::OPTION, array( self::class, 'on_read' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( self::class, 'filter_gateways' ), 99 );
		add_action( 'admin_notices', array( self::class, 'admin_notice' ) );
	}

	/**
	 * Refuse live keys on save and keep the stored value.
	 *
	 * @param mixed $value Incoming settings.
	 * @param mixed $old   Stored settings.
	 * @return mixed
	 */
	public static function on_save( mixed $value, mixed $old ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $item ) {
			if ( self::is_live_key( $item ) ) {
				return $old;
			}
		}
		$value['testmode'] = 'yes';
		return $value;
	}

	/**
	 * Force test mode on every read and take test keys from the environment.
	 *
	 * @param mixed $settings Stored settings.
	 * @return array<string,mixed>
	 */
	public static function on_read( mixed $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();

		$settings['testmode']             = 'yes';
		$settings['publishable_key']      = '';
		$settings['secret_key']           = '';
		$settings['webhook_secret']       = '';
		$settings['test_publishable_key'] = Config::get( 'STRIPE_TEST_PUBLISHABLE_KEY' );
		$settings['test_secret_key']      = Config::get( 'STRIPE_TEST_SECRET_KEY' );
		$settings['test_webhook_secret']  = Config::get( 'STRIPE_TEST_WEBHOOK_SECRET' );
		$settings['test_connection_type'] = 'api';
		$settings['enabled']              = ( 'stripe' === Config::payment_mode() && self::has_test_keys() ) ? 'yes' : 'no';

		return $settings;
	}

	/**
	 * Whether test keys are present and shaped like test keys.
	 */
	public static function has_test_keys(): bool {
		return str_starts_with( Config::get( 'STRIPE_TEST_SECRET_KEY' ), 'sk_test_' )
			&& str_starts_with( Config::get( 'STRIPE_TEST_PUBLISHABLE_KEY' ), 'pk_test_' );
	}

	/**
	 * Fail closed: with a live key present no gateway is offered at all.
	 *
	 * @param array<string,mixed> $gateways Available gateways.
	 * @return array<string,mixed>
	 */
	public static function filter_gateways( array $gateways ): array {
		return self::is_blocked() ? array() : $gateways;
	}

	/**
	 * Tell administrators why payments are off, naming the setting and never its value.
	 */
	public static function admin_notice(): void {
		$violations = self::violations();
		if ( array() === $violations ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s %s</p></div>',
			esc_html__( 'Live Stripe key refused. Payments and bookings are off until it is removed:', 'quillmoor-booking' ),
			esc_html( implode( ', ', $violations ) )
		);
	}
}
