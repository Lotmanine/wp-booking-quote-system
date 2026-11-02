<?php
/**
 * Explore: category row, photo-first listing grid and the map. Rendered on the server; the script
 * only adds the photo arrows and the map.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\Locations;
use Quillmoor\Booking\Listings\Search;

defined( 'ABSPATH' ) || exit;

$quillmoor_section = isset( $attributes['section'] ) && 'home' === $attributes['section'] ? 'home' : 'results';
$quillmoor_filters = Blocks::query_filters();

if ( ! function_exists( 'quillmoor_card' ) ) {
	/**
	 * One listing card.
	 *
	 * @param array<string,mixed> $card  Card data from Search::card().
	 * @param bool                $eager Whether the first photo loads eagerly.
	 */
	function quillmoor_card( array $card, bool $eager = false ): string {
		$photos = '';
		foreach ( (array) $card['photos'] as $i => $photo ) {
			$photos .= sprintf(
				'<img src="%s" alt="%s" width="%d" height="%d" loading="%s" decoding="async">',
				esc_url( (string) $photo['url'] ),
				esc_attr( (string) $photo['alt'] ),
				(int) $photo['width'],
				(int) $photo['height'],
				$eager && 0 === $i ? 'eager' : 'lazy'
			);
		}
		$count = count( (array) $card['photos'] );
		$price = '' !== $card['total']
			? '<strong>' . esc_html( (string) $card['total'] ) . '</strong> <span>' . esc_html( $card['price']['amount'] . ' a night' ) . '</span>'
			: '<strong>' . esc_html( (string) $card['price']['amount'] ) . '</strong> <span>' . esc_html( 'stay' === $card['type'] ? 'a night' : 'per guest, set menu' ) . '</span>';
		return sprintf(
			'<article class="qm-card" data-testid="listing-card" data-listing="%1$d" data-type="%2$s" data-qm-card>
				<div class="qm-card__media">
					<div class="qm-card__photos" data-qm-photos tabindex="-1">%3$s</div>
					%4$s
					%5$s
				</div>
				<a class="qm-card__link" href="%6$s">
					<span class="qm-card__title">%7$s</span>
					<span class="qm-card__line">%8$s</span>
					<span class="qm-card__line qm-card__line--muted">%9$s</span>
					<span class="qm-card__price">%10$s</span>
				</a>
			</article>',
			(int) $card['id'],
			esc_attr( (string) $card['type'] ),
			$photos,
			$card['instant'] ? '<span class="qm-card__tag">' . esc_html__( 'Instant book', 'quillmoor-booking' ) . '</span>' : '',
			$count > 1 ? '<button type="button" class="qm-card__nav qm-card__nav--prev" data-dir="-1" aria-label="' . esc_attr__( 'Previous photo', 'quillmoor-booking' ) . '" hidden></button><button type="button" class="qm-card__nav qm-card__nav--next" data-dir="1" aria-label="' . esc_attr__( 'Next photo', 'quillmoor-booking' ) . '"></button><span class="qm-card__dots" aria-hidden="true">' . str_repeat( '<i></i>', $count ) . '</span>' : '',
			esc_url( (string) $card['url'] ),
			esc_html( (string) $card['title'] ),
			esc_html( $card['kind'] . ( '' !== $card['location'] ? ' in ' . $card['location'] : '' ) ),
			esc_html( implode( ', ', (array) $card['facts'] ) ),
			$price
		);
	}
}

if ( 'home' === $quillmoor_section ) :
	$quillmoor_rows = array(
		'stay'   => array( __( 'Places to stay', 'quillmoor-booking' ), __( 'Cabins, villas, cottages and city homes, booked by the night.', 'quillmoor-booking' ), __( 'Show all stays', 'quillmoor-booking' ) ),
		'dining' => array( __( 'Places to eat', 'quillmoor-booking' ), __( 'Bistros, a tasting menu, a garden terrace, a cafe and a wine bar, booked by the table.', 'quillmoor-booking' ), __( 'Show all restaurants', 'quillmoor-booking' ) ),
	);
	?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-explore qm-explore--home' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>>
		<?php foreach ( $quillmoor_rows as $quillmoor_type => $quillmoor_row ) : ?>
			<?php
			$quillmoor_find  = array_merge( $quillmoor_filters, array( 'type' => $quillmoor_type ) );
			$quillmoor_found = Search::find( $quillmoor_find );
			?>
			<section class="qm-shelf" data-testid="shelf-<?php echo esc_attr( $quillmoor_type ); ?>">
				<header class="qm-shelf__head">
					<div>
						<h2><?php echo esc_html( $quillmoor_row[0] ); ?></h2>
						<p><?php echo esc_html( $quillmoor_row[1] ); ?></p>
					</div>
					<a class="qm-shelf__more" href="<?php echo esc_url( add_query_arg( 'type', $quillmoor_type, home_url( '/explore/' ) ) ); ?>"><?php echo esc_html( $quillmoor_row[2] ); ?></a>
				</header>
				<div class="qm-grid">
					<?php
					foreach ( array_slice( $quillmoor_found, 0, 'stay' === $quillmoor_type ? 8 : 5 ) as $quillmoor_i => $quillmoor_listing ) {
						echo quillmoor_card( Search::card( $quillmoor_listing, $quillmoor_find ), $quillmoor_i < 4 && 'stay' === $quillmoor_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the card helper.
					}
					?>
				</div>
			</section>
		<?php endforeach; ?>
	</div>
	<?php
	return;
endif;

$quillmoor_type  = '' === $quillmoor_filters['type'] ? 'stay' : $quillmoor_filters['type'];
$quillmoor_find  = array_merge( $quillmoor_filters, array( 'type' => $quillmoor_type ) );
$quillmoor_found = Search::find( $quillmoor_find );
$quillmoor_cards = array_map( static fn( Listing $l ): array => Search::card( $l, $quillmoor_find ), $quillmoor_found );
$quillmoor_cats  = Locations::categories( $quillmoor_type );
$quillmoor_base  = remove_query_arg( 'category' );
$quillmoor_place = '' === $quillmoor_filters['location'] ? '' : Locations::name( $quillmoor_filters['location'] );
$quillmoor_title = 'stay' === $quillmoor_type
	? sprintf( /* translators: %d: count */ _n( '%d place to stay', '%d places to stay', count( $quillmoor_cards ), 'quillmoor-booking' ), count( $quillmoor_cards ) )
	: sprintf( /* translators: %d: count */ _n( '%d place to eat', '%d places to eat', count( $quillmoor_cards ), 'quillmoor-booking' ), count( $quillmoor_cards ) );
$quillmoor_map   = array_values(
	array_filter(
		array_map(
			static fn( array $c ): ?array => null === $c['lat'] ? null : array(
				'id'    => $c['id'],
				'lat'   => $c['lat'],
				'lng'   => $c['lng'],
				'label' => $c['price']['amount'],
				'title' => $c['title'],
				'url'   => $c['url'],
				'photo' => $c['photos'][0]['url'] ?? '',
				'line'  => $c['kind'] . ( '' !== $c['location'] ? ' in ' . $c['location'] : '' ),
			),
			$quillmoor_cards
		)
	)
);
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-explore' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> data-qm-explore>
	<nav class="qm-cats" aria-label="<?php esc_attr_e( 'Categories', 'quillmoor-booking' ); ?>">
		<a class="qm-cats__item<?php echo '' === $quillmoor_filters['category'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $quillmoor_base ); ?>" <?php echo '' === $quillmoor_filters['category'] ? 'aria-current="page"' : ''; ?> data-testid="category-all">
			<?php echo Blocks::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
			<span><?php esc_html_e( 'All', 'quillmoor-booking' ); ?></span>
		</a>
		<?php foreach ( $quillmoor_cats as $quillmoor_cat ) : ?>
			<?php $quillmoor_on = $quillmoor_cat['slug'] === $quillmoor_filters['category']; ?>
			<a class="qm-cats__item<?php echo $quillmoor_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'category', $quillmoor_cat['slug'], $quillmoor_base ) ); ?>" <?php echo $quillmoor_on ? 'aria-current="page"' : ''; ?> data-testid="category-<?php echo esc_attr( $quillmoor_cat['slug'] ); ?>">
				<?php echo Blocks::icon( $quillmoor_cat['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
				<span><?php echo esc_html( $quillmoor_cat['name'] ); ?></span>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="qm-explore__split">
		<div class="qm-explore__list">
			<div class="qm-explore__bar">
				<h1 class="qm-explore__title" data-testid="results-title"><?php echo esc_html( $quillmoor_title . ( '' !== $quillmoor_place ? ' in ' . $quillmoor_place : '' ) ); ?></h1>
				<p class="qm-explore__note">
					<?php
					if ( 'stay' === $quillmoor_type && '' !== $quillmoor_filters['check_in'] && '' !== $quillmoor_filters['check_out'] ) {
						esc_html_e( 'Showing places free for your dates. Prices include every night and the cleaning fee, before extras.', 'quillmoor-booking' );
					} elseif ( 'dining' === $quillmoor_type && '' !== $quillmoor_filters['date'] ) {
						esc_html_e( 'Showing places with a free table on your date. Times are in UTC.', 'quillmoor-booking' );
					} else {
						esc_html_e( 'Add dates to see only what is free. Every place and price here is fictional.', 'quillmoor-booking' );
					}
					?>
				</p>
			</div>
			<?php if ( array() === $quillmoor_cards ) : ?>
				<div class="qm-empty" data-testid="results-empty">
					<h2><?php esc_html_e( 'Nothing free for that search', 'quillmoor-booking' ); ?></h2>
					<p><?php esc_html_e( 'Try other dates, fewer guests or another place.', 'quillmoor-booking' ); ?></p>
					<a class="qm-btn qm-btn--ghost" href="<?php echo esc_url( add_query_arg( 'type', $quillmoor_type, home_url( '/explore/' ) ) ); ?>"><?php esc_html_e( 'Clear the filters', 'quillmoor-booking' ); ?></a>
				</div>
			<?php else : ?>
				<div class="qm-grid qm-grid--results">
					<?php
					foreach ( $quillmoor_cards as $quillmoor_i => $quillmoor_card ) {
						echo quillmoor_card( $quillmoor_card, $quillmoor_i < 3 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the card helper.
					}
					?>
				</div>
			<?php endif; ?>
		</div>
		<aside class="qm-explore__map" aria-label="<?php esc_attr_e( 'Map', 'quillmoor-booking' ); ?>">
			<div class="qm-map" data-qm-map data-points="<?php echo esc_attr( (string) wp_json_encode( $quillmoor_map ) ); ?>" data-testid="results-map"></div>
			<p class="qm-map__note"><?php esc_html_e( 'Pins show approximate, fictional positions.', 'quillmoor-booking' ); ?></p>
		</aside>
	</div>
	<button type="button" class="qm-maptoggle" data-qm-maptoggle aria-pressed="false" data-testid="map-toggle">
		<?php echo Blocks::icon( 'map', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
		<span data-label-show="<?php esc_attr_e( 'Show map', 'quillmoor-booking' ); ?>" data-label-hide="<?php esc_attr_e( 'Show list', 'quillmoor-booking' ); ?>"><?php esc_html_e( 'Show map', 'quillmoor-booking' ); ?></span>
	</button>
</div>
