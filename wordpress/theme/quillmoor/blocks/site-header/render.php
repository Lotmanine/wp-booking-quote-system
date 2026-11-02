<?php
/**
 * Site header: logo, main menu, account link and the mobile drawer.
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;

$quillmoor_links = quillmoor_menu_links();
$quillmoor_user  = wp_get_current_user();
?>
<header class="qm-header" data-qm-header>
	<div class="qm-container qm-header__inner">
		<a class="qm-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Quillmoor Home Services, home', 'quillmoor' ); ?>">
			<span class="qm-logo__mark" aria-hidden="true">QM</span>
			<span class="qm-logo__word">Quill<span>moor</span></span>
		</a>

		<nav class="qm-nav" aria-label="<?php esc_attr_e( 'Main', 'quillmoor' ); ?>">
			<ul>
				<?php foreach ( $quillmoor_links as $quillmoor_link ) : ?>
					<li><a class="qm-nav__link<?php echo $quillmoor_link['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $quillmoor_link['url'] ); ?>"<?php echo $quillmoor_link['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $quillmoor_link['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<div class="qm-header__actions">
			<?php if ( $quillmoor_user->exists() ) : ?>
				<span class="qm-account" title="<?php esc_attr_e( 'Signed in', 'quillmoor' ); ?>">
					<span class="qm-account__avatar" aria-hidden="true"><?php echo esc_html( strtoupper( substr( (string) $quillmoor_user->display_name, 0, 1 ) ) ); ?></span>
					<span class="qm-account__name"><?php echo esc_html( $quillmoor_user->display_name ); ?></span>
				</span>
				<a class="qm-header__link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'quillmoor' ); ?></a>
			<?php else : ?>
				<a class="qm-header__link" href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>"><?php esc_html_e( 'Sign in', 'quillmoor' ); ?></a>
			<?php endif; ?>
			<a class="qm-nav-cta" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Book a service', 'quillmoor' ); ?></a>
			<button type="button" class="qm-icon-btn qm-menu-btn" aria-controls="qm-drawer" aria-expanded="false" data-qm-open>
				<span class="screen-reader-text"><?php esc_html_e( 'Open menu', 'quillmoor' ); ?></span>
				<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
			</button>
		</div>
	</div>

	<div class="qm-drawer" id="qm-drawer" hidden data-qm-drawer>
		<div class="qm-drawer__backdrop" data-qm-close></div>
		<div class="qm-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="qm-drawer-title">
			<div class="qm-drawer__head">
				<p class="qm-drawer__title" id="qm-drawer-title"><?php esc_html_e( 'Menu', 'quillmoor' ); ?></p>
				<button type="button" class="qm-icon-btn" data-qm-close>
					<span class="screen-reader-text"><?php esc_html_e( 'Close menu', 'quillmoor' ); ?></span>
					<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
				</button>
			</div>
			<ul class="qm-drawer__links">
				<?php foreach ( $quillmoor_links as $quillmoor_link ) : ?>
					<li><a class="qm-drawer__link<?php echo $quillmoor_link['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $quillmoor_link['url'] ); ?>"<?php echo $quillmoor_link['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $quillmoor_link['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<div class="qm-drawer__foot">
				<?php if ( $quillmoor_user->exists() ) : ?>
					<p class="qm-drawer__who"><?php echo esc_html( sprintf( /* translators: %s: display name */ __( 'Signed in as %s', 'quillmoor' ), $quillmoor_user->display_name ) ); ?></p>
					<a class="qm-btn qm-btn--secondary qm-btn--block" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'quillmoor' ); ?></a>
				<?php else : ?>
					<a class="qm-btn qm-btn--secondary qm-btn--block" href="<?php echo esc_url( wp_login_url( home_url( '/portal/' ) ) ); ?>"><?php esc_html_e( 'Sign in', 'quillmoor' ); ?></a>
				<?php endif; ?>
				<a class="qm-btn qm-btn--primary qm-btn--block" href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'Book a service', 'quillmoor' ); ?></a>
			</div>
		</div>
	</div>
</header>
