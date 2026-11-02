<?php
/**
 * Title: Home
 * Slug: quillmoor/home
 * Inserter: no
 *
 * @package Quillmoor
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:group {"className":"qm-home-search","layout":{"type":"default"}} -->
<div class="wp-block-group qm-home-search">
<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:heading {"level":1,"className":"qm-home-title"} -->
<h1 class="wp-block-heading qm-home-title">Find a place to stay, or a table for tonight</h1>
<!-- /wp:heading -->
<!-- wp:quillmoor/search /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:quillmoor/explore {"section":"home"} /-->

<!-- wp:group {"className":"qm-how","layout":{"type":"default"}} -->
<div class="wp-block-group qm-how">
<!-- wp:heading {"className":"qm-how__title"} -->
<h2 class="wp-block-heading qm-how__title">How a booking works here</h2>
<!-- /wp:heading -->
<!-- wp:html -->
<ol class="qm-how__steps">
	<li><strong>Pick your dates or a time.</strong> The calendar greys out nights that are booked, held or blocked by the host, and times that are full.</li>
	<li><strong>Hold it for ten minutes.</strong> The database gives each night or seat to one guest only, even when many people click at once.</li>
	<li><strong>Pay a test deposit.</strong> Through WooCommerce and Stripe in test mode. Instant book confirms at once; otherwise the host has 24 hours to answer.</li>
	<li><strong>Manage it from Trips.</strong> Change dates, cancel under the listing's policy, and download the booking PDF.</li>
</ol>
<!-- /wp:html -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
