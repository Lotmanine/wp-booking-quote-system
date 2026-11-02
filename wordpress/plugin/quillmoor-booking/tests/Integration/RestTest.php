<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Pdf\PdfRenderer;
use Quillmoor\Booking\Services;

class RestTest extends TestCase {

	/** @return int[] Listing ids from a search. */
	private function search( array $params ): array {
		$response = $this->rest( 'GET', '/listings', $params );
		$this->assertSame( 200, $response->get_status() );
		return array_column( $response->get_data()['listings'], 'id' );
	}

	private function quote_body( int $listing_id, array $extra = array() ): array {
		return $extra + array(
			'listing_id'      => $listing_id,
			'check_in'        => $this->day( 10 ),
			'check_out'       => $this->day( 12 ),
			'guests'          => 2,
			'email'           => 'rest@example.com',
			'full_name'       => 'Rest Guest',
			'turnstile_token' => 'ok',
		);
	}

	/** Quote through REST and return id and token. */
	private function rest_quote( int $listing_id, array $extra = array() ): array {
		$response = $this->rest( 'POST', '/quote', $this->quote_body( $listing_id, $extra ) );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return array( (int) $response->get_data()['booking']['id'], (string) $response->get_data()['token'] );
	}

	private function rest_hold( int $id, string $token ) {
		return $this->rest(
			'POST',
			'/hold',
			array(
				'booking_id'      => $id,
				'token'           => $token,
				'turnstile_token' => 'ok',
			)
		);
	}

	public function test_listings_filter_by_type(): void {
		$stay   = $this->stay_listing();
		$dining = $this->dining_listing();
		$this->stay_listing( array(), 0, 'draft' );

		$this->assertSame( array( $stay ), $this->search( array( 'type' => 'stay' ) ) );
		$this->assertSame( array( $dining ), $this->search( array( 'type' => 'dining' ) ) );
		$this->assertEqualsCanonicalizing( array( $stay, $dining ), $this->search( array() ) );
	}

	public function test_listings_filter_by_location(): void {
		$here  = $this->stay_listing();
		$there = $this->stay_listing();
		$this->location( 'far-coast', 'riverside' );
		wp_set_object_terms( $there, array( 'far-coast' ), 'qm_location' );

		$this->assertSame( array( $there ), $this->search( array( 'where' => 'far-coast' ) ) );
		$this->assertSame( array( $here ), $this->search( array( 'location' => 'test-valley' ) ) );
	}

	public function test_listings_with_dates_hide_places_that_cannot_take_them(): void {
		$free   = $this->stay_listing();
		$booked = $this->stay_listing();
		$short  = $this->stay_listing( array( 'qm_max_nights' => 2 ) );
		$this->paid_stay( $booked, $this->day( 11 ), $this->day( 12 ) );

		$found = $this->search(
			array(
				'type'      => 'stay',
				'check_in'  => $this->day( 10 ),
				'check_out' => $this->day( 13 ),
			)
		);

		$this->assertSame( array( $free ), $found );
		// The night before and the check-out day of another stay do not count.
		$this->assertContains(
			$booked,
			$this->search(
				array(
					'type'      => 'stay',
					'check_in'  => $this->day( 12 ),
					'check_out' => $this->day( 14 ),
				)
			)
		);
		$this->assertContains(
			$short,
			$this->search(
				array(
					'check_in'  => $this->day( 10 ),
					'check_out' => $this->day( 12 ),
				)
			)
		);
	}

	public function test_blocked_nights_and_past_dates_are_filtered(): void {
		$listing = $this->stay_listing();
		Services::nights()->block( $listing, array( $this->day( 10 ) ) );

		$this->assertSame(
			array(),
			$this->search(
				array(
					'check_in'  => $this->day( 10 ),
					'check_out' => $this->day( 11 ),
				)
			)
		);
		$this->assertSame(
			array(),
			$this->search(
				array(
					'check_in'  => $this->day( -2 ),
					'check_out' => $this->day( -1 ),
				)
			)
		);
	}

	public function test_listings_filter_by_guests_and_party(): void {
		$small  = $this->stay_listing( array( 'qm_max_guests' => 2 ) );
		$large  = $this->stay_listing( array( 'qm_max_guests' => 6 ) );
		$dining = $this->dining_listing( array( 'qm_max_party' => 4 ) );

		$this->assertSame( array( $large ), $this->search( array( 'type' => 'stay', 'guests' => 5 ) ) );
		$this->assertEqualsCanonicalizing( array( $small, $large ), $this->search( array( 'type' => 'stay', 'guests' => 2 ) ) );
		$this->assertSame( array(), $this->search( array( 'type' => 'dining', 'party' => 6 ) ) );
		$this->assertSame( array( $dining ), $this->search( array( 'type' => 'dining', 'party' => 4 ) ) );
	}

	public function test_dining_search_with_a_date_needs_a_free_table(): void {
		$open   = $this->dining_listing();
		$closed = $this->dining_listing();
		$full   = $this->dining_listing(
			array(
				'qm_covers'  => 4,
				'qm_periods' => array(
					array(
						'label' => 'Dinner',
						'days'  => array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
						'start' => '19:00',
						'end'   => '19:00',
					),
				),
			)
		);
		Services::tables()->close_day( $closed, $this->day( 5 ), $this->host() );
		$this->paid_table( $full, $this->day( 5 ) . ' 19:00:00', 2 );

		$params = array(
			'type'  => 'dining',
			'date'  => $this->day( 5 ),
			'party' => 3,
		);

		$this->assertSame( array( $open ), $this->search( $params ) );
		$params['party'] = 2;
		$this->assertEqualsCanonicalizing( array( $open, $full ), $this->search( $params ) );
	}

	public function test_a_card_carries_the_search_into_its_link_and_shows_the_total(): void {
		$listing = $this->stay_listing( array( 'qm_cleaning_fee' => 50 ) );

		$card = $this->rest(
			'GET',
			'/listings',
			array(
				'check_in'  => $this->day( 10 ),
				'check_out' => $this->day( 12 ),
				'guests'    => 3,
			)
		)->get_data()['listings'][0];

		$this->assertSame( $listing, $card['id'] );
		$this->assertSame( 'stay', $card['type'] );
		$this->assertSame( '$100', $card['price']['amount'] );
		$this->assertSame( 'night', $card['price']['unit'] );
		$this->assertSame( '$250 total', $card['total'] );
		$this->assertTrue( $card['instant'] );
		$this->assertStringContainsString( 'check_in=' . $this->day( 10 ), $card['url'] );
		$this->assertStringContainsString( 'guests=3', $card['url'] );
		$this->assertSame( 'Test Valley', $card['location'] );
	}

	public function test_the_stay_calendar_lists_unavailable_nights(): void {
		$listing = $this->stay_listing(
			array(
				'qm_min_nights' => 2,
				'qm_max_nights' => 7,
			)
		);
		$this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );
		Services::nights()->block( $listing, array( $this->day( 15 ) ) );

		$data = $this->rest(
			'GET',
			"/listings/{$listing}/calendar",
			array(
				'from' => $this->day( 1 ),
				'to'   => $this->day( 30 ),
			)
		)->get_data();

		$this->assertSame( 'stay', $data['type'] );
		$this->assertSame( $this->day( 1 ), $data['first'] );
		$this->assertSame( $this->day( Config::STAY_DAYS_AHEAD ), $data['last'] );
		$this->assertSame( 2, $data['min_nights'] );
		$this->assertSame( 7, $data['max_nights'] );
		$this->assertSame(
			array(
				$this->day( 10 ) => 'booked',
				$this->day( 11 ) => 'booked',
				$this->day( 15 ) => 'blocked',
			),
			$data['unavailable']
		);
	}

	public function test_the_dining_calendar_lists_closed_days(): void {
		$listing = $this->dining_listing();
		Services::tables()->close_day( $listing, $this->day( 6 ), $this->host() );

		$data = $this->rest(
			'GET',
			"/listings/{$listing}/calendar",
			array(
				'from' => $this->day( 1 ),
				'to'   => $this->day( 10 ),
			)
		)->get_data();

		$this->assertSame( 'dining', $data['type'] );
		$this->assertSame( array( $this->day( 6 ) => 'closed' ), $data['closed'] );
		$this->assertSame( $this->day( Config::DINING_DAYS_AHEAD ), $data['last'] );
	}

	public function test_the_calendar_refuses_long_ranges_and_hidden_listings(): void {
		$listing = $this->stay_listing();
		$draft   = $this->stay_listing( array(), 0, 'draft' );

		$long = $this->rest(
			'GET',
			"/listings/{$listing}/calendar",
			array(
				'from' => $this->day( 1 ),
				'to'   => $this->day( 500 ),
			)
		);

		$this->assertSame( 400, $long->get_status() );
		$this->assertSame( 'range_too_long', $this->code( $long ) );
		$this->assertSame( 404, $this->rest( 'GET', "/listings/{$draft}/calendar" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/listings/999999/calendar' )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', "/listings/{$listing}/calendar", array( 'from' => 'garbage' ) )->get_status() );
	}

	public function test_slots_say_whether_the_party_fits(): void {
		$listing = $this->dining_listing();
		$this->paid_table( $listing, $this->seating( 3, 0 ), 7 );

		$slots = $this->rest(
			'GET',
			"/listings/{$listing}/slots",
			array(
				'date'  => $this->day( 3 ),
				'party' => 4,
			)
		)->get_data()['slots'];

		$this->assertCount( 5, $slots );
		$this->assertSame( 3, $slots[0]['seats_left'] );
		$this->assertFalse( $slots[0]['fits'] );
		$this->assertTrue( $slots[1]['fits'] );
	}

	public function test_slots_need_a_dining_listing_and_a_date(): void {
		$this->assertSame( 404, $this->rest( 'GET', '/listings/' . $this->stay_listing() . '/slots', array( 'date' => $this->day( 3 ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'GET', '/listings/' . $this->dining_listing() . '/slots', array( 'date' => 'friday' ) )->get_status() );
	}

	public function test_the_price_preview_for_a_table(): void {
		$listing = $this->dining_listing();

		$ok  = $this->rest(
			'POST',
			"/listings/{$listing}/price",
			array(
				'slot_start' => $this->seating( 3 ),
				'guests'     => 3,
			)
		);
		$bad = $this->rest(
			'POST',
			"/listings/{$listing}/price",
			array(
				'slot_start' => $this->day( 3 ) . ' 18:05:00',
				'guests'     => 3,
			)
		);

		$this->assertSame( 15000, $ok->get_data()['total_cents'] );
		$this->assertSame( 3000, $ok->get_data()['deposit_cents'] );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'slot_invalid', $this->code( $bad ) );
	}

	public function test_a_quote_returns_the_booking_and_its_token(): void {
		$listing = $this->stay_listing();

		$response = $this->rest( 'POST', '/quote', $this->quote_body( $listing ) );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'quote', $data['booking']['state'] );
		$this->assertSame( $this->token( $data['booking']['id'] ), $data['token'] );
		$this->assertSame( $this->day( 10 ), $data['booking']['check_in'] );
		$this->assertSame( '$200', $data['booking']['total'] );
		$this->assertFalse( $data['booking']['can_cancel'] );
		$this->assertSame( $this->host(), (int) $this->booking( $data['booking']['id'] )['host_user_id'] );
		$this->assertSame( 'test-valley', $this->booking( $data['booking']['id'] )['location'] );
	}

	public function test_a_quote_needs_a_name_and_a_valid_email(): void {
		$listing = $this->stay_listing();

		$this->assertSame( 400, $this->rest( 'POST', '/quote', $this->quote_body( $listing, array( 'email' => 'not an email' ) ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/quote', $this->quote_body( $listing, array( 'full_name' => '' ) ) )->get_status() );
		$this->assertSame( 0, $this->row_count( 'bookings' ) );
	}

	public function test_a_quote_reports_rule_breaks(): void {
		$listing = $this->stay_listing();

		$taken = $this->rest( 'POST', '/quote', $this->quote_body( $listing, array( 'guests' => 9 ) ) );
		$gone  = $this->rest( 'POST', '/quote', $this->quote_body( 999999 ) );

		$this->assertSame( 400, $taken->get_status() );
		$this->assertSame( 'too_many_guests', $this->code( $taken ) );
		$this->assertSame( 404, $gone->get_status() );
	}

	public function test_without_turnstile_keys_a_visitor_must_sign_in(): void {
		$this->unset_env( 'TURNSTILE_SITE_KEY' );
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );

		$quote = $this->rest( 'POST', '/quote', $this->quote_body( $listing ) );
		$hold  = $this->rest_hold( $id, $this->token( $id ) );

		$this->assertSame( 403, $quote->get_status() );
		$this->assertSame( 'signin_required', $this->code( $quote ) );
		$this->assertSame( 403, $hold->get_status() );
		$this->assertSame( 'signin_required', $this->code( $hold ) );
		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertSame( array(), $this->http );
	}

	public function test_a_signed_in_guest_needs_no_turnstile_and_books_as_themselves(): void {
		$this->unset_env( 'TURNSTILE_SITE_KEY' );
		$this->unset_env( 'TURNSTILE_SECRET_KEY' );
		$user = $this->customer_user( 'member@example.com' );
		wp_set_current_user( $user );

		$response = $this->rest( 'POST', '/quote', $this->quote_body( $this->stay_listing(), array( 'email' => 'someone-else@example.com', 'turnstile_token' => '' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$customer = Services::bookings()->customer( (int) $this->booking( $response->get_data()['booking']['id'] )['customer_id'] );
		$this->assertSame( 'member@example.com', $customer['email'] );
		$this->assertSame( 201, $this->rest_hold( $response->get_data()['booking']['id'], '' )->get_status() );
		$this->assertSame( array(), $this->http );
	}

	public function test_a_rejected_turnstile_token_stops_the_quote(): void {
		$this->turnstile_ok = false;

		$response = $this->rest( 'POST', '/quote', $this->quote_body( $this->stay_listing() ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'turnstile_failed', $this->code( $response ) );
		$this->assertSame( 0, $this->row_count( 'bookings' ) );
	}

	public function test_a_hold_offers_the_payment_link_when_payments_work(): void {
		[ $id, $token ] = $this->rest_quote( $this->stay_listing() );

		$response = $this->rest_hold( $id, $token );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'ready', $response->get_data()['payment_status'] );
		$this->assertStringContainsString( 'qhs_pay=' . $id, (string) $response->get_data()['pay_url'] );
		$this->assertSame( 'hold', $response->get_data()['booking']['state'] );
		$this->assertNotEmpty( $response->get_data()['booking']['hold_expires_at'] );
	}

	public function test_a_hold_works_while_payments_are_not_set_up(): void {
		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );
		$this->unset_env( 'STRIPE_TEST_SECRET_KEY' );
		$this->unset_env( 'STRIPE_TEST_PUBLISHABLE_KEY' );
		[ $id, $token ] = $this->rest_quote( $this->stay_listing() );

		$response = $this->rest_hold( $id, $token );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'not_set_up', $response->get_data()['payment_status'] );
		$this->assertNull( $response->get_data()['pay_url'] );
		$this->assertSame( 'hold', $this->state( $id ) );
		$this->assertCount( 2, $this->nights_of( $id ) );
	}

	public function test_a_hold_on_taken_dates_is_a_conflict(): void {
		$listing = $this->stay_listing();
		$this->paid_stay( $listing, $this->day( 11 ), $this->day( 12 ), 'first@example.com' );
		[ $id, $token ] = $this->rest_quote( $listing );

		$response = $this->rest_hold( $id, $token );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'unavailable', $this->code( $response ) );
	}

	public function test_a_table_quote_and_hold_over_rest(): void {
		$listing        = $this->dining_listing();
		[ $id, $token ] = $this->rest_quote(
			$listing,
			array(
				'check_in'   => '',
				'check_out'  => '',
				'slot_start' => $this->seating( 3, 2 ),
				'guests'     => 4,
			)
		);

		$this->assertSame( 201, $this->rest_hold( $id, $token )->get_status() );
		$this->assertSame( 4, $this->booked_seats( $listing, $this->seating( 3, 2 ) ) );
		$this->assertSame( 'dining', $this->booking( $id )['listing_type'] );
	}

	public function test_change_dates_over_rest(): void {
		$listing = $this->stay_listing();
		$id      = $this->paid_stay( $listing, $this->day( 10 ), $this->day( 12 ) );

		$response = $this->rest(
			'POST',
			"/bookings/{$id}/change",
			array(
				'token'     => $this->token( $id ),
				'check_in'  => $this->day( 20 ),
				'check_out' => $this->day( 23 ),
			)
		);
		$same     = $this->rest(
			'POST',
			"/bookings/{$id}/change",
			array(
				'token'     => $this->token( $id ),
				'check_in'  => $this->day( 20 ),
				'check_out' => $this->day( 23 ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->day( 20 ), $response->get_data()['check_in'] );
		$this->assertSame( '$300', $response->get_data()['total'] );
		$this->assertSame( 400, $same->get_status() );
		$this->assertSame( 'same_dates', $this->code( $same ) );
	}

	public function test_cancel_over_rest_reports_the_refund(): void {
		$id = $this->paid_stay( $this->stay_listing( array( 'qm_cancellation' => 'flexible' ) ), $this->day( 10 ), $this->day( 12 ) );

		$data = $this->rest( 'POST', "/bookings/{$id}/cancel", array( 'token' => $this->token( $id ) ) )->get_data();

		$this->assertSame( 'cancelled', $data['state'] );
		$this->assertSame( 'Deposit refund due', $data['refund'] );
		$this->assertFalse( $data['can_cancel'] );
		$this->assertFalse( $data['can_change'] );
		$again = $this->rest( 'POST', "/bookings/{$id}/cancel", array( 'token' => $this->token( $id ) ) );
		$this->assertSame( 'not_cancellable', $this->code( $again ) );
	}

	public function test_pdfs_render_for_quotes_and_confirmations(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertStringStartsWith( '%PDF', PdfRenderer::quote( $this->booking( $id ) ) );
		$this->assertStringStartsWith( '%PDF', PdfRenderer::confirmation( $this->booking( $id ) ) );
		$table = $this->paid_table( $this->dining_listing(), $this->seating( 3 ), 2 );
		$this->assertStringStartsWith( '%PDF', PdfRenderer::confirmation( $this->booking( $table ) ) );
	}

	public function test_live_keys_close_the_booking_routes(): void {
		$listing = $this->stay_listing();
		$id      = $this->stay_quote( $listing, $this->day( 10 ), $this->day( 12 ) );
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_abc' );

		$quote = $this->rest( 'POST', '/quote', $this->quote_body( $listing ) );
		$hold  = $this->rest_hold( $id, $this->token( $id ) );

		$this->assertSame( 503, $quote->get_status() );
		$this->assertSame( 503, $hold->get_status() );
		$this->assertSame( 'payments_refused', $this->code( $hold ) );
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_email_verification_route(): void {
		$id    = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 2, array(), 'verify@example.com' );
		$cid   = (int) $this->booking( $id )['customer_id'];
		$token = 'known-token';
		Services::bookings()->update_customer(
			$cid,
			array(
				'verify_token_hash' => hash( 'sha256', $token ),
				'verify_expires_at' => \Quillmoor\Booking\Db::now( 600 ),
			)
		);

		$this->assertSame( 400, $this->rest( 'POST', '/verify-email', array( 'token' => 'wrong' ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', '/verify-email', array( 'token' => $token ) )->get_status() );
		$this->assertNotEmpty( Services::bookings()->customer( $cid )['email_verified_at'] );
		$this->assertSame( 400, $this->rest( 'POST', '/verify-email', array( 'token' => $token ) )->get_status() );
	}
}
