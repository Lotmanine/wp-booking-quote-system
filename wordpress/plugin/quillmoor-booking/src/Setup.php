<?php
/**
 * Site setup.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Demo\DemoReset;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Demo\Seeder;
use Quillmoor\Booking\Payment\WooHooks;
use WC_Install;
use WC_Product_Simple;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent site configuration, run by `wp qhs setup`.
 */
final class Setup {

	/**
	 * Run every step.
	 */
	public static function run(): void {
		Schema::install();
		Roles::sync();
		self::woocommerce();
		self::deposit_product();
		self::pages();
		update_option( 'blogname', 'Quillmoor' );
		update_option( 'blogdescription', 'A fictional place to book stays and tables' );
		update_option( 'timezone_string', 'UTC' );
		update_option( 'users_can_register', 0 );
		update_option( 'default_comment_status', 'closed' );
		wp_delete_post( 1, true );
		wp_delete_post( 2, true );

		// First start after an upgrade: restore the whole demo when allowed, else seed the places.
		if ( ! Seeder::is_seeded() ) {
			if ( DemoReset::is_allowed() ) {
				DemoReset::run();
			} else {
				Seeder::run( false );
			}
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Store settings for a deposit-only shop.
	 */
	private static function woocommerce(): void {
		if ( class_exists( WC_Install::class ) ) {
			WC_Install::create_pages();
		}
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_ship_to_countries', 'disabled' );
		update_option( 'woocommerce_enable_coupons', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
		update_option( 'woocommerce_coming_soon', 'no' );
		update_option( 'woocommerce_store_pages_only', 'no' );
		update_option( 'woocommerce_task_list_hidden', 'yes' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		// A deposit-only shop has no use for the cart and account icons WooCommerce adds to the header.
		delete_option( 'woocommerce_hooked_blocks_version' );
	}

	/**
	 * Create the hidden virtual deposit product.
	 */
	private static function deposit_product(): void {
		$product = wc_get_product( (int) get_option( WooHooks::PRODUCT_OPTION ) );
		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}
		$product->set_name( 'Booking deposit (fictional)' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '20' );
		$product->save();
		update_option( WooHooks::PRODUCT_OPTION, $product->get_id(), false );
	}

	/**
	 * Create pages from the theme's block markup. App pages use the template without a title.
	 */
	private static function pages(): void {
		$explore = '<!-- wp:group {"className":"qm-container qm-explore-page","layout":{"type":"default"}} --><div class="wp-block-group qm-container qm-explore-page">'
			. '<!-- wp:quillmoor/search {"compact":true} /--><!-- wp:quillmoor/explore /--></div><!-- /wp:group -->';
		$credits = '<!-- wp:group {"className":"qm-container qm-narrow","layout":{"type":"default"}} --><div class="wp-block-group qm-container qm-narrow">'
			. '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Photo credits</h1><!-- /wp:heading -->'
			. '<!-- wp:quillmoor/credits /--></div><!-- /wp:group -->';
		$pages   = array(
			'home'          => array( 'Quillmoor', '<!-- wp:pattern {"slug":"quillmoor/home"} /-->', 'page-app' ),
			'explore'       => array( 'Explore', $explore, 'page-app' ),
			'trips'         => array( 'Trips', '<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} --><div class="wp-block-group qm-container"><!-- wp:quillmoor/trips /--></div><!-- /wp:group -->', 'page-app' ),
			'demo'          => array( 'Demo logins', '<!-- wp:pattern {"slug":"quillmoor/demo"} /-->', '' ),
			'photo-credits' => array( 'Photo credits', $credits, 'page-app' ),
		);
		foreach ( $pages as $slug => $page ) {
			$found = get_page_by_path( $slug );
			$id    = wp_insert_post(
				array(
					'ID'           => $found ? $found->ID : 0,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => $page[0],
					'post_content' => $page[1],
				)
			);
			if ( $id > 0 ) {
				update_post_meta( $id, '_wp_page_template', '' !== $page[2] ? $page[2] : 'default' );
			}
			if ( 'home' === $slug ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			}
		}

		// Pages of the home services version. Their old links redirect to the new pages.
		foreach ( array( 'quote', 'services', 'book', 'portal', 'staff' ) as $old_slug ) {
			$old = get_page_by_path( $old_slug );
			if ( $old ) {
				wp_delete_post( $old->ID, true );
			}
		}

		$checkout = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $checkout > 0 ) {
			wp_update_post(
				array(
					'ID'         => $checkout,
					'post_title' => 'Pay your deposit',
				)
			);
		}
	}
}
