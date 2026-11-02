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
 * Reads and writes booking, quote, customer and note rows.
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
	 * Update a quote, used when dates change.
	 *
	 * @param int                 $id   Quote id.
	 * @param array<string,mixed> $data Column values.
	 */
	public function update_quote( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'quotes' ), $data, array( 'id' => $id ) );
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
	 * verified row keeps its name because anyone can type any address into the form.
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
			$wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d AND state <> %s ORDER BY slot_start DESC, id DESC LIMIT 100', Db::table( 'bookings' ), $customer_id, BookingState::Quote->value ),
			ARRAY_A
		);
	}

	/**
	 * Bookings a host or staff member handles: listings they own, or bookings assigned to them.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $states  States to include, or every state when empty.
	 * @param int      $limit   Page size.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_host( int $user_id, array $states = array(), int $limit = 200 ): array {
		return $this->listing_query( '(b.host_user_id = %d OR b.staff_user_id = %d)', array( $user_id, $user_id ), $states, $limit, 0, 'b.slot_start ASC' )['rows'];
	}

	/**
	 * Bookings of one listing that hold nights or seats in a range, for calendars.
	 *
	 * @param int    $listing_id Listing id.
	 * @param string $from       First day.
	 * @param string $to         Last day.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_listing_between( int $listing_id, string $from, string $to ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, c.full_name, c.email FROM %i b JOIN %i c ON c.id = b.customer_id
				WHERE b.listing_id = %d AND b.state IN (%s, %s, %s, %s, %s, %s)
				AND b.slot_start <= %s AND COALESCE(b.slot_end, b.slot_start) >= %s
				ORDER BY b.slot_start ASC',
				Db::table( 'bookings' ),
				Db::table( 'customers' ),
				$listing_id,
				BookingState::Hold->value,
				BookingState::DepositPaid->value,
				BookingState::Requested->value,
				BookingState::Confirmed->value,
				BookingState::Arrived->value,
				BookingState::Completed->value,
				$to . ' 23:59:59',
				$from . ' 00:00:00'
			),
			ARRAY_A
		);
	}

	/**
	 * Bookings with a start inside a range, for every listing.
	 *
	 * @param string $from    Inclusive start.
	 * @param string $to      Exclusive end.
	 * @param int    $host_id Limit to one host, or 0 for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function in_range( string $from, string $to, int $host_id = 0 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT b.*, c.full_name, p.post_title AS listing_title FROM %i b JOIN %i c ON c.id = b.customer_id LEFT JOIN %i p ON p.ID = b.listing_id
				WHERE b.slot_start >= %s AND b.slot_start < %s AND b.state IN (%s, %s, %s, %s) ORDER BY b.slot_start ASC',
				Db::table( 'bookings' ),
				Db::table( 'customers' ),
				$wpdb->posts,
				$from,
				$to,
				BookingState::Requested->value,
				BookingState::Confirmed->value,
				BookingState::Arrived->value,
				BookingState::Completed->value
			),
			ARRAY_A
		);
		if ( $host_id < 1 ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => (int) $row['host_user_id'] === $host_id || (int) $row['staff_user_id'] === $host_id
			)
		);
	}

	/**
	 * Admin and host list, filtered. Newest first, or by arrival when a date range is given.
	 *
	 * @param array{state?:string,term?:string,host_id?:int,listing_id?:int,type?:string,from?:string,to?:string} $filters Filters.
	 * @param int                                                                                                  $limit   Page size.
	 * @param int                                                                                                  $offset  Rows to skip.
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public function search( array $filters, int $limit, int $offset ): array {
		global $wpdb;
		$where = array( 'b.state <> %s' );
		$args  = array( BookingState::Quote->value );
		$host  = (int) ( $filters['host_id'] ?? 0 );
		if ( $host > 0 ) {
			$where[] = '(b.host_user_id = %d OR b.staff_user_id = %d)';
			$args[]  = $host;
			$args[]  = $host;
		}
		if ( (int) ( $filters['listing_id'] ?? 0 ) > 0 ) {
			$where[] = 'b.listing_id = %d';
			$args[]  = (int) $filters['listing_id'];
		}
		if ( in_array( $filters['type'] ?? '', array( 'stay', 'dining' ), true ) ) {
			$where[] = 'b.listing_type = %s';
			$args[]  = (string) $filters['type'];
		}
		$order = 'b.id DESC';
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $filters['from'] ?? '' ) ) ) {
			$where[] = 'b.slot_start >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
			$order   = 'b.slot_start ASC';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $filters['to'] ?? '' ) ) ) {
			$where[] = 'b.slot_start <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
			$order   = 'b.slot_start ASC';
		}
		$term = trim( (string) ( $filters['term'] ?? '' ) );
		if ( 1 === preg_match( '/^(?:qm-?)?(\d+)$/i', $term, $match ) ) {
			$where[] = 'b.id = %d';
			$args[]  = (int) $match[1];
		} elseif ( '' !== $term ) {
			$like    = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = '(c.email LIKE %s OR c.full_name LIKE %s OR p.post_title LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$state  = (string) ( $filters['state'] ?? '' );
		$states = '' === $state ? array() : ( 'upcoming' === $state ? array( BookingState::Requested->value, BookingState::Confirmed->value, BookingState::DepositPaid->value ) : array( $state ) );
		return $this->listing_query( implode( ' AND ', $where ), $args, $states, $limit, $offset, $order );
	}

	/**
	 * Shared paged query joining the customer, quote and listing title.
	 *
	 * @param string           $where  Fixed SQL fragment with placeholders.
	 * @param array<int,mixed> $args   Placeholder values.
	 * @param string[]         $states States, or all when empty.
	 * @param int              $limit  Page size.
	 * @param int              $offset Rows to skip.
	 * @param string           $order  Fixed ORDER BY fragment.
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	private function listing_query( string $where, array $args, array $states, int $limit, int $offset, string $order ): array {
		global $wpdb;
		if ( array() !== $states ) {
			$where .= ' AND b.state IN (' . implode( ', ', array_fill( 0, count( $states ), '%s' ) ) . ')';
			$args   = array_merge( $args, array_values( $states ) );
		}
		$tables = array( Db::table( 'bookings' ), Db::table( 'customers' ), Db::table( 'quotes' ), $wpdb->posts );
		$from   = 'FROM %i b JOIN %i c ON c.id = b.customer_id LEFT JOIN %i q ON q.id = b.quote_id LEFT JOIN %i p ON p.ID = b.listing_id WHERE ' . $where;
		$order  = in_array( $order, array( 'b.id DESC', 'b.slot_start ASC' ), true ) ? $order : 'b.id DESC';

		// The WHERE clause is built from fixed fragments with placeholders, so the sniffs cannot count them.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", array_merge( $tables, $args ) ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, c.full_name, c.email, q.total_cents, q.deposit_cents, q.line_items, p.post_title AS listing_title, p.post_author AS listing_author {$from} ORDER BY {$order} LIMIT %d OFFSET %d",
				array_merge( $tables, $args, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Number of bookings per state.
	 *
	 * @param int $host_id Limit to one host, or 0 for all.
	 * @return array<string,int>
	 */
	public function state_counts( int $host_id = 0 ): array {
		global $wpdb;
		$rows = $host_id > 0
			? $wpdb->get_results(
				$wpdb->prepare( 'SELECT state, COUNT(*) AS total FROM %i WHERE host_user_id = %d OR staff_user_id = %d GROUP BY state', Db::table( 'bookings' ), $host_id, $host_id ),
				ARRAY_A
			)
			: $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS total FROM %i GROUP BY state', Db::table( 'bookings' ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['state'] ] = (int) $row['total'];
		}
		return $out;
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
	 * Requests left unanswered past the deadline.
	 *
	 * @param int $hours Response window.
	 * @return int[]
	 */
	public function stale_request_ids( int $hours ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state = %s AND requested_at < %s LIMIT 100',
				Db::table( 'bookings' ),
				BookingState::Requested->value,
				Db::now( -1 * $hours * HOUR_IN_SECONDS )
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

	/**
	 * Record a history line that is not a state change.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $state      Current state, written as both ends.
	 * @param string $actor      Actor label.
	 * @param string $reason     What happened.
	 */
	public function log( int $booking_id, string $state, string $actor, string $reason ): void {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'booking_history' ),
			array(
				'booking_id' => $booking_id,
				'from_state' => $state,
				'to_state'   => $state,
				'actor'      => substr( $actor, 0, 40 ),
				'reason'     => substr( $reason, 0, 190 ),
				'created_at' => Db::now(),
			)
		);
	}

	/**
	 * Internal notes on a booking, oldest first.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<int,array<string,mixed>>
	 */
	public function notes( int $booking_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, author_id, body, created_at FROM %i WHERE booking_id = %d ORDER BY id ASC', Db::table( 'booking_notes' ), $booking_id ),
			ARRAY_A
		);
	}

	/**
	 * Add an internal note.
	 *
	 * @param int    $booking_id Booking id.
	 * @param int    $author_id  Author.
	 * @param string $body       Plain text.
	 */
	public function add_note( int $booking_id, int $author_id, string $body ): int {
		global $wpdb;
		$wpdb->insert(
			Db::table( 'booking_notes' ),
			array(
				'booking_id' => $booking_id,
				'author_id'  => $author_id,
				'body'       => $body,
				'created_at' => Db::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deposits taken on bookings that are still live or finished, in cents.
	 *
	 * @param int $host_id Limit to one host, or 0 for all.
	 */
	public function deposits_cents( int $host_id = 0 ): int {
		global $wpdb;
		$states = array( BookingState::DepositPaid->value, BookingState::Requested->value, BookingState::Confirmed->value, BookingState::Arrived->value, BookingState::Completed->value, BookingState::NoShow->value );
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Ten values in one array: two tables, six states, the host twice.
			$wpdb->prepare(
				'SELECT COALESCE(SUM(q.deposit_cents), 0) FROM %i b JOIN %i q ON q.id = b.quote_id WHERE b.state IN (%s, %s, %s, %s, %s, %s) AND b.order_id > 0 AND ( %d = 0 OR b.host_user_id = %d )',
				array_merge( array( Db::table( 'bookings' ), Db::table( 'quotes' ) ), $states, array( $host_id, $host_id ) )
			)
		);
	}

	/**
	 * Every live booking holding nights or seats on a listing, used before a listing is deleted.
	 *
	 * @param int $listing_id Listing id.
	 */
	public function live_count_for_listing( int $listing_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE listing_id = %d AND state IN (%s, %s, %s, %s, %s)',
				Db::table( 'bookings' ),
				$listing_id,
				BookingState::Hold->value,
				BookingState::DepositPaid->value,
				BookingState::Requested->value,
				BookingState::Confirmed->value,
				BookingState::Arrived->value
			)
		);
	}
}
