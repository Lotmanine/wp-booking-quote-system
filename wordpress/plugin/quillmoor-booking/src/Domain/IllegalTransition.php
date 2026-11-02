<?php
/**
 * Illegal state change.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown before any write when a transition is not in the table.
 */
final class IllegalTransition extends RuntimeException {
}
