<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Admin\AdminPages;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Services;
use WPDieException;

/**
 * Host and manager screens render from the same data the actions change, scoped to the viewer.
 */
class AdminPagesTest extends TestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function screen( string $method, array $get = array() ): string {
		$_GET = $get;
		ob_start();
		try {
			AdminPages::$method();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function screen_status( string $method, array $get = array() ): int {
		try {
			$this->screen( $method, $get );
		} catch ( WPDieException $e ) {
			return (int) $e->getCode();
		}
		return 200;
	}

	public function test_the_dashboard_counts_only_the_hosts_bookings(): void {
		$host  = $this->make_host();
		$other = $this->make_host();
		$this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $host ), $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $other ), $this->day( 10 ), $this->day( 12 ), 'b@example.com' );
		$this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $other ), $this->day( 20 ), $this->day( 22 ), 'c@example.com' );
		wp_set_current_user( $host );

		$html = $this->screen( 'dashboard' );

		$this->assertMatchesRegularExpression( '/kpi-requests[^>]*>.*?1/s', $html );
		$this->assertStringContainsString( '$40', $html );
	}

	public function test_the_booking_list_is_scoped_to_the_host(): void {
		$host   = $this->make_host();
		$mine   = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$theirs = $this->paid_stay( $this->stay_listing( array(), $this->make_host() ), $this->day( 10 ), $this->day( 12 ), 'b@example.com' );
		wp_set_current_user( $host );

		$html = $this->screen( 'bookings' );

		$this->assertStringContainsString( 'data-booking="' . $mine . '"', $html );
		$this->assertStringNotContainsString( 'data-booking="' . $theirs . '"', $html );

		wp_set_current_user( $this->user( Roles::MANAGER ) );
		$all = $this->screen( 'bookings' );
		$this->assertStringContainsString( 'data-booking="' . $theirs . '"', $all );
	}

	public function test_the_booking_list_searches_by_reference_guest_and_state(): void {
		$host    = $this->make_host();
		$listing = $this->stay_listing( array(), $host );
		$first   = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'alpha@example.com' );
		$second  = $this->paid_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'beta@example.com' );
		Services::service()->cancel( $second, 'guest' );
		$quote = $this->stay_quote( $listing, $this->day( 30 ), $this->day( 32 ), 2, array(), 'gamma@example.com' );
		wp_set_current_user( $host );

		$by_ref   = $this->screen( 'bookings', array( 's' => 'QM-' . $first ) );
		$by_mail  = $this->screen( 'bookings', array( 's' => 'beta@' ) );
		$by_state = $this->screen( 'bookings', array( 'state' => 'cancelled' ) );
		$all      = $this->screen( 'bookings' );

		$this->assertStringContainsString( 'data-booking="' . $first . '"', $by_ref );
		$this->assertStringNotContainsString( 'data-booking="' . $second . '"', $by_ref );
		$this->assertStringContainsString( 'data-booking="' . $second . '"', $by_mail );
		$this->assertStringNotContainsString( 'data-booking="' . $first . '"', $by_mail );
		$this->assertStringContainsString( 'data-booking="' . $second . '"', $by_state );
		$this->assertStringNotContainsString( 'data-booking="' . $first . '"', $by_state );
		$this->assertStringNotContainsString( 'data-booking="' . $quote . '"', $all );
	}

	public function test_one_booking_shows_its_actions_notes_and_history(): void {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $host ), $this->day( 10 ), $this->day( 12 ) );
		Services::service()->add_note( $id, $host, 'Gate code 1234' );
		wp_set_current_user( $host );

		$html = $this->screen( 'bookings', array( 'booking' => (string) $id ) );

		$this->assertStringContainsString( 'data-testid="action-confirm"', $html );
		$this->assertStringContainsString( 'data-testid="action-decline"', $html );
		$this->assertStringContainsString( 'Gate code 1234', $html );
		$this->assertStringContainsString( 'QM-' . $id, $html );
	}

	public function test_another_hosts_booking_page_is_404(): void {
		$id = $this->paid_stay( $this->stay_listing( array(), $this->make_host() ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $this->make_host() );

		$this->assertSame( 404, $this->screen_status( 'bookings', array( 'booking' => (string) $id ) ) );
	}

	public function test_guests_cannot_open_the_host_screens(): void {
		wp_set_current_user( $this->user( Roles::CUSTOMER ) );

		$this->assertSame( 403, $this->screen_status( 'dashboard' ) );
		$this->assertSame( 403, $this->screen_status( 'bookings' ) );
		$this->assertSame( 403, $this->screen_status( 'calendar' ) );
		$this->assertSame( 403, $this->screen_status( 'activity' ) );
	}

	public function test_the_stay_calendar_shows_booked_and_blocked_nights(): void {
		$host    = $this->make_host();
		$listing = $this->stay_listing( array(), $host );
		$in      = $this->day( 40 );
		$this->paid_stay( $listing, $in, $this->plus( $in, 2 ) );
		Services::nights()->block( $listing, array( $this->plus( $in, 3 ) ) );
		wp_set_current_user( $host );

		$html = $this->screen(
			'calendar',
			array(
				'listing' => (string) $listing,
				'month'   => substr( $in, 0, 7 ),
			)
		);

		$this->assertStringContainsString( 'value="' . wp_create_nonce( 'qhs_calendar_' . $listing ) . '"', $html );
		$this->assertStringContainsString( 'name="listing_id" value="' . $listing . '"', $html );
		$this->assertStringContainsString( $this->plus( $in, 3 ), $html );
	}

	public function test_the_dining_calendar_shows_a_day_of_seatings(): void {
		$host    = $this->make_host();
		$listing = $this->dining_listing( array(), $host );
		$this->paid_table( $listing, $this->seating( 3 ), 4 );
		wp_set_current_user( $host );

		$html = $this->screen(
			'calendar',
			array(
				'listing' => (string) $listing,
				'month'   => substr( $this->day( 3 ), 0, 7 ),
				'day'     => $this->day( 3 ),
			)
		);

		$this->assertStringContainsString( '18:00', $html );
	}

	public function test_a_host_only_picks_their_own_listings_in_the_calendar(): void {
		$host  = $this->make_host();
		$mine  = $this->stay_listing( array(), $host );
		$other = $this->stay_listing( array(), $this->make_host() );
		wp_set_current_user( $host );

		$html = $this->screen( 'calendar', array( 'listing' => (string) $other ) );

		$this->assertStringContainsString( 'name="listing_id" value="' . $mine . '"', $html );
		$this->assertStringNotContainsString( 'name="listing_id" value="' . $other . '"', $html );
	}

	public function test_a_listing_with_live_bookings_cannot_be_removed(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$this->assertFalse( wp_trash_post( $listing ) );
		$this->assertFalse( wp_delete_post( $listing, true ) );
		$this->assertSame( 'publish', get_post_status( $listing ) );

		Services::service()->cancel( $id, 'host', false );
		$this->assertInstanceOf( \WP_Post::class, wp_delete_post( $listing, true ) );
	}

	public function test_the_listing_editor_boxes_render(): void {
		$host   = $this->make_host();
		$stay   = get_post( $this->stay_listing( array( 'qm_extras' => array( array( 'label' => 'Firewood', 'price' => 25, 'unit' => 'per_stay' ) ) ), $host ) );
		$dining = get_post( $this->dining_listing( array(), $host ) );
		$this->paid_stay( $stay->ID, $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $host );

		ob_start();
		\Quillmoor\Booking\Admin\ListingEditor::preview_box( $stay );
		\Quillmoor\Booking\Admin\ListingEditor::preview_box( $dining );
		\Quillmoor\Booking\Admin\ListingEditor::links_box( $stay );
		\Quillmoor\Booking\Admin\ListingEditor::column( 'qm_price', $stay->ID );
		\Quillmoor\Booking\Admin\ListingEditor::column( 'qm_book', $dining->ID );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="firewood"', $html );
		$this->assertStringContainsString( 'First open seating: ' . $this->day( 1 ), $html );
		$this->assertStringContainsString( '1 live booking', $html );
		$this->assertStringContainsString( '$100 / night', $html );
		$this->assertStringContainsString( 'Instant', $html );
	}

	public function test_the_activity_screen_is_for_managers(): void {
		$this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $this->make_host() );
		$this->assertSame( 403, $this->screen_status( 'activity' ) );

		wp_set_current_user( $this->user( Roles::MANAGER ) );
		$html = $this->screen( 'activity' );

		$this->assertStringContainsString( 'booking.confirmed', $html );
		$this->assertStringContainsString( 'Booking confirmed (demo)', $html );
		$this->assertStringNotContainsString( 'guest@example.com', $html );
	}
}
