<?php
/**
 * Listing page: gallery, details, map, host panel and the booking card. The card is built in view.js
 * from the server's calendar, slot and price answers.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Listings\Fields;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\Pricing;

defined( 'ABSPATH' ) || exit;

$quillmoor_listing = Blocks::current_listing();
if ( null === $quillmoor_listing || ( ! $quillmoor_listing->is_published() && ! current_user_can( 'edit_post', $quillmoor_listing->id ) ) ) {
	echo '<div class="qm-empty"><h1>' . esc_html__( 'This place is not available', 'quillmoor-booking' ) . '</h1><p><a href="' . esc_url( home_url( '/explore/' ) ) . '">' . esc_html__( 'Explore other places', 'quillmoor-booking' ) . '</a></p></div>';
	return;
}

if ( ! is_user_logged_in() && '' !== Config::get( 'TURNSTILE_SITE_KEY' ) ) {
	wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), QUILLMOOR_VERSION, true );
}

$quillmoor_l       = $quillmoor_listing;
$quillmoor_stay    = ListingType::Stay === $quillmoor_l->type;
$quillmoor_photos  = $quillmoor_l->photo_list( 'large' );
$quillmoor_loc     = $quillmoor_l->location();
$quillmoor_point   = $quillmoor_l->point();
$quillmoor_host    = get_userdata( $quillmoor_l->host_id );
$quillmoor_since   = $quillmoor_host ? (string) get_user_meta( $quillmoor_host->ID, 'qm_host_since', true ) : '';
$quillmoor_place   = null === $quillmoor_loc ? '' : $quillmoor_loc['name'];
$quillmoor_filters = Blocks::query_filters();
$quillmoor_ready   = Blocks::readiness();
$quillmoor_extras  = array();
foreach ( $quillmoor_l->extras as $quillmoor_key => $quillmoor_extra ) {
	$quillmoor_extras[] = array(
		'key'   => $quillmoor_key,
		'label' => $quillmoor_extra['label'],
		'price' => Pricing::money( $quillmoor_extra['cents'] ) . ( 'per_night' === $quillmoor_extra['unit'] ? ' a night' : ( 'per_guest' === $quillmoor_extra['unit'] ? ' a guest' : '' ) ),
	);
}
$quillmoor_card    = array(
	'id'         => $quillmoor_l->id,
	'type'       => $quillmoor_l->type->value,
	'title'      => $quillmoor_l->title,
	'instant'    => $quillmoor_l->instant_book,
	'price'      => Pricing::money( $quillmoor_l->from_cents() ),
	'unit'       => $quillmoor_stay ? 'night' : 'guest',
	'maxGuests'  => $quillmoor_l->max_guests,
	'minNights'  => $quillmoor_l->min_nights,
	'maxNights'  => $quillmoor_l->max_nights,
	'minParty'   => $quillmoor_l->min_party,
	'maxParty'   => $quillmoor_l->max_party,
	'extras'     => $quillmoor_extras,
	'signedIn'   => is_user_logged_in(),
	'login'      => wp_login_url( (string) get_permalink( $quillmoor_l->id ) ),
	'ready'      => $quillmoor_ready,
	'trips'      => home_url( '/trips/' ),
	'start'      => array(
		'check_in'  => $quillmoor_filters['check_in'],
		'check_out' => $quillmoor_filters['check_out'],
		'guests'    => $quillmoor_filters['guests'],
		'date'      => $quillmoor_filters['date'],
		'party'     => max( $quillmoor_filters['party'], $quillmoor_stay ? 0 : $quillmoor_filters['guests'] ),
	),
	'policy'     => $quillmoor_l->cancellation_text(),
	'holdMinute' => Config::HOLD_MINUTES,
);
$quillmoor_amen    = $quillmoor_l->amenities();
$quillmoor_weekday = static function ( array $days ): string {
	$names = array_map( static fn( string $d ): string => substr( Fields::WEEKDAYS[ $d ] ?? $d, 0, 3 ), $days );
	return 7 === count( $days ) ? 'Every day' : implode( ', ', $names );
};

echo Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-listing' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> data-testid="listing-page" data-type="<?php echo esc_attr( $quillmoor_l->type->value ); ?>">
	<header class="qm-listing__head">
		<h1 class="qm-listing__title"><?php echo esc_html( $quillmoor_l->title ); ?></h1>
		<p class="qm-listing__where">
			<?php echo esc_html( $quillmoor_l->kind_label() . ( '' !== $quillmoor_place ? ' in ' . $quillmoor_place : '' ) ); ?>
			<?php if ( ! $quillmoor_l->is_published() ) : ?>
				<span class="qm-tag"><?php esc_html_e( 'Draft preview', 'quillmoor-booking' ); ?></span>
			<?php endif; ?>
		</p>
	</header>

	<div class="qm-gallery qm-gallery--<?php echo (int) min( 5, count( $quillmoor_photos ) ); ?>" data-qm-gallery>
		<?php foreach ( $quillmoor_photos as $quillmoor_i => $quillmoor_photo ) : ?>
			<button type="button" class="qm-gallery__item" data-index="<?php echo (int) $quillmoor_i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: photo number, 2: total */ __( 'Open photo %1$d of %2$d', 'quillmoor-booking' ), $quillmoor_i + 1, count( $quillmoor_photos ) ) ); ?>">
				<img src="<?php echo esc_url( $quillmoor_photo['url'] ); ?>" alt="<?php echo esc_attr( $quillmoor_photo['alt'] ); ?>" width="<?php echo (int) $quillmoor_photo['width']; ?>" height="<?php echo (int) $quillmoor_photo['height']; ?>" <?php echo 0 === $quillmoor_i ? 'fetchpriority="high"' : 'loading="lazy"'; ?>>
			</button>
		<?php endforeach; ?>
		<?php if ( count( $quillmoor_photos ) > 1 ) : ?>
			<button type="button" class="qm-gallery__all" data-index="0" data-testid="gallery-all"><?php echo Blocks::icon( 'grid', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Show all %d photos', 'quillmoor-booking' ), count( $quillmoor_photos ) ) ); ?></button>
			<span class="qm-gallery__count" aria-hidden="true">1 / <?php echo (int) count( $quillmoor_photos ); ?></span>
		<?php endif; ?>
	</div>
	<dialog class="qm-lightbox" data-qm-lightbox aria-label="<?php esc_attr_e( 'Photos', 'quillmoor-booking' ); ?>">
		<button type="button" class="qm-lightbox__close" data-close><?php esc_html_e( 'Close', 'quillmoor-booking' ); ?></button>
		<div class="qm-lightbox__list">
			<?php foreach ( $quillmoor_photos as $quillmoor_i => $quillmoor_photo ) : ?>
				<figure id="qm-photo-<?php echo (int) $quillmoor_i; ?>"><img src="<?php echo esc_url( $quillmoor_photo['url'] ); ?>" alt="<?php echo esc_attr( $quillmoor_photo['alt'] ); ?>" loading="lazy" width="<?php echo (int) $quillmoor_photo['width']; ?>" height="<?php echo (int) $quillmoor_photo['height']; ?>"><figcaption><?php echo esc_html( (string) wp_get_attachment_caption( $quillmoor_photo['id'] ) ); ?></figcaption></figure>
			<?php endforeach; ?>
		</div>
	</dialog>

	<div class="qm-listing__layout">
		<div class="qm-listing__main">
			<section class="qm-listing__intro">
				<h2><?php echo esc_html( $quillmoor_l->summary ); ?></h2>
				<p class="qm-listing__facts" data-testid="listing-facts"><?php echo esc_html( implode( ', ', $quillmoor_l->size_facts() ) ); ?></p>
			</section>

			<?php if ( $quillmoor_host ) : ?>
				<section class="qm-hoststrip">
					<span class="qm-avatar" aria-hidden="true"><?php echo esc_html( strtoupper( substr( (string) $quillmoor_host->display_name, 0, 1 ) ) ); ?></span>
					<div>
						<p class="qm-hoststrip__name"><?php echo esc_html( sprintf( /* translators: %s: host */ __( 'Hosted by %s', 'quillmoor-booking' ), $quillmoor_host->display_name ) ); ?></p>
						<p class="qm-muted"><?php echo esc_html( '' !== $quillmoor_since ? sprintf( /* translators: %s: year */ __( 'Hosting since %s (demo data)', 'quillmoor-booking' ), $quillmoor_since ) : __( 'Demo host', 'quillmoor-booking' ) ); ?></p>
					</div>
				</section>
			<?php endif; ?>

				<section class="qm-highlights">
					<div class="qm-highlight">
						<?php echo Blocks::icon( $quillmoor_l->instant_book ? 'calendar' : 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
						<div><p class="qm-highlight__t"><?php echo esc_html( $quillmoor_l->instant_book ? __( 'Instant book', 'quillmoor-booking' ) : __( 'Request to book', 'quillmoor-booking' ) ); ?></p><p class="qm-muted"><?php echo esc_html( $quillmoor_l->instant_book ? __( 'Your reservation is confirmed as soon as the deposit is paid.', 'quillmoor-booking' ) : __( 'The host answers within 24 hours. Without an answer the request is declined and the deposit refunded.', 'quillmoor-booking' ) ); ?></p></div>
					</div>
					<?php foreach ( $quillmoor_l->highlights as $quillmoor_h ) : ?>
						<div class="qm-highlight">
							<?php echo Blocks::icon( $quillmoor_h['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
							<div><p class="qm-highlight__t"><?php echo esc_html( $quillmoor_h['title'] ); ?></p><p class="qm-muted"><?php echo esc_html( $quillmoor_h['text'] ); ?></p></div>
						</div>
					<?php endforeach; ?>
				</section>

			<section class="qm-section qm-prose">
				<?php echo wp_kses_post( wpautop( esc_html( $quillmoor_l->description ) ) ); ?>
			</section>

			<?php if ( $quillmoor_stay && array() !== $quillmoor_l->spaces ) : ?>
				<section class="qm-section">
					<h2><?php esc_html_e( 'Where you will sleep', 'quillmoor-booking' ); ?></h2>
					<div class="qm-rooms">
						<?php foreach ( $quillmoor_l->spaces as $quillmoor_space ) : ?>
							<div class="qm-room"><?php echo Blocks::icon( 'bed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><p class="qm-room__t"><?php echo esc_html( (string) ( $quillmoor_space['name'] ?? '' ) ); ?></p><p class="qm-muted"><?php echo esc_html( (string) ( $quillmoor_space['sleeping'] ?? '' ) ); ?></p></div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( ! $quillmoor_stay ) : ?>
				<section class="qm-section" data-testid="opening-hours">
					<h2><?php esc_html_e( 'Opening hours and tables', 'quillmoor-booking' ); ?></h2>
					<dl class="qm-hours">
						<?php foreach ( $quillmoor_l->periods as $quillmoor_period ) : ?>
							<div><dt><?php echo esc_html( $quillmoor_period['label'] ); ?></dt><dd><?php echo esc_html( $quillmoor_weekday( $quillmoor_period['days'] ) . ', first seating ' . $quillmoor_period['start'] . ', last ' . $quillmoor_period['end'] . ' UTC' ); ?></dd></div>
						<?php endforeach; ?>
					</dl>
					<p class="qm-muted"><?php echo esc_html( sprintf( /* translators: 1: seats, 2: minutes, 3: minutes */ __( 'Up to %1$d guests can start at each time. Seatings every %2$d minutes, and a table is kept for %3$d minutes.', 'quillmoor-booking' ), $quillmoor_l->covers, $quillmoor_l->slot_minutes, $quillmoor_l->seating_minutes ) ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( array() !== $quillmoor_amen ) : ?>
				<section class="qm-section">
					<h2><?php echo esc_html( $quillmoor_stay ? __( 'What this place offers', 'quillmoor-booking' ) : __( 'Good to know', 'quillmoor-booking' ) ); ?></h2>
					<ul class="qm-amenities" data-testid="amenities">
						<?php foreach ( $quillmoor_amen as $quillmoor_a ) : ?>
							<li><?php echo Blocks::icon( $quillmoor_a['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span><?php echo esc_html( $quillmoor_a['name'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( array() !== $quillmoor_extras ) : ?>
				<section class="qm-section">
					<h2><?php esc_html_e( 'Extras you can add', 'quillmoor-booking' ); ?></h2>
					<ul class="qm-pricelist">
						<?php foreach ( $quillmoor_extras as $quillmoor_x ) : ?>
							<li><span><?php echo esc_html( $quillmoor_x['label'] ); ?></span><span><?php echo esc_html( $quillmoor_x['price'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( null !== $quillmoor_point ) : ?>
				<section class="qm-section">
					<h2><?php echo esc_html( $quillmoor_stay ? __( 'Where you will be', 'quillmoor-booking' ) : __( 'Where to find it', 'quillmoor-booking' ) ); ?></h2>
					<p><?php echo esc_html( $quillmoor_place . ( '' !== $quillmoor_l->area_note ? '. ' . $quillmoor_l->area_note : '' ) ); ?></p>
					<div class="qm-map qm-map--listing" data-qm-listing-map data-lat="<?php echo esc_attr( (string) $quillmoor_point['lat'] ); ?>" data-lng="<?php echo esc_attr( (string) $quillmoor_point['lng'] ); ?>" data-testid="listing-map"></div>
					<p class="qm-muted"><?php esc_html_e( 'The circle shows the approximate, fictional area. The street address is shared once a booking is confirmed.', 'quillmoor-booking' ); ?></p>
				</section>
			<?php endif; ?>

			<section class="qm-section qm-rules">
				<h2><?php esc_html_e( 'Things to know', 'quillmoor-booking' ); ?></h2>
				<div class="qm-rules__cols">
					<div>
						<h3><?php esc_html_e( 'House rules', 'quillmoor-booking' ); ?></h3>
						<ul>
							<?php if ( $quillmoor_stay ) : ?>
								<li><?php echo esc_html( sprintf( /* translators: 1: time, 2: time */ __( 'Check-in after %1$s, checkout before %2$s', 'quillmoor-booking' ), $quillmoor_l->check_in_time, $quillmoor_l->check_out_time ) ); ?></li>
								<li><?php echo esc_html( sprintf( /* translators: 1: min, 2: max */ __( 'Stays of %1$d to %2$d nights', 'quillmoor-booking' ), $quillmoor_l->min_nights, $quillmoor_l->max_nights ) ); ?></li>
							<?php else : ?>
								<li><?php echo esc_html( sprintf( /* translators: 1: min, 2: max */ __( 'Tables for %1$d to %2$d guests', 'quillmoor-booking' ), $quillmoor_l->min_party, $quillmoor_l->max_party ) ); ?></li>
							<?php endif; ?>
							<?php foreach ( $quillmoor_l->house_rules as $quillmoor_rule ) : ?>
								<li><?php echo esc_html( $quillmoor_rule ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<div>
						<h3><?php esc_html_e( 'Cancellation policy', 'quillmoor-booking' ); ?></h3>
						<p data-testid="cancellation"><?php echo esc_html( $quillmoor_l->cancellation_text() ); ?></p>
						<p class="qm-muted"><?php esc_html_e( 'A host cancellation always refunds the full deposit. Changes close 24 hours before arrival.', 'quillmoor-booking' ); ?></p>
					</div>
				</div>
			</section>

			<?php if ( $quillmoor_host ) : ?>
				<section class="qm-section qm-hostcard" data-testid="host-panel">
					<h2><?php esc_html_e( 'Meet your host', 'quillmoor-booking' ); ?></h2>
					<div class="qm-hostcard__box">
						<div class="qm-hostcard__id">
							<span class="qm-avatar qm-avatar--lg" aria-hidden="true"><?php echo esc_html( strtoupper( substr( (string) $quillmoor_host->display_name, 0, 1 ) ) ); ?></span>
							<p class="qm-hostcard__name"><?php echo esc_html( $quillmoor_host->display_name ); ?></p>
							<p class="qm-muted"><?php esc_html_e( 'Fictional host', 'quillmoor-booking' ); ?></p>
						</div>
						<div>
							<p><?php echo esc_html( (string) get_user_meta( $quillmoor_host->ID, 'qm_host_bio', true ) ); ?></p>
							<dl class="qm-hostcard__facts">
								<?php if ( '' !== $quillmoor_since ) : ?>
									<div><dt><?php esc_html_e( 'Hosting since', 'quillmoor-booking' ); ?></dt><dd><?php echo esc_html( $quillmoor_since . ' (demo data)' ); ?></dd></div>
								<?php endif; ?>
								<?php $quillmoor_langs = (string) get_user_meta( $quillmoor_host->ID, 'qm_host_languages', true ); ?>
								<?php if ( '' !== $quillmoor_langs ) : ?>
									<div><dt><?php esc_html_e( 'Languages', 'quillmoor-booking' ); ?></dt><dd><?php echo esc_html( $quillmoor_langs ); ?></dd></div>
								<?php endif; ?>
								<div><dt><?php esc_html_e( 'Answers requests', 'quillmoor-booking' ); ?></dt><dd><?php esc_html_e( 'Within 24 hours, or the request is declined automatically', 'quillmoor-booking' ); ?></dd></div>
							</dl>
						</div>
					</div>
				</section>
			<?php endif; ?>
		</div>

		<aside class="qm-book" id="reserve" aria-label="<?php esc_attr_e( 'Reserve', 'quillmoor-booking' ); ?>">
			<div class="qm-book__card" data-qm-book="<?php echo esc_attr( (string) wp_json_encode( $quillmoor_card ) ); ?>" data-testid="booking-card">
				<p class="qm-book__price"><strong><?php echo esc_html( Pricing::money( $quillmoor_l->from_cents() ) ); ?></strong> <span><?php echo esc_html( $quillmoor_stay ? __( 'a night', 'quillmoor-booking' ) : __( 'per guest, set menu', 'quillmoor-booking' ) ); ?></span></p>
				<noscript><p><?php esc_html_e( 'Reserving needs JavaScript.', 'quillmoor-booking' ); ?></p></noscript>
			</div>
			<p class="qm-book__fine"><?php esc_html_e( 'Demo: prices are fictional and payments run in test mode only.', 'quillmoor-booking' ); ?></p>
		</aside>
	</div>

	<div class="qm-mobilebar" data-qm-mobilebar>
		<p><strong data-qm-mobile-price><?php echo esc_html( Pricing::money( $quillmoor_l->from_cents() ) ); ?></strong> <span data-qm-mobile-note><?php echo esc_html( $quillmoor_stay ? __( 'a night', 'quillmoor-booking' ) : __( 'per guest', 'quillmoor-booking' ) ); ?></span></p>
		<a class="qm-btn qm-btn--primary" href="#reserve" data-testid="mobile-reserve"><?php echo esc_html( $quillmoor_stay ? __( 'Check dates', 'quillmoor-booking' ) : __( 'Find a table', 'quillmoor-booking' ) ); ?></a>
	</div>
</div>
