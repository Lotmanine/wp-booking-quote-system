<?php
/**
 * Availability engine.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what a guest may pick. The claim tables, not this class, decide who gets it.
 */
final class Availability {

	private const WEEKDAY_KEYS = array(
		1 => 'mon',
		2 => 'tue',
		3 => 'wed',
		4 => 'thu',
		5 => 'fri',
		6 => 'sat',
		7 => 'sun',
	);

	/**
	 * Build the engine.
	 *
	 * @param NightClaims $nights Night claims.
	 * @param TableSlots  $tables Table slots.
	 */
	public function __construct( private NightClaims $nights, private TableSlots $tables ) {
	}

	/**
	 * First night a guest may arrive: tomorrow, UTC.
	 */
	public static function first_check_in(): string {
		return gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
	}

	/**
	 * Whether a stay range is inside the booking window and the listing rules, ignoring claims.
	 *
	 * @param Listing $listing   Listing.
	 * @param string  $check_in  Arrival.
	 * @param string  $check_out Departure.
	 * @throws BookingError With the reason when it is not.
	 */
	public function assert_stay_range( Listing $listing, string $check_in, string $check_out ): void {
		if ( ! Listing::is_date( $check_in ) || ! Listing::is_date( $check_out ) ) {
			throw new BookingError( 'dates_invalid', 'Choose your dates.', 400 );
		}
		if ( $check_in < self::first_check_in() ) {
			throw new BookingError( 'dates_past', 'Arrivals start tomorrow.', 400 );
		}
		if ( $check_out > gmdate( 'Y-m-d', time() + Config::STAY_DAYS_AHEAD * DAY_IN_SECONDS ) ) {
			throw new BookingError( 'dates_far', 'That is too far ahead to book.', 400 );
		}
		$nights = Pricing::nights( $check_in, $check_out );
		if ( $nights < $listing->min_nights || $nights > $listing->max_nights ) {
			throw new BookingError( 'stay_length', sprintf( 'Stays here are %d to %d nights.', (int) $listing->min_nights, (int) $listing->max_nights ), 400 );
		}
	}

	/**
	 * Night by night status of a stay listing, for calendars.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $from    First night.
	 * @param string  $to      Last night.
	 * @return array<string,string> Unavailable nights keyed by date: booked, held or blocked.
	 */
	public function stay_calendar( Listing $listing, string $from, string $to ): array {
		$out = array();
		foreach ( $this->nights->taken( $listing->id, $from, $to ) as $night => $claim ) {
			$out[ $night ] = NightClaims::BLOCK === $claim['kind'] ? 'blocked' : ( NightClaims::HOLD === $claim['kind'] ? 'held' : 'booked' );
		}
		return $out;
	}

	/**
	 * Whether a dining slot follows the listing's service pattern and the booking window.
	 *
	 * @param Listing $listing    Listing.
	 * @param string  $slot_start Slot, Y-m-d H:i:s UTC.
	 */
	public function is_bookable_slot( Listing $listing, string $slot_start ): bool {
		if ( ListingType::Dining !== $listing->type ) {
			return false;
		}
		$time = strtotime( $slot_start . ' UTC' );
		if ( false === $time || gmdate( 'Y-m-d H:i:s', $time ) !== $slot_start ) {
			return false;
		}
		if ( $time < time() + Config::DINING_LEAD_MINUTES * MINUTE_IN_SECONDS || $time > time() + Config::DINING_DAYS_AHEAD * DAY_IN_SECONDS ) {
			return false;
		}
		return in_array( gmdate( 'H:i', $time ), $this->pattern_times( $listing, gmdate( 'Y-m-d', $time ) ), true );
	}

	/**
	 * Open times on a day with the seats left in each.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $day     Day, Y-m-d.
	 * @return array<int,array{slot_start:string,time:string,period:string,seats_left:int}>
	 */
	public function dining_slots( Listing $listing, string $day ): array {
		if ( ListingType::Dining !== $listing->type || ! Listing::is_date( $day ) ) {
			return array();
		}
		if ( array() !== $this->tables->closed_days( $listing->id, $day, $day ) ) {
			return array();
		}
		$booked = $this->tables->booked_on( $listing->id, $day );
		$out    = array();
		foreach ( $this->pattern_times( $listing, $day, true ) as $time => $period ) {
			$slot = $day . ' ' . $time . ':00';
			if ( ! $this->is_bookable_slot( $listing, $slot ) ) {
				continue;
			}
			$left = $listing->covers - ( $booked[ $slot ] ?? 0 );
			if ( $left >= $listing->min_party ) {
				$out[] = array(
					'slot_start' => $slot,
					'time'       => $time,
					'period'     => $period,
					'seats_left' => $left,
				);
			}
		}
		return $out;
	}

	/**
	 * Days of the next weeks with service, used to grey out closed days in the date picker.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $from    First day.
	 * @param string  $to      Last day.
	 * @return array<string,string> Unavailable days keyed by date: closed or no_service.
	 */
	public function dining_calendar( Listing $listing, string $from, string $to ): array {
		$out    = array();
		$closed = array_flip( $this->tables->closed_days( $listing->id, $from, $to ) );
		$day    = $from;
		$guard  = 0;
		while ( $day <= $to && $guard++ < 400 ) {
			if ( isset( $closed[ $day ] ) ) {
				$out[ $day ] = 'closed';
			} elseif ( array() === $this->pattern_times( $listing, $day ) ) {
				$out[ $day ] = 'no_service';
			}
			$day = gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * Slot times on a weekday from the service periods.
	 *
	 * @param Listing $listing     Listing.
	 * @param string  $day         Day.
	 * @param bool    $with_period Whether to key times with their period label.
	 * @return array<int|string,string> Times, or period labels keyed by time.
	 */
	private function pattern_times( Listing $listing, string $day, bool $with_period = false ): array {
		$weekday = self::WEEKDAY_KEYS[ (int) gmdate( 'N', (int) strtotime( $day . ' 00:00:00 UTC' ) ) ];
		$times   = array();
		foreach ( $listing->periods as $period ) {
			if ( ! in_array( $weekday, $period['days'], true ) ) {
				continue;
			}
			$start = (int) strtotime( $day . ' ' . $period['start'] . ':00 UTC' );
			$end   = (int) strtotime( $day . ' ' . $period['end'] . ':00 UTC' );
			for ( $t = $start; $t <= $end; $t += $listing->slot_minutes * MINUTE_IN_SECONDS ) {
				$times[ gmdate( 'H:i', $t ) ] = $period['label'];
			}
		}
		ksort( $times );
		return $with_period ? $times : array_keys( $times );
	}
}
