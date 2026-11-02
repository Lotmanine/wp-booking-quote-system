<?php
/**
 * Admin calendar and reports.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Admin;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Rest\StaffApi;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Screens for users with the manage capability.
 */
final class AdminPages {

	public const SLUG = 'qhs-calendar';

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_qhs_triage', array( self::class, 'handle_triage' ) );
	}

	/**
	 * Add menu pages.
	 */
	public static function menu(): void {
		$cap = BookingPolicy::CAP_MANAGE;
		add_menu_page( 'Bookings', 'Bookings', $cap, self::SLUG, array( self::class, 'calendar' ), 'dashicons-calendar-alt', 3 );
		add_submenu_page( self::SLUG, 'Calendar', 'Calendar', $cap, self::SLUG, array( self::class, 'calendar' ) );
		add_submenu_page( self::SLUG, 'Reports', 'Reports', $cap, 'qhs-reports', array( self::class, 'reports' ) );
		add_submenu_page( self::SLUG, 'Triage', 'Triage', $cap, 'qhs-triage', array( self::class, 'triage' ) );
	}

	/**
	 * Month calendar.
	 */
	public static function calendar(): void {
		self::require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only month selector.
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : gmdate( 'Y-m' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = gmdate( 'Y-m' );
		}

		$first = (int) strtotime( $month . '-01 00:00:00 UTC' );
		$next  = (int) strtotime( '+1 month', $first );
		$prev  = (int) strtotime( '-1 month', $first );
		$rows  = Services::bookings()->in_range( gmdate( 'Y-m-d H:i:s', $first ), gmdate( 'Y-m-d H:i:s', $next ) );

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ substr( (string) $row['slot_start'], 0, 10 ) ][] = $row;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Booking calendar', 'quillmoor-booking' ) . '</h1>';
		printf(
			'<p><a class="button" href="%s">%s</a> <strong style="margin:0 12px">%s</strong> <a class="button" href="%s">%s</a></p>',
			esc_url( add_query_arg( 'month', gmdate( 'Y-m', $prev ) ) ),
			esc_html__( 'Previous', 'quillmoor-booking' ),
			esc_html( gmdate( 'F Y', $first ) ),
			esc_url( add_query_arg( 'month', gmdate( 'Y-m', $next ) ) ),
			esc_html__( 'Next', 'quillmoor-booking' )
		);

		echo '<table class="widefat fixed striped"><thead><tr>';
		foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $day ) {
			echo '<th>' . esc_html( $day ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';

		$lead = (int) gmdate( 'N', $first ) - 1;
		echo str_repeat( '<td></td>', $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		$days = (int) gmdate( 't', $first );
		for ( $day = 1; $day <= $days; $day++ ) {
			$date = sprintf( '%s-%02d', $month, $day );
			echo '<td style="vertical-align:top;height:90px"><strong>' . esc_html( (string) $day ) . '</strong>';
			foreach ( $by_day[ $date ] ?? array() as $row ) {
				printf(
					'<div style="font-size:12px">%s QHS-%d %s</div>',
					esc_html( substr( (string) $row['slot_start'], 11, 5 ) ),
					(int) $row['id'],
					esc_html( BookingState::from( (string) $row['state'] )->label() )
				);
			}
			echo '</td>';
			if ( 0 === ( $lead + $day ) % 7 && $day < $days ) {
				echo '</tr><tr>';
			}
		}
		echo '</tr></tbody></table></div>';
	}

	/**
	 * Reporting screen.
	 */
	public static function reports(): void {
		global $wpdb;
		self::require_cap();

		$states = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT state, COUNT(*) AS total FROM %i GROUP BY state', Db::table( 'bookings' ) ),
			ARRAY_A
		);
		$money  = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.service, COUNT(*) AS jobs, SUM(q.total_cents) AS total, SUM(q.deposit_cents) AS deposits
				FROM %i b JOIN %i q ON q.id = b.quote_id
				WHERE b.state IN (%s, %s, %s, %s) GROUP BY b.service',
				Db::table( 'bookings' ),
				Db::table( 'quotes' ),
				BookingState::DepositPaid->value,
				BookingState::Confirmed->value,
				BookingState::Assigned->value,
				BookingState::Completed->value
			),
			ARRAY_A
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Reports', 'quillmoor-booking' ) . '</h1>';
		echo '<p>' . esc_html__( 'All figures are fictional demo data.', 'quillmoor-booking' ) . '</p>';

		echo '<h2>' . esc_html__( 'Bookings by state', 'quillmoor-booking' ) . '</h2><table class="widefat striped" style="max-width:420px"><tbody>';
		foreach ( $states as $row ) {
			printf(
				'<tr><td>%s</td><td>%d</td></tr>',
				esc_html( BookingState::from( (string) $row['state'] )->label() ),
				(int) $row['total']
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Secured work by service', 'quillmoor-booking' ) . '</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>Service</th><th>Jobs</th><th>Fictional value</th><th>Fictional deposits</th></tr></thead><tbody>';
		foreach ( $money as $row ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( Catalog::label( 'services', (string) $row['service'] ) ),
				(int) $row['jobs'],
				esc_html( Catalog::money( (int) $row['total'] ) ),
				esc_html( Catalog::money( (int) $row['deposits'] ) )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Operations', 'quillmoor-booking' ) . '</h2><table class="widefat striped" style="max-width:420px"><tbody>';
		printf( '<tr><td>Emails today</td><td>%d of %d</td></tr>', (int) Services::mail()->used_today(), (int) Config::mail_daily_cap() );
		printf( '<tr><td>Events waiting for n8n</td><td>%d</td></tr>', (int) Services::outbox()->pending_count() );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Recent alerts', 'quillmoor-booking' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( Alerts::latest( 20 ) as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( (string) $row['created_at'] ),
				esc_html( (string) $row['source'] ),
				esc_html( (string) $row['message'] )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Pending AI suggestions.
	 */
	public static function triage(): void {
		global $wpdb;
		self::require_cap();

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.id, t.booking_id, t.suggested_priority, t.reason, t.source, b.customer_note
				FROM %i t JOIN %i b ON b.id = t.booking_id WHERE t.status = %s ORDER BY t.id DESC LIMIT 50',
				Db::table( 'triage' ),
				Db::table( 'bookings' ),
				'pending'
			),
			ARRAY_A
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Triage suggestions', 'quillmoor-booking' ) . '</h1>';
		echo '<p>' . esc_html__( 'Suggestions change nothing until a person accepts them.', 'quillmoor-booking' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Booking</th><th>Note</th><th>Suggested</th><th>Reason</th><th>Source</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>QHS-' . (int) $row['booking_id'] . '</td><td>' . esc_html( (string) $row['customer_note'] ) . '</td><td>'
				. esc_html( (string) $row['suggested_priority'] ) . '</td><td>' . esc_html( (string) $row['reason'] ) . '</td><td>' . esc_html( (string) $row['source'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'qhs_triage_' . (int) $row['id'] );
			echo '<input type="hidden" name="action" value="qhs_triage"><input type="hidden" name="triage_id" value="' . (int) $row['id'] . '">';
			echo '<button class="button button-primary" name="decision" value="accepted">' . esc_html__( 'Accept', 'quillmoor-booking' ) . '</button> ';
			echo '<button class="button" name="decision" value="dismissed">' . esc_html__( 'Dismiss', 'quillmoor-booking' ) . '</button>';
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Handle a triage form.
	 */
	public static function handle_triage(): void {
		global $wpdb;
		self::require_cap();

		$id = isset( $_POST['triage_id'] ) ? absint( $_POST['triage_id'] ) : 0;
		check_admin_referer( 'qhs_triage_' . $id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT booking_id, suggested_priority FROM %i WHERE id = %d AND status = %s', Db::table( 'triage' ), $id, 'pending' ),
			ARRAY_A
		);
		if ( is_array( $row ) && in_array( $decision, array( 'accepted', 'dismissed' ), true ) ) {
			StaffApi::review( $id, (int) $row['booking_id'], $decision, (string) $row['suggested_priority'], get_current_user_id() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=qhs-triage' ) );
		exit;
	}

	/**
	 * Stop unless the user manages bookings.
	 */
	private static function require_cap(): void {
		if ( ! current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You cannot view this page.', 'quillmoor-booking' ), '', array( 'response' => 403 ) );
		}
	}
}
