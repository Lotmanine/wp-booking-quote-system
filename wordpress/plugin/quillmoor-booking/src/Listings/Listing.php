<?php
/**
 * Listing read model.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, immutable view of a listing post and its custom fields. Money is held in integer cents.
 */
final class Listing {

	/**
	 * Build a listing.
	 *
	 * @param int                               $id            Post id.
	 * @param string                            $title         Title.
	 * @param string                            $slug          Post slug.
	 * @param string                            $status        Post status.
	 * @param int                               $host_id       Host user id.
	 * @param ListingType                       $type          Type.
	 * @param string                            $summary       Short summary.
	 * @param string                            $description   Description.
	 * @param bool                              $instant_book  Whether paid bookings confirm at once.
	 * @param array<int,array<string,string>>   $highlights    Highlights.
	 * @param int[]                             $photos        Attachment ids, cover first.
	 * @param float|null                        $lat           Approximate latitude.
	 * @param float|null                        $lng           Approximate longitude.
	 * @param string                            $area_note     Area text.
	 * @param string                            $property_kind Kind of stay.
	 * @param int                               $max_guests    Stay capacity.
	 * @param int                               $bedrooms      Bedrooms.
	 * @param int                               $beds          Beds.
	 * @param float                             $baths         Bathrooms.
	 * @param int                               $floor_area    Floor area in m².
	 * @param array<int,array<string,mixed>>    $spaces        Rooms and spaces.
	 * @param string                            $check_in_time  Check-in time, H:i.
	 * @param string                            $check_out_time Check-out time, H:i.
	 * @param int                               $nightly_cents Nightly price.
	 * @param int                               $weekend_cents Weekend night price, 0 when unset.
	 * @param int                               $cleaning_cents Cleaning fee.
	 * @param int                               $min_nights    Minimum nights.
	 * @param int                               $max_nights    Maximum nights.
	 * @param array<int,array{label:string,start:string,end:string,cents:int}> $seasons Seasonal rates.
	 * @param string                            $cuisine       Cuisine.
	 * @param int                               $menu_cents    Set menu per guest.
	 * @param int                               $covers        Seats per slot.
	 * @param int                               $min_party     Smallest party.
	 * @param int                               $max_party     Largest party.
	 * @param int                               $slot_minutes  Slot length.
	 * @param int                               $seating_minutes Table time.
	 * @param array<int,array{label:string,days:string[],start:string,end:string}> $periods Service periods.
	 * @param array<int,array<string,mixed>>    $tables        Tables.
	 * @param string                            $deposit_rule  Deposit rule.
	 * @param float                             $deposit_value Deposit value.
	 * @param array<string,array{label:string,cents:int,unit:string}> $extras Extras keyed by slug.
	 * @param string[]                          $house_rules   House rules.
	 * @param string                            $cancellation  Cancellation policy.
	 * @param string                            $address       Street address, for confirmed guests.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $slug,
		public readonly string $status,
		public readonly int $host_id,
		public readonly ListingType $type,
		public readonly string $summary,
		public readonly string $description,
		public readonly bool $instant_book,
		public readonly array $highlights,
		public readonly array $photos,
		public readonly ?float $lat,
		public readonly ?float $lng,
		public readonly string $area_note,
		public readonly string $property_kind,
		public readonly int $max_guests,
		public readonly int $bedrooms,
		public readonly int $beds,
		public readonly float $baths,
		public readonly int $floor_area,
		public readonly array $spaces,
		public readonly string $check_in_time,
		public readonly string $check_out_time,
		public readonly int $nightly_cents,
		public readonly int $weekend_cents,
		public readonly int $cleaning_cents,
		public readonly int $min_nights,
		public readonly int $max_nights,
		public readonly array $seasons,
		public readonly string $cuisine,
		public readonly int $menu_cents,
		public readonly int $covers,
		public readonly int $min_party,
		public readonly int $max_party,
		public readonly int $slot_minutes,
		public readonly int $seating_minutes,
		public readonly array $periods,
		public readonly array $tables,
		public readonly string $deposit_rule,
		public readonly float $deposit_value,
		public readonly array $extras,
		public readonly array $house_rules,
		public readonly string $cancellation,
		public readonly string $address = '',
	) {
	}

	/**
	 * Load a listing post.
	 *
	 * @param int $id Post id.
	 */
	public static function find( int $id ): ?self {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || PostTypes::LISTING !== $post->post_type ) {
			return null;
		}
		return self::from_post( $post );
	}

	/**
	 * Build from a post.
	 *
	 * @param WP_Post $post Listing post.
	 */
	public static function from_post( WP_Post $post ): self {
		$id = (int) $post->ID;

		$seasons = array();
		foreach ( self::rows( $id, 'qm_seasons' ) as $row ) {
			$start = (string) ( $row['start'] ?? '' );
			$end   = (string) ( $row['end'] ?? '' );
			if ( self::is_date( $start ) && self::is_date( $end ) && $start <= $end ) {
				$seasons[] = array(
					'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'start' => $start,
					'end'   => $end,
					'cents' => self::cents( $row['price'] ?? 0 ),
				);
			}
		}

		$periods = array();
		foreach ( self::rows( $id, 'qm_periods' ) as $row ) {
			$start = (string) ( $row['start'] ?? '' );
			$end   = (string) ( $row['end'] ?? '' );
			if ( self::is_time( $start ) && self::is_time( $end ) && $start <= $end ) {
				$periods[] = array(
					'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'days'  => array_values( array_intersect( array_map( 'strval', (array) ( $row['days'] ?? array() ) ), array_keys( Fields::WEEKDAYS ) ) ),
					'start' => substr( $start, 0, 5 ),
					'end'   => substr( $end, 0, 5 ),
				);
			}
		}

		$extras = array();
		foreach ( self::rows( $id, 'qm_extras' ) as $row ) {
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$key   = sanitize_title( $label );
			if ( '' === $key || isset( $extras[ $key ] ) ) {
				continue;
			}
			$unit           = (string) ( $row['unit'] ?? 'per_stay' );
			$extras[ $key ] = array(
				'label' => $label,
				'cents' => self::cents( $row['price'] ?? 0 ),
				'unit'  => in_array( $unit, array( 'per_stay', 'per_night', 'per_guest' ), true ) ? $unit : 'per_stay',
			);
		}

		$highlights = array();
		foreach ( self::rows( $id, 'qm_highlights' ) as $row ) {
			$highlights[] = array(
				'icon'  => sanitize_key( (string) ( $row['icon'] ?? 'home' ) ),
				'title' => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'text'  => sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
			);
		}

		$rules = array();
		foreach ( self::rows( $id, 'qm_house_rules' ) as $row ) {
			$rule = sanitize_text_field( (string) ( $row['rule'] ?? '' ) );
			if ( '' !== $rule ) {
				$rules[] = $rule;
			}
		}

		$photos = array_values( array_filter( array_map( 'intval', (array) self::value( $id, 'qm_gallery', array() ) ) ) );
		$lat    = self::value( $id, 'qm_lat', '' );
		$lng    = self::value( $id, 'qm_lng', '' );
		$cancel = (string) self::value( $id, 'qm_cancellation', 'moderate' );
		$rule   = (string) self::value( $id, 'qm_deposit_rule', 'percent' );

		return new self(
			$id,
			(string) get_the_title( $post ),
			(string) $post->post_name,
			(string) $post->post_status,
			(int) $post->post_author,
			ListingType::parse( (string) self::value( $id, 'qm_type', 'stay' ) ),
			(string) self::value( $id, 'qm_summary', '' ),
			(string) self::value( $id, 'qm_description', '' ),
			(bool) self::value( $id, 'qm_instant_book', false ),
			$highlights,
			$photos,
			is_numeric( $lat ) ? (float) $lat : null,
			is_numeric( $lng ) ? (float) $lng : null,
			(string) self::value( $id, 'qm_area_note', '' ),
			(string) self::value( $id, 'qm_property_kind', 'home' ),
			max( 1, (int) self::value( $id, 'qm_max_guests', 2 ) ),
			(int) self::value( $id, 'qm_bedrooms', 1 ),
			max( 1, (int) self::value( $id, 'qm_beds', 1 ) ),
			(float) self::value( $id, 'qm_baths', 1 ),
			(int) self::value( $id, 'qm_floor_area', 0 ),
			self::rows( $id, 'qm_spaces' ),
			self::time_or( (string) self::value( $id, 'qm_check_in_time', '' ), '15:00' ),
			self::time_or( (string) self::value( $id, 'qm_check_out_time', '' ), '11:00' ),
			self::cents( self::value( $id, 'qm_nightly_price', 0 ) ),
			self::cents( self::value( $id, 'qm_weekend_price', 0 ) ),
			self::cents( self::value( $id, 'qm_cleaning_fee', 0 ) ),
			max( 1, (int) self::value( $id, 'qm_min_nights', 1 ) ),
			max( 1, (int) self::value( $id, 'qm_max_nights', 14 ) ),
			$seasons,
			(string) self::value( $id, 'qm_cuisine', '' ),
			self::cents( self::value( $id, 'qm_menu_price', 0 ) ),
			max( 1, (int) self::value( $id, 'qm_covers', 20 ) ),
			max( 1, (int) self::value( $id, 'qm_min_party', 1 ) ),
			max( 1, (int) self::value( $id, 'qm_max_party', 8 ) ),
			in_array( (int) self::value( $id, 'qm_slot_minutes', 30 ), array( 15, 30, 60 ), true ) ? (int) self::value( $id, 'qm_slot_minutes', 30 ) : 30,
			max( 30, (int) self::value( $id, 'qm_seating_minutes', 90 ) ),
			$periods,
			self::rows( $id, 'qm_tables' ),
			in_array( $rule, array( 'percent', 'per_guest', 'flat', 'first_night' ), true ) ? $rule : 'percent',
			max( 0.0, (float) self::value( $id, 'qm_deposit_value', 20 ) ),
			$extras,
			$rules,
			in_array( $cancel, array( 'flexible', 'moderate', 'strict' ), true ) ? $cancel : 'moderate',
			sanitize_text_field( (string) self::value( $id, 'qm_address', '' ) )
		);
	}

	/**
	 * Location term of the listing.
	 *
	 * @return array{slug:string,name:string,lat:float|null,lng:float|null}|null
	 */
	public function location(): ?array {
		$terms = get_the_terms( $this->id, PostTypes::LOCATION );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		$term = $terms[0];
		$lat  = get_term_meta( $term->term_id, 'qm_lat', true );
		$lng  = get_term_meta( $term->term_id, 'qm_lng', true );
		return array(
			'slug' => (string) $term->slug,
			'name' => (string) $term->name,
			'lat'  => is_numeric( $lat ) ? (float) $lat : null,
			'lng'  => is_numeric( $lng ) ? (float) $lng : null,
		);
	}

	/**
	 * Location slug, or "anywhere".
	 */
	public function location_slug(): string {
		$location = $this->location();
		return null === $location ? 'anywhere' : $location['slug'];
	}

	/**
	 * Amenity names and icons.
	 *
	 * @return array<int,array{slug:string,name:string,icon:string}>
	 */
	public function amenities(): array {
		$terms = get_the_terms( $this->id, PostTypes::AMENITY );
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
				'icon' => (string) ( get_term_meta( $term->term_id, 'qm_icon', true ) ? get_term_meta( $term->term_id, 'qm_icon', true ) : 'check' ),
			);
		}
		return $out;
	}

	/**
	 * Category slugs.
	 *
	 * @return string[]
	 */
	public function categories(): array {
		$terms = get_the_terms( $this->id, PostTypes::CATEGORY );
		return is_array( $terms ) ? array_map( static fn( $term ): string => (string) $term->slug, $terms ) : array();
	}

	/**
	 * Hours before the start when a full deposit refund stops.
	 */
	public function refund_cutoff_hours(): int {
		return match ( $this->cancellation ) {
			'flexible' => 24,
			'strict'   => 14 * 24,
			default    => 5 * 24,
		};
	}

	/**
	 * Human cancellation text.
	 */
	public function cancellation_text(): string {
		return match ( $this->cancellation ) {
			'flexible' => 'Flexible: cancel up to 24 hours before for a full deposit refund.',
			'strict'   => 'Strict: cancel up to 14 days before for a full deposit refund.',
			default    => 'Moderate: cancel up to 5 days before for a full deposit refund.',
		};
	}

	/**
	 * "From" price for cards, in cents: a night for stays, the set menu for dining.
	 */
	public function from_cents(): int {
		return ListingType::Stay === $this->type ? $this->nightly_cents : $this->menu_cents;
	}

	/**
	 * Whether the listing is published.
	 */
	public function is_published(): bool {
		return 'publish' === $this->status;
	}

	/**
	 * Read a field through Secure Custom Fields, or straight from post meta without it.
	 *
	 * @param int    $id       Post id.
	 * @param string $name     Field name.
	 * @param mixed  $fallback Value when empty.
	 * @return mixed
	 */
	public static function value( int $id, string $name, mixed $fallback ): mixed {
		$value = function_exists( 'get_field' ) ? get_field( $name, $id ) : get_post_meta( $id, $name, true );
		// Secure Custom Fields returns false for an empty field, which is a real value only for booleans.
		if ( null === $value || '' === $value || ( false === $value && ! is_bool( $fallback ) ) ) {
			return $fallback;
		}
		return $value;
	}

	/**
	 * Repeater rows.
	 *
	 * @param int    $id   Post id.
	 * @param string $name Field name.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $id, string $name ): array {
		if ( function_exists( 'get_field' ) ) {
			$rows = get_field( $name, $id );
			return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
		}
		// Without the fields plugin, read the same post meta layout it writes: a row count, then name_index_sub.
		$count = min( (int) get_post_meta( $id, $name, true ), 50 );
		$subs  = Fields::sub_field_names( $name );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $subs as $sub ) {
				$row[ $sub ] = get_post_meta( $id, $name . '_' . $i . '_' . $sub, true );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Permalink.
	 */
	public function url(): string {
		return (string) get_permalink( $this->id );
	}

	/**
	 * Photos with their URLs and alt text, cover first.
	 *
	 * @param string $size Image size.
	 * @return array<int,array{id:int,url:string,alt:string,width:int,height:int}>
	 */
	public function photo_list( string $size = 'large' ): array {
		$out = array();
		foreach ( $this->photos as $photo_id ) {
			$image = wp_get_attachment_image_src( $photo_id, $size );
			if ( ! is_array( $image ) ) {
				continue;
			}
			$out[] = array(
				'id'     => $photo_id,
				'url'    => (string) $image[0],
				'alt'    => (string) get_post_meta( $photo_id, '_wp_attachment_image_alt', true ),
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
			);
		}
		return $out;
	}

	/**
	 * Size facts for cards: guests, bedrooms, beds and baths, or party sizes for a table.
	 *
	 * @return string[]
	 */
	public function size_facts(): array {
		if ( ListingType::Dining === $this->type ) {
			return array( sprintf( 'Tables for %d to %d', $this->min_party, $this->max_party ) );
		}
		$baths = (float) $this->baths;
		return array(
			sprintf( '%d %s', $this->max_guests, 1 === $this->max_guests ? 'guest' : 'guests' ),
			0 === $this->bedrooms ? 'Studio' : sprintf( '%d %s', $this->bedrooms, 1 === $this->bedrooms ? 'bedroom' : 'bedrooms' ),
			sprintf( '%d %s', $this->beds, 1 === $this->beds ? 'bed' : 'beds' ),
			sprintf( '%s %s', rtrim( rtrim( number_format( $baths, 1 ), '0' ), '.' ), 1.0 === $baths ? 'bath' : 'baths' ),
		);
	}

	/**
	 * Map point: the listing's own, else its location's.
	 *
	 * @return array{lat:float,lng:float}|null
	 */
	public function point(): ?array {
		if ( null !== $this->lat && null !== $this->lng ) {
			return array(
				'lat' => $this->lat,
				'lng' => $this->lng,
			);
		}
		$location = $this->location();
		if ( null !== $location && null !== $location['lat'] && null !== $location['lng'] ) {
			return array(
				'lat' => $location['lat'],
				'lng' => $location['lng'],
			);
		}
		return null;
	}

	/**
	 * Kind label, such as "Cabin" or "Wine bar".
	 */
	public function kind_label(): string {
		if ( ListingType::Dining === $this->type ) {
			if ( '' !== $this->cuisine ) {
				return $this->cuisine;
			}
			$cats = get_the_terms( $this->id, PostTypes::CATEGORY );
			return is_array( $cats ) && array() !== $cats ? (string) $cats[0]->name : 'Restaurant';
		}
		return match ( $this->property_kind ) {
			'cabin'     => 'Cabin',
			'apartment' => 'Apartment',
			'villa'     => 'Villa',
			'room'      => 'Private room',
			default     => 'Entire home',
		};
	}

	/**
	 * Dollars to integer cents.
	 *
	 * @param mixed $dollars Field value.
	 */
	public static function cents( mixed $dollars ): int {
		return is_numeric( $dollars ) ? max( 0, (int) round( (float) $dollars * 100 ) ) : 0;
	}

	/**
	 * Whether a string is a Y-m-d date.
	 *
	 * @param string $value Value.
	 */
	public static function is_date( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && false !== strtotime( $value . ' 00:00:00 UTC' );
	}

	/**
	 * Whether a string is an H:i time.
	 *
	 * @param string $value Value.
	 */
	public static function is_time( string $value ): bool {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value );
	}

	/**
	 * A valid H:i time or a fallback.
	 *
	 * @param string $value    Value.
	 * @param string $fallback Fallback.
	 */
	private static function time_or( string $value, string $fallback ): string {
		return self::is_time( $value ) ? substr( $value, 0, 5 ) : $fallback;
	}
}
