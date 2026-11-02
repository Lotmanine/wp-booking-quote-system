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
<!-- wp:group {"align":"full","className":"qm-hero","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull qm-hero">
<!-- wp:group {"className":"qm-container qm-hero__inner","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container qm-hero__inner">
<!-- wp:paragraph {"className":"qm-eyebrow"} -->
<p class="qm-eyebrow">Fictional home services, real booking engine</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"qm-hero__title"} -->
<h1 class="wp-block-heading qm-hero__title">Book home help the way you book a stay.</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"qm-lead"} -->
<p class="qm-lead">Search a service, pick a day on the calendar, hold a slot for ten minutes and pay a test deposit. A team member is assigned by area, skill and workload.</p>
<!-- /wp:paragraph -->
<!-- wp:quillmoor/search /-->
<!-- wp:paragraph {"className":"qm-hero__note"} -->
<p class="qm-hero__note">Demo only. Prices are fictional and payments run in test mode.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"qm-section","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull qm-section">
<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:group {"className":"qm-section-head","layout":{"type":"default"}} -->
<div class="wp-block-group qm-section-head">
<!-- wp:paragraph {"className":"qm-eyebrow"} -->
<p class="qm-eyebrow">Services</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"className":"qm-h2"} -->
<h2 class="wp-block-heading qm-h2">Six services, one calendar</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:quillmoor/services {"mode":"featured"} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"qm-section qm-section--alt","anchor":"how-it-works","layout":{"type":"default"}} -->
<div id="how-it-works" class="wp-block-group alignfull qm-section qm-section--alt">
<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:group {"className":"qm-section-head","layout":{"type":"default"}} -->
<div class="wp-block-group qm-section-head">
<!-- wp:paragraph {"className":"qm-eyebrow"} -->
<p class="qm-eyebrow">How it works</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"className":"qm-h2"} -->
<h2 class="wp-block-heading qm-h2">From search to a confirmed visit</h2>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->
<!-- wp:html -->
<ol class="qm-steps">
	<li class="qm-card qm-step"><span class="qm-step__n">01</span><h3>Search and compare</h3><p>Filter by category, price and visit length. Every price is worked out on the server, never trusted from the browser.</p></li>
	<li class="qm-card qm-step"><span class="qm-step__n">02</span><h3>Pick a day and a time</h3><p>The calendar only offers open slots. Your choice is held for ten minutes, and two people can never hold the same slot.</p></li>
	<li class="qm-card qm-step"><span class="qm-step__n">03</span><h3>Pay a test deposit</h3><p>Checkout runs on WooCommerce in Stripe test mode. A live key is refused, so no real money can move.</p></li>
	<li class="qm-card qm-step"><span class="qm-step__n">04</span><h3>A team member is assigned</h3><p>A separate dispatch service picks staff by area, skill and workload, then signs its answer back to the site.</p></li>
</ol>
<!-- /wp:html -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"qm-section","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull qm-section">
<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:group {"className":"qm-section-head","layout":{"type":"default"}} -->
<div class="wp-block-group qm-section-head">
<!-- wp:paragraph {"className":"qm-eyebrow"} -->
<p class="qm-eyebrow">Behind the booking</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"className":"qm-h2"} -->
<h2 class="wp-block-heading qm-h2">One booking, three dashboards</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"qm-body"} -->
<p class="qm-body">Sign in with a shared demo account to see each side of the same system.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
<!-- wp:html -->
<div class="qm-roles">
	<a class="qm-card qm-role" href="/demo/"><span class="qm-role__tag">Customer</span><h3>Your bookings</h3><p>Upcoming and past visits, live status, reschedule, cancel and PDF documents.</p><span class="qm-link-arrow">Try it <span aria-hidden="true">&rarr;</span></span></a>
	<a class="qm-card qm-role" href="/demo/"><span class="qm-role__tag">Staff</span><h3>Day agenda</h3><p>Jobs grouped by day, one tap to move a job from on the way to complete.</p><span class="qm-link-arrow">Try it <span aria-hidden="true">&rarr;</span></span></a>
	<a class="qm-card qm-role" href="/demo/"><span class="qm-role__tag">Admin</span><h3>Calendar and reports</h3><p>A month calendar, the full bookings list, triage suggestions and fictional revenue.</p><span class="qm-link-arrow">Try it <span aria-hidden="true">&rarr;</span></span></a>
</div>
<!-- /wp:html -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"full","className":"qm-section qm-section--tight","layout":{"type":"default"}} -->
<div class="wp-block-group alignfull qm-section qm-section--tight">
<!-- wp:group {"className":"qm-container","layout":{"type":"default"}} -->
<div class="wp-block-group qm-container">
<!-- wp:html -->
<div class="qm-cta-band">
	<div>
		<h2 class="qm-h2">Ready to try a booking?</h2>
		<p>It takes about a minute, and nothing you do here costs money.</p>
	</div>
	<div class="qm-cta-band__actions">
		<a class="qm-btn qm-btn--primary" href="/services/">Browse services</a>
		<a class="qm-btn qm-btn--secondary" href="/demo/">Demo logins</a>
	</div>
</div>
<!-- /wp:html -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
