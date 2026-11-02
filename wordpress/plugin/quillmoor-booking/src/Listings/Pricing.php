<?php
/**
 * Price rules.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

use Quillmoor\Booking\Domain\BookingError;

defined( 'ABSPATH' ) || exit;

/**
 * Every price is computed here, on the server, in integer cents. Prices sent by a browser are never read.
 */
final class Pricing {

	public const MIN_DEPOSIT_CENTS = 2000;

	/**
	 * Price a stay.
	 *
	 * Each night takes the first matching rule: a seasonal rate, then the Friday and Saturday
	 * price, then the nightly price.
	 *
	 * @param Listing  $listing   Listing.
	 * @param string   $check_in  Arrival date, Y-m-d.
	 * @param string   $check_out Departure date, Y-m-d.
	 * @param int      $guests    Guests.
	 * @param string[] $extras    Extra keys.
	 * @return array{line_items: array<int,array{label:string,cents:int}>, total_cents:int, deposit_cents:int, nights:int}
	 * @throws BookingError When the request breaks a listing rule.
	 */
	public static function stay( Listing $listing, string $check_in, string $check_out, int $guests, array $extras ): array {
		if ( ListingType::Stay !== $listing->type ) {
			throw new BookingError( 'wrong_type', 'This listing takes table bookings, not stays.', 400 );
		}
		$nights = self::nights( $check_in, $check_out );
		if ( $nights < 1 ) {
			throw new BookingError( 'dates_invalid', 'Choose a check-out date after check-in.', 400 );
		}
		if ( $nights < $listing->min_nights ) {
			throw new BookingError( 'too_short', sprintf( 'This place needs at least %d nights.', (int) $listing->min_nights ), 400 );
		}
		if ( $nights > $listing->max_nights ) {
			throw new BookingError( 'too_long', sprintf( 'This place takes at most %d nights.', (int) $listing->max_nights ), 400 );
		}
		if ( $guests < 1 || $guests > $listing->max_guests ) {
			throw new BookingError( 'too_many_guests', sprintf( 'This place sleeps up to %d guests.', (int) $listing->max_guests ), 400 );
		}
		if ( $listing->nightly_cents < 1 ) {
			throw new BookingError( 'not_priced', 'This place has no price yet.', 409 );
		}

		// Group nights by rate, so the breakdown reads "$185 x 3 nights" rather than one line a night.
		$groups = array();
		$first  = 0;
		foreach ( self::night_dates( $check_in, $nights ) as $i => $night ) {
			$rate  = self::night_rate( $listing, $night );
			$key   = $rate['label'] . '|' . $rate['cents'];
			$first = 0 === $i ? $rate['cents'] : $first;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'label' => $rate['label'],
					'cents' => $rate['cents'],
					'count' => 0,
				);
			}
			++$groups[ $key ]['count'];
		}

		$lines = array();
		foreach ( $groups as $group ) {
			$lines[] = array(
				'label' => sprintf(
					'%s x %d %s%s',
					self::money( $group['cents'] ),
					$group['count'],
					'' === $group['label'] ? '' : $group['label'] . ' ',
					1 === $group['count'] ? 'night' : 'nights'
				),
				'cents' => $group['cents'] * $group['count'],
			);
		}
		if ( $listing->cleaning_cents > 0 ) {
			$lines[] = array(
				'label' => 'Cleaning fee',
				'cents' => $listing->cleaning_cents,
			);
		}
		$lines = array_merge( $lines, self::extra_lines( $listing, $extras, $nights, $guests ) );
		$total = (int) array_sum( array_column( $lines, 'cents' ) );

		return array(
			'line_items'    => $lines,
			'total_cents'   => $total,
			'deposit_cents' => self::deposit( $listing, $total, $guests, $first ),
			'nights'        => $nights,
		);
	}

	/**
	 * Price a table booking.
	 *
	 * @param Listing  $listing Listing.
	 * @param int      $party   Guests.
	 * @param string[] $extras  Extra keys.
	 * @return array{line_items: array<int,array{label:string,cents:int}>, total_cents:int, deposit_cents:int, nights:int}
	 * @throws BookingError When the party breaks a listing rule.
	 */
	public static function dining( Listing $listing, int $party, array $extras ): array {
		if ( ListingType::Dining !== $listing->type ) {
			throw new BookingError( 'wrong_type', 'This listing takes stays, not table bookings.', 400 );
		}
		if ( $party < $listing->min_party || $party > $listing->max_party ) {
			throw new BookingError( 'party_size', sprintf( 'Tables here are for %d to %d guests.', (int) $listing->min_party, (int) $listing->max_party ), 400 );
		}

		$lines = array();
		if ( $listing->menu_cents > 0 ) {
			$lines[] = array(
				'label' => sprintf( 'Set menu, %s x %d %s', self::money( $listing->menu_cents ), $party, 1 === $party ? 'guest' : 'guests' ),
				'cents' => $listing->menu_cents * $party,
			);
		}
		$lines = array_merge( $lines, self::extra_lines( $listing, $extras, 1, $party ) );
		$total = (int) array_sum( array_column( $lines, 'cents' ) );

		return array(
			'line_items'    => $lines,
			'total_cents'   => $total,
			'deposit_cents' => self::deposit( $listing, $total, $party, $listing->menu_cents * $party ),
			'nights'        => 0,
		);
	}

	/**
	 * Rate of one night.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $night   Night date, Y-m-d.
	 * @return array{label:string,cents:int}
	 */
	public static function night_rate( Listing $listing, string $night ): array {
		foreach ( $listing->seasons as $season ) {
			if ( $night >= $season['start'] && $night <= $season['end'] && $season['cents'] > 0 ) {
				return array(
					'label' => strtolower( '' === $season['label'] ? 'seasonal' : $season['label'] ),
					'cents' => $season['cents'],
				);
			}
		}
		$weekday = (int) gmdate( 'N', (int) strtotime( $night . ' 00:00:00 UTC' ) );
		if ( $listing->weekend_cents > 0 && in_array( $weekday, array( 5, 6 ), true ) ) {
			return array(
				'label' => 'weekend',
				'cents' => $listing->weekend_cents,
			);
		}
		return array(
			'label' => '',
			'cents' => $listing->nightly_cents,
		);
	}

	/**
	 * Lines for the chosen extras.
	 *
	 * @param Listing  $listing Listing.
	 * @param string[] $keys    Extra keys.
	 * @param int      $nights  Nights, 1 for a table booking.
	 * @param int      $guests  Guests.
	 * @return array<int,array{label:string,cents:int}>
	 * @throws BookingError For an unknown extra.
	 */
	private static function extra_lines( Listing $listing, array $keys, int $nights, int $guests ): array {
		$lines = array();
		foreach ( array_unique( $keys ) as $key ) {
			if ( ! isset( $listing->extras[ $key ] ) ) {
				throw new BookingError( 'invalid_option', 'That extra is not offered here.', 400 );
			}
			$extra = $listing->extras[ $key ];
			$times = match ( $extra['unit'] ) {
				'per_night' => $nights,
				'per_guest' => $guests,
				default     => 1,
			};
			$label   = 1 === $times ? $extra['label'] : sprintf( '%s, %s x %d', $extra['label'], self::money( $extra['cents'] ), $times );
			$lines[] = array(
				'label' => $label,
				'cents' => $extra['cents'] * $times,
			);
		}
		return $lines;
	}

	/**
	 * Deposit from the listing rule, never below the minimum or above the total.
	 *
	 * @param Listing $listing     Listing.
	 * @param int     $total_cents Total.
	 * @param int     $guests      Guests.
	 * @param int     $first_cents First night, or the menu total for dining.
	 */
	public static function deposit( Listing $listing, int $total_cents, int $guests, int $first_cents ): int {
		$value   = $listing->deposit_value;
		$deposit = match ( $listing->deposit_rule ) {
			'per_guest'   => (int) round( $value * 100 ) * $guests,
			'flat'        => (int) round( $value * 100 ),
			'first_night' => $first_cents,
			default       => (int) ( round( $total_cents * min( 100.0, $value ) / 100 / 100 ) * 100 ),
		};
		$deposit = max( self::MIN_DEPOSIT_CENTS, $deposit );
		return $total_cents > 0 ? min( $total_cents, $deposit ) : $deposit;
	}

	/**
	 * Nights between two dates.
	 *
	 * @param string $check_in  Arrival.
	 * @param string $check_out Departure.
	 */
	public static function nights( string $check_in, string $check_out ): int {
		if ( ! Listing::is_date( $check_in ) || ! Listing::is_date( $check_out ) ) {
			return 0;
		}
		return (int) round( ( strtotime( $check_out . ' 00:00:00 UTC' ) - strtotime( $check_in . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	/**
	 * The nights a stay occupies, check-out excluded.
	 *
	 * @param string $check_in Arrival.
	 * @param int    $nights   Number of nights.
	 * @return string[]
	 */
	public static function night_dates( string $check_in, int $nights ): array {
		$start = (int) strtotime( $check_in . ' 00:00:00 UTC' );
		$out   = array();
		for ( $i = 0; $i < $nights; $i++ ) {
			$out[] = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Format cents as dollars.
	 *
	 * @param int $cents Amount.
	 */
	public static function money( int $cents ): string {
		return '$' . number_format( $cents / 100, 0 === $cents % 100 ? 0 : 2 );
	}
}
