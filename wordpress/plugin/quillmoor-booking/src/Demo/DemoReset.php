<?php
/**
 * Nightly demo reset.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Payment\WooHooks;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Restores the demo. It runs only on a site that says it is the demo, on the demo host.
 */
final class DemoReset {

	public const CUSTOMER_LOGIN = 'demo-customer';
	public const STAFF_LOGIN    = 'demo-staff';
	public const MANAGER_LOGIN  = 'demo-admin';

	/**
	 * Staff roster, matching the dispatch service seed.
	 */
	private const STAFF = array(
		array(
			'login' => self::STAFF_LOGIN,
			'name'  => 'Rowan Vale',
			'ref'   => 'rowan',
		),
		array(
			'login' => 'staff-sam',
			'name'  => 'Sam Ellery',
			'ref'   => 'sam',
		),
		array(
			'login' => 'staff-jordan',
			'name'  => 'Jordan Pike',
			'ref'   => 'jordan',
		),
	);

	/**
	 * Tables that hold only demo activity. Counters stay, so a reset cannot refill the email cap.
	 */
	private const TABLES = array( 'slot_claims', 'booking_history', 'outbox', 'triage', 'mail_log', 'alerts', 'bookings', 'quotes', 'customers' );

	/**
	 * Whether a reset may run here.
	 */
	public static function is_allowed(): bool {
		if ( ! Config::is_demo_site() ) {
			return false;
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $host && Config::get( 'QHS_DEMO_HOST' ) === $host;
	}

	/**
	 * Scheduled entry point.
	 */
	public static function run_scheduled(): void {
		if ( self::is_allowed() ) {
			self::run();
		}
	}

	/**
	 * Wipe demo activity and restore accounts.
	 *
	 * @throws RuntimeException When this is not the demo site.
	 */
	public static function run(): void {
		global $wpdb;
		if ( ! self::is_allowed() ) {
			throw new RuntimeException( 'Refusing to reset: this is not the configured demo site.' );
		}

		self::delete_orders();
		self::delete_crm_contacts();
		foreach ( self::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Db::table( $table ) ) );
		}
		self::ensure_accounts();
		self::reset_dispatch();
	}

	/**
	 * Clear the dispatch service too. It keeps its own assignments, so without this staff would
	 * look busy with jobs that no longer exist.
	 */
	private static function reset_dispatch(): void {
		$url = Config::get( 'QHS_DISPATCH_INTERNAL_URL' );
		if ( '' === $url ) {
			return;
		}
		$response = wp_remote_request(
			$url . '/api/v1/assignments',
			array(
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => array(
					'X-Api-Key' => Config::get( 'DISPATCH_API_KEY' ),
					'Accept'    => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Alerts::log( 'reset', 'The dispatch service was not reset. Staff may show as busy.' );
		}
	}

	/**
	 * Create or restore demo accounts and their customer record.
	 */
	public static function ensure_accounts(): void {
		$password = Config::get( 'QHS_DEMO_PASSWORD' );

		$customer_id = self::ensure_user( self::CUSTOMER_LOGIN, 'Avery Demo', 'demo-customer@example.com', Roles::CUSTOMER, $password );
		$manager_id  = self::ensure_user( self::MANAGER_LOGIN, 'Demo Manager', 'demo-admin@example.com', Roles::MANAGER, $password );
		update_user_meta( $customer_id, DemoGuard::META, '1' );
		update_user_meta( $manager_id, DemoGuard::META, '1' );

		foreach ( self::STAFF as $staff ) {
			// Only the public staff login uses the shared password.
			$pass = self::STAFF_LOGIN === $staff['login'] ? $password : wp_generate_password( 32 );
			$id   = self::ensure_user( $staff['login'], $staff['name'], $staff['login'] . '@example.com', Roles::STAFF, $pass );
			update_user_meta( $id, DemoGuard::META, '1' );
			update_user_meta( $id, 'qhs_staff_ref', $staff['ref'] );
		}

		global $wpdb;
		$wpdb->insert(
			Db::table( 'customers' ),
			array(
				'user_id'           => $customer_id,
				'email'             => 'demo-customer@example.com',
				'full_name'         => 'Avery Demo',
				'email_verified_at' => Db::now(),
				'created_at'        => Db::now(),
			)
		);
	}

	/**
	 * Create a user or reset an existing one.
	 *
	 * @param string $login    Login.
	 * @param string $name     Display name.
	 * @param string $email    Address.
	 * @param string $role     Role.
	 * @param string $password Password.
	 */
	private static function ensure_user( string $login, string $name, string $email, string $role, string $password ): int {
		$user = get_user_by( 'login', $login );
		$data = array(
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => $name,
			'first_name'   => strtok( $name, ' ' ),
			'role'         => $role,
			'user_pass'    => $password,
		);
		if ( $user ) {
			$data['ID'] = $user->ID;
			remove_all_filters( 'send_password_change_email' );
			add_filter( 'send_password_change_email', '__return_false' );
			add_filter( 'send_email_change_email', '__return_false' );
			wp_update_user( $data );
			return $user->ID;
		}
		return (int) wp_insert_user( $data );
	}

	/**
	 * Delete orders that belong to bookings. Other orders are left alone.
	 */
	private static function delete_orders(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$product_id = (int) get_option( WooHooks::PRODUCT_OPTION );
		do {
			$orders = wc_get_orders(
				array(
					'limit'  => 100,
					'status' => 'any',
				)
			);
			$hit    = 0;
			foreach ( is_array( $orders ) ? $orders : array() as $order ) {
				foreach ( $order->get_items() as $item ) {
					if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() === $product_id ) {
						$order->delete( true );
						++$hit;
						break;
					}
				}
			}
		} while ( $hit > 0 );
	}

	/**
	 * Delete CRM contacts tagged as demo.
	 */
	private static function delete_crm_contacts(): void {
		if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return;
		}
		$ids = \FluentCrm\App\Models\Subscriber::filterByTags( array( self::demo_tag_id() ) )->pluck( 'id' )->toArray();
		if ( array() !== $ids ) {
			\FluentCrm\App\Services\Helper::deleteContacts( $ids );
		}
	}

	/**
	 * Id of the demo tag.
	 */
	private static function demo_tag_id(): int {
		$tag = \FluentCrm\App\Models\Tag::where( 'slug', 'qhs-demo' )->first();
		return $tag ? (int) $tag->id : 0;
	}
}
