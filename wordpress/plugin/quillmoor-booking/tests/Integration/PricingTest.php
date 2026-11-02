<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Services;

class PricingTest extends TestCase {

	/** Price a stay and return the result or the error code. */
	private function stay( int $listing_id, string $in, string $out, int $guests = 2, array $extras = array() ): array {
		return Pricing::stay( $this->load( $listing_id ), $in, $out, $guests, $extras );
	}

	private function stay_error( int $listing_id, string $in, string $out, int $guests = 2, array $extras = array() ): string {
		try {
			$this->stay( $listing_id, $in, $out, $guests, $extras );
		} catch ( BookingError $e ) {
			return $e->error_code;
		}
		return '';
	}

	/** @return array<string,int> Line cents keyed by label. */
	private function lines( array $price ): array {
		return array_column( $price['line_items'], 'cents', 'label' );
	}

	public function test_nightly_price_times_nights(): void {
		$listing = $this->stay_listing();
		$in      = $this->weekday( 1 );

		$price = $this->stay( $listing, $in, $this->plus( $in, 3 ) );

		$this->assertSame( array( '$100 x 3 nights' => 30000 ), $this->lines( $price ) );
		$this->assertSame( 30000, $price['total_cents'] );
		$this->assertSame( 3, $price['nights'] );
	}

	public function test_one_night_reads_singular(): void {
		$in = $this->weekday( 2 );

		$price = $this->stay( $this->stay_listing(), $in, $this->plus( $in, 1 ) );

		$this->assertSame( array( '$100 x 1 night' => 10000 ), $this->lines( $price ) );
	}

	public function test_friday_and_saturday_take_the_weekend_price(): void {
		$listing = $this->stay_listing( array( 'qm_weekend_price' => 150 ) );
		$in      = $this->weekday( 4 );

		$price = $this->stay( $listing, $in, $this->plus( $in, 4 ) );

		$this->assertSame(
			array(
				'$100 x 2 nights'         => 20000,
				'$150 x 2 weekend nights' => 30000,
			),
			$this->lines( $price )
		);
		$this->assertSame( 50000, $price['total_cents'] );
	}

	public function test_sunday_night_is_not_a_weekend_night(): void {
		$listing = $this->stay_listing( array( 'qm_weekend_price' => 150 ) );
		$in      = $this->weekday( 7 );

		$this->assertSame( 10000, $this->stay( $listing, $in, $this->plus( $in, 1 ) )['total_cents'] );
	}

	public function test_no_weekend_price_means_the_nightly_price(): void {
		$in = $this->weekday( 5 );

		$this->assertSame( 20000, $this->stay( $this->stay_listing(), $in, $this->plus( $in, 2 ) )['total_cents'] );
	}

	public function test_a_season_replaces_nightly_and_weekend_prices_inside_its_dates(): void {
		$in      = $this->weekday( 4, 20 );
		$listing = $this->stay_listing(
			array(
				'qm_weekend_price' => 150,
				'qm_seasons'       => array(
					array(
						'label' => 'Summer',
						'start' => $this->plus( $in, 1 ),
						'end'   => $this->plus( $in, 2 ),
						'price' => 220,
					),
				),
			)
		);

		$price = $this->stay( $listing, $in, $this->plus( $in, 4 ) );

		// Thursday at the nightly price, Friday and Saturday in season, Sunday at the nightly price.
		$this->assertSame(
			array(
				'$100 x 2 nights'        => 20000,
				'$220 x 2 summer nights' => 44000,
			),
			$this->lines( $price )
		);
		$this->assertSame( 64000, $price['total_cents'] );
	}

	public function test_a_season_without_a_label_is_called_seasonal(): void {
		$in      = $this->weekday( 1, 20 );
		$listing = $this->stay_listing(
			array(
				'qm_seasons' => array(
					array(
						'label' => '',
						'start' => $in,
						'end'   => $in,
						'price' => 175,
					),
				),
			)
		);

		$this->assertArrayHasKey( '$175 x 1 seasonal night', $this->lines( $this->stay( $listing, $in, $this->plus( $in, 1 ) ) ) );
	}

	public function test_a_season_with_reversed_dates_is_ignored(): void {
		$in      = $this->weekday( 1, 20 );
		$listing = $this->stay_listing(
			array(
				'qm_seasons' => array(
					array(
						'label' => 'Broken',
						'start' => $this->plus( $in, 5 ),
						'end'   => $in,
						'price' => 999,
					),
				),
			)
		);

		$this->assertSame( array(), $this->load( $listing )->seasons );
		$this->assertSame( 20000, $this->stay( $listing, $in, $this->plus( $in, 2 ) )['total_cents'] );
	}

	public function test_cleaning_fee_is_added_once(): void {
		$listing = $this->stay_listing( array( 'qm_cleaning_fee' => 45 ) );
		$in      = $this->weekday( 1 );

		$price = $this->stay( $listing, $in, $this->plus( $in, 3 ) );

		$this->assertSame( 4500, $this->lines( $price )['Cleaning fee'] );
		$this->assertSame( 34500, $price['total_cents'] );
	}

	public function test_extras_per_stay_per_night_and_per_guest(): void {
		$listing = $this->stay_listing(
			array(
				'qm_extras' => array(
					array(
						'label' => 'Firewood',
						'price' => 25,
						'unit'  => 'per_stay',
					),
					array(
						'label' => 'Pool heating',
						'price' => 40,
						'unit'  => 'per_night',
					),
					array(
						'label' => 'Breakfast basket',
						'price' => 18,
						'unit'  => 'per_guest',
					),
				),
			)
		);
		$in = $this->weekday( 1 );

		$price = $this->stay( $listing, $in, $this->plus( $in, 3 ), 3, array( 'firewood', 'pool-heating', 'breakfast-basket' ) );

		$lines = $this->lines( $price );
		$this->assertSame( 2500, $lines['Firewood'] );
		$this->assertSame( 12000, $lines['Pool heating, $40 x 3'] );
		$this->assertSame( 5400, $lines['Breakfast basket, $18 x 3'] );
		$this->assertSame( 30000 + 2500 + 12000 + 5400, $price['total_cents'] );
	}

	public function test_an_extra_is_charged_once_even_if_sent_twice(): void {
		$listing = $this->stay_listing(
			array(
				'qm_extras' => array(
					array(
						'label' => 'Firewood',
						'price' => 25,
						'unit'  => 'per_stay',
					),
				),
			)
		);
		$in = $this->weekday( 1 );

		$this->assertSame( 12500, $this->stay( $listing, $in, $this->plus( $in, 1 ), 2, array( 'firewood', 'firewood' ) )['total_cents'] );
	}

	public function test_an_unknown_extra_is_refused(): void {
		$in = $this->weekday( 1 );

		$this->assertSame( 'invalid_option', $this->stay_error( $this->stay_listing(), $in, $this->plus( $in, 1 ), 2, array( 'free-yacht' ) ) );
	}

	public function test_an_unknown_unit_counts_once(): void {
		$listing = $this->stay_listing(
			array(
				'qm_extras' => array(
					array(
						'label' => 'Odd',
						'price' => 10,
						'unit'  => 'per_hour',
					),
				),
			)
		);

		$this->assertSame( 'per_stay', $this->load( $listing )->extras['odd']['unit'] );
	}

	public function test_minimum_and_maximum_nights(): void {
		$listing = $this->stay_listing(
			array(
				'qm_min_nights' => 3,
				'qm_max_nights' => 5,
			)
		);
		$in = $this->weekday( 1 );

		$this->assertSame( 'too_short', $this->stay_error( $listing, $in, $this->plus( $in, 2 ) ) );
		$this->assertSame( 'too_long', $this->stay_error( $listing, $in, $this->plus( $in, 6 ) ) );
		$this->assertSame( 30000, $this->stay( $listing, $in, $this->plus( $in, 3 ) )['total_cents'] );
		$this->assertSame( 50000, $this->stay( $listing, $in, $this->plus( $in, 5 ) )['total_cents'] );
	}

	public function test_check_out_must_follow_check_in(): void {
		$in = $this->weekday( 1 );

		$this->assertSame( 'dates_invalid', $this->stay_error( $this->stay_listing(), $in, $in ) );
		$this->assertSame( 'dates_invalid', $this->stay_error( $this->stay_listing(), $in, $this->plus( $in, -2 ) ) );
		$this->assertSame( 'dates_invalid', $this->stay_error( $this->stay_listing(), 'soon', 'later' ) );
	}

	public function test_guest_count_must_fit_the_place(): void {
		$listing = $this->stay_listing( array( 'qm_max_guests' => 4 ) );
		$in      = $this->weekday( 1 );

		$this->assertSame( 'too_many_guests', $this->stay_error( $listing, $in, $this->plus( $in, 1 ), 5 ) );
		$this->assertSame( 'too_many_guests', $this->stay_error( $listing, $in, $this->plus( $in, 1 ), 0 ) );
		$this->assertSame( '', $this->stay_error( $listing, $in, $this->plus( $in, 1 ), 4 ) );
	}

	public function test_a_listing_without_a_price_cannot_be_priced(): void {
		$in = $this->weekday( 1 );

		$this->assertSame( 'not_priced', $this->stay_error( $this->stay_listing( array( 'qm_nightly_price' => 0 ) ), $in, $this->plus( $in, 1 ) ) );
	}

	public function test_each_type_refuses_the_other_price_rule(): void {
		$in = $this->weekday( 1 );
		$this->assertSame( 'wrong_type', $this->stay_error( $this->dining_listing(), $in, $this->plus( $in, 1 ) ) );

		$this->expectException( BookingError::class );
		Pricing::dining( $this->load( $this->stay_listing() ), 2, array() );
	}

	/** @dataProvider deposits */
	public function test_deposit_rules( array $fields, int $nights, int $guests, int $expected_total, int $expected_deposit ): void {
		$listing = $this->stay_listing( $fields + array( 'qm_max_guests' => 6 ) );
		$in      = $this->weekday( 5 );

		$price = $this->stay( $listing, $in, $this->plus( $in, $nights ), $guests );

		$this->assertSame( $expected_total, $price['total_cents'] );
		$this->assertSame( $expected_deposit, $price['deposit_cents'] );
	}

	public static function deposits(): array {
		return array(
			'percent of the total'          => array( array( 'qm_deposit_rule' => 'percent', 'qm_deposit_value' => 20 ), 3, 2, 30000, 6000 ),
			'percent rounds to whole money' => array( array( 'qm_nightly_price' => 123, 'qm_deposit_rule' => 'percent', 'qm_deposit_value' => 20 ), 1, 2, 12300, 2500 ),
			'percent never above 100'       => array( array( 'qm_deposit_rule' => 'percent', 'qm_deposit_value' => 150 ), 2, 2, 20000, 20000 ),
			'minimum of twenty'             => array( array( 'qm_nightly_price' => 50, 'qm_deposit_rule' => 'percent', 'qm_deposit_value' => 10 ), 1, 2, 5000, 2000 ),
			'never above the total'         => array( array( 'qm_nightly_price' => 15, 'qm_deposit_rule' => 'flat', 'qm_deposit_value' => 100 ), 1, 2, 1500, 1500 ),
			'per guest'                     => array( array( 'qm_deposit_rule' => 'per_guest', 'qm_deposit_value' => 25 ), 3, 3, 30000, 7500 ),
			'flat'                          => array( array( 'qm_deposit_rule' => 'flat', 'qm_deposit_value' => 75 ), 3, 2, 30000, 7500 ),
			'first night at weekend price'  => array( array( 'qm_weekend_price' => 160, 'qm_deposit_rule' => 'first_night' ), 3, 2, 42000, 16000 ),
			'unknown rule falls to percent' => array( array( 'qm_deposit_rule' => 'everything', 'qm_deposit_value' => 20 ), 3, 2, 30000, 6000 ),
		);
	}

	public function test_dining_is_the_set_menu_per_guest(): void {
		$price = Pricing::dining( $this->load( $this->dining_listing() ), 4, array() );

		$this->assertSame( array( 'Set menu, $50 x 4 guests' => 20000 ), $this->lines( $price ) );
		$this->assertSame( 20000, $price['total_cents'] );
		$this->assertSame( 4000, $price['deposit_cents'] );
		$this->assertSame( 0, $price['nights'] );
	}

	public function test_dining_extras_count_guests_and_once(): void {
		$listing = $this->dining_listing(
			array(
				'qm_extras' => array(
					array(
						'label' => 'Wine pairing',
						'price' => 30,
						'unit'  => 'per_guest',
					),
					array(
						'label' => 'Birthday cake',
						'price' => 20,
						'unit'  => 'per_stay',
					),
					array(
						'label' => 'Corkage',
						'price' => 15,
						'unit'  => 'per_night',
					),
				),
			)
		);

		$price = Pricing::dining( $this->load( $listing ), 2, array( 'wine-pairing', 'birthday-cake', 'corkage' ) );

		$lines = $this->lines( $price );
		$this->assertSame( 6000, $lines['Wine pairing, $30 x 2'] );
		$this->assertSame( 2000, $lines['Birthday cake'] );
		$this->assertSame( 1500, $lines['Corkage'] );
		$this->assertSame( 10000 + 6000 + 2000 + 1500, $price['total_cents'] );
	}

	/** @dataProvider parties */
	public function test_party_size_limits( int $party, bool $ok ): void {
		$listing = $this->load(
			$this->dining_listing(
				array(
					'qm_min_party' => 2,
					'qm_max_party' => 6,
				)
			)
		);

		try {
			Pricing::dining( $listing, $party, array() );
			$this->assertTrue( $ok );
		} catch ( BookingError $e ) {
			$this->assertFalse( $ok );
			$this->assertSame( 'party_size', $e->error_code );
		}
	}

	public static function parties(): array {
		return array(
			'below the minimum' => array( 1, false ),
			'the minimum'       => array( 2, true ),
			'the maximum'       => array( 6, true ),
			'above the maximum' => array( 7, false ),
		);
	}

	public function test_dining_without_a_menu_price_still_takes_the_minimum_deposit(): void {
		$price = Pricing::dining( $this->load( $this->dining_listing( array( 'qm_menu_price' => 0 ) ) ), 2, array() );

		$this->assertSame( 0, $price['total_cents'] );
		$this->assertSame( Pricing::MIN_DEPOSIT_CENTS, $price['deposit_cents'] );
	}

	public function test_money_format(): void {
		$this->assertSame( '$100', Pricing::money( 10000 ) );
		$this->assertSame( '$123.45', Pricing::money( 12345 ) );
		$this->assertSame( '$1,234,567', Pricing::money( 123456700 ) );
		$this->assertSame( '$0', Pricing::money( 0 ) );
	}

	public function test_nights_and_night_dates(): void {
		$this->assertSame( 3, Pricing::nights( '2027-03-27', '2027-03-30' ) );
		$this->assertSame( array( '2027-03-27', '2027-03-28', '2027-03-29' ), Pricing::night_dates( '2027-03-27', 3 ) );
		$this->assertSame( 0, Pricing::nights( 'x', '2027-03-30' ) );
		$this->assertSame( array( '2027-02-28', '2027-03-01' ), Pricing::night_dates( '2027-02-28', 2 ) );
	}

	public function test_the_quote_stores_the_server_price_and_ignores_a_sent_total(): void {
		$listing = $this->stay_listing( array( 'qm_cleaning_fee' => 40 ) );
		$in      = $this->weekday( 1 );

		$response = $this->rest(
			'POST',
			'/quote',
			array(
				'listing_id'      => $listing,
				'check_in'        => $in,
				'check_out'       => $this->plus( $in, 2 ),
				'guests'          => 2,
				'email'           => 'price@example.com',
				'full_name'       => 'Price Check',
				'total_cents'     => 1,
				'deposit_cents'   => 1,
				'turnstile_token' => 'ok',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$booking = Services::bookings()->find( $response->get_data()['booking']['id'] );
		$quote   = Services::bookings()->quote( (int) $booking['quote_id'] );
		$this->assertSame( 24000, (int) $quote['total_cents'] );
		$this->assertSame( 4800, (int) $quote['deposit_cents'] );
		$this->assertSame( '$240', $response->get_data()['booking']['total'] );
		$this->assertSame( '$192', $response->get_data()['booking']['balance'] );
	}

	public function test_the_price_preview_matches_the_rules(): void {
		$listing = $this->stay_listing( array( 'qm_weekend_price' => 150 ) );
		$in      = $this->weekday( 5 );

		$data = $this->rest(
			'POST',
			"/listings/{$listing}/price",
			array(
				'check_in'  => $in,
				'check_out' => $this->plus( $in, 2 ),
				'guests'    => 2,
			)
		)->get_data();

		$this->assertSame( 30000, $data['total_cents'] );
		$this->assertSame( '$300', $data['total'] );
		$this->assertSame( '$60', $data['deposit'] );
		$this->assertSame( '$240', $data['balance'] );
		$this->assertSame( 2, $data['nights'] );
		$this->assertSame( array( array( 'label' => '$150 x 2 weekend nights', 'amount' => '$300' ) ), $data['lines'] );
	}
}
