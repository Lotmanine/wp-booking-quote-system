<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Blocks;

/**
 * Server rendering of the front-end blocks. Notices and warnings fail the test, so these also
 * catch render code that reads a missing field.
 */
class BlocksTest extends TestCase {

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function render( string $name, array $attributes = array() ): string {
		return render_block(
			array(
				'blockName'    => 'quillmoor/' . $name,
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	}

	public function test_every_block_is_registered(): void {
		foreach ( Blocks::NAMES as $name ) {
			$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'quillmoor/' . $name ), $name );
		}
	}

	public function test_explore_lists_matching_cards(): void {
		$stay   = $this->stay_listing();
		$dining = $this->dining_listing();
		$_GET   = array( 'type' => 'stay' );

		$html = $this->render( 'explore' );

		$this->assertStringContainsString( 'data-listing="' . $stay . '"', $html );
		$this->assertStringNotContainsString( 'data-listing="' . $dining . '"', $html );
		$this->assertStringContainsString( 'Test Stay', $html );
	}

	public function test_explore_home_sections_render(): void {
		$this->stay_listing();
		$this->dining_listing();

		$html = $this->render( 'explore', array( 'section' => 'home' ) );

		$this->assertStringContainsString( 'Places to stay', $html );
		$this->assertStringContainsString( 'Places to eat', $html );
	}

	public function test_explore_with_dates_shows_the_total(): void {
		$this->stay_listing();
		$_GET = array(
			'check_in'  => $this->day( 10 ),
			'check_out' => $this->day( 12 ),
		);

		$this->assertStringContainsString( '$200 total', $this->render( 'explore' ) );
	}

	/** @dataProvider listing_kinds */
	public function test_the_listing_page_renders_for_each_type( string $kind ): void {
		$id = 'stay' === $kind
			? $this->stay_listing(
				array(
					'qm_extras' => array(
						array(
							'label' => 'Firewood',
							'price' => 25,
							'unit'  => 'per_stay',
						),
					),
				)
			)
			: $this->dining_listing();
		$this->go_to( get_permalink( $id ) );

		$html = $this->render( 'listing' );

		$this->assertStringContainsString( 'data-testid="listing-page"', $html );
		$this->assertStringContainsString( 'data-type="' . $kind . '"', $html );
	}

	public static function listing_kinds(): array {
		return array(
			'stay'   => array( 'stay' ),
			'dining' => array( 'dining' ),
		);
	}

	public function test_a_draft_listing_page_is_not_shown_to_visitors(): void {
		$id = $this->stay_listing( array(), 0, 'draft' );
		$this->go_to( '/?p=' . $id . '&post_type=qm_listing' );

		$this->assertStringContainsString( 'This place is not available', $this->render( 'listing' ) );
	}

	public function test_trips_asks_visitors_to_sign_in(): void {
		$this->assertStringContainsString( 'data-testid="trips-signin"', $this->render( 'trips' ) );
	}

	public function test_trips_opens_a_guest_link_and_a_signed_in_dashboard(): void {
		$_GET = array(
			'booking' => '5',
			'token'   => 'abc',
		);
		$this->assertStringContainsString( 'data-booking="5"', $this->render( 'trips' ) );

		$_GET = array();
		wp_set_current_user( $this->customer_user( 'member@example.com' ) );
		$this->assertStringContainsString( 'data-signed-in="1"', $this->render( 'trips' ) );
	}

	public function test_search_and_credits_render(): void {
		$this->location();

		$this->assertNotSame( '', $this->render( 'search' ) );
		$this->assertStringContainsString( 'data-testid="credits"', $this->render( 'credits' ) );
	}
}
