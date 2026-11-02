<?php
/**
 * Front-end blocks.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Rest\PublicApi;
use Quillmoor\Booking\Rest\Routes;
use Quillmoor\Booking\Security\StripeKeyGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks: search bar, service results, service page with the reservation card,
 * the customer portal and the staff agenda.
 */
final class Blocks {

	public const NAMES = array( 'search', 'services', 'service', 'portal', 'staff' );

	/**
	 * Register on init.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
		add_action( 'template_redirect', array( self::class, 'redirect_old_quote_page' ) );
	}

	/**
	 * Register block types and the shared front-end script.
	 */
	public static function register_blocks(): void {
		wp_register_style( 'qhs-app', QUILLMOOR_PLUGIN_URL . 'assets/app.css', array(), QUILLMOOR_VERSION );
		wp_register_script( 'qhs-api', QUILLMOOR_PLUGIN_URL . 'assets/api.js', array(), QUILLMOOR_VERSION, true );
		wp_add_inline_script( 'qhs-api', 'window.qhsConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );

		foreach ( self::NAMES as $name ) {
			register_block_type( QUILLMOOR_PLUGIN_DIR . 'blocks/' . $name );
		}
	}

	/**
	 * Show the dynamic blocks as labelled placeholders in the editor.
	 */
	public static function editor_assets(): void {
		wp_enqueue_script( 'qhs-blocks-editor', QUILLMOOR_PLUGIN_URL . 'assets/blocks-editor.js', array( 'wp-blocks', 'wp-element' ), QUILLMOOR_VERSION, true );
	}

	/**
	 * The step-by-step wizard was replaced by the service page. Old links land on the results.
	 */
	public static function redirect_old_quote_page(): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		if ( '/quote' === untrailingslashit( $path ) ) {
			wp_safe_redirect( home_url( '/services/' ), 301 );
			exit;
		}
	}

	/**
	 * Front-end configuration. The nonce belongs to the viewer, so it is added at render time.
	 *
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return array(
			'rest'         => esc_url_raw( rest_url( Routes::NS . '/' ) ),
			'home'         => esc_url_raw( home_url( '/' ) ),
			'turnstileKey' => Config::get( 'TURNSTILE_SITE_KEY' ),
			'holdMinutes'  => Config::HOLD_MINUTES,
			'leadHours'    => Config::MIN_LEAD_HOURS,
			'daysAhead'    => Config::BOOKING_DAYS_AHEAD,
			'mapImage'     => QUILLMOOR_PLUGIN_URL . 'assets/img/map.svg',
			'catalog'      => PublicApi::catalog()->get_data(),
			'ready'        => self::readiness(),
		);
	}

	/**
	 * Why online booking is paused, if it is. The card shows these instead of failing late.
	 *
	 * @return array{open:bool,reasons:array<int,string>}
	 */
	public static function readiness(): array {
		$reasons = array();
		if ( StripeKeyGuard::is_blocked() ) {
			$reasons[] = 'Booking is off: a live payment key was refused.';
		} elseif ( ! Services::deposit()->is_available() ) {
			$reasons[] = 'Deposits are not set up yet.';
		}
		if ( '' === Config::get( 'TURNSTILE_SITE_KEY' ) || '' === Config::get( 'TURNSTILE_SECRET_KEY' ) ) {
			$reasons[] = 'The security check is not set up yet.';
		}
		return array(
			'open'    => array() === $reasons,
			'reasons' => $reasons,
		);
	}

	/**
	 * Print the REST nonce beside a block, so cookie-authenticated calls pass.
	 */
	public static function nonce_tag(): string {
		return '<meta name="qhs-nonce" content="' . esc_attr( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) . '">';
	}

	/**
	 * Read a catalog key from the query string, or an empty string when it is not in the group.
	 *
	 * @param string $param Query parameter.
	 * @param string $group Catalog group.
	 */
	public static function query_key( string $param, string $group ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter values, checked against the catalog.
		$value   = isset( $_GET[ $param ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $param ] ) ) : '';
		$catalog = Catalog::all();
		return isset( $catalog[ $group ][ $value ] ) ? $value : '';
	}

	/**
	 * Read a date (Y-m-d) from the query string.
	 *
	 * @param string $param Query parameter.
	 */
	public static function query_date( string $param ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter value, pattern checked.
		$value = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) ) : '';
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Small inline icon set, so the blocks need no icon font.
	 *
	 * @param string $name Icon name.
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'cleaning' => '<path d="M7 21h10M9 21V9h6v12M10 9V5h4v4M14 5h3l1 2" />',
			'repairs'  => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.6-.4-.4-2.6z" />',
			'garden'   => '<path d="M12 21v-8M12 13c-4 0-6-3-6-7 4 0 6 3 6 7zM12 13c4 0 6-3 6-7-4 0-6 3-6 7z" />',
			'all'      => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" />',
			'pin'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z" /><circle cx="12" cy="10" r="2.5" />',
			'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2" /><path d="M4 10h16M9 3v4M15 3v4" />',
			'home'     => '<path d="M4 11l8-7 8 7v9H4z" /><path d="M10 20v-5h4v5" />',
			'search'   => '<circle cx="11" cy="11" r="6.5" /><path d="M16 16l4.5 4.5" />',
			'clock'    => '<circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" />',
			'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5" />',
		);
		return '<svg class="qhs-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
