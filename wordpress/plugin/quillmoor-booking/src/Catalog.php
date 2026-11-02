<?php
/**
 * Fictional service catalog and pricing rules.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * All prices are fictional demo values, in integer US cents.
 */
final class Catalog {

	/**
	 * Full catalog, filterable so a site can change rates without editing this file.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public static function all(): array {
		$catalog = array(
			'services' => array(
				'cleaning'  => array(
					'label'      => 'Home cleaning',
					'base_cents' => 12000,
					'skill'      => 'cleaning',
				),
				'handyman'  => array(
					'label'      => 'Handyman visit',
					'base_cents' => 9000,
					'skill'      => 'handyman',
				),
				'gardening' => array(
					'label'      => 'Garden tidy',
					'base_cents' => 8000,
					'skill'      => 'garden',
				),
			),
			'sizes'    => array(
				'small'  => array(
					'label'      => 'Small (up to 2 rooms)',
					'multiplier' => 100,
				),
				'medium' => array(
					'label'      => 'Medium (3 to 4 rooms)',
					'multiplier' => 140,
				),
				'large'  => array(
					'label'      => 'Large (5 rooms or more)',
					'multiplier' => 190,
				),
			),
			'areas'    => array(
				'north'     => array(
					'label'           => 'North district',
					'surcharge_cents' => 0,
					'capacity'        => 1,
				),
				'central'   => array(
					'label'           => 'Central district',
					'surcharge_cents' => 1500,
					'capacity'        => 2,
				),
				'riverside' => array(
					'label'           => 'Riverside district',
					'surcharge_cents' => 2500,
					'capacity'        => 1,
				),
			),
			'extras'   => array(
				'supplies' => array(
					'label' => 'We bring supplies',
					'cents' => 1500,
				),
				'windows'  => array(
					'label' => 'Interior windows',
					'cents' => 3500,
				),
				'waste'    => array(
					'label' => 'Waste removal',
					'cents' => 2500,
				),
			),
		);

		/**
		 * Filters the catalog.
		 *
		 * @param array $catalog Services, sizes, areas and extras.
		 */
		return apply_filters( 'quillmoor_catalog', $catalog );
	}

	/**
	 * Compute a price on the server. Client-supplied prices are never read.
	 *
	 * @param string   $service Service key.
	 * @param string   $size    Size key.
	 * @param string   $area    Area key.
	 * @param string[] $extras  Extra keys.
	 * @return array{line_items: array<int,array{label:string,cents:int}>, total_cents:int, deposit_cents:int}
	 * @throws InvalidArgumentException When a key is not in the catalog.
	 */
	public static function price( string $service, string $size, string $area, array $extras ): array {
		$catalog = self::all();
		foreach (
			array(
				'services' => $service,
				'sizes'    => $size,
				'areas'    => $area,
			) as $group => $key
		) {
			if ( ! isset( $catalog[ $group ][ $key ] ) ) {
				throw new InvalidArgumentException( 'Unknown option.' );
			}
		}

		$service_row = $catalog['services'][ $service ];
		$size_row    = $catalog['sizes'][ $size ];
		$area_row    = $catalog['areas'][ $area ];

		$base  = (int) round( (int) $service_row['base_cents'] * (int) $size_row['multiplier'] / 100 );
		$lines = array(
			array(
				'label' => $service_row['label'] . ', ' . $size_row['label'],
				'cents' => $base,
			),
		);
		if ( (int) $area_row['surcharge_cents'] > 0 ) {
			$lines[] = array(
				'label' => $area_row['label'] . ' travel',
				'cents' => (int) $area_row['surcharge_cents'],
			);
		}
		foreach ( array_unique( $extras ) as $extra ) {
			if ( ! isset( $catalog['extras'][ $extra ] ) ) {
				throw new InvalidArgumentException( 'Unknown option.' );
			}
			$lines[] = array(
				'label' => (string) $catalog['extras'][ $extra ]['label'],
				'cents' => (int) $catalog['extras'][ $extra ]['cents'],
			);
		}

		$total = (int) array_sum( array_column( $lines, 'cents' ) );

		return array(
			'line_items'    => $lines,
			'total_cents'   => $total,
			'deposit_cents' => self::deposit_for( $total ),
		);
	}

	/**
	 * Deposit: 20 percent rounded to the dollar, minimum 20 dollars.
	 *
	 * @param int $total_cents Quote total.
	 */
	public static function deposit_for( int $total_cents ): int {
		$deposit = (int) ( round( $total_cents * 0.20 / 100 ) * 100 );
		return max( 2000, $deposit );
	}

	/**
	 * Crews available per slot in an area.
	 *
	 * @param string $area Area key.
	 */
	public static function capacity( string $area ): int {
		$catalog = self::all();
		return (int) ( $catalog['areas'][ $area ]['capacity'] ?? 0 );
	}

	/**
	 * Skill a service needs.
	 *
	 * @param string $service Service key.
	 */
	public static function skill( string $service ): string {
		$catalog = self::all();
		return (string) ( $catalog['services'][ $service ]['skill'] ?? '' );
	}

	/**
	 * Display label.
	 *
	 * @param string $group Catalog group.
	 * @param string $key   Item key.
	 */
	public static function label( string $group, string $key ): string {
		$catalog = self::all();
		return (string) ( $catalog[ $group ][ $key ]['label'] ?? $key );
	}

	/**
	 * Format cents as a display amount.
	 *
	 * @param int $cents Amount.
	 */
	public static function money( int $cents ): string {
		return '$' . number_format( $cents / 100, 2 );
	}
}
