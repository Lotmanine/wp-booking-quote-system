<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Payment\Refunds;
use Quillmoor\Booking\Services;

class RefundTest extends TestCase {

	/** A stay paid through a real order and the fake gateway. */
	private function paid_with_order( array $fields = array() ): array {
		$id    = $this->held_stay( $this->stay_listing( $fields ), $this->day( 30 ), $this->day( 33 ) );
		$order = $this->order_for( $id, true );
		return array( $id, $order->get_id() );
	}

	private function refund_error( int $id ): string {
		try {
			Services::refunds()->refund( $id, 'host' );
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	public function test_a_cancelled_booking_is_refunded_through_the_gateway(): void {
		[ $id, $order_id ] = $this->paid_with_order();
		Services::service()->cancel( $id, 'host', false );

		$refunded = Services::refunds()->refund( $id, 'host' );

		$this->assertSame( 'refunded', $refunded['refund_status'] );
		$this->assertSame( 6000, (int) $refunded['refund_cents'] );
		$this->assertNotEmpty( $refunded['refunded_at'] );
		$order = wc_get_order( $order_id );
		$this->assertEquals( 60.0, (float) $order->get_total_refunded() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 1, $this->row_count( 'booking_history', "booking_id = {$id} AND reason = 'deposit refunded'" ) );
		$this->assertSame( 'Deposit refunded: $60', \Quillmoor\Booking\Domain\BookingView::refund_label( $refunded ) );
	}

	public function test_a_declined_request_is_refundable(): void {
		[ $id ] = $this->paid_with_order( array( 'qm_instant_book' => false ) );
		Services::service()->decline( $id, 'host' );

		$this->assertTrue( Refunds::is_refundable( $this->booking( $id ) ) );
		$this->assertSame( 'refunded', Services::refunds()->refund( $id, 'host' )['refund_status'] );
	}

	public function test_a_no_show_is_refundable_by_the_host(): void {
		[ $id ] = $this->paid_with_order();
		$this->move_start( $id, -1 * HOUR_IN_SECONDS );
		Services::service()->no_show( $id, 'host' );

		$this->assertSame( 'refunded', Services::refunds()->refund( $id, 'host' )['refund_status'] );
	}

	public function test_a_forfeited_deposit_can_still_be_refunded_by_the_host(): void {
		[ $id ] = $this->paid_with_order( array( 'qm_cancellation' => 'strict' ) );
		$this->move_start( $id, 5 * DAY_IN_SECONDS );
		$this->assertSame( 'forfeited', Services::service()->cancel( $id, 'guest' )['refund_status'] );

		$this->assertSame( 'refunded', Services::refunds()->refund( $id, 'host' )['refund_status'] );
	}

	public function test_a_live_booking_cannot_be_refunded(): void {
		[ $id ] = $this->paid_with_order();

		$this->assertFalse( Refunds::is_refundable( $this->booking( $id ) ) );
		$this->assertSame( 'still_live', $this->refund_error( $id ) );
		$this->assertSame( 'none', $this->booking( $id )['refund_status'] );
	}

	public function test_a_booking_without_payment_cannot_be_refunded(): void {
		$id = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		Services::service()->cancel( $id, 'guest' );

		$this->assertSame( 'no_payment', $this->refund_error( $id ) );
		$this->assertSame( 'not_found', $this->refund_error( 999999 ) );
	}

	public function test_a_refund_runs_once(): void {
		[ $id, $order_id ] = $this->paid_with_order();
		Services::service()->cancel( $id, 'host', false );
		Services::refunds()->refund( $id, 'host' );

		$this->assertFalse( Refunds::is_refundable( $this->booking( $id ) ) );
		$this->assertSame( 'already_refunded', $this->refund_error( $id ) );
		$this->assertCount( 1, wc_get_order( $order_id )->get_refunds() );
	}

	public function test_an_order_refunded_elsewhere_is_not_refunded_again(): void {
		[ $id, $order_id ] = $this->paid_with_order();
		Services::service()->cancel( $id, 'host', false );
		wc_create_refund(
			array(
				'order_id' => $order_id,
				'amount'   => 60,
			)
		);

		$this->assertSame( 'already_refunded', $this->refund_error( $id ) );
	}

	public function test_a_missing_order_is_reported(): void {
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ), 'guest@example.com', 0, 987654 );
		Services::service()->cancel( $id, 'host', false );

		$this->assertSame( 'no_payment', $this->refund_error( $id ) );
	}

	public function test_a_gateway_refusal_is_reported_and_nothing_is_marked(): void {
		[ $id ] = $this->paid_with_order();
		Services::service()->cancel( $id, 'host', false );
		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );

		$this->assertSame( 'refund_failed', $this->refund_error( $id ) );
		$this->assertSame( 'flagged', $this->booking( $id )['refund_status'] );
	}

	public function test_refunds_stop_while_a_live_key_is_present(): void {
		[ $id ] = $this->paid_with_order();
		Services::service()->cancel( $id, 'host', false );
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_abc' );

		$this->assertSame( 'payments_unavailable', $this->refund_error( $id ) );
	}

	public function test_the_trip_view_offers_a_refund_only_when_it_makes_sense(): void {
		[ $id ] = $this->paid_with_order();
		$this->assertFalse( \Quillmoor\Booking\Rest\Respond::booking( $this->booking( $id ) )['refundable'] );

		Services::service()->cancel( $id, 'host', false );
		$view = \Quillmoor\Booking\Rest\Respond::booking( $this->booking( $id ) );

		$this->assertTrue( $view['refundable'] );
		$this->assertSame( 'Deposit refund due', $view['refund'] );
	}
}
