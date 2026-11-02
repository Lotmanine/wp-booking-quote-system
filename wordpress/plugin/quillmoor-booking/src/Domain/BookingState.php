<?php
/**
 * Booking states.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The booking lifecycle and its legal transitions.
 */
enum BookingState: string {

	case Quote       = 'quote';
	case Hold        = 'hold';
	case DepositPaid = 'deposit_paid';
	case Confirmed   = 'confirmed';
	case Assigned    = 'assigned';
	case Completed   = 'completed';
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
			self::DepositPaid => array( self::Confirmed, self::Cancelled ),
			self::Confirmed   => array( self::Assigned, self::Cancelled ),
			self::Assigned    => array( self::Confirmed, self::Completed, self::Cancelled ),
			self::Completed, self::Cancelled => array(),
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
	 * Human label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Quote       => 'Quote',
			self::Hold        => 'Slot on hold',
			self::DepositPaid => 'Deposit paid',
			self::Confirmed   => 'Confirmed',
			self::Assigned    => 'Staff assigned',
			self::Completed   => 'Completed',
			self::Cancelled   => 'Cancelled',
		};
	}
}
