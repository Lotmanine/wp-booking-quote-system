<?php
namespace Quillmoor\Booking\Tests\Integration;

use InvalidArgumentException;
use Quillmoor\Booking\Catalog;

class PricingTest extends TestCase {

	public function test_price_combines_service_size_area_and_extras(): void {
		$price = Catalog::price( 'cleaning', 'medium', 'riverside', array( 'windows' ) );

		// 120.00 x 1.4 + 25.00 travel + 35.00 windows.
		$this->assertSame( 22800, $price['total_cents'] );
		$this->assertCount( 3, $price['line_items'] );
	}

	public function test_deposit_is_twenty_percent_rounded_to_the_dollar(): void {
		$this->assertSame( 4600, Catalog::deposit_for( 22800 ) );
	}

	public function test_deposit_has_a_minimum(): void {
		$this->assertSame( 2000, Catalog::deposit_for( 8000 ) );
	}

	public function test_repeated_extras_count_once(): void {
		$once  = Catalog::price( 'cleaning', 'small', 'north', array( 'waste' ) );
		$twice = Catalog::price( 'cleaning', 'small', 'north', array( 'waste', 'waste' ) );
		$this->assertSame( $once['total_cents'], $twice['total_cents'] );
	}

	/** @dataProvider unknown_options */
	public function test_unknown_options_are_refused( array $args ): void {
		$this->expectException( InvalidArgumentException::class );
		Catalog::price( ...$args );
	}

	public static function unknown_options(): array {
		return array(
			'service' => array( array( 'nope', 'small', 'north', array() ) ),
			'size'    => array( array( 'cleaning', 'nope', 'north', array() ) ),
			'area'    => array( array( 'cleaning', 'small', 'nope', array() ) ),
			'extra'   => array( array( 'cleaning', 'small', 'north', array( 'nope' ) ) ),
		);
	}

	public function test_client_supplied_price_is_ignored(): void {
		$response = $this->rest(
			'POST',
			'/quote',
			array(
				'service'         => 'cleaning',
				'size'            => 'small',
				'area'            => 'north',
				'email'           => 'p@example.com',
				'full_name'       => 'P',
				'total_cents'     => 1,
				'deposit_cents'   => 1,
				'turnstile_token' => 'x',
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '$120.00', $response->get_data()['booking']['total'] );
	}
}
