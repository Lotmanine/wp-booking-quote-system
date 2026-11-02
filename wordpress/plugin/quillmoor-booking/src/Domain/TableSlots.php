<?php
/**
 * Seat capacity for dining.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Each time slot is a row with a capacity and a booked count. Seats are taken with one
 * conditional UPDATE, so the database refuses a party the moment the slot would overflow.
 */
final class TableSlots {

	/**
	 * Take seats in a slot.
	 *
	 * @param int         $listing_id Listing id.
	 * @param string      $slot_start Slot start, UTC.
	 * @param int         $capacity   Seats per slot from the listing.
	 * @param int         $covers     Party size.
	 * @param int         $booking_id Booking id.
	 * @param string|null $expires_at Hold expiry, or null when permanent.
	 */
	public function acquire( int $listing_id, string $slot_start, int $capacity, int $covers, int $booking_id, ?string $expires_at ): bool {
		global $wpdb;
		$slots = Db::table( 'table_slots' );
		$day   = substr( $slot_start, 0, 10 );

		$this->release_expired_in_slot( $listing_id, $slot_start );

		// Create the slot row on first use. A closed day is copied in, so a later conditional update refuses it.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (listing_id, slot_start, capacity, booked, closed)
				SELECT %d, %s, %d, 0, EXISTS (SELECT 1 FROM %i WHERE listing_id = %d AND day = %s)',
				$slots,
				$listing_id,
				$slot_start,
				$capacity,
				Db::table( 'day_closures' ),
				$listing_id,
				$day
			)
		);
		// A host may have changed the seat count since the row was made.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET capacity = %d WHERE listing_id = %d AND slot_start = %s', $slots, $capacity, $listing_id, $slot_start ) );

		$taken = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET booked = booked + %d WHERE listing_id = %d AND slot_start = %s AND closed = 0 AND booked + %d <= capacity',
				$slots,
				$covers,
				$listing_id,
				$slot_start,
				$covers
			)
		);
		if ( 1 !== $taken ) {
			return false;
		}

		if ( null === $expires_at ) {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (booking_id, listing_id, slot_start, covers, expires_at, created_at) VALUES (%d, %d, %s, %d, NULL, %s)
					ON DUPLICATE KEY UPDATE covers = VALUES(covers), expires_at = NULL',
					Db::table( 'table_claims' ),
					$booking_id,
					$listing_id,
					$slot_start,
					$covers,
					Db::now()
				)
			);
			return true;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (booking_id, listing_id, slot_start, covers, expires_at, created_at) VALUES (%d, %d, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE covers = VALUES(covers), expires_at = VALUES(expires_at)',
				Db::table( 'table_claims' ),
				$booking_id,
				$listing_id,
				$slot_start,
				$covers,
				$expires_at,
				Db::now()
			)
		);
		return true;
	}

	/**
	 * Make a booking's seats permanent.
	 *
	 * @param int $booking_id Booking id.
	 * @return bool False when the seats are gone.
	 */
	public function make_permanent( int $booking_id ): bool {
		global $wpdb;
		$table = Db::table( 'table_claims' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = NULL WHERE booking_id = %d', $table, $booking_id ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', $table, $booking_id ) ) > 0;
	}

	/**
	 * Give a booking's seats back. Each claim row is deleted before its seats are returned, so
	 * only one caller can decrement, however many sweeps race.
	 *
	 * @param int         $booking_id Booking id.
	 * @param string|null $slot_start Only this slot, or every slot of the booking.
	 */
	public function release( int $booking_id, ?string $slot_start = null ): void {
		global $wpdb;
		if ( null === $slot_start ) {
			$claims = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, listing_id, slot_start, covers FROM %i WHERE booking_id = %d', Db::table( 'table_claims' ), $booking_id ), ARRAY_A );
		} else {
			$claims = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, listing_id, slot_start, covers FROM %i WHERE booking_id = %d AND slot_start = %s', Db::table( 'table_claims' ), $booking_id, $slot_start ), ARRAY_A );
		}
		foreach ( $claims as $claim ) {
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Db::table( 'table_claims' ), (int) $claim['id'] ) );
			if ( 1 !== $deleted ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET booked = GREATEST(CAST(booked AS SIGNED) - %d, 0) WHERE listing_id = %d AND slot_start = %s',
					Db::table( 'table_slots' ),
					(int) $claim['covers'],
					(int) $claim['listing_id'],
					(string) $claim['slot_start']
				)
			);
		}
	}

	/**
	 * Release holds in one slot whose time ran out, so their seats can be taken again.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $slot_start Slot start.
	 */
	private function release_expired_in_slot( int $listing_id, string $slot_start ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT booking_id FROM %i WHERE listing_id = %d AND slot_start = %s AND expires_at IS NOT NULL AND expires_at < %s',
				Db::table( 'table_claims' ),
				$listing_id,
				$slot_start,
				Db::now()
			)
		);
		foreach ( $ids as $id ) {
			$this->release( (int) $id, $slot_start );
		}
	}

	/**
	 * Seats taken per slot on one day.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $day        Day, Y-m-d.
	 * @return array<string,int> Booked seats keyed by slot start.
	 */
	public function booked_on( int $listing_id, string $day ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT slot_start, SUM(covers) AS seats FROM %i WHERE listing_id = %d AND slot_start >= %s AND slot_start < %s AND ( expires_at IS NULL OR expires_at >= %s ) GROUP BY slot_start',
				Db::table( 'table_claims' ),
				$listing_id,
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00',
				Db::now()
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['slot_start'] ] = (int) $row['seats'];
		}
		return $out;
	}

	/**
	 * Close a day for a host.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $day        Day.
	 * @param int    $user_id    Host.
	 * @return bool False when the day already has bookings.
	 */
	public function close_day( int $listing_id, string $day, int $user_id ): bool {
		global $wpdb;
		if ( array() !== array_filter( $this->booked_on( $listing_id, $day ) ) ) {
			return false;
		}
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (listing_id, day, created_by, created_at) VALUES (%d, %s, %d, %s)',
				Db::table( 'day_closures' ),
				$listing_id,
				$day,
				$user_id,
				Db::now()
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET closed = 1 WHERE listing_id = %d AND slot_start >= %s AND slot_start < %s',
				Db::table( 'table_slots' ),
				$listing_id,
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00'
			)
		);
		return true;
	}

	/**
	 * Reopen a day.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $day        Day.
	 */
	public function open_day( int $listing_id, string $day ): void {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'day_closures' ),
			array(
				'listing_id' => $listing_id,
				'day'        => $day,
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET closed = 0 WHERE listing_id = %d AND slot_start >= %s AND slot_start < %s',
				Db::table( 'table_slots' ),
				$listing_id,
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00'
			)
		);
	}

	/**
	 * Closed days of a listing in a range.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $from       First day.
	 * @param string $to         Last day.
	 * @return string[]
	 */
	public function closed_days( int $listing_id, string $from, string $to ): array {
		global $wpdb;
		return array_map(
			'strval',
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT day FROM %i WHERE listing_id = %d AND day >= %s AND day <= %s',
					Db::table( 'day_closures' ),
					$listing_id,
					$from,
					$to
				)
			)
		);
	}
}
