<?php
/**
 * Demo content seeder.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Listings\FieldWriter;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Services;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and restores the demo places, hosts, terms and photos. Seeded rows carry a seed key, so a
 * reset can restore them and remove whatever demo visitors added.
 */
final class Seeder {

	public const SEED_META  = 'qm_seed_key';
	public const PHOTO_META = 'qm_seed_photo';

	/**
	 * Photo folder inside the plugin.
	 */
	public static function photo_dir(): string {
		return QUILLMOOR_PLUGIN_DIR . 'demo/photos/';
	}

	/**
	 * Photo credits, in listing order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function credits(): array {
		$file = self::photo_dir() . 'credits.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$rows = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Whether the demo places exist.
	 */
	public static function is_seeded(): bool {
		$found = get_posts(
			array(
				'post_type'      => PostTypes::LISTING,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		return array() !== $found;
	}

	/**
	 * Create or restore everything.
	 *
	 * @param bool $prune Remove listings and uploads that are not part of the seed.
	 */
	public static function run( bool $prune ): void {
		self::terms();
		$hosts  = self::hosts();
		$photos = self::photos( $hosts );
		$ids    = self::listings( $hosts, $photos );
		if ( $prune ) {
			self::prune( $ids, $hosts );
		}
	}

	/**
	 * Locations, categories and amenities with their display meta.
	 */
	private static function terms(): void {
		foreach ( SeedData::locations() as $slug => $row ) {
			$id = self::term( PostTypes::LOCATION, $slug, $row['name'] );
			update_term_meta( $id, 'qm_lat', $row['lat'] );
			update_term_meta( $id, '_qm_lat', 'field_qm_location_lat' );
			update_term_meta( $id, 'qm_lng', $row['lng'] );
			update_term_meta( $id, '_qm_lng', 'field_qm_location_lng' );
			update_term_meta( $id, 'qm_region', $row['region'] );
			update_term_meta( $id, 'qm_zone', $row['zone'] );
		}
		foreach ( SeedData::categories() as $slug => $row ) {
			$id = self::term( PostTypes::CATEGORY, $slug, $row['name'] );
			update_term_meta( $id, 'qm_icon', $row['icon'] );
			update_term_meta( $id, '_qm_icon', 'field_qm_category_icon' );
			update_term_meta( $id, 'qm_for', $row['for'] );
		}
		foreach ( SeedData::amenities() as $slug => $row ) {
			$id = self::term( PostTypes::AMENITY, $slug, $row['name'] );
			update_term_meta( $id, 'qm_icon', $row['icon'] );
			update_term_meta( $id, '_qm_icon', 'field_qm_amenity_icon' );
		}
	}

	/**
	 * Create or rename a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @param string $name     Name.
	 */
	private static function term( string $taxonomy, string $slug, string $name ): int {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term instanceof WP_Term ) {
			wp_update_term( $term->term_id, $taxonomy, array( 'name' => $name ) );
			return (int) $term->term_id;
		}
		$made = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
		return is_array( $made ) ? (int) $made['term_id'] : 0;
	}

	/**
	 * Host accounts. Only demo-host uses the public demo password.
	 *
	 * @return array<string,int> User ids keyed by login.
	 */
	private static function hosts(): array {
		$out = array();
		foreach ( SeedData::hosts() as $login => $host ) {
			$password      = DemoReset::HOST_LOGIN === $login ? DemoReset::demo_password() : wp_generate_password( 32 );
			$id            = DemoReset::ensure_user( $login, $host['name'], $login . '@example.com', Roles::HOST, $password );
			$out[ $login ] = $id;
			update_user_meta( $id, DemoGuard::META, '1' );
			update_user_meta( $id, 'qm_host_since', $host['since'] );
			update_user_meta( $id, 'qm_host_bio', $host['bio'] );
			update_user_meta( $id, 'qm_host_languages', $host['languages'] );
		}
		return $out;
	}

	/**
	 * Import the photos once and keep their alt text and credit current.
	 *
	 * @param array<string,int> $hosts Host ids keyed by login.
	 * @return array<string,int> Attachment ids keyed by file name.
	 */
	private static function photos( array $hosts ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$owners = array();
		foreach ( SeedData::listings() as $slug => $listing ) {
			$owners[ $slug ] = $hosts[ $listing['host'] ] ?? 0;
		}

		// Two sizes are enough for cards and the gallery; fewer files keep the import fast.
		$limit = static fn(): array => array(
			'medium_large' => array(
				'width'  => 768,
				'height' => 0,
				'crop'   => false,
			),
			'large'        => array(
				'width'  => 1280,
				'height' => 0,
				'crop'   => false,
			),
		);
		add_filter( 'intermediate_image_sizes_advanced', $limit );

		$out = array();
		foreach ( self::credits() as $credit ) {
			$file = sanitize_file_name( (string) $credit['file'] );
			$id   = self::photo_id( $file );
			if ( 0 === $id ) {
				$id = self::import_photo( $file, (int) ( $owners[ (string) $credit['listing'] ] ?? 0 ) );
			}
			if ( $id < 1 ) {
				continue;
			}
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $credit['alt'] ) );
			wp_update_post(
				array(
					'ID'           => $id,
					'post_title'   => sanitize_text_field( (string) $credit['alt'] ),
					'post_excerpt' => sprintf( 'Photo: %s, Unsplash License', sanitize_text_field( (string) $credit['photographer'] ) ),
				)
			);
			$out[ $file ] = $id;
		}

		remove_filter( 'intermediate_image_sizes_advanced', $limit );
		return $out;
	}

	/**
	 * Attachment already imported for a file.
	 *
	 * @param string $file File name.
	 */
	public static function photo_id( string $file ): int {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::PHOTO_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $file, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array() === $found ? 0 : (int) $found[0];
	}

	/**
	 * Copy one photo into the uploads folder and register it.
	 *
	 * @param string $file  File name.
	 * @param int    $owner Uploading user.
	 */
	private static function import_photo( string $file, int $owner ): int {
		$source = self::photo_dir() . $file;
		if ( ! is_readable( $source ) ) {
			return 0;
		}
		$tmp = wp_tempnam( $file );
		copy( $source, $tmp );
		$id = media_handle_sideload(
			array(
				'name'     => $file,
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( (int) $id, self::PHOTO_META, $file );
		if ( $owner > 0 ) {
			wp_update_post(
				array(
					'ID'          => (int) $id,
					'post_author' => $owner,
				)
			);
		}
		return (int) $id;
	}

	/**
	 * Create or restore the listings.
	 *
	 * @param array<string,int> $hosts  Host ids keyed by login.
	 * @param array<string,int> $photos Attachment ids keyed by file.
	 * @return int[] Listing ids.
	 */
	private static function listings( array $hosts, array $photos ): array {
		$locations = SeedData::locations();
		$ids       = array();
		foreach ( SeedData::listings() as $slug => $row ) {
			$found = get_posts(
				array(
					'post_type'      => PostTypes::LISTING,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => self::SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$id    = wp_insert_post(
				array(
					'ID'          => array() === $found ? 0 : (int) $found[0],
					'post_type'   => PostTypes::LISTING,
					'post_status' => 'publish',
					'post_title'  => (string) $row['title'],
					'post_name'   => $slug,
					'post_author' => (int) ( $hosts[ $row['host'] ] ?? 0 ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$id = (int) $id;
			update_post_meta( $id, self::SEED_META, $slug );

			$fields = (array) $row['fields'];
			$centre = $locations[ (string) $row['location'] ];
			$fields = array_merge(
				self::empty_fields( (string) $fields['qm_type'] ),
				$fields,
				array(
					'qm_lat'     => round( $centre['lat'] + (float) $row['offset'][0], 6 ),
					'qm_lng'     => round( $centre['lng'] + (float) $row['offset'][1], 6 ),
					'qm_gallery' => array_values(
						array_filter(
							array_map( static fn( int $n ): int => (int) ( $photos[ $slug . '-' . $n . '.webp' ] ?? 0 ), array( 1, 2, 3, 4 ) )
						)
					),
				)
			);
			FieldWriter::save( $id, $fields );

			wp_set_object_terms( $id, array( (string) $row['location'] ), PostTypes::LOCATION );
			wp_set_object_terms( $id, (array) $row['categories'], PostTypes::CATEGORY );
			wp_set_object_terms( $id, (array) $row['amenities'], PostTypes::AMENITY );
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Blank values for the other listing type, so a restored listing has no leftovers.
	 *
	 * @param string $type Listing type.
	 * @return array<string,mixed>
	 */
	private static function empty_fields( string $type ): array {
		$common = array(
			'qm_highlights'  => array(),
			'qm_extras'      => array(),
			'qm_house_rules' => array(),
		);
		if ( 'dining' === $type ) {
			return $common + array(
				'qm_seasons' => array(),
				'qm_spaces'  => array(),
			);
		}
		return $common + array(
			'qm_periods' => array(),
			'qm_tables'  => array(),
		);
	}

	/**
	 * Remove what demo visitors added: listings outside the seed and their uploads.
	 *
	 * @param int[]             $keep  Seeded listing ids.
	 * @param array<string,int> $hosts Host ids.
	 */
	private static function prune( array $keep, array $hosts ): void {
		$extra = get_posts(
			array(
				'post_type'      => PostTypes::LISTING,
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The nightly reset must see every added listing.
				'fields'         => 'ids',
				'post__not_in'   => $keep,
			)
		);
		foreach ( $extra as $id ) {
			wp_delete_post( (int) $id, true );
		}

		$authors = array_values( $hosts );
		$manager = get_user_by( 'login', DemoReset::MANAGER_LOGIN );
		if ( $manager ) {
			$authors[] = $manager->ID;
		}
		$uploads = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The nightly reset must see every upload; only ids are loaded.
				'fields'         => 'ids',
				'author__in'     => $authors,
			)
		);
		foreach ( $uploads as $id ) {
			if ( '' === (string) get_post_meta( (int) $id, self::PHOTO_META, true ) ) {
				wp_delete_attachment( (int) $id, true );
			}
		}
	}

	/**
	 * A few bookings and blocks so host screens are not empty. They are labelled as seed data and
	 * no payment is attached to them.
	 *
	 * @param int $guest_customer_id Customer row of the fictional guest.
	 */
	public static function sample_activity( int $guest_customer_id ): void {
		$by_slug = array();
		foreach ( get_posts(
			array(
				'post_type'      => PostTypes::LISTING,
				'posts_per_page' => 50,
				'meta_key'       => self::SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		) as $post ) {
			$by_slug[ (string) get_post_meta( $post->ID, self::SEED_META, true ) ] = Listing::from_post( $post );
		}

		$day = static fn( int $offset ): string => gmdate( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );

		if ( isset( $by_slug['fern-hollow-cabin'] ) ) {
			self::stay( $by_slug['fern-hollow-cabin'], $guest_customer_id, $day( 12 ), $day( 15 ), 3, BookingState::Confirmed );
			Services::nights()->block( $by_slug['fern-hollow-cabin']->id, array( $day( 20 ), $day( 21 ) ) );
		}
		if ( isset( $by_slug['boathouse-on-the-pond'] ) ) {
			self::stay( $by_slug['boathouse-on-the-pond'], $guest_customer_id, $day( 18 ), $day( 21 ), 2, BookingState::Requested );
		}
		if ( isset( $by_slug['stone-cottage-in-the-hills'] ) ) {
			self::stay( $by_slug['stone-cottage-in-the-hills'], $guest_customer_id, $day( -9 ), $day( -6 ), 2, BookingState::Completed );
		}
		if ( isset( $by_slug['the-garden-table'] ) ) {
			$listing = $by_slug['the-garden-table'];
			for ( $i = 3; $i < 12; $i++ ) {
				$slots = Services::availability()->dining_slots( $listing, $day( $i ) );
				if ( array() !== $slots ) {
					self::table( $listing, $guest_customer_id, $slots[ min( 2, count( $slots ) - 1 ) ]['slot_start'], 4 );
					break;
				}
			}
		}
	}

	/**
	 * Seed one stay.
	 *
	 * @param Listing      $listing     Listing.
	 * @param int          $customer_id Customer row.
	 * @param string       $check_in    Arrival.
	 * @param string       $check_out   Departure.
	 * @param int          $guests      Guests.
	 * @param BookingState $state       State.
	 */
	private static function stay( Listing $listing, int $customer_id, string $check_in, string $check_out, int $guests, BookingState $state ): void {
		$price = Pricing::stay( $listing, $check_in, $check_out, $guests, array() );
		$id    = self::insert( $listing, $customer_id, $state, $price, $check_in, $check_out, $check_in . ' ' . $listing->check_in_time . ':00', $check_out . ' ' . $listing->check_out_time . ':00', $guests );
		Services::nights()->acquire( $listing->id, Pricing::night_dates( $check_in, $price['nights'] ), $id, null );
	}

	/**
	 * Seed one table.
	 *
	 * @param Listing $listing     Listing.
	 * @param int     $customer_id Customer row.
	 * @param string  $slot        Seating.
	 * @param int     $party       Party size.
	 */
	private static function table( Listing $listing, int $customer_id, string $slot, int $party ): void {
		$price = Pricing::dining( $listing, $party, array() );
		$end   = gmdate( 'Y-m-d H:i:s', (int) strtotime( $slot . ' UTC' ) + $listing->seating_minutes * MINUTE_IN_SECONDS );
		$id    = self::insert( $listing, $customer_id, BookingState::Confirmed, $price, null, null, $slot, $end, $party );
		Services::tables()->acquire( $listing->id, $slot, $listing->covers, $party, $id, null );
	}

	/**
	 * Insert the rows of a seeded booking.
	 *
	 * @param Listing                                                                                                    $listing     Listing.
	 * @param int                                                                                                        $customer_id Customer.
	 * @param BookingState                                                                                               $state       State.
	 * @param array{line_items:array<int,array{label:string,cents:int}>,total_cents:int,deposit_cents:int,nights:int} $price       Price.
	 * @param string|null                                                                                                $check_in    Arrival.
	 * @param string|null                                                                                                $check_out   Departure.
	 * @param string                                                                                                     $start       Start.
	 * @param string                                                                                                     $end         End.
	 * @param int                                                                                                        $guests      Guests.
	 */
	private static function insert( Listing $listing, int $customer_id, BookingState $state, array $price, ?string $check_in, ?string $check_out, string $start, string $end, int $guests ): int {
		global $wpdb;
		$repo     = Services::bookings();
		$quote_id = $repo->insert_quote(
			array(
				'customer_id'   => $customer_id,
				'listing_id'    => $listing->id,
				'listing_type'  => $listing->type->value,
				'check_in'      => $check_in,
				'check_out'     => $check_out,
				'slot_start'    => $start,
				'guests'        => $guests,
				'extras'        => '[]',
				'line_items'    => (string) wp_json_encode( $price['line_items'] ),
				'total_cents'   => $price['total_cents'],
				'deposit_cents' => $price['deposit_cents'],
				'valid_until'   => Db::now( DAY_IN_SECONDS ),
				'created_at'    => Db::now(),
			)
		);
		$id       = $repo->insert(
			array(
				'quote_id'     => $quote_id,
				'customer_id'  => $customer_id,
				'listing_id'   => $listing->id,
				'listing_type' => $listing->type->value,
				'host_user_id' => $listing->host_id,
				'location'     => $listing->location_slug(),
				'state'        => $state->value,
				'check_in'     => $check_in,
				'check_out'    => $check_out,
				'slot_start'   => $start,
				'slot_end'     => $end,
				'guests'       => $guests,
				'access_key'   => bin2hex( random_bytes( 16 ) ),
				'requested_at' => BookingState::Requested === $state ? Db::now() : null,
				'completed_at' => BookingState::Completed === $state ? Db::now( -5 * DAY_IN_SECONDS ) : null,
				'created_at'   => Db::now(),
			)
		);
		$wpdb->insert(
			Db::table( 'booking_history' ),
			array(
				'booking_id' => $id,
				'from_state' => BookingState::Quote->value,
				'to_state'   => $state->value,
				'actor'      => 'seed',
				'reason'     => 'demo seed data, no payment taken',
				'created_at' => Db::now(),
			)
		);
		return $id;
	}

	/**
	 * Type of a seeded listing, for tests.
	 *
	 * @param string $slug Seed slug.
	 */
	public static function type_of( string $slug ): ListingType {
		$rows = SeedData::listings();
		return ListingType::parse( (string) ( $rows[ $slug ]['fields']['qm_type'] ?? 'stay' ) );
	}
}
