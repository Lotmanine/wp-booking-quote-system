<?php
/**
 * Front-end blocks.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Rest\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic blocks for the wizard, the customer portal and the staff portal.
 */
final class Blocks {

	/**
	 * Register on init.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'editor_assets' ) );
	}

	/**
	 * Register block types and the shared front-end script.
	 */
	public static function register_blocks(): void {
		wp_register_style( 'qhs-app', QUILLMOOR_PLUGIN_URL . 'assets/app.css', array(), QUILLMOOR_VERSION );
		wp_register_script( 'qhs-api', QUILLMOOR_PLUGIN_URL . 'assets/api.js', array(), QUILLMOOR_VERSION, true );
		wp_add_inline_script( 'qhs-api', 'window.qhsConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );

		foreach ( array( 'wizard', 'portal', 'staff' ) as $name ) {
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
	 * Front-end configuration. The nonce belongs to the viewer, so it is added at render time.
	 *
	 * @return array<string,string>
	 */
	private static function config(): array {
		return array(
			'rest'         => esc_url_raw( rest_url( Routes::NS . '/' ) ),
			'turnstileKey' => Config::get( 'TURNSTILE_SITE_KEY' ),
		);
	}

	/**
	 * Print the REST nonce beside a block, so cookie-authenticated calls pass.
	 */
	public static function nonce_tag(): string {
		return '<meta name="qhs-nonce" content="' . esc_attr( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ) . '">';
	}
}
