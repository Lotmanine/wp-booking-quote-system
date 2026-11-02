<?php
/**
 * Site footer.
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;
?>
<footer class="qm-footer">
	<div class="qm-container qm-footer__grid">
		<div class="qm-footer__brand">
			<a class="qm-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="qm-logo__mark" aria-hidden="true">QM</span>
				<span class="qm-logo__word">Quill<span>moor</span></span>
			</a>
			<p><?php esc_html_e( 'Quillmoor Home Services is a fictional company. No real services or payments are offered, and every price on this site is fictional.', 'quillmoor' ); ?></p>
		</div>
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'Book', 'quillmoor' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/services/' ) ); ?>"><?php esc_html_e( 'All services', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/services/?category=cleaning' ) ); ?>"><?php esc_html_e( 'Cleaning', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/services/?category=repairs' ) ); ?>"><?php esc_html_e( 'Repairs and fitting', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/services/?category=garden' ) ); ?>"><?php esc_html_e( 'Garden', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'Accounts', 'quillmoor' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/portal/' ) ); ?>"><?php esc_html_e( 'Your bookings', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/staff/' ) ); ?>"><?php esc_html_e( 'Staff portal', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/demo/' ) ); ?>"><?php esc_html_e( 'Demo logins', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'About this demo', 'quillmoor' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Test payments only', 'quillmoor' ); ?></li>
				<li><?php esc_html_e( 'Data resets every night', 'quillmoor' ); ?></li>
				<li><a href="https://oldev.site/" rel="noopener"><?php esc_html_e( 'Built by OLDEV', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
	</div>
	<div class="qm-container qm-footer__base">
		<p class="qm-meta"><?php echo esc_html( sprintf( /* translators: %s: year */ __( 'Demo site, %s. Fictional company and prices.', 'quillmoor' ), gmdate( 'Y' ) ) ); ?></p>
	</div>
</footer>
