<?php
/**
 * Shared test helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Services;
use WP_REST_Request;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/** @var array<string,string> Environment to restore. */
	private array $saved_env = array();

	/** @var array<int,array<string,mixed>> Outbound HTTP calls. */
	protected array $http = array();

	/** @var bool What the fake Turnstile answers. */
	protected bool $turnstile_ok = true;

	public function set_up(): void {
		parent::set_up();
		Services::reset();
		$this->http         = array();
		$this->turnstile_ok = true;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		foreach ( array( 'customers', 'quotes', 'bookings', 'slot_claims', 'booking_history', 'outbox', 'nonces', 'counters', 'mail_log', 'triage', 'alerts' ) as $table ) {
			$GLOBALS['wpdb']->query( 'DELETE FROM ' . Db::table( $table ) );
		}
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->saved_env as $name => $value ) {
			putenv( '' === $value ? $name : $name . '=' . $value );
		}
		$this->saved_env = array();
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tear_down();
	}

	protected function env( string $name, string $value ): void {
		if ( ! isset( $this->saved_env[ $name ] ) ) {
			$this->saved_env[ $name ] = (string) getenv( $name );
		}
		putenv( $name . '=' . $value );
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

	/** A bookable slot, offset so tests can pick several. */
	protected function slot( int $index = 0, string $area = 'north' ): string {
		$slots = array_merge( ...array_values( Services::availability()->open_slots( $area ) ) );
		return $slots[ $index ];
	}

	/** A slot beyond the 24 hour change cutoff. */
	protected function far_slot( int $index = 0 ): string {
		$slots = array_values(
			array_filter(
				array_merge( ...array_values( Services::availability()->open_slots( 'north' ) ) ),
				static fn( string $slot ): bool => strtotime( $slot . ' UTC' ) > time() + 3 * DAY_IN_SECONDS
			)
		);
		return $slots[ $index ];
	}

	/** Create a quote through the service. */
	protected function quote( string $email = 'a@example.com', int $user_id = 0, string $area = 'north', string $note = '', bool $consent = false ): int {
		$result = Services::service()->create_quote(
			array(
				'service'           => 'cleaning',
				'size'              => 'small',
				'area'              => $area,
				'extras'            => array(),
				'email'             => $email,
				'full_name'         => 'Test Person',
				'note'              => $note,
				'marketing_consent' => $consent,
			),
			$user_id
		);
		return $result['booking_id'];
	}

	/** A booking moved to confirmed. */
	protected function confirmed( string $email = 'a@example.com', int $user_id = 0, ?string $slot = null ): int {
		$id = $this->quote( $email, $user_id );
		Services::service()->hold( $id, $slot ?? $this->far_slot(), 'test' );
		Services::service()->on_deposit_paid( $id, 99 );
		return $id;
	}

	protected function assigned( int $staff_user_id, ?string $slot = null, string $email = 'a@example.com' ): int {
		$id = $this->confirmed( $email, 0, $slot );
		Services::bookings()->update(
			$id,
			array(
				'state'         => BookingState::Assigned->value,
				'staff_user_id' => $staff_user_id,
			)
		);
		return $id;
	}

	protected function state( int $id ): string {
		return (string) Services::bookings()->find( $id )['state'];
	}

	/** Dispatch a REST request. */
	protected function rest( string $method, string $route, array $params = array(), array $headers = array() ) {
		$request = new WP_REST_Request( $method, '/qhs/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}
		return rest_do_request( $request );
	}

	protected function user( string $role ): int {
		return (int) self::factory()->user->create( array( 'role' => $role ) );
	}

	/** A customer account linked to a customer row. */
	protected function customer_user( string $email ): int {
		$user = $this->user( 'qhs_customer' );
		$row  = Services::bookings()->upsert_customer( $email, 'Linked Person', $user );
		Services::bookings()->update_customer( (int) $row['id'], array( 'email_verified_at' => Db::now() ) );
		return $user;
	}

	protected function row_count( string $table, string $where = '1=1' ): int {
		return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Db::table( $table ) . ' WHERE ' . $where );
	}
}
