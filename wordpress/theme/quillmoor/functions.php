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

// Preload the body font, so the first paint uses Outfit instead of swapping.
add_action(
	'wp_head',
	static function (): void {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( get_theme_file_uri( 'assets/fonts/outfit-latin-var.woff2' ) )
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
		return 'Quillmoor Home Services';
	}
);

/**
 * Font faces for screens outside the block theme, such as the login page and wp-admin.
 */
function quillmoor_font_faces(): string {
	$faces = array(
		'Outfit'         => array( 'outfit-latin-var.woff2', '400 700' ),
		'JetBrains Mono' => array( 'jetbrains-mono-latin-var.woff2', '400 500' ),
	);
	$css   = '';
	foreach ( $faces as $family => $face ) {
		$css .= sprintf(
			"@font-face{font-family:'%s';src:url('%s') format('woff2');font-weight:%s;font-style:normal;font-display:swap}",
			$family,
			esc_url( get_theme_file_uri( 'assets/fonts/' . $face[0] ) ),
			$face[1]
		);
	}
	return $css;
}

/**
 * Main menu links, with the current page marked.
 *
 * @return array<int,array{label:string,url:string,active:bool}>
 */
function quillmoor_menu_links(): array {
	$path  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never printed.
	$links = array(
		array( __( 'Services', 'quillmoor' ), '/services/', array( '/services/', '/book/' ) ),
		array( __( 'How it works', 'quillmoor' ), '/#how-it-works', array() ),
		array( __( 'Your bookings', 'quillmoor' ), '/portal/', array( '/portal/' ) ),
		array( __( 'Staff', 'quillmoor' ), '/staff/', array( '/staff/' ) ),
		array( __( 'Demo logins', 'quillmoor' ), '/demo/', array( '/demo/' ) ),
	);
	$out   = array();
	foreach ( $links as $link ) {
		$out[] = array(
			'label'  => $link[0],
			'url'    => home_url( $link[1] ),
			'active' => in_array( trailingslashit( $path ), $link[2], true ),
		);
	}
	return $out;
}
