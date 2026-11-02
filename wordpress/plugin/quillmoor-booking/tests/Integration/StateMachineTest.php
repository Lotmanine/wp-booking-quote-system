<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\IllegalTransition;
use Quillmoor\Booking\Services;

class StateMachineTest extends TestCase {

	public function test_happy_path_order(): void {
		$path = array( BookingState::Quote, BookingState::Hold, BookingState::DepositPaid, BookingState::Confirmed, BookingState::Assigned, BookingState::Completed );
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) {
			$this->assertTrue( $path[ $i ]->can_go_to( $path[ $i + 1 ] ), $path[ $i ]->value );
		}
	}

	/** @dataProvider illegal */
	public function test_illegal_moves_are_refused( BookingState $from, BookingState $to ): void {
		$this->expectException( IllegalTransition::class );
		Services::machine()->transition( 1, $from, $to, 'test' );
	}

	public static function illegal(): array {
		return array(
			'skip deposit'        => array( BookingState::Hold, BookingState::Confirmed ),
			'skip hold'           => array( BookingState::Quote, BookingState::DepositPaid ),
			'complete unassigned' => array( BookingState::Confirmed, BookingState::Completed ),
			'reopen completed'    => array( BookingState::Completed, BookingState::Assigned ),
			'reopen cancelled'    => array( BookingState::Cancelled, BookingState::Quote ),
			'cancel completed'    => array( BookingState::Completed, BookingState::Cancelled ),
		);
	}

	public function test_only_one_caller_wins_a_transition(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot(), 'test' );

		$first  = Services::machine()->transition( $id, BookingState::Hold, BookingState::Cancelled, 'one' );
		$second = Services::machine()->transition( $id, BookingState::Hold, BookingState::DepositPaid, 'two' );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertSame( 'cancelled', $this->state( $id ) );
	}

	public function test_every_transition_is_recorded_and_emits_an_event(): void {
		$id = $this->confirmed();

		$this->assertSame( 3, $this->row_count( 'booking_history', 'booking_id = ' . $id ) );
		foreach ( array( 'booking.hold', 'booking.deposit_paid', 'booking.confirmed' ) as $event ) {
			$this->assertSame( 1, $this->row_count( 'outbox', "event = '{$event}' AND booking_id = {$id}" ), $event );
		}
	}
}
