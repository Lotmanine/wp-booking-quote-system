<?php
/**
 * Environment-backed settings.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Every secret and limit comes from the environment, never from options.
 */
final class Config {

	public const HOLD_MINUTES         = 10;
	public const STAY_DAYS_AHEAD      = 270;
	public const DINING_DAYS_AHEAD    = 60;
	public const DINING_LEAD_MINUTES  = 120;
	public const ARRIVAL_WINDOW_HOURS = 36;
	public const REQUEST_HOURS        = 24;
	public const CHANGE_CUTOFF_HOURS  = 24;
	public const MAX_ACTIVE_HOLDS     = 3;
	public const SIGNATURE_WINDOW     = 300;
	public const MAIL_FROM_NAME       = 'Quillmoor (demo)';
	public const CONSENT_TEXT_VERSION = '2026-11-02';

	/**
	 * Read an environment value.
	 *
	 * @param string $name     Variable name.
	 * @param string $fallback Value when unset or empty.
	 */
	public static function get( string $name, string $fallback = '' ): string {
		$value = getenv( $name );
		return ( false === $value || '' === $value ) ? $fallback : (string) $value;
	}

	/**
	 * Read an integer environment value.
	 *
	 * @param string $name     Variable name.
	 * @param int    $fallback Value when unset or empty.
	 */
	public static function int( string $name, int $fallback ): int {
		return (int) self::get( $name, (string) $fallback );
	}

	/**
	 * Whether this install is the public demo.
	 */
	public static function is_demo_site(): bool {
		return '1' === self::get( 'QHS_DEMO_SITE' );
	}

	/**
	 * Payment mode: "stripe" uses WooCommerce Stripe Gateway, "fake" the labelled local gateway.
	 */
	public static function payment_mode(): string {
		return 'fake' === self::get( 'QHS_PAYMENT_MODE' ) ? 'fake' : 'stripe';
	}

	/**
	 * Shared secret for a signing key id.
	 *
	 * @param string $key_id Either "n8n" or "dispatch".
	 */
	public static function hmac_secret( string $key_id ): string {
		$map = array(
			'n8n'      => 'QHS_HMAC_SECRET_N8N',
			'dispatch' => 'QHS_HMAC_SECRET_DISPATCH',
		);
		return isset( $map[ $key_id ] ) ? self::get( $map[ $key_id ] ) : '';
	}

	/**
	 * Global daily email cap, shared with nothing else on this site.
	 */
	public static function mail_daily_cap(): int {
		return self::int( 'QHS_MAIL_DAILY_CAP', 50 );
	}

	/**
	 * Daily email limit per recipient.
	 */
	public static function mail_per_address(): int {
		return self::int( 'QHS_MAIL_PER_ADDRESS_DAILY', 5 );
	}

	/**
	 * n8n webhook that sends email when no Brevo key is set. Empty means log-only.
	 */
	public static function n8n_mail_url(): string {
		return self::get( 'QHS_N8N_MAIL_URL' );
	}

	/**
	 * Accounts created for paying guests per day. Bookings still confirm once it is reached.
	 */
	public static function accounts_daily_cap(): int {
		return self::int( 'QHS_ACCOUNTS_DAILY_CAP', 50 );
	}
}
