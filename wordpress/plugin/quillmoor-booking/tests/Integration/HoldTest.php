<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Services;

class HoldTest extends TestCase {

	private function hold_error( int $booking_id ): string {
		try {
			$this->hold( $booking_id );
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	public function test_a_stay_hold_claims_every_night_for_ten_minutes(): void {
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 13 ) );

		$held = $this->hold( $id );

		$this->assertSame( 'hold', $held['state'] );
		$this->assertSame( 10, Config::HOLD_MINUTES );
		$this->assertEqualsWithDelta( time() + 600, strtotime( $held['hold_expires_at'] . ' UTC' ), 5 );
		$this->assertSame( array( $this->day( 10 ), $this->day( 11 ), $this->day( 12 ) ), $this->nights_of( $id ) );
		$this->assertSame( 3, $this->row_count( 'night_claims', "booking_id = {$id} AND kind = 'hold' AND expires_at IS NOT NULL" ) );
		$this->assertSame( 1, $this->row_count( 'outbox', "booking_id = {$id} AND event = 'booking.hold'" ) );
	}

	public function test_an_overlapping_stay_is_refused_and_writes_nothing(): void {
		$listing = $this->stay_listing();
		$this->held_stay( $listing, $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$second = $this->stay_quote( $listing, $this->day( 12 ), $this->day( 16 ), 2, array(), 'b@example.com' );

		$this->assertSame( 'unavailable', $this->hold_error( $second ) );
		$this->assertSame( 'quote', $this->state( $second ) );
		$this->assertSame( array(), $this->nights_of( $second ) );
		$this->assertSame( 3, $this->row_count( 'night_claims' ) );
	}

	public function test_a_stay_inside_another_is_refused(): void {
		$listing = $this->stay_listing();
		$this->paid_stay( $listing, $this->day( 10 ), $this->day( 15 ), 'a@example.com' );

		$this->assertSame( 'unavailable', $this->hold_error( $this->stay_quote( $listing, $this->day( 11 ), $this->day( 12 ), 2, array(), 'b@example.com' ) ) );
		$this->assertSame( 'unavailable', $this->hold_error( $this->stay_quote( $listing, $this->day( 8 ), $this->day( 17 ), 2, array(), 'c@example.com' ) ) );
	}

	public function test_adjacent_stays_share_the_changeover_day(): void {
		$listing = $this->stay_listing();
		$first   = $this->held_stay( $listing, $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$before  = $this->held_stay( $listing, $this->day( 7 ), $this->day( 10 ), 'b@example.com' );
		$after   = $this->held_stay( $listing, $this->day( 13 ), $this->day( 14 ), 'c@example.com' );

		$this->assertSame( 'hold', $this->state( $first ) );
		$this->assertSame( 'hold', $this->state( $before ) );
		$this->assertSame( 'hold', $this->state( $after ) );
		$this->assertSame( 7, $this->row_count( 'night_claims' ) );
	}

	public function test_the_same_nights_on_another_listing_are_free(): void {
		$this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ), 'a@example.com' );

		$this->assertSame( 'hold', $this->state( $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ), 'b@example.com' ) ) );
	}

	public function test_the_database_key_refuses_a_second_claim_on_a_night(): void {
		$listing = $this->stay_listing();

		$this->assertTrue( Services::nights()->acquire( $listing, array( $this->day( 20 ), $this->day( 21 ) ), 1, null ) );
		$this->assertFalse( Services::nights()->acquire( $listing, array( $this->day( 21 ), $this->day( 22 ) ), 2, null ) );
		$this->assertSame( 0, $this->row_count( 'night_claims', 'booking_id = 2' ) );
		$this->assertFalse( Services::nights()->acquire( $listing, array(), 3, null ) );
	}

	public function test_an_expired_stay_hold_is_released_by_the_sweep(): void {
		$listing = $this->stay_listing();
		$id      = $this->held_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		$this->expire_hold( $id );

		$this->assertSame( 1, Services::service()->release_expired_holds() );

		$booking = $this->booking( $id );
		$this->assertSame( 'quote', $booking['state'] );
		$this->assertNull( $booking['hold_expires_at'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
		$this->assertSame( 1, $this->row_count( 'outbox', "booking_id = {$id} AND event = 'booking.hold_expired'" ) );
		$this->assertSame( 0, Services::service()->release_expired_holds() );
	}

	public function test_live_holds_are_left_alone_by_the_sweep(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 0, Services::service()->release_expired_holds() );
		$this->assertSame( 'hold', $this->state( $id ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_expired_nights_can_be_taken_before_the_sweep_runs(): void {
		$listing = $this->stay_listing();
		$first   = $this->held_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$this->expire_hold( $first );

		$second = $this->held_stay( $listing, $this->day( 11 ), $this->day( 13 ), 'b@example.com' );

		$this->assertSame( 'hold', $this->state( $second ) );
		$this->assertSame( array( $this->day( 11 ), $this->day( 12 ) ), $this->nights_of( $second ) );
		// The first booking keeps the night nobody took until the sweep frees it.
		$this->assertSame( array( $this->day( 10 ) ), $this->nights_of( $first ) );
		Services::service()->release_expired_holds();
		$this->assertSame( array(), $this->nights_of( $first ) );
		$this->assertSame( array( $this->day( 11 ), $this->day( 12 ) ), $this->nights_of( $second ) );
	}

	public function test_a_released_quote_can_be_held_again(): void {
		$listing = $this->stay_listing();
		$id      = $this->held_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		$this->expire_hold( $id );
		Services::service()->release_expired_holds();

		$this->assertSame( 'hold', $this->hold( $id )['state'] );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_a_hold_cannot_be_held_twice(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'not_quotable', $this->hold_error( $id ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_an_old_quote_cannot_be_held(): void {
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		Services::bookings()->update_quote( (int) $this->booking( $id )['quote_id'], array( 'valid_until' => '2020-01-01 00:00:00' ) );

		$this->assertSame( 'quote_expired', $this->hold_error( $id ) );
	}

	public function test_the_hold_checks_the_listing_rules_again(): void {
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );
		$this->set_fields( $listing, array( 'qm_min_nights' => 5 ) );

		$this->assertSame( 'stay_length', $this->hold_error( $id ) );
	}

	public function test_an_unpublished_listing_cannot_be_held(): void {
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );
		wp_update_post(
			array(
				'ID'          => $listing,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'not_found', $this->hold_error( $id ) );
	}

	public function test_a_draft_listing_cannot_be_quoted(): void {
		$this->expectException( BookingError::class );
		$this->stay_quote( $this->stay_listing( array(), 0, 'draft' ), $this->day( 10 ), $this->day( 12 ) );
	}

	public function test_a_guest_cannot_hoard_holds(): void {
		$listing = $this->stay_listing();
		for ( $i = 0; $i < Config::MAX_ACTIVE_HOLDS; $i++ ) {
			$this->held_stay( $listing, $this->day( 10 + 3 * $i ), $this->day( 11 + 3 * $i ), 'hoard@example.com' );
		}

		$extra = $this->stay_quote( $listing, $this->day( 40 ), $this->day( 41 ), 2, array(), 'hoard@example.com' );
		$this->assertSame( 'too_many_holds', $this->hold_error( $extra ) );
		$this->assertSame( array(), $this->nights_of( $extra ) );

		$other = $this->stay_quote( $listing, $this->day( 40 ), $this->day( 41 ), 2, array(), 'other@example.com' );
		$this->assertSame( '', $this->hold_error( $other ) );
	}

	public function test_a_table_hold_takes_seats_for_the_party(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$id      = $this->table_quote( $listing, $slot, 4 );

		$held = $this->hold( $id );

		$this->assertSame( 'hold', $held['state'] );
		$this->assertSame( 4, $this->booked_seats( $listing, $slot ) );
		$this->assertSame( 1, $this->row_count( 'table_claims', "booking_id = {$id} AND covers = 4 AND expires_at IS NOT NULL" ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) strtotime( $slot . ' UTC' ) + 90 * MINUTE_IN_SECONDS ), $held['slot_end'] );
	}

	public function test_a_full_slot_refuses_the_next_party(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$this->hold( $this->table_quote( $listing, $slot, 4, 'a@example.com' ) );
		$this->hold( $this->table_quote( $listing, $slot, 4, 'b@example.com' ) );
		$third = $this->table_quote( $listing, $slot, 4, 'c@example.com' );

		$this->assertSame( 'unavailable', $this->hold_error( $third ) );
		$this->assertSame( 8, $this->booked_seats( $listing, $slot ) );
		$this->assertSame( 0, $this->row_count( 'table_claims', "booking_id = {$third}" ) );

		$this->assertSame( '', $this->hold_error( $this->table_quote( $listing, $slot, 2, 'd@example.com' ) ) );
		$this->assertSame( 10, $this->booked_seats( $listing, $slot ) );
		$this->assertSame( 'unavailable', $this->hold_error( $this->table_quote( $listing, $slot, 1, 'e@example.com' ) ) );
	}

	public function test_other_slots_keep_their_own_capacity(): void {
		$listing = $this->dining_listing();
		$this->hold( $this->table_quote( $listing, $this->seating( 3, 0 ), 8, 'a@example.com' ) );

		$this->assertSame( '', $this->hold_error( $this->table_quote( $listing, $this->seating( 3, 1 ), 8, 'b@example.com' ) ) );
	}

	public function test_an_expired_table_hold_gives_its_seats_back_before_the_sweep(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$first   = $this->table_quote( $listing, $slot, 8, 'a@example.com' );
		$this->hold( $first );
		$this->expire_hold( $first );

		$second = $this->table_quote( $listing, $slot, 8, 'b@example.com' );
		$this->hold( $second );

		$this->assertSame( 8, $this->booked_seats( $listing, $slot ) );
		$this->assertSame( 0, $this->row_count( 'table_claims', "booking_id = {$first}" ) );
		// The sweep still moves the first booking back to a quote and gives nothing back twice.
		$this->assertSame( 1, Services::service()->release_expired_holds() );
		$this->assertSame( 'quote', $this->state( $first ) );
		$this->assertSame( 8, $this->booked_seats( $listing, $slot ) );
	}

	public function test_the_sweep_releases_expired_table_holds(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$id      = $this->table_quote( $listing, $slot, 6 );
		$this->hold( $id );
		$this->expire_hold( $id );

		$this->assertSame( 1, Services::service()->release_expired_holds() );

		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertSame( 0, $this->booked_seats( $listing, $slot ) );
		$this->assertSame( 0, $this->row_count( 'table_claims' ) );
	}

	public function test_seats_never_go_below_zero_when_released_twice(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$id      = $this->table_quote( $listing, $slot, 3 );
		$this->hold( $id );

		Services::tables()->release( $id );
		Services::tables()->release( $id );

		$this->assertSame( 0, $this->booked_seats( $listing, $slot ) );
	}

	public function test_a_lower_seat_count_applies_to_new_holds(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$this->hold( $this->table_quote( $listing, $slot, 4, 'a@example.com' ) );
		$this->set_fields( $listing, array( 'qm_covers' => 6 ) );

		$this->assertSame( 'unavailable', $this->hold_error( $this->table_quote( $listing, $slot, 3, 'b@example.com' ) ) );
		$this->assertSame( '', $this->hold_error( $this->table_quote( $listing, $slot, 2, 'c@example.com' ) ) );
		$this->assertSame( 6, (int) $GLOBALS['wpdb']->get_var( 'SELECT capacity FROM ' . Db::table( 'table_slots' ) . " WHERE listing_id = {$listing}" ) );
	}
}
