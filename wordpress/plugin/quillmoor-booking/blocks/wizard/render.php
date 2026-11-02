<?php
/**
 * Quote wizard shell. The interface is built in view.js.
 *
 * @package Quillmoor\Booking
 */

defined( 'ABSPATH' ) || exit;

wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', array(), QUILLMOOR_VERSION, true );

echo \Quillmoor\Booking\Blocks::nonce_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the helper.
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'qhs-app' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core escapes this. ?> id="qhs-wizard" data-signed-in="<?php echo is_user_logged_in() ? '1' : '0'; ?>">
	<noscript><?php esc_html_e( 'This page needs JavaScript.', 'quillmoor-booking' ); ?></noscript>
</div>
