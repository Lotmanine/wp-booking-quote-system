<?php
/**
 * Atomic fixed-window counters.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Backs rate limits and the email cap.
 */
final class Counters {

	/**
	 * Take one unit from a counter if it is below its limit.
	 *
	 * The single upsert keeps this atomic: MariaDB reports 0 affected rows when the IF leaves the
	 * value unchanged, which only happens at the limit.
	 *
	 * @param string $key            Counter key.
	 * @param int    $window_seconds Window length.
	 * @param int    $limit          Maximum inside the window.
	 * @return bool True when the unit was taken.
	 */
	public function take( string $key, int $window_seconds, int $limit ): bool {
		global $wpdb;
		if ( $limit < 1 ) {
			return false;
		}
		$window = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		$rows   = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (counter_key, window_start, count_value) VALUES (%s, %d, 1)
				ON DUPLICATE KEY UPDATE count_value = IF(count_value < %d, count_value + 1, count_value)',
				Db::table( 'counters' ),
				$key,
				$window,
				$limit
			)
		);
		return $rows > 0;
	}

	/**
	 * Give back a unit, used when a send did not happen.
	 *
	 * @param string $key            Counter key.
	 * @param int    $window_seconds Window length.
	 */
	public function give_back( string $key, int $window_seconds ): void {
		global $wpdb;
		$window = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET count_value = count_value - 1 WHERE counter_key = %s AND window_start = %d AND count_value > 0',
				Db::table( 'counters' ),
				$key,
				$window
			)
		);
	}

	/**
	 * Current value.
	 *
	 * @param string $key            Counter key.
	 * @param int    $window_seconds Window length.
	 */
	public function value( string $key, int $window_seconds ): int {
		global $wpdb;
		$window = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT count_value FROM %i WHERE counter_key = %s AND window_start = %d', Db::table( 'counters' ), $key, $window )
		);
	}

	/**
	 * Drop windows older than two days.
	 */
	public function purge(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_start < %d', Db::table( 'counters' ), time() - 2 * DAY_IN_SECONDS ) );
	}
}
