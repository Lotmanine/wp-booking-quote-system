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
			'<div class="qhs-demo-banner" role="status" style="background:#fff3cd;color:#3d2f00;border-bottom:2px solid #d9b44a;padding:8px 16px;text-align:center;font:600 14px/1.4 system-ui,sans-serif;position:relative;z-index:99999">%s</div>',
			esc_html( self::TEXT )
		);
	}
}
