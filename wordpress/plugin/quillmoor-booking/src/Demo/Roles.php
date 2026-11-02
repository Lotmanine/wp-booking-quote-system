<?php
/**
 * Demo roles.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

use Quillmoor\Booking\Security\BookingPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Least-privilege roles. The demo admin is a manager, never an administrator.
 */
final class Roles {

	public const CUSTOMER = 'qhs_customer';
	public const STAFF    = 'qhs_staff';
	public const MANAGER  = 'qhs_manager';

	/**
	 * Create or refresh the roles.
	 */
	public static function sync(): void {
		$definitions = array(
			self::CUSTOMER => array(
				'label' => 'Booking customer',
				'caps'  => array( 'read' => true ),
			),
			self::STAFF    => array(
				'label' => 'Booking staff',
				'caps'  => array(
					'read'                  => true,
					BookingPolicy::CAP_WORK => true,
				),
			),
			self::MANAGER  => array(
				'label' => 'Booking manager (demo admin)',
				'caps'  => array(
					'read'                    => true,
					BookingPolicy::CAP_MANAGE => true,
				),
			),
		);
		foreach ( $definitions as $slug => $definition ) {
			remove_role( $slug );
			add_role( $slug, $definition['label'], $definition['caps'] );
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			$admin->add_cap( BookingPolicy::CAP_MANAGE );
		}
	}
}
