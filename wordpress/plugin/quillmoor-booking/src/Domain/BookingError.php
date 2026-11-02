<?php
/**
 * Expected booking failures.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries an API error code and HTTP status.
 */
final class BookingError extends RuntimeException {

	/**
	 * Build the error.
	 *
	 * @param string $error_code Stable API code.
	 * @param string $error_text Message safe to show a visitor.
	 * @param int    $status     HTTP status.
	 */
	public function __construct( public readonly string $error_code, string $error_text, public readonly int $status = 409 ) {
		parent::__construct( $error_text );
	}
}
