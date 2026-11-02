<?php
/**
 * Shared test helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Db;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Listings\FieldWriter;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Payment\FakeGateway;
use Quillmoor\Booking\Payment\WooHooks;
use Quillmoor\Booking\Services;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/** Thrown in place of a redirect, so an admin-post handler never reaches its exit. */
class RedirectedException extends \RuntimeException {
}

abstract class TestCase extends WP_UnitTestCase {

	/** Tables cleaned before every test. */
	public const TABLES = array( 'customers', 'quotes', 'bookings', 'night_claims', 'table_slots', 'table_claims', 'day_closures', 'booking_notes', 'booking_history', 'outbox', 'nonces', 'counters', 'mail_log', 'triage', 'alerts' );

	/** @var array<string,string|false> Environment to restore. */
	private array $saved_env = array();

	/** @var array<int,array<string,mixed>> Outbound HTTP calls. */
	protected array $http = array();

	/** @var bool What the fake Turnstile answers. */
	protected bool $turnstile_ok = true;

	/** @var int Host of the default listings. */
	protected int $host = 0;

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		$this->http         = array();
		$this->turnstile_ok = true;
		$this->host         = 0;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		foreach ( self::TABLES as $table ) {
			$GLOBALS['wpdb']->query( 'DELETE FROM ' . Db::table( $table ) );
		}
		$this->flush_fields();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->saved_env as $name => $value ) {
			putenv( false === $value ? $name : $name . '=' . $value );
		}
		$this->saved_env = array();
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$_POST    = array();
		$_REQUEST = array();
		Services::reset();
		parent::tear_down();
	}

	/** Set an environment variable for this test only. */
	protected function env( string $name, string $value ): void {
		if ( ! array_key_exists( $name, $this->saved_env ) ) {
			$this->saved_env[ $name ] = getenv( $name );
		}
		putenv( $name . '=' . $value );
	}

	/** Remove an environment variable for this test only. */
	protected function unset_env( string $name ): void {
		if ( ! array_key_exists( $name, $this->saved_env ) ) {
			$this->saved_env[ $name ] = getenv( $name );
		}
		putenv( $name );
	}

	public function fake_http( $result, array $args, string $url ) {
		$this->http[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( str_contains( $url, 'turnstile' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode( array( 'success' => $this->turnstile_ok ) ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		return array(
			'response' => array( 'code' => 201 ),
			'body'     => '{}',
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	/** Forget field values Secure Custom Fields cached in this request. */
	protected function flush_fields(): void {
		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'values' )->reset();
		}
	}

	/* ---------------------------------------------------------------- Dates */

	/** A date some days from today, UTC. */
	protected function day( int $offset ): string {
		return gmdate( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );
	}

	/** First date at least $min_days ahead that falls on an ISO weekday (1 Monday .. 7 Sunday). */
	protected function weekday( int $iso, int $min_days = 7 ): string {
		for ( $i = $min_days; $i < $min_days + 7; $i++ ) {
			if ( (int) gmdate( 'N', time() + $i * DAY_IN_SECONDS ) === $iso ) {
				return $this->day( $i );
			}
		}
		return $this->day( $min_days );
	}

	/** Date plus days. */
	protected function plus( string $date, int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + $days * DAY_IN_SECONDS );
	}

	/* ---------------------------------------------------------------- Listings */

	/** A host account. */
	protected function make_host(): int {
		return $this->user( Roles::HOST );
	}

	/** Default host, created once per test. */
	protected function host(): int {
		if ( 0 === $this->host ) {
			$this->host = $this->make_host();
		}
		return $this->host;
	}

	/** A location term with a dispatch zone. */
	protected function location( string $slug = 'test-valley', string $zone = 'north' ): int {
		$term = get_term_by( 'slug', $slug, PostTypes::LOCATION );
		if ( $term ) {
			return (int) $term->term_id;
		}
		$made = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), PostTypes::LOCATION, array( 'slug' => $slug ) );
		update_term_meta( (int) $made['term_id'], 'qm_zone', $zone );
		return (int) $made['term_id'];
	}

	/**
	 * Create a listing post and its fields.
	 *
	 * @param array<string,mixed> $fields Field values.
	 */
	protected function listing( array $fields, int $host = 0, string $status = 'publish', string $location = 'test-valley' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => PostTypes::LISTING,
				'post_status' => $status,
				'post_title'  => 'dining' === ( $fields['qm_type'] ?? '' ) ? 'Test Table' : 'Test Stay',
				'post_author' => $host > 0 ? $host : $this->host(),
			)
		);
		FieldWriter::save( $id, $fields );
		if ( '' !== $location ) {
			$this->location( $location );
			wp_set_object_terms( $id, array( $location ), PostTypes::LOCATION );
		}
		$this->flush_fields();
		return $id;
	}

	/**
	 * A place to stay: $100 a night, 1 to 14 nights, 4 guests, instant book, 20% deposit.
	 *
	 * @param array<string,mixed> $fields Overrides.
	 */
	protected function stay_listing( array $fields = array(), int $host = 0, string $status = 'publish' ): int {
		return $this->listing(
			array_merge(
				array(
					'qm_type'           => 'stay',
					'qm_summary'        => 'A test stay.',
					'qm_instant_book'   => true,
					'qm_max_guests'     => 4,
					'qm_bedrooms'       => 2,
					'qm_beds'           => 2,
					'qm_baths'          => 1,
					'qm_check_in_time'  => '15:00',
					'qm_check_out_time' => '11:00',
					'qm_nightly_price'  => 100,
					'qm_weekend_price'  => 0,
					'qm_cleaning_fee'   => 0,
					'qm_min_nights'     => 1,
					'qm_max_nights'     => 14,
					'qm_seasons'        => array(),
					'qm_deposit_rule'   => 'percent',
					'qm_deposit_value'  => 20,
					'qm_extras'         => array(),
					'qm_cancellation'   => 'moderate',
					'qm_address'        => '1 Test Lane',
				),
				$fields
			),
			$host,
			$status
		);
	}

	/**
	 * A place to eat: $50 set menu, 10 seats a slot, parties of 1 to 8, dinner 18:00 to 20:00 every day.
	 *
	 * @param array<string,mixed> $fields Overrides.
	 */
	protected function dining_listing( array $fields = array(), int $host = 0, string $status = 'publish' ): int {
		return $this->listing(
			array_merge(
				array(
					'qm_type'            => 'dining',
					'qm_summary'         => 'A test table.',
					'qm_instant_book'    => true,
					'qm_cuisine'         => 'Test food',
					'qm_menu_price'      => 50,
					'qm_covers'          => 10,
					'qm_min_party'       => 1,
					'qm_max_party'       => 8,
					'qm_slot_minutes'    => '30',
					'qm_seating_minutes' => 90,
					'qm_periods'         => array(
						array(
							'label' => 'Dinner',
							'days'  => array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ),
							'start' => '18:00',
							'end'   => '20:00',
						),
					),
					'qm_deposit_rule'    => 'per_guest',
					'qm_deposit_value'   => 10,
					'qm_extras'          => array(),
					'qm_cancellation'    => 'flexible',
				),
				$fields
			),
			$host,
			$status
		);
	}

	/** Update fields on an existing listing. */
	protected function set_fields( int $listing_id, array $fields ): void {
		FieldWriter::save( $listing_id, $fields );
		$this->flush_fields();
	}

	protected function load( int $listing_id ): Listing {
		$listing = Listing::find( $listing_id );
		$this->assertNotNull( $listing );
		return $listing;
	}

	/** A dinner seating some days ahead: 18:00 plus $index half hours. */
	protected function seating( int $days = 3, int $index = 0 ): string {
		return $this->day( $days ) . ' ' . gmdate( 'H:i', (int) strtotime( '2000-01-01 18:00:00 UTC' ) + $index * 30 * MINUTE_IN_SECONDS ) . ':00';
	}

	/* ---------------------------------------------------------------- Bookings */

	/** A stay quote through the service. */
	protected function stay_quote( int $listing_id, string $check_in, string $check_out, int $guests = 2, array $extras = array(), string $email = 'guest@example.com', int $user_id = 0, string $note = '', bool $consent = false ): int {
		$result = Services::service()->create_quote(
			array(
				'listing_id'        => $listing_id,
				'check_in'          => $check_in,
				'check_out'         => $check_out,
				'slot_start'        => '',
				'guests'            => $guests,
				'extras'            => $extras,
				'email'             => $email,
				'full_name'         => 'Test Guest',
				'note'              => $note,
				'marketing_consent' => $consent,
			),
			$user_id
		);
		return $result['booking_id'];
	}

	/** A table quote through the service. */
	protected function table_quote( int $listing_id, string $slot, int $party = 2, string $email = 'diner@example.com', int $user_id = 0, array $extras = array() ): int {
		$result = Services::service()->create_quote(
			array(
				'listing_id'        => $listing_id,
				'check_in'          => '',
				'check_out'         => '',
				'slot_start'        => $slot,
				'guests'            => $party,
				'extras'            => $extras,
				'email'             => $email,
				'full_name'         => 'Test Diner',
				'note'              => '',
				'marketing_consent' => false,
			),
			$user_id
		);
		return $result['booking_id'];
	}

	/** Hold a quote. */
	protected function hold( int $booking_id ): array {
		return Services::service()->hold( $booking_id, 'test' );
	}

	/** Quote and hold a stay. */
	protected function held_stay( int $listing_id, string $check_in, string $check_out, string $email = 'guest@example.com', int $user_id = 0 ): int {
		$id = $this->stay_quote( $listing_id, $check_in, $check_out, 2, array(), $email, $user_id );
		$this->hold( $id );
		return $id;
	}

	/** A paid stay: confirmed on an instant-book listing, requested otherwise. */
	protected function paid_stay( int $listing_id, string $check_in, string $check_out, string $email = 'guest@example.com', int $user_id = 0, int $order_id = 99 ): int {
		$id = $this->held_stay( $listing_id, $check_in, $check_out, $email, $user_id );
		Services::service()->on_deposit_paid( $id, $order_id );
		return $id;
	}

	/** A paid table. */
	protected function paid_table( int $listing_id, string $slot, int $party = 2, string $email = 'diner@example.com', int $order_id = 99 ): int {
		$id = $this->table_quote( $listing_id, $slot, $party, $email );
		$this->hold( $id );
		Services::service()->on_deposit_paid( $id, $order_id );
		return $id;
	}

	/** Make a hold look ten minutes old. */
	protected function expire_hold( int $booking_id ): void {
		global $wpdb;
		$past = '2020-01-01 00:00:00';
		$wpdb->update( Db::table( 'bookings' ), array( 'hold_expires_at' => $past ), array( 'id' => $booking_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s WHERE booking_id = %d AND expires_at IS NOT NULL', Db::table( 'night_claims' ), $past, $booking_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET expires_at = %s WHERE booking_id = %d AND expires_at IS NOT NULL', Db::table( 'table_claims' ), $past, $booking_id ) );
	}

	/** Move a booking's start, keeping its claims, to test time windows. */
	protected function move_start( int $booking_id, int $seconds_from_now ): void {
		Services::bookings()->update( $booking_id, array( 'slot_start' => Db::now( $seconds_from_now ) ) );
	}

	protected function booking( int $id ): array {
		$row = Services::bookings()->find( $id );
		$this->assertIsArray( $row );
		return $row;
	}

	protected function state( int $id ): string {
		return (string) $this->booking( $id )['state'];
	}

	/** Booking token for guest links. */
	protected function token( int $id ): string {
		return \Quillmoor\Booking\Security\BookingPolicy::token_for( $this->booking( $id ) );
	}

	/** Nights a booking owns. */
	protected function nights_of( int $booking_id ): array {
		global $wpdb;
		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT night FROM %i WHERE booking_id = %d ORDER BY night', Db::table( 'night_claims' ), $booking_id ) ) );
	}

	/** Seats booked in a slot. */
	protected function booked_seats( int $listing_id, string $slot ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT booked FROM %i WHERE listing_id = %d AND slot_start = %s', Db::table( 'table_slots' ), $listing_id, $slot ) );
	}

	/* ---------------------------------------------------------------- WooCommerce */

	/** The hidden deposit product. */
	protected function deposit_product(): int {
		$existing = (int) get_option( WooHooks::PRODUCT_OPTION );
		if ( $existing > 0 && wc_get_product( $existing ) ) {
			return $existing;
		}
		$product = new \WC_Product_Simple();
		$product->set_name( 'Deposit' );
		$product->set_virtual( true );
		$product->set_regular_price( '20' );
		$product->save();
		update_option( WooHooks::PRODUCT_OPTION, $product->get_id() );
		return $product->get_id();
	}

	/** An order for a booking's deposit, paid through the fake gateway when asked. */
	protected function order_for( int $booking_id, bool $pay = false ): \WC_Order {
		$quote = Services::bookings()->quote( (int) $this->booking( $booking_id )['quote_id'] );
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product_id( $this->deposit_product() );
		$item->set_quantity( 1 );
		$item->set_subtotal( (string) ( (int) $quote['deposit_cents'] / 100 ) );
		$item->set_total( (string) ( (int) $quote['deposit_cents'] / 100 ) );
		$item->add_meta_data( WooHooks::ORDER_META, $booking_id, true );
		$order->add_item( $item );
		$order->set_payment_method( FakeGateway::ID );
		$order->calculate_totals( false );
		$order->save();
		if ( $pay ) {
			$order->payment_complete( 'fake-' . $order->get_id() );
		}
		return $order;
	}

	/* ---------------------------------------------------------------- Requests */

	/** Dispatch a REST request. */
	protected function rest( string $method, string $route, array $params = array(), array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/qhs/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return rest_do_request( $request );
	}

	/**
	 * Run an admin-post handler of Admin\Actions and report where it went: a redirect, a wp_die
	 * status, and the notice it left for the next screen.
	 *
	 * @return array{redirect:string,die:int,notice:array<string,string>}
	 */
	protected function admin_post( string $handler, array $data ): array {
		$_POST    = $data;
		$_REQUEST = $data;
		$stop     = static function ( string $location ): string {
			throw new RedirectedException( $location );
		};
		add_filter( 'wp_redirect', $stop );
		$result = array(
			'redirect' => '',
			'die'      => 0,
			'notice'   => array(),
		);
		try {
			\Quillmoor\Booking\Admin\Actions::$handler();
		} catch ( RedirectedException $e ) {
			$result['redirect'] = $e->getMessage();
		} catch ( \WPDieException $e ) {
			$result['die'] = (int) $e->getCode();
		} finally {
			remove_filter( 'wp_redirect', $stop );
			$_POST    = array();
			$_REQUEST = array();
		}
		$notice           = get_transient( 'qhs_notice_' . get_current_user_id() );
		$result['notice'] = is_array( $notice ) ? $notice : array();
		delete_transient( 'qhs_notice_' . get_current_user_id() );
		return $result;
	}

	/** Error code of a REST response. */
	protected function code( WP_REST_Response $response ): string {
		$data = $response->get_data();
		return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
	}

	protected function user( string $role ): int {
		return (int) self::factory()->user->create( array( 'role' => $role ) );
	}

	/** A guest account linked to a verified customer row. */
	protected function customer_user( string $email ): int {
		$user = $this->user( Roles::CUSTOMER );
		$row  = Services::bookings()->upsert_customer( $email, 'Linked Person', $user );
		Services::bookings()->update_customer( (int) $row['id'], array( 'email_verified_at' => Db::now() ) );
		return $user;
	}

	protected function row_count( string $table, string $where = '1=1' ): int {
		return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Db::table( $table ) . ' WHERE ' . $where );
	}
}
