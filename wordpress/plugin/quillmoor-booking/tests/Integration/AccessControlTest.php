<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;

class AccessControlTest extends TestCase {

	private function token( int $id ): string {
		return BookingPolicy::token_for( Services::bookings()->find( $id ) );
	}

	public function test_a_customer_cannot_read_another_booking(): void {
		$owner    = $this->customer_user( 'owner@example.com' );
		$intruder = $this->customer_user( 'intruder@example.com' );
		$id       = $this->confirmed( 'owner@example.com', $owner );

		wp_set_current_user( $intruder );
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$id}" )->get_status() );

		wp_set_current_user( $owner );
		$this->assertSame( 200, $this->rest( 'GET', "/bookings/{$id}" )->get_status() );
	}

	public function test_a_customer_list_holds_only_their_own_bookings(): void {
		$owner    = $this->customer_user( 'owner@example.com' );
		$intruder = $this->customer_user( 'intruder@example.com' );
		$this->confirmed( 'owner@example.com', $owner );

		wp_set_current_user( $intruder );
		$this->assertSame( array(), $this->rest( 'GET', '/bookings' )->get_data()['bookings'] );
	}

	/** @dataProvider writes */
	public function test_a_customer_cannot_change_another_booking( string $path ): void {
		$owner    = $this->customer_user( 'owner@example.com' );
		$intruder = $this->customer_user( 'intruder@example.com' );
		$id       = $this->confirmed( 'owner@example.com', $owner );
		$before   = Services::bookings()->find( $id );

		wp_set_current_user( $intruder );
		$response = $this->rest( 'POST', "/bookings/{$id}/{$path}", array( 'slot_start' => $this->far_slot( 1 ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( $before, Services::bookings()->find( $id ) );
	}

	public static function writes(): array {
		return array(
			'reschedule' => array( 'reschedule' ),
			'cancel'     => array( 'cancel' ),
		);
	}

	public function test_guest_without_a_token_is_refused(): void {
		$id = $this->quote();
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$id}" )->get_status() );
	}

	public function test_a_token_opens_only_its_own_booking(): void {
		$first  = $this->quote( 'a@example.com' );
		$second = $this->quote( 'b@example.com' );
		$token  = $this->token( $first );

		$this->assertSame( 200, $this->rest( 'GET', "/bookings/{$first}", array(), array( 'X-QHS-Token' => $token ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$second}", array(), array( 'X-QHS-Token' => $token ) )->get_status() );
	}

	public function test_a_wrong_token_is_refused(): void {
		$id = $this->quote();
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$id}", array(), array( 'X-QHS-Token' => str_repeat( 'a', 64 ) ) )->get_status() );
	}

	public function test_hold_needs_ownership_of_the_quote(): void {
		$id       = $this->quote();
		$response = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $id,
				'slot_start'      => $this->slot(),
				'turnstile_token' => 'x',
			)
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_pdf_is_refused_to_other_people(): void {
		$id = $this->quote();
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$id}/pdf/quote" )->get_status() );
	}

	public function test_a_token_does_not_leak_internal_fields(): void {
		$id   = $this->quote();
		$data = $this->rest( 'GET', "/bookings/{$id}", array(), array( 'X-QHS-Token' => $this->token( $id ) ) )->get_data();

		$this->assertArrayNotHasKey( 'access_key', $data );
		$this->assertArrayNotHasKey( 'customer_id', $data );
	}

	public function test_staff_only_see_jobs_assigned_to_them(): void {
		$mine  = $this->user( 'qhs_staff' );
		$other = $this->user( 'qhs_staff' );
		$job   = $this->assigned( $mine );

		wp_set_current_user( $other );
		$this->assertSame( array(), $this->rest( 'GET', '/staff/schedule' )->get_data()['jobs'] );
		$this->assertSame( 404, $this->rest( 'POST', "/staff/jobs/{$job}/status", array( 'status' => 'completed' ) )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$job}" )->get_status() );
		$this->assertSame( 'assigned', $this->state( $job ) );

		wp_set_current_user( $mine );
		$this->assertCount( 1, $this->rest( 'GET', '/staff/schedule' )->get_data()['jobs'] );
		$this->assertSame( 200, $this->rest( 'POST', "/staff/jobs/{$job}/status", array( 'status' => 'completed' ) )->get_status() );
		$this->assertSame( 'completed', $this->state( $job ) );
	}

	public function test_customers_cannot_reach_staff_routes(): void {
		wp_set_current_user( $this->customer_user( 'c@example.com' ) );
		$this->assertSame( 403, $this->rest( 'GET', '/staff/schedule' )->get_status() );
	}

	public function test_staff_cannot_reschedule_or_cancel(): void {
		$staff = $this->user( 'qhs_staff' );
		$job   = $this->assigned( $staff );

		wp_set_current_user( $staff );
		$this->assertSame( 404, $this->rest( 'POST', "/bookings/{$job}/cancel" )->get_status() );
	}

	public function test_managers_can_see_every_booking(): void {
		$id = $this->quote();
		wp_set_current_user( $this->user( 'qhs_manager' ) );
		$this->assertSame( 200, $this->rest( 'GET', "/bookings/{$id}" )->get_status() );
	}

	public function test_customer_can_reschedule_and_cancel_their_own(): void {
		$owner = $this->customer_user( 'owner@example.com' );
		$first = $this->far_slot( 0 );
		$next  = $this->far_slot( 1 );
		$id    = $this->confirmed( 'owner@example.com', $owner, $first );

		wp_set_current_user( $owner );
		$moved = $this->rest( 'POST', "/bookings/{$id}/reschedule", array( 'slot_start' => $next ) );
		$this->assertSame( 200, $moved->get_status() );
		$this->assertSame( $next, $moved->get_data()['slot_start'] );
		$this->assertSame( 0, $this->row_count( 'slot_claims', "slot_start = '{$first}'" ) );
		$this->assertSame( 1, $this->row_count( 'slot_claims', "slot_start = '{$next}'" ) );

		$this->assertSame( 200, $this->rest( 'POST', "/bookings/{$id}/cancel" )->get_status() );
		$this->assertSame( 'cancelled', $this->state( $id ) );
		$this->assertSame( 0, $this->row_count( 'slot_claims', 'booking_id = ' . $id ) );
	}

	public function test_changes_close_inside_the_cutoff(): void {
		$owner = $this->customer_user( 'owner@example.com' );
		$id    = $this->confirmed( 'owner@example.com', $owner );
		Services::bookings()->update( $id, array( 'slot_start' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );

		wp_set_current_user( $owner );
		$response = $this->rest( 'POST', "/bookings/{$id}/cancel" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'too_late', $response->get_data()['code'] );
	}

	public function test_consent_route_needs_a_session(): void {
		$this->assertSame( 401, $this->rest( 'POST', '/consent', array( 'marketing_consent' => true ) )->get_status() );
	}
}
