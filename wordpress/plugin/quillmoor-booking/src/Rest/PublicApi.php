<?php
/**
 * Public booking endpoints.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Domain\Availability;
use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\Search;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Security\ClientIp;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Search, calendars, price previews, quotes and holds.
 */
final class PublicApi {

	/**
	 * Listing cards for the explore grid and map.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function listings( WP_REST_Request $request ): WP_REST_Response {
		$filters = Search::filters( $request->get_params() );
		$cards   = array_map( static fn( Listing $listing ): array => Search::card( $listing, $filters ), Search::find( $filters ) );
		return new WP_REST_Response( array( 'listings' => $cards ) );
	}

	/**
	 * Unavailable nights or closed days in a range, for the date pickers.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function calendar( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = self::published( (int) $request['id'] );
		if ( null === $listing ) {
			return Respond::error( 'not_found', 'That place is not available.', 404 );
		}
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		$from = Listing::is_date( $from ) ? $from : gmdate( 'Y-m-d' );
		$to   = Listing::is_date( $to ) && $to >= $from ? $to : gmdate( 'Y-m-d', (int) strtotime( $from . ' 00:00:00 UTC' ) + 92 * DAY_IN_SECONDS );
		if ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) > 400 * DAY_IN_SECONDS ) {
			return Respond::error( 'range_too_long', 'Ask for a shorter range.', 400 );
		}

		if ( ListingType::Stay === $listing->type ) {
			return new WP_REST_Response(
				array(
					'type'        => 'stay',
					'first'       => Availability::first_check_in(),
					'last'        => gmdate( 'Y-m-d', time() + Config::STAY_DAYS_AHEAD * DAY_IN_SECONDS ),
					'min_nights'  => $listing->min_nights,
					'max_nights'  => $listing->max_nights,
					'unavailable' => Services::availability()->stay_calendar( $listing, $from, $to ),
				)
			);
		}
		return new WP_REST_Response(
			array(
				'type'   => 'dining',
				'first'  => gmdate( 'Y-m-d' ),
				'last'   => gmdate( 'Y-m-d', time() + Config::DINING_DAYS_AHEAD * DAY_IN_SECONDS ),
				'closed' => Services::availability()->dining_calendar( $listing, $from, $to ),
			)
		);
	}

	/**
	 * Open table times on one day.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function slots( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$listing = self::published( (int) $request['id'] );
		if ( null === $listing || ListingType::Dining !== $listing->type ) {
			return Respond::error( 'not_found', 'That place is not available.', 404 );
		}
		$day   = (string) $request->get_param( 'date' );
		$party = max( $listing->min_party, (int) $request->get_param( 'party' ) );
		if ( ! Listing::is_date( $day ) ) {
			return Respond::error( 'invalid_date', 'Choose a date.', 400 );
		}
		$slots = array();
		foreach ( Services::availability()->dining_slots( $listing, $day ) as $slot ) {
			$slots[] = $slot + array( 'fits' => $slot['seats_left'] >= $party );
		}
		return new WP_REST_Response( array( 'slots' => $slots ) );
	}

	/**
	 * Server price for a request, so the booking card never computes money itself.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function price( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Services::counters()->take( 'rl:price:' . ClientIp::hash( ClientIp::resolve() ), MINUTE_IN_SECONDS, 120 ) ) {
			return Respond::error( 'rate_limited', 'Too many requests. Please wait a minute.', 429 );
		}
		$listing = Listing::find( (int) $request['id'] );
		$preview = null !== $listing && ! $listing->is_published() && BookingPolicy::can_manage_listing( get_current_user_id(), $listing->id );
		if ( null === $listing || ( ! $listing->is_published() && ! $preview ) ) {
			return Respond::error( 'not_found', 'That place is not available.', 404 );
		}
		try {
			$quote = Services::service()->price_request(
				$listing,
				sanitize_text_field( (string) $request->get_param( 'check_in' ) ),
				sanitize_text_field( (string) $request->get_param( 'check_out' ) ),
				sanitize_text_field( (string) $request->get_param( 'slot_start' ) ),
				max( 1, (int) $request->get_param( 'guests' ) ),
				self::extras( $request )
			);
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}
		return new WP_REST_Response( Respond::price( $quote['price'] ) );
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

		// A signed-in guest books under their own record, whatever the form says.
		$own = $user > 0 ? Services::bookings()->customer_by_user( $user ) : null;
		if ( null !== $own ) {
			$email = (string) $own['email'];
			$name  = (string) $own['full_name'];
		} elseif ( $user > 0 ) {
			// No record yet: use the account's own address, so a typed address never links someone else's bookings.
			$email = strtolower( (string) wp_get_current_user()->user_email );
		}

		if ( ! is_email( $email ) || '' === $name || strlen( $name ) > 120 ) {
			return Respond::error( 'invalid_contact', 'Please enter your name and a valid email.', 400 );
		}

		try {
			$result = Services::service()->create_quote(
				array(
					'listing_id'        => (int) $request->get_param( 'listing_id' ),
					'check_in'          => sanitize_text_field( (string) $request->get_param( 'check_in' ) ),
					'check_out'         => sanitize_text_field( (string) $request->get_param( 'check_out' ) ),
					'slot_start'        => sanitize_text_field( (string) $request->get_param( 'slot_start' ) ),
					'guests'            => max( 1, (int) $request->get_param( 'guests' ) ),
					'extras'            => self::extras( $request ),
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
		if ( get_current_user_id() < 1 ) {
			// A Turnstile token is single use, so the check this quote passed also covers its first hold.
			set_transient( self::pass_key( (int) $booking['id'] ), 1, 5 * MINUTE_IN_SECONDS );
		}
		return new WP_REST_Response(
			array(
				'booking' => Respond::booking( $booking ),
				'token'   => BookingPolicy::token_for( $booking ),
			),
			201
		);
	}

	/**
	 * Hold the nights or seats of a quote for ten minutes. The hold works even while payments are
	 * not set up, so the guest sees the real availability and an honest payment step.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function hold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$blocked = Respond::live_key_block() ?? self::guard( $request, 'hold', Config::int( 'QHS_LIMIT_HOLD_PER_MIN', 10 ), false );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$booking = Respond::viewable_for_change( $request, (int) $request->get_param( 'booking_id' ) );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		// The first hold within five minutes of a checked quote reuses that check; any other hold by a
		// visitor who is not signed in needs a fresh token.
		if ( get_current_user_id() < 1 && ! delete_transient( self::pass_key( (int) $booking['id'] ) )
			&& ! Services::turnstile()->verify( (string) $request->get_param( 'turnstile_token' ), ClientIp::resolve() ) ) {
			return Respond::error( 'turnstile_failed', 'Please complete the security check.', 403 );
		}

		try {
			$held = Services::service()->hold( (int) $booking['id'], Respond::actor( $request )->label() );
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}

		$payable = Services::deposit()->is_available();
		return new WP_REST_Response(
			array(
				'booking'        => Respond::booking( $held ),
				'pay_url'        => $payable ? Services::deposit()->pay_url( $held ) : null,
				'payment_status' => $payable ? 'ready' : 'not_set_up',
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
	 * Whether a visitor who is not signed in can reserve: that needs the security check.
	 */
	public static function guests_can_reserve(): bool {
		return '' !== Config::get( 'TURNSTILE_SITE_KEY' ) && '' !== Config::get( 'TURNSTILE_SECRET_KEY' );
	}

	/**
	 * Per-IP limit first because it is cheap, then the security check for visitors who are not
	 * signed in. Accounts are created by the site only, so a signed-in request skips Turnstile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $name    Limit name.
	 * @param int             $limit   Requests per minute.
	 * @param bool            $check   Whether to verify the Turnstile token here; false when the caller does.
	 */
	private static function guard( WP_REST_Request $request, string $name, int $limit, bool $check = true ): ?WP_Error {
		$ip = ClientIp::resolve();
		if ( ! Services::counters()->take( 'rl:' . $name . ':' . ClientIp::hash( $ip ), MINUTE_IN_SECONDS, $limit ) ) {
			return Respond::error( 'rate_limited', 'Too many requests. Please wait a minute.', 429 );
		}
		if ( get_current_user_id() > 0 ) {
			return null;
		}
		if ( ! self::guests_can_reserve() ) {
			return Respond::error( 'signin_required', 'Sign in to reserve. The security check for guests is not set up yet on this demo.', 403 );
		}
		if ( $check && ! Services::turnstile()->verify( (string) $request->get_param( 'turnstile_token' ), $ip ) ) {
			return Respond::error( 'turnstile_failed', 'Please complete the security check.', 403 );
		}
		return null;
	}

	/**
	 * Transient that records a passed Turnstile check for a booking's first hold.
	 *
	 * @param int $booking_id Booking id.
	 */
	private static function pass_key( int $booking_id ): string {
		return 'qhs_turnstile_pass_' . $booking_id;
	}

	/**
	 * Extra keys from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]
	 */
	private static function extras( WP_REST_Request $request ): array {
		$extras = $request->get_param( 'extras' );
		return is_array( $extras ) ? array_values( array_filter( array_map( 'sanitize_title', array_map( 'strval', $extras ) ) ) ) : array();
	}

	/**
	 * A published listing, or null.
	 *
	 * @param int $id Listing id.
	 */
	private static function published( int $id ): ?Listing {
		$listing = Listing::find( $id );
		return null !== $listing && $listing->is_published() ? $listing : null;
	}
}
