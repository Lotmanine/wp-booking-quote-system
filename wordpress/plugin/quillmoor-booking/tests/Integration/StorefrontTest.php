<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Admin\AdminPages;
use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Crm\CrmPipeline;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Rest\Respond;
use Quillmoor\Booking\Services;
use WPDieException;

/**
 * The search, results, service page and admin list sit on these server pieces.
 */
class StorefrontTest extends TestCase {

	public function test_catalog_lists_every_service_with_display_fields_and_no_dispatch_skill(): void {
		$data = $this->rest( 'GET', '/catalog' )->get_data();

		$this->assertCount( 6, $data['services'] );
		$this->assertCount( 3, $data['categories'] );
		foreach ( $data['services'] as $service ) {
			$this->assertArrayNotHasKey( 'skill', $service );
			$this->assertNotSame( '', $service['category'] );
			$this->assertNotSame( '', $service['hours'] );
			$this->assertNotEmpty( $service['includes'] );
			$this->assertStringEndsWith( '/assets/img/' . $service['key'] . '.svg', $service['image'] );
		}
		$this->assertSame( array( 100, 140, 190 ), array_column( $data['sizes'], 'multiplier' ) );
	}

	public function test_every_service_maps_to_a_skill_the_dispatch_roster_has(): void {
		foreach ( array_keys( Catalog::all()['services'] ) as $key ) {
			$this->assertContains( Catalog::skill( $key ), array( 'cleaning', 'handyman', 'garden' ), $key );
			$this->assertArrayHasKey( Catalog::category( $key ), Catalog::all()['categories'], $key );
			$this->assertGreaterThan( 0, Catalog::price( $key, 'small', 'north', array() )['total_cents'] );
		}
	}

	public function test_crm_tags_follow_the_catalog(): void {
		$tags = CrmPipeline::all_tags();

		$this->assertContains( 'service-deep-clean', $tags );
		$this->assertContains( 'service-lawn', $tags );
		$this->assertContains( 'area-riverside', $tags );
	}

	public function test_booking_payload_names_the_service_and_its_image_but_no_internal_keys(): void {
		$booking = Respond::booking( Services::bookings()->find( $this->quote() ) );

		$this->assertSame( 'cleaning', $booking['service_key'] );
		$this->assertStringEndsWith( '/cleaning.svg', $booking['image'] );
		$this->assertArrayNotHasKey( 'access_key', $booking );
		$this->assertArrayNotHasKey( 'customer_id', $booking );
	}

	public function test_admin_search_filters_by_state_reference_email_and_name(): void {
		$first  = $this->quote( 'first@example.com' );
		$second = $this->confirmed( 'second@example.com' );

		$this->assertSame( array( $second ), array_map( 'intval', array_column( Services::bookings()->search( 'confirmed', '', 20, 0 )['rows'], 'id' ) ) );
		$this->assertSame( array( $first ), array_map( 'intval', array_column( Services::bookings()->search( '', 'QHS-' . $first, 20, 0 )['rows'], 'id' ) ) );
		$this->assertSame( array( $second ), array_map( 'intval', array_column( Services::bookings()->search( '', 'second@', 20, 0 )['rows'], 'id' ) ) );
		$this->assertSame( 2, Services::bookings()->search( '', 'Test Person', 20, 0 )['total'] );
		$this->assertSame( 2, Services::bookings()->search( '', '', 1, 0 )['total'] );
		$this->assertCount( 1, Services::bookings()->search( '', '', 1, 1 )['rows'] );
	}

	public function test_admin_search_treats_wildcards_as_text(): void {
		$this->quote( 'plain@example.com' );

		$this->assertSame( 0, Services::bookings()->search( '', '%', 20, 0 )['total'] );
		$this->assertSame( 0, Services::bookings()->search( '', "' OR 1=1 -- ", 20, 0 )['total'] );
	}

	public function test_state_counts(): void {
		$this->quote( 'a@example.com' );
		$this->quote( 'b@example.com' );
		$this->confirmed( 'c@example.com' );

		$counts = Services::bookings()->state_counts();
		$this->assertSame( 2, $counts['quote'] );
		$this->assertSame( 1, $counts['confirmed'] );
	}

	public function test_readiness_is_open_with_a_gateway_and_turnstile_keys(): void {
		$this->env( 'TURNSTILE_SITE_KEY', 'site' );
		$this->env( 'TURNSTILE_SECRET_KEY', 'secret' );

		$this->assertSame(
			array(
				'open'    => true,
				'reasons' => array(),
			),
			Blocks::readiness()
		);
	}

	public function test_readiness_explains_missing_turnstile_and_deposit_setup(): void {
		$this->env( 'TURNSTILE_SITE_KEY', '' );
		$this->env( 'QHS_PAYMENT_MODE', 'stripe' );
		$this->env( 'STRIPE_TEST_SECRET_KEY', '' );

		$ready = Blocks::readiness();
		$this->assertFalse( $ready['open'] );
		$this->assertContains( 'Deposits are not set up yet.', $ready['reasons'] );
		$this->assertContains( 'The security check is not set up yet.', $ready['reasons'] );
	}

	public function test_readiness_reports_a_refused_live_key_without_its_value(): void {
		$this->env( 'STRIPE_TEST_SECRET_KEY', 'sk_live_fixture' );

		$ready = Blocks::readiness();
		$this->assertFalse( $ready['open'] );
		$this->assertContains( 'Booking is off: a live payment key was refused.', $ready['reasons'] );
		$this->assertStringNotContainsString( 'fixture', implode( ' ', $ready['reasons'] ) );
	}

	public function test_admin_bookings_screen_needs_the_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );
		AdminPages::bookings();
	}

	public function test_admin_bookings_screen_lists_bookings_for_a_manager(): void {
		Roles::sync();
		$id = $this->confirmed( 'listed@example.com' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::MANAGER ) ) );

		ob_start();
		AdminPages::bookings();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'QHS-' . $id, $html );
		$this->assertStringContainsString( 'listed@example.com', $html );
		$this->assertStringContainsString( 'qhs-admin-pill--confirmed', $html );
	}
}
