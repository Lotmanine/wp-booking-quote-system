<?php
/**
 * Photo credits: every demo photo with its photographer, source page and licence.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Demo\SeedData;
use Quillmoor\Booking\Demo\Seeder;

defined( 'ABSPATH' ) || exit;

$quillmoor_credits  = Seeder::credits();
$quillmoor_listings = SeedData::listings();
$quillmoor_groups   = array();
foreach ( $quillmoor_credits as $quillmoor_row ) {
	$quillmoor_groups[ (string) $quillmoor_row['listing'] ][] = $quillmoor_row;
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-credits' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> data-testid="credits">
	<p class="qm-credits__lead">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of photos */
				__( 'The places on this demo are fictional, but the %d photos are real and come from Unsplash. The Unsplash License allows free use without attribution; each photographer is credited here anyway. Photos are resized and recompressed for the web. The photographers do not endorse this demo.', 'quillmoor-booking' ),
				count( $quillmoor_credits )
			)
		);
		?>
	</p>
	<?php foreach ( $quillmoor_groups as $quillmoor_slug => $quillmoor_rows ) : ?>
		<section class="qm-credits__group">
			<h2><?php echo esc_html( (string) ( $quillmoor_listings[ $quillmoor_slug ]['title'] ?? $quillmoor_slug ) ); ?></h2>
			<ul class="qm-credits__list">
				<?php foreach ( $quillmoor_rows as $quillmoor_row ) : ?>
					<?php
					$quillmoor_img = wp_get_attachment_image_url( Seeder::photo_id( (string) $quillmoor_row['file'] ), 'medium_large' );
					$quillmoor_img = $quillmoor_img ? $quillmoor_img : QUILLMOOR_PLUGIN_URL . 'demo/photos/' . rawurlencode( (string) $quillmoor_row['file'] );
					?>
					<li class="qm-credit" data-testid="credit" data-file="<?php echo esc_attr( (string) $quillmoor_row['file'] ); ?>">
						<img src="<?php echo esc_url( $quillmoor_img ); ?>" alt="<?php echo esc_attr( (string) $quillmoor_row['alt'] ); ?>" width="240" height="160" loading="lazy">
						<div>
							<p><?php echo esc_html( (string) $quillmoor_row['alt'] ); ?></p>
							<p class="qm-muted">
								<?php esc_html_e( 'Photo by', 'quillmoor-booking' ); ?>
								<a href="<?php echo esc_url( (string) $quillmoor_row['photographer_url'] ); ?>" rel="noopener"><?php echo esc_html( (string) $quillmoor_row['photographer'] ); ?></a>,
								<a href="<?php echo esc_url( (string) $quillmoor_row['source_url'] ); ?>" rel="noopener"><?php esc_html_e( 'source on Unsplash', 'quillmoor-booking' ); ?></a>,
								<a href="<?php echo esc_url( (string) $quillmoor_row['licence_url'] ); ?>" rel="noopener license"><?php echo esc_html( (string) $quillmoor_row['licence'] ); ?></a>
							</p>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endforeach; ?>
	<p class="qm-muted"><?php esc_html_e( 'Map tiles and data come from OpenStreetMap contributors under the Open Database License. Plus Jakarta Sans is used under the SIL Open Font License.', 'quillmoor-booking' ); ?></p>
</div>
