<?php
/**
 * Night ownership for stays.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * One row per listing and night, under UNIQUE(listing_id, night). Holds, bookings and host blocks
 * all live in the same table, so the database decides every conflict.
 */
final class NightClaims {

	public const HOLD    = 'hold';
	public const BOOKING = 'booking';
	public const BLOCK   = 'block';

	/**
	 * Claim every night of a range in one statement.
	 *
	 * A multi-row INSERT is atomic: if any night is taken, the duplicate key fails the whole
	 * statement and no night is written. Rows go in ascending date order, so two overlapping
	 * requests lock in the same order and cannot both stall: exactly one of them commits.
	 *
	 * @param int         $listing_id Listing id.
	 * @param string[]    $nights     Night dates, Y-m-d, ascending.
	 * @param int         $booking_id Booking id.
	 * @param string|null $expires_at Hold expiry, or null for a confirmed booking.
	 */
	public function acquire( int $listing_id, array $nights, int $booking_id, ?string $expires_at ): bool {
		global $wpdb;
		if ( array() === $nights ) {
			return false;
		}
		sort( $nights );
		$table = Db::table( 'night_claims' );

		// Free nights whose hold ran out. Racers may both delete; the insert below still has one winner.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE listing_id = %d AND night >= %s AND night <= %s AND kind = %s AND expires_at < %s',
				$table,
				$listing_id,
				$nights[0],
				$nights[ count( $nights ) - 1 ],
				self::HOLD,
				Db::now()
			)
		);

		$kind   = null === $expires_at ? self::BOOKING : self::HOLD;
		$values = array();
		foreach ( $nights as $night ) {
			$values[] = null === $expires_at
				? $wpdb->prepare( '(%d, %s, %d, %s, NULL, %s)', $listing_id, $night, $booking_id, $kind, Db::now() )
				: $wpdb->prepare( '(%d, %s, %d, %s, %s, %s)', $listing_id, $night, $booking_id, $kind, $expires_at, Db::now() );
		}

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Each tuple is prepared above.
			$wpdb->prepare( 'INSERT INTO %i (listing_id, night, booking_id, kind, expires_at, created_at) VALUES ', $table ) . implode( ', ', $values )
		);
		$wpdb->suppress_errors( $suppress );

		return count( $nights ) === $inserted;
	}

	/**
	 * Turn a booking's held nights into booked nights.
	 *
	 * A held row still present belongs to the booking even past its expiry, because nobody else
	 * has replaced it.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $expected   Nights the booking should own.
	 * @return bool False when any night is gone.
	 */
	public function make_permanent( int $booking_id, int $expected ): bool {
		global $wpdb;
		$table = Db::table( 'night_claims' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET kind = %s, expires_at = NULL WHERE booking_id = %d', $table, self::BOOKING, $booking_id ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', $table, $booking_id ) ) === $expected;
	}

	/**
	 * Release every night of a booking.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function release( int $booking_id ): void {
		global $wpdb;
		if ( $booking_id > 0 ) {
			$wpdb->delete( Db::table( 'night_claims' ), array( 'booking_id' => $booking_id ) );
		}
	}

	/**
	 * Release some nights of a booking.
	 *
	 * @param int      $booking_id Booking id.
	 * @param string[] $nights     Nights to free.
	 */
	public function release_nights( int $booking_id, array $nights ): void {
		global $wpdb;
		foreach ( $nights as $night ) {
			$wpdb->delete(
				Db::table( 'night_claims' ),
				array(
					'booking_id' => $booking_id,
					'night'      => $night,
				)
			);
		}
	}

	/**
	 * Block nights for a host. Taken nights stay as they are.
	 *
	 * @param int      $listing_id Listing id.
	 * @param string[] $nights     Nights.
	 * @return string[] Nights that could not be blocked.
	 */
	public function block( int $listing_id, array $nights ): array {
		global $wpdb;
		$table   = Db::table( 'night_claims' );
		$refused = array();
		foreach ( $nights as $night ) {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE listing_id = %d AND night = %s AND kind = %s AND expires_at < %s',
					$table,
					$listing_id,
					$night,
					self::HOLD,
					Db::now()
				)
			);
			$done = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (listing_id, night, booking_id, kind, expires_at, created_at) VALUES (%d, %s, 0, %s, NULL, %s)',
					$table,
					$listing_id,
					$night,
					self::BLOCK,
					Db::now()
				)
			);
			if ( 1 !== $done && self::BLOCK !== $this->kind_of( $listing_id, $night ) ) {
				$refused[] = $night;
			}
		}
		return $refused;
	}

	/**
	 * Unblock nights. Only host blocks are removed.
	 *
	 * @param int      $listing_id Listing id.
	 * @param string[] $nights     Nights.
	 */
	public function unblock( int $listing_id, array $nights ): void {
		global $wpdb;
		foreach ( $nights as $night ) {
			$wpdb->delete(
				Db::table( 'night_claims' ),
				array(
					'listing_id' => $listing_id,
					'night'      => $night,
					'kind'       => self::BLOCK,
				)
			);
		}
	}

	/**
	 * Kind of the live claim on a night, or an empty string.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $night      Night.
	 */
	public function kind_of( int $listing_id, string $night ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT kind FROM %i WHERE listing_id = %d AND night = %s AND ( expires_at IS NULL OR expires_at >= %s )',
				Db::table( 'night_claims' ),
				$listing_id,
				$night,
				Db::now()
			)
		);
	}

	/**
	 * Live claims of a listing in a range.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $from       First night, inclusive.
	 * @param string $to         Last night, inclusive.
	 * @return array<string,array{kind:string,booking_id:int}> Keyed by night.
	 */
	public function taken( int $listing_id, string $from, string $to ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT night, kind, booking_id FROM %i WHERE listing_id = %d AND night >= %s AND night <= %s AND ( expires_at IS NULL OR expires_at >= %s )',
				Db::table( 'night_claims' ),
				$listing_id,
				$from,
				$to,
				Db::now()
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['night'] ] = array(
				'kind'       => (string) $row['kind'],
				'booking_id' => (int) $row['booking_id'],
			);
		}
		return $out;
	}
}
