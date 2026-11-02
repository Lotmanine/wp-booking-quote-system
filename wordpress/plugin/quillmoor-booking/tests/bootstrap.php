<?php
/**
 * Test bootstrap: real WordPress, WooCommerce and FluentCRM against a real MariaDB.
 *
 * @package Quillmoor\Booking
 */

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $plugin_root . '/tests/wp-tests-config.php' );
putenv( 'QHS_HMAC_SECRET_N8N=' . str_repeat( 'n', 40 ) );
putenv( 'QHS_HMAC_SECRET_DISPATCH=' . str_repeat( 'd', 40 ) );
putenv( 'TURNSTILE_SECRET_KEY=test-secret' );
putenv( 'QHS_PAYMENT_MODE=fake' );
putenv( 'QHS_MAIL_PER_ADDRESS_DAILY=5' );
putenv( 'QHS_MAIL_DAILY_CAP=50' );
putenv( 'QHS_LIMIT_QUOTE_PER_MIN=1000' );
putenv( 'QHS_LIMIT_HOLD_PER_MIN=1000' );

$tests_dir = $plugin_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		$plugins = dirname( $plugin_root );
		require_once $plugins . '/woocommerce/woocommerce.php';
		require_once $plugins . '/fluent-crm/fluent-crm.php';
		require_once $plugin_root . '/quillmoor-booking.php';
	}
);

tests_add_filter(
	'plugins_loaded',
	static function (): void {
		require_once FLUENTCRM_PLUGIN_PATH . 'database/FluentCRMDBMigrator.php';
		\FluentCRMDBMigrator::migrate();
	},
	1
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
		wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		\Quillmoor\Booking\Schema::install();
		\Quillmoor\Booking\Demo\Roles::sync();
	}
);

require $tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/Integration/TestCase.php';
