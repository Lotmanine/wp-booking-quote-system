<?php
/**
 * Host and manager screens: dashboard, bookings, calendar and activity.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Admin;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Payment\Refunds;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Hosts see only their own listings and bookings. The manager sees every host.
 */
final class AdminPages {

	public const SLUG = 'qhs-dashboard';

	private const PER_PAGE = 20;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Add menu pages.
	 */
	public static function menu(): void {
		$cap = BookingPolicy::CAP_HOST;
		add_menu_page( 'Quillmoor', 'Quillmoor', $cap, self::SLUG, array( self::class, 'dashboard' ), 'dashicons-palmtree', 3 );
		add_submenu_page( self::SLUG, 'Dashboard', 'Dashboard', $cap, self::SLUG, array( self::class, 'dashboard' ) );
		add_submenu_page( self::SLUG, 'Bookings', 'Bookings', $cap, 'qhs-bookings', array( self::class, 'bookings' ) );
		add_submenu_page( self::SLUG, 'Calendar', 'Calendar', $cap, 'qhs-calendar', array( self::class, 'calendar' ) );
		add_submenu_page( self::SLUG, 'Locations', 'Locations', BookingPolicy::CAP_MANAGE, 'edit-tags.php?taxonomy=' . PostTypes::LOCATION . '&post_type=' . PostTypes::LISTING );
		add_submenu_page( self::SLUG, 'Categories', 'Categories', BookingPolicy::CAP_MANAGE, 'edit-tags.php?taxonomy=' . PostTypes::CATEGORY . '&post_type=' . PostTypes::LISTING );
		add_submenu_page( self::SLUG, 'Amenities', 'Amenities', BookingPolicy::CAP_MANAGE, 'edit-tags.php?taxonomy=' . PostTypes::AMENITY . '&post_type=' . PostTypes::LISTING );
		add_submenu_page( self::SLUG, 'Activity', 'Activity', BookingPolicy::CAP_MANAGE, 'qhs-activity', array( self::class, 'activity' ) );
	}

	/**
	 * Styles and scripts for the booking screens and the listing editor.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function assets( string $hook ): void {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$listing = null !== $screen && PostTypes::LISTING === $screen->post_type;
		if ( ! str_contains( $hook, 'qhs-' ) && ! str_contains( $hook, 'quillmoor' ) && ! $listing ) {
			return;
		}
		wp_enqueue_style( 'qhs-admin', QUILLMOOR_PLUGIN_URL . 'assets/admin.css', array(), QUILLMOOR_VERSION );
		if ( function_exists( 'quillmoor_font_faces' ) ) {
			wp_add_inline_style( 'qhs-admin', quillmoor_font_faces() );
		}
		wp_enqueue_style( 'leaflet', QUILLMOOR_PLUGIN_URL . 'assets/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', QUILLMOOR_PLUGIN_URL . 'assets/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'qhs-admin', QUILLMOOR_PLUGIN_URL . 'assets/admin.js', array( 'leaflet' ), QUILLMOOR_VERSION, true );
		wp_add_inline_script(
			'qhs-admin',
			'window.qhsAdmin = ' . wp_json_encode(
				array(
					'rest'  => esc_url_raw( rest_url( 'qhs/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'tiles' => Blocks::TILE_URL,
					'attr'  => Blocks::TILE_ATTRIBUTION,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Dashboard: what needs an answer, who arrives next and how the listings are doing.
	 */
	public static function dashboard(): void {
		self::require_host();
		$host     = Ui::host_scope();
		$repo     = Services::bookings();
		$counts   = $repo->state_counts( $host );
		$today    = gmdate( 'Y-m-d' );
		$week     = gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
		$requests = $repo->search(
			array(
				'state'   => BookingState::Requested->value,
				'host_id' => $host,
			),
			10,
			0
		)['rows'];
		$arrivals = $repo->search(
			array(
				'state'   => BookingState::Confirmed->value,
				'host_id' => $host,
				'from'    => $today,
				'to'      => $week,
			),
			10,
			0
		);
		$listings = Ui::my_listings();
		$live     = count( array_filter( $listings, static fn( Listing $l ): bool => $l->is_published() ) );
		$holds    = (int) ( $counts[ BookingState::Hold->value ] ?? 0 );

		Ui::header( self::SLUG, __( 'Dashboard', 'quillmoor-booking' ), __( 'Requests waiting for an answer, the week ahead and how your listings are set up.', 'quillmoor-booking' ) );

		$tiles = array(
			array( __( 'Requests to answer', 'quillmoor-booking' ), (string) count( $requests ), __( 'answer within 24 hours', 'quillmoor-booking' ), 'kpi-requests' ),
			array( __( 'Arrivals in 7 days', 'quillmoor-booking' ), (string) $arrivals['total'], __( 'stays and tables', 'quillmoor-booking' ), 'kpi-arrivals' ),
			array( __( 'On hold now', 'quillmoor-booking' ), (string) $holds, __( 'waiting for payment', 'quillmoor-booking' ), 'kpi-holds' ),
			array( __( 'Confirmed', 'quillmoor-booking' ), (string) (int) ( $counts[ BookingState::Confirmed->value ] ?? 0 ), __( 'all dates', 'quillmoor-booking' ), 'kpi-confirmed' ),
			array( __( 'Deposits taken', 'quillmoor-booking' ), Pricing::money( $repo->deposits_cents( $host ) ), __( 'test mode, fictional', 'quillmoor-booking' ), 'kpi-deposits' ),
			array( __( 'Listings live', 'quillmoor-booking' ), $live . ' / ' . count( $listings ), __( 'published of total', 'quillmoor-booking' ), 'kpi-listings' ),
		);
		echo '<div class="qhs-admin__kpis">';
		foreach ( $tiles as $tile ) {
			printf( '<div class="qhs-admin__kpi" data-testid="%4$s"><span>%1$s</span><strong>%2$s</strong><em>%3$s</em></div>', esc_html( $tile[0] ), esc_html( $tile[1] ), esc_html( $tile[2] ), esc_attr( $tile[3] ) );
		}
		echo '</div>';

		echo '<div class="qhs-admin__cols">';
		echo '<section class="qhs-admin__card" data-testid="requests"><h2>' . esc_html__( 'Needs your answer', 'quillmoor-booking' ) . '</h2>';
		if ( array() === $requests ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'No requests waiting. Listings without instant book send each paid reservation here first.', 'quillmoor-booking' ) . '</p>';
		}
		foreach ( $requests as $row ) {
			echo '<article class="qhs-admin__req"><div><a href="' . esc_url( Ui::booking_url( $row ) ) . '"><strong>' . esc_html( (string) $row['full_name'] ) . '</strong></a>';
			echo '<span>' . esc_html( (string) $row['listing_title'] ) . '</span><span class="qhs-admin__sub">' . esc_html( BookingView::when( $row ) ) . '</span></div><div class="qhs-admin__acts">';
			echo Ui::action_button( (int) $row['id'], 'confirm', __( 'Accept', 'quillmoor-booking' ), 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			echo Ui::action_button( (int) $row['id'], 'decline', __( 'Decline', 'quillmoor-booking' ), 'secondary', __( 'Decline this request? The guest is told and the deposit is flagged for refund.', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			echo '</div></article>';
		}
		echo '</section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Arriving in the next 7 days', 'quillmoor-booking' ) . '</h2>';
		self::booking_table( $arrivals['rows'], __( 'Nobody arrives this week.', 'quillmoor-booking' ) );
		echo '</section></div>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Your listings', 'quillmoor-booking' ) . '</h2><div class="qhs-admin__lgrid">';
		foreach ( $listings as $listing ) {
			$photo = $listing->photo_list( 'medium_large' );
			echo '<article class="qhs-admin__lcard">';
			if ( isset( $photo[0] ) ) {
				echo '<img src="' . esc_url( $photo[0]['url'] ) . '" alt="" width="320" height="200" loading="lazy">';
			}
			echo '<div><strong>' . esc_html( $listing->title ) . '</strong><span class="qhs-admin__sub">' . esc_html( $listing->kind_label() . ', ' . ( $listing->location()['name'] ?? '' ) ) . '</span>';
			echo '<span class="qhs-admin__sub">' . esc_html( $listing->is_published() ? __( 'Live', 'quillmoor-booking' ) : __( 'Draft', 'quillmoor-booking' ) ) . ' &middot; ' . esc_html( $listing->instant_book ? __( 'Instant book', 'quillmoor-booking' ) : __( 'Request to book', 'quillmoor-booking' ) ) . '</span>';
			echo '<p class="qhs-admin__links"><a href="' . esc_url( (string) get_edit_post_link( $listing->id ) ) . '">' . esc_html__( 'Edit', 'quillmoor-booking' ) . '</a> <a href="' . esc_url( admin_url( 'admin.php?page=qhs-calendar&listing=' . $listing->id ) ) . '">' . esc_html__( 'Calendar', 'quillmoor-booking' ) . '</a> <a href="' . esc_url( $listing->url() ) . '">' . esc_html__( 'View', 'quillmoor-booking' ) . '</a></p></div></article>';
		}
		if ( array() === $listings ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'No listings yet. Add a place to stay or a place to eat.', 'quillmoor-booking' ) . '</p>';
		}
		echo '</div></section>';

		if ( current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			self::system_card();
		}
		Ui::footer();
	}

	/**
	 * What is set up and what is not, for the manager.
	 */
	private static function system_card(): void {
		$rows = array(
			array( __( 'Payments', 'quillmoor-booking' ), Blocks::payments_label() ),
			array( __( 'Guest security check', 'quillmoor-booking' ), '' !== Config::get( 'TURNSTILE_SECRET_KEY' ) ? __( 'Turnstile on', 'quillmoor-booking' ) : __( 'Not set up: only signed-in accounts can reserve', 'quillmoor-booking' ) ),
			array( __( 'Email', 'quillmoor-booking' ), '' !== Config::get( 'BREVO_API_KEY' ) ? sprintf( /* translators: 1: sent today, 2: cap */ __( 'Brevo, %1$d of %2$d today', 'quillmoor-booking' ), Services::mail()->used_today(), Config::mail_daily_cap() ) : __( 'Not set up: messages are written to the mail log only', 'quillmoor-booking' ) ),
			array( __( 'Events waiting for n8n', 'quillmoor-booking' ), (string) Services::outbox()->pending_count() ),
		);
		echo '<section class="qhs-admin__card" data-testid="system"><h2>' . esc_html__( 'Set-up status', 'quillmoor-booking' ) . '</h2><dl class="qhs-admin__dl">';
		foreach ( $rows as $row ) {
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $row[0] ), esc_html( $row[1] ) );
		}
		echo '</dl></section>';
	}

	/**
	 * Bookings list, or one booking when ?booking= is set.
	 */
	public static function bookings(): void {
		self::require_host();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$id = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		if ( $id > 0 ) {
			self::booking( $id );
			return;
		}
		$filters = array(
			'state'      => isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '',
			'term'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'type'       => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
			'listing_id' => isset( $_GET['listing'] ) ? absint( $_GET['listing'] ) : 0,
			'from'       => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			'to'         => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '',
			'host_id'    => Ui::host_scope(),
		);
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$states = array( 'upcoming' => __( 'Upcoming', 'quillmoor-booking' ) );
		foreach ( BookingState::cases() as $state ) {
			if ( BookingState::Quote !== $state ) {
				$states[ $state->value ] = $state->label();
			}
		}
		if ( ! isset( $states[ $filters['state'] ] ) ) {
			$filters['state'] = '';
		}
		$result = Services::bookings()->search( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		Ui::header( 'qhs-bookings', __( 'Bookings', 'quillmoor-booking' ), __( 'Search by reference such as QM-12, by guest or by listing. Filter by state, type, listing and arrival dates.', 'quillmoor-booking' ) );
		echo '<form class="qhs-admin__filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="qhs-bookings">';
		printf( '<label><span>%s</span><input type="search" name="s" value="%s" data-testid="bookings-search"></label>', esc_html__( 'Search', 'quillmoor-booking' ), esc_attr( $filters['term'] ) );
		echo '<label><span>' . esc_html__( 'State', 'quillmoor-booking' ) . '</span><select name="state" data-testid="bookings-state"><option value="">' . esc_html__( 'Any state', 'quillmoor-booking' ) . '</option>';
		foreach ( $states as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $filters['state'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label><label><span>' . esc_html__( 'Type', 'quillmoor-booking' ) . '</span><select name="type"><option value="">' . esc_html__( 'Stays and tables', 'quillmoor-booking' ) . '</option>';
		printf( '<option value="stay"%s>%s</option><option value="dining"%s>%s</option></select></label>', selected( $filters['type'], 'stay', false ), esc_html__( 'Stays', 'quillmoor-booking' ), selected( $filters['type'], 'dining', false ), esc_html__( 'Tables', 'quillmoor-booking' ) );
		echo '<label><span>' . esc_html__( 'Listing', 'quillmoor-booking' ) . '</span><select name="listing"><option value="0">' . esc_html__( 'All listings', 'quillmoor-booking' ) . '</option>';
		foreach ( Ui::my_listings() as $listing ) {
			printf( '<option value="%d"%s>%s</option>', (int) $listing->id, selected( $filters['listing_id'], $listing->id, false ), esc_html( $listing->title ) );
		}
		echo '</select></label>';
		printf( '<label><span>%s</span><input type="date" name="from" value="%s"></label>', esc_html__( 'Arriving from', 'quillmoor-booking' ), esc_attr( $filters['from'] ) );
		printf( '<label><span>%s</span><input type="date" name="to" value="%s"></label>', esc_html__( 'to', 'quillmoor-booking' ), esc_attr( $filters['to'] ) );
		echo '<button class="qhs-admin__btn" type="submit">' . esc_html__( 'Filter', 'quillmoor-booking' ) . '</button></form>';

		echo '<section class="qhs-admin__card"><p class="qhs-admin__sub" data-testid="bookings-total">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d booking', '%d bookings', $result['total'], 'quillmoor-booking' ), $result['total'] ) ) . '</p>';
		self::booking_table( $result['rows'], __( 'No bookings match these filters.', 'quillmoor-booking' ) );
		$pages = (int) ceil( $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<nav class="qhs-admin__pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				printf( '<a href="%s" class="%s">%d</a>', esc_url( add_query_arg( 'paged', $i ) ), $i === $page ? 'is-active' : '', (int) $i );
			}
			echo '</nav>';
		}
		echo '</section>';
		Ui::footer();
	}

	/**
	 * Table of bookings.
	 *
	 * @param array<int,array<string,mixed>> $rows  Rows from the repository.
	 * @param string                         $empty_text Empty text.
	 */
	private static function booking_table( array $rows, string $empty_text ): void {
		if ( array() === $rows ) {
			echo '<p class="qhs-admin__empty">' . esc_html( $empty_text ) . '</p>';
			return;
		}
		echo '<div class="qhs-admin__scroll"><table class="qhs-admin__table" data-testid="bookings-table"><thead><tr><th>' . esc_html__( 'Booking', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Guest', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'When', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'State', 'quillmoor-booking' ) . '</th><th class="r">' . esc_html__( 'Total', 'quillmoor-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr data-testid="booking-row" data-booking="%1$d"><td><a href="%2$s"><strong>%3$s</strong></a><span class="qhs-admin__sub">%4$s</span></td><td>%5$s<span class="qhs-admin__sub">%6$s</span></td><td>%7$s</td><td>%8$s</td><td class="r">%9$s</td></tr>',
				(int) $row['id'],
				esc_url( Ui::booking_url( $row ) ),
				esc_html( BookingView::ref( $row ) ),
				esc_html( (string) ( $row['listing_title'] ?? '' ) ),
				esc_html( (string) $row['full_name'] ),
				esc_html( (string) $row['email'] ),
				esc_html( BookingView::when( $row ) ),
				Ui::badge( $row ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
				esc_html( Pricing::money( (int) ( $row['total_cents'] ?? 0 ) ) )
			);
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One booking: details, money, actions, notes and history.
	 *
	 * @param int $id Booking id.
	 */
	private static function booking( int $id ): void {
		$booking = Services::bookings()->find( $id );
		$actor   = new \Quillmoor\Booking\Security\Actor( get_current_user_id() );
		if ( null === $booking || ! Services::policy()->can_manage( $actor, $booking ) ) {
			wp_die( esc_html__( 'Booking not found.', 'quillmoor-booking' ), '', array( 'response' => 404 ) );
		}
		$quote    = Services::bookings()->quote( (int) $booking['quote_id'] );
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		$listing  = Listing::find( (int) $booking['listing_id'] );
		$state    = BookingState::from( (string) $booking['state'] );
		$dining   = BookingView::is_dining( $booking );
		$lines    = json_decode( (string) ( $quote['line_items'] ?? '[]' ), true );
		$start    = (int) strtotime( (string) $booking['slot_start'] . ' UTC' );

		Ui::header( 'qhs-bookings', BookingView::ref( $booking ) . ', ' . BookingView::listing_title( $booking ), BookingView::when( $booking ) );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=qhs-bookings' ) ) . '">' . esc_html__( 'All bookings', 'quillmoor-booking' ) . '</a></p>';

		echo '<div class="qhs-admin__cols qhs-admin__cols--wide"><div>';
		echo '<section class="qhs-admin__card" data-testid="booking-detail"><div class="qhs-admin__row"><h2>' . esc_html__( 'Reservation', 'quillmoor-booking' ) . '</h2>' . Ui::badge( $booking ) . '</div><dl class="qhs-admin__dl">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
		$facts = array(
			__( 'Guest', 'quillmoor-booking' )      => (string) ( $customer['full_name'] ?? '' ),
			__( 'Email', 'quillmoor-booking' )      => (string) ( $customer['email'] ?? '' ),
			__( 'Listing', 'quillmoor-booking' )    => BookingView::listing_title( $booking ),
			__( 'When', 'quillmoor-booking' )       => BookingView::when( $booking ) . ( $dining ? ' UTC' : '' ),
			__( 'Guests', 'quillmoor-booking' )     => (string) (int) $booking['guests'],
			__( 'Guest note', 'quillmoor-booking' ) => '' === (string) $booking['customer_note'] ? __( 'None', 'quillmoor-booking' ) : (string) $booking['customer_note'],
		);
		if ( (int) $booking['staff_user_id'] > 0 ) {
			$staff = get_userdata( (int) $booking['staff_user_id'] );
			$facts[ __( 'Turnover team', 'quillmoor-booking' ) ] = $staff ? $staff->display_name : '';
		}
		foreach ( $facts as $label => $value ) {
			printf( '<div><dt>%s</dt><dd>%s</dd></div>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</dl></section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Price and payment', 'quillmoor-booking' ) . '</h2><table class="qhs-admin__table"><tbody>';
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			printf( '<tr><td>%s</td><td class="r">%s</td></tr>', esc_html( (string) $line['label'] ), esc_html( Pricing::money( (int) $line['cents'] ) ) );
		}
		printf( '<tr class="t"><td>%s</td><td class="r">%s</td></tr>', esc_html__( 'Total', 'quillmoor-booking' ), esc_html( Pricing::money( (int) ( $quote['total_cents'] ?? 0 ) ) ) );
		printf( '<tr><td>%s</td><td class="r">%s</td></tr>', esc_html__( 'Deposit', 'quillmoor-booking' ), esc_html( Pricing::money( (int) ( $quote['deposit_cents'] ?? 0 ) ) ) );
		echo '</tbody></table><p class="qhs-admin__sub" data-testid="payment-status">';
		if ( (int) $booking['order_id'] > 0 ) {
			echo esc_html( sprintf( /* translators: %d: order number */ __( 'Deposit paid in test mode, order %d.', 'quillmoor-booking' ), (int) $booking['order_id'] ) );
		} elseif ( ( $state->is_live() && BookingState::Hold !== $state ) || in_array( $state, array( BookingState::Completed, BookingState::NoShow ), true ) ) {
			echo esc_html__( 'No payment attached: demo seed data.', 'quillmoor-booking' );
		} else {
			echo esc_html__( 'No payment taken.', 'quillmoor-booking' );
		}
		$refund = BookingView::refund_label( $booking );
		if ( '' !== $refund ) {
			echo ' <strong data-testid="refund-status">' . esc_html( $refund ) . '</strong>';
		}
		echo '</p>';
		if ( null !== $listing ) {
			echo '<p class="qhs-admin__sub">' . esc_html( $listing->cancellation_text() ) . '</p>';
		}
		echo '</section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'History', 'quillmoor-booking' ) . '</h2><ol class="qhs-admin__timeline" data-testid="history">';
		foreach ( Services::bookings()->history( $id ) as $row ) {
			$label = $row['from_state'] === $row['to_state'] ? (string) $row['reason'] : BookingState::from( (string) $row['to_state'] )->label( (string) $booking['listing_type'] ) . ( '' !== (string) $row['reason'] ? ': ' . $row['reason'] : '' );
			printf( '<li><time>%s UTC</time><strong>%s</strong><span>%s</span></li>', esc_html( (string) $row['created_at'] ), esc_html( $label ), esc_html( self::actor_name( (string) $row['actor'] ) ) );
		}
		echo '</ol></section></div><div>';

		echo '<section class="qhs-admin__card" data-testid="actions"><h2>' . esc_html__( 'Actions', 'quillmoor-booking' ) . '</h2><div class="qhs-admin__acts qhs-admin__acts--stack">';
		$buttons = 0;
		if ( BookingState::Requested === $state ) {
			echo Ui::action_button( $id, 'confirm', __( 'Accept request', 'quillmoor-booking' ), 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			echo Ui::action_button( $id, 'decline', __( 'Decline request', 'quillmoor-booking' ), 'secondary', __( 'Decline this request? The guest is told and the deposit is flagged for refund.', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			$buttons += 2;
		}
		if ( BookingState::Confirmed === $state ) {
			if ( time() >= $start - Config::ARRIVAL_WINDOW_HOURS * HOUR_IN_SECONDS ) {
				echo Ui::action_button( $id, 'arrive', $dining ? __( 'Mark as seated', 'quillmoor-booking' ) : __( 'Check in', 'quillmoor-booking' ), 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
				++$buttons;
			}
			if ( time() >= $start ) {
				echo Ui::action_button( $id, 'no_show', __( 'Record a no-show', 'quillmoor-booking' ), 'secondary', __( 'Record a no-show? The nights or seats are released.', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
				++$buttons;
			}
		}
		if ( BookingState::Arrived === $state ) {
			echo Ui::action_button( $id, 'complete', $dining ? __( 'Close the table', 'quillmoor-booking' ) : __( 'Check out', 'quillmoor-booking' ), 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			++$buttons;
		}
		if ( $state->can_go_to( BookingState::Cancelled ) && BookingState::Quote !== $state ) {
			echo Ui::action_button( $id, 'cancel', __( 'Cancel booking', 'quillmoor-booking' ), 'danger', __( 'Cancel this booking? A host cancellation always flags the full deposit for refund.', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			++$buttons;
		}
		if ( Refunds::is_refundable( $booking ) ) {
			echo Ui::action_button( $id, 'refund', __( 'Refund deposit', 'quillmoor-booking' ), 'secondary', __( 'Refund the deposit through the payment gateway in test mode?', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			++$buttons;
		}
		if ( 0 === $buttons ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'No actions for this state.', 'quillmoor-booking' ) . '</p>';
		}
		if ( BookingState::Confirmed === $state && time() < $start - Config::ARRIVAL_WINDOW_HOURS * HOUR_IN_SECONDS ) {
			echo '<p class="qhs-admin__sub">' . esc_html( sprintf( /* translators: %d: hours */ __( 'Check-in opens %d hours before arrival.', 'quillmoor-booking' ), Config::ARRIVAL_WINDOW_HOURS ) ) . '</p>';
		}
		echo '</div></section>';

		$triage = self::pending_triage( $id );
		if ( null !== $triage ) {
			echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Suggested priority', 'quillmoor-booking' ) . '</h2>';
			echo '<p><span class="qhs-badge">' . esc_html( (string) $triage['suggested_priority'] ) . '</span> ' . esc_html( (string) $triage['reason'] ) . '</p>';
			echo '<p class="qhs-admin__sub">' . esc_html( 'ai' === $triage['source'] ? __( 'AI suggestion. Nothing changes until you accept it.', 'quillmoor-booking' ) : __( 'Automatic default.', 'quillmoor-booking' ) ) . '</p><div class="qhs-admin__acts">';
			echo Ui::action_button( $id, 'triage_accept', __( 'Accept', 'quillmoor-booking' ), 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			echo Ui::action_button( $id, 'triage_dismiss', __( 'Dismiss', 'quillmoor-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
			echo '</div></section>';
		}

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Internal notes', 'quillmoor-booking' ) . '</h2><p class="qhs-admin__sub">' . esc_html__( 'Seen by hosts and managers only, never by the guest.', 'quillmoor-booking' ) . '</p><ul class="qhs-admin__notes" data-testid="notes">';
		foreach ( Services::bookings()->notes( $id ) as $note ) {
			$author = get_userdata( (int) $note['author_id'] );
			printf( '<li><p>%s</p><span class="qhs-admin__sub">%s, %s UTC</span></li>', esc_html( (string) $note['body'] ), esc_html( $author ? $author->display_name : '' ), esc_html( (string) $note['created_at'] ) );
		}
		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qhs-admin__noteform"><input type="hidden" name="action" value="qhs_booking"><input type="hidden" name="booking_id" value="' . (int) $id . '"><input type="hidden" name="do" value="note">';
		wp_nonce_field( 'qhs_booking_' . $id );
		echo '<label class="screen-reader-text" for="qhs-note">' . esc_html__( 'Note', 'quillmoor-booking' ) . '</label><textarea id="qhs-note" name="note" rows="3" maxlength="1000" required data-testid="note-input"></textarea><button type="submit" class="qhs-admin__btn" data-testid="note-submit">' . esc_html__( 'Add note', 'quillmoor-booking' ) . '</button></form></section>';
		echo '</div></div>';
		Ui::footer();
	}

	/**
	 * Readable actor.
	 *
	 * @param string $actor Stored actor label.
	 */
	private static function actor_name( string $actor ): string {
		if ( str_starts_with( $actor, 'user:' ) ) {
			$user = get_userdata( (int) substr( $actor, 5 ) );
			return $user ? $user->display_name : $actor;
		}
		return match ( $actor ) {
			'guest'    => __( 'Guest with the booking link', 'quillmoor-booking' ),
			'system'   => __( 'System', 'quillmoor-booking' ),
			'seed'     => __( 'Demo seed', 'quillmoor-booking' ),
			'dispatch' => __( 'Dispatch service', 'quillmoor-booking' ),
			default    => $actor,
		};
	}

	/**
	 * Latest pending suggestion.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>|null
	 */
	public static function pending_triage( int $booking_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, suggested_priority, reason, source FROM %i WHERE booking_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
				Db::table( 'triage' ),
				$booking_id,
				'pending'
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Availability calendar for one listing: nights for stays, days and seatings for tables.
	 */
	public static function calendar(): void {
		self::require_host();
		$listings = Ui::my_listings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$chosen = isset( $_GET['listing'] ) ? absint( $_GET['listing'] ) : 0;
		$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$day    = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$listing = null;
		foreach ( $listings as $candidate ) {
			if ( $candidate->id === $chosen || ( 0 === $chosen && null === $listing ) ) {
				$listing = $candidate;
			}
		}
		// A listing id that is not the viewer's, or no longer exists, falls back to their first listing.
		$listing = $listing ?? ( $listings[0] ?? null );
		$month   = 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : gmdate( 'Y-m' );

		Ui::header( 'qhs-calendar', __( 'Calendar', 'quillmoor-booking' ), __( 'Block nights or close days, and see who is booked. Changes apply at once to the booking card.', 'quillmoor-booking' ) );
		if ( null === $listing ) {
			echo '<section class="qhs-admin__card"><p class="qhs-admin__empty">' . esc_html__( 'Add a listing first.', 'quillmoor-booking' ) . '</p></section>';
			Ui::footer();
			return;
		}

		echo '<form class="qhs-admin__filters" method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="qhs-calendar"><label><span>' . esc_html__( 'Listing', 'quillmoor-booking' ) . '</span><select name="listing" data-testid="calendar-listing" onchange="this.form.submit()">';
		foreach ( $listings as $candidate ) {
			printf( '<option value="%d"%s>%s (%s)</option>', (int) $candidate->id, selected( $candidate->id, $listing->id, false ), esc_html( $candidate->title ), esc_html( ListingType::Stay === $candidate->type ? __( 'stay', 'quillmoor-booking' ) : __( 'tables', 'quillmoor-booking' ) ) );
		}
		printf( '</select></label><input type="hidden" name="month" value="%s"><noscript><button class="qhs-admin__btn">%s</button></noscript></form>', esc_attr( $month ), esc_html__( 'Show', 'quillmoor-booking' ) );

		$first = $month . '-01';
		$last  = gmdate( 'Y-m-t', (int) strtotime( $first . ' 00:00:00 UTC' ) );
		$prev  = gmdate( 'Y-m', (int) strtotime( $first . ' 00:00:00 UTC' ) - DAY_IN_SECONDS );
		$next  = gmdate( 'Y-m', (int) strtotime( $last . ' 00:00:00 UTC' ) + DAY_IN_SECONDS );
		$base  = admin_url( 'admin.php?page=qhs-calendar&listing=' . $listing->id );

		echo '<section class="qhs-admin__card"><div class="qhs-admin__row"><h2>' . esc_html( gmdate( 'F Y', (int) strtotime( $first . ' UTC' ) ) ) . '</h2><div class="qhs-admin__acts">';
		printf( '<a class="qhs-admin__btn qhs-admin__btn--secondary" href="%s">%s</a> <a class="qhs-admin__btn qhs-admin__btn--secondary" href="%s" data-testid="calendar-next">%s</a>', esc_url( $base . '&month=' . $prev ), esc_html__( 'Previous', 'quillmoor-booking' ), esc_url( $base . '&month=' . $next ), esc_html__( 'Next', 'quillmoor-booking' ) );
		echo '</div></div>';

		$bookings = array();
		foreach ( Services::bookings()->for_listing_between( $listing->id, $first, $last ) as $row ) {
			$bookings[] = $row;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-qhs-calform><input type="hidden" name="action" value="qhs_calendar"><input type="hidden" name="listing_id" value="' . (int) $listing->id . '"><input type="hidden" name="month" value="' . esc_attr( $month ) . '">';
		wp_nonce_field( 'qhs_calendar_' . $listing->id );

		$stay = ListingType::Stay === $listing->type;
		if ( $stay ) {
			$taken = Services::availability()->stay_calendar( $listing, $first, $last );
			$who   = array();
			foreach ( $bookings as $row ) {
				foreach ( Pricing::night_dates( (string) $row['check_in'], Pricing::nights( (string) $row['check_in'], (string) $row['check_out'] ) ) as $night ) {
					$who[ $night ] = $row;
				}
			}
		} else {
			$closed = Services::availability()->dining_calendar( $listing, $first, $last );
		}

		echo '<div class="qhs-cal" role="grid" data-testid="admin-calendar"><div class="qhs-cal__head" role="row">';
		foreach ( array( 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ) as $name ) {
			echo '<span role="columnheader">' . esc_html( $name ) . '</span>';
		}
		echo '</div><div class="qhs-cal__grid" role="row">';
		$lead = (int) gmdate( 'N', (int) strtotime( $first . ' UTC' ) ) - 1;
		for ( $i = 0; $i < $lead; $i++ ) {
			echo '<span class="qhs-cal__pad"></span>';
		}
		$today = gmdate( 'Y-m-d' );
		for ( $d = $first; $d <= $last; $d = gmdate( 'Y-m-d', (int) strtotime( $d . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) ) {
			$past = $d < $today;
			if ( $stay ) {
				$status = $taken[ $d ] ?? 'free';
				$rate   = Pricing::night_rate( $listing, $d );
				$line   = 'free' === $status ? Pricing::money( $rate['cents'] ) : ( 'blocked' === $status ? __( 'Blocked', 'quillmoor-booking' ) : ( 'held' === $status ? __( 'On hold', 'quillmoor-booking' ) : (string) ( $who[ $d ]['full_name'] ?? __( 'Booked', 'quillmoor-booking' ) ) ) );
				$link   = isset( $who[ $d ] ) ? Ui::booking_url( $who[ $d ] ) : '';
			} else {
				$status = isset( $closed[ $d ] ) ? $closed[ $d ] : 'open';
				$seats  = 'open' === $status ? array_sum( Services::tables()->booked_on( $listing->id, $d ) ) : 0;
				$line   = 'closed' === $status ? __( 'Closed', 'quillmoor-booking' ) : ( 'no_service' === $status ? __( 'No service', 'quillmoor-booking' ) : sprintf( /* translators: %d: seats */ _n( '%d seat booked', '%d seats booked', $seats, 'quillmoor-booking' ), $seats ) );
				$link   = 'open' === $status ? $base . '&month=' . $month . '&day=' . $d : '';
			}
			$can_pick = ! $past && ( $stay ? in_array( $status, array( 'free', 'blocked' ), true ) : in_array( $status, array( 'open', 'closed' ), true ) );
			printf(
				'<div class="qhs-cal__day is-%1$s%2$s" role="gridcell" data-testid="admin-day" data-date="%3$s" data-status="%1$s">%4$s<span class="qhs-cal__n">%5$s</span>%6$s</div>',
				esc_attr( $status ),
				$past ? ' is-past' : '',
				esc_attr( $d ),
				$can_pick ? '<input type="checkbox" name="dates[]" value="' . esc_attr( $d ) . '" aria-label="' . esc_attr( gmdate( 'l j F', (int) strtotime( $d . ' UTC' ) ) ) . '">' : '',
				esc_html( (string) (int) substr( $d, 8, 2 ) ),
				'' === $link ? '<span class="qhs-cal__l">' . esc_html( $line ) . '</span>' : '<a class="qhs-cal__l" href="' . esc_url( $link ) . '">' . esc_html( $line ) . '</a>'
			);
		}
		echo '</div></div><div class="qhs-admin__acts">';
		if ( $stay ) {
			printf( '<button class="qhs-admin__btn" name="do" value="block" data-testid="calendar-block">%s</button> <button class="qhs-admin__btn qhs-admin__btn--secondary" name="do" value="unblock" data-testid="calendar-unblock">%s</button>', esc_html__( 'Block selected nights', 'quillmoor-booking' ), esc_html__( 'Unblock selected nights', 'quillmoor-booking' ) );
		} else {
			printf( '<button class="qhs-admin__btn" name="do" value="close" data-testid="calendar-close">%s</button> <button class="qhs-admin__btn qhs-admin__btn--secondary" name="do" value="open" data-testid="calendar-open">%s</button>', esc_html__( 'Close selected days', 'quillmoor-booking' ), esc_html__( 'Reopen selected days', 'quillmoor-booking' ) );
		}
		echo '</div></form>';
		echo '<p class="qhs-admin__sub">' . esc_html(
			$stay
				? sprintf( /* translators: 1: min nights, 2: max nights */ __( 'Stays of %1$d to %2$d nights. Prices shown are per night after seasonal and weekend rules. Booked and held nights cannot be blocked.', 'quillmoor-booking' ), $listing->min_nights, $listing->max_nights )
				: sprintf( /* translators: 1: seats, 2: minutes */ __( '%1$d seats per time slot, every %2$d minutes. A day with bookings cannot be closed. Open a day to see its seatings.', 'quillmoor-booking' ), $listing->covers, $listing->slot_minutes )
		) . ' <a href="' . esc_url( (string) get_edit_post_link( $listing->id ) ) . '">' . esc_html__( 'Edit rules and prices', 'quillmoor-booking' ) . '</a></p>';
		echo '</section>';

		if ( ! $stay && Listing::is_date( $day ) ) {
			self::seatings( $listing, $day, $bookings );
		}
		Ui::footer();
	}

	/**
	 * Seatings of one day for a dining listing.
	 *
	 * @param Listing                        $listing  Listing.
	 * @param string                         $day      Day.
	 * @param array<int,array<string,mixed>> $bookings Bookings in the month.
	 */
	private static function seatings( Listing $listing, string $day, array $bookings ): void {
		$booked = Services::tables()->booked_on( $listing->id, $day );
		echo '<section class="qhs-admin__card" data-testid="seatings"><h2>' . esc_html( sprintf( /* translators: %s: date */ __( 'Seatings on %s (UTC)', 'quillmoor-booking' ), gmdate( 'l j F', (int) strtotime( $day . ' UTC' ) ) ) ) . '</h2><table class="qhs-admin__table"><thead><tr><th>' . esc_html__( 'Time', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Booked', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Guests', 'quillmoor-booking' ) . '</th></tr></thead><tbody>';
		$times = array();
		foreach ( $listing->periods as $period ) {
			$start = (int) strtotime( $day . ' ' . $period['start'] . ':00 UTC' );
			$end   = (int) strtotime( $day . ' ' . $period['end'] . ':00 UTC' );
			$wday  = strtolower( gmdate( 'D', $start ) );
			if ( ! in_array( $wday, $period['days'], true ) ) {
				continue;
			}
			for ( $t = $start; $t <= $end; $t += $listing->slot_minutes * MINUTE_IN_SECONDS ) {
				$times[ gmdate( 'Y-m-d H:i:s', $t ) ] = $period['label'];
			}
		}
		ksort( $times );
		foreach ( $times as $slot => $label ) {
			$names = array();
			foreach ( $bookings as $row ) {
				if ( (string) $row['slot_start'] === $slot ) {
					$names[] = '<a href="' . esc_url( Ui::booking_url( $row ) ) . '">' . esc_html( $row['full_name'] . ' (' . (int) $row['guests'] . ')' ) . '</a>';
				}
			}
			printf(
				'<tr><td>%s <span class="qhs-admin__sub">%s</span></td><td>%d / %d</td><td>%s</td></tr>',
				esc_html( substr( $slot, 11, 5 ) ),
				esc_html( (string) $label ),
				(int) ( $booked[ $slot ] ?? 0 ),
				(int) $listing->covers,
				wp_kses( implode( ', ', $names ), array( 'a' => array( 'href' => true ) ) )
			);
		}
		if ( array() === $times ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No service on this day.', 'quillmoor-booking' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Manager activity: events, alerts and the mail log.
	 */
	public static function activity(): void {
		global $wpdb;
		if ( ! current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You cannot view this page.', 'quillmoor-booking' ), '', array( 'response' => 403 ) );
		}
		Ui::header( 'qhs-activity', __( 'Activity', 'quillmoor-booking' ), __( 'Events sent to n8n, alerts and every email attempt.', 'quillmoor-booking' ) );
		self::system_card();

		$events = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT event, booking_id, attempts, delivered_at, failed_at, created_at FROM %i ORDER BY id DESC LIMIT 25', Db::table( 'outbox' ) ), ARRAY_A );
		echo '<div class="qhs-admin__cols"><section class="qhs-admin__card"><h2>' . esc_html__( 'Recent events', 'quillmoor-booking' ) . '</h2><div class="qhs-admin__scroll"><table class="qhs-admin__table"><thead><tr><th>' . esc_html__( 'Event', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Booking', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Delivery', 'quillmoor-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $row ) {
			$status = null !== $row['delivered_at'] ? __( 'Delivered', 'quillmoor-booking' ) : ( null !== $row['failed_at'] ? __( 'Failed', 'quillmoor-booking' ) : sprintf( /* translators: %d: attempts */ __( 'Waiting, %d attempts', 'quillmoor-booking' ), (int) $row['attempts'] ) );
			printf( '<tr><td>%s<span class="qhs-admin__sub">%s UTC</span></td><td>QM-%d</td><td>%s</td></tr>', esc_html( (string) $row['event'] ), esc_html( (string) $row['created_at'] ), (int) $row['booking_id'], esc_html( $status ) );
		}
		if ( array() === $events ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No events yet.', 'quillmoor-booking' ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Recent alerts', 'quillmoor-booking' ) . '</h2>';
		$alerts = Alerts::latest( 20 );
		if ( array() === $alerts ) {
			echo '<p class="qhs-admin__empty">' . esc_html__( 'No alerts.', 'quillmoor-booking' ) . '</p>';
		}
		echo '<ul class="qhs-admin__notes">';
		foreach ( $alerts as $row ) {
			printf( '<li><p><strong>%s</strong> %s</p><span class="qhs-admin__sub">%s UTC</span></li>', esc_html( (string) $row['source'] ), esc_html( (string) $row['message'] ), esc_html( (string) $row['created_at'] ) );
		}
		echo '</ul></section></div>';

		$mail = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind, booking_id, transport, status, subject, created_at FROM %i ORDER BY id DESC LIMIT 25', Db::table( 'mail_log' ) ), ARRAY_A );
		echo '<section class="qhs-admin__card"><h2>' . esc_html__( 'Mail log', 'quillmoor-booking' ) . '</h2><p class="qhs-admin__sub">' . esc_html__( 'Addresses are stored as hashes. Reserved test domains such as example.com are never sent to.', 'quillmoor-booking' ) . '</p><div class="qhs-admin__scroll"><table class="qhs-admin__table"><thead><tr><th>' . esc_html__( 'Message', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Booking', 'quillmoor-booking' ) . '</th><th>' . esc_html__( 'Result', 'quillmoor-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $mail as $row ) {
			printf( '<tr><td>%s<span class="qhs-admin__sub">%s UTC</span></td><td>%s</td><td>%s<span class="qhs-admin__sub">%s</span></td></tr>', esc_html( (string) $row['subject'] ), esc_html( (string) $row['created_at'] ), (int) $row['booking_id'] > 0 ? 'QM-' . (int) $row['booking_id'] : '', esc_html( (string) $row['status'] ), esc_html( (string) $row['transport'] ) );
		}
		echo '</tbody></table></div></section>';
		Ui::footer();
	}

	/**
	 * Stop unless the user hosts or manages.
	 */
	private static function require_host(): void {
		if ( ! current_user_can( BookingPolicy::CAP_HOST ) && ! current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You cannot view this page.', 'quillmoor-booking' ), '', array( 'response' => 403 ) );
		}
	}
}
