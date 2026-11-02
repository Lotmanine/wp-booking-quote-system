<?php
/**
 * Search bar. A plain GET form to the results page; view.js turns each field into a panel.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

$quillmoor_catalog  = Catalog::all();
$quillmoor_category = Blocks::query_key( 'category', 'categories' );
$quillmoor_area     = Blocks::query_key( 'area', 'areas' );
$quillmoor_size     = Blocks::query_key( 'size', 'sizes' );
$quillmoor_date     = Blocks::query_date( 'date' );
$quillmoor_min      = gmdate( 'Y-m-d', time() + Config::MIN_LEAD_HOURS * HOUR_IN_SECONDS );
$quillmoor_max      = gmdate( 'Y-m-d', time() + Config::BOOKING_DAYS_AHEAD * DAY_IN_SECONDS );
$quillmoor_compact  = ! empty( $attributes['compact'] );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-search-wrap' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>>
<form class="qhs-search<?php echo $quillmoor_compact ? ' is-compact' : ''; ?>" action="<?php echo esc_url( home_url( '/services/' ) ); ?>" method="get" role="search" aria-label="<?php esc_attr_e( 'Find a service', 'quillmoor-booking' ); ?>" data-qhs-search data-testid="search">
	<div class="qhs-search__seg" data-field="category">
		<label class="qhs-search__label" for="qhs-s-category"><?php esc_html_e( 'Service', 'quillmoor-booking' ); ?></label>
		<select id="qhs-s-category" name="category" class="qhs-search__native">
			<option value=""><?php esc_html_e( 'Any service', 'quillmoor-booking' ); ?></option>
			<?php foreach ( $quillmoor_catalog['categories'] as $quillmoor_key => $quillmoor_item ) : ?>
				<option value="<?php echo esc_attr( $quillmoor_key ); ?>" <?php selected( $quillmoor_category, $quillmoor_key ); ?>><?php echo esc_html( $quillmoor_item['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="qhs-search__seg" data-field="area">
		<label class="qhs-search__label" for="qhs-s-area"><?php esc_html_e( 'Where', 'quillmoor-booking' ); ?></label>
		<select id="qhs-s-area" name="area" class="qhs-search__native">
			<option value=""><?php esc_html_e( 'Any district', 'quillmoor-booking' ); ?></option>
			<?php foreach ( $quillmoor_catalog['areas'] as $quillmoor_key => $quillmoor_item ) : ?>
				<option value="<?php echo esc_attr( $quillmoor_key ); ?>" <?php selected( $quillmoor_area, $quillmoor_key ); ?>><?php echo esc_html( $quillmoor_item['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<div class="qhs-search__seg" data-field="date">
		<label class="qhs-search__label" for="qhs-s-date"><?php esc_html_e( 'When', 'quillmoor-booking' ); ?></label>
		<input id="qhs-s-date" type="date" name="date" class="qhs-search__native" value="<?php echo esc_attr( $quillmoor_date ); ?>" min="<?php echo esc_attr( $quillmoor_min ); ?>" max="<?php echo esc_attr( $quillmoor_max ); ?>">
	</div>
	<div class="qhs-search__seg" data-field="size">
		<label class="qhs-search__label" for="qhs-s-size"><?php esc_html_e( 'Home size', 'quillmoor-booking' ); ?></label>
		<select id="qhs-s-size" name="size" class="qhs-search__native">
			<option value=""><?php esc_html_e( 'Any size', 'quillmoor-booking' ); ?></option>
			<?php foreach ( $quillmoor_catalog['sizes'] as $quillmoor_key => $quillmoor_item ) : ?>
				<option value="<?php echo esc_attr( $quillmoor_key ); ?>" <?php selected( $quillmoor_size, $quillmoor_key ); ?>><?php echo esc_html( $quillmoor_item['label'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<button type="submit" class="qhs-search__go" data-testid="search-submit">
		<?php echo Blocks::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
		<span><?php esc_html_e( 'Search', 'quillmoor-booking' ); ?></span>
	</button>
</form>
</div>
