<?php
/**
 * Listing search.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Finds published listings for the explore page and builds the cards. When dates are given, a
 * listing is shown only if it can take them, so the grid never offers what the server refuses.
 */
final class Search {

	/**
	 * Read and clean search filters from an array such as $_GET or REST params.
	 *
	 * @param array<string,mixed> $raw Raw values.
	 * @return array{type:string,location:string,category:string,check_in:string,check_out:string,guests:int,date:string,party:int}
	 */
	public static function filters( array $raw ): array {
		$date = static fn( string $key ): string => Listing::is_date( (string) ( $raw[ $key ] ?? '' ) ) ? (string) $raw[ $key ] : '';
		$type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
		return array(
			'type'      => in_array( $type, array( 'stay', 'dining' ), true ) ? $type : '',
			'location'  => sanitize_title( (string) ( $raw['where'] ?? $raw['location'] ?? '' ) ),
			'category'  => sanitize_title( (string) ( $raw['category'] ?? '' ) ),
			'check_in'  => $date( 'check_in' ),
			'check_out' => $date( 'check_out' ),
			'guests'    => max( 0, min( 20, (int) ( $raw['guests'] ?? 0 ) ) ),
			'date'      => $date( 'date' ),
			'party'     => max( 0, min( 20, (int) ( $raw['party'] ?? 0 ) ) ),
		);
	}

	/**
	 * Listings that match.
	 *
	 * @param array{type:string,location:string,category:string,check_in:string,check_out:string,guests:int,date:string,party:int} $filters Filters.
	 * @return Listing[]
	 */
	public static function find( array $filters ): array {
		$tax = array();
		if ( '' !== $filters['location'] ) {
			$tax[] = array(
				'taxonomy' => PostTypes::LOCATION,
				'field'    => 'slug',
				'terms'    => $filters['location'],
			);
		}
		if ( '' !== $filters['category'] ) {
			$tax[] = array(
				'taxonomy' => PostTypes::CATEGORY,
				'field'    => 'slug',
				'terms'    => $filters['category'],
			);
		}
		$args = array(
			'post_type'      => PostTypes::LISTING,
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => 'menu_order date',
			'order'          => 'ASC',
		);
		if ( array() !== $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$out = array();
		foreach ( get_posts( $args ) as $post ) {
			$listing = Listing::from_post( $post );
			if ( '' !== $filters['type'] && $listing->type->value !== $filters['type'] ) {
				continue;
			}
			if ( self::fits( $listing, $filters ) ) {
				$out[] = $listing;
			}
		}
		return $out;
	}

	/**
	 * Whether a listing can take the requested dates and party.
	 *
	 * @param Listing                                                                                                       $listing Listing.
	 * @param array{type:string,location:string,category:string,check_in:string,check_out:string,guests:int,date:string,party:int} $filters Filters.
	 */
	public static function fits( Listing $listing, array $filters ): bool {
		if ( ListingType::Stay === $listing->type ) {
			if ( $filters['guests'] > $listing->max_guests ) {
				return false;
			}
			if ( '' === $filters['check_in'] || '' === $filters['check_out'] ) {
				return true;
			}
			try {
				Services::availability()->assert_stay_range( $listing, $filters['check_in'], $filters['check_out'] );
			} catch ( BookingError $e ) {
				return false;
			}
			$last = gmdate( 'Y-m-d', (int) strtotime( $filters['check_out'] . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
			return array() === Services::nights()->taken( $listing->id, $filters['check_in'], $last );
		}

		$party = max( 1, $filters['party'] > 0 ? $filters['party'] : $filters['guests'] );
		if ( $party > $listing->max_party ) {
			return false;
		}
		if ( '' === $filters['date'] ) {
			return true;
		}
		foreach ( Services::availability()->dining_slots( $listing, $filters['date'] ) as $slot ) {
			if ( $slot['seats_left'] >= max( $party, $listing->min_party ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Card data for one listing.
	 *
	 * @param Listing                                                                                                       $listing Listing.
	 * @param array{type:string,location:string,category:string,check_in:string,check_out:string,guests:int,date:string,party:int} $filters Filters, carried into the link.
	 * @return array<string,mixed>
	 */
	public static function card( Listing $listing, array $filters ): array {
		$location = $listing->location();
		$point    = $listing->point();
		$carry    = ListingType::Stay === $listing->type
			? array_filter(
				array(
					'check_in'  => $filters['check_in'],
					'check_out' => $filters['check_out'],
					'guests'    => $filters['guests'] > 0 ? $filters['guests'] : '',
				)
			)
			: array_filter(
				array(
					'date'  => $filters['date'],
					'party' => $filters['party'] > 0 ? $filters['party'] : ( $filters['guests'] > 0 ? $filters['guests'] : '' ),
				)
			);

		$price = ListingType::Stay === $listing->type
			? array(
				'amount' => Pricing::money( $listing->nightly_cents ),
				'unit'   => 'night',
			)
			: array(
				'amount' => Pricing::money( $listing->menu_cents ),
				'unit'   => 'guest',
			);
		$total = '';
		if ( ListingType::Stay === $listing->type && '' !== $filters['check_in'] && '' !== $filters['check_out'] ) {
			try {
				$quote = Pricing::stay( $listing, $filters['check_in'], $filters['check_out'], max( 1, min( $listing->max_guests, $filters['guests'] ) ), array() );
				$total = Pricing::money( $quote['total_cents'] ) . ' total';
			} catch ( BookingError $e ) {
				$total = '';
			}
		}

		return array(
			'id'       => $listing->id,
			'title'    => $listing->title,
			'url'      => add_query_arg( $carry, $listing->url() ),
			'type'     => $listing->type->value,
			'kind'     => $listing->kind_label(),
			'location' => null === $location ? '' : $location['name'],
			'summary'  => $listing->summary,
			'facts'    => $listing->size_facts(),
			'photos'   => array_slice( $listing->photo_list( 'medium_large' ), 0, 4 ),
			'price'    => $price,
			'total'    => $total,
			'instant'  => $listing->instant_book,
			'lat'      => null === $point ? null : $point['lat'],
			'lng'      => null === $point ? null : $point['lng'],
		);
	}
}
