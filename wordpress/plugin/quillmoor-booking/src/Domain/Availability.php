<?php
/**
 * Availability engine.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Works out open slots from the working pattern and live claims.
 */
final class Availability {

	public const WINDOWS = array( 9, 11, 13, 15 );

	/**
	 * Build the engine.
	 *
	 * @param SlotClaims $claims Slot claims.
	 */
	public function __construct( private SlotClaims $claims ) {
	}

	/**
	 * Whether a timestamp is inside the bookable pattern, ignoring current claims.
	 *
	 * @param string $slot_start Slot start, UTC.
	 */
	public function is_bookable_time( string $slot_start ): bool {
		$time = strtotime( $slot_start . ' UTC' );
		if ( false === $time || gmdate( 'Y-m-d H:i:s', $time ) !== $slot_start ) {
			return false;
		}
		if ( '0' === gmdate( 'w', $time ) ) {
			return false;
		}
		if ( ! in_array( (int) gmdate( 'G', $time ), self::WINDOWS, true ) || '00:00' !== gmdate( 'i:s', $time ) ) {
			return false;
		}
		$earliest = time() + Config::MIN_LEAD_HOURS * HOUR_IN_SECONDS;
		$latest   = time() + Config::BOOKING_DAYS_AHEAD * DAY_IN_SECONDS;
		return $time >= $earliest && $time <= $latest;
	}

	/**
	 * Open slots for an area, grouped by date.
	 *
	 * @param string $area Area key.
	 * @return array<string,array<int,string>>
	 */
	public function open_slots( string $area ): array {
		$capacity = Catalog::capacity( $area );
		if ( $capacity < 1 ) {
			return array();
		}

		$from  = gmdate( 'Y-m-d 00:00:00' );
		$to    = gmdate( 'Y-m-d 00:00:00', time() + ( Config::BOOKING_DAYS_AHEAD + 1 ) * DAY_IN_SECONDS );
		$taken = $this->claims->taken( $area, $from, $to );
		$days  = array();

		for ( $day = 0; $day <= Config::BOOKING_DAYS_AHEAD; $day++ ) {
			$date = gmdate( 'Y-m-d', time() + $day * DAY_IN_SECONDS );
			foreach ( self::WINDOWS as $hour ) {
				$slot = sprintf( '%s %02d:00:00', $date, $hour );
				if ( $this->is_bookable_time( $slot ) && ( $taken[ $slot ] ?? 0 ) < $capacity ) {
					$days[ $date ][] = $slot;
				}
			}
		}

		return $days;
	}
}
