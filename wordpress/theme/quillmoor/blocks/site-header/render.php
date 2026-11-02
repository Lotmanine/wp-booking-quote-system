<?php
/**
 * Site header: logo, stays and restaurants, and the account menu.
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;

$quillmoor_user  = wp_get_current_user();
$quillmoor_host  = $quillmoor_user->exists() && ( current_user_can( 'qhs_host_bookings' ) || current_user_can( 'qhs_manage_bookings' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Capabilities added by the booking plugin.
$quillmoor_type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only highlights a tab.
$quillmoor_path  = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared.
$quillmoor_on    = str_starts_with( $quillmoor_path, '/explore' ) ? ( 'dining' === $quillmoor_type ? 'dining' : 'stay' ) : '';
$quillmoor_links = array(
	'stay'   => array( __( 'Stays', 'quillmoor' ), home_url( '/explore/?type=stay' ) ),
	'dining' => array( __( 'Restaurants', 'quillmoor' ), home_url( '/explore/?type=dining' ) ),
);
?>
<header class="qm-header" data-qm-header>
	<div class="qm-header__inner">
		<a class="qm-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Quillmoor, home', 'quillmoor' ); ?>">
			<svg class="qm-logo__mark" width="30" height="30" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><path d="M6 27C9 16 15 8 27 5c-2 9-8 17-18 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 27l9-10" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>
			<span class="qm-logo__word">quillmoor</span>
		</a>

		<nav class="qm-nav" aria-label="<?php esc_attr_e( 'Main', 'quillmoor' ); ?>">
			<?php foreach ( $quillmoor_links as $quillmoor_key => $quillmoor_link ) : ?>
				<a class="qm-nav__link<?php echo $quillmoor_key === $quillmoor_on ? ' is-active' : ''; ?>" href="<?php echo esc_url( $quillmoor_link[1] ); ?>"<?php echo $quillmoor_key === $quillmoor_on ? ' aria-current="page"' : ''; ?> data-testid="nav-<?php echo esc_attr( $quillmoor_key ); ?>"><?php echo esc_html( $quillmoor_link[0] ); ?></a>
			<?php endforeach; ?>
		</nav>

		<div class="qm-header__actions">
			<?php if ( $quillmoor_host ) : ?>
				<a class="qm-header__link" href="<?php echo esc_url( admin_url( 'admin.php?page=qhs-dashboard' ) ); ?>"><?php esc_html_e( 'Host dashboard', 'quillmoor' ); ?></a>
			<?php else : ?>
				<a class="qm-header__link" href="<?php echo esc_url( home_url( '/demo/' ) ); ?>"><?php esc_html_e( 'Try hosting', 'quillmoor' ); ?></a>
			<?php endif; ?>
			<details class="qm-menu" data-qm-menu>
				<summary class="qm-menu__btn" aria-label="<?php esc_attr_e( 'Account menu', 'quillmoor' ); ?>" data-testid="account-menu">
					<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
					<span class="qm-menu__avatar" aria-hidden="true"><?php echo $quillmoor_user->exists() ? esc_html( strtoupper( substr( (string) $quillmoor_user->display_name, 0, 1 ) ) ) : '<svg width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 21a8 8 0 0 1 16 0z" fill="currentColor"/></svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped name or a static SVG. ?></span>
				</summary>
				<div class="qm-menu__panel">
					<?php if ( $quillmoor_user->exists() ) : ?>
						<p class="qm-menu__who"><?php echo esc_html( sprintf( /* translators: %s: display name */ __( 'Signed in as %s', 'quillmoor' ), $quillmoor_user->display_name ) ); ?></p>
						<a href="<?php echo esc_url( home_url( '/trips/' ) ); ?>"><?php esc_html_e( 'Trips', 'quillmoor' ); ?></a>
						<?php if ( $quillmoor_host ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=qhs-dashboard' ) ); ?>"><?php esc_html_e( 'Host dashboard', 'quillmoor' ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<a href="<?php echo esc_url( wp_login_url( home_url( '/trips/' ) ) ); ?>" class="is-strong"><?php esc_html_e( 'Sign in', 'quillmoor' ); ?></a>
						<a href="<?php echo esc_url( home_url( '/trips/' ) ); ?>"><?php esc_html_e( 'Trips', 'quillmoor' ); ?></a>
					<?php endif; ?>
					<hr>
					<a href="<?php echo esc_url( home_url( '/explore/?type=stay' ) ); ?>"><?php esc_html_e( 'Places to stay', 'quillmoor' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/explore/?type=dining' ) ); ?>"><?php esc_html_e( 'Places to eat', 'quillmoor' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/demo/' ) ); ?>"><?php esc_html_e( 'Demo logins', 'quillmoor' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/photo-credits/' ) ); ?>"><?php esc_html_e( 'Photo credits', 'quillmoor' ); ?></a>
					<?php if ( $quillmoor_user->exists() ) : ?>
						<hr>
						<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'quillmoor' ); ?></a>
					<?php endif; ?>
				</div>
			</details>
		</div>
	</div>
</header>
