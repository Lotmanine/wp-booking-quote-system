<?php
/**
 * Booking states.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The booking lifecycle, shared by stays and table bookings, and its legal transitions.
 */
enum BookingState: string {

	case Quote       = 'quote';
	case Hold        = 'hold';
	case DepositPaid = 'deposit_paid';
	case Requested   = 'requested';
	case Confirmed   = 'confirmed';
	case Arrived     = 'arrived';
	case Completed   = 'completed';
	case NoShow      = 'no_show';
	case Declined    = 'declined';
	case Cancelled   = 'cancelled';

	/**
	 * States reachable from this one.
	 *
	 * @return self[]
	 */
	public function next(): array {
		return match ( $this ) {
			self::Quote       => array( self::Hold, self::Cancelled ),
			self::Hold        => array( self::Quote, self::DepositPaid, self::Cancelled ),
			self::DepositPaid => array( self::Requested, self::Confirmed, self::Cancelled ),
			self::Requested   => array( self::Confirmed, self::Declined, self::Cancelled ),
			self::Confirmed   => array( self::Arrived, self::NoShow, self::Cancelled ),
			self::Arrived     => array( self::Completed ),
			self::Completed, self::NoShow, self::Declined, self::Cancelled => array(),
		};
	}

	/**
	 * Whether a transition is legal.
	 *
	 * @param self $to Target state.
	 */
	public function can_go_to( self $to ): bool {
		return in_array( $to, $this->next(), true );
	}

	/**
	 * Whether the booking still holds nights or seats.
	 */
	public function is_live(): bool {
		return in_array( $this, array( self::Hold, self::DepositPaid, self::Requested, self::Confirmed, self::Arrived ), true );
	}

	/**
	 * Human label.
	 *
	 * @param string $type Listing type, for the arrival wording.
	 */
	public function label( string $type = 'stay' ): string {
		return match ( $this ) {
			self::Quote       => 'Not reserved',
			self::Hold        => 'On hold for payment',
			self::DepositPaid => 'Deposit paid',
			self::Requested   => 'Waiting for the host',
			self::Confirmed   => 'Confirmed',
			self::Arrived     => 'dining' === $type ? 'Seated' : 'Checked in',
			self::Completed   => 'Completed',
			self::NoShow      => 'No-show',
			self::Declined    => 'Declined by the host',
			self::Cancelled   => 'Cancelled',
		};
	}
}
