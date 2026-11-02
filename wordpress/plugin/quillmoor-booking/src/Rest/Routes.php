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

		// Public reads.
		self::add( '/listings', WP_REST_Server::READABLE, array( PublicApi::class, 'listings' ), $open );
		self::add( '/listings/(?P<id>\d+)/calendar', WP_REST_Server::READABLE, array( PublicApi::class, 'calendar' ), $open );
		self::add( '/listings/(?P<id>\d+)/slots', WP_REST_Server::READABLE, array( PublicApi::class, 'slots' ), $open );
		self::add( '/listings/(?P<id>\d+)/price', WP_REST_Server::CREATABLE, array( PublicApi::class, 'price' ), $open );

		// Public writes. Each verifies a per-IP limit and, for visitors who are not signed in, Turnstile.
		self::add( '/quote', WP_REST_Server::CREATABLE, array( PublicApi::class, 'quote' ), $open );
		self::add( '/hold', WP_REST_Server::CREATABLE, array( PublicApi::class, 'hold' ), $open );
		self::add( '/verify-email', WP_REST_Server::CREATABLE, array( PublicApi::class, 'verify_email' ), $open );

		// Trips. Object-level checks run through BookingPolicy inside each handler.
		self::add( '/bookings', WP_REST_Server::READABLE, array( PortalApi::class, 'list_mine' ), $open );
		self::add( '/bookings/(?P<id>\d+)', WP_REST_Server::READABLE, array( PortalApi::class, 'show' ), $open );
		self::add( '/bookings/(?P<id>\d+)/change', WP_REST_Server::CREATABLE, array( PortalApi::class, 'change' ), $open );
		self::add( '/bookings/(?P<id>\d+)/cancel', WP_REST_Server::CREATABLE, array( PortalApi::class, 'cancel' ), $open );
		self::add( '/bookings/(?P<id>\d+)/pdf/(?P<kind>quote|confirmation)', WP_REST_Server::READABLE, array( PortalApi::class, 'pdf' ), $open );
		self::add( '/consent', WP_REST_Server::CREATABLE, array( PortalApi::class, 'consent' ), $open );

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
