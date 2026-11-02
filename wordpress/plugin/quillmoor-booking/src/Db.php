<?php
/**
 * Table name and time helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Shared database helpers.
 */
final class Db {

	/**
	 * Full table name.
	 *
	 * @param string $name Short table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'qhs_' . $name;
	}

	/**
	 * Current UTC time as a DATETIME string.
	 *
	 * @param int $offset_seconds Seconds to add.
	 */
	public static function now( int $offset_seconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset_seconds );
	}
}
