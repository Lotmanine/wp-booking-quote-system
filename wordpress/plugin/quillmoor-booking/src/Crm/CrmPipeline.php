<?php
/**
 * Booking events to CRM stages.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Crm;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Domain\BookingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A contact carries exactly one stage tag, plus type, demo and consent tags.
 */
final class CrmPipeline {

	public const STAGES = array(
		'booking.quote'        => 'stage-quote',
		'booking.hold'         => 'stage-hold',
		'booking.hold_expired' => 'stage-quote',
		'booking.deposit_paid' => 'stage-deposit-paid',
		'booking.requested'    => 'stage-requested',
		'booking.confirmed'    => 'stage-confirmed',
		'booking.rescheduled'  => 'stage-confirmed',
		'booking.arrived'      => 'stage-arrived',
		'booking.completed'    => 'stage-completed',
		'booking.no_show'      => 'stage-no-show',
		'booking.declined'     => 'stage-declined',
		'booking.cancelled'    => 'stage-cancelled',
	);

	public const CONSENT_TAG = 'consent-marketing';
	public const DEMO_TAG    = 'qhs-demo';

	/**
	 * Build the pipeline.
	 *
	 * @param CrmPort           $crm      CRM.
	 * @param BookingRepository $bookings Bookings.
	 */
	public function __construct( private CrmPort $crm, private BookingRepository $bookings ) {
	}

	/**
	 * Every tag slug the pipeline uses.
	 *
	 * @return string[]
	 */
	public static function all_tags(): array {
		return array_values(
			array_unique(
				array_merge(
					array_values( self::STAGES ),
					array( self::CONSENT_TAG, self::DEMO_TAG, 'type-stay', 'type-dining' )
				)
			)
		);
	}

	/**
	 * Apply one booking event.
	 *
	 * @param string $event      Event name.
	 * @param int    $booking_id Booking id.
	 */
	public function apply( string $event, int $booking_id ): bool {
		if ( ! isset( self::STAGES[ $event ] ) ) {
			return false;
		}
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			return false;
		}
		$customer = $this->bookings->customer( (int) $booking['customer_id'] );
		if ( null === $customer ) {
			return false;
		}

		$stage   = self::STAGES[ $event ];
		$consent = (bool) $customer['marketing_consent'];
		$add     = array( $stage, 'type-' . ( 'dining' === $booking['listing_type'] ? 'dining' : 'stay' ) );
		if ( Config::is_demo_site() ) {
			$add[] = self::DEMO_TAG;
		}
		if ( $consent ) {
			$add[] = self::CONSENT_TAG;
		}
		$remove = array_values( array_diff( array_unique( array_values( self::STAGES ) ), array( $stage ) ) );
		if ( ! $consent ) {
			$remove[] = self::CONSENT_TAG;
		}

		return $this->crm->sync_contact( (string) $customer['email'], (string) $customer['full_name'], $consent, $add, $remove );
	}

	/**
	 * Apply a consent change straight away, so withdrawal never waits on a queue.
	 *
	 * @param int  $customer_id Customer id.
	 * @param bool $consent     New consent.
	 */
	public function set_consent( int $customer_id, bool $consent ): void {
		$customer = $this->bookings->customer( $customer_id );
		if ( null === $customer ) {
			return;
		}
		$this->crm->sync_contact(
			(string) $customer['email'],
			(string) $customer['full_name'],
			$consent,
			$consent ? array( self::CONSENT_TAG ) : array(),
			$consent ? array() : array( self::CONSENT_TAG )
		);
	}
}
