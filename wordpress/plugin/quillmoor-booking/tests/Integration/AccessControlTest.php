<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Security\Actor;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;

class AccessControlTest extends TestCase {

	private function show( int $id, array $params = array(), array $headers = array() ): int {
		return $this->rest( 'GET', "/bookings/{$id}", $params, $headers )->get_status();
	}

	public function test_the_guest_link_token_opens_the_booking(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 200, $this->show( $id, array( 'token' => $this->token( $id ) ) ) );
		$this->assertSame( 200, $this->show( $id, array(), array( 'X-QHS-Token' => $this->token( $id ) ) ) );
	}

	public function test_no_token_or_another_bookings_token_looks_like_a_missing_booking(): void {
		$listing = $this->stay_listing();
		$mine    = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'a@example.com' );
		$theirs  = $this->paid_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'b@example.com' );

		$denied  = $this->rest( 'GET', "/bookings/{$theirs}", array( 'token' => $this->token( $mine ) ) );
		$missing = $this->rest( 'GET', '/bookings/999999', array( 'token' => $this->token( $mine ) ) );

		$this->assertSame( 404, $this->show( $mine ) );
		$this->assertSame( 404, $denied->get_status() );
		$this->assertSame( $missing->get_status(), $denied->get_status() );
		$this->assertSame( $this->code( $missing ), $this->code( $denied ) );
		$this->assertSame( 404, $this->show( $mine, array( 'token' => str_repeat( '0', 64 ) ) ) );
	}

	public function test_a_signed_in_guest_sees_only_their_bookings(): void {
		$listing = $this->stay_listing();
		$owner   = $this->customer_user( 'owner@example.com' );
		$other   = $this->customer_user( 'other@example.com' );
		$mine    = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ), 'owner@example.com', $owner );
		$theirs  = $this->paid_stay( $listing, $this->day( 20 ), $this->day( 22 ), 'other@example.com', $other );

		wp_set_current_user( $owner );

		$this->assertSame( 200, $this->show( $mine ) );
		$this->assertSame( 404, $this->show( $theirs ) );
		$list = $this->rest( 'GET', '/bookings' )->get_data();
		$this->assertSame( array( $mine ), array_column( $list['bookings'], 'id' ) );
		$this->assertSame( 'Linked Person', $list['name'] );
	}

	public function test_the_trip_list_needs_a_sign_in(): void {
		$this->assertSame( 401, $this->rest( 'GET', '/bookings' )->get_status() );
		$this->assertSame( 401, $this->rest( 'POST', '/consent', array( 'marketing_consent' => true ) )->get_status() );
	}

	public function test_the_host_of_the_listing_can_view_its_bookings(): void {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );

		wp_set_current_user( $host );

		$this->assertSame( 200, $this->show( $id ) );
		$this->assertSame( $host, (int) $this->booking( $id )['host_user_id'] );
	}

	public function test_a_host_of_another_listing_gets_404(): void {
		$id = $this->paid_stay( $this->stay_listing( array(), $this->make_host() ), $this->day( 10 ), $this->day( 12 ) );

		wp_set_current_user( $this->make_host() );

		$this->assertSame( 404, $this->show( $id ) );
		$this->assertSame( 404, $this->rest( 'POST', "/bookings/{$id}/cancel" )->get_status() );
		$this->assertSame( 'confirmed', $this->state( $id ) );
	}

	public function test_the_host_cannot_use_the_guest_routes_to_change_a_booking(): void {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );

		wp_set_current_user( $host );

		$this->assertSame( 404, $this->rest( 'POST', "/bookings/{$id}/cancel" )->get_status() );
		$this->assertSame(
			404,
			$this->rest(
				'POST',
				"/bookings/{$id}/change",
				array(
					'check_in'  => $this->day( 20 ),
					'check_out' => $this->day( 22 ),
				)
			)->get_status()
		);
		$this->assertSame( 'confirmed', $this->state( $id ) );
	}

	public function test_the_manager_sees_and_cancels_any_booking_with_a_refund(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_cancellation' => 'strict' ) ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $this->user( Roles::MANAGER ) );

		$this->assertSame( 200, $this->show( $id ) );
		$response = $this->rest( 'POST', "/bookings/{$id}/cancel" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $this->state( $id ) );
		$this->assertSame( 'flagged', $this->booking( $id )['refund_status'] );
	}

	public function test_only_the_assigned_team_member_sees_the_booking(): void {
		$assigned = $this->user( Roles::STAFF );
		$other    = $this->user( Roles::STAFF );
		$id       = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		Services::bookings()->update( $id, array( 'staff_user_id' => $assigned ) );

		wp_set_current_user( $assigned );
		$this->assertSame( 200, $this->show( $id ) );
		$this->assertSame( 404, $this->rest( 'POST', "/bookings/{$id}/cancel" )->get_status() );

		wp_set_current_user( $other );
		$this->assertSame( 404, $this->show( $id ) );
	}

	public function test_a_guest_cancels_with_the_token(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 30 ), $this->day( 32 ) );

		$response = $this->rest( 'POST', "/bookings/{$id}/cancel", array( 'token' => $this->token( $id ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $response->get_data()['state'] );
		$history = Services::bookings()->history( $id );
		$this->assertSame( 'guest', end( $history )['actor'] );
	}

	public function test_another_guest_cannot_hold_my_quote(): void {
		$listing = $this->stay_listing();
		$mine    = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ), 2, array(), 'a@example.com' );
		$theirs  = $this->stay_quote( $listing, $this->day( 20 ), $this->day( 22 ), 2, array(), 'b@example.com' );

		$response = $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $mine,
				'token'           => $this->token( $theirs ),
				'turnstile_token' => 'ok',
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'quote', $this->state( $mine ) );
	}

	public function test_the_public_view_hides_internal_fields_and_the_address_until_confirmed(): void {
		$listing = $this->stay_listing( array( 'qm_instant_book' => false ) );
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		$token   = $this->token( $id );

		$waiting = $this->rest( 'GET', "/bookings/{$id}", array( 'token' => $token ) )->get_data();
		Services::service()->confirm( $id, 'host' );
		$known = $this->rest( 'GET', "/bookings/{$id}", array( 'token' => $token ) )->get_data();

		$this->assertSame( '', $waiting['address'] );
		$this->assertSame( '1 Test Lane', $known['address'] );
		foreach ( array( 'access_key', 'customer_id', 'order_id', 'staff_user_id', 'host_user_id', 'version', 'email' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $known, $key );
		}
		$this->assertStringNotContainsString( $this->booking( $id )['access_key'], (string) wp_json_encode( $known ) );
		$this->assertSame( array( 'Waiting for the host', 'Confirmed' ), array_slice( array_column( $known['history'], 'to' ), -2 ) );
	}

	public function test_listing_management_follows_ownership(): void {
		$host    = $this->make_host();
		$other   = $this->make_host();
		$manager = $this->user( Roles::MANAGER );
		$guest   = $this->user( Roles::CUSTOMER );
		$listing = $this->stay_listing( array(), $host );

		$this->assertTrue( BookingPolicy::can_manage_listing( $host, $listing ) );
		$this->assertFalse( BookingPolicy::can_manage_listing( $other, $listing ) );
		$this->assertTrue( BookingPolicy::can_manage_listing( $manager, $listing ) );
		$this->assertFalse( BookingPolicy::can_manage_listing( $guest, $listing ) );
		$this->assertFalse( BookingPolicy::can_manage_listing( 0, $listing ) );

		$this->assertTrue( user_can( $host, 'edit_post', $listing ) );
		$this->assertFalse( user_can( $other, 'edit_post', $listing ) );
		$this->assertTrue( user_can( $manager, 'edit_post', $listing ) );
		$this->assertFalse( user_can( $guest, 'edit_post', $listing ) );
	}

	public function test_policy_roles(): void {
		$host  = $this->make_host();
		$id    = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );
		$row   = $this->booking( $id );
		$guest = new Actor( 0, $this->token( $id ) );

		$this->assertTrue( Services::policy()->can_change( $guest, $row ) );
		$this->assertFalse( Services::policy()->can_manage( $guest, $row ) );
		$this->assertTrue( Services::policy()->can_manage( new Actor( $host ), $row ) );
		$this->assertFalse( Services::policy()->can_change( new Actor( $host ), $row ) );
		$this->assertFalse( Services::policy()->can_manage( new Actor( $this->make_host() ), $row ) );
		$this->assertTrue( Services::policy()->can_manage( new Actor( $this->user( Roles::MANAGER ) ), $row ) );
		$this->assertSame( 'guest', $guest->label() );
		$this->assertSame( 'user:' . $host, ( new Actor( $host ) )->label() );
	}

	public function test_a_host_who_lost_the_role_loses_access(): void {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );
		( new \WP_User( $host ) )->set_role( Roles::CUSTOMER );

		wp_set_current_user( $host );

		$this->assertSame( 404, $this->show( $id ) );
	}

	public function test_a_draft_price_preview_is_for_its_host_only(): void {
		$host    = $this->make_host();
		$listing = $this->stay_listing( array(), $host, 'draft' );
		$params  = array(
			'check_in'  => $this->day( 10 ),
			'check_out' => $this->day( 12 ),
			'guests'    => 2,
		);

		$this->assertSame( 404, $this->rest( 'POST', "/listings/{$listing}/price", $params )->get_status() );
		wp_set_current_user( $this->make_host() );
		$this->assertSame( 404, $this->rest( 'POST', "/listings/{$listing}/price", $params )->get_status() );
		wp_set_current_user( $host );
		$this->assertSame( 200, $this->rest( 'POST', "/listings/{$listing}/price", $params )->get_status() );
	}

	public function test_the_pdf_route_checks_access_and_state(): void {
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertSame( 404, $this->rest( 'GET', "/bookings/{$id}/pdf/quote" )->get_status() );
		$early = $this->rest( 'GET', "/bookings/{$id}/pdf/confirmation", array( 'token' => $this->token( $id ) ) );
		$this->assertSame( 409, $early->get_status() );
		$this->assertSame( 'not_confirmed', $this->code( $early ) );
	}
}
