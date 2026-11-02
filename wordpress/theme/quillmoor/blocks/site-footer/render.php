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
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'Book', 'quillmoor' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/explore/?type=stay' ) ); ?>"><?php esc_html_e( 'Places to stay', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/explore/?type=dining' ) ); ?>"><?php esc_html_e( 'Places to eat', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/trips/' ) ); ?>"><?php esc_html_e( 'Trips', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'Hosting', 'quillmoor' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/demo/' ) ); ?>"><?php esc_html_e( 'Demo logins', 'quillmoor' ); ?></a></li>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=qhs-dashboard' ) ); ?>"><?php esc_html_e( 'Host dashboard', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
		<div>
			<p class="qm-footer__head"><?php esc_html_e( 'About this demo', 'quillmoor' ); ?></p>
			<ul>
				<li><a href="<?php echo esc_url( home_url( '/photo-credits/' ) ); ?>"><?php esc_html_e( 'Photo credits', 'quillmoor' ); ?></a></li>
				<li><?php esc_html_e( 'Test payments only, data resets every night', 'quillmoor' ); ?></li>
				<li><a href="https://oldev.site/" rel="noopener"><?php esc_html_e( 'Built by OLDEV', 'quillmoor' ); ?></a></li>
			</ul>
		</div>
	</div>
	<div class="qm-container qm-footer__base">
		<p><?php echo esc_html( sprintf( /* translators: %s: year */ __( 'Quillmoor is a fictional brand made for a portfolio demo, %s. The places, hosts and prices are invented, nothing can really be booked, and no money moves.', 'quillmoor' ), gmdate( 'Y' ) ) ); ?></p>
	</div>
</footer>
