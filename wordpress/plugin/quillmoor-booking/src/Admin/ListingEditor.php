<?php
/**
 * Listing editor additions and host scoping.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Admin;

use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The fields themselves come from Secure Custom Fields. This class adds the map picker, the price
 * preview, list columns, and keeps each host to their own listings and uploads.
 */
final class ListingEditor {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes_' . PostTypes::LISTING, array( self::class, 'meta_boxes' ) );
		add_action( 'pre_get_posts', array( self::class, 'own_listings_only' ) );
		add_filter( 'ajax_query_attachments_args', array( self::class, 'own_uploads_only' ) );
		add_filter( 'pre_trash_post', array( self::class, 'keep_booked_listing' ), 10, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'keep_booked_listing' ), 10, 2 );
		add_filter( 'manage_' . PostTypes::LISTING . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . PostTypes::LISTING . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * Add the map and preview boxes.
	 */
	public static function meta_boxes(): void {
		add_meta_box( 'qhs-map', __( 'Map position', 'quillmoor-booking' ), array( self::class, 'map_box' ), PostTypes::LISTING, 'normal', 'default' );
		add_meta_box( 'qhs-preview', __( 'Price preview', 'quillmoor-booking' ), array( self::class, 'preview_box' ), PostTypes::LISTING, 'side', 'default' );
		add_meta_box( 'qhs-links', __( 'Availability and bookings', 'quillmoor-booking' ), array( self::class, 'links_box' ), PostTypes::LISTING, 'side', 'default' );
	}

	/**
	 * Leaflet map that writes the latitude and longitude fields.
	 *
	 * @param WP_Post $post Listing.
	 */
	public static function map_box( WP_Post $post ): void {
		echo '<p class="description">' . esc_html__( 'Click the map or drag the pin to set the point. It fills the latitude and longitude fields in the Location tab. Guests see an area around it before they book.', 'quillmoor-booking' ) . '</p>';
		echo '<div class="qhs-mapbox" data-qhs-mappicker data-testid="map-picker" style="height:320px"></div>';
		unset( $post );
	}

	/**
	 * Price preview from the saved listing, through the same server rules as the booking card.
	 *
	 * @param WP_Post $post Listing.
	 */
	public static function preview_box( WP_Post $post ): void {
		$listing = Listing::from_post( $post );
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Save the listing once to preview prices.', 'quillmoor-booking' ) . '</p>';
			return;
		}
		$stay = ListingType::Stay === $listing->type;
		$in   = gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS );
		$out  = gmdate( 'Y-m-d', time() + ( 14 + max( 2, $listing->min_nights ) ) * DAY_IN_SECONDS );
		echo '<div class="qhs-preview" data-qhs-preview data-listing="' . (int) $listing->id . '" data-type="' . esc_attr( $listing->type->value ) . '">';
		if ( $stay ) {
			printf( '<label>%s<input type="date" name="check_in" value="%s"></label>', esc_html__( 'Check-in', 'quillmoor-booking' ), esc_attr( $in ) );
			printf( '<label>%s<input type="date" name="check_out" value="%s"></label>', esc_html__( 'Checkout', 'quillmoor-booking' ), esc_attr( $out ) );
			printf( '<label>%s<input type="number" name="guests" min="1" max="%d" value="%d"></label>', esc_html__( 'Guests', 'quillmoor-booking' ), (int) $listing->max_guests, (int) min( 2, $listing->max_guests ) );
		} else {
			$slot = '';
			for ( $i = 1; $i < 15 && '' === $slot; $i++ ) {
				$slots = Services::availability()->dining_slots( $listing, gmdate( 'Y-m-d', time() + $i * DAY_IN_SECONDS ) );
				$slot  = $slots[0]['slot_start'] ?? '';
			}
			printf( '<input type="hidden" name="slot_start" value="%s">', esc_attr( $slot ) );
			printf( '<p class="description">%s</p>', esc_html( '' === $slot ? __( 'No open seating in the next two weeks.', 'quillmoor-booking' ) : sprintf( /* translators: %s: date and time */ __( 'First open seating: %s UTC', 'quillmoor-booking' ), substr( $slot, 0, 16 ) ) ) );
			printf( '<label>%s<input type="number" name="guests" min="%d" max="%d" value="%d"></label>', esc_html__( 'Party size', 'quillmoor-booking' ), (int) $listing->min_party, (int) $listing->max_party, (int) max( 2, $listing->min_party ) );
		}
		if ( array() !== $listing->extras ) {
			echo '<fieldset><legend>' . esc_html__( 'Extras', 'quillmoor-booking' ) . '</legend>';
			foreach ( $listing->extras as $key => $extra ) {
				printf( '<label class="qhs-preview__check"><input type="checkbox" name="extras" value="%s"> %s</label>', esc_attr( $key ), esc_html( $extra['label'] . ' (' . Pricing::money( $extra['cents'] ) . ')' ) );
			}
			echo '</fieldset>';
		}
		echo '<button type="button" class="button" data-qhs-preview-run>' . esc_html__( 'Preview price', 'quillmoor-booking' ) . '</button><div class="qhs-preview__out" aria-live="polite" data-testid="price-preview"></div>';
		echo '<p class="description">' . esc_html__( 'Uses the saved listing. Save first to preview your changes.', 'quillmoor-booking' ) . '</p></div>';
	}

	/**
	 * Links to the calendar and the bookings of this listing.
	 *
	 * @param WP_Post $post Listing.
	 */
	public static function links_box( WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Available after the first save.', 'quillmoor-booking' ) . '</p>';
			return;
		}
		$live = Services::bookings()->live_count_for_listing( $post->ID );
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=qhs-calendar&listing=' . $post->ID ) ) . '">' . esc_html__( 'Open the calendar', 'quillmoor-booking' ) . '</a></p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=qhs-bookings&listing=' . $post->ID ) ) . '">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d live booking', '%d live bookings', $live, 'quillmoor-booking' ), $live ) ) . '</a></p>';
	}

	/**
	 * Hosts list only their own listings.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function own_listings_only( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || PostTypes::LISTING !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_others_qm_listings' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- A post type capability, granted by Roles::sync().
			$query->set( 'author', get_current_user_id() );
		}
	}

	/**
	 * Hosts pick only from their own uploads in the media modal.
	 *
	 * @param array<string,mixed> $args Query args.
	 * @return array<string,mixed>
	 */
	public static function own_uploads_only( array $args ): array {
		if ( ! current_user_can( BookingPolicy::CAP_MANAGE ) && current_user_can( BookingPolicy::CAP_HOST ) ) {
			$args['author'] = get_current_user_id();
		}
		return $args;
	}

	/**
	 * Refuse to trash or delete a listing that still holds bookings.
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Post.
	 * @return mixed
	 */
	public static function keep_booked_listing( mixed $check, WP_Post $post ): mixed {
		if ( PostTypes::LISTING === $post->post_type && Services::bookings()->live_count_for_listing( $post->ID ) > 0 ) {
			if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				wp_die( esc_html__( 'This listing has live bookings. Cancel them before removing it.', 'quillmoor-booking' ), '', array( 'response' => 409 ) );
			}
			return false;
		}
		return $check;
	}

	/**
	 * List columns.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['qm_type']  = __( 'Type', 'quillmoor-booking' );
				$out['qm_price'] = __( 'Price', 'quillmoor-booking' );
				$out['qm_book']  = __( 'Booking', 'quillmoor-booking' );
			}
		}
		return $out;
	}

	/**
	 * Column value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Listing id.
	 */
	public static function column( string $column, int $post_id ): void {
		$listing = Listing::find( $post_id );
		if ( null === $listing ) {
			return;
		}
		if ( 'qm_type' === $column ) {
			echo esc_html( $listing->kind_label() );
		} elseif ( 'qm_price' === $column ) {
			echo esc_html( Pricing::money( $listing->from_cents() ) . ( ListingType::Stay === $listing->type ? ' / night' : ' / guest' ) );
		} elseif ( 'qm_book' === $column ) {
			echo esc_html( $listing->instant_book ? __( 'Instant', 'quillmoor-booking' ) : __( 'Request', 'quillmoor-booking' ) );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=qhs-calendar&listing=' . $post_id ) ) . '">' . esc_html__( 'Calendar', 'quillmoor-booking' ) . '</a>';
		}
	}
}
