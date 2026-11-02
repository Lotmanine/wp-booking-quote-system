<?php
/**
 * Demo content.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Demo;

defined( 'ABSPATH' ) || exit;

/**
 * The fictional places, hosts and terms of the demo. Names, hosts and prices are invented; map
 * points are approximate positions chosen for the demo, not real businesses.
 */
final class SeedData {

	/**
	 * Hosts. Only demo-host signs in with the shared demo password.
	 *
	 * @return array<string,array{name:string,since:string,bio:string,languages:string}>
	 */
	public static function hosts(): array {
		return array(
			DemoReset::HOST_LOGIN => array(
				'name'      => 'Robin Ashby',
				'since'     => '2024',
				'bio'       => 'Looks after two country stays and a garden restaurant in the north valleys. Fictional host for the demo.',
				'languages' => 'English, Spanish',
			),
			'host-mara'           => array(
				'name'      => 'Mara Quinn',
				'since'     => '2023',
				'bio'       => 'Runs pool villas and a small tasting room on the coast and in the hills. Fictional host for the demo.',
				'languages' => 'English, Portuguese',
			),
			'host-ines'           => array(
				'name'      => 'Ines Calder',
				'since'     => '2025',
				'bio'       => 'Hosts city homes in Old Harbour and two places to eat nearby. Fictional host for the demo.',
				'languages' => 'English, French',
			),
		);
	}

	/**
	 * Locations with an approximate centre and the dispatch zone that covers them.
	 *
	 * @return array<string,array{name:string,region:string,lat:float,lng:float,zone:string}>
	 */
	public static function locations(): array {
		return array(
			'fernbrook-valley' => array(
				'name'   => 'Fernbrook Valley',
				'region' => 'Northern forests',
				'lat'    => 39.2433,
				'lng'    => -123.2047,
				'zone'   => 'north',
			),
			'larch-lake'       => array(
				'name'   => 'Larch Lake',
				'region' => 'Lake country',
				'lat'    => 39.0968,
				'lng'    => -122.8902,
				'zone'   => 'riverside',
			),
			'kestrel-hills'    => array(
				'name'   => 'Kestrel Hills',
				'region' => 'Wine hills',
				'lat'    => 38.5122,
				'lng'    => -122.8577,
				'zone'   => 'north',
			),
			'saltmarsh-coast'  => array(
				'name'   => 'Saltmarsh Coast',
				'region' => 'Pacific shore',
				'lat'    => 38.3049,
				'lng'    => -123.0569,
				'zone'   => 'riverside',
			),
			'old-harbour'      => array(
				'name'   => 'Old Harbour',
				'region' => 'City',
				'lat'    => 37.8044,
				'lng'    => -122.2712,
				'zone'   => 'central',
			),
		);
	}

	/**
	 * Categories for the category row.
	 *
	 * @return array<string,array{name:string,icon:string,for:string}>
	 */
	public static function categories(): array {
		return array(
			'cabins'        => array(
				'name' => 'Cabins',
				'icon' => 'cabin',
				'for'  => 'stay',
			),
			'lakefront'     => array(
				'name' => 'Lakefront',
				'icon' => 'view',
				'for'  => 'stay',
			),
			'pools'         => array(
				'name' => 'Amazing pools',
				'icon' => 'pool',
				'for'  => 'stay',
			),
			'villas'        => array(
				'name' => 'Villas',
				'icon' => 'home',
				'for'  => 'stay',
			),
			'beach'         => array(
				'name' => 'Beachfront',
				'icon' => 'beach',
				'for'  => 'stay',
			),
			'countryside'   => array(
				'name' => 'Countryside',
				'icon' => 'country',
				'for'  => 'stay',
			),
			'cottages'      => array(
				'name' => 'Cottages',
				'icon' => 'leaf',
				'for'  => 'stay',
			),
			'lofts'         => array(
				'name' => 'Lofts',
				'icon' => 'design',
				'for'  => 'stay',
			),
			'city'          => array(
				'name' => 'City',
				'icon' => 'city',
				'for'  => 'stay',
			),
			'bistros'       => array(
				'name' => 'Bistros',
				'icon' => 'bistro',
				'for'  => 'dining',
			),
			'tasting-menus' => array(
				'name' => 'Tasting menus',
				'icon' => 'fine',
				'for'  => 'dining',
			),
			'terraces'      => array(
				'name' => 'Terraces',
				'icon' => 'terrace',
				'for'  => 'dining',
			),
			'cafes'         => array(
				'name' => 'Cafes',
				'icon' => 'cafe',
				'for'  => 'dining',
			),
			'wine-bars'     => array(
				'name' => 'Wine bars',
				'icon' => 'wine',
				'for'  => 'dining',
			),
		);
	}

	/**
	 * Amenities.
	 *
	 * @return array<string,array{name:string,icon:string}>
	 */
	public static function amenities(): array {
		$list = array(
			'wifi'        => array( 'Wifi', 'wifi' ),
			'kitchen'     => array( 'Kitchen', 'kitchen' ),
			'parking'     => array( 'Free parking on premises', 'parking' ),
			'washer'      => array( 'Washer', 'washer' ),
			'air'         => array( 'Air conditioning', 'air' ),
			'heating'     => array( 'Heating', 'heating' ),
			'fireplace'   => array( 'Indoor fireplace', 'fire' ),
			'workspace'   => array( 'Dedicated workspace', 'workspace' ),
			'self-check'  => array( 'Self check-in', 'key' ),
			'garden'      => array( 'Garden', 'leaf' ),
			'pool'        => array( 'Pool', 'pool' ),
			'sea-view'    => array( 'Sea view', 'beach' ),
			'lake-view'   => array( 'Lake view', 'view' ),
			'step-free'   => array( 'Step-free entrance', 'accessible' ),
			'family'      => array( 'Family friendly', 'child' ),
			'pets'        => array( 'Pets allowed', 'pet' ),
			'smoke-alarm' => array( 'Smoke alarm', 'smoke' ),
			'vegetarian'  => array( 'Vegetarian options', 'veg' ),
			'outdoor'     => array( 'Outdoor seating', 'terrace' ),
			'wine-list'   => array( 'Wine list', 'wine' ),
		);
		$out  = array();
		foreach ( $list as $slug => $row ) {
			$out[ $slug ] = array(
				'name' => $row[0],
				'icon' => $row[1],
			);
		}
		return $out;
	}

	/**
	 * Seasonal rate window, computed from today so the demo always has upcoming seasons.
	 *
	 * @param string $label Season name.
	 * @param string $from  Month and day, m-d.
	 * @param string $to    Month and day, m-d.
	 * @param int    $price Price per night in dollars.
	 * @return array{label:string,start:string,end:string,price:int}
	 */
	private static function season( string $label, string $from, string $to, int $price ): array {
		$year  = (int) gmdate( 'Y' );
		$start = $year . '-' . $from;
		if ( $start < gmdate( 'Y-m-d' ) ) {
			++$year;
			$start = $year . '-' . $from;
		}
		$end = ( $to < $from ? $year + 1 : $year ) . '-' . $to;
		return array(
			'label' => $label,
			'start' => $start,
			'end'   => $end,
			'price' => $price,
		);
	}

	/**
	 * The fourteen places.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function listings(): array {
		$stay_rules = array(
			array( 'rule' => 'No parties or events' ),
			array( 'rule' => 'Quiet hours from 22:00 to 08:00' ),
			array( 'rule' => 'No smoking inside' ),
		);

		return array(
			'fern-hollow-cabin'          => array(
				'title'      => 'Fern Hollow Cabin',
				'host'       => DemoReset::HOST_LOGIN,
				'location'   => 'fernbrook-valley',
				'categories' => array( 'cabins', 'countryside' ),
				'amenities'  => array( 'wifi', 'kitchen', 'fireplace', 'parking', 'heating', 'self-check', 'smoke-alarm' ),
				'offset'     => array( 0.012, -0.018 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire cabin in the pines',
					'qm_description'    => "A dark timber cabin on a quiet slope above Fernbrook Valley. The living room has a stone fireplace and deep leather sofas, the dining nook seats four, and the upstairs bedroom sits under a sloping pine ceiling.\n\nTrails start at the gate. The nearest village shop is a ten minute drive.",
					'qm_instant_book'   => 1,
					'qm_highlights'     => array(
						array(
							'icon'  => 'fire',
							'title' => 'Wood fire',
							'text'  => 'The fireplace is ready to light, with a first basket of wood.',
						),
						array(
							'icon'  => 'key',
							'title' => 'Self check-in',
							'text'  => 'A key box by the door, with the code sent once you are confirmed.',
						),
						array(
							'icon'  => 'country',
							'title' => 'Trails from the door',
							'text'  => 'Forest paths start at the gate.',
						),
					),
					'qm_address'        => '14 Hollow Lane, Fernbrook Valley (fictional)',
					'qm_area_note'      => 'Forest valley, quiet at night, no street lights.',
					'qm_property_kind'  => 'cabin',
					'qm_max_guests'     => 4,
					'qm_bedrooms'       => 2,
					'qm_beds'           => 3,
					'qm_baths'          => 1,
					'qm_floor_area'     => 78,
					'qm_spaces'         => array(
						array(
							'name'     => 'Loft bedroom',
							'size'     => 16,
							'sleeping' => '1 double bed',
						),
						array(
							'name'     => 'Bunk room',
							'size'     => 9,
							'sleeping' => '2 single beds',
						),
					),
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 185,
					'qm_weekend_price'  => 215,
					'qm_cleaning_fee'   => 60,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 14,
					'qm_seasons'        => array( self::season( 'Winter holidays', '12-18', '01-03', 245 ) ),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(
						array(
							'label' => 'Extra firewood',
							'price' => 25,
							'unit'  => 'per_stay',
						),
						array(
							'label' => 'Breakfast basket',
							'price' => 18,
							'unit'  => 'per_guest',
						),
						array(
							'label' => 'Late checkout until 14:00',
							'price' => 35,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'moderate',
				),
			),
			'boathouse-on-the-pond'      => array(
				'title'      => 'Boathouse on the Pond',
				'host'       => DemoReset::HOST_LOGIN,
				'location'   => 'larch-lake',
				'categories' => array( 'lakefront', 'countryside' ),
				'amenities'  => array( 'wifi', 'kitchen', 'fireplace', 'lake-view', 'parking', 'heating', 'smoke-alarm' ),
				'offset'     => array( -0.008, 0.011 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire boathouse on a still pond',
					'qm_description'    => "A small wooden boathouse with a deck that runs right round the building and steps down to the water. Inside: a fireplace room with soft chairs, a galley kitchen and one bedroom.\n\nThe host confirms each request, usually the same day.",
					'qm_instant_book'   => 0,
					'qm_highlights'     => array(
						array(
							'icon'  => 'view',
							'title' => 'On the water',
							'text'  => 'The deck sits a step above the pond.',
						),
						array(
							'icon'  => 'fire',
							'title' => 'Fireplace room',
							'text'  => 'Armchairs around a stone fireplace.',
						),
					),
					'qm_address'        => 'Pond Road, Larch Lake (fictional)',
					'qm_area_note'      => 'Lakeside meadow with a gravel track to the door.',
					'qm_property_kind'  => 'cabin',
					'qm_max_guests'     => 2,
					'qm_bedrooms'       => 1,
					'qm_beds'           => 1,
					'qm_baths'          => 1,
					'qm_floor_area'     => 42,
					'qm_spaces'         => array(
						array(
							'name'     => 'Bedroom',
							'size'     => 12,
							'sleeping' => '1 queen bed',
						),
					),
					'qm_check_in_time'  => '16:00',
					'qm_check_out_time' => '10:30',
					'qm_nightly_price'  => 165,
					'qm_weekend_price'  => 0,
					'qm_cleaning_fee'   => 45,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 10,
					'qm_seasons'        => array( self::season( 'Summer', '06-15', '08-31', 195 ) ),
					'qm_deposit_rule'   => 'first_night',
					'qm_deposit_value'  => 0,
					'qm_extras'         => array(
						array(
							'label' => 'Rowing boat for the stay',
							'price' => 30,
							'unit'  => 'per_stay',
						),
						array(
							'label' => 'Breakfast basket',
							'price' => 18,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'    => array_merge( $stay_rules, array( array( 'rule' => 'Life jackets on in the boat' ) ) ),
					'qm_cancellation'   => 'flexible',
				),
			),
			'clifftop-pool-villa'        => array(
				'title'      => 'Clifftop Pool Villa',
				'host'       => 'host-mara',
				'location'   => 'saltmarsh-coast',
				'categories' => array( 'villas', 'pools', 'beach' ),
				'amenities'  => array( 'wifi', 'kitchen', 'pool', 'sea-view', 'air', 'parking', 'washer', 'workspace', 'smoke-alarm' ),
				'offset'     => array( 0.009, -0.014 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire villa with a long pool terrace',
					'qm_description'    => "A white villa with timber ceilings and glass doors that fold back onto the pool terrace. Four bedrooms, a garden pavilion and loungers under the palms.\n\nRequests are confirmed by the host. Minimum three nights.",
					'qm_instant_book'   => 0,
					'qm_highlights'     => array(
						array(
							'icon'  => 'pool',
							'title' => 'Private pool',
							'text'  => 'A long pool with loungers and parasols.',
						),
						array(
							'icon'  => 'beach',
							'title' => 'Sea air',
							'text'  => 'A short walk down to the shore path.',
						),
						array(
							'icon'  => 'workspace',
							'title' => 'Room to work',
							'text'  => 'A desk and fast wifi in the study.',
						),
					),
					'qm_address'        => '3 Cliff Walk, Saltmarsh Coast (fictional)',
					'qm_area_note'      => 'Coast road, windy in the afternoon.',
					'qm_property_kind'  => 'villa',
					'qm_max_guests'     => 8,
					'qm_bedrooms'       => 4,
					'qm_beds'           => 5,
					'qm_baths'          => 3.5,
					'qm_floor_area'     => 240,
					'qm_spaces'         => array(
						array(
							'name'     => 'Main bedroom',
							'size'     => 28,
							'sleeping' => '1 king bed',
						),
						array(
							'name'     => 'Garden room',
							'size'     => 20,
							'sleeping' => '1 queen bed',
						),
						array(
							'name'     => 'Twin rooms',
							'size'     => 30,
							'sleeping' => '3 single beds across two rooms',
						),
					),
					'qm_check_in_time'  => '16:00',
					'qm_check_out_time' => '10:00',
					'qm_nightly_price'  => 520,
					'qm_weekend_price'  => 590,
					'qm_cleaning_fee'   => 150,
					'qm_min_nights'     => 3,
					'qm_max_nights'     => 21,
					'qm_seasons'        => array( self::season( 'Summer', '06-20', '09-05', 680 ) ),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 25,
					'qm_extras'         => array(
						array(
							'label' => 'Pool heating',
							'price' => 40,
							'unit'  => 'per_night',
						),
						array(
							'label' => 'Mid-stay clean',
							'price' => 90,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => array_merge( $stay_rules, array( array( 'rule' => 'Children by the pool only with an adult' ) ) ),
					'qm_cancellation'   => 'strict',
				),
			),
			'garden-plunge-pool-villa'   => array(
				'title'      => 'Garden Plunge Pool Villa',
				'host'       => 'host-mara',
				'location'   => 'kestrel-hills',
				'categories' => array( 'villas', 'pools', 'countryside' ),
				'amenities'  => array( 'wifi', 'kitchen', 'pool', 'garden', 'air', 'parking', 'self-check', 'family', 'smoke-alarm' ),
				'offset'     => array( -0.011, 0.016 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire villa with a walled garden',
					'qm_description'    => "A courtyard villa built around a plunge pool, with stepping stones, palms and a stone water wall. The pavilion has a hanging swing; the bedrooms open onto the garden.\n\nInstant book: a paid reservation is confirmed straight away.",
					'qm_instant_book'   => 1,
					'qm_highlights'     => array(
						array(
							'icon'  => 'pool',
							'title' => 'Plunge pool',
							'text'  => 'Sheltered in a walled garden.',
						),
						array(
							'icon'  => 'key',
							'title' => 'Self check-in',
							'text'  => 'Smart lock with a code for your dates.',
						),
					),
					'qm_address'        => '22 Kestrel Rise, Kestrel Hills (fictional)',
					'qm_area_note'      => 'Vineyard hills, ten minutes from the village.',
					'qm_property_kind'  => 'villa',
					'qm_max_guests'     => 6,
					'qm_bedrooms'       => 3,
					'qm_beds'           => 4,
					'qm_baths'          => 2,
					'qm_floor_area'     => 160,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 380,
					'qm_weekend_price'  => 420,
					'qm_cleaning_fee'   => 110,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 14,
					'qm_seasons'        => array( self::season( 'Harvest', '09-01', '10-15', 440 ) ),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(
						array(
							'label' => 'Cot and high chair',
							'price' => 15,
							'unit'  => 'per_stay',
						),
						array(
							'label' => 'Late checkout until 14:00',
							'price' => 60,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'moderate',
				),
			),
			'white-courtyard-house'      => array(
				'title'      => 'White Courtyard House',
				'host'       => 'host-ines',
				'location'   => 'old-harbour',
				'categories' => array( 'city', 'pools' ),
				'amenities'  => array( 'wifi', 'kitchen', 'pool', 'air', 'washer', 'workspace', 'step-free', 'smoke-alarm' ),
				'offset'     => array( 0.014, -0.022 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire home with a pool, minutes from the harbour',
					'qm_description'    => "A white and timber house with tall windows over a lawn and a pool. The covered terrace has outdoor sofas and a kitchen, and inside it is calm and bright.\n\nStep-free from the street to the terrace.",
					'qm_instant_book'   => 1,
					'qm_highlights'     => array(
						array(
							'icon'  => 'accessible',
							'title' => 'Step-free',
							'text'  => 'Level access from the street to the terrace.',
						),
						array(
							'icon'  => 'pool',
							'title' => 'Pool and terrace',
							'text'  => 'Outdoor kitchen beside the pool.',
						),
					),
					'qm_address'        => '9 Chalk Street, Old Harbour (fictional)',
					'qm_area_note'      => 'Residential street, twelve minutes on foot to the harbour.',
					'qm_property_kind'  => 'home',
					'qm_max_guests'     => 5,
					'qm_bedrooms'       => 2,
					'qm_beds'           => 3,
					'qm_baths'          => 2,
					'qm_floor_area'     => 120,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 240,
					'qm_weekend_price'  => 275,
					'qm_cleaning_fee'   => 80,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 28,
					'qm_seasons'        => array(),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(
						array(
							'label' => 'Airport pick-up',
							'price' => 55,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'moderate',
				),
			),
			'sunlit-brick-loft'          => array(
				'title'      => 'Sunlit Brick Loft',
				'host'       => 'host-ines',
				'location'   => 'old-harbour',
				'categories' => array( 'lofts', 'city' ),
				'amenities'  => array( 'wifi', 'kitchen', 'heating', 'washer', 'workspace', 'self-check', 'smoke-alarm' ),
				'offset'     => array( -0.004, 0.006 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire loft in a converted warehouse',
					'qm_description'    => "One open room with brick walls and tall windows, a long dining table and a green velvet sofa. The bedroom corner has a round mirror and sheer curtains.\n\nThird floor, with a lift.",
					'qm_instant_book'   => 1,
					'qm_highlights'     => array(
						array(
							'icon'  => 'design',
							'title' => 'Warehouse loft',
							'text'  => 'Brick, steel and tall windows.',
						),
						array(
							'icon'  => 'workspace',
							'title' => 'Dedicated workspace',
							'text'  => 'Desk by the window and fast wifi.',
						),
					),
					'qm_address'        => '40 Mill Yard, Old Harbour (fictional)',
					'qm_area_note'      => 'Busy at weekends, quiet inside.',
					'qm_property_kind'  => 'apartment',
					'qm_max_guests'     => 2,
					'qm_bedrooms'       => 0,
					'qm_beds'           => 1,
					'qm_baths'          => 1,
					'qm_floor_area'     => 64,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '14:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 150,
					'qm_weekend_price'  => 175,
					'qm_cleaning_fee'   => 40,
					'qm_min_nights'     => 1,
					'qm_max_nights'     => 30,
					'qm_seasons'        => array(),
					'qm_deposit_rule'   => 'flat',
					'qm_deposit_value'  => 50,
					'qm_extras'         => array(
						array(
							'label' => 'Early check-in from 11:00',
							'price' => 25,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'flexible',
				),
			),
			'quiet-city-apartment'       => array(
				'title'      => 'Quiet City Apartment',
				'host'       => 'host-ines',
				'location'   => 'old-harbour',
				'categories' => array( 'city' ),
				'amenities'  => array( 'wifi', 'kitchen', 'heating', 'washer', 'family', 'smoke-alarm' ),
				'offset'     => array( 0.007, 0.013 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire apartment over a tree-lined street',
					'qm_description'    => "A calm one bedroom apartment with a big sectional sofa, a breakfast nook over the trees and a bedroom with a city view.\n\nThe host confirms each request.",
					'qm_instant_book'   => 0,
					'qm_highlights'     => array(
						array(
							'icon'  => 'city',
							'title' => 'Central and quiet',
							'text'  => 'Faces the trees, not the road.',
						),
					),
					'qm_address'        => '5 Linden Row, Old Harbour (fictional)',
					'qm_area_note'      => 'Shops and a park on the corner.',
					'qm_property_kind'  => 'apartment',
					'qm_max_guests'     => 3,
					'qm_bedrooms'       => 1,
					'qm_beds'           => 2,
					'qm_baths'          => 1,
					'qm_floor_area'     => 58,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 135,
					'qm_weekend_price'  => 0,
					'qm_cleaning_fee'   => 35,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 30,
					'qm_seasons'        => array(),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'moderate',
				),
			),
			'stone-cottage-in-the-hills' => array(
				'title'      => 'Stone Cottage in the Hills',
				'host'       => DemoReset::HOST_LOGIN,
				'location'   => 'kestrel-hills',
				'categories' => array( 'cottages', 'countryside' ),
				'amenities'  => array( 'wifi', 'kitchen', 'garden', 'heating', 'parking', 'pets', 'smoke-alarm' ),
				'offset'     => array( 0.018, 0.009 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire cottage on a hill farm',
					'qm_description'    => "A stone cottage with blue trim and a slate roof, set in sheep pasture. The farmhouse kitchen has a long table and copper pans; upstairs are two bedrooms with shutters.\n\nDogs are welcome.",
					'qm_instant_book'   => 1,
					'qm_highlights'     => array(
						array(
							'icon'  => 'pet',
							'title' => 'Dogs welcome',
							'text'  => 'Up to two dogs, with a fenced garden.',
						),
						array(
							'icon'  => 'kitchen',
							'title' => 'Farmhouse kitchen',
							'text'  => 'A long table for slow dinners.',
						),
					),
					'qm_address'        => 'Hill Farm, Kestrel Hills (fictional)',
					'qm_area_note'      => 'Single track road, last mile unpaved.',
					'qm_property_kind'  => 'home',
					'qm_max_guests'     => 4,
					'qm_bedrooms'       => 2,
					'qm_beds'           => 2,
					'qm_baths'          => 1.5,
					'qm_floor_area'     => 90,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '10:00',
					'qm_nightly_price'  => 195,
					'qm_weekend_price'  => 220,
					'qm_cleaning_fee'   => 55,
					'qm_min_nights'     => 2,
					'qm_max_nights'     => 21,
					'qm_seasons'        => array( self::season( 'Spring lambing', '03-20', '04-30', 210 ) ),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(
						array(
							'label' => 'Dog stay',
							'price' => 12,
							'unit'  => 'per_night',
						),
						array(
							'label' => 'Farm breakfast',
							'price' => 16,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'    => array_merge( $stay_rules, array( array( 'rule' => 'Dogs stay off the beds' ) ) ),
					'qm_cancellation'   => 'moderate',
				),
			),
			'palm-terrace-beach-house'   => array(
				'title'      => 'Palm Terrace Beach House',
				'host'       => 'host-mara',
				'location'   => 'saltmarsh-coast',
				'categories' => array( 'beach' ),
				'amenities'  => array( 'wifi', 'kitchen', 'sea-view', 'air', 'washer', 'outdoor', 'family', 'smoke-alarm' ),
				'offset'     => array( -0.016, -0.007 ),
				'fields'     => array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'Entire beach house behind the dunes',
					'qm_description'    => "An open lounge with built in sofas facing the palms and the sea, a covered wooden terrace with a hanging chair and a white arched dining room.\n\nThe host confirms each request.",
					'qm_instant_book'   => 0,
					'qm_highlights'     => array(
						array(
							'icon'  => 'beach',
							'title' => 'Beach behind the dunes',
							'text'  => 'Two minutes on a sandy path.',
						),
						array(
							'icon'  => 'terrace',
							'title' => 'Covered terrace',
							'text'  => 'Shade all afternoon.',
						),
					),
					'qm_address'        => '1 Dune Path, Saltmarsh Coast (fictional)',
					'qm_area_note'      => 'Small beach village with a cafe and a shop.',
					'qm_property_kind'  => 'home',
					'qm_max_guests'     => 6,
					'qm_bedrooms'       => 3,
					'qm_beds'           => 4,
					'qm_baths'          => 2,
					'qm_floor_area'     => 140,
					'qm_spaces'         => array(),
					'qm_check_in_time'  => '16:00',
					'qm_check_out_time' => '10:00',
					'qm_nightly_price'  => 310,
					'qm_weekend_price'  => 345,
					'qm_cleaning_fee'   => 95,
					'qm_min_nights'     => 3,
					'qm_max_nights'     => 21,
					'qm_seasons'        => array( self::season( 'Summer', '06-15', '09-10', 395 ) ),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(
						array(
							'label' => 'Beach kit: umbrella and chairs',
							'price' => 20,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'    => $stay_rules,
					'qm_cancellation'   => 'strict',
				),
			),
			'the-brick-kitchen'          => array(
				'title'      => 'The Brick Kitchen',
				'host'       => 'host-ines',
				'location'   => 'old-harbour',
				'categories' => array( 'bistros' ),
				'amenities'  => array( 'vegetarian', 'wine-list', 'step-free' ),
				'offset'     => array( -0.009, -0.012 ),
				'fields'     => array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'Seasonal bistro with a long communal table',
					'qm_description'     => "A bistro in an old brick warehouse. The set menu changes with the market: a starter to share, fish or a vegetable main, and a small dessert.\n\nTables are kept for 90 minutes.",
					'qm_instant_book'    => 1,
					'qm_highlights'      => array(
						array(
							'icon'  => 'bistro',
							'title' => 'Market menu',
							'text'  => 'Three courses that follow the season.',
						),
						array(
							'icon'  => 'veg',
							'title' => 'Vegetarian main',
							'text'  => 'Always one on the menu.',
						),
					),
					'qm_address'         => '2 Mill Yard, Old Harbour (fictional)',
					'qm_area_note'       => 'Ground floor, step-free entrance.',
					'qm_cuisine'         => 'Seasonal bistro',
					'qm_menu_price'      => 48,
					'qm_covers'          => 24,
					'qm_min_party'       => 1,
					'qm_max_party'       => 8,
					'qm_slot_minutes'    => '30',
					'qm_seating_minutes' => 90,
					'qm_periods'         => array(
						array(
							'label' => 'Lunch',
							'days'  => array( 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
							'start' => '12:00',
							'end'   => '14:00',
						),
						array(
							'label' => 'Dinner',
							'days'  => array( 'tue', 'wed', 'thu', 'fri', 'sat' ),
							'start' => '18:00',
							'end'   => '21:30',
						),
					),
					'qm_tables'          => array(
						array(
							'label' => 'Communal table',
							'seats' => 12,
							'count' => 1,
						),
						array(
							'label' => 'Tables for two',
							'seats' => 2,
							'count' => 6,
						),
					),
					'qm_deposit_rule'    => 'per_guest',
					'qm_deposit_value'   => 10,
					'qm_extras'          => array(
						array(
							'label' => 'Celebration cake',
							'price' => 24,
							'unit'  => 'per_stay',
						),
						array(
							'label' => 'Wine pairing',
							'price' => 28,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'     => array(
						array( 'rule' => 'Tables are kept for 90 minutes' ),
						array( 'rule' => 'Tell us about allergies in the note' ),
					),
					'qm_cancellation'    => 'flexible',
				),
			),
			'nine-courses'               => array(
				'title'      => 'Nine Courses',
				'host'       => 'host-mara',
				'location'   => 'kestrel-hills',
				'categories' => array( 'tasting-menus' ),
				'amenities'  => array( 'vegetarian', 'wine-list' ),
				'offset'     => array( 0.004, -0.019 ),
				'fields'     => array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'A nine course tasting menu, four evenings a week',
					'qm_description'     => "A small dining room with low lamps and twelve seats per sitting. One tasting menu of nine courses, built around the hills: lamb, grilled artichokes, garden vegetables.\n\nThe host confirms each table.",
					'qm_instant_book'    => 0,
					'qm_highlights'      => array(
						array(
							'icon'  => 'fine',
							'title' => 'One tasting menu',
							'text'  => 'Nine courses over about three hours.',
						),
					),
					'qm_address'         => '7 Vine Lane, Kestrel Hills (fictional)',
					'qm_area_note'       => 'Parking in the yard.',
					'qm_cuisine'         => 'Tasting menu',
					'qm_menu_price'      => 135,
					'qm_covers'          => 12,
					'qm_min_party'       => 2,
					'qm_max_party'       => 6,
					'qm_slot_minutes'    => '60',
					'qm_seating_minutes' => 180,
					'qm_periods'         => array(
						array(
							'label' => 'Dinner',
							'days'  => array( 'wed', 'thu', 'fri', 'sat' ),
							'start' => '18:00',
							'end'   => '20:00',
						),
					),
					'qm_tables'          => array(
						array(
							'label' => 'Dining room',
							'seats' => 2,
							'count' => 6,
						),
					),
					'qm_deposit_rule'    => 'per_guest',
					'qm_deposit_value'   => 40,
					'qm_extras'          => array(
						array(
							'label' => 'Wine pairing',
							'price' => 65,
							'unit'  => 'per_guest',
						),
						array(
							'label' => 'Non-alcoholic pairing',
							'price' => 38,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'     => array(
						array( 'rule' => 'Please arrive on time: the menu starts for the whole room' ),
						array( 'rule' => 'Dietary needs with at least two days notice' ),
					),
					'qm_cancellation'    => 'strict',
				),
			),
			'the-garden-table'           => array(
				'title'      => 'The Garden Table',
				'host'       => DemoReset::HOST_LOGIN,
				'location'   => 'fernbrook-valley',
				'categories' => array( 'terraces' ),
				'amenities'  => array( 'outdoor', 'vegetarian', 'family', 'parking' ),
				'offset'     => array( -0.015, 0.008 ),
				'fields'     => array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'Garden restaurant with a shaded terrace',
					'qm_description'     => "Long tables on a shaded patio and a veranda over the forest. The kitchen cooks from its own garden: spring vegetables, grains and grilled fish.\n\nOpen for weekend lunch and evening dinner.",
					'qm_instant_book'    => 1,
					'qm_highlights'      => array(
						array(
							'icon'  => 'terrace',
							'title' => 'Outdoor tables',
							'text'  => 'Shaded patio and a covered veranda.',
						),
						array(
							'icon'  => 'child',
							'title' => 'Family friendly',
							'text'  => 'High chairs and a small menu for children.',
						),
					),
					'qm_address'         => 'Mill Road, Fernbrook Valley (fictional)',
					'qm_area_note'       => 'On the valley road, with a car park.',
					'qm_cuisine'         => 'Garden kitchen',
					'qm_menu_price'      => 42,
					'qm_covers'          => 30,
					'qm_min_party'       => 1,
					'qm_max_party'       => 10,
					'qm_slot_minutes'    => '30',
					'qm_seating_minutes' => 120,
					'qm_periods'         => array(
						array(
							'label' => 'Lunch',
							'days'  => array( 'fri', 'sat', 'sun' ),
							'start' => '12:00',
							'end'   => '14:30',
						),
						array(
							'label' => 'Dinner',
							'days'  => array( 'thu', 'fri', 'sat' ),
							'start' => '18:00',
							'end'   => '20:30',
						),
					),
					'qm_tables'          => array(
						array(
							'label' => 'Patio',
							'seats' => 6,
							'count' => 3,
						),
						array(
							'label' => 'Veranda',
							'seats' => 4,
							'count' => 3,
						),
					),
					'qm_deposit_rule'    => 'flat',
					'qm_deposit_value'   => 20,
					'qm_extras'          => array(
						array(
							'label' => 'Garden tour before the meal',
							'price' => 10,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'     => array(
						array( 'rule' => 'Outdoor tables move inside if it rains' ),
					),
					'qm_cancellation'    => 'flexible',
				),
			),
			'corner-light-cafe'          => array(
				'title'      => 'Corner Light Cafe',
				'host'       => DemoReset::HOST_LOGIN,
				'location'   => 'old-harbour',
				'categories' => array( 'cafes' ),
				'amenities'  => array( 'wifi', 'vegetarian', 'step-free', 'family' ),
				'offset'     => array( 0.011, 0.002 ),
				'fields'     => array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'All day cafe with a weekend brunch set',
					'qm_description'     => "A bright corner cafe with paper lanterns, hanging plants and long tables. Book a table for the brunch set: a plate of the day, bread and a hot drink.\n\nTables are kept for an hour.",
					'qm_instant_book'    => 1,
					'qm_highlights'      => array(
						array(
							'icon'  => 'cafe',
							'title' => 'Brunch set',
							'text'  => 'A plate of the day, bread and a hot drink.',
						),
					),
					'qm_address'         => '1 Lantern Corner, Old Harbour (fictional)',
					'qm_area_note'       => 'On the corner of the market square.',
					'qm_cuisine'         => 'Cafe and brunch',
					'qm_menu_price'      => 22,
					'qm_covers'          => 16,
					'qm_min_party'       => 1,
					'qm_max_party'       => 6,
					'qm_slot_minutes'    => '30',
					'qm_seating_minutes' => 60,
					'qm_periods'         => array(
						array(
							'label' => 'Brunch',
							'days'  => array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
							'start' => '08:00',
							'end'   => '13:00',
						),
					),
					'qm_tables'          => array(
						array(
							'label' => 'Long tables',
							'seats' => 8,
							'count' => 2,
						),
					),
					'qm_deposit_rule'    => 'flat',
					'qm_deposit_value'   => 20,
					'qm_extras'          => array(
						array(
							'label' => 'Fresh juice',
							'price' => 6,
							'unit'  => 'per_guest',
						),
					),
					'qm_house_rules'     => array(
						array( 'rule' => 'Tables are kept for 60 minutes' ),
					),
					'qm_cancellation'    => 'flexible',
				),
			),
			'low-lamp-wine-bar'          => array(
				'title'      => 'Low Lamp Wine Bar',
				'host'       => 'host-ines',
				'location'   => 'old-harbour',
				'categories' => array( 'wine-bars' ),
				'amenities'  => array( 'wine-list', 'vegetarian' ),
				'offset'     => array( -0.002, -0.004 ),
				'fields'     => array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'Wine flights and small plates by lamplight',
					'qm_description'     => "A dim bar with arched shelves of bottles, a leather banquette and round wooden tables. Book a table for a flight of three wines with small plates.\n\nThe host confirms each table.",
					'qm_instant_book'    => 0,
					'qm_highlights'      => array(
						array(
							'icon'  => 'wine',
							'title' => 'Three wine flight',
							'text'  => 'Chosen each week, with small plates.',
						),
					),
					'qm_address'         => '18 Cellar Steps, Old Harbour (fictional)',
					'qm_area_note'       => 'Down six steps from the street.',
					'qm_cuisine'         => 'Wine bar and small plates',
					'qm_menu_price'      => 38,
					'qm_covers'          => 20,
					'qm_min_party'       => 1,
					'qm_max_party'       => 6,
					'qm_slot_minutes'    => '30',
					'qm_seating_minutes' => 90,
					'qm_periods'         => array(
						array(
							'label' => 'Evening',
							'days'  => array( 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
							'start' => '17:00',
							'end'   => '22:00',
						),
					),
					'qm_tables'          => array(
						array(
							'label' => 'Banquette',
							'seats' => 4,
							'count' => 3,
						),
						array(
							'label' => 'Bar seats',
							'seats' => 1,
							'count' => 8,
						),
					),
					'qm_deposit_rule'    => 'flat',
					'qm_deposit_value'   => 20,
					'qm_extras'          => array(
						array(
							'label' => 'Cheese board',
							'price' => 18,
							'unit'  => 'per_stay',
						),
					),
					'qm_house_rules'     => array(
						array( 'rule' => 'Over 21 only' ),
					),
					'qm_cancellation'    => 'moderate',
				),
			),
		);
	}
}
