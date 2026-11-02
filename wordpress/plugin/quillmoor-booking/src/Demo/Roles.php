<?php
/**
 * Roles.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Security\BookingPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Least-privilege roles. Hosts manage their own listings and bookings, the manager manages all of
 * them, and the demo admin is a manager, never an administrator.
 */
final class Roles {

	public const CUSTOMER = 'qhs_customer';
	public const HOST     = 'qhs_host';
	public const STAFF    = 'qhs_staff';
	public const MANAGER  = 'qhs_manager';

	/**
	 * Create or refresh the roles.
	 */
	public static function sync(): void {
		$host_caps = array_fill_keys( PostTypes::HOST_CAPS, true ) + array(
			'read'                  => true,
			'upload_files'          => true,
			BookingPolicy::CAP_HOST => true,
		);

		$definitions = array(
			self::CUSTOMER => array(
				'label' => 'Guest',
				'caps'  => array( 'read' => true ),
			),
			self::HOST     => array(
				'label' => 'Host',
				'caps'  => $host_caps,
			),
			self::STAFF    => array(
				'label' => 'Turnover team',
				'caps'  => array(
					'read'                  => true,
					BookingPolicy::CAP_WORK => true,
				),
			),
			self::MANAGER  => array(
				'label' => 'Booking manager (demo admin)',
				'caps'  => $host_caps + array_fill_keys( PostTypes::MANAGER_CAPS, true ) + array( BookingPolicy::CAP_MANAGE => true ),
			),
		);
		foreach ( $definitions as $slug => $definition ) {
			remove_role( $slug );
			add_role( $slug, $definition['label'], $definition['caps'] );
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			foreach ( array_merge( PostTypes::HOST_CAPS, PostTypes::MANAGER_CAPS, array( BookingPolicy::CAP_MANAGE, BookingPolicy::CAP_HOST ) ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}
}
