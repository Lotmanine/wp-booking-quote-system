<?php
/**
 * Custom fields, defined in code.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Listings;

defined( 'ABSPATH' ) || exit;

/**
 * Field groups for Secure Custom Fields. They live here, not in the database, so they are
 * versioned with the code and the same on every install.
 */
final class Fields {

	public const ICONS = array(
		'home'       => 'Home',
		'cabin'      => 'Cabin',
		'beach'      => 'Beach',
		'city'       => 'City',
		'country'    => 'Countryside',
		'pool'       => 'Pool',
		'view'       => 'View',
		'design'     => 'Design',
		'bistro'     => 'Bistro',
		'fine'       => 'Fine dining',
		'cafe'       => 'Cafe',
		'wine'       => 'Wine',
		'terrace'    => 'Terrace',
		'wifi'       => 'Wifi',
		'kitchen'    => 'Kitchen',
		'parking'    => 'Parking',
		'washer'     => 'Washer',
		'air'        => 'Air conditioning',
		'heating'    => 'Heating',
		'fire'       => 'Fireplace',
		'workspace'  => 'Workspace',
		'key'        => 'Self check-in',
		'leaf'       => 'Garden',
		'accessible' => 'Step-free access',
		'child'      => 'Child friendly',
		'pet'        => 'Pets allowed',
		'smoke'      => 'Smoke alarm',
		'veg'        => 'Vegetarian options',
		'calendar'   => 'Calendar',
		'door'       => 'Door',
	);

	public const WEEKDAYS = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'acf/init', array( self::class, 'register_groups' ) );
	}

	/**
	 * Show a field only for one listing type.
	 *
	 * @param string $type Listing type value.
	 * @return array<int,array<int,array<string,string>>>
	 */
	private static function only( string $type ): array {
		return array(
			array(
				array(
					'field'    => 'field_qm_type',
					'operator' => '==',
					'value'    => $type,
				),
			),
		);
	}

	/**
	 * Build a field definition.
	 *
	 * @param string              $name  Field name, which is also the meta key.
	 * @param string              $type  Field type.
	 * @param string              $label Label.
	 * @param array<string,mixed> $extra Extra settings.
	 * @return array<string,mixed>
	 */
	public static function field( string $name, string $type, string $label, array $extra = array() ): array {
		return array_merge(
			array(
				'key'   => 'field_' . $name,
				'name'  => $name,
				'type'  => $type,
				'label' => $label,
			),
			$extra
		);
	}

	/**
	 * Tab separator.
	 *
	 * @param string $key   Unique key.
	 * @param string $label Tab label.
	 * @param string $only  Listing type the tab belongs to, or an empty string for both.
	 * @return array<string,mixed>
	 */
	private static function tab( string $key, string $label, string $only = '' ): array {
		$tab = array(
			'key'       => 'field_qm_tab_' . $key,
			'name'      => '',
			'type'      => 'tab',
			'label'     => $label,
			'placement' => 'top',
		);
		if ( '' !== $only ) {
			$tab['conditional_logic'] = self::only( $only );
		}
		return $tab;
	}

	/**
	 * Sub field names of a repeater.
	 *
	 * @param string $name Repeater name.
	 * @return string[]
	 */
	public static function sub_field_names( string $name ): array {
		foreach ( self::raw_listing_fields() as $field ) {
			if ( ( $field['name'] ?? '' ) === $name && isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				return array_map( static fn( array $sub ): string => (string) $sub['name'], $field['sub_fields'] );
			}
		}
		return array();
	}

	/**
	 * Every field of a listing, keyed for reuse by the seeder and the tests.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function listing_fields(): array {
		return self::scope_sub_fields( self::raw_listing_fields() );
	}

	/**
	 * Sub field keys must be unique across the site, so they take their parent's name.
	 *
	 * @param array<int,array<string,mixed>> $fields Fields.
	 * @return array<int,array<string,mixed>>
	 */
	private static function scope_sub_fields( array $fields ): array {
		foreach ( $fields as $i => $field ) {
			if ( empty( $field['sub_fields'] ) || ! is_array( $field['sub_fields'] ) ) {
				continue;
			}
			foreach ( $field['sub_fields'] as $j => $sub ) {
				$fields[ $i ]['sub_fields'][ $j ]['key'] = 'field_' . $field['name'] . '_' . $sub['name'];
			}
		}
		return $fields;
	}

	/**
	 * Listing fields before key scoping.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function raw_listing_fields(): array {
		$money = array(
			'min'     => 0,
			'step'    => 1,
			'prepend' => '$',
		);

		return array(
			self::tab( 'basics', 'Basics' ),
			self::field(
				'qm_type',
				'button_group',
				'Listing type',
				array(
					'choices'       => array(
						'stay'   => 'Place to stay',
						'dining' => 'Place to eat',
					),
					'default_value' => 'stay',
					'required'      => 1,
				)
			),
			self::field(
				'qm_summary',
				'text',
				'Short summary',
				array(
					'instructions' => 'Shown under the title, for example "Entire cabin" or "Seasonal tasting menu".',
					'maxlength'    => 80,
					'required'     => 1,
				)
			),
			self::field(
				'qm_description',
				'textarea',
				'Description',
				array(
					'rows'      => 6,
					'maxlength' => 2000,
					'required'  => 1,
				)
			),
			self::field(
				'qm_instant_book',
				'true_false',
				'Instant book',
				array(
					'instructions' => 'On: paid reservations confirm straight away. Off: you confirm or decline each request within 24 hours.',
					'ui'           => 1,
				)
			),
			self::field(
				'qm_highlights',
				'repeater',
				'Highlights',
				array(
					'max'          => 4,
					'layout'       => 'table',
					'button_label' => 'Add highlight',
					'sub_fields'   => array(
						self::field(
							'icon',
							'select',
							'Icon',
							array( 'choices' => self::ICONS )
						),
						self::field( 'title', 'text', 'Title', array( 'maxlength' => 60 ) ),
						self::field( 'text', 'text', 'Detail', array( 'maxlength' => 140 ) ),
					),
				)
			),

			self::tab( 'photos', 'Photos' ),
			self::field(
				'qm_gallery',
				'gallery',
				'Photos',
				array(
					'instructions'  => 'The first photo is the cover. Use your own photos or freely licensed ones, and add the credit in the caption.',
					'return_format' => 'id',
					'min'           => 1,
					'max'           => 12,
					'mime_types'    => 'jpg,jpeg,png,webp',
					'max_size'      => 3,
					'required'      => 1,
				)
			),

			self::tab( 'where', 'Location' ),
			self::field(
				'qm_address',
				'text',
				'Street address',
				array(
					'instructions' => 'Shared with guests only once their booking is confirmed.',
					'maxlength'    => 160,
				)
			),
			self::field(
				'qm_lat',
				'number',
				'Latitude',
				array(
					'instructions' => 'Click the map below to set the point. Guests see an area around it before they book.',
					'min'          => -90,
					'max'          => 90,
					'step'         => 0.000001,
				)
			),
			self::field(
				'qm_lng',
				'number',
				'Longitude',
				array(
					'min'  => -180,
					'max'  => 180,
					'step' => 0.000001,
				)
			),
			self::field( 'qm_area_note', 'text', 'About the area', array( 'maxlength' => 200 ) ),

			self::tab( 'stay', 'Rooms and guests', 'stay' ),
			self::field(
				'qm_property_kind',
				'select',
				'Property kind',
				array(
					'choices'           => array(
						'home'      => 'Entire home',
						'cabin'     => 'Cabin',
						'apartment' => 'Apartment',
						'villa'     => 'Villa',
						'room'      => 'Private room',
					),
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_max_guests',
				'number',
				'Guests',
				array(
					'min'               => 1,
					'max'               => 16,
					'default_value'     => 2,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_bedrooms',
				'number',
				'Bedrooms',
				array(
					'min'               => 0,
					'max'               => 10,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_beds',
				'number',
				'Beds',
				array(
					'min'               => 1,
					'max'               => 16,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_baths',
				'number',
				'Bathrooms',
				array(
					'min'               => 0,
					'max'               => 10,
					'step'              => 0.5,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_floor_area',
				'number',
				'Floor area',
				array(
					'min'               => 0,
					'append'            => 'm²',
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_spaces',
				'repeater',
				'Rooms and spaces',
				array(
					'layout'            => 'table',
					'max'               => 10,
					'button_label'      => 'Add a room',
					'conditional_logic' => self::only( 'stay' ),
					'sub_fields'        => array(
						self::field( 'name', 'text', 'Room', array( 'maxlength' => 40 ) ),
						self::field(
							'size',
							'number',
							'Size',
							array(
								'min'    => 0,
								'append' => 'm²',
							)
						),
						self::field( 'sleeping', 'text', 'Beds', array( 'maxlength' => 60 ) ),
					),
				)
			),
			self::field(
				'qm_check_in_time',
				'time_picker',
				'Check-in from',
				array(
					'display_format'    => 'H:i',
					'return_format'     => 'H:i',
					'default_value'     => '15:00',
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_check_out_time',
				'time_picker',
				'Check-out by',
				array(
					'display_format'    => 'H:i',
					'return_format'     => 'H:i',
					'default_value'     => '11:00',
					'conditional_logic' => self::only( 'stay' ),
				)
			),

			self::tab( 'rates', 'Nightly rates', 'stay' ),
			self::field( 'qm_nightly_price', 'number', 'Price per night', $money + array( 'conditional_logic' => self::only( 'stay' ) ) ),
			self::field(
				'qm_weekend_price',
				'number',
				'Friday and Saturday nights',
				$money + array(
					'instructions'      => 'Leave empty to use the nightly price.',
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field( 'qm_cleaning_fee', 'number', 'Cleaning fee', $money + array( 'conditional_logic' => self::only( 'stay' ) ) ),
			self::field(
				'qm_min_nights',
				'number',
				'Minimum nights',
				array(
					'min'               => 1,
					'max'               => 30,
					'default_value'     => 1,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_max_nights',
				'number',
				'Maximum nights',
				array(
					'min'               => 1,
					'max'               => 60,
					'default_value'     => 14,
					'conditional_logic' => self::only( 'stay' ),
				)
			),
			self::field(
				'qm_seasons',
				'repeater',
				'Seasonal rates',
				array(
					'instructions'      => 'A seasonal rate replaces the nightly and weekend price for every night inside its dates.',
					'layout'            => 'table',
					'max'               => 8,
					'button_label'      => 'Add a season',
					'conditional_logic' => self::only( 'stay' ),
					'sub_fields'        => array(
						self::field( 'label', 'text', 'Season', array( 'maxlength' => 40 ) ),
						self::field(
							'start',
							'date_picker',
							'From',
							array(
								'display_format' => 'Y-m-d',
								'return_format'  => 'Y-m-d',
							)
						),
						self::field(
							'end',
							'date_picker',
							'To',
							array(
								'display_format' => 'Y-m-d',
								'return_format'  => 'Y-m-d',
							)
						),
						self::field( 'price', 'number', 'Per night', $money ),
					),
				)
			),

			self::tab( 'dining', 'Service and seats', 'dining' ),
			self::field( 'qm_cuisine', 'text', 'Cuisine', array( 'conditional_logic' => self::only( 'dining' ) ) ),
			self::field(
				'qm_menu_price',
				'number',
				'Set menu per guest',
				$money + array( 'conditional_logic' => self::only( 'dining' ) )
			),
			self::field(
				'qm_covers',
				'number',
				'Seats per time slot',
				array(
					'instructions'      => 'How many guests can start a meal at the same time.',
					'min'               => 1,
					'max'               => 200,
					'default_value'     => 20,
					'conditional_logic' => self::only( 'dining' ),
				)
			),
			self::field(
				'qm_min_party',
				'number',
				'Smallest party',
				array(
					'min'               => 1,
					'max'               => 20,
					'default_value'     => 1,
					'conditional_logic' => self::only( 'dining' ),
				)
			),
			self::field(
				'qm_max_party',
				'number',
				'Largest party',
				array(
					'min'               => 1,
					'max'               => 20,
					'default_value'     => 8,
					'conditional_logic' => self::only( 'dining' ),
				)
			),
			self::field(
				'qm_slot_minutes',
				'select',
				'Time slot length',
				array(
					'choices'           => array(
						'15' => '15 minutes',
						'30' => '30 minutes',
						'60' => '60 minutes',
					),
					'default_value'     => '30',
					'conditional_logic' => self::only( 'dining' ),
				)
			),
			self::field(
				'qm_seating_minutes',
				'number',
				'Table kept for',
				array(
					'min'               => 30,
					'max'               => 300,
					'default_value'     => 90,
					'append'            => 'minutes',
					'conditional_logic' => self::only( 'dining' ),
				)
			),
			self::field(
				'qm_periods',
				'repeater',
				'Service periods',
				array(
					'layout'            => 'block',
					'max'               => 6,
					'button_label'      => 'Add a service period',
					'conditional_logic' => self::only( 'dining' ),
					'sub_fields'        => array(
						self::field( 'label', 'text', 'Service', array( 'maxlength' => 30 ) ),
						self::field(
							'days',
							'checkbox',
							'Days',
							array(
								'choices' => self::WEEKDAYS,
								'layout'  => 'horizontal',
							)
						),
						self::field(
							'start',
							'time_picker',
							'First seating',
							array(
								'display_format' => 'H:i',
								'return_format'  => 'H:i',
							)
						),
						self::field(
							'end',
							'time_picker',
							'Last seating',
							array(
								'display_format' => 'H:i',
								'return_format'  => 'H:i',
							)
						),
					),
				)
			),
			self::field(
				'qm_tables',
				'repeater',
				'Tables',
				array(
					'layout'            => 'table',
					'max'               => 10,
					'button_label'      => 'Add tables',
					'conditional_logic' => self::only( 'dining' ),
					'sub_fields'        => array(
						self::field( 'label', 'text', 'Area', array( 'maxlength' => 40 ) ),
						self::field(
							'seats',
							'number',
							'Seats',
							array(
								'min' => 1,
								'max' => 20,
							)
						),
						self::field(
							'count',
							'number',
							'Tables',
							array(
								'min' => 1,
								'max' => 50,
							)
						),
					),
				)
			),

			self::tab( 'money', 'Deposit and extras' ),
			self::field(
				'qm_deposit_rule',
				'select',
				'Deposit',
				array(
					'choices'       => array(
						'percent'     => 'A percentage of the total',
						'per_guest'   => 'A fixed amount per guest',
						'flat'        => 'A fixed amount per booking',
						'first_night' => 'The first night',
					),
					'default_value' => 'percent',
				)
			),
			self::field(
				'qm_deposit_value',
				'number',
				'Deposit amount',
				array(
					'instructions'  => 'Percent, or dollars, depending on the rule. Deposits are never below $20 or above the total.',
					'min'           => 0,
					'default_value' => 20,
				)
			),
			self::field(
				'qm_extras',
				'repeater',
				'Extras',
				array(
					'layout'       => 'table',
					'max'          => 10,
					'button_label' => 'Add an extra',
					'sub_fields'   => array(
						self::field( 'label', 'text', 'Extra', array( 'maxlength' => 60 ) ),
						self::field( 'price', 'number', 'Price', $money ),
						self::field(
							'unit',
							'select',
							'Charged',
							array(
								'choices' => array(
									'per_stay'  => 'Once per booking',
									'per_night' => 'Per night',
									'per_guest' => 'Per guest',
								),
							)
						),
					),
				)
			),

			self::tab( 'rules', 'Rules and policy' ),
			self::field(
				'qm_house_rules',
				'repeater',
				'House rules',
				array(
					'layout'       => 'table',
					'max'          => 10,
					'button_label' => 'Add a rule',
					'sub_fields'   => array(
						self::field( 'rule', 'text', 'Rule', array( 'maxlength' => 120 ) ),
					),
				)
			),
			self::field(
				'qm_cancellation',
				'radio',
				'Cancellation policy',
				array(
					'choices'       => array(
						'flexible' => 'Flexible: full deposit refund up to 24 hours before',
						'moderate' => 'Moderate: full deposit refund up to 5 days before',
						'strict'   => 'Strict: full deposit refund up to 14 days before',
					),
					'default_value' => 'moderate',
				)
			),
		);
	}

	/**
	 * Register every group.
	 */
	public static function register_groups(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_local_field_group(
			array(
				'key'                   => 'group_qm_listing',
				'title'                 => 'Listing details',
				'fields'                => self::listing_fields(),
				'location'              => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => PostTypes::LISTING,
						),
					),
				),
				'position'              => 'acf_after_title',
				'style'                 => 'seamless',
				'hide_on_screen'        => array( 'the_content', 'excerpt', 'featured_image', 'comments', 'discussion', 'slug' ),
				'instruction_placement' => 'field',
			)
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_qm_location',
				'title'    => 'Map position',
				'fields'   => array(
					self::field(
						'qm_lat',
						'number',
						'Latitude',
						array(
							'key'  => 'field_qm_location_lat',
							'min'  => -90,
							'max'  => 90,
							'step' => 0.000001,
						)
					),
					self::field(
						'qm_lng',
						'number',
						'Longitude',
						array(
							'key'  => 'field_qm_location_lng',
							'min'  => -180,
							'max'  => 180,
							'step' => 0.000001,
						)
					),
					self::field( 'qm_region', 'text', 'Region', array( 'maxlength' => 60 ) ),
				),
				'location' => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => PostTypes::LOCATION,
						),
					),
				),
			)
		);

		$icon = static fn( string $key ): array => self::field(
			'qm_icon',
			'select',
			'Icon',
			array(
				'key'     => $key,
				'choices' => self::ICONS,
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_qm_category',
				'title'    => 'Category display',
				'fields'   => array_merge(
					array( $icon( 'field_qm_category_icon' ) ),
					array(
						self::field(
							'qm_for',
							'select',
							'Shown for',
							array(
								'choices' => array(
									'stay'   => 'Places to stay',
									'dining' => 'Places to eat',
								),
							)
						),
					)
				),
				'location' => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => PostTypes::CATEGORY,
						),
					),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_qm_amenity',
				'title'    => 'Amenity display',
				'fields'   => array( $icon( 'field_qm_amenity_icon' ) ),
				'location' => array(
					array(
						array(
							'param'    => 'taxonomy',
							'operator' => '==',
							'value'    => PostTypes::AMENITY,
						),
					),
				),
			)
		);
	}
}
