<?php
/**
 * Trips: the guest dashboard. Signed-in guests see every booking; a guest link opens one booking.
 *
 * @package Quillmoor\Booking
 */

use Quillmoor\Booking\Blocks;
use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing; the token is checked by the REST API.
$quillmoor_booking = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
$quillmoor_token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
$quillmoor_verify  = isset( $_GET['verify'] ) ? sanitize_text_field( wp_unslash( $_GET['verify'] ) ) : '';
$quillmoor_paid    = isset( $_GET['paid'] );
// phpcs:enable WordPress.Security.NonceVerification.Recommended

echo Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
$quillmoor_user = wp_get_current_user();
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qm-trips' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>
	data-qm-trips
	data-signed-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>"
	data-booking="<?php echo (int) $quillmoor_booking; ?>"
	data-token="<?php echo esc_attr( $quillmoor_token ); ?>"
	data-verify="<?php echo esc_attr( $quillmoor_verify ); ?>"
	data-paid="<?php echo $quillmoor_paid ? '1' : '0'; ?>"
	data-cutoff="<?php echo (int) Config::CHANGE_CUTOFF_HOURS; ?>"
	data-testid="trips">
	<header class="qm-trips__head">
		<h1><?php esc_html_e( 'Trips', 'quillmoor-booking' ); ?></h1>
		<?php if ( $quillmoor_user->exists() ) : ?>
			<p class="qm-muted"><?php echo esc_html( sprintf( /* translators: %s: name */ __( 'Signed in as %s. Your stays, tables and deposits in one place.', 'quillmoor-booking' ), $quillmoor_user->display_name ) ); ?></p>
		<?php endif; ?>
	</header>
	<?php if ( ! is_user_logged_in() && ( 0 === $quillmoor_booking || '' === $quillmoor_token ) && '' === $quillmoor_verify ) : ?>
		<div class="qm-empty" data-testid="trips-signin">
			<h2><?php esc_html_e( 'Sign in to see your trips', 'quillmoor-booking' ); ?></h2>
			<p><?php esc_html_e( 'Or open the link from your booking email. On this demo, the guest login is listed on the Demo logins page.', 'quillmoor-booking' ); ?></p>
			<a class="qm-btn qm-btn--primary" href="<?php echo esc_url( wp_login_url( home_url( '/trips/' ) ) ); ?>"><?php esc_html_e( 'Sign in', 'quillmoor-booking' ); ?></a>
		</div>
	<?php else : ?>
		<div class="qm-trips__body" data-qm-trips-body><p class="qm-muted"><?php esc_html_e( 'Loading your trips...', 'quillmoor-booking' ); ?></p></div>
	<?php endif; ?>
</div>
