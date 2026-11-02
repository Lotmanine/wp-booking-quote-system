<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Services;

class HoldTest extends TestCase {

	private function expire( int $id ): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Db::table( 'bookings' ) . " SET hold_expires_at = '2020-01-01 00:00:00' WHERE id = {$id}" );
		$wpdb->query( 'UPDATE ' . Db::table( 'slot_claims' ) . " SET expires_at = '2020-01-01 00:00:00' WHERE booking_id = {$id}" );
	}

	public function test_hold_lasts_ten_minutes(): void {
		$held = Services::service()->hold( $this->quote(), $this->slot(), 'test' );

		$this->assertSame( 'hold', $held['state'] );
		$this->assertEqualsWithDelta( time() + 600, strtotime( $held['hold_expires_at'] . ' UTC' ), 5 );
		$this->assertSame( 10, Config::HOLD_MINUTES );
	}

	public function test_a_held_slot_cannot_be_taken(): void {
		$slot = $this->slot();
		Services::service()->hold( $this->quote( 'a@example.com' ), $slot, 'test' );

		try {
			Services::service()->hold( $this->quote( 'b@example.com' ), $slot, 'test' );
			$this->fail( 'Second hold should fail.' );
		} catch ( BookingError $e ) {
			$this->assertSame( 'slot_taken', $e->error_code );
		}
		$this->assertSame( 1, $this->row_count( 'slot_claims' ) );
	}

	public function test_capacity_two_area_takes_exactly_two(): void {
		$slot = $this->slot( 0, 'central' );
		Services::service()->hold( $this->quote( 'a@example.com', 0, 'central' ), $slot, 'test' );
		Services::service()->hold( $this->quote( 'b@example.com', 0, 'central' ), $slot, 'test' );

		$this->expectException( BookingError::class );
		Services::service()->hold( $this->quote( 'c@example.com', 0, 'central' ), $slot, 'test' );
	}

	public function test_expired_hold_is_released_and_the_slot_reopens(): void {
		$slot = $this->slot();
		$id   = $this->quote();
		Services::service()->hold( $id, $slot, 'test' );
		$this->assertNotContains( $slot, array_merge( ...array_values( Services::availability()->open_slots( 'north' ) ) ) );

		$this->expire( $id );
		$released = Services::service()->release_expired_holds();

		$this->assertSame( 1, $released );
		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertSame( 0, $this->row_count( 'slot_claims', 'booking_id = ' . $id ) );
		$this->assertContains( $slot, array_merge( ...array_values( Services::availability()->open_slots( 'north' ) ) ) );
		$this->assertSame( 1, $this->row_count( 'outbox', "event = 'booking.hold_expired'" ) );
	}

	public function test_unexpired_holds_are_left_alone(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot(), 'test' );

		$this->assertSame( 0, Services::service()->release_expired_holds() );
		$this->assertSame( 'hold', $this->state( $id ) );
	}

	public function test_expired_claim_can_be_taken_before_the_sweep_runs(): void {
		$slot  = $this->slot();
		$first = $this->quote( 'a@example.com' );
		Services::service()->hold( $first, $slot, 'test' );
		$this->expire( $first );

		$held = Services::service()->hold( $this->quote( 'b@example.com' ), $slot, 'test' );

		$this->assertSame( 'hold', $held['state'] );
		$this->assertSame( 1, $this->row_count( 'slot_claims' ) );
	}

	public function test_expired_booking_can_pick_a_new_slot(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot( 0 ), 'test' );
		$this->expire( $id );
		Services::service()->release_expired_holds();

		$held = Services::service()->hold( $id, $this->slot( 1 ), 'test' );

		$this->assertSame( 'hold', $held['state'] );
	}

	/** @dataProvider bad_slots */
	public function test_out_of_pattern_slots_are_refused( string $slot ): void {
		$this->expectException( BookingError::class );
		Services::service()->hold( $this->quote(), $slot, 'test' );
	}

	public static function bad_slots(): array {
		return array(
			'past'        => array( '2020-01-06 09:00:00' ),
			'bad format'  => array( 'tomorrow' ),
			'off pattern' => array( gmdate( 'Y-m-d 10:15:00', time() + 3 * DAY_IN_SECONDS ) ),
			'too far'     => array( gmdate( 'Y-m-d 09:00:00', time() + 60 * DAY_IN_SECONDS ) ),
		);
	}

	public function test_a_customer_cannot_hoard_slots(): void {
		for ( $i = 0; $i < Config::MAX_ACTIVE_HOLDS; $i++ ) {
			Services::service()->hold( $this->quote(), $this->slot(), 'test' );
		}

		try {
			Services::service()->hold( $this->quote(), $this->slot(), 'test' );
			$this->fail( 'Should be limited.' );
		} catch ( BookingError $e ) {
			$this->assertSame( 'too_many_holds', $e->error_code );
		}
	}

	public function test_the_database_key_itself_refuses_a_duplicate_claim(): void {
		global $wpdb;
		$slot   = $this->slot();
		$table  = Db::table( 'slot_claims' );
		$insert = $wpdb->prepare( "INSERT IGNORE INTO %i (area, slot_start, unit, booking_id, created_at) VALUES ('north', %s, 1, 1, %s)", $table, $slot, Db::now() );

		$this->assertSame( 1, $wpdb->query( $insert ) );
		$this->assertSame( 0, $wpdb->query( $insert ) );
	}
}
