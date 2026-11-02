<?php
/**
 * Listing types.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

defined( 'ABSPATH' ) || exit;

/**
 * A listing is either a place to stay, booked by the night, or a place to eat, booked by the seating.
 */
enum ListingType: string {

	case Stay   = 'stay';
	case Dining = 'dining';

	/**
	 * Human label.
	 */
	public function label(): string {
		return self::Stay === $this ? 'Place to stay' : 'Place to eat';
	}

	/**
	 * Label of the "arrived" state for this type.
	 */
	public function arrived_label(): string {
		return self::Stay === $this ? 'Checked in' : 'Seated';
	}

	/**
	 * Parse a stored value, defaulting to a stay.
	 *
	 * @param string $value Stored value.
	 */
	public static function parse( string $value ): self {
		return self::tryFrom( $value ) ?? self::Stay;
	}
}
