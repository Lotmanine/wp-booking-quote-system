<?php
namespace Quillmoor\Booking\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Payment\FakeGateway;
use Quillmoor\Booking\Payment\WooHooks;
use Quillmoor\Booking\Services;
use WC_Order_Item_Product;
use WC_Product_Simple;

class DepositTest extends TestCase {

	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Deposit' );
		$product->set_virtual( true );
		$product->set_regular_price( '20' );
		$product->save();
		update_option( WooHooks::PRODUCT_OPTION, $product->get_id() );
		return $product->get_id();
	}

	private function order_for( int $booking_id ): \WC_Order {
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $this->product() );
		$item->add_meta_data( WooHooks::ORDER_META, $booking_id, true );
		$order->add_item( $item );
		$order->save();
		return $order;
	}

	private function lapse( int $id ): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Db::table( 'bookings' ) . " SET hold_expires_at = '2020-01-01 00:00:00' WHERE id = {$id}" );
		$wpdb->query( 'UPDATE ' . Db::table( 'slot_claims' ) . " SET expires_at = '2020-01-01 00:00:00' WHERE booking_id = {$id}" );
	}

	public function test_orders_use_high_performance_storage(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_paying_the_order_confirms_the_booking(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot(), 'test' );

		$this->order_for( $id )->payment_complete( 'txn' );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 1, $this->row_count( 'slot_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
	}

	public function test_repeat_payment_hooks_change_nothing(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot(), 'test' );
		$order = $this->order_for( $id );

		$order->payment_complete( 'txn' );
		$version = Services::bookings()->find( $id )['version'];
		WooHooks::on_paid( $order->get_id() );
		WooHooks::on_paid( $order->get_id() );

		$this->assertSame( $version, Services::bookings()->find( $id )['version'] );
		$this->assertSame( 1, $this->row_count( 'outbox', "event = 'booking.confirmed'" ) );
	}

	public function test_late_payment_reclaims_a_free_slot(): void {
		$id = $this->quote();
		Services::service()->hold( $id, $this->slot(), 'test' );
		$this->lapse( $id );
		Services::service()->release_expired_holds();
		$this->assertSame( 'quote', $this->state( $id ) );

		Services::service()->on_deposit_paid( $id, 5 );

		$this->assertSame( 'confirmed', $this->state( $id ) );
		$this->assertSame( 1, $this->row_count( 'slot_claims', "booking_id = {$id} AND expires_at IS NULL" ) );
	}

	public function test_late_payment_for_a_lost_slot_cancels_and_flags_a_refund(): void {
		$slot  = $this->slot();
		$first = $this->quote( 'a@example.com' );
		Services::service()->hold( $first, $slot, 'test' );
		$this->lapse( $first );
		Services::service()->release_expired_holds();
		$second = $this->quote( 'b@example.com' );
		Services::service()->hold( $second, $slot, 'test' );

		Services::service()->on_deposit_paid( $first, 5 );

		$this->assertSame( 'cancelled', $this->state( $first ) );
		$this->assertSame( 'flagged', Services::bookings()->find( $first )['refund_status'] );
		$this->assertSame( 1, $this->row_count( 'alerts' ) );
		$this->assertSame( 'hold', $this->state( $second ) );
	}

	public function test_payment_racing_the_sweep_does_not_double_book(): void {
		$slot  = $this->slot();
		$first = $this->quote( 'a@example.com' );
		Services::service()->hold( $first, $slot, 'test' );
		$this->lapse( $first );
		$second = $this->quote( 'b@example.com' );
		Services::service()->hold( $second, $slot, 'test' );

		Services::service()->on_deposit_paid( $first, 5 );

		$this->assertSame( 'cancelled', $this->state( $first ) );
		$this->assertSame( 1, $this->row_count( 'slot_claims', "slot_start = '{$slot}'" ) );
	}

	public function test_the_deposit_cannot_be_added_directly(): void {
		$product = $this->product();

		$this->assertFalse( WooHooks::block_direct_add( true, $product ) );
		$this->assertTrue( WooHooks::block_direct_add( true, $product + 1 ) );
	}

	public function test_cart_price_comes_from_the_stored_quote(): void {
		$id      = $this->quote();
		$product = $this->product();
		Services::service()->hold( $id, $this->slot(), 'test' );
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product, 1, 0, array(), array( WooHooks::CART_KEY => $id ) );

		WC()->cart->calculate_totals();

		$this->assertEquals( 24.0, (float) WC()->cart->get_total( 'edit' ) );
		WC()->cart->empty_cart();
	}

	public function test_expired_hold_blocks_block_checkout(): void {
		$id      = $this->quote();
		$product = $this->product();
		Services::service()->hold( $id, $this->slot(), 'test' );
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product, 1, 0, array(), array( WooHooks::CART_KEY => $id ) );
		$this->lapse( $id );
		$errors = new \WP_Error();

		WooHooks::validate_store_api_cart( $errors, WC()->cart );

		$this->assertSame( 'qhs_hold_expired', $errors->get_error_code() );
		WC()->cart->empty_cart();
	}

	public function test_fake_gateway_only_exists_in_fake_mode(): void {
		$gateway = new FakeGateway();
		$this->assertTrue( $gateway->is_available() );

		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );
		$this->assertFalse( ( new FakeGateway() )->is_available() );
	}

	public function test_return_url_goes_to_the_booking_portal(): void {
		$id    = $this->quote();
		$order = $this->order_for( $id );

		$this->assertStringContainsString( '/portal/', WooHooks::return_url( 'http://x', $order ) );
		$this->assertStringContainsString( 'booking=' . $id, WooHooks::return_url( 'http://x', $order ) );
	}
}
