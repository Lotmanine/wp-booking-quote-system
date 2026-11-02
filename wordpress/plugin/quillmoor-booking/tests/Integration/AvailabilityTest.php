<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Domain\Availability;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Services;

class AvailabilityTest extends TestCase {

	private function range_error( int $listing_id, string $in, string $out ): string {
		try {
			Services::availability()->assert_stay_range( $this->load( $listing_id ), $in, $out );
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	/** @return string[] Slot starts on a day. */
	private function slot_starts( int $listing_id, string $day ): array {
		return array_column( Services::availability()->dining_slots( $this->load( $listing_id ), $day ), 'slot_start' );
	}

	public function test_stays_start_tomorrow(): void {
		$listing = $this->stay_listing();

		$this->assertSame( $this->day( 1 ), Availability::first_check_in() );
		$this->assertSame( 'dates_past', $this->range_error( $listing, $this->day( 0 ), $this->day( 2 ) ) );
		$this->assertSame( 'dates_past', $this->range_error( $listing, $this->day( -3 ), $this->day( -1 ) ) );
		$this->assertSame( '', $this->range_error( $listing, $this->day( 1 ), $this->day( 2 ) ) );
	}

	public function test_stays_close_after_the_booking_window(): void {
		$listing = $this->stay_listing();
		$last    = $this->day( Config::STAY_DAYS_AHEAD );

		$this->assertSame( '', $this->range_error( $listing, $this->plus( $last, -2 ), $last ) );
		$this->assertSame( 'dates_far', $this->range_error( $listing, $this->plus( $last, -1 ), $this->plus( $last, 1 ) ) );
	}

	public function test_stay_length_rules_apply_before_any_claim(): void {
		$listing = $this->stay_listing(
			array(
				'qm_min_nights' => 2,
				'qm_max_nights' => 3,
			)
		);
		$in = $this->day( 10 );

		$this->assertSame( 'stay_length', $this->range_error( $listing, $in, $this->plus( $in, 1 ) ) );
		$this->assertSame( 'stay_length', $this->range_error( $listing, $in, $this->plus( $in, 4 ) ) );
		$this->assertSame( 'dates_invalid', $this->range_error( $listing, '10/12/2027', $in ) );
	}

	public function test_stay_calendar_shows_booked_held_and_blocked_nights(): void {
		$listing = $this->stay_listing();
		$this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$this->held_stay( $listing, $this->day( 14 ), $this->day( 15 ), 'b@example.com' );
		Services::nights()->block( $listing, array( $this->day( 20 ) ) );

		$calendar = Services::availability()->stay_calendar( $this->load( $listing ), $this->day( 1 ), $this->day( 30 ) );

		$this->assertSame(
			array(
				$this->day( 10 ) => 'booked',
				$this->day( 11 ) => 'booked',
				$this->day( 14 ) => 'held',
				$this->day( 20 ) => 'blocked',
			),
			$calendar
		);
	}

	public function test_an_expired_hold_no_longer_shows_on_the_calendar(): void {
		$listing = $this->stay_listing();
		$id      = $this->held_stay( $listing, $this->day( 14 ), $this->day( 16 ) );
		$this->expire_hold( $id );

		$this->assertSame( array(), Services::availability()->stay_calendar( $this->load( $listing ), $this->day( 1 ), $this->day( 30 ) ) );
	}

	public function test_the_calendar_belongs_to_one_listing(): void {
		$one = $this->stay_listing();
		$two = $this->stay_listing();
		$this->paid_stay( $one, $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( array(), Services::availability()->stay_calendar( $this->load( $two ), $this->day( 1 ), $this->day( 30 ) ) );
	}

	public function test_blocked_nights_cannot_be_held(): void {
		$listing = $this->stay_listing();
		Services::nights()->block( $listing, array( $this->day( 11 ) ) );
		$id = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 13 ) );

		try {
			$this->hold( $id );
			$this->fail( 'A blocked night must refuse the hold.' );
		} catch ( BookingError $e ) {
			$this->assertSame( 'unavailable', $e->error_code );
		}
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_blocking_leaves_booked_nights_alone(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$refused = Services::nights()->block( $listing, array( $this->day( 9 ), $this->day( 10 ) ) );

		$this->assertSame( array( $this->day( 10 ) ), $refused );
		$this->assertSame( array( $this->day( 10 ), $this->day( 11 ) ), $this->nights_of( $id ) );
		$this->assertSame( 'block', Services::nights()->kind_of( $listing, $this->day( 9 ) ) );
	}

	public function test_blocking_twice_is_not_a_refusal_and_unblock_frees_the_night(): void {
		$listing = $this->stay_listing();
		Services::nights()->block( $listing, array( $this->day( 9 ) ) );

		$this->assertSame( array(), Services::nights()->block( $listing, array( $this->day( 9 ) ) ) );
		Services::nights()->unblock( $listing, array( $this->day( 9 ) ) );
		$this->assertSame( '', Services::nights()->kind_of( $listing, $this->day( 9 ) ) );
	}

	public function test_unblock_never_removes_a_booking(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 11 ) );

		Services::nights()->unblock( $listing, array( $this->day( 10 ) ) );

		$this->assertSame( array( $this->day( 10 ) ), $this->nights_of( $id ) );
	}

	public function test_an_expired_hold_can_be_blocked(): void {
		$listing = $this->stay_listing();
		$id      = $this->held_stay( $listing, $this->day( 10 ), $this->day( 11 ) );
		$this->expire_hold( $id );

		$this->assertSame( array(), Services::nights()->block( $listing, array( $this->day( 10 ) ) ) );
		$this->assertSame( 'block', Services::nights()->kind_of( $listing, $this->day( 10 ) ) );
	}

	public function test_dining_slots_follow_the_service_period(): void {
		$listing = $this->dining_listing();

		$slots = Services::availability()->dining_slots( $this->load( $listing ), $this->day( 3 ) );

		$this->assertSame( array( '18:00', '18:30', '19:00', '19:30', '20:00' ), array_column( $slots, 'time' ) );
		$this->assertSame( array( 'Dinner' ), array_values( array_unique( array_column( $slots, 'period' ) ) ) );
		$this->assertSame( array( 10 ), array_values( array_unique( array_column( $slots, 'seats_left' ) ) ) );
		$this->assertSame( $this->day( 3 ) . ' 18:00:00', $slots[0]['slot_start'] );
	}

	public function test_slot_length_sets_the_step(): void {
		$listing = $this->dining_listing( array( 'qm_slot_minutes' => '60' ) );

		$this->assertSame( array( '18:00', '19:00', '20:00' ), array_column( Services::availability()->dining_slots( $this->load( $listing ), $this->day( 3 ) ), 'time' ) );
	}

	public function test_periods_apply_only_on_their_days(): void {
		$saturday = $this->weekday( 6, 3 );
		$monday   = $this->weekday( 1, 3 );
		$listing  = $this->dining_listing(
			array(
				'qm_periods' => array(
					array(
						'label' => 'Lunch',
						'days'  => array( 'sat', 'sun' ),
						'start' => '12:00',
						'end'   => '12:30',
					),
					array(
						'label' => 'Dinner',
						'days'  => array( 'fri', 'sat' ),
						'start' => '19:00',
						'end'   => '19:00',
					),
				),
			)
		);

		$slots = Services::availability()->dining_slots( $this->load( $listing ), $saturday );

		$this->assertSame( array( '12:00', '12:30', '19:00' ), array_column( $slots, 'time' ) );
		$this->assertSame( array( 'Lunch', 'Lunch', 'Dinner' ), array_column( $slots, 'period' ) );
		$this->assertSame( array(), $this->slot_starts( $listing, $monday ) );
		$this->assertSame( 'no_service', Services::availability()->dining_calendar( $this->load( $listing ), $monday, $monday )[ $monday ] ?? '' );
		$this->assertArrayNotHasKey( $saturday, Services::availability()->dining_calendar( $this->load( $listing ), $saturday, $saturday ) );
	}

	public function test_booked_seats_reduce_what_is_left(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3, 1 );
		$this->paid_table( $listing, $slot, 4 );

		$slots = array_column( Services::availability()->dining_slots( $this->load( $listing ), $this->day( 3 ) ), 'seats_left', 'slot_start' );

		$this->assertSame( 6, $slots[ $slot ] );
		$this->assertSame( 10, $slots[ $this->seating( 3, 0 ) ] );
	}

	public function test_a_slot_that_cannot_seat_the_smallest_party_is_hidden(): void {
		$listing = $this->dining_listing(
			array(
				'qm_covers'    => 6,
				'qm_min_party' => 2,
			)
		);
		$slot    = $this->seating( 3 );
		$this->paid_table( $listing, $slot, 5 );

		$this->assertNotContains( $slot, $this->slot_starts( $listing, $this->day( 3 ) ) );
	}

	public function test_a_closed_day_has_no_slots_and_refuses_holds(): void {
		$listing = $this->dining_listing();
		$this->assertTrue( Services::tables()->close_day( $listing, $this->day( 4 ), $this->host() ) );

		$this->assertSame( array(), $this->slot_starts( $listing, $this->day( 4 ) ) );
		$this->assertSame( 'closed', Services::availability()->dining_calendar( $this->load( $listing ), $this->day( 4 ), $this->day( 4 ) )[ $this->day( 4 ) ] );
		$this->assertSame( array( $this->day( 4 ) ), Services::tables()->closed_days( $listing, $this->day( 1 ), $this->day( 9 ) ) );

		$id = $this->table_quote( $listing, $this->seating( 4 ) );
		try {
			$this->hold( $id );
			$this->fail( 'A closed day must refuse the hold.' );
		} catch ( BookingError $e ) {
			$this->assertSame( 'unavailable', $e->error_code );
		}
		$this->assertSame( 0, $this->booked_seats( $listing, $this->seating( 4 ) ) );
	}

	public function test_closing_a_day_with_bookings_is_refused(): void {
		$listing = $this->dining_listing();
		$this->paid_table( $listing, $this->seating( 4 ), 2 );

		$this->assertFalse( Services::tables()->close_day( $listing, $this->day( 4 ), $this->host() ) );
		$this->assertSame( array(), Services::tables()->closed_days( $listing, $this->day( 4 ), $this->day( 4 ) ) );
	}

	public function test_closing_after_a_slot_row_exists_closes_that_slot_too(): void {
		$listing = $this->dining_listing();
		$id      = $this->table_quote( $listing, $this->seating( 4 ) );
		$this->hold( $id );
		Services::service()->cancel( $id, 'test' );

		$this->assertSame( 0, $this->booked_seats( $listing, $this->seating( 4 ) ) );

		$this->assertTrue( Services::tables()->close_day( $listing, $this->day( 4 ), $this->host() ) );
		$this->expectException( BookingError::class );
		$this->hold( $this->table_quote( $listing, $this->seating( 4 ), 2, 'other@example.com' ) );
	}

	public function test_reopening_a_day_restores_its_slots(): void {
		$listing = $this->dining_listing();
		Services::tables()->close_day( $listing, $this->day( 4 ), $this->host() );

		Services::tables()->open_day( $listing, $this->day( 4 ) );

		$this->assertCount( 5, $this->slot_starts( $listing, $this->day( 4 ) ) );
		$this->assertSame( 'hold', $this->hold( $this->table_quote( $listing, $this->seating( 4 ) ) )['state'] );
	}

	public function test_dining_needs_two_hours_notice_and_closes_after_sixty_days(): void {
		$listing = $this->load(
			$this->dining_listing(
				array(
					'qm_periods' => array(
						array(
							'label' => 'All day',
							'days'  => array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
							'start' => '00:00',
							'end'   => '23:30',
						),
					),
				)
			)
		);
		$half_hour = static fn( int $seconds ): string => gmdate( 'Y-m-d H:i:00', (int) ( ceil( ( time() + $seconds ) / 1800 ) * 1800 ) );

		$this->assertFalse( Services::availability()->is_bookable_slot( $listing, $half_hour( HOUR_IN_SECONDS ) ) );
		$this->assertTrue( Services::availability()->is_bookable_slot( $listing, $half_hour( 3 * HOUR_IN_SECONDS ) ) );
		$this->assertTrue( Services::availability()->is_bookable_slot( $listing, $half_hour( 59 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( Services::availability()->is_bookable_slot( $listing, $half_hour( 61 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( Services::availability()->is_bookable_slot( $listing, $half_hour( -1 * DAY_IN_SECONDS ) ) );
	}

	/** @dataProvider bad_slots */
	public function test_slots_off_the_pattern_are_not_bookable( string $suffix ): void {
		$listing = $this->load( $this->dining_listing() );

		$this->assertFalse( Services::availability()->is_bookable_slot( $listing, $this->day( 3 ) . $suffix ) );
	}

	public static function bad_slots(): array {
		return array(
			'between slots'   => array( ' 18:15:00' ),
			'before service'  => array( ' 17:30:00' ),
			'after service'   => array( ' 20:30:00' ),
			'missing seconds' => array( ' 18:00' ),
			'not a time'      => array( ' dinner' ),
		);
	}

	public function test_a_stay_has_no_dining_slots(): void {
		$listing = $this->load( $this->stay_listing() );

		$this->assertFalse( Services::availability()->is_bookable_slot( $listing, $this->seating( 3 ) ) );
		$this->assertSame( array(), Services::availability()->dining_slots( $listing, $this->day( 3 ) ) );
	}

	public function test_a_table_quote_on_an_unbookable_time_is_refused(): void {
		$this->expectException( BookingError::class );
		$this->table_quote( $this->dining_listing(), $this->day( 3 ) . ' 18:15:00' );
	}
}
