<?php
/**
 * Object-level authorization.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Domain\BookingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every booking read and write goes through these checks.
 */
final class BookingPolicy {

	public const CAP_MANAGE = 'qhs_manage_bookings';
	public const CAP_WORK   = 'qhs_work_jobs';

	/**
	 * Build the policy.
	 *
	 * @param BookingRepository $bookings Bookings.
	 */
	public function __construct( private BookingRepository $bookings ) {
	}

	/**
	 * Booking token for guest links, derived so a database leak alone does not expose links.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function token_for( array $booking ): string {
		return hash_hmac( 'sha256', $booking['id'] . '|' . $booking['access_key'], wp_salt( 'auth' ) );
	}

	/**
	 * Whether the actor owns the booking.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function is_owner( Actor $actor, array $booking ): bool {
		if ( '' !== $actor->token && hash_equals( self::token_for( $booking ), $actor->token ) ) {
			return true;
		}
		if ( $actor->user_id < 1 ) {
			return false;
		}
		$customer = $this->bookings->customer( (int) $booking['customer_id'] );
		return null !== $customer && (int) $customer['user_id'] === $actor->user_id;
	}

	/**
	 * Whether the actor is the staff member on the job.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function is_assigned_staff( Actor $actor, array $booking ): bool {
		return $actor->user_id > 0
			&& (int) $booking['staff_user_id'] === $actor->user_id
			&& user_can( $actor->user_id, self::CAP_WORK );
	}

	/**
	 * Whether the actor manages all bookings.
	 *
	 * @param Actor $actor Requester.
	 */
	public function is_manager( Actor $actor ): bool {
		return $actor->user_id > 0 && user_can( $actor->user_id, self::CAP_MANAGE );
	}

	/**
	 * Read access.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function can_view( Actor $actor, array $booking ): bool {
		return $this->is_owner( $actor, $booking ) || $this->is_assigned_staff( $actor, $booking ) || $this->is_manager( $actor );
	}

	/**
	 * Reschedule or cancel access.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function can_change( Actor $actor, array $booking ): bool {
		return $this->is_owner( $actor, $booking ) || $this->is_manager( $actor );
	}

	/**
	 * Job status access.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function can_work( Actor $actor, array $booking ): bool {
		return $this->is_assigned_staff( $actor, $booking ) || $this->is_manager( $actor );
	}
}
