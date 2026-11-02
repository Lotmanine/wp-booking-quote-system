<?php
/**
 * Service page: gallery, details and the reservation card. The card itself is built in view.js.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), QUILLMOOR_VERSION, true );

$quillmoor_catalog = Catalog::all();
$quillmoor_key     = Blocks::query_key( 'service', 'services' );

echo Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.

if ( '' === $quillmoor_key ) :
	?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-detail qhs-container' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>>
		<div class="qhs-notfound">
			<p class="qhs-eyebrow"><?php esc_html_e( 'Service', 'quillmoor-booking' ); ?></p>
			<h1><?php esc_html_e( 'Choose a service first', 'quillmoor-booking' ); ?></h1>
			<p><?php esc_html_e( 'This page shows one service at a time. Pick one from the list to see its calendar.', 'quillmoor-booking' ); ?></p>
			<a class="qhs-btn qhs-btn--primary" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Browse services', 'quillmoor-booking' ); ?></a>
		</div>
	</div>
	<?php
	return;
endif;

$quillmoor_service  = $quillmoor_catalog['services'][ $quillmoor_key ];
$quillmoor_category = (string) $quillmoor_service['category'];
$quillmoor_related  = array_filter(
	$quillmoor_catalog['services'],
	static fn( $row, $key ) => $key !== $quillmoor_key && $row['category'] === $quillmoor_category,
	ARRAY_FILTER_USE_BOTH
);
$quillmoor_image    = Catalog::image_url( $quillmoor_key );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-detail qhs-container' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> data-testid="service-page">
	<nav class="qhs-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'quillmoor-booking' ); ?>">
		<a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Services', 'quillmoor-booking' ); ?></a>
		<span aria-hidden="true">/</span>
		<a href="<?php echo esc_url( add_query_arg( 'category', $quillmoor_category, home_url( '/services/' ) ) ); ?>"><?php echo esc_html( Catalog::label( 'categories', $quillmoor_category ) ); ?></a>
		<span aria-hidden="true">/</span>
		<span aria-current="page"><?php echo esc_html( (string) $quillmoor_service['label'] ); ?></span>
	</nav>

	<header class="qhs-detail__head">
		<h1 class="qhs-detail__title"><?php echo esc_html( (string) $quillmoor_service['label'] ); ?></h1>
		<ul class="qhs-detail__meta">
			<li><?php echo Blocks::icon( $quillmoor_category ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( Catalog::label( 'categories', $quillmoor_category ) ); ?></li>
			<li><?php echo Blocks::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( (string) $quillmoor_service['hours'] ); ?></li>
			<li><?php echo Blocks::icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( sprintf( /* translators: %d: number of districts */ __( '%d districts', 'quillmoor-booking' ), count( $quillmoor_catalog['areas'] ) ) ); ?></li>
		</ul>
	</header>

	<div class="qhs-gallery" data-qhs-gallery>
		<button type="button" class="qhs-gallery__main" data-index="0" aria-label="<?php esc_attr_e( 'Open photo 1 of 3', 'quillmoor-booking' ); ?>"><img src="<?php echo esc_url( $quillmoor_image ); ?>" alt="" width="640" height="400" fetchpriority="high"></button>
		<button type="button" class="qhs-gallery__tile qhs-gallery__tile--zoom" data-index="1" aria-label="<?php esc_attr_e( 'Open photo 2 of 3', 'quillmoor-booking' ); ?>"><img src="<?php echo esc_url( $quillmoor_image ); ?>" alt="" width="640" height="400" loading="lazy"></button>
		<button type="button" class="qhs-gallery__tile" data-index="2" aria-label="<?php esc_attr_e( 'Open photo 3 of 3', 'quillmoor-booking' ); ?>"><img src="<?php echo esc_url( QUILLMOOR_PLUGIN_URL . 'assets/img/map.svg' ); ?>" alt="" width="640" height="400" loading="lazy"></button>
		<span class="qhs-gallery__count" aria-hidden="true"><?php esc_html_e( 'Original illustrations', 'quillmoor-booking' ); ?></span>
	</div>

	<div class="qhs-detail__layout">
		<div class="qhs-detail__content">
			<section class="qhs-detail__section">
				<h2><?php esc_html_e( 'About this service', 'quillmoor-booking' ); ?></h2>
				<p class="qhs-detail__lead"><?php echo esc_html( (string) $quillmoor_service['summary'] ); ?></p>
			</section>

			<section class="qhs-detail__section">
				<h2><?php esc_html_e( 'What is included', 'quillmoor-booking' ); ?></h2>
				<ul class="qhs-checklist">
					<?php foreach ( (array) $quillmoor_service['includes'] as $quillmoor_line ) : ?>
						<li><?php echo Blocks::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span><?php echo esc_html( (string) $quillmoor_line ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="qhs-detail__section">
				<h2><?php esc_html_e( 'Extras you can add', 'quillmoor-booking' ); ?></h2>
				<ul class="qhs-extras-list">
					<?php foreach ( $quillmoor_catalog['extras'] as $quillmoor_extra ) : ?>
						<li><span><?php echo esc_html( (string) $quillmoor_extra['label'] ); ?></span><span class="qhs-mono"><?php echo esc_html( '+' . Catalog::money( (int) $quillmoor_extra['cents'] ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<p class="qhs-fine"><?php esc_html_e( 'All prices on this site are fictional.', 'quillmoor-booking' ); ?></p>
			</section>

			<section class="qhs-detail__section">
				<h2><?php esc_html_e( 'Where the teams work', 'quillmoor-booking' ); ?></h2>
				<div class="qhs-areas">
					<?php foreach ( $quillmoor_catalog['areas'] as $quillmoor_area ) : ?>
						<div class="qhs-area">
							<p class="qhs-area__name"><?php echo esc_html( (string) $quillmoor_area['label'] ); ?></p>
							<p class="qhs-area__fee"><?php echo esc_html( (int) $quillmoor_area['surcharge_cents'] > 0 ? '+' . Catalog::money( (int) $quillmoor_area['surcharge_cents'] ) . ' travel' : 'No travel fee' ); ?></p>
							<p class="qhs-meta"><?php echo esc_html( sprintf( /* translators: %d: crews */ _n( '%d crew per slot', '%d crews per slot', (int) $quillmoor_area['capacity'], 'quillmoor-booking' ), (int) $quillmoor_area['capacity'] ) ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="qhs-detail__section">
				<h2><?php esc_html_e( 'Good to know', 'quillmoor-booking' ); ?></h2>
				<dl class="qhs-facts">
					<div><dt><?php esc_html_e( 'Slot hold', 'quillmoor-booking' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: minutes */ __( 'Your time is held for %d minutes while you pay.', 'quillmoor-booking' ), Config::HOLD_MINUTES ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Deposit', 'quillmoor-booking' ); ?></dt><dd><?php esc_html_e( '20 percent of the total, at least $20, paid in test mode.', 'quillmoor-booking' ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Changes', 'quillmoor-booking' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: hours */ __( 'Reschedule or cancel from Your bookings up to %d hours before the visit.', 'quillmoor-booking' ), Config::CHANGE_CUTOFF_HOURS ) ); ?></dd></div>
					<div><dt><?php esc_html_e( 'Times', 'quillmoor-booking' ); ?></dt><dd><?php esc_html_e( 'Visits start at 9, 11, 13 or 15 UTC, Monday to Saturday.', 'quillmoor-booking' ); ?></dd></div>
				</dl>
			</section>

			<?php if ( $quillmoor_related ) : ?>
				<section class="qhs-detail__section">
					<h2><?php esc_html_e( 'Also in this category', 'quillmoor-booking' ); ?></h2>
					<div class="qhs-related">
						<?php foreach ( $quillmoor_related as $quillmoor_rkey => $quillmoor_row ) : ?>
							<a class="qhs-related__item" href="<?php echo esc_url( add_query_arg( 'service', $quillmoor_rkey, home_url( '/book/' ) ) ); ?>">
								<img src="<?php echo esc_url( Catalog::image_url( (string) $quillmoor_rkey ) ); ?>" alt="" width="640" height="400" loading="lazy">
								<span><strong><?php echo esc_html( (string) $quillmoor_row['label'] ); ?></strong><span><?php echo esc_html( 'From ' . Catalog::money( (int) $quillmoor_row['base_cents'] ) . ', fictional' ); ?></span></span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>

		<aside class="qhs-reserve" id="reserve" aria-label="<?php esc_attr_e( 'Reserve', 'quillmoor-booking' ); ?>" data-qhs-reserve data-service="<?php echo esc_attr( $quillmoor_key ); ?>" data-area="<?php echo esc_attr( Blocks::query_key( 'area', 'areas' ) ); ?>" data-size="<?php echo esc_attr( Blocks::query_key( 'size', 'sizes' ) ); ?>" data-date="<?php echo esc_attr( Blocks::query_date( 'date' ) ); ?>" data-signed-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>" data-login="<?php echo esc_url( wp_login_url( (string) add_query_arg( null, null ) ) ); ?>">
			<div class="qhs-reserve__card">
				<p class="qhs-reserve__price"><strong><?php echo esc_html( Catalog::money( (int) $quillmoor_service['base_cents'] ) ); ?></strong> <span><?php esc_html_e( 'from, fictional', 'quillmoor-booking' ); ?></span></p>
				<noscript><p><?php esc_html_e( 'Booking needs JavaScript.', 'quillmoor-booking' ); ?></p></noscript>
			</div>
		</aside>
	</div>

	<div class="qhs-mobilebar" data-qhs-mobilebar>
		<p><strong data-qhs-mobile-price><?php echo esc_html( Catalog::money( (int) $quillmoor_service['base_cents'] ) ); ?></strong> <span data-qhs-mobile-note><?php esc_html_e( 'from, fictional', 'quillmoor-booking' ); ?></span></p>
		<a class="qhs-btn qhs-btn--primary" href="#reserve"><?php esc_html_e( 'Check availability', 'quillmoor-booking' ); ?></a>
	</div>
</div>
