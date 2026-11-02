<?php
/**
 * Listing post type and taxonomies.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Security\BookingPolicy;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Listings are posts with their own capabilities, so a host edits only what they own.
 */
final class PostTypes {

	public const LISTING  = 'qm_listing';
	public const LOCATION = 'qm_location';
	public const AMENITY  = 'qm_amenity';
	public const CATEGORY = 'qm_category';

	/**
	 * Primitive capabilities a host gets for their own listings.
	 */
	public const HOST_CAPS = array(
		'edit_qm_listings',
		'publish_qm_listings',
		'edit_published_qm_listings',
		'delete_qm_listings',
		'delete_published_qm_listings',
	);

	/**
	 * Extra capabilities a manager gets to act on every listing.
	 */
	public const MANAGER_CAPS = array(
		'edit_others_qm_listings',
		'delete_others_qm_listings',
		'read_private_qm_listings',
		'edit_private_qm_listings',
		'delete_private_qm_listings',
	);

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_types' ), 5 );
		add_action( 'post_updated', array( self::class, 'follow_owner' ), 10, 3 );
	}

	/**
	 * When a listing changes owner, its bookings move to the new host.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public static function follow_owner( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( self::LISTING !== $after->post_type || (int) $after->post_author === (int) $before->post_author ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table, nothing to cache.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET host_user_id = %d WHERE listing_id = %d', Db::table( 'bookings' ), (int) $after->post_author, $post_id ) );
	}

	/**
	 * Register the post type and taxonomies.
	 */
	public static function register_types(): void {
		register_post_type(
			self::LISTING,
			array(
				'labels'          => array(
					'name'               => __( 'Listings', 'quillmoor-booking' ),
					'singular_name'      => __( 'Listing', 'quillmoor-booking' ),
					'add_new'            => __( 'Add listing', 'quillmoor-booking' ),
					'add_new_item'       => __( 'Add a new listing', 'quillmoor-booking' ),
					'edit_item'          => __( 'Edit listing', 'quillmoor-booking' ),
					'all_items'          => __( 'Listings', 'quillmoor-booking' ),
					'search_items'       => __( 'Search listings', 'quillmoor-booking' ),
					'not_found'          => __( 'No listings yet.', 'quillmoor-booking' ),
					'not_found_in_trash' => __( 'No listings in the trash.', 'quillmoor-booking' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'qhs-dashboard',
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => 'listing',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'author', 'revisions' ),
				'capability_type' => array( 'qm_listing', 'qm_listings' ),
				'map_meta_cap'    => true,
				'menu_icon'       => 'dashicons-admin-home',
			)
		);

		$term_caps = array(
			'manage_terms' => BookingPolicy::CAP_MANAGE,
			'edit_terms'   => BookingPolicy::CAP_MANAGE,
			'delete_terms' => BookingPolicy::CAP_MANAGE,
			'assign_terms' => 'edit_qm_listings',
		);

		register_taxonomy(
			self::LOCATION,
			self::LISTING,
			array(
				'labels'            => array(
					'name'          => __( 'Locations', 'quillmoor-booking' ),
					'singular_name' => __( 'Location', 'quillmoor-booking' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => array( 'slug' => 'place' ),
				'capabilities'      => $term_caps,
			)
		);
		register_taxonomy(
			self::CATEGORY,
			self::LISTING,
			array(
				'labels'            => array(
					'name'          => __( 'Categories', 'quillmoor-booking' ),
					'singular_name' => __( 'Category', 'quillmoor-booking' ),
				),
				'hierarchical'      => true,
				'public'            => true,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => array( 'slug' => 'category-of-place' ),
				'capabilities'      => $term_caps,
			)
		);
		register_taxonomy(
			self::AMENITY,
			self::LISTING,
			array(
				'labels'       => array(
					'name'          => __( 'Amenities', 'quillmoor-booking' ),
					'singular_name' => __( 'Amenity', 'quillmoor-booking' ),
				),
				'hierarchical' => true,
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'rewrite'      => false,
				'capabilities' => $term_caps,
			)
		);
	}
}
