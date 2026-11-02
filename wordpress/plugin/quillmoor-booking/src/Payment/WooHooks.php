<?php
/**
 * WooCommerce integration.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Payment;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;
use WC_Cart;
use WC_Order;
use WC_Order_Item_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The deposit is a hidden virtual product in the cart. Its price always comes from the stored
 * quote. Order data is read through the CRUD API, so HPOS and the Checkout block both work.
 */
final class WooHooks {

	public const PRODUCT_OPTION = 'qhs_deposit_product_id';
	public const CART_KEY       = 'qhs_booking_id';
	public const ORDER_META     = '_qhs_booking_id';

	/**
	 * True only while this class adds the deposit to the cart.
	 *
	 * @var bool
	 */
	private static bool $adding = false;

	/**
	 * Declare HPOS and cart/checkout block support.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', QUILLMOOR_PLUGIN_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', QUILLMOOR_PLUGIN_FILE, true );
		}
	}

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'wp_loaded', array( self::class, 'start_payment' ), 30 );
		add_action( 'wp_loaded', array( self::class, 'clear_cart_after_payment' ), 31 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'block_direct_add' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'apply_price' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'copy_to_line_item' ), 10, 3 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'validate_store_api_cart' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( self::class, 'validate_classic_checkout' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_paid' ) );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_paid' ) );
		add_filter( 'woocommerce_get_return_url', array( self::class, 'return_url' ), 20, 2 );
		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_fake_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( self::class, 'register_fake_gateway_block' ) );
	}

	/**
	 * Register the labelled fake gateway. It only appears in fake payment mode.
	 *
	 * @param array<int,string> $gateways Gateway classes.
	 * @return array<int,string>
	 */
	public static function add_fake_gateway( array $gateways ): array {
		$gateways[] = FakeGateway::class;
		return $gateways;
	}

	/**
	 * Expose the fake gateway to the Checkout block.
	 *
	 * @param PaymentMethodRegistry $registry Payment method registry.
	 */
	public static function register_fake_gateway_block( PaymentMethodRegistry $registry ): void {
		$registry->register( new FakeGatewayBlockSupport() );
	}

	/**
	 * Turn a pay link into a cart holding one deposit, then send the customer to checkout.
	 */
	public static function start_payment(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authorized by the booking token below.
		if ( ! isset( $_GET['qhs_pay'], $_GET['qhs_token'] ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$booking_id = absint( $_GET['qhs_pay'] );
		$token      = sanitize_text_field( wp_unslash( $_GET['qhs_token'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$booking = Services::bookings()->find( $booking_id );
		if ( null === $booking || ! hash_equals( BookingPolicy::token_for( $booking ), $token ) ) {
			wp_die( esc_html__( 'This payment link is not valid.', 'quillmoor-booking' ), '', array( 'response' => 403 ) );
		}
		if ( BookingState::Hold->value !== $booking['state'] || ! Services::deposit()->is_available() ) {
			wp_safe_redirect( MailService::portal_link( $booking ) );
			exit;
		}

		$product_id = (int) get_option( self::PRODUCT_OPTION );
		wc_load_cart();
		WC()->cart->empty_cart();
		self::$adding = true;
		WC()->cart->add_to_cart( $product_id, 1, 0, array(), array( self::CART_KEY => $booking_id ) );
		self::$adding = false;

		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		if ( null !== $customer ) {
			$name = explode( ' ', (string) $customer['full_name'], 2 );
			WC()->customer->set_billing_first_name( $name[0] );
			WC()->customer->set_billing_last_name( $name[1] ?? '' );
			WC()->customer->set_billing_email( (string) $customer['email'] );
			WC()->customer->save();
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Payment returns to the portal, not the order-received page that normally clears the cart.
	 */
	public static function clear_cart_after_payment(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only empties the visitor's own cart.
		if ( isset( $_GET['paid'] ) && function_exists( 'WC' ) && ! is_admin() ) {
			wc_load_cart();
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Stop the deposit product being added by URL or by another plugin.
	 *
	 * @param bool $passed     Current result.
	 * @param int  $product_id Product id.
	 */
	public static function block_direct_add( bool $passed, int $product_id ): bool {
		if ( ! self::$adding && (int) get_option( self::PRODUCT_OPTION ) === $product_id ) {
			return false;
		}
		return $passed;
	}

	/**
	 * Price the deposit from the stored quote. A deposit line without a booking, for example one
	 * added through the Store API, is removed here.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function apply_price( WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				if ( (int) get_option( self::PRODUCT_OPTION ) === (int) $item['product_id'] ) {
					$cart->remove_cart_item( $key );
				}
				continue;
			}
			$booking = Services::bookings()->find( (int) $item[ self::CART_KEY ] );
			$quote   = null === $booking ? null : Services::bookings()->quote( (int) $booking['quote_id'] );
			if ( null === $quote ) {
				$cart->remove_cart_item( $key );
				continue;
			}
			$item['data']->set_price( ( (int) $quote['deposit_cents'] ) / 100 );
		}
	}

	/**
	 * Carry the booking id onto the order line.
	 *
	 * @param WC_Order_Item_Product $item   Order line.
	 * @param string                $key    Cart key.
	 * @param array<string,mixed>   $values Cart item.
	 */
	public static function copy_to_line_item( WC_Order_Item_Product $item, string $key, array $values ): void {
		if ( ! empty( $values[ self::CART_KEY ] ) ) {
			$item->add_meta_data( self::ORDER_META, (string) $values[ self::CART_KEY ], true );
		}
	}

	/**
	 * Block checkout: refuse a deposit whose hold has run out.
	 *
	 * @param WP_Error $errors Errors.
	 * @param WC_Cart  $cart   Cart.
	 */
	public static function validate_store_api_cart( WP_Error $errors, WC_Cart $cart ): void {
		if ( null !== self::expired_hold_in( $cart ) ) {
			$errors->add( 'qhs_hold_expired', __( 'Your hold has expired. Please choose your dates again.', 'quillmoor-booking' ) );
		}
	}

	/**
	 * Classic checkout: same rule.
	 */
	public static function validate_classic_checkout(): void {
		if ( null !== self::expired_hold_in( WC()->cart ) ) {
			wc_add_notice( __( 'Your hold has expired. Please choose your dates again.', 'quillmoor-booking' ), 'error' );
		}
	}

	/**
	 * Find an expired hold in a cart.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	private static function expired_hold_in( WC_Cart $cart ): ?int {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			$booking = Services::bookings()->find( (int) $item[ self::CART_KEY ] );
			if ( null === $booking
				|| BookingState::Hold->value !== $booking['state']
				|| (string) $booking['hold_expires_at'] < gmdate( 'Y-m-d H:i:s' ) ) {
				return (int) $item[ self::CART_KEY ];
			}
		}
		return null;
	}

	/**
	 * Booking id on an order, read through the CRUD API.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function booking_id_for( WC_Order $order ): int {
		foreach ( $order->get_items() as $item ) {
			$id = (int) $item->get_meta( self::ORDER_META );
			if ( $id > 0 ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Mark the booking paid. Several hooks fire for one payment; the service ignores repeats.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_paid( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$booking_id = self::booking_id_for( $order );
		if ( $booking_id > 0 ) {
			Services::service()->on_deposit_paid( $booking_id, $order_id );
		}
	}

	/**
	 * After checkout, return to the booking in the portal.
	 *
	 * @param string        $url   Default URL.
	 * @param WC_Order|bool $order Order.
	 */
	public static function return_url( string $url, mixed $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return $url;
		}
		$booking_id = self::booking_id_for( $order );
		$booking    = $booking_id > 0 ? Services::bookings()->find( $booking_id ) : null;
		return null === $booking ? $url : add_query_arg( 'paid', '1', MailService::portal_link( $booking ) );
	}
}
