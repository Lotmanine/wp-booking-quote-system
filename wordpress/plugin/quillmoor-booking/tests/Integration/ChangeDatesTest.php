<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Services;

class ChangeDatesTest extends TestCase {

	private function move_stay( int $id, string $in, string $out ): array {
		return Services::service()->change_dates( $id, $in, $out, '', 'guest' );
	}

	private function move_table( int $id, string $slot ): array {
		return Services::service()->change_dates( $id, '', '', $slot, 'guest' );
	}

	private function error_of( callable $call ): string {
		try {
			$call();
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	public function test_a_stay_moves_to_new_dates(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 13 ) );
		$before  = $this->booking( $id );
		Services::bookings()->update( $id, array( 'reminder_sent_at' => Db::now() ) );

		$moved = $this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) );

		$this->assertSame( 'confirmed', $moved['state'] );
		$this->assertSame( $this->day( 20 ), $moved['check_in'] );
		$this->assertSame( $this->day( 22 ), $moved['check_out'] );
		$this->assertSame( $this->day( 20 ) . ' 15:00:00', $moved['slot_start'] );
		$this->assertSame( $this->day( 22 ) . ' 11:00:00', $moved['slot_end'] );
		$this->assertNull( $moved['reminder_sent_at'] );
		$this->assertGreaterThan( (int) $before['version'], (int) $moved['version'] );
		$this->assertSame( array( $this->day( 20 ), $this->day( 21 ) ), $this->nights_of( $id ) );
		$this->assertSame( 2, $this->row_count( 'night_claims', "booking_id = {$id} AND kind = 'booking' AND expires_at IS NULL" ) );
		$this->assertSame( 1, $this->row_count( 'outbox', "booking_id = {$id} AND event = 'booking.rescheduled'" ) );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND reason = 'dates changed'" ) );
	}

	public function test_the_new_total_is_stored_and_the_paid_deposit_stays(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 13 ) );
		$quote   = (int) $this->booking( $id )['quote_id'];

		$this->move_stay( $id, $this->day( 20 ), $this->day( 25 ) );

		$stored = Services::bookings()->quote( $quote );
		$this->assertSame( 50000, (int) $stored['total_cents'] );
		$this->assertSame( 6000, (int) $stored['deposit_cents'] );
		$this->assertSame( $this->day( 20 ), $stored['check_in'] );
		$this->assertStringContainsString( '5 nights', (string) $stored['line_items'] );
	}

	public function test_an_overlapping_shift_keeps_the_shared_nights(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 13 ) );

		$this->move_stay( $id, $this->day( 11 ), $this->day( 14 ) );

		$this->assertSame( array( $this->day( 11 ), $this->day( 12 ), $this->day( 13 ) ), $this->nights_of( $id ) );
		$this->assertSame( 'hold', $this->state( $this->held_stay( $listing, $this->day( 10 ), $this->day( 11 ), 'next@example.com' ) ) );
	}

	public function test_a_shorter_stay_frees_the_dropped_nights(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 13 ) );

		$this->move_stay( $id, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( array( $this->day( 10 ), $this->day( 11 ) ), $this->nights_of( $id ) );
	}

	public function test_taken_nights_refuse_the_change_and_keep_the_original(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$this->paid_stay( $listing, $this->day( 14 ), $this->day( 16 ), 'b@example.com' );

		$this->assertSame( 'unavailable', $this->error_of( fn() => $this->move_stay( $id, $this->day( 12 ), $this->day( 15 ) ) ) );

		$booking = $this->booking( $id );
		$this->assertSame( $this->day( 10 ), $booking['check_in'] );
		$this->assertSame( $this->day( 13 ), $booking['check_out'] );
		$this->assertSame( array( $this->day( 10 ), $this->day( 11 ), $this->day( 12 ) ), $this->nights_of( $id ) );
		$this->assertSame( 0, $this->row_count( 'outbox', "booking_id = {$id} AND event = 'booking.rescheduled'" ) );
	}

	public function test_a_blocked_night_refuses_the_change(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		Services::nights()->block( $listing, array( $this->day( 21 ) ) );

		$this->assertSame( 'unavailable', $this->error_of( fn() => $this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) ) ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_new_dates_must_follow_the_listing_rules(): void {
		$listing = $this->stay_listing( array( 'qm_min_nights' => 2 ) );
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'stay_length', $this->error_of( fn() => $this->move_stay( $id, $this->day( 20 ), $this->day( 21 ) ) ) );
		$this->assertSame( 'dates_past', $this->error_of( fn() => $this->move_stay( $id, $this->day( 0 ), $this->day( 2 ) ) ) );
	}

	public function test_the_same_dates_are_refused(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'same_dates', $this->error_of( fn() => $this->move_stay( $id, $this->day( 10 ), $this->day( 12 ) ) ) );
	}

	public function test_changes_close_24_hours_before_arrival(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->move_start( $id, 20 * HOUR_IN_SECONDS );

		$this->assertSame( 'too_late', $this->error_of( fn() => $this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) ) ) );
	}

	public function test_only_upcoming_bookings_can_change(): void {
		$listing = $this->stay_listing();
		$held    = $this->held_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'not_changeable', $this->error_of( fn() => $this->move_stay( $held, $this->day( 20 ), $this->day( 22 ) ) ) );
	}

	public function test_a_request_can_change_dates_while_it_waits(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_instant_book' => false ) ), $this->day( 10 ), $this->day( 12 ) );

		$moved = $this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) );

		$this->assertSame( 'requested', $moved['state'] );
		$this->assertSame( array( $this->day( 20 ), $this->day( 21 ) ), $this->nights_of( $id ) );
	}

	public function test_a_table_moves_to_another_time(): void {
		$listing = $this->dining_listing();
		$old     = $this->seating( 3, 0 );
		$new     = $this->seating( 4, 2 );
		$id      = $this->paid_table( $listing, $old, 4 );

		$moved = $this->move_table( $id, $new );

		$this->assertSame( $new, $moved['slot_start'] );
		$this->assertNull( $moved['check_in'] );
		$this->assertSame( 0, $this->booked_seats( $listing, $old ) );
		$this->assertSame( 4, $this->booked_seats( $listing, $new ) );
		$this->assertSame( 1, $this->row_count( 'table_claims', "booking_id = {$id}" ) );
		$this->assertSame( 1, $this->row_count( 'table_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
	}

	public function test_a_full_time_refuses_the_table_change(): void {
		$listing = $this->dining_listing();
		$old     = $this->seating( 3, 0 );
		$full    = $this->seating( 3, 1 );
		$id      = $this->paid_table( $listing, $old, 4, 'a@example.com' );
		$this->paid_table( $listing, $full, 8, 'b@example.com' );

		$this->assertSame( 'unavailable', $this->error_of( fn() => $this->move_table( $id, $full ) ) );

		$this->assertSame( $old, $this->booking( $id )['slot_start'] );
		$this->assertSame( 4, $this->booked_seats( $listing, $old ) );
		$this->assertSame( 8, $this->booked_seats( $listing, $full ) );
	}

	public function test_a_table_change_needs_a_bookable_time(): void {
		$id = $this->paid_table( $this->dining_listing(), $this->seating( 3 ), 2 );

		$this->assertSame( 'slot_invalid', $this->error_of( fn() => $this->move_table( $id, $this->day( 3 ) . ' 18:10:00' ) ) );
		$this->assertSame( 'same_dates', $this->error_of( fn() => $this->move_table( $id, $this->seating( 3 ) ) ) );
	}

	public function test_a_dispatch_decision_for_the_old_dates_goes_stale(): void {
		$staff = $this->user( 'qhs_staff' );
		update_user_meta( $staff, 'qhs_staff_ref', 'rowan' );
		$id      = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$version = (int) $this->booking( $id )['version'];

		$this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) );

		$this->assertSame( 'stale', Services::service()->apply_dispatch_decision( $id, $version, 'assigned', 'rowan', 'late' ) );
		$this->assertSame( 0, (int) $this->booking( $id )['staff_user_id'] );
	}

	public function test_a_change_clears_the_assigned_team_member(): void {
		$staff = $this->user( 'qhs_staff' );
		update_user_meta( $staff, 'qhs_staff_ref', 'rowan' );
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->assertSame( 'assigned', Services::service()->apply_dispatch_decision( $id, (int) $this->booking( $id )['version'], 'assigned', 'rowan', 'free' ) );

		$this->move_stay( $id, $this->day( 20 ), $this->day( 22 ) );

		$this->assertSame( 0, (int) $this->booking( $id )['staff_user_id'] );
	}
}
