<?php
/**
 * Front-end blocks.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\Search;
use Quillmoor\Booking\Rest\PublicApi;
use Quillmoor\Booking\Rest\Routes;
use Quillmoor\Booking\Security\StripeKeyGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks: the search pill, the explore grid with its map, the listing page with the booking
 * card, the guest's trips and the photo credits.
 */
final class Blocks {

	public const NAMES = array( 'search', 'explore', 'listing', 'trips', 'credits' );

	public const TILE_URL         = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
	public const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

	/**
	 * Register on init.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'template_redirect', array( self::class, 'redirect_old_pages' ) );
	}

	/**
	 * Register block types and the shared front-end assets.
	 */
	public static function register_blocks(): void {
		wp_register_style( 'leaflet', QUILLMOOR_PLUGIN_URL . 'assets/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'leaflet', QUILLMOOR_PLUGIN_URL . 'assets/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_style( 'qhs-app', QUILLMOOR_PLUGIN_URL . 'assets/app.css', array(), QUILLMOOR_VERSION );
		wp_register_script( 'qhs-api', QUILLMOOR_PLUGIN_URL . 'assets/api.js', array(), QUILLMOOR_VERSION, true );
		wp_add_inline_script( 'qhs-api', 'window.qhsConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );

		foreach ( self::NAMES as $name ) {
			register_block_type( QUILLMOOR_PLUGIN_DIR . 'blocks/' . $name );
		}
	}

	/**
	 * Old pages of the home services version land on their replacements.
	 */
	public static function redirect_old_pages(): void {
		$path = untrailingslashit( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
		$map  = array(
			'/quote'    => '/explore/',
			'/services' => '/explore/',
			'/book'     => '/explore/',
			'/staff'    => '/wp-admin/admin.php?page=qhs-dashboard',
			'/portal'   => '/trips/',
		);
		if ( isset( $map[ $path ] ) ) {
			// Keep the guest link parameters when an old portal link is opened.
			$query = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passed through esc_url_raw by the redirect.
			wp_safe_redirect( home_url( $map[ $path ] ) . ( '/portal' === $path && '' !== $query ? '?' . $query : '' ), 301 );
			exit;
		}
	}

	/**
	 * Front-end configuration. The nonce belongs to the viewer, so it is printed at render time.
	 *
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return array(
			'rest'         => esc_url_raw( rest_url( Routes::NS . '/' ) ),
			'home'         => esc_url_raw( home_url( '/' ) ),
			'turnstileKey' => Config::get( 'TURNSTILE_SITE_KEY' ),
			'holdMinutes'  => Config::HOLD_MINUTES,
			'tiles'        => self::TILE_URL,
			'attribution'  => self::TILE_ATTRIBUTION,
		);
	}

	/**
	 * What the booking card needs to say up front.
	 *
	 * @return array{guests:bool,payments:bool,payments_label:string,blocked:bool}
	 */
	public static function readiness(): array {
		return array(
			'guests'         => PublicApi::guests_can_reserve(),
			'payments'       => Services::deposit()->is_available(),
			'payments_label' => self::payments_label(),
			'blocked'        => StripeKeyGuard::is_blocked(),
		);
	}

	/**
	 * Payment set-up in words.
	 */
	public static function payments_label(): string {
		if ( StripeKeyGuard::is_blocked() ) {
			return 'Off: a live payment key was refused';
		}
		if ( 'fake' === Config::payment_mode() ) {
			return 'Local fake gateway (tests only, not Stripe)';
		}
		return StripeKeyGuard::has_test_keys() ? 'Stripe in test mode' : 'Not set up yet: no Stripe test keys';
	}

	/**
	 * Print the REST nonce beside a block, so cookie-authenticated calls pass.
	 */
	public static function nonce_tag(): string {
		return '<meta name="qhs-nonce" content="' . esc_attr( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) . '">';
	}

	/**
	 * Search filters from the query string.
	 *
	 * @return array{type:string,location:string,category:string,check_in:string,check_out:string,guests:int,date:string,party:int}
	 */
	public static function query_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search filters, each value validated.
		return Search::filters( wp_unslash( $_GET ) );
	}

	/**
	 * Listing shown on the current single listing page.
	 */
	public static function current_listing(): ?Listing {
		$id = (int) get_queried_object_id();
		return $id > 0 ? Listing::find( $id ) : null;
	}

	/**
	 * Inline icon set, so the blocks need no icon font.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size.
	 */
	public static function icon( string $name, int $size = 24 ): string {
		$paths = self::icon_paths();
		return '<svg class="qm-i" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? $paths['check'] ) . '</svg>';
	}

	/**
	 * Icon paths, shared with the scripts through the same names.
	 *
	 * @return array<string,string>
	 */
	public static function icon_paths(): array {
		return array(
			'home'       => '<path d="M4 11l8-7 8 7v9H4z"/><path d="M10 20v-5h4v5"/>',
			'cabin'      => '<path d="M3 12l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/><path d="M5 14h14"/>',
			'beach'      => '<path d="M3 20c2-1.3 4-1.3 6 0s4 1.3 6 0 4-1.3 6 0"/><path d="M12 16V7"/><path d="M4 9a8 8 0 0 1 16 0z"/>',
			'city'       => '<path d="M4 20V9l5-3v14"/><path d="M9 20V4l7 3v13"/><path d="M16 20v-9l4 2v7"/><path d="M3 20h18"/>',
			'country'    => '<path d="M3 19l6-9 4 6 3-4 5 7z"/><circle cx="17" cy="6" r="2"/>',
			'pool'       => '<path d="M3 17c2-1.3 4-1.3 6 0s4 1.3 6 0 4-1.3 6 0"/><path d="M8 14V5a2 2 0 0 1 4 0"/><path d="M14 14V5a2 2 0 0 1 4 0"/><path d="M8 9h6"/>',
			'view'       => '<path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/>',
			'design'     => '<path d="M4 20h16"/><path d="M6 20V9l6-5 6 5v11"/><path d="M10 13h4v7h-4z"/>',
			'bistro'     => '<path d="M7 3v8a2 2 0 0 0 4 0V3"/><path d="M9 11v10"/><path d="M16 21V3c2 1.5 3 4 3 7h-3"/>',
			'fine'       => '<circle cx="12" cy="13" r="6"/><path d="M12 7V3"/><path d="M4 21h16"/>',
			'cafe'       => '<path d="M4 9h12v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M16 10h2a2.5 2.5 0 0 1 0 5h-2"/><path d="M8 3v3M12 3v3"/>',
			'wine'       => '<path d="M8 3h8l-1 6a3 3 0 0 1-6 0z"/><path d="M12 12v8"/><path d="M8 21h8"/>',
			'terrace'    => '<path d="M3 9h18l-2-4H5z"/><path d="M12 9v12"/><path d="M6 21v-6h12v6"/>',
			'wifi'       => '<path d="M2 9a15 15 0 0 1 20 0"/><path d="M5.5 12.5a10 10 0 0 1 13 0"/><path d="M9 16a5 5 0 0 1 6 0"/><circle cx="12" cy="19.5" r=".8"/>',
			'kitchen'    => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M4 10h16"/><path d="M8 6.5h.01M8 14v3"/>',
			'parking'    => '<rect x="4" y="3" width="16" height="18" rx="3"/><path d="M10 17V8h3a2.5 2.5 0 0 1 0 5h-3"/>',
			'washer'     => '<rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="13" r="4.5"/><path d="M8 6.5h.01"/>',
			'air'        => '<path d="M3 8h12a3 3 0 1 0-3-3"/><path d="M3 12h16a3 3 0 1 1-3 3"/><path d="M3 16h8"/>',
			'heating'    => '<path d="M12 21a6 6 0 0 0 6-6c0-4-6-11-6-11S6 11 6 15a6 6 0 0 0 6 6z"/>',
			'fire'       => '<path d="M12 21c-4 0-6-2.7-6-6 0-4 4-6 4-11 3 2 8 6 8 11 0 3.3-2 6-6 6z"/><path d="M12 21c-1.7 0-3-1.2-3-3 0-2 3-3.5 3-6 1.5 1.5 3 3.5 3 6 0 1.8-1.3 3-3 3z"/>',
			'workspace'  => '<rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/>',
			'key'        => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3"/>',
			'leaf'       => '<path d="M5 19c0-8 6-14 15-14 0 9-6 15-14 15"/><path d="M5 19l8-8"/>',
			'accessible' => '<circle cx="12" cy="4.5" r="1.6"/><path d="M8 9h8M12 9v5l4 6M12 14l-4 6"/>',
			'child'      => '<circle cx="12" cy="6" r="3"/><path d="M6 21v-3a6 6 0 0 1 12 0v3"/>',
			'pet'        => '<circle cx="6" cy="10" r="2"/><circle cx="10" cy="6" r="2"/><circle cx="14" cy="6" r="2"/><circle cx="18" cy="10" r="2"/><path d="M12 12c-3 0-5 3.5-5 6 0 2 2 2.5 5 1 3 1.5 5 1 5-1 0-2.5-2-6-5-6z"/>',
			'smoke'      => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/><path d="M12 4v2M12 18v2"/>',
			'veg'        => '<path d="M12 21c-4-3-7-7-7-11 3 0 5 1.5 7 4 2-2.5 4-4 7-4 0 4-3 8-7 11z"/>',
			'calendar'   => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
			'door'       => '<path d="M6 21V4h12v17"/><path d="M3 21h18"/><path d="M14 12h.01"/>',
			'pin'        => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
			'search'     => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
			'clock'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
			'check'      => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
			'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'grid'       => '<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/>',
			'map'        => '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z"/><path d="M9 4v14M15 6v14"/>',
			'bed'        => '<path d="M3 18V7M3 13h18v5M21 18v-3a3 3 0 0 0-3-3H10v1"/><circle cx="7" cy="10.5" r="1.5"/>',
			'shield'     => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
			'table'      => '<path d="M3 9h18M6 9v11M18 9v11M8 5h8"/>',
		);
	}
}
