<?php
/**
 * Theme setup.
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'quillmoor', get_stylesheet_uri(), array(), wp_get_theme()->get( 'Version' ) );
	}
);
