<?php
/**
 * Request identity.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Who is asking: a signed-in user, a guest holding a booking token, or nobody.
 */
final class Actor {

	/**
	 * Build an actor.
	 *
	 * @param int    $user_id WordPress user id, 0 for guests.
	 * @param string $token   Guest booking token, empty when absent.
	 */
	public function __construct( public readonly int $user_id, public readonly string $token = '' ) {
	}

	/**
	 * Label for the history table.
	 */
	public function label(): string {
		return $this->user_id > 0 ? 'user:' . $this->user_id : 'guest';
	}
}
