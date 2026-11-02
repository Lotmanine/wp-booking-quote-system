<?php
/**
 * Demo banner.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the demo notice on the front end, login and admin screens.
 */
final class Banner {

	public const TEXT = 'Demo: fictional company, no real services or payments';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_body_open', array( self::class, 'render' ), 1 );
		add_action( 'login_header', array( self::class, 'render' ), 1 );
		add_action( 'in_admin_header', array( self::class, 'render' ), 1 );
	}

	/**
	 * Print the banner with inline styles, so no theme or checkout template can lose it.
	 */
	public static function render(): void {
		printf(
			'<div class="qhs-demo-banner" role="status" style="background:#fffbeb;color:#78350f;border-bottom:1px solid #fcd34d;padding:8px 16px;text-align:center;font:500 13px/1.45 Outfit,system-ui,sans-serif;letter-spacing:.01em;position:relative;z-index:99999"><span style="display:inline-block;width:7px;height:7px;border-radius:99px;background:#d97706;margin-right:8px;vertical-align:1px" aria-hidden="true"></span>%s</div>',
			esc_html( self::TEXT )
		);
	}
}
