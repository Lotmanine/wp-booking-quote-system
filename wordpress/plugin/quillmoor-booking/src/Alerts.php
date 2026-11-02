<?php
/**
 * Operational alerts.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts shown on the admin screen.
 */
final class Alerts {

	/**
	 * Store an alert.
	 *
	 * @param string $source  Where it came from.
	 * @param string $message Plain text message.
	 */
	public static function log( string $source, string $message ): void {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'alerts' ),
			array(
				'source'     => substr( sanitize_key( $source ), 0, 40 ),
				'message'    => substr( sanitize_text_field( $message ), 0, 500 ),
				'created_at' => Db::now(),
			)
		);
	}

	/**
	 * Latest alerts.
	 *
	 * @param int $limit Row count.
	 * @return array<int,array<string,mixed>>
	 */
	public static function latest( int $limit = 50 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT source, message, created_at FROM %i ORDER BY id DESC LIMIT %d', Db::table( 'alerts' ), $limit ),
			ARRAY_A
		);
	}
}
