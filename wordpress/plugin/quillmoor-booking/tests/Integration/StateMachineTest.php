<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\IllegalTransition;
use Quillmoor\Booking\Services;

class StateMachineTest extends TestCase {

	private function error_of( callable $call ): string {
		try {
			$call();
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	/** @return string[] "from>to" pairs in order. */
	private function history( int $id ): array {
		return array_map(
			static fn( array $row ): string => $row['from_state'] . '>' . $row['to_state'],
			Services::bookings()->history( $id )
		);
	}

	private function mail_kinds( int $id ): array {
		return array_map( 'strval', $GLOBALS['wpdb']->get_col( 'SELECT kind FROM ' . Db::table( 'mail_log' ) . " WHERE booking_id = {$id} ORDER BY id" ) );
	}

	private function request_listing(): int {
		return $this->stay_listing( array( 'qm_instant_book' => false ) );
	}

	public function test_instant_book_goes_straight_to_confirmed(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$booking = $this->booking( $id );
		$this->assertSame( 'confirmed', $booking['state'] );
		$this->assertSame( 99, (int) $booking['order_id'] );
		$this->assertNull( $booking['hold_expires_at'] );
		$this->assertSame( array( 'quote>hold', 'hold>deposit_paid', 'deposit_paid>confirmed' ), $this->history( $id ) );
		$this->assertSame( 2, $this->row_count( 'night_claims', "booking_id = {$id} AND kind = 'booking' AND expires_at IS NULL" ) );
		$this->assertSame( array( 'account_setup', 'confirmation' ), $this->mail_kinds( $id ) );
		foreach ( array( 'booking.quote', 'booking.hold', 'booking.deposit_paid', 'booking.confirmed' ) as $event ) {
			$this->assertSame( 1, $this->row_count( 'outbox', "booking_id = {$id} AND event = '{$event}'" ), $event );
		}
	}

	public function test_an_instant_table_is_confirmed_and_its_seats_kept(): void {
		$listing = $this->dining_listing();
		$id      = $this->paid_table( $listing, $this->seating( 3 ), 4 );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 1, $this->row_count( 'table_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
		$this->assertSame( 4, $this->booked_seats( $listing, $this->seating( 3 ) ) );
	}

	public function test_without_instant_book_the_paid_hold_waits_for_the_host(): void {
		$id = $this->paid_stay( $this->request_listing(), $this->day( 10 ), $this->day( 12 ) );

		$booking = $this->booking( $id );
		$this->assertSame( 'requested', $booking['state'] );
		$this->assertNotEmpty( $booking['requested_at'] );
		$this->assertSame( array( 'quote>hold', 'hold>deposit_paid', 'deposit_paid>requested' ), $this->history( $id ) );
		$this->assertSame( array( 'account_setup', 'request' ), $this->mail_kinds( $id ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_the_host_confirms_a_request(): void {
		$id = $this->paid_stay( $this->request_listing(), $this->day( 10 ), $this->day( 12 ) );

		$confirmed = Services::service()->confirm( $id, 'host' );

		$this->assertSame( 'confirmed', $confirmed['state'] );
		$this->assertSame( array( 'account_setup', 'request', 'confirmation' ), $this->mail_kinds( $id ) );
		$this->assertSame( 'not_requested', $this->error_of( fn() => Services::service()->confirm( $id, 'host' ) ) );
	}

	public function test_the_host_declines_a_request_and_the_nights_are_freed(): void {
		$listing = $this->request_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$declined = Services::service()->decline( $id, 'host' );

		$this->assertSame( 'declined', $declined['state'] );
		$this->assertSame( 'flagged', $declined['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
		$this->assertSame( array( 'account_setup', 'request', 'declined' ), $this->mail_kinds( $id ) );
		$this->assertSame( 'not_requested', $this->error_of( fn() => Services::service()->confirm( $id, 'host' ) ) );
		$this->assertSame( 'hold', $this->state( $this->held_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'next@example.com' ) ) );
	}

	public function test_a_declined_table_frees_its_seats(): void {
		$listing = $this->dining_listing( array( 'qm_instant_book' => false ) );
		$id      = $this->paid_table( $listing, $this->seating( 3 ), 6 );
		$this->assertSame( 'requested', $this->state( $id ) );

		Services::service()->decline( $id, 'host' );

		$this->assertSame( 0, $this->booked_seats( $listing, $this->seating( 3 ) ) );
	}

	public function test_only_requests_can_be_declined(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'not_requested', $this->error_of( fn() => Services::service()->decline( $id, 'host' ) ) );
		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_unanswered_requests_expire_after_the_response_window(): void {
		$listing = $this->request_listing();
		$stale   = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$fresh   = $this->paid_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'b@example.com' );
		Services::bookings()->update( $stale, array( 'requested_at' => Db::now( -1 * ( Config::REQUEST_HOURS + 1 ) * HOUR_IN_SECONDS ) ) );

		$this->assertSame( 1, Services::service()->expire_requests() );

		$this->assertSame( 'declined', $this->state( $stale ) );
		$this->assertSame( 'requested', $this->state( $fresh ) );
		$this->assertSame( array(), $this->nights_of( $stale ) );
		$history = Services::bookings()->history( $stale );
		$this->assertSame( 'system', end( $history )['actor'] );
		$this->assertStringContainsString( 'no answer within 24 hours', end( $history )['reason'] );
		$this->assertSame( 0, Services::service()->expire_requests() );
	}

	public function test_guests_can_be_checked_in_from_36_hours_before_arrival(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'too_early', $this->error_of( fn() => Services::service()->arrive( $id, 'host' ) ) );

		$this->move_start( $id, 30 * HOUR_IN_SECONDS );
		$arrived = Services::service()->arrive( $id, 'host' );

		$this->assertSame( 'arrived', $arrived['state'] );
		$this->assertNotEmpty( $arrived['arrived_at'] );
		$this->assertSame( 'Checked in', BookingState::Arrived->label( 'stay' ) );
		$this->assertSame( 'Seated', BookingState::Arrived->label( 'dining' ) );
	}

	public function test_only_confirmed_bookings_arrive(): void {
		$id = $this->paid_stay( $this->request_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->move_start( $id, HOUR_IN_SECONDS );

		$this->assertSame( 'not_confirmed', $this->error_of( fn() => Services::service()->arrive( $id, 'host' ) ) );
	}

	public function test_completion_needs_an_arrival(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 'not_arrived', $this->error_of( fn() => Services::service()->complete( $id, 'host' ) ) );

		$this->move_start( $id, -1 * HOUR_IN_SECONDS );
		Services::service()->arrive( $id, 'host' );
		$completed = Services::service()->complete( $id, 'host' );

		$this->assertSame( 'completed', $completed['state'] );
		$this->assertNotEmpty( $completed['completed_at'] );
		$this->assertSame( 'not_cancellable', $this->error_of( fn() => Services::service()->cancel( $id, 'guest' ) ) );
	}

	public function test_a_no_show_waits_for_the_start_time_and_frees_the_nights(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->move_start( $id, 10 * MINUTE_IN_SECONDS );
		$this->assertSame( 'too_early', $this->error_of( fn() => Services::service()->no_show( $id, 'host' ) ) );

		$this->move_start( $id, -10 * MINUTE_IN_SECONDS );
		$this->assertSame( 'no_show', Services::service()->no_show( $id, 'host' )['state'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_a_table_no_show_frees_the_seats(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$id      = $this->paid_table( $listing, $slot, 5 );
		$this->move_start( $id, -1 * MINUTE_IN_SECONDS );

		Services::service()->no_show( $id, 'host' );

		$this->assertSame( 0, $this->booked_seats( $listing, $slot ) );
	}

	public function test_an_arrived_guest_cannot_be_a_no_show(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->move_start( $id, -1 * HOUR_IN_SECONDS );
		Services::service()->arrive( $id, 'host' );

		$this->assertSame( 'not_confirmed', $this->error_of( fn() => Services::service()->no_show( $id, 'host' ) ) );
	}

	public function test_a_quote_and_a_hold_can_be_cancelled_without_a_refund(): void {
		$listing = $this->stay_listing();
		$quote   = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );
		$held    = $this->held_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'other@example.com' );

		$this->assertSame( 'cancelled', Services::service()->cancel( $quote, 'guest' )['state'] );
		$cancelled = Services::service()->cancel( $held, 'guest' );

		$this->assertSame( 'cancelled', $cancelled['state'] );
		$this->assertSame( 'none', $cancelled['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $held ) );
		$this->assertSame( 'not_cancellable', $this->error_of( fn() => Services::service()->cancel( $held, 'guest' ) ) );
	}

	/** @dataProvider cancellations */
	public function test_cancellation_refund_follows_the_policy( string $policy, int $hours_before, bool $by_guest, string $expected ): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_cancellation' => $policy ) ), $this->day( 30 ), $this->day( 32 ) );
		$this->move_start( $id, $hours_before * HOUR_IN_SECONDS );

		$cancelled = Services::service()->cancel( $id, $by_guest ? 'guest' : 'host', $by_guest );

		$this->assertSame( 'cancelled', $cancelled['state'] );
		$this->assertSame( $expected, $cancelled['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public static function cancellations(): array {
		return array(
			'flexible, two days ahead'        => array( 'flexible', 48, true, 'flagged' ),
			'flexible, twelve hours ahead'    => array( 'flexible', 12, true, 'forfeited' ),
			'moderate, six days ahead'        => array( 'moderate', 6 * 24, true, 'flagged' ),
			'moderate, four days ahead'       => array( 'moderate', 4 * 24, true, 'forfeited' ),
			'strict, fifteen days ahead'      => array( 'strict', 15 * 24, true, 'flagged' ),
			'strict, ten days ahead'          => array( 'strict', 10 * 24, true, 'forfeited' ),
			'host cancels inside the cutoff'  => array( 'strict', 2, false, 'flagged' ),
			'host cancels well ahead'         => array( 'moderate', 30 * 24, false, 'flagged' ),
		);
	}

	public function test_a_requested_booking_can_be_cancelled_by_the_guest(): void {
		$listing = $this->request_listing();
		$id      = $this->paid_stay( $listing, $this->day( 30 ), $this->day( 32 ) );

		$this->assertSame( 'flagged', Services::service()->cancel( $id, 'guest' )['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $id ) );
	}

	public function test_a_cancelled_table_frees_its_seats(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$id      = $this->paid_table( $listing, $slot, 4 );

		Services::service()->cancel( $id, 'guest' );

		$this->assertSame( 0, $this->booked_seats( $listing, $slot ) );
	}

	public function test_illegal_transitions_throw(): void {
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->expectException( IllegalTransition::class );
		Services::machine()->transition( $id, BookingState::Quote, BookingState::Confirmed, 'test' );
	}

	public function test_a_stale_from_state_loses_the_race(): void {
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertFalse( Services::machine()->transition( $id, BookingState::Hold, BookingState::DepositPaid, 'test' ) );
		$this->assertTrue( Services::machine()->transition( $id, BookingState::Quote, BookingState::Cancelled, 'test' ) );
		$this->assertFalse( Services::machine()->transition( $id, BookingState::Quote, BookingState::Cancelled, 'test' ) );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND to_state = 'cancelled'" ) );
	}

	public function test_every_transition_bumps_the_version_and_fires_the_hook(): void {
		$seen = array();
		$spy  = static function ( int $booking_id, BookingState $from, BookingState $to ) use ( &$seen ): void {
			$seen[] = $from->value . '>' . $to->value;
		};
		add_action( 'quillmoor_booking_transitioned', $spy, 10, 3 );
		$id     = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$before = (int) $this->booking( $id )['version'];

		$this->hold( $id );

		remove_action( 'quillmoor_booking_transitioned', $spy, 10 );
		$this->assertSame( array( 'quote>hold' ), $seen );
		$this->assertSame( $before + 1, (int) $this->booking( $id )['version'] );
	}

	public function test_terminal_states_go_nowhere(): void {
		foreach ( array( BookingState::Completed, BookingState::NoShow, BookingState::Declined, BookingState::Cancelled ) as $state ) {
			$this->assertSame( array(), $state->next(), $state->value );
			$this->assertFalse( $state->is_live(), $state->value );
		}
		foreach ( array( BookingState::Hold, BookingState::DepositPaid, BookingState::Requested, BookingState::Confirmed, BookingState::Arrived ) as $state ) {
			$this->assertTrue( $state->is_live(), $state->value );
		}
		$this->assertFalse( BookingState::Quote->is_live() );
		$this->assertFalse( BookingState::Arrived->can_go_to( BookingState::Cancelled ) );
	}
}
