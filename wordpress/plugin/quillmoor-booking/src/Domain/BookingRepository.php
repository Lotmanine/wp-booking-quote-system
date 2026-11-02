<?php
/**
 * Booking persistence.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes booking, quote and customer rows.
 */
final class BookingRepository {

	/**
	 * Find a booking.
	 *
	 * @param int $id Booking id.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'bookings' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a booking and return its id.
	 *
	 * @param array<string,mixed> $data Column values.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( Db::table( 'bookings' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update non-state columns.
	 *
	 * @param int                 $id   Booking id.
	 * @param array<string,mixed> $data Column values.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'bookings' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Find a quote.
	 *
	 * @param int $id Quote id.
	 * @return array<string,mixed>|null
	 */
	public function quote( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'quotes' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert a quote and return its id.
	 *
	 * @param array<string,mixed> $data Column values.
	 */
	public function insert_quote( array $data ): int {
		global $wpdb;
		$wpdb->insert( Db::table( 'quotes' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a customer.
	 *
	 * @param int $id Customer id.
	 * @return array<string,mixed>|null
	 */
	public function customer( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'customers' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Customer linked to a WordPress user.
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|null
	 */
	public function customer_by_user( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', Db::table( 'customers' ), $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Customer by email address.
	 *
	 * @param string $email Lowercase address.
	 * @return array<string,mixed>|null
	 */
	public function customer_by_email( string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', Db::table( 'customers' ), $email ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Create or refresh a customer row. Racing inserts converge on the unique email, and a
	 * verified row keeps its name because anyone can type any address into the wizard.
	 *
	 * @param string $email     Lowercase address.
	 * @param string $full_name Display name.
	 * @param int    $user_id   Linked user or 0.
	 * @return array<string,mixed>
	 */
	public function upsert_customer( string $email, string $full_name, int $user_id ): array {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (user_id, email, full_name, created_at) VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE full_name = IF(email_verified_at IS NULL, VALUES(full_name), full_name), user_id = IF(user_id = 0, VALUES(user_id), user_id)',
				Db::table( 'customers' ),
				$user_id,
				$email,
				$full_name,
				Db::now()
			)
		);
		return (array) $this->customer_by_email( $email );
	}

	/**
	 * Update a customer.
	 *
	 * @param int                 $id   Customer id.
	 * @param array<string,mixed> $data Column values.
	 */
	public function update_customer( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'customers' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Bookings of a customer, newest first.
	 *
	 * @param int $customer_id Customer id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_customer( int $customer_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC LIMIT 50', Db::table( 'bookings' ), $customer_id ),
			ARRAY_A
		);
	}

	/**
	 * Active jobs for a staff member.
	 *
	 * @param int $user_id Staff user id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_staff( int $user_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE staff_user_id = %d AND state IN (%s, %s) ORDER BY slot_start ASC LIMIT 100',
				Db::table( 'bookings' ),
				$user_id,
				BookingState::Assigned->value,
				BookingState::Completed->value
			),
			ARRAY_A
		);
	}

	/**
	 * Bookings with a slot inside a range.
	 *
	 * @param string $from Inclusive start.
	 * @param string $to   Exclusive end.
	 * @return array<int,array<string,mixed>>
	 */
	public function in_range( string $from, string $to ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slot_start >= %s AND slot_start < %s AND state NOT IN (%s, %s) ORDER BY slot_start ASC',
				Db::table( 'bookings' ),
				$from,
				$to,
				BookingState::Cancelled->value,
				BookingState::Quote->value
			),
			ARRAY_A
		);
	}

	/**
	 * Holds whose time ran out.
	 *
	 * @param int $limit Batch size.
	 * @return int[]
	 */
	public function expired_hold_ids( int $limit = 100 ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state = %s AND hold_expires_at < %s LIMIT %d',
				Db::table( 'bookings' ),
				BookingState::Hold->value,
				Db::now(),
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Active holds for a customer.
	 *
	 * @param int $customer_id Customer id.
	 */
	public function active_holds( int $customer_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE customer_id = %d AND state = %s AND hold_expires_at >= %s',
				Db::table( 'bookings' ),
				$customer_id,
				BookingState::Hold->value,
				Db::now()
			)
		);
	}

	/**
	 * History rows for a booking.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int,array<string,mixed>>
	 */
	public function history( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT from_state, to_state, actor, reason, created_at FROM %i WHERE booking_id = %d ORDER BY id ASC', Db::table( 'booking_history' ), $booking_id ),
			ARRAY_A
		);
	}
}
