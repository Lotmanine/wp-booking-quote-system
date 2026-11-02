<?php
/**
 * Plugin Name:       Quillmoor Booking
 * Description:       Stays and table bookings with holds, deposits, host tools and guest trips for the fictional Quillmoor demo.
 * Version:           2.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce, secure-custom-fields
 * Author:            Lotfi O.
 * License:           GPL-2.0-or-later
 * Text Domain:       quillmoor-booking
 *
 * @package Quillmoor\Booking
 */

defined( 'ABSPATH' ) || exit;

define( 'QUILLMOOR_PLUGIN_FILE', __FILE__ );
define( 'QUILLMOOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUILLMOOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QUILLMOOR_VERSION', '2.0.0' );

require_once QUILLMOOR_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \Quillmoor\Booking\Schema::class, 'install' ) );

add_action( 'before_woocommerce_init', array( \Quillmoor\Booking\Payment\WooHooks::class, 'declare_compatibility' ) );
add_action( 'plugins_loaded', array( \Quillmoor\Booking\Plugin::class, 'boot' ), 20 );
