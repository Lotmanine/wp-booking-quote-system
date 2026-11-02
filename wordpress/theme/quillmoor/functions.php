<?php
/**
 * Theme setup.
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		register_block_type( __DIR__ . '/blocks/site-header' );
		register_block_type( __DIR__ . '/blocks/site-footer' );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'quillmoor', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	}
);

// WordPress joins title parts with an en dash by default. The demo copy avoids those dashes.
add_filter(
	'document_title_separator',
	static function (): string {
		return '|';
	}
);

// Preload the body font, so the first paint uses Plus Jakarta Sans instead of swapping.
add_action(
	'wp_head',
	static function (): void {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_theme_file_uri( 'assets/fonts/plus-jakarta-sans-latin-var.woff2' ) )
		);
	},
	1
);

add_action(
	'login_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'quillmoor-login', get_theme_file_uri( 'assets/login.css' ), array(), wp_get_theme()->get( 'Version' ) );
		wp_add_inline_style( 'quillmoor-login', quillmoor_font_faces() );
	}
);

add_filter(
	'login_headerurl',
	static function (): string {
		return home_url( '/' );
	}
);

add_filter(
	'login_headertext',
	static function (): string {
		return 'quillmoor';
	}
);

/**
 * Font face for screens outside the block theme, such as the login page and wp-admin.
 */
function quillmoor_font_faces(): string {
	return sprintf(
		"@font-face{font-family:'Plus Jakarta Sans';src:url('%s') format('woff2');font-weight:200 800;font-style:normal;font-display:swap}",
		esc_url( get_theme_file_uri( 'assets/fonts/plus-jakarta-sans-latin-var.woff2' ) )
	);
}
