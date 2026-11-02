<?php
/**
 * Locations and categories for search.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Read helpers for the location and category terms.
 */
final class Locations {

	/**
	 * Every location with published listings, for the "Where" field.
	 *
	 * @return array<int,array{slug:string,name:string,region:string}>
	 */
	public static function all(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::LOCATION,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[] = array(
				'slug'   => (string) $term->slug,
				'name'   => (string) $term->name,
				'region' => (string) get_term_meta( $term->term_id, 'qm_region', true ),
			);
		}
		return $out;
	}

	/**
	 * Categories shown for one listing type, in menu order.
	 *
	 * @param string $type Listing type.
	 * @return array<int,array{slug:string,name:string,icon:string}>
	 */
	public static function categories( string $type ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => PostTypes::CATEGORY,
				'hide_empty' => false,
				'orderby'    => 'term_id',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$for = (string) get_term_meta( $term->term_id, 'qm_for', true );
			if ( '' !== $for && $for !== $type ) {
				continue;
			}
			$icon  = (string) get_term_meta( $term->term_id, 'qm_icon', true );
			$out[] = array(
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
				'icon' => '' === $icon ? 'home' : $icon,
			);
		}
		return $out;
	}

	/**
	 * Dispatch zone of a location, used by the turnover team roster.
	 *
	 * @param string $slug Location slug.
	 */
	public static function zone( string $slug ): string {
		$term = get_term_by( 'slug', $slug, PostTypes::LOCATION );
		$zone = $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, 'qm_zone', true ) : '';
		return '' === $zone ? 'central' : $zone;
	}

	/**
	 * Location name from a slug.
	 *
	 * @param string $slug Location slug.
	 */
	public static function name( string $slug ): string {
		$term = get_term_by( 'slug', $slug, PostTypes::LOCATION );
		return $term instanceof WP_Term ? (string) $term->name : '';
	}
}
