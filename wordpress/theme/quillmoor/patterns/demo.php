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
$quillmoor_roles    = array(
	array( 'Guest', 'demo-customer', 'Search, hold a stay or a table, then manage it from Trips.', home_url( '/trips/' ), 'Sign in as the guest' ),
	array( 'Host', 'demo-host', 'Manage your own listings, calendar, requests, notes and refunds. You only see your own places.', admin_url( 'admin.php?page=qhs-dashboard' ), 'Sign in as the host' ),
	array( 'Admin (restricted)', 'demo-admin', 'Every host, every booking, locations, categories, amenities and the activity log.', admin_url( 'admin.php?page=qhs-dashboard' ), 'Sign in as the admin' ),
);
?>
<!-- wp:paragraph {"className":"qm-lead"} -->
<p class="qm-lead">Three shared accounts show each side of the system. Data resets every night, and these accounts cannot change their password or email.</p>
<!-- /wp:paragraph -->
<!-- wp:html -->
<div class="qm-logins">
	<?php foreach ( $quillmoor_roles as $quillmoor_role ) : ?>
		<div class="qm-login" data-testid="login-<?php echo esc_attr( $quillmoor_role[1] ); ?>">
			<p class="qm-login__role"><?php echo esc_html( $quillmoor_role[0] ); ?></p>
			<p class="qm-login__what"><?php echo esc_html( $quillmoor_role[2] ); ?></p>
			<dl>
				<dt>Username</dt><dd><code><?php echo esc_html( $quillmoor_role[1] ); ?></code></dd>
				<dt>Password</dt><dd><code><?php echo esc_html( $quillmoor_password ); ?></code></dd>
			</dl>
			<a class="qm-btn qm-btn--primary qm-btn--block" href="<?php echo esc_url( wp_login_url( $quillmoor_role[3] ) ); ?>"><?php echo esc_html( $quillmoor_role[4] ); ?></a>
		</div>
	<?php endforeach; ?>
</div>
<!-- /wp:html -->
<!-- wp:paragraph {"className":"qm-fine"} -->
<p class="qm-fine">Payments run in test mode only. Never enter a real card number. While no Stripe test keys are configured, holds work but the deposit step says payments are not set up.</p>
<!-- /wp:paragraph -->
