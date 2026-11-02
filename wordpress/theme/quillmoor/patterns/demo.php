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
	array( 'Customer', 'demo-customer', 'Book a service, then manage it from Your bookings.', home_url( '/portal/' ) ),
	array( 'Staff', 'demo-staff', 'Work assigned jobs from the day agenda.', home_url( '/staff/' ) ),
	array( 'Admin (restricted)', 'demo-admin', 'Calendar, bookings list, triage and reports in wp-admin.', admin_url( 'admin.php?page=qhs-calendar' ) ),
);
?>
<!-- wp:paragraph {"className":"qm-lead"} -->
<p class="qm-lead">Three shared accounts show each side of the system. Data resets every night, and these accounts cannot change their password or email.</p>
<!-- /wp:paragraph -->
<!-- wp:html -->
<div class="qm-logins">
	<?php foreach ( $quillmoor_roles as $quillmoor_role ) : ?>
		<div class="qm-card qm-login">
			<p class="qm-role__tag"><?php echo esc_html( $quillmoor_role[0] ); ?></p>
			<p class="qm-login__what"><?php echo esc_html( $quillmoor_role[2] ); ?></p>
			<dl>
				<dt>Username</dt><dd><code><?php echo esc_html( $quillmoor_role[1] ); ?></code></dd>
				<dt>Password</dt><dd><code><?php echo esc_html( $quillmoor_password ); ?></code></dd>
			</dl>
			<a class="qm-btn qm-btn--primary qm-btn--block" href="<?php echo esc_url( wp_login_url( $quillmoor_role[3] ) ); ?>">Sign in as <?php echo esc_html( strtolower( strtok( $quillmoor_role[0], ' ' ) ) ); ?></a>
		</div>
	<?php endforeach; ?>
</div>
<!-- /wp:html -->
<!-- wp:paragraph {"className":"qm-fine"} -->
<p class="qm-fine">Payments use test mode only. Never enter a real card number.</p>
<!-- /wp:paragraph -->
