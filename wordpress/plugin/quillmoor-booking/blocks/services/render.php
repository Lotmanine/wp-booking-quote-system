<?php
/**
 * Service results. Cards render on the server; view.js filters and sorts them in place.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Catalog;

defined( 'ABSPATH' ) || exit;

$quillmoor_mode     = ( $attributes['mode'] ?? 'browse' ) === 'featured' ? 'featured' : 'browse';
$quillmoor_catalog  = Catalog::all();
$quillmoor_category = Blocks::query_key( 'category', 'categories' );
$quillmoor_area     = Blocks::query_key( 'area', 'areas' );
$quillmoor_size     = Blocks::query_key( 'size', 'sizes' );
$quillmoor_date     = Blocks::query_date( 'date' );
$quillmoor_mult     = (int) $quillmoor_catalog['sizes'][ '' !== $quillmoor_size ? $quillmoor_size : 'small' ]['multiplier'];
$quillmoor_carry    = array_filter(
	array(
		'area' => $quillmoor_area,
		'date' => $quillmoor_date,
		'size' => $quillmoor_size,
	)
);

$quillmoor_cards = array();
$quillmoor_order = 0;
foreach ( $quillmoor_catalog['services'] as $quillmoor_key => $quillmoor_service ) {
	preg_match_all( '/\d+/', (string) $quillmoor_service['hours'], $quillmoor_hours );
	$quillmoor_cards[] = array(
		'key'      => $quillmoor_key,
		'service'  => $quillmoor_service,
		'price'    => (int) round( (int) $quillmoor_service['base_cents'] * $quillmoor_mult / 100 ),
		'max_h'    => (int) max( array_merge( array( 0 ), $quillmoor_hours[0] ) ),
		'order'    => $quillmoor_order++,
		'url'      => add_query_arg( array( 'service' => $quillmoor_key ) + $quillmoor_carry, home_url( '/book/' ) ),
		'category' => (string) $quillmoor_service['category'],
	);
}
$quillmoor_area_label = '' !== $quillmoor_area ? Catalog::label( 'areas', $quillmoor_area ) : '';
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-results qhs-results--' . $quillmoor_mode ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> data-qhs-results data-mode="<?php echo esc_attr( $quillmoor_mode ); ?>" aria-label="<?php esc_attr_e( 'Services', 'quillmoor-booking' ); ?>">
	<?php if ( 'browse' === $quillmoor_mode ) : ?>
		<div class="qhs-results__search">
			<?php
			echo render_block( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered block markup, escaped inside.
				array(
					'blockName'    => 'quillmoor/search',
					'attrs'        => array( 'compact' => true ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
			?>
		</div>
	<?php endif; ?>

	<div class="qhs-chips" role="<?php echo 'browse' === $quillmoor_mode ? 'group' : 'navigation'; ?>" aria-label="<?php esc_attr_e( 'Categories', 'quillmoor-booking' ); ?>">
		<?php
		$quillmoor_chips = array( '' => __( 'All services', 'quillmoor-booking' ) );
		foreach ( $quillmoor_catalog['categories'] as $quillmoor_key => $quillmoor_item ) {
			$quillmoor_chips[ $quillmoor_key ] = $quillmoor_item['label'];
		}
		foreach ( $quillmoor_chips as $quillmoor_key => $quillmoor_label ) :
			$quillmoor_active = $quillmoor_key === $quillmoor_category;
			$quillmoor_href   = add_query_arg( array_filter( array( 'category' => $quillmoor_key ) ) + $quillmoor_carry, home_url( '/services/' ) );
			?>
			<a class="qhs-chip<?php echo $quillmoor_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $quillmoor_href ); ?>" data-category="<?php echo esc_attr( $quillmoor_key ); ?>" data-testid="chip-<?php echo esc_attr( '' === $quillmoor_key ? 'all' : $quillmoor_key ); ?>"<?php echo $quillmoor_active ? ' aria-current="true"' : ''; ?>>
				<?php echo Blocks::icon( '' === $quillmoor_key ? 'all' : $quillmoor_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
				<span><?php echo esc_html( $quillmoor_label ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( 'browse' === $quillmoor_mode ) : ?>
		<div class="qhs-toolbar">
			<p class="qhs-toolbar__count" aria-live="polite" data-qhs-count>
				<?php
				/* translators: %d: number of services */
				echo esc_html( sprintf( _n( '%d service', '%d services', count( $quillmoor_cards ), 'quillmoor-booking' ), count( $quillmoor_cards ) ) );
				if ( '' !== $quillmoor_area_label ) {
					/* translators: %s: district name */
					echo esc_html( sprintf( __( ' in %s', 'quillmoor-booking' ), $quillmoor_area_label ) );
				}
				?>
			</p>
			<div class="qhs-toolbar__controls">
				<button type="button" class="qhs-btn qhs-btn--secondary qhs-btn--sm" aria-expanded="false" aria-controls="qhs-filters" data-qhs-filters-toggle data-testid="filters-toggle" hidden>
					<?php esc_html_e( 'Filters', 'quillmoor-booking' ); ?> <span class="qhs-badge-count" data-qhs-filter-count hidden></span>
				</button>
				<label class="qhs-sort">
					<span><?php esc_html_e( 'Sort', 'quillmoor-booking' ); ?></span>
					<select data-qhs-sort data-testid="sort">
						<option value="recommended"><?php esc_html_e( 'Recommended', 'quillmoor-booking' ); ?></option>
						<option value="price-asc"><?php esc_html_e( 'Price: low to high', 'quillmoor-booking' ); ?></option>
						<option value="price-desc"><?php esc_html_e( 'Price: high to low', 'quillmoor-booking' ); ?></option>
						<option value="length"><?php esc_html_e( 'Shortest visit', 'quillmoor-booking' ); ?></option>
					</select>
				</label>
			</div>
		</div>
		<div class="qhs-filters" id="qhs-filters" data-qhs-filters hidden></div>
	<?php endif; ?>

	<div class="qhs-grid" data-qhs-grid>
		<?php foreach ( $quillmoor_cards as $quillmoor_card ) : ?>
			<article class="qhs-svc" data-key="<?php echo esc_attr( $quillmoor_card['key'] ); ?>" data-category="<?php echo esc_attr( $quillmoor_card['category'] ); ?>" data-price="<?php echo esc_attr( (string) $quillmoor_card['price'] ); ?>" data-hours="<?php echo esc_attr( (string) $quillmoor_card['max_h'] ); ?>" data-order="<?php echo esc_attr( (string) $quillmoor_card['order'] ); ?>" data-testid="service-card-<?php echo esc_attr( $quillmoor_card['key'] ); ?>"<?php echo ( '' !== $quillmoor_category && $quillmoor_category !== $quillmoor_card['category'] ) ? ' hidden' : ''; ?>>
				<a class="qhs-svc__link" href="<?php echo esc_url( $quillmoor_card['url'] ); ?>">
					<span class="qhs-svc__media"><img src="<?php echo esc_url( Catalog::image_url( $quillmoor_card['key'] ) ); ?>" alt="" width="640" height="400" loading="lazy" decoding="async"></span>
					<span class="qhs-svc__body">
						<span class="qhs-svc__top">
							<span class="qhs-tag"><?php echo esc_html( Catalog::label( 'categories', $quillmoor_card['category'] ) ); ?></span>
							<span class="qhs-svc__hours"><?php echo Blocks::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( (string) $quillmoor_card['service']['hours'] ); ?></span>
						</span>
						<span class="qhs-svc__title"><?php echo esc_html( (string) $quillmoor_card['service']['label'] ); ?></span>
						<span class="qhs-svc__summary"><?php echo esc_html( (string) $quillmoor_card['service']['summary'] ); ?></span>
						<span class="qhs-svc__price"><strong><?php echo esc_html( '$' . number_format( $quillmoor_card['price'] / 100 ) ); ?></strong> <?php esc_html_e( 'from, fictional', 'quillmoor-booking' ); ?></span>
					</span>
				</a>
			</article>
		<?php endforeach; ?>
	</div>
	<div class="qhs-empty" data-qhs-empty hidden>
		<p><?php esc_html_e( 'No service matches these filters.', 'quillmoor-booking' ); ?></p>
		<button type="button" class="qhs-btn qhs-btn--secondary" data-qhs-clear><?php esc_html_e( 'Clear filters', 'quillmoor-booking' ); ?></button>
	</div>
	<?php if ( 'featured' === $quillmoor_mode ) : ?>
		<p class="qhs-results__more"><a class="qhs-link-arrow" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Compare all services', 'quillmoor-booking' ); ?> <span aria-hidden="true">&rarr;</span></a></p>
	<?php endif; ?>
</section>
