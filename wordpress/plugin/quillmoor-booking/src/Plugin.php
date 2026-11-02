<?php
/**
 * Bootstrap.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use Quillmoor\Booking\Admin\Actions;
use Quillmoor\Booking\Admin\AdminPages;
use Quillmoor\Booking\Admin\ListingEditor;
use Quillmoor\Booking\Cli\Command;
use Quillmoor\Booking\Demo\Banner;
use Quillmoor\Booking\Demo\DemoGuard;
use Quillmoor\Booking\Demo\DemoReset;
use Quillmoor\Booking\Listings\Fields;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Payment\WooHooks;
use Quillmoor\Booking\Rest\Routes;
use Quillmoor\Booking\Security\StripeKeyGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Wires hooks. Each callback stays a one-liner that resolves a service.
 */
final class Plugin {

	public const EXPIRE_HOOK = 'qhs_expire_holds';
	public const PURGE_HOOK  = 'qhs_purge';
	public const RESET_HOOK  = 'qhs_nightly_reset';

	/**
	 * Register everything.
	 */
	public static function boot(): void {
		Schema::maybe_upgrade();

		StripeKeyGuard::register();
		Banner::register();
		DemoGuard::register();
		PostTypes::register();
		Fields::register();
		Routes::register();
		AdminPages::register();
		Actions::register();
		ListingEditor::register();
		Blocks::register();

		if ( class_exists( 'WooCommerce' ) ) {
			WooHooks::register();
		}

		// Core and WooCommerce mail would skip Brevo, the daily cap and the verified-address rule.
		add_filter( 'pre_wp_mail', '__return_false' );

		add_action( self::EXPIRE_HOOK, array( self::class, 'expire_holds' ) );
		add_action( self::PURGE_HOOK, array( self::class, 'purge' ) );
		add_action( self::RESET_HOOK, array( DemoReset::class, 'run_scheduled' ) );
		add_action( Outbox::HOOK, array( self::class, 'deliver_outbox' ) );
		add_action( 'init', array( self::class, 'schedule' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'qhs', Command::class );
		}
	}

	/**
	 * Keep recurring jobs scheduled.
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::EXPIRE_HOOK ) ) {
			as_schedule_recurring_action( time() + 60, MINUTE_IN_SECONDS, self::EXPIRE_HOOK, array(), 'qhs' );
		}
		if ( ! as_has_scheduled_action( self::PURGE_HOOK ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::PURGE_HOOK, array(), 'qhs' );
		}
		if ( Config::is_demo_site() && ! as_has_scheduled_action( self::RESET_HOOK ) ) {
			as_schedule_recurring_action( (int) strtotime( 'tomorrow 03:00 UTC' ), DAY_IN_SECONDS, self::RESET_HOOK, array(), 'qhs' );
		}
	}

	/**
	 * Release expired holds and decline requests the host left unanswered.
	 */
	public static function expire_holds(): void {
		Services::service()->release_expired_holds();
		Services::service()->expire_requests();
	}

	/**
	 * Deliver queued events.
	 */
	public static function deliver_outbox(): void {
		Services::outbox()->deliver_due();
	}

	/**
	 * Remove spent nonces and old counters.
	 */
	public static function purge(): void {
		Services::signer()->purge();
		Services::counters()->purge();
	}
}
