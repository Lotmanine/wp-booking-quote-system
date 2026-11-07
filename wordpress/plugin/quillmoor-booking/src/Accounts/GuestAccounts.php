<?php
/**
 * Accounts for guests who paid a deposit.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Accounts;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Demo\DemoGuard;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Domain\BookingRepository;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Security\Counters;
use Quillmoor\Booking\Services;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A guest books and pays without an account. Once the deposit is confirmed, whatever the gateway,
 * the booking's address gets an account: a new one with the guest role and a password the guest
 * chooses from an emailed link, or the existing one when the address already has an account.
 *
 * Accounts are never made at quote or hold, never signed in automatically, and capped per day.
 * Self-registration stays closed: only this path creates guest accounts.
 */
final class GuestAccounts {

	/**
	 * User meta that marks an account made by this path, so the nightly reset can remove it.
	 */
	public const META = 'qhs_guest_account';

	private const CAP_KEY = 'accounts:daily';

	/**
	 * Build the service.
	 *
	 * @param BookingRepository $bookings Bookings.
	 * @param Counters          $counters Counters for the daily cap.
	 * @param MailService       $mail     Mail.
	 */
	public function __construct( private BookingRepository $bookings, private Counters $counters, private MailService $mail ) {
	}

	/**
	 * State machine hook. Only the move into deposit paid counts as payment confirmation.
	 *
	 * @param int          $booking_id Booking id.
	 * @param BookingState $from       Previous state.
	 * @param BookingState $to         New state.
	 */
	public static function on_transition( int $booking_id, BookingState $from, BookingState $to ): void {
		if ( BookingState::DepositPaid !== $to ) {
			return;
		}
		Services::accounts()->after_payment( $booking_id );
	}

	/**
	 * Give a paid booking's address an account. Call only once the deposit is confirmed.
	 *
	 * @param int $booking_id Booking id.
	 * @return string Outcome: created, linked, already_linked, skipped, capped or failed.
	 */
	public function after_payment( int $booking_id ): string {
		$booking  = $this->bookings->find( $booking_id );
		$customer = null === $booking ? null : $this->bookings->customer( (int) $booking['customer_id'] );
		if ( null === $booking || null === $customer ) {
			return 'failed';
		}
		// Booked while signed in, or linked by an earlier booking: the booking already shows in the account.
		if ( (int) $customer['user_id'] > 0 ) {
			return 'already_linked';
		}

		$email    = (string) $customer['email'];
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			return $this->link_existing( $existing, $customer, $booking );
		}

		if ( ! $this->counters->take( self::CAP_KEY, DAY_IN_SECONDS, Config::accounts_daily_cap() ) ) {
			Alerts::log( 'accounts', 'The daily limit on new guest accounts was reached. Booking ' . $booking_id . ' is confirmed without an account; the guest keeps the booking link.' );
			return 'capped';
		}

		$login   = 'guest-' . bin2hex( random_bytes( 6 ) );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				// Long and random, never shown: the guest sets their own from the emailed link.
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => (string) $customer['full_name'],
				'nickname'     => (string) $customer['full_name'],
				'role'         => Roles::CUSTOMER,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$this->counters->give_back( self::CAP_KEY, DAY_IN_SECONDS );
			return 'failed';
		}
		update_user_meta( $user_id, self::META, '1' );
		$this->bookings->update_customer( (int) $customer['id'], array( 'user_id' => $user_id ) );

		$user = get_userdata( $user_id );
		$key  = $user instanceof WP_User ? get_password_reset_key( $user ) : null;
		if ( is_string( $key ) ) {
			$url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $login ), 'login' );
			$this->mail->send_account_setup( $customer, $booking, $url );
		}
		return 'created';
	}

	/**
	 * Link a booking to the account that already uses its address, and say where to find it.
	 *
	 * @param WP_User             $user     Existing account.
	 * @param array<string,mixed> $customer Customer row.
	 * @param array<string,mixed> $booking  Booking row.
	 */
	private function link_existing( WP_User $user, array $customer, array $booking ): string {
		// A typed address never attaches a booking to a shared demo login or a staff, host or admin account.
		if ( ! self::is_plain_guest( $user ) ) {
			return 'skipped';
		}
		// The account already has its own customer record, under an address it used before.
		if ( null !== $this->bookings->customer_by_user( $user->ID ) ) {
			return 'skipped';
		}
		$this->bookings->update_customer( (int) $customer['id'], array( 'user_id' => $user->ID ) );
		$this->mail->send_account_signin( $customer, $booking );
		return 'linked';
	}

	/**
	 * Whether an account is an ordinary guest account: the guest role only, and not a demo login.
	 *
	 * @param WP_User $user Account.
	 */
	public static function is_plain_guest( WP_User $user ): bool {
		return array( Roles::CUSTOMER ) === array_values( $user->roles ) && ! DemoGuard::is_demo_user( $user->ID );
	}

	/**
	 * Remove every account this path made, with what WordPress keeps for it. Used by the nightly reset.
	 *
	 * @return int Accounts removed.
	 */
	public static function delete_all(): int {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$ids     = get_users(
			array(
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Nightly reset only.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Nightly reset only.
				'fields'     => 'ID',
				'number'     => -1,
			)
		);
		$removed = 0;
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			// Belt and braces: never remove a demo login or an account that gained another role.
			if ( ! $user instanceof WP_User || ! self::is_plain_guest( $user ) ) {
				continue;
			}
			if ( wp_delete_user( $user->ID ) ) {
				++$removed;
			}
		}
		return $removed;
	}
}
