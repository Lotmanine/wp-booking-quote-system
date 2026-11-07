<?php
/**
 * Wordfence settings, applied with `wp eval-file` on every boot so they survive redeploys.
 * Two-factor login stays available but is not forced: enrolling is a step for the site owner.
 */

if ( ! class_exists( 'wfConfig' ) ) {
	WP_CLI::warning( 'Wordfence is not loaded, settings skipped.' );
	return;
}

$settings = array(
	'firewallEnabled'                => 1,
	'loginSecurityEnabled'           => 1,
	// The demo logins share one public password, so the limits leave room for typing mistakes:
	// ten failures inside an hour lock the address out for an hour.
	'loginSec_maxFailures'           => 10,
	'loginSec_maxForgotPasswd'       => 5,
	'loginSec_countFailMins'         => 60,
	'loginSec_lockoutMins'           => 60,
	// A mistyped demo username must not lock a visitor out at once.
	'loginSec_lockInvalidUsers'      => 0,
	'loginSec_userBlacklist'         => "admin\nadministrator\nroot\nwordpress",
	'loginSec_maskLoginErrors'       => 1,
	// Also closes the REST API user list and oEmbed author data to visitors who are not signed in.
	'loginSec_disableAuthorScan'     => 1,
	'loginSec_disableOEmbedAuthor'   => 1,
	'loginSec_breachPasswds_enabled' => 1,
	'other_hideWPVersion'            => 1,
	// Signed machine callbacks and payment webhooks may arrive without a browser User-Agent. They are
	// checked by their signatures instead.
	'other_blockBadPOST'             => 0,
	'disableCodeExecutionUploads'    => 1,
	// Rate limits. A listing page makes a few API calls per change of dates; the n8n callbacks, the
	// payment webhook and the checkout are a handful of requests per booking, well under these.
	'maxGlobalRequests'              => 480,
	'maxGlobalRequests_action'       => 'throttle',
	'maxRequestsCrawlers'            => 120,
	'maxRequestsCrawlers_action'     => 'throttle',
	'maxRequestsHumans'              => 240,
	'maxRequestsHumans_action'       => 'throttle',
	'max404Crawlers'                 => 60,
	'max404Crawlers_action'          => 'throttle',
	'max404Humans'                   => 60,
	'max404Humans_action'            => 'throttle',
	'blockedTime'                    => 300,
	// Apache's mod_remoteip already sets REMOTE_ADDR to the visitor address, trusting X-Forwarded-For only
	// from the networks this container is attached to (see qhs-remoteip.php). No header is trusted here.
	'howGetIPs'                      => 'REMOTE_ADDR',
	'howGetIPs_trusted_proxies'      => '',
	// No address bypasses the firewall.
	'whitelisted'                    => '',
	'scheduledScansEnabled'          => 1,
	'alertEmails'                    => (string) getenv( 'WP_ADMIN_EMAIL' ),
);

foreach ( $settings as $key => $value ) {
	wfConfig::set( $key, $value );
}

// Leave learning mode at once: there is no real traffic to learn from. The firewall's file storage
// refuses writes from the CLI unless told otherwise, and saves on shutdown.
if ( ! defined( 'WFWAF_ALWAYS_ALLOW_FILE_WRITING' ) ) {
	define( 'WFWAF_ALWAYS_ALLOW_FILE_WRITING', true );
}
if ( class_exists( 'wfWAF' ) && wfWAF::getInstance() ) {
	$storage = wfWAF::getInstance()->getStorageEngine();
	$storage->setConfig( 'wafStatus', 'enabled' );
	$storage->setConfig( 'learningModeGracePeriodEnabled', 0 );
	$storage->unsetConfig( 'learningModeGracePeriod' );
}

WP_CLI::success( 'Wordfence settings applied.' );
