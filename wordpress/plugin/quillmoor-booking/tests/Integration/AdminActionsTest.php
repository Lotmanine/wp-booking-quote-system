<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Services;

class AdminActionsTest extends TestCase {

	private function booking_action( int $id, string $do, array $extra = array(), ?string $nonce = null ): array {
		return $this->admin_post(
			'booking',
			$extra + array(
				'booking_id' => $id,
				'do'         => $do,
				'_wpnonce'   => $nonce ?? wp_create_nonce( 'qhs_booking_' . $id ),
			)
		);
	}

	private function calendar_action( int $listing_id, string $do, array $dates, ?string $nonce = null ): array {
		return $this->admin_post(
			'calendar',
			array(
				'listing_id' => $listing_id,
				'do'         => $do,
				'dates'      => $dates,
				'month'      => substr( $dates[0] ?? gmdate( 'Y-m' ), 0, 7 ),
				'_wpnonce'   => $nonce ?? wp_create_nonce( 'qhs_calendar_' . $listing_id ),
			)
		);
	}

	/** A request on a listing owned by a new host, with that host signed in. */
	private function request_as_host(): array {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $host ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $host );
		return array( $id, $host );
	}

	public function test_the_host_confirms_a_request(): void {
		[ $id ] = $this->request_as_host();

		$result = $this->booking_action( $id, 'confirm' );

		$this->assertStringContainsString( 'page=qhs-bookings&booking=' . $id, $result['redirect'] );
		$this->assertSame( 'success', $result['notice']['kind'] );
		$this->assertSame( 'confirmed', $this->state( $id ) );
		$history = Services::bookings()->history( $id );
		$this->assertSame( 'user:' . get_current_user_id(), end( $history )['actor'] );
	}

	public function test_the_host_declines_a_request(): void {
		[ $id ] = $this->request_as_host();

		$this->booking_action( $id, 'decline' );

		$this->assertSame( 'declined', $this->state( $id ) );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_a_bad_nonce_stops_the_action(): void {
		[ $id ] = $this->request_as_host();

		$this->assertSame( 403, $this->booking_action( $id, 'confirm', array(), 'bad-nonce' )['die'] );
		$this->assertSame( 403, $this->booking_action( $id, 'confirm', array(), wp_create_nonce( 'qhs_booking_' . ( $id + 1 ) ) )['die'] );
		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_a_host_of_another_listing_gets_404(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $this->make_host() ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $this->make_host() );

		$this->assertSame( 404, $this->booking_action( $id, 'confirm' )['die'] );
		$this->assertSame( 404, $this->booking_action( $id, 'cancel' )['die'] );
		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_guests_and_staff_cannot_use_host_actions(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ) ), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 404, $this->booking_action( $id, 'confirm' )['die'] );
		wp_set_current_user( $this->user( Roles::CUSTOMER ) );
		$this->assertSame( 404, $this->booking_action( $id, 'confirm' )['die'] );
		$staff = $this->user( Roles::STAFF );
		Services::bookings()->update( $id, array( 'staff_user_id' => $staff ) );
		wp_set_current_user( $staff );
		$this->assertSame( 404, $this->booking_action( $id, 'confirm' )['die'] );
		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_the_manager_acts_on_any_listing(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ), $this->make_host() ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $this->user( Roles::MANAGER ) );

		$this->booking_action( $id, 'confirm' );

		$this->assertSame( 'confirmed', $this->state( $id ) );
	}

	public function test_a_host_cancellation_flags_a_refund_even_inside_the_cutoff(): void {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array( 'qm_cancellation' => 'strict' ), $host ), $this->day( 3 ), $this->day( 5 ) );
		wp_set_current_user( $host );

		$this->booking_action( $id, 'cancel' );

		$this->assertSame( 'cancelled', $this->state( $id ) );
		$this->assertSame( 'flagged', $this->booking( $id )['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_the_host_refunds_after_cancelling(): void {
		$host = $this->make_host();
		$id   = $this->held_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );
		$this->order_for( $id, true );
		wp_set_current_user( $host );

		$early = $this->booking_action( $id, 'refund' );
		$this->assertSame( 'error', $early['notice']['kind'] );
		$this->assertSame( 'none', $this->booking( $id )['refund_status'] );

		$this->booking_action( $id, 'cancel' );
		$done = $this->booking_action( $id, 'refund' );

		$this->assertSame( 'success', $done['notice']['kind'] );
		$this->assertSame( 'refunded', $this->booking( $id )['refund_status'] );
	}

	public function test_arrival_completion_and_no_show_from_the_screen(): void {
		$host  = $this->make_host();
		$stay  = $this->stay_listing( array(), $host );
		$first = $this->paid_stay( $stay, $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$other = $this->paid_stay( $stay, $this->day( 20 ), $this->day( 22 ), 'b@example.com' );
		wp_set_current_user( $host );

		$this->assertSame( 'error', $this->booking_action( $first, 'arrive' )['notice']['kind'] );
		$this->move_start( $first, -1 * HOUR_IN_SECONDS );
		$this->booking_action( $first, 'arrive' );
		$this->booking_action( $first, 'complete' );
		$this->move_start( $other, -1 * HOUR_IN_SECONDS );
		$this->booking_action( $other, 'no_show' );

		$this->assertSame( 'completed', $this->state( $first ) );
		$this->assertSame( 'no_show', $this->state( $other ) );
	}

	public function test_notes_are_stored_as_plain_text(): void {
		[ $id, $host ] = $this->request_as_host();

		$this->booking_action( $id, 'note', array( 'note' => '<b>Late</b> arrival <script>x</script>' ) );
		$empty = $this->booking_action( $id, 'note', array( 'note' => '   ' ) );

		$notes = Services::bookings()->notes( $id );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Late arrival', $notes[0]['body'] );
		$this->assertSame( $host, (int) $notes[0]['author_id'] );
		$this->assertSame( 'error', $empty['notice']['kind'] );
		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_an_unknown_action_changes_nothing(): void {
		[ $id ] = $this->request_as_host();

		$this->assertSame( 'error', $this->booking_action( $id, 'delete_everything' )['notice']['kind'] );
		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_the_host_blocks_and_unblocks_nights(): void {
		$host    = $this->make_host();
		$listing = $this->stay_listing( array(), $host );
		wp_set_current_user( $host );

		$result = $this->calendar_action( $listing, 'block', array( $this->day( 5 ), $this->day( 6 ), $this->day( -3 ), 'nonsense' ) );

		$this->assertStringContainsString( 'page=qhs-calendar&listing=' . $listing, $result['redirect'] );
		$this->assertSame( 'success', $result['notice']['kind'] );
		$this->assertSame( 2, $this->row_count( 'night_claims', "listing_id = {$listing} AND kind = 'block'" ) );
		$this->assertSame( 0, $this->row_count( 'night_claims', "night = '" . $this->day( -3 ) . "'" ) );

		$this->calendar_action( $listing, 'unblock', array( $this->day( 5 ) ) );

		$this->assertSame( array( $this->day( 6 ) => 'blocked' ), Services::availability()->stay_calendar( $this->load( $listing ), $this->day( 1 ), $this->day( 30 ) ) );
	}

	public function test_blocking_booked_nights_reports_them(): void {
		$host    = $this->make_host();
		$listing = $this->stay_listing( array(), $host );
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $host );

		$result = $this->calendar_action( $listing, 'block', array( $this->day( 9 ), $this->day( 10 ) ) );

		$this->assertSame( 'error', $result['notice']['kind'] );
		$this->assertStringContainsString( $this->day( 10 ), $result['notice']['message'] );
		$this->assertSame( array( $this->day( 10 ), $this->day( 11 ) ), $this->nights_of( $id ) );
		$this->assertSame( 'block', Services::nights()->kind_of( $listing, $this->day( 9 ) ) );
	}

	public function test_calendar_actions_check_the_nonce_and_the_owner(): void {
		$listing = $this->stay_listing( array(), $this->make_host() );
		$other   = $this->make_host();
		wp_set_current_user( $other );

		$this->assertSame( 404, $this->calendar_action( $listing, 'block', array( $this->day( 5 ) ) )['die'] );
		$this->assertSame( 403, $this->calendar_action( $listing, 'block', array( $this->day( 5 ) ), 'bad' )['die'] );
		$this->assertSame( 0, $this->row_count( 'night_claims' ) );

		wp_set_current_user( $this->user( Roles::MANAGER ) );
		$this->calendar_action( $listing, 'block', array( $this->day( 5 ) ) );
		$this->assertSame( 1, $this->row_count( 'night_claims' ) );
	}

	public function test_the_host_closes_and_reopens_dining_days(): void {
		$host    = $this->make_host();
		$listing = $this->dining_listing( array(), $host );
		$this->paid_table( $listing, $this->seating( 4 ), 2 );
		wp_set_current_user( $host );

		$closed = $this->calendar_action( $listing, 'close', array( $this->day( 3 ), $this->day( 4 ) ) );

		$this->assertSame( 'error', $closed['notice']['kind'] );
		$this->assertStringContainsString( $this->day( 4 ), $closed['notice']['message'] );
		$this->assertSame( array( $this->day( 3 ) ), Services::tables()->closed_days( $listing, $this->day( 1 ), $this->day( 9 ) ) );
		$this->assertSame( 1, $this->row_count( 'day_closures', "created_by = {$host}" ) );

		$this->calendar_action( $listing, 'open', array( $this->day( 3 ) ) );

		$this->assertSame( array(), Services::tables()->closed_days( $listing, $this->day( 1 ), $this->day( 9 ) ) );
	}

	public function test_calendar_actions_follow_the_listing_type(): void {
		$host   = $this->make_host();
		$stay   = $this->stay_listing( array(), $host );
		$dining = $this->dining_listing( array(), $host );
		wp_set_current_user( $host );

		$this->assertSame( 'error', $this->calendar_action( $stay, 'close', array( $this->day( 5 ) ) )['notice']['kind'] );
		$this->assertSame( 'error', $this->calendar_action( $dining, 'block', array( $this->day( 5 ) ) )['notice']['kind'] );
		$this->assertSame( 'error', $this->calendar_action( $stay, 'block', array() )['notice']['kind'] );
		$this->assertSame( 0, $this->row_count( 'night_claims' ) );
		$this->assertSame( 0, $this->row_count( 'day_closures' ) );
	}
}
