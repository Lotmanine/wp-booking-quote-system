<?php
/**
 * Public wizard endpoints.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Security\ClientIp;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Quote wizard API.
 */
final class PublicApi {

	/**
	 * Catalog without internal fields such as the dispatch skill.
	 */
	public static function catalog(): WP_REST_Response {
		$catalog = Catalog::all();
		$out     = array();
		foreach ( $catalog as $group => $items ) {
			foreach ( $items as $key => $item ) {
				$row = array(
					'key'   => $key,
					'label' => $item['label'],
					'cents' => (int) ( $item['base_cents'] ?? $item['cents'] ?? $item['surcharge_cents'] ?? 0 ),
				);
				if ( 'services' === $group ) {
					$row['category'] = (string) ( $item['category'] ?? '' );
					$row['hours']    = (string) ( $item['hours'] ?? '' );
					$row['summary']  = (string) ( $item['summary'] ?? '' );
					$row['includes'] = array_values( array_map( 'strval', (array) ( $item['includes'] ?? array() ) ) );
					$row['image']    = Catalog::image_url( (string) $key );
				}
				if ( 'sizes' === $group ) {
					$row['multiplier'] = (int) $item['multiplier'];
				}
				$out[ $group ][] = $row;
			}
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Open slots for an area.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function availability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$area = sanitize_key( (string) $request->get_param( 'area' ) );
		if ( Catalog::capacity( $area ) < 1 ) {
			return Respond::error( 'invalid_option', 'That option is not available.', 400 );
		}
		return new WP_REST_Response( array( 'days' => Services::availability()->open_slots( $area ) ) );
	}

	/**
	 * Create a quote.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$blocked = Respond::live_key_block() ?? self::guard( $request, 'quote', Config::int( 'QHS_LIMIT_QUOTE_PER_MIN', 10 ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$email = strtolower( sanitize_email( (string) $request->get_param( 'email' ) ) );
		$name  = sanitize_text_field( (string) $request->get_param( 'full_name' ) );
		$user  = get_current_user_id();

		// A signed-in customer books under their own record, whatever the form says.
		$own = $user > 0 ? Services::bookings()->customer_by_user( $user ) : null;
		if ( null !== $own ) {
			$email = (string) $own['email'];
			$name  = (string) $own['full_name'];
		}

		if ( ! is_email( $email ) || '' === $name || strlen( $name ) > 120 ) {
			return Respond::error( 'invalid_contact', 'Please enter your name and a valid email.', 400 );
		}

		$extras = $request->get_param( 'extras' );
		$extras = is_array( $extras ) ? array_map( 'sanitize_key', array_map( 'strval', $extras ) ) : array();

		try {
			$result = Services::service()->create_quote(
				array(
					'service'           => sanitize_key( (string) $request->get_param( 'service' ) ),
					'size'              => sanitize_key( (string) $request->get_param( 'size' ) ),
					'area'              => sanitize_key( (string) $request->get_param( 'area' ) ),
					'extras'            => $extras,
					'email'             => $email,
					'full_name'         => $name,
					'note'              => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'note' ) ), 0, 500 ),
					'marketing_consent' => filter_var( $request->get_param( 'marketing_consent' ), FILTER_VALIDATE_BOOLEAN ),
				),
				$user
			);
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}

		$booking = (array) Services::bookings()->find( $result['booking_id'] );
		return new WP_REST_Response(
			array(
				'booking' => Respond::booking( $booking ),
				'token'   => BookingPolicy::token_for( $booking ),
			),
			201
		);
	}

	/**
	 * Hold a slot.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function hold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$blocked = Respond::live_key_block() ?? self::guard( $request, 'hold', Config::int( 'QHS_LIMIT_HOLD_PER_MIN', 10 ) );
		if ( null !== $blocked ) {
			return $blocked;
		}
		if ( ! Services::deposit()->is_available() ) {
			return Respond::error( 'payments_unavailable', 'Deposits are not set up yet.', 503 );
		}

		$booking = Respond::viewable_for_change( $request, (int) $request->get_param( 'booking_id' ) );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		try {
			$held = Services::service()->hold( (int) $booking['id'], sanitize_text_field( (string) $request->get_param( 'slot_start' ) ), Respond::actor( $request )->label() );
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}

		return new WP_REST_Response(
			array(
				'booking' => Respond::booking( $held ),
				'pay_url' => Services::deposit()->pay_url( $held ),
			),
			201
		);
	}

	/**
	 * Confirm an email address.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function verify_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ip = ClientIp::hash( ClientIp::resolve() );
		if ( ! Services::counters()->take( 'rl:verify:' . $ip, MINUTE_IN_SECONDS, 10 ) ) {
			return Respond::error( 'rate_limited', 'Too many requests. Please wait a minute.', 429 );
		}
		$done = Services::service()->verify_email( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		return $done
			? new WP_REST_Response( array( 'verified' => true ) )
			: Respond::error( 'invalid_token', 'This link is not valid or has expired.', 400 );
	}

	/**
	 * Per-IP limit first because it is cheap, then Turnstile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Limit name.
	 * @param int             $limit   Requests per minute.
	 */
	private static function guard( WP_REST_Request $request, string $name, int $limit ): ?WP_Error {
		$ip = ClientIp::resolve();
		if ( ! Services::counters()->take( 'rl:' . $name . ':' . ClientIp::hash( $ip ), MINUTE_IN_SECONDS, $limit ) ) {
			return Respond::error( 'rate_limited', 'Too many requests. Please wait a minute.', 429 );
		}
		if ( ! Services::turnstile()->verify( (string) $request->get_param( 'turnstile_token' ), $ip ) ) {
			return Respond::error( 'turnstile_failed', 'Please complete the security check.', 403 );
		}
		return null;
	}
}
