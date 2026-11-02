<?php
/**
 * REST route table.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * All routes in one place, so the security review reads top to bottom.
 */
final class Routes {

	public const NS = 'qhs/v1';

	/**
	 * Register on rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Define every route.
	 */
	public static function routes(): void {
		$open = '__return_true';

		// Public. Each write verifies Turnstile and a per-IP limit inside the handler.
		self::add( '/catalog', WP_REST_Server::READABLE, array( PublicApi::class, 'catalog' ), $open );
		self::add( '/availability', WP_REST_Server::READABLE, array( PublicApi::class, 'availability' ), $open );
		self::add( '/quote', WP_REST_Server::CREATABLE, array( PublicApi::class, 'quote' ), $open );
		self::add( '/hold', WP_REST_Server::CREATABLE, array( PublicApi::class, 'hold' ), $open );
		self::add( '/verify-email', WP_REST_Server::CREATABLE, array( PublicApi::class, 'verify_email' ), $open );

		// Portal. Object-level checks run through BookingPolicy inside each handler.
		self::add( '/bookings', WP_REST_Server::READABLE, array( PortalApi::class, 'list_mine' ), $open );
		self::add( '/bookings/(?P<id>\d+)', WP_REST_Server::READABLE, array( PortalApi::class, 'show' ), $open );
		self::add( '/bookings/(?P<id>\d+)/reschedule', WP_REST_Server::CREATABLE, array( PortalApi::class, 'reschedule' ), $open );
		self::add( '/bookings/(?P<id>\d+)/cancel', WP_REST_Server::CREATABLE, array( PortalApi::class, 'cancel' ), $open );
		self::add( '/bookings/(?P<id>\d+)/pdf/(?P<kind>quote|confirmation)', WP_REST_Server::READABLE, array( PortalApi::class, 'pdf' ), $open );
		self::add( '/consent', WP_REST_Server::CREATABLE, array( PortalApi::class, 'consent' ), $open );

		// Staff.
		$staff = array( StaffApi::class, 'can_work' );
		self::add( '/staff/schedule', WP_REST_Server::READABLE, array( StaffApi::class, 'schedule' ), $staff );
		self::add( '/staff/jobs/(?P<id>\d+)/status', WP_REST_Server::CREATABLE, array( StaffApi::class, 'status' ), $staff );
		self::add( '/staff/jobs/(?P<id>\d+)/triage', WP_REST_Server::CREATABLE, array( StaffApi::class, 'triage' ), $staff );

		// Machine to machine. The permission callback verifies the signed envelope.
		$n8n      = array( MachineApi::class, 'verify_n8n' );
		$dispatch = array( MachineApi::class, 'verify_dispatch' );
		self::add( '/dispatch/decision', WP_REST_Server::CREATABLE, array( MachineApi::class, 'decision' ), $dispatch );
		self::add( '/n8n/crm-sync', WP_REST_Server::CREATABLE, array( MachineApi::class, 'crm_sync' ), $n8n );
		self::add( '/n8n/reminders', WP_REST_Server::CREATABLE, array( MachineApi::class, 'reminders' ), $n8n );
		self::add( '/n8n/followups', WP_REST_Server::CREATABLE, array( MachineApi::class, 'followups' ), $n8n );
		self::add( '/n8n/triage', WP_REST_Server::CREATABLE, array( MachineApi::class, 'triage' ), $n8n );
		self::add( '/n8n/alert', WP_REST_Server::CREATABLE, array( MachineApi::class, 'alert' ), $n8n );
	}

	/**
	 * Add one route.
	 *
	 * @param string   $path       Route path.
	 * @param string   $methods    HTTP methods.
	 * @param callable $callback   Handler.
	 * @param callable $permission Permission callback.
	 */
	private static function add( string $path, string $methods, callable $callback, callable $permission ): void {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission,
			)
		);
	}
}
