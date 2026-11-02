<?php
namespace Quillmoor\Booking\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Quillmoor\Booking\Payment\FakeGateway;
use Quillmoor\Booking\Payment\WooHooks;
use Quillmoor\Booking\Services;

class DepositTest extends TestCase {

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	private function cart_with( int $booking_id ): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->deposit_product(), 1, 0, array(), array( WooHooks::CART_KEY => $booking_id ) );
	}

	public function test_orders_use_high_performance_storage(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_paying_the_order_confirms_an_instant_stay(): void {
		$listing = $this->stay_listing();
		$id      = $this->held_stay( $listing, $this->day( 10 ), $this->day( 13 ) );

		$order = $this->order_for( $id, true );

		$booking = $this->booking( $id );
		$this->assertSame( 'confirmed', $booking['state'] );
		$this->assertSame( $order->get_id(), (int) $booking['order_id'] );
		$this->assertSame( 3, $this->row_count( 'night_claims', "booking_id = {$id} AND kind = 'booking' AND expires_at IS NULL" ) );
		$this->assertSame( $id, WooHooks::booking_id_for( wc_get_order( $order->get_id() ) ) );
	}

	public function test_paying_a_request_listing_sends_it_to_the_host(): void {
		$id = $this->held_stay( $this->stay_listing( array( 'qm_instant_book' => false ) ), $this->day( 10 ), $this->day( 13 ) );

		$this->order_for( $id, true );

		$this->assertSame( 'requested', $this->state( $id ) );
	}

	public function test_paying_a_table_makes_its_seats_permanent(): void {
		$listing = $this->dining_listing();
		$id      = $this->table_quote( $listing, $this->seating( 3 ), 4 );
		$this->hold( $id );

		$this->order_for( $id, true );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 1, $this->row_count( 'table_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
	}

	public function test_repeat_payment_hooks_change_nothing(): void {
		$id    = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$order = $this->order_for( $id, true );
		$after = $this->booking( $id );

		WooHooks::on_paid( $order->get_id() );
		WooHooks::on_paid( $order->get_id() );
		Services::service()->on_deposit_paid( $id, $order->get_id() );

		$this->assertSame( $after['version'], $this->booking( $id )['version'] );
		$this->assertSame( 1, $this->row_count( 'outbox', "booking_id = {$id} AND event = 'booking.confirmed'" ) );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND to_state = 'deposit_paid'" ) );
	}

	public function test_an_order_without_a_booking_is_ignored(): void {
		$order = wc_create_order();
		$order->save();

		WooHooks::on_paid( $order->get_id() );
		WooHooks::on_paid( 999999 );

		$this->assertSame( 0, $this->row_count( 'bookings' ) );
	}

	public function test_late_payment_reclaims_free_nights(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$this->expire_hold( $id );
		Services::service()->release_expired_holds();
		$this->assertSame( 'quote', $this->state( $id ) );

		Services::service()->on_deposit_paid( $id, 5 );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 3, $this->row_count( 'night_claims', "booking_id = {$id} AND kind = 'booking'" ) );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND reason = 'reclaimed for late payment'" ) );
	}

	public function test_late_payment_for_lost_nights_cancels_and_flags_a_refund(): void {
		$listing = $this->stay_listing();
		$first   = $this->held_stay( $listing, $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$this->expire_hold( $first );
		Services::service()->release_expired_holds();
		$second = $this->held_stay( $listing, $this->day( 12 ), $this->day( 14 ), 'b@example.com' );

		Services::service()->on_deposit_paid( $first, 5 );

		$booking = $this->booking( $first );
		$this->assertSame( 'cancelled', $booking['state'] );
		$this->assertSame( 'flagged', $booking['refund_status'] );
		$this->assertSame( 5, (int) $booking['order_id'] );
		$this->assertSame( array(), $this->nights_of( $first ) );
		$this->assertSame( 1, $this->row_count( 'alerts', "source = 'payment'" ) );
		$this->assertSame( 'hold', $this->state( $second ) );
		$this->assertCount( 2, $this->nights_of( $second ) );
	}

	public function test_payment_racing_the_sweep_does_not_double_book(): void {
		$listing = $this->stay_listing();
		$first   = $this->held_stay( $listing, $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$this->expire_hold( $first );
		// The sweep has not run, but another guest already took one of the nights.
		$second = $this->held_stay( $listing, $this->day( 12 ), $this->day( 14 ), 'b@example.com' );

		Services::service()->on_deposit_paid( $first, 5 );

		$this->assertSame( 'cancelled', $this->state( $first ) );
		$this->assertSame( 'flagged', $this->booking( $first )['refund_status'] );
		$this->assertSame( array(), $this->nights_of( $first ) );
		$this->assertSame( array( $this->day( 12 ), $this->day( 13 ) ), $this->nights_of( $second ) );
		$this->assertSame( 1, $this->row_count( 'night_claims', "night = '" . $this->day( 12 ) . "'" ) );
	}

	public function test_payment_after_expiry_keeps_nights_nobody_took(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$this->expire_hold( $id );

		Services::service()->on_deposit_paid( $id, 5 );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 3, $this->row_count( 'night_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
	}

	public function test_late_payment_for_a_full_table_cancels(): void {
		$listing = $this->dining_listing();
		$slot    = $this->seating( 3 );
		$first   = $this->table_quote( $listing, $slot, 6, 'a@example.com' );
		$this->hold( $first );
		$this->expire_hold( $first );
		$second = $this->table_quote( $listing, $slot, 8, 'b@example.com' );
		$this->hold( $second );

		Services::service()->on_deposit_paid( $first, 5 );

		$this->assertSame( 'cancelled', $this->state( $first ) );
		$this->assertSame( 8, $this->booked_seats( $listing, $slot ) );
	}

	public function test_the_deposit_cannot_be_added_directly(): void {
		$product = $this->deposit_product();

		$this->assertFalse( WooHooks::block_direct_add( true, $product ) );
		$this->assertTrue( WooHooks::block_direct_add( true, $product + 1 ) );
	}

	public function test_cart_price_comes_from_the_stored_quote(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$this->cart_with( $id );

		WC()->cart->calculate_totals();

		$this->assertEquals( 60.0, (float) WC()->cart->get_total( 'edit' ) );
	}

	public function test_a_deposit_line_without_a_booking_is_removed(): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $this->deposit_product() );

		WC()->cart->calculate_totals();

		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	public function test_an_expired_hold_blocks_block_checkout(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$this->cart_with( $id );
		$errors = new \WP_Error();
		WooHooks::validate_store_api_cart( $errors, WC()->cart );
		$this->assertFalse( $errors->has_errors() );

		$this->expire_hold( $id );
		WooHooks::validate_store_api_cart( $errors, WC()->cart );

		$this->assertSame( 'qhs_hold_expired', $errors->get_error_code() );
	}

	public function test_an_expired_hold_blocks_classic_checkout(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$this->cart_with( $id );
		$this->expire_hold( $id );
		wc_clear_notices();

		WooHooks::validate_classic_checkout();

		$this->assertSame( 1, wc_notice_count( 'error' ) );
		wc_clear_notices();
	}

	public function test_the_fake_gateway_only_exists_in_fake_mode(): void {
		$this->assertTrue( ( new FakeGateway() )->is_available() );
		$this->assertStringContainsString( 'not Stripe', ( new FakeGateway() )->title );

		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );
		$this->assertFalse( ( new FakeGateway() )->is_available() );
		$this->assertSame( 'no', ( new FakeGateway() )->enabled );
	}

	public function test_the_fake_gateway_marks_the_order_paid(): void {
		$id    = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$order = $this->order_for( $id );

		$result = ( new FakeGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertStringContainsString( 'paid=1', $result['redirect'] );
	}

	public function test_the_fake_gateway_refuses_outside_fake_mode(): void {
		$id    = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$order = $this->order_for( $id );
		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );

		$this->assertSame( 'failure', ( new FakeGateway() )->process_payment( $order->get_id() )['result'] );
		$this->assertSame( 'hold', $this->state( $id ) );
	}

	public function test_return_url_goes_to_the_trip(): void {
		$id    = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$order = $this->order_for( $id );
		$url   = WooHooks::return_url( 'http://x', $order );

		$this->assertStringContainsString( '/trips/', $url );
		$this->assertStringContainsString( 'booking=' . $id, $url );
		$this->assertStringContainsString( 'token=' . $this->token( $id ), $url );
		$this->assertStringContainsString( 'paid=1', $url );
		$this->assertSame( 'http://x', WooHooks::return_url( 'http://x', false ) );
	}

	public function test_the_pay_url_carries_the_booking_token(): void {
		$id  = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 13 ) );
		$url = Services::deposit()->pay_url( $this->booking( $id ) );

		$this->assertStringContainsString( 'qhs_pay=' . $id, $url );
		$this->assertStringContainsString( 'qhs_token=' . $this->token( $id ), $url );
	}

	public function test_deposits_are_counted_for_the_host(): void {
		$host  = $this->make_host();
		$other = $this->make_host();
		$this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 13 ), 'a@example.com' );
		$this->paid_stay( $this->stay_listing( array(), $other ), $this->day( 10 ), $this->day( 12 ), 'b@example.com' );
		$this->held_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ), 'c@example.com' );

		$this->assertSame( 6000, Services::bookings()->deposits_cents( $host ) );
		$this->assertSame( 6000 + 4000, Services::bookings()->deposits_cents() );
	}
}
