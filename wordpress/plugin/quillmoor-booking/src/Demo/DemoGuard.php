<?php
/**
 * Restrictions on the public demo accounts.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shared logins must stay usable: no password or email changes, and no screens beyond their own.
 */
final class DemoGuard {

	public const META = 'qhs_demo_account';

	private const ALLOWED_SCREENS = array( 'admin.php', 'admin-post.php', 'admin-ajax.php' );

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'deny_self_edit' ), 10, 3 );
		add_filter( 'allow_password_reset', array( self::class, 'allow_password_reset' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'allow_app_passwords' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( self::class, 'refuse_profile_update' ), 10, 3 );
		add_action( 'woocommerce_save_account_details_errors', array( self::class, 'refuse_account_update' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'limit_admin_screens' ) );
		add_filter( 'show_admin_bar', array( self::class, 'show_admin_bar' ) );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_manager_admin' ) );
	}

	/**
	 * Whether a user is a shared demo account.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_demo_user( int $user_id ): bool {
		return $user_id > 0 && '1' === get_user_meta( $user_id, self::META, true );
	}

	/**
	 * WordPress lets users edit themselves by default. Demo accounts cannot, on any route.
	 *
	 * @param string[] $caps    Required caps.
	 * @param string   $cap     Requested cap.
	 * @param int      $user_id User id.
	 * @return string[]
	 */
	public static function deny_self_edit( array $caps, string $cap, int $user_id ): array {
		if ( in_array( $cap, array( 'edit_user', 'manage_woocommerce' ), true ) && self::is_demo_user( $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * No password reset for demo accounts.
	 *
	 * @param bool $allow   Current value.
	 * @param int  $user_id User id.
	 */
	public static function allow_password_reset( bool $allow, int $user_id ): bool {
		return self::is_demo_user( $user_id ) ? false : $allow;
	}

	/**
	 * No application passwords for demo accounts.
	 *
	 * @param bool    $available Current value.
	 * @param WP_User $user      User.
	 */
	public static function allow_app_passwords( bool $available, WP_User $user ): bool {
		return self::is_demo_user( $user->ID ) ? false : $available;
	}

	/**
	 * Refuse a profile save.
	 *
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Whether this is an update.
	 * @param object   $user   User data.
	 */
	public static function refuse_profile_update( WP_Error $errors, bool $update, object $user ): void {
		if ( $update && isset( $user->ID ) && self::is_demo_user( (int) $user->ID ) ) {
			$errors->add( 'qhs_demo_account', __( 'Demo accounts cannot be changed.', 'quillmoor-booking' ) );
		}
	}

	/**
	 * Refuse a WooCommerce account save.
	 *
	 * @param WP_Error $errors Errors.
	 * @param object   $user   User data.
	 */
	public static function refuse_account_update( WP_Error $errors, object $user ): void {
		if ( isset( $user->ID ) && self::is_demo_user( (int) $user->ID ) ) {
			$errors->add( 'qhs_demo_account', __( 'Demo accounts cannot be changed.', 'quillmoor-booking' ) );
		}
	}

	/**
	 * Keep demo users on the booking screens.
	 */
	public static function limit_admin_screens(): void {
		$user_id = get_current_user_id();
		if ( ! self::is_demo_user( $user_id ) || wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$own  = in_array( $page, array( 'qhs-calendar', 'qhs-bookings', 'qhs-reports', 'qhs-triage' ), true );
		if ( ! in_array( $pagenow, self::ALLOWED_SCREENS, true ) || ( 'admin.php' === $pagenow && ! $own ) ) {
			$user = wp_get_current_user();
			wp_safe_redirect( in_array( Roles::MANAGER, $user->roles, true ) ? admin_url( 'admin.php?page=qhs-calendar' ) : home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Demo customers and staff stay on the front end, without the toolbar.
	 *
	 * @param bool $show Current value.
	 */
	public static function show_admin_bar( bool $show ): bool {
		return self::is_demo_user( get_current_user_id() ) ? false : $show;
	}

	/**
	 * Send each role to its own area.
	 *
	 * @param string           $redirect  Default target.
	 * @param string           $requested Requested target.
	 * @param WP_User|WP_Error $user      User.
	 */
	public static function login_redirect( string $redirect, string $requested, mixed $user ): string {
		if ( ! $user instanceof WP_User ) {
			return $redirect;
		}
		if ( in_array( Roles::MANAGER, $user->roles, true ) ) {
			return admin_url( 'admin.php?page=qhs-calendar' );
		}
		if ( in_array( Roles::STAFF, $user->roles, true ) ) {
			return home_url( '/staff/' );
		}
		if ( in_array( Roles::CUSTOMER, $user->roles, true ) ) {
			return home_url( '/portal/' );
		}
		return $redirect;
	}

	/**
	 * WooCommerce pushes users without shop caps out of wp-admin; managers need their screens.
	 *
	 * @param bool $prevent Current value.
	 */
	public static function allow_manager_admin( bool $prevent ): bool {
		$user = wp_get_current_user();
		return in_array( Roles::MANAGER, $user->roles, true ) ? false : $prevent;
	}
}
