<?php
/**
 * Staff portal shell. The interface is built in view.js.
 *
 * @package Quillmoor\Booking
 */

defined( 'ABSPATH' ) || exit;

echo \Quillmoor\Booking\Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-app' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> id="qhs-staff" data-signed-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>" data-login="<?php echo esc_url( wp_login_url( home_url( '/staff/' ) ) ); ?>">
	<noscript><?php esc_html_e( 'This page needs JavaScript.', 'quillmoor-booking' ); ?></noscript>
</div>
