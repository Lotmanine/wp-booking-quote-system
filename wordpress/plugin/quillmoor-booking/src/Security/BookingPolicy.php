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
	public const CAP_HOST   = 'qhs_host_bookings';
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
	 * Whether the actor hosts the listing of the booking.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function is_host( Actor $actor, array $booking ): bool {
		return $actor->user_id > 0
			&& (int) $booking['host_user_id'] === $actor->user_id
			&& user_can( $actor->user_id, self::CAP_HOST );
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
		return $this->is_owner( $actor, $booking ) || $this->is_host( $actor, $booking ) || $this->is_assigned_staff( $actor, $booking ) || $this->is_manager( $actor );
	}

	/**
	 * Guest-side change or cancel access.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function can_change( Actor $actor, array $booking ): bool {
		return $this->is_owner( $actor, $booking ) || $this->is_manager( $actor );
	}

	/**
	 * Host-side access: confirm, decline, check in, cancel, refund and notes.
	 *
	 * @param Actor               $actor   Requester.
	 * @param array<string,mixed> $booking Booking row.
	 */
	public function can_manage( Actor $actor, array $booking ): bool {
		return $this->is_host( $actor, $booking ) || $this->is_manager( $actor );
	}

	/**
	 * Whether a user may manage a listing's calendar and bookings.
	 *
	 * @param int $user_id    User id.
	 * @param int $listing_id Listing id.
	 */
	public static function can_manage_listing( int $user_id, int $listing_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		if ( user_can( $user_id, self::CAP_MANAGE ) ) {
			return true;
		}
		return user_can( $user_id, self::CAP_HOST ) && (int) get_post_field( 'post_author', $listing_id ) === $user_id;
	}
}
