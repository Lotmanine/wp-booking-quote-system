<?php
/**
 * Title: Demo logins
 * Slug: quillmoor/demo
 * Inserter: no
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;

$quillmoor_password = (string) getenv( 'QHS_DEMO_PASSWORD' );
?>
<!-- wp:paragraph -->
<p>Three shared accounts show each side of the system. Data resets every night, and these accounts cannot change their password or email.</p>
<!-- /wp:paragraph -->
<!-- wp:html -->
<table class="qm-logins">
	<thead><tr><th>Role</th><th>Username</th><th>Password</th></tr></thead>
	<tbody>
		<tr><td>Customer</td><td><code>demo-customer</code></td><td><code><?php echo esc_html( $quillmoor_password ); ?></code></td></tr>
		<tr><td>Staff</td><td><code>demo-staff</code></td><td><code><?php echo esc_html( $quillmoor_password ); ?></code></td></tr>
		<tr><td>Admin (restricted)</td><td><code>demo-admin</code></td><td><code><?php echo esc_html( $quillmoor_password ); ?></code></td></tr>
	</tbody>
</table>
<!-- /wp:html -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( wp_login_url() ); ?>">Sign in</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- wp:paragraph {"className":"qm-fine"} -->
<p class="qm-fine">Payments use test mode only. Never enter a real card number.</p>
<!-- /wp:paragraph -->
