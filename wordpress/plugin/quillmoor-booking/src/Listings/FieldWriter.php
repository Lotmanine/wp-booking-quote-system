<?php
/**
 * Field storage.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes listing fields in the post meta layout Secure Custom Fields uses, with the reference keys
 * it expects, so the seeder and the tests do not depend on the plugin's load order.
 */
final class FieldWriter {

	/**
	 * Save field values on a listing.
	 *
	 * @param int                 $post_id Listing id.
	 * @param array<string,mixed> $values  Values keyed by field name.
	 */
	public static function save( int $post_id, array $values ): void {
		$fields = array();
		foreach ( Fields::listing_fields() as $field ) {
			if ( '' !== (string) $field['name'] ) {
				$fields[ (string) $field['name'] ] = $field;
			}
		}

		foreach ( $values as $name => $value ) {
			if ( ! isset( $fields[ $name ] ) ) {
				continue;
			}
			$field = $fields[ $name ];
			if ( 'repeater' === $field['type'] ) {
				self::save_rows( $post_id, $field, is_array( $value ) ? array_values( $value ) : array() );
				continue;
			}
			update_post_meta( $post_id, $name, is_bool( $value ) ? (int) $value : $value );
			update_post_meta( $post_id, '_' . $name, (string) $field['key'] );
		}
	}

	/**
	 * Save repeater rows: a count, then one meta row per sub field.
	 *
	 * @param int                            $post_id Listing id.
	 * @param array<string,mixed>            $field   Repeater definition.
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 */
	private static function save_rows( int $post_id, array $field, array $rows ): void {
		$name = (string) $field['name'];
		$old  = (int) get_post_meta( $post_id, $name, true );
		$subs = is_array( $field['sub_fields'] ?? null ) ? $field['sub_fields'] : array();

		$count = count( $rows );
		$last  = max( $old, $count );
		for ( $i = 0; $i < $last; $i++ ) {
			foreach ( $subs as $sub ) {
				$meta = $name . '_' . $i . '_' . $sub['name'];
				if ( $i >= $count ) {
					delete_post_meta( $post_id, $meta );
					delete_post_meta( $post_id, '_' . $meta );
					continue;
				}
				update_post_meta( $post_id, $meta, $rows[ $i ][ $sub['name'] ] ?? '' );
				update_post_meta( $post_id, '_' . $meta, (string) $sub['key'] );
			}
		}
		update_post_meta( $post_id, $name, count( $rows ) );
		update_post_meta( $post_id, '_' . $name, (string) $field['key'] );
	}
}
