<?php
/**
 * Slot ownership.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * The UNIQUE(area, slot_start, unit) key decides who owns a slot. PHP never checks first.
 */
final class SlotClaims {

	/**
	 * Try to claim one unit of a slot.
	 *
	 * Statements run in autocommit on purpose: no transaction spans the delete and the insert,
	 * so no gap lock is held between them and racers cannot deadlock. If two racers both clear
	 * an expired row, the unique key still lets only one insert land.
	 *
	 * @param string      $area       Area key.
	 * @param string      $slot_start Slot start, UTC.
	 * @param int         $booking_id Claiming booking.
	 * @param string|null $expires_at Hold expiry, or null for a permanent claim.
	 */
	public function acquire( string $area, string $slot_start, int $booking_id, ?string $expires_at ): bool {
		global $wpdb;
		$table    = Db::table( 'slot_claims' );
		$capacity = Catalog::capacity( $area );

		for ( $unit = 1; $unit <= $capacity; $unit++ ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE area = %s AND slot_start = %s AND unit = %d AND expires_at IS NOT NULL AND expires_at < %s',
					$table,
					$area,
					$slot_start,
					$unit,
					Db::now()
				)
			);

			if ( null === $expires_at ) {
				$sql = $wpdb->prepare(
					'INSERT IGNORE INTO %i (area, slot_start, unit, booking_id, expires_at, created_at) VALUES (%s, %s, %d, %d, NULL, %s)',
					$table,
					$area,
					$slot_start,
					$unit,
					$booking_id,
					Db::now()
				);
			} else {
				$sql = $wpdb->prepare(
					'INSERT IGNORE INTO %i (area, slot_start, unit, booking_id, expires_at, created_at) VALUES (%s, %s, %d, %d, %s, %s)',
					$table,
					$area,
					$slot_start,
					$unit,
					$booking_id,
					$expires_at,
					Db::now()
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared in the branch above.
			if ( 1 === $wpdb->query( $sql ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Make the claims of a booking permanent.
	 *
	 * A claim still present belongs to the booking even past its expiry, because nobody else
	 * has taken the slot.
	 *
	 * @param int $booking_id Booking id.
	 * @return bool False when the claim is gone.
	 */
	public function make_permanent( int $booking_id ): bool {
		global $wpdb;
		$table = Db::table( 'slot_claims' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = NULL WHERE booking_id = %d', $table, $booking_id ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', $table, $booking_id ) ) > 0;
	}

	/**
	 * Release every claim of a booking.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function release( int $booking_id ): void {
		global $wpdb;
		$wpdb->delete( Db::table( 'slot_claims' ), array( 'booking_id' => $booking_id ) );
	}

	/**
	 * Release a single claim by slot, used when moving a booking to a new slot.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $slot_start Slot start.
	 */
	public function release_slot( int $booking_id, string $slot_start ): void {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'slot_claims' ),
			array(
				'booking_id' => $booking_id,
				'slot_start' => $slot_start,
			)
		);
	}

	/**
	 * Count live claims per slot in a range.
	 *
	 * @param string $area Area key.
	 * @param string $from Range start.
	 * @param string $to   Range end.
	 * @return array<string,int> Keyed by slot_start.
	 */
	public function taken( string $area, string $from, string $to ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT slot_start, COUNT(*) AS taken FROM %i
				WHERE area = %s AND slot_start >= %s AND slot_start < %s AND ( expires_at IS NULL OR expires_at >= %s )
				GROUP BY slot_start',
				Db::table( 'slot_claims' ),
				$area,
				$from,
				$to,
				Db::now()
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['slot_start'] ] = (int) $row['taken'];
		}
		return $out;
	}
}
