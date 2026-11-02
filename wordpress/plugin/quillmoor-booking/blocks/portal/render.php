<?php
/**
 * Customer portal shell. The interface is built in view.js.
 *
 * @package Quillmoor\Booking
 */

defined( 'ABSPATH' ) || exit;

echo \Quillmoor\Booking\Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
$quillmoor_user = wp_get_current_user();
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-dash qhs-container' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?>>
	<header class="qhs-dash__head">
		<div>
			<p class="qhs-eyebrow"><?php echo esc_html( $quillmoor_user->exists() ? sprintf( /* translators: %s: display name */ __( 'Hello, %s', 'quillmoor-booking' ), $quillmoor_user->display_name ) : __( 'Your account', 'quillmoor-booking' ) ); ?></p>
			<h1 class="qhs-dash__title"><?php esc_html_e( 'Your bookings', 'quillmoor-booking' ); ?></h1>
			<p class="qhs-dash__lead"><?php esc_html_e( 'Upcoming visits, past jobs and everything you can change.', 'quillmoor-booking' ); ?></p>
		</div>
		<a class="qhs-btn qhs-btn--primary" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Book another service', 'quillmoor-booking' ); ?></a>
	</header>
	<div class="qhs-app" id="qhs-portal" data-signed-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>" data-login="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>">
		<div class="qhs-skeleton" aria-busy="true"><?php esc_html_e( 'Loading', 'quillmoor-booking' ); ?></div>
		<noscript><?php esc_html_e( 'This page needs JavaScript.', 'quillmoor-booking' ); ?></noscript>
	</div>
</div>
