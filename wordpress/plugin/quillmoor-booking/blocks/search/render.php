<?php
/**
 * Search pill: where, when and who. A plain GET form, so it works before any script runs.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Listings\Locations;

defined( 'ABSPATH' ) || exit;

$quillmoor_f    = Blocks::query_filters();
$quillmoor_type = 'dining' === $quillmoor_f['type'] ? 'dining' : 'stay';
$quillmoor_min  = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );
$quillmoor_who  = 'dining' === $quillmoor_type ? max( $quillmoor_f['party'], $quillmoor_f['guests'] ) : $quillmoor_f['guests'];
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-search' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>>
	<form class="qm-search__form" method="get" action="<?php echo esc_url( home_url( '/explore/' ) ); ?>" role="search" data-qm-search data-type="<?php echo esc_attr( $quillmoor_type ); ?>" aria-label="<?php esc_attr_e( 'Search places', 'quillmoor-booking' ); ?>">
		<fieldset class="qm-search__tabs">
			<legend class="screen-reader-text"><?php esc_html_e( 'What are you looking for', 'quillmoor-booking' ); ?></legend>
			<label class="qm-search__tab"><input type="radio" name="type" value="stay" <?php checked( 'stay', $quillmoor_type ); ?> data-testid="search-type-stay"><span><?php esc_html_e( 'Stays', 'quillmoor-booking' ); ?></span></label>
			<label class="qm-search__tab"><input type="radio" name="type" value="dining" <?php checked( 'dining', $quillmoor_type ); ?> data-testid="search-type-dining"><span><?php esc_html_e( 'Restaurants', 'quillmoor-booking' ); ?></span></label>
		</fieldset>
		<div class="qm-search__pill">
			<label class="qm-search__field qm-search__field--where">
				<span class="qm-search__label"><?php esc_html_e( 'Where', 'quillmoor-booking' ); ?></span>
				<select name="where" data-testid="search-where">
					<option value=""><?php esc_html_e( 'Anywhere', 'quillmoor-booking' ); ?></option>
					<?php foreach ( Locations::all() as $quillmoor_loc ) : ?>
						<option value="<?php echo esc_attr( $quillmoor_loc['slug'] ); ?>" <?php selected( $quillmoor_f['location'], $quillmoor_loc['slug'] ); ?>><?php echo esc_html( $quillmoor_loc['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="qm-search__field" data-for="stay">
				<span class="qm-search__label"><?php esc_html_e( 'Check in', 'quillmoor-booking' ); ?></span>
				<input type="date" name="check_in" min="<?php echo esc_attr( $quillmoor_min ); ?>" value="<?php echo esc_attr( $quillmoor_f['check_in'] ); ?>" data-testid="search-check-in">
			</label>
			<label class="qm-search__field" data-for="stay">
				<span class="qm-search__label"><?php esc_html_e( 'Check out', 'quillmoor-booking' ); ?></span>
				<input type="date" name="check_out" min="<?php echo esc_attr( $quillmoor_min ); ?>" value="<?php echo esc_attr( $quillmoor_f['check_out'] ); ?>" data-testid="search-check-out">
			</label>
			<label class="qm-search__field" data-for="dining">
				<span class="qm-search__label"><?php esc_html_e( 'Date', 'quillmoor-booking' ); ?></span>
				<input type="date" name="date" min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( $quillmoor_f['date'] ); ?>" data-testid="search-date">
			</label>
			<label class="qm-search__field qm-search__field--who">
				<span class="qm-search__label"><?php esc_html_e( 'Who', 'quillmoor-booking' ); ?></span>
				<select name="guests" data-testid="search-guests">
					<option value=""><?php esc_html_e( 'Add guests', 'quillmoor-booking' ); ?></option>
					<?php for ( $quillmoor_i = 1; $quillmoor_i <= 10; $quillmoor_i++ ) : ?>
						<option value="<?php echo (int) $quillmoor_i; ?>" <?php selected( $quillmoor_who, $quillmoor_i ); ?>><?php echo esc_html( sprintf( /* translators: %d: guests */ _n( '%d guest', '%d guests', $quillmoor_i, 'quillmoor-booking' ), $quillmoor_i ) ); ?></option>
					<?php endfor; ?>
				</select>
			</label>
			<button type="submit" class="qm-search__go" data-testid="search-submit">
				<?php echo Blocks::icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
				<span><?php esc_html_e( 'Search', 'quillmoor-booking' ); ?></span>
			</button>
		</div>
	</form>
</div>
