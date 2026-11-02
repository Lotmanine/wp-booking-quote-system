<?php
/**
 * Booking wording.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Listings\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Shared wording for a booking, so the trips page, wp-admin, email and PDFs say the same thing.
 */
final class BookingView {

	/**
	 * Public reference.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function ref( array $booking ): string {
		return 'QM-' . (int) $booking['id'];
	}

	/**
	 * Whether the booking is a table booking.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function is_dining( array $booking ): bool {
		return 'dining' === (string) $booking['listing_type'];
	}

	/**
	 * Listing title, or a fallback when the listing was deleted.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function listing_title( array $booking ): string {
		$title = get_the_title( (int) $booking['listing_id'] );
		return '' === $title ? 'Listing ' . (int) $booking['listing_id'] : $title;
	}

	/**
	 * When, in words: dates and nights for a stay, day, time and party for a table.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function when( array $booking ): string {
		if ( self::is_dining( $booking ) ) {
			$start = (int) strtotime( (string) $booking['slot_start'] . ' UTC' );
			$party = (int) $booking['guests'];
			return sprintf( '%s at %s, %d %s', gmdate( 'D, M j, Y', $start ), gmdate( 'H:i', $start ), $party, 1 === $party ? 'guest' : 'guests' );
		}
		$in     = (string) $booking['check_in'];
		$out    = (string) $booking['check_out'];
		$nights = Pricing::nights( $in, $out );
		$guests = (int) $booking['guests'];
		return sprintf(
			'%s to %s, %d %s, %d %s',
			gmdate( 'D, M j', (int) strtotime( $in . ' 00:00:00 UTC' ) ),
			gmdate( 'D, M j, Y', (int) strtotime( $out . ' 00:00:00 UTC' ) ),
			$nights,
			1 === $nights ? 'night' : 'nights',
			$guests,
			1 === $guests ? 'guest' : 'guests'
		);
	}

	/**
	 * State label in the wording of the listing type.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function state_label( array $booking ): string {
		return BookingState::from( (string) $booking['state'] )->label( (string) $booking['listing_type'] );
	}

	/**
	 * Refund wording, or an empty string when there is nothing to say.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function refund_label( array $booking ): string {
		return match ( (string) $booking['refund_status'] ) {
			'flagged'   => 'Deposit refund due',
			'forfeited' => 'Deposit kept under the cancellation policy',
			'refunded'  => 'Deposit refunded: ' . Pricing::money( (int) ( $booking['refund_cents'] ?? 0 ) ),
			default     => '',
		};
	}
}
