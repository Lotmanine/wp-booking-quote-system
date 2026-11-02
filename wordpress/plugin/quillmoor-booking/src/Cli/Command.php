<?php
/**
 * WP-CLI commands.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Cli;

use Quillmoor\Booking\Demo\DemoReset;
use Quillmoor\Booking\Setup;
use Quillmoor\Booking\Services;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Operate the booking demo.
 */
final class Command {

	/**
	 * Create pages, the deposit product, roles and WooCommerce settings.
	 */
	public function setup(): void {
		Setup::run();
		WP_CLI::success( 'Setup complete.' );
	}

	/**
	 * Reset demo data. Refuses on anything but the demo site.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param string[] $args       Positional.
	 * @param string[] $assoc_args Flags.
	 */
	public function reset( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Delete all demo bookings?', $assoc_args );
		try {
			DemoReset::run();
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( 'Demo data reset.' );
	}

	/**
	 * Release expired holds now.
	 *
	 * @subcommand expire-holds
	 */
	public function expire_holds(): void {
		WP_CLI::success( Services::service()->release_expired_holds() . ' holds released.' );
	}

	/**
	 * Deliver queued events now.
	 *
	 * @subcommand deliver-outbox
	 */
	public function deliver_outbox(): void {
		WP_CLI::success( Services::outbox()->deliver_due() . ' events delivered.' );
	}
}
