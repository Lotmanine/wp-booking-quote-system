<?php
/**
 * Admin calendar, bookings list, triage and reports.
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

	private const PER_PAGE = 20;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_qhs_triage', array( self::class, 'handle_triage' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Add menu pages.
	 */
	public static function menu(): void {
		$cap = BookingPolicy::CAP_MANAGE;
		add_menu_page( 'Bookings', 'Bookings', $cap, self::SLUG, array( self::class, 'calendar' ), 'dashicons-calendar-alt', 3 );
		add_submenu_page( self::SLUG, 'Calendar', 'Calendar', $cap, self::SLUG, array( self::class, 'calendar' ) );
		add_submenu_page( self::SLUG, 'All bookings', 'All bookings', $cap, 'qhs-bookings', array( self::class, 'bookings' ) );
		add_submenu_page( self::SLUG, 'Triage', 'Triage', $cap, 'qhs-triage', array( self::class, 'triage' ) );
		add_submenu_page( self::SLUG, 'Reports', 'Reports', $cap, 'qhs-reports', array( self::class, 'reports' ) );
	}

	/**
	 * Styles for the booking screens only.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'qhs-' ) ) {
			return;
		}
		wp_enqueue_style( 'qhs-admin', QUILLMOOR_PLUGIN_URL . 'assets/admin.css', array(), QUILLMOOR_VERSION );
		if ( function_exists( 'quillmoor_font_faces' ) ) {
			wp_add_inline_style( 'qhs-admin', quillmoor_font_faces() );
		}
	}

	/**
	 * Shared screen header with the section tabs.
	 *
	 * @param string $current Current page slug.
	 * @param string $title   Screen title.
	 * @param string $lead    Short description.
	 */
	private static function header( string $current, string $title, string $lead ): void {
		$tabs = array(
			self::SLUG     => __( 'Calendar', 'quillmoor-booking' ),
			'qhs-bookings' => __( 'All bookings', 'quillmoor-booking' ),
			'qhs-triage'   => __( 'Triage', 'quillmoor-booking' ),
			'qhs-reports'  => __( 'Reports', 'quillmoor-booking' ),
		);
		echo '<div class="wrap qhs-admin"><header class="qhs-admin__head"><div><p class="qhs-admin__eyebrow">' . esc_html__( 'Quillmoor bookings', 'quillmoor-booking' ) . '</p>';
		echo '<h1 class="qhs-admin__title">' . esc_html( $title ) . '</h1><p class="qhs-admin__lead">' . esc_html( $lead ) . '</p></div>';
		echo '<a class="qhs-admin__view" href="' . esc_url( home_url( '/services/' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View site', 'quillmoor-booking' ) . '</a></header>';
		echo '<nav class="qhs-admin__tabs" aria-label="' . esc_attr__( 'Booking screens', 'quillmoor-booking' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				$slug === $current ? 'is-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * KPI tiles.
	 *
	 * @param array<int,array{0:string,1:string,2?:string}> $tiles Label, value and optional note.
	 */
	private static function kpis( array $tiles ): void {
		echo '<div class="qhs-admin__kpis">';
		foreach ( $tiles as $tile ) {
			printf(
				'<div class="qhs-admin__kpi"><span class="qhs-admin__kpi-label">%s</span><strong>%s</strong>%s</div>',
				esc_html( $tile[0] ),
				esc_html( $tile[1] ),
				isset( $tile[2] ) ? '<span class="qhs-admin__kpi-note">' . esc_html( $tile[2] ) . '</span>' : ''
			);
		}
		echo '</div>';
	}

	/**
	 * State pill.
	 *
	 * @param string $state State value.
	 */
	private static function pill( string $state ): string {
		$known = BookingState::tryFrom( $state );
		return '<span class="qhs-admin-pill qhs-admin-pill--' . esc_attr( $state ) . '">' . esc_html( $known ? $known->label() : $state ) . '</span>';
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

		$first  = (int) strtotime( $month . '-01 00:00:00 UTC' );
		$next   = (int) strtotime( '+1 month', $first );
		$prev   = (int) strtotime( '-1 month', $first );
		$rows   = Services::bookings()->in_range( gmdate( 'Y-m-d H:i:s', $first ), gmdate( 'Y-m-d H:i:s', $next ) );
		$counts = Services::bookings()->state_counts();
		$week   = Services::bookings()->in_range( gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );

		$by_day = array();
		foreach ( $rows as $row ) {
			$by_day[ substr( (string) $row['slot_start'], 0, 10 ) ][] = $row;
		}

		self::header( self::SLUG, __( 'Booking calendar', 'quillmoor-booking' ), __( 'Every held, paid and assigned visit by day. Times are UTC.', 'quillmoor-booking' ) );
		self::kpis(
			array(
				array( __( 'Next 7 days', 'quillmoor-booking' ), (string) count( $week ), __( 'visits on the calendar', 'quillmoor-booking' ) ),
				array( __( 'Waiting for staff', 'quillmoor-booking' ), (string) ( ( $counts['confirmed'] ?? 0 ) + ( $counts['deposit_paid'] ?? 0 ) ), __( 'paid, not yet assigned', 'quillmoor-booking' ) ),
				array( __( 'Slots on hold', 'quillmoor-booking' ), (string) ( $counts['hold'] ?? 0 ), __( 'released after 10 minutes', 'quillmoor-booking' ) ),
				array( __( 'Completed', 'quillmoor-booking' ), (string) ( $counts['completed'] ?? 0 ), __( 'since the last reset', 'quillmoor-booking' ) ),
			)
		);

		echo '<section class="qhs-admin__card">';
		printf(
			'<div class="qhs-admin__calbar"><h2>%s</h2><div class="qhs-admin__calnav"><a class="button" href="%s">%s</a><a class="button" href="%s">%s</a><a class="button" href="%s">%s</a></div></div>',
			esc_html( gmdate( 'F Y', $first ) ),
			esc_url( add_query_arg( 'month', gmdate( 'Y-m', $prev ) ) ),
			esc_html__( 'Previous', 'quillmoor-booking' ),
			esc_url( remove_query_arg( 'month' ) ),
			esc_html__( 'Today', 'quillmoor-booking' ),
			esc_url( add_query_arg( 'month', gmdate( 'Y-m', $next ) ) ),
			esc_html__( 'Next', 'quillmoor-booking' )
		);

		echo '<div class="qhs-admin__cal" role="table" aria-label="' . esc_attr( gmdate( 'F Y', $first ) ) . '"><div class="qhs-admin__calrow qhs-admin__calrow--head" role="row">';
		foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $day ) {
			echo '<div role="columnheader">' . esc_html( $day ) . '</div>';
		}
		echo '</div><div class="qhs-admin__calgrid" role="rowgroup">';

		$lead  = (int) gmdate( 'N', $first ) - 1;
		$days  = (int) gmdate( 't', $first );
		$today = gmdate( 'Y-m-d' );
		echo str_repeat( '<div class="qhs-admin__cell is-pad" aria-hidden="true"></div>', $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		for ( $day = 1; $day <= $days; $day++ ) {
			$date  = sprintf( '%s-%02d', $month, $day );
			$items = $by_day[ $date ] ?? array();
			printf(
				'<div class="qhs-admin__cell%s%s" role="cell"><span class="qhs-admin__daynum">%d</span>',
				$date === $today ? ' is-today' : '',
				$items ? ' has-items' : '',
				(int) $day
			);
			foreach ( $items as $row ) {
				printf(
					'<span class="qhs-admin__event qhs-admin__event--%s" title="%s"><b>%s</b> QHS-%d <em>%s</em></span>',
					esc_attr( (string) $row['state'] ),
					esc_attr( Catalog::label( 'services', (string) $row['service'] ) . ', ' . Catalog::label( 'areas', (string) $row['area'] ) ),
					esc_html( substr( (string) $row['slot_start'], 11, 5 ) ),
					(int) $row['id'],
					esc_html( BookingState::from( (string) $row['state'] )->label() )
				);
			}
			echo '</div>';
		}
		$tail = ( 7 - ( ( $lead + $days ) % 7 ) ) % 7;
		echo str_repeat( '<div class="qhs-admin__cell is-pad" aria-hidden="true"></div>', $tail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup.
		echo '</div></div>';

		echo '<ul class="qhs-admin__legend">';
		foreach ( array( 'hold', 'deposit_paid', 'confirmed', 'assigned', 'completed' ) as $state ) {
			echo '<li>' . self::pill( $state ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pill().
		}
		echo '</ul></section></div>';
	}

	/**
	 * Every booking, filterable by state and searchable.
	 */
	public static function bookings(): void {
		self::require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		$term  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( null === BookingState::tryFrom( $state ) ) {
			$state = '';
		}

		$result = Services::bookings()->search( $state, $term, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$counts = Services::bookings()->state_counts();
		$base   = admin_url( 'admin.php?page=qhs-bookings' );

		self::header( 'qhs-bookings', __( 'All bookings', 'quillmoor-booking' ), __( 'Newest first. Search by reference such as QHS-12, by email or by name.', 'quillmoor-booking' ) );

		echo '<section class="qhs-admin__card"><div class="qhs-admin__filters"><ul class="qhs-admin__states">';
		printf( '<li><a href="%s" class="%s">%s <span>%d</span></a></li>', esc_url( $base ), '' === $state ? 'is-active' : '', esc_html__( 'All', 'quillmoor-booking' ), (int) array_sum( $counts ) );
		foreach ( BookingState::cases() as $case ) {
			printf(
				'<li><a href="%s" class="%s">%s <span>%d</span></a></li>',
				esc_url( add_query_arg( 'state', $case->value, $base ) ),
				$case->value === $state ? 'is-active' : '',
				esc_html( $case->label() ),
				(int) ( $counts[ $case->value ] ?? 0 )
			);
		}
		echo '</ul><form method="get" class="qhs-admin__search" role="search"><input type="hidden" name="page" value="qhs-bookings">';
		if ( '' !== $state ) {
			echo '<input type="hidden" name="state" value="' . esc_attr( $state ) . '">';
		}
		printf(
			'<label class="screen-reader-text" for="qhs-admin-s">%1$s</label><input id="qhs-admin-s" type="search" name="s" value="%2$s" placeholder="%1$s"><button class="button">%3$s</button></form></div>',
			esc_attr__( 'Search bookings', 'quillmoor-booking' ),
			esc_attr( $term ),
			esc_html__( 'Search', 'quillmoor-booking' )
		);

		echo '<div class="qhs-admin__tablewrap"><table class="qhs-admin__table"><thead><tr><th>Booking</th><th>Customer</th><th>Visit (UTC)</th><th>Staff</th><th>State</th><th class="r">Total</th></tr></thead><tbody>';
		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="6" class="qhs-admin__empty">' . esc_html__( 'No bookings match.', 'quillmoor-booking' ) . '</td></tr>';
		}
		foreach ( $result['rows'] as $row ) {
			$staff = (int) $row['staff_user_id'] > 0 ? get_userdata( (int) $row['staff_user_id'] ) : false;
			printf(
				'<tr><td><div class="qhs-admin__svc"><img src="%s" alt="" width="64" height="40"><div><strong>QHS-%d</strong><span>%s</span></div></div></td><td><strong>%s</strong><span class="qhs-admin__sub">%s</span></td><td>%s<span class="qhs-admin__sub">%s</span></td><td>%s</td><td>%s</td><td class="r">%s</td></tr>',
				esc_url( Catalog::image_url( (string) $row['service'] ) ),
				(int) $row['id'],
				esc_html( Catalog::label( 'services', (string) $row['service'] ) ),
				esc_html( (string) $row['full_name'] ),
				esc_html( (string) $row['email'] ),
				esc_html( $row['slot_start'] ? substr( (string) $row['slot_start'], 0, 16 ) : 'No slot' ),
				esc_html( Catalog::label( 'areas', (string) $row['area'] ) ),
				esc_html( $staff ? $staff->display_name : '' ),
				self::pill( (string) $row['state'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in pill().
				esc_html( Catalog::money( (int) $row['total_cents'] ) )
			);
		}
		echo '</tbody></table></div>';

		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="qhs-admin__pages" aria-label="' . esc_attr__( 'Pages', 'quillmoor-booking' ) . '">';
			for ( $page = 1; $page <= $pages; $page++ ) {
				printf(
					'<a href="%s" class="%s"%s>%d</a>',
					esc_url( add_query_arg( 'paged', $page ) ),
					$page === $paged ? 'is-active' : '',
					$page === $paged ? ' aria-current="page"' : '',
					(int) $page
				);
			}
			echo '</nav>';
		}
		echo '<p class="qhs-admin__note">' . esc_html__( 'All figures are fictional demo data.', 'quillmoor-booking' ) . '</p></section></div>';
	}

	/**
	 * Reporting screen.
	 */
	public static function reports(): void {
		global $wpdb;
		self::require_cap();

		$counts   = Services::bookings()->state_counts();
		$money    = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.service, COUNT(*) AS jobs, SUM(q.total_cents) AS total, SUM(q.deposit_cents) AS deposits
				FROM %i b JOIN %i q ON q.id = b.quote_id
				WHERE b.state IN (%s, %s, %s, %s) GROUP BY b.service ORDER BY total DESC',
				Db::table( 'bookings' ),
				Db::table( 'quotes' ),
				BookingState::DepositPaid->value,
				BookingState::Confirmed->value,
				BookingState::Assigned->value,
				BookingState::Completed->value
			),
			ARRAY_A
		);
		$value    = (int) array_sum( array_column( $money, 'total' ) );
		$deposits = (int) array_sum( array_column( $money, 'deposits' ) );

		self::header( 'qhs-reports', __( 'Reports', 'quillmoor-booking' ), __( 'Pipeline, secured work and operations. All figures are fictional demo data.', 'quillmoor-booking' ) );
		self::kpis(
			array(
				array( __( 'Bookings', 'quillmoor-booking' ), (string) array_sum( $counts ), __( 'every state', 'quillmoor-booking' ) ),
				array( __( 'Secured value', 'quillmoor-booking' ), Catalog::money( $value ), __( 'paid deposit or later, fictional', 'quillmoor-booking' ) ),
				array( __( 'Deposits', 'quillmoor-booking' ), Catalog::money( $deposits ), __( 'test mode, fictional', 'quillmoor-booking' ) ),
				array( __( 'Emails today', 'quillmoor-booking' ), Services::mail()->used_today() . ' / ' . Config::mail_daily_cap(), __( 'shared Brevo allowance', 'quillmoor-booking' ) ),
			)
		);

		$max = max( 1, (int) max( array_merge( array( 0 ), array_values( $counts ) ) ) );
		echo '<div class="qhs-admin__cols"><section class="qhs-admin__card"><h2>' . esc_html__( 'Bookings by state', 'quillmoor-booking' ) . '</h2><ul class="qhs-admin__bars">';
		foreach ( BookingState::cases() as $case ) {
			$count = (int) ( $counts[ $case->value ] ?? 0 );
			printf(
				'<li><span class="qhs-admin__bar-label">%s</span><span class="qhs-admin__bar"><span class="qhs-admin__bar-fill qhs-admin__bar-fill--%s" style="width:%d%%"></span></span><span class="qhs-admin__bar-n">%d</span></li>',
				esc_html( $case->label() ),
				esc_attr( $case->value ),
				(int) round( 100 * $count / $max ),
				(int) $count
			);
		}
		echo '</ul></section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Secured work by service', 'quillmoor-booking' ) . '</h2><table class="qhs-admin__table"><thead><tr><th>Service</th><th class="r">Jobs</th><th class="r">Fictional value</th><th class="r">Fictional deposits</th></tr></thead><tbody>';
		if ( ! $money ) {
			echo '<tr><td colspan="4" class="qhs-admin__empty">' . esc_html__( 'No paid bookings yet.', 'quillmoor-booking' ) . '</td></tr>';
		}
		foreach ( $money as $row ) {
			printf(
				'<tr><td>%s</td><td class="r">%d</td><td class="r">%s</td><td class="r">%s</td></tr>',
				esc_html( Catalog::label( 'services', (string) $row['service'] ) ),
				(int) $row['jobs'],
				esc_html( Catalog::money( (int) $row['total'] ) ),
				esc_html( Catalog::money( (int) $row['deposits'] ) )
			);
		}
		echo '</tbody></table></section></div>';

		echo '<div class="qhs-admin__cols"><section class="qhs-admin__card"><h2>' . esc_html__( 'Operations', 'quillmoor-booking' ) . '</h2><dl class="qhs-admin__dl">';
		printf( '<div><dt>%s</dt><dd>%d of %d</dd></div>', esc_html__( 'Emails today', 'quillmoor-booking' ), (int) Services::mail()->used_today(), (int) Config::mail_daily_cap() );
		printf( '<div><dt>%s</dt><dd>%d</dd></div>', esc_html__( 'Events waiting for n8n', 'quillmoor-booking' ), (int) Services::outbox()->pending_count() );
		echo '</dl></section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Recent alerts', 'quillmoor-booking' ) . '</h2>';
		$alerts = Alerts::latest( 20 );
		if ( ! $alerts ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'No alerts. Every pipeline step has answered.', 'quillmoor-booking' ) . '</p>';
		} else {
			echo '<ul class="qhs-admin__alerts">';
			foreach ( $alerts as $row ) {
				printf(
					'<li><span class="qhs-admin__sub">%s</span><strong>%s</strong><span>%s</span></li>',
					esc_html( (string) $row['created_at'] ),
					esc_html( (string) $row['source'] ),
					esc_html( (string) $row['message'] )
				);
			}
			echo '</ul>';
		}
		echo '</section></div></div>';
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

		self::header( 'qhs-triage', __( 'Triage suggestions', 'quillmoor-booking' ), __( 'Suggestions change nothing until a person accepts them.', 'quillmoor-booking' ) );
		echo '<section class="qhs-admin__card">';
		if ( ! $rows ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'Nothing to review. New customer notes appear here with a suggested priority.', 'quillmoor-booking' ) . '</p>';
		}
		echo '<div class="qhs-admin__triage">';
		foreach ( $rows as $row ) {
			echo '<article class="qhs-admin__tcard"><header><strong>QHS-' . (int) $row['booking_id'] . '</strong>';
			echo '<span class="qhs-admin-pill qhs-admin-pill--prio-' . esc_attr( (string) $row['suggested_priority'] ) . '">' . esc_html( (string) $row['suggested_priority'] ) . '</span>';
			echo '<span class="qhs-admin__sub">' . esc_html( 'ai' === $row['source'] ? 'AI suggestion' : 'Automatic default' ) . '</span></header>';
			echo '<blockquote>' . esc_html( (string) $row['customer_note'] ) . '</blockquote><p>' . esc_html( (string) $row['reason'] ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'qhs_triage_' . (int) $row['id'] );
			echo '<input type="hidden" name="action" value="qhs_triage"><input type="hidden" name="triage_id" value="' . (int) $row['id'] . '">';
			echo '<button class="button button-primary" name="decision" value="accepted">' . esc_html__( 'Accept', 'quillmoor-booking' ) . '</button> ';
			echo '<button class="button" name="decision" value="dismissed">' . esc_html__( 'Dismiss', 'quillmoor-booking' ) . '</button>';
			echo '</form></article>';
		}
		echo '</div></section></div>';
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
