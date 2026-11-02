<?php
/**
 * Host and manager form handlers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Admin;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Security\Actor;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Every handler checks the nonce, then the object-level permission, then calls the service that
 * owns the change. Messages come back through a short-lived notice, never through the URL.
 */
final class Actions {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_post_qhs_booking', array( self::class, 'booking' ) );
		add_action( 'admin_post_qhs_calendar', array( self::class, 'calendar' ) );
	}

	/**
	 * A booking action: accept, decline, check in, complete, no-show, cancel, refund, note, triage.
	 */
	public static function booking(): void {
		$id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		check_admin_referer( 'qhs_booking_' . $id );
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$user    = get_current_user_id();
		$actor   = new Actor( $user );
		$booking = Services::bookings()->find( $id );
		if ( null === $booking || ! Services::policy()->can_manage( $actor, $booking ) ) {
			wp_die( esc_html__( 'Booking not found.', 'quillmoor-booking' ), '', array( 'response' => 404 ) );
		}

		$label   = 'user:' . $user;
		$service = Services::service();
		try {
			switch ( $do ) {
				case 'confirm':
					$service->confirm( $id, $label );
					Ui::flash( __( 'Request accepted. The guest has been told.', 'quillmoor-booking' ) );
					break;
				case 'decline':
					$service->decline( $id, $label );
					Ui::flash( __( 'Request declined. The nights or seats are free again.', 'quillmoor-booking' ) );
					break;
				case 'arrive':
					$service->arrive( $id, $label );
					Ui::flash( __( 'Arrival recorded.', 'quillmoor-booking' ) );
					break;
				case 'complete':
					$service->complete( $id, $label );
					Ui::flash( __( 'Marked as completed.', 'quillmoor-booking' ) );
					break;
				case 'no_show':
					$service->no_show( $id, $label );
					Ui::flash( __( 'No-show recorded. The nights or seats are free again.', 'quillmoor-booking' ) );
					break;
				case 'cancel':
					$service->cancel( $id, $label, false );
					Ui::flash( __( 'Booking cancelled. The deposit is flagged for refund.', 'quillmoor-booking' ) );
					break;
				case 'refund':
					Services::refunds()->refund( $id, $label );
					Ui::flash( __( 'Deposit refunded in test mode.', 'quillmoor-booking' ) );
					break;
				case 'note':
					$service->add_note( $id, $user, sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ) );
					Ui::flash( __( 'Note added.', 'quillmoor-booking' ) );
					break;
				case 'triage_accept':
				case 'triage_dismiss':
					self::triage( $id, 'triage_accept' === $do ? 'accepted' : 'dismissed', $user );
					Ui::flash( __( 'Suggestion reviewed.', 'quillmoor-booking' ) );
					break;
				default:
					Ui::flash( __( 'Unknown action.', 'quillmoor-booking' ), 'error' );
			}
		} catch ( BookingError $e ) {
			Ui::flash( $e->getMessage(), 'error' );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=qhs-bookings&booking=' . $id ) );
		exit;
	}

	/**
	 * Review an AI suggestion. Only this human step changes the priority.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $decision   Either "accepted" or "dismissed".
	 * @param int    $user_id    Reviewer.
	 */
	private static function triage( int $booking_id, string $decision, int $user_id ): void {
		global $wpdb;
		$pending = AdminPages::pending_triage( $booking_id );
		if ( null === $pending ) {
			return;
		}
		$wpdb->update(
			Db::table( 'triage' ),
			array(
				'status'      => $decision,
				'reviewed_by' => $user_id,
			),
			array( 'id' => (int) $pending['id'] )
		);
		if ( 'accepted' === $decision ) {
			Services::bookings()->update( $booking_id, array( 'priority' => (string) $pending['suggested_priority'] ) );
		}
	}

	/**
	 * Block or unblock nights, close or reopen days.
	 */
	public static function calendar(): void {
		$listing_id = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
		check_admin_referer( 'qhs_calendar_' . $listing_id );
		$user = get_current_user_id();
		if ( ! BookingPolicy::can_manage_listing( $user, $listing_id ) ) {
			wp_die( esc_html__( 'Listing not found.', 'quillmoor-booking' ), '', array( 'response' => 404 ) );
		}
		$listing = Listing::find( $listing_id );
		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$month   = isset( $_POST['month'] ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : '';
		$raw     = isset( $_POST['dates'] ) && is_array( $_POST['dates'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['dates'] ) ) : array();
		$today   = gmdate( 'Y-m-d' );
		$dates   = array_values( array_unique( array_filter( $raw, static fn( string $d ): bool => Listing::is_date( $d ) && $d >= $today ) ) );
		sort( $dates );

		if ( null === $listing || array() === $dates ) {
			Ui::flash( __( 'Select at least one day first.', 'quillmoor-booking' ), 'error' );
		} elseif ( ListingType::Stay === $listing->type && 'block' === $do ) {
			$refused = Services::nights()->block( $listing_id, $dates );
			Ui::flash(
				array() === $refused
					? sprintf( /* translators: %d: nights */ _n( '%d night blocked.', '%d nights blocked.', count( $dates ), 'quillmoor-booking' ), count( $dates ) )
					: sprintf( /* translators: %s: dates */ __( 'Some nights are booked or on hold and were left as they are: %s', 'quillmoor-booking' ), implode( ', ', $refused ) ),
				array() === $refused ? 'success' : 'error'
			);
		} elseif ( ListingType::Stay === $listing->type && 'unblock' === $do ) {
			Services::nights()->unblock( $listing_id, $dates );
			Ui::flash( __( 'Nights unblocked.', 'quillmoor-booking' ) );
		} elseif ( ListingType::Dining === $listing->type && 'close' === $do ) {
			$refused = array();
			foreach ( $dates as $day ) {
				if ( ! Services::tables()->close_day( $listing_id, $day, $user ) ) {
					$refused[] = $day;
				}
			}
			Ui::flash(
				array() === $refused ? __( 'Days closed.', 'quillmoor-booking' ) : sprintf( /* translators: %s: dates */ __( 'Days with bookings stay open: %s', 'quillmoor-booking' ), implode( ', ', $refused ) ),
				array() === $refused ? 'success' : 'error'
			);
		} elseif ( ListingType::Dining === $listing->type && 'open' === $do ) {
			foreach ( $dates as $day ) {
				Services::tables()->open_day( $listing_id, $day );
			}
			Ui::flash( __( 'Days reopened.', 'quillmoor-booking' ) );
		} else {
			Ui::flash( __( 'Unknown action.', 'quillmoor-booking' ), 'error' );
		}

		$month = 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : gmdate( 'Y-m' );
		wp_safe_redirect( admin_url( 'admin.php?page=qhs-calendar&listing=' . $listing_id . '&month=' . $month ) );
		exit;
	}
}
