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

// Search and share tags. The site runs no SEO plugin, so the theme prints them.
add_action( 'wp_head', 'quillmoor_print_meta_tags', 5 );

/**
 * Meta description for the current view: the home page, a listing's own summary, or a generic line.
 */
function quillmoor_meta_description(): string {
	if ( is_front_page() ) {
		return 'Search fictional stays and restaurants, hold nights or a table, and pay a test deposit. A WordPress and WooCommerce booking demo built by Lotfi Otmanine.';
	}
	if ( is_singular( 'qm_listing' ) ) {
		$id      = (int) get_queried_object_id();
		$summary = trim( wp_strip_all_tags( (string) get_post_meta( $id, 'qm_summary', true ) ) );
		if ( '' !== $summary ) {
			$terms = get_the_terms( $id, 'qm_location' );
			$place = is_array( $terms ) && array() !== $terms ? ', ' . $terms[0]->name : '';
			$kind  = 'dining' === get_post_meta( $id, 'qm_type', true ) ? 'place to eat' : 'place to stay';
			return sprintf( '%s%s. A fictional %s on Quillmoor, a booking demo where nothing can really be booked.', rtrim( $summary, '.' ), $place, $kind );
		}
	}
	return 'Quillmoor is a fictional brand made for a WordPress booking demo. The places, hosts and prices are invented, nothing can really be booked, and no money moves.';
}

/**
 * Print the meta description, OpenGraph and Twitter card tags, and the home page's JSON-LD.
 */
function quillmoor_print_meta_tags(): void {
	$description = quillmoor_meta_description();
	$title       = wp_get_document_title();
	$url         = is_singular() ? (string) wp_get_canonical_url() : '';
	$image       = get_theme_file_uri( 'assets/og-image.jpg' );
	$image_alt   = 'The Quillmoor booking demo home page, with the search bar and places to stay';

	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );

	$properties = array(
		'og:type'         => 'website',
		'og:site_name'    => 'Quillmoor',
		'og:locale'       => 'en_US',
		'og:title'        => $title,
		'og:description'  => $description,
		'og:url'          => $url,
		'og:image'        => $image,
		'og:image:width'  => '1200',
		'og:image:height' => '630',
		'og:image:type'   => 'image/jpeg',
		'og:image:alt'    => $image_alt,
	);
	foreach ( $properties as $property => $content ) {
		if ( '' !== $content ) {
			printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
		}
	}

	$cards = array(
		'twitter:card'        => 'summary_large_image',
		'twitter:title'       => $title,
		'twitter:description' => $description,
		'twitter:image'       => $image,
		'twitter:image:alt'   => $image_alt,
	);
	foreach ( $cards as $name => $content ) {
		printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $content ) );
	}

	if ( is_front_page() ) {
		$graph = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'WebSite',
			'name'        => 'Quillmoor',
			'url'         => home_url( '/' ),
			'description' => $description,
			'creator'     => array(
				'@type'  => 'Person',
				'name'   => 'Lotfi Otmanine',
				'url'    => 'https://oldev.site',
				'sameAs' => array(
					'https://github.com/Lotmanine',
					'https://www.linkedin.com/in/lotfi-otmanine',
					'https://www.upwork.com/freelancers/~01bd748fcb665b90ca',
				),
			),
		);
		wp_print_inline_script_tag( (string) wp_json_encode( $graph, JSON_UNESCAPED_SLASHES ), array( 'type' => 'application/ld+json' ) );
	}
}
