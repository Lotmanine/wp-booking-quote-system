<?php
/**
 * Booking use cases.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use InvalidArgumentException;
use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Catalog;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Outbox;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates quotes, holds, payment, changes and job progress.
 */
final class BookingService {

	public const JOB_STATUSES = array( 'scheduled', 'en_route', 'on_site' );

	/**
	 * Build the service.
	 *
	 * @param BookingRepository $bookings     Bookings.
	 * @param StateMachine      $machine      State machine.
	 * @param SlotClaims        $claims       Slot claims.
	 * @param Availability      $availability Availability.
	 * @param Outbox            $outbox       Outbox.
	 * @param MailService       $mail         Mail.
	 */
	public function __construct(
		private BookingRepository $bookings,
		private StateMachine $machine,
		private SlotClaims $claims,
		private Availability $availability,
		private Outbox $outbox,
		private MailService $mail
	) {
	}

	/**
	 * Price a request and open a booking in the quote state.
	 *
	 * @param array{service:string,size:string,area:string,extras:string[],email:string,full_name:string,note:string,marketing_consent:bool} $input Validated request.
	 * @param int                                                                                                                             $user_id Signed-in user or 0.
	 * @return array{booking_id:int,price:array<string,mixed>}
	 * @throws BookingError When an option is unknown.
	 */
	public function create_quote( array $input, int $user_id ): array {
		try {
			$price = Catalog::price( $input['service'], $input['size'], $input['area'], $input['extras'] );
		} catch ( InvalidArgumentException $e ) {
			throw new BookingError( 'invalid_option', 'That option is not available.', 400 );
		}

		$customer = $this->bookings->upsert_customer( strtolower( $input['email'] ), $input['full_name'], $user_id );

		// Anyone can type any address, so consent only counts once the address owner confirms it.
		// A signed-in customer has already proved ownership.
		$owns_address = $user_id > 0 && (int) $customer['user_id'] === $user_id && ! empty( $customer['email_verified_at'] );
		if ( $input['marketing_consent'] && ! (int) $customer['marketing_consent'] ) {
			if ( $owns_address ) {
				$this->record_consent( (int) $customer['id'] );
			} else {
				$this->bookings->update_customer( (int) $customer['id'], array( 'consent_pending' => 1 ) );
			}
		}

		$quote_id = $this->bookings->insert_quote(
			array(
				'customer_id'   => (int) $customer['id'],
				'service'       => $input['service'],
				'size'          => $input['size'],
				'area'          => $input['area'],
				'extras'        => (string) wp_json_encode( array_values( array_unique( $input['extras'] ) ) ),
				'line_items'    => (string) wp_json_encode( $price['line_items'] ),
				'total_cents'   => $price['total_cents'],
				'deposit_cents' => $price['deposit_cents'],
				'valid_until'   => Db::now( 7 * DAY_IN_SECONDS ),
				'created_at'    => Db::now(),
			)
		);

		$booking_id = $this->bookings->insert(
			array(
				'quote_id'      => $quote_id,
				'customer_id'   => (int) $customer['id'],
				'state'         => BookingState::Quote->value,
				'service'       => $input['service'],
				'area'          => $input['area'],
				'customer_note' => $input['note'],
				'access_key'    => bin2hex( random_bytes( 16 ) ),
				'created_at'    => Db::now(),
			)
		);

		$this->outbox->record( 'booking.quote', $booking_id );
		if ( '' !== $input['note'] && Services::counters()->take( 'ai:triage:daily', DAY_IN_SECONDS, Config::int( 'QHS_TRIAGE_DAILY_CAP', 50 ) ) ) {
			$this->outbox->record( 'booking.note_received', $booking_id, 'triage' );
		}

		$fresh = $this->bookings->customer( (int) $customer['id'] ) ?? $customer;
		if ( ( empty( $fresh['email_verified_at'] ) || (int) $fresh['consent_pending'] ) && (string) $fresh['verify_expires_at'] < Db::now() ) {
			$this->mail->send_verification( $fresh );
		}

		return array(
			'booking_id' => $booking_id,
			'price'      => $price,
		);
	}

	/**
	 * Confirm an address from its emailed token and apply any consent waiting on it.
	 *
	 * @param string $token Raw token.
	 * @return bool
	 */
	public function verify_email( string $token ): bool {
		global $wpdb;
		$customer = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE verify_token_hash = %s AND verify_expires_at >= %s',
				Db::table( 'customers' ),
				hash( 'sha256', $token ),
				Db::now()
			),
			ARRAY_A
		);
		if ( ! is_array( $customer ) ) {
			return false;
		}
		$this->bookings->update_customer(
			(int) $customer['id'],
			array(
				'email_verified_at' => Db::now(),
				'verify_token_hash' => null,
				'verify_expires_at' => null,
			)
		);
		if ( (int) $customer['consent_pending'] ) {
			$this->record_consent( (int) $customer['id'] );
		}
		return true;
	}

	/**
	 * Store consent with its wording version.
	 *
	 * @param int $customer_id Customer id.
	 */
	public function record_consent( int $customer_id ): void {
		$this->bookings->update_customer(
			$customer_id,
			array(
				'marketing_consent'    => 1,
				'consent_pending'      => 0,
				'consent_at'           => Db::now(),
				'consent_text_version' => Config::CONSENT_TEXT_VERSION,
			)
		);
	}

	/**
	 * Withdraw consent.
	 *
	 * @param int $customer_id Customer id.
	 */
	public function withdraw_consent( int $customer_id ): void {
		$this->bookings->update_customer(
			$customer_id,
			array(
				'marketing_consent' => 0,
				'consent_pending'   => 0,
				'consent_at'        => null,
			)
		);
	}

	/**
	 * Hold a slot for ten minutes.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $slot_start Slot start, UTC.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function hold( int $booking_id, string $slot_start, string $actor ): array {
		$booking = $this->require_booking( $booking_id );

		if ( BookingState::Quote->value !== $booking['state'] ) {
			throw new BookingError( 'not_quotable', 'This quote already has a slot.' );
		}
		$quote = $this->bookings->quote( (int) $booking['quote_id'] );
		if ( null === $quote || (string) $quote['valid_until'] < Db::now() ) {
			throw new BookingError( 'quote_expired', 'This quote has expired. Please start a new one.' );
		}
		if ( ! $this->availability->is_bookable_time( $slot_start ) ) {
			throw new BookingError( 'slot_invalid', 'That time is not bookable.', 400 );
		}
		if ( $this->bookings->active_holds( (int) $booking['customer_id'] ) >= Config::MAX_ACTIVE_HOLDS ) {
			throw new BookingError( 'too_many_holds', 'You already have slots on hold.', 429 );
		}

		$expires = Db::now( Config::HOLD_MINUTES * MINUTE_IN_SECONDS );
		if ( ! $this->claims->acquire( (string) $booking['area'], $slot_start, $booking_id, $expires ) ) {
			throw new BookingError( 'slot_taken', 'That slot was just taken. Please choose another.' );
		}

		$this->bookings->update(
			$booking_id,
			array(
				'slot_start'      => $slot_start,
				'hold_expires_at' => $expires,
			)
		);
		if ( ! $this->machine->transition( $booking_id, BookingState::Quote, BookingState::Hold, $actor ) ) {
			$this->claims->release_slot( $booking_id, $slot_start );
			throw new BookingError( 'not_quotable', 'This quote already has a slot.' );
		}

		return $this->require_booking( $booking_id );
	}

	/**
	 * Return expired holds to the quote state and free their slots.
	 *
	 * @return int Holds released.
	 */
	public function release_expired_holds(): int {
		$released = 0;
		foreach ( $this->bookings->expired_hold_ids() as $id ) {
			if ( $this->machine->transition( $id, BookingState::Hold, BookingState::Quote, 'system', 'hold expired' ) ) {
				$this->claims->release( $id );
				// slot_start stays, so a payment already in flight can try to reclaim the same slot.
				$this->bookings->update( $id, array( 'hold_expires_at' => null ) );
				++$released;
			}
		}
		return $released;
	}

	/**
	 * Record a paid deposit. Safe to call twice: only the first caller wins the transition.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $order_id   WooCommerce order id.
	 */
	public function on_deposit_paid( int $booking_id, int $order_id ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			return;
		}
		$this->bookings->update( $booking_id, array( 'order_id' => $order_id ) );

		if ( BookingState::Quote->value === $booking['state'] ) {
			$this->late_payment( $booking + array( 'lost_slot' => $booking['slot_start'] ) );
			return;
		}
		if ( BookingState::Hold->value !== $booking['state'] ) {
			return;
		}
		if ( ! $this->claims->make_permanent( $booking_id ) ) {
			// The hold lapsed and someone else took the slot before the sweep ran.
			$this->machine->transition( $booking_id, BookingState::Hold, BookingState::Quote, 'system', 'hold lost before payment' );
			$fresh = $this->bookings->find( $booking_id );
			if ( null !== $fresh ) {
				$this->late_payment( $fresh + array( 'lost_slot' => $booking['slot_start'] ) );
			}
			return;
		}
		$this->mark_paid( $booking_id );
	}

	/**
	 * Move a paid hold forward and confirm it.
	 *
	 * @param int $booking_id Booking id.
	 */
	private function mark_paid( int $booking_id ): void {
		if ( ! $this->machine->transition( $booking_id, BookingState::Hold, BookingState::DepositPaid, 'system', 'payment complete' ) ) {
			return;
		}
		$this->bookings->update( $booking_id, array( 'hold_expires_at' => null ) );
		$this->machine->transition( $booking_id, BookingState::DepositPaid, BookingState::Confirmed, 'system', 'slot secured' );

		$booking = $this->bookings->find( $booking_id );
		if ( null !== $booking ) {
			$this->mail->send_confirmation( $booking );
		}
	}

	/**
	 * Payment arrived after the hold was released: claim again, or cancel and flag a refund.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	private function late_payment( array $booking ): void {
		$booking_id = (int) $booking['id'];
		$slot       = (string) ( $booking['lost_slot'] ?? '' );

		if ( '' !== $slot && $this->claims->acquire( (string) $booking['area'], $slot, $booking_id, null ) ) {
			$this->bookings->update( $booking_id, array( 'slot_start' => $slot ) );
			if ( $this->machine->transition( $booking_id, BookingState::Quote, BookingState::Hold, 'system', 'reclaimed for late payment' ) ) {
				$this->mark_paid( $booking_id );
				return;
			}
		}

		$this->machine->transition( $booking_id, BookingState::Quote, BookingState::Cancelled, 'system', 'payment after hold expired' );
		$this->bookings->update( $booking_id, array( 'refund_status' => 'flagged' ) );
		Alerts::log( 'payment', 'Deposit for booking ' . $booking_id . ' arrived after its slot was lost. Refund needed.' );
	}

	/**
	 * Move a confirmed booking to a new slot.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $slot_start New slot, UTC.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function reschedule( int $booking_id, string $slot_start, string $actor ): array {
		$booking = $this->require_booking( $booking_id );
		$state   = BookingState::from( (string) $booking['state'] );

		if ( ! in_array( $state, array( BookingState::Confirmed, BookingState::Assigned ), true ) ) {
			throw new BookingError( 'not_reschedulable', 'Only confirmed bookings can be moved.' );
		}
		$this->assert_before_cutoff( $booking );
		if ( ! $this->availability->is_bookable_time( $slot_start ) ) {
			throw new BookingError( 'slot_invalid', 'That time is not bookable.', 400 );
		}

		$old_slot = (string) $booking['slot_start'];
		if ( $old_slot === $slot_start ) {
			throw new BookingError( 'slot_same', 'That is already your slot.', 400 );
		}
		if ( ! $this->claims->acquire( (string) $booking['area'], $slot_start, $booking_id, null ) ) {
			throw new BookingError( 'slot_taken', 'That slot was just taken. Please choose another.' );
		}
		$this->claims->release_slot( $booking_id, $old_slot );
		$this->bookings->update(
			$booking_id,
			array(
				'slot_start'       => $slot_start,
				'reminder_sent_at' => null,
				'staff_user_id'    => 0,
				'job_status'       => 'scheduled',
			)
		);

		if ( BookingState::Assigned === $state ) {
			$this->machine->transition( $booking_id, BookingState::Assigned, BookingState::Confirmed, $actor, 'rescheduled' );
		} else {
			$this->touch_version( $booking_id );
		}
		$this->outbox->record( 'booking.rescheduled', $booking_id );

		return $this->require_booking( $booking_id );
	}

	/**
	 * Cancel a booking and free its slot.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @param bool   $enforce_cutoff Whether the customer cutoff applies.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function cancel( int $booking_id, string $actor, bool $enforce_cutoff = true ): array {
		$booking = $this->require_booking( $booking_id );
		$state   = BookingState::from( (string) $booking['state'] );

		if ( ! $state->can_go_to( BookingState::Cancelled ) ) {
			throw new BookingError( 'not_cancellable', 'This booking can no longer be cancelled.' );
		}
		if ( $enforce_cutoff && in_array( $state, array( BookingState::Confirmed, BookingState::Assigned ), true ) ) {
			$this->assert_before_cutoff( $booking );
		}
		if ( ! $this->machine->transition( $booking_id, $state, BookingState::Cancelled, $actor ) ) {
			throw new BookingError( 'conflict', 'This booking just changed. Please refresh.' );
		}
		$this->claims->release( $booking_id );

		if ( (int) $booking['order_id'] > 0 ) {
			$this->bookings->update( $booking_id, array( 'refund_status' => 'flagged' ) );
		}

		return $this->require_booking( $booking_id );
	}

	/**
	 * Apply a dispatch decision. A stale version is ignored, so a late answer about an old slot
	 * cannot assign anyone.
	 *
	 * @param int    $booking_id Booking id.
	 * @param int    $version    Version the decision was made for.
	 * @param string $outcome    Either "assigned" or "unassigned".
	 * @param string $staff_ref  Roster reference.
	 * @param string $reason     Explanation.
	 * @return string Result code.
	 */
	public function apply_dispatch_decision( int $booking_id, int $version, string $outcome, string $staff_ref, string $reason ): string {
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			return 'not_found';
		}
		if ( (int) $booking['version'] !== $version || BookingState::Confirmed->value !== $booking['state'] ) {
			return 'stale';
		}
		if ( 'assigned' !== $outcome ) {
			Alerts::log( 'dispatch', 'No staff available for booking ' . $booking_id . ': ' . $reason );
			return 'unassigned';
		}

		$users = get_users(
			array(
				'meta_key'   => 'qhs_staff_ref', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $staff_ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		if ( array() === $users ) {
			Alerts::log( 'dispatch', 'Unknown staff reference for booking ' . $booking_id . '.' );
			return 'unknown_staff';
		}

		$this->bookings->update( $booking_id, array( 'staff_user_id' => (int) $users[0] ) );
		$done = $this->machine->transition( $booking_id, BookingState::Confirmed, BookingState::Assigned, 'dispatch', substr( $reason, 0, 190 ) );
		return $done ? 'assigned' : 'stale';
	}

	/**
	 * Update job progress or finish the job.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $status     Status, or "completed".
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function update_job( int $booking_id, string $status, string $actor ): array {
		$booking = $this->require_booking( $booking_id );
		if ( BookingState::Assigned->value !== $booking['state'] ) {
			throw new BookingError( 'not_assigned', 'This job is not active.' );
		}

		if ( 'completed' === $status ) {
			if ( ! $this->machine->transition( $booking_id, BookingState::Assigned, BookingState::Completed, $actor ) ) {
				throw new BookingError( 'conflict', 'This job just changed. Please refresh.' );
			}
			$this->bookings->update(
				$booking_id,
				array(
					'completed_at' => Db::now(),
					'job_status'   => 'done',
				)
			);
		} elseif ( in_array( $status, self::JOB_STATUSES, true ) ) {
			$this->bookings->update( $booking_id, array( 'job_status' => $status ) );
		} else {
			throw new BookingError( 'invalid_status', 'Unknown job status.', 400 );
		}

		return $this->require_booking( $booking_id );
	}

	/**
	 * Send due reminders.
	 *
	 * @param int $hours_ahead Look-ahead window.
	 * @return array{due:int,sent:int,skipped:int}
	 */
	public function run_reminders( int $hours_ahead ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE state IN (%s, %s) AND reminder_sent_at IS NULL AND slot_start > %s AND slot_start <= %s LIMIT 50',
				Db::table( 'bookings' ),
				BookingState::Confirmed->value,
				BookingState::Assigned->value,
				Db::now(),
				Db::now( $hours_ahead * HOUR_IN_SECONDS )
			),
			ARRAY_A
		);
		return $this->run_batch( $rows, 'reminder_sent_at', 'reminder' );
	}

	/**
	 * Send due follow-ups after completion.
	 *
	 * @param int $hours_after Delay after completion.
	 * @return array{due:int,sent:int,skipped:int}
	 */
	public function run_followups( int $hours_after ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE state = %s AND followup_sent_at IS NULL AND completed_at <= %s LIMIT 50',
				Db::table( 'bookings' ),
				BookingState::Completed->value,
				Db::now( -1 * $hours_after * HOUR_IN_SECONDS )
			),
			ARRAY_A
		);
		return $this->run_batch( $rows, 'followup_sent_at', 'followup' );
	}

	/**
	 * Claim and send one batch. The claim stops a repeat run from sending twice.
	 *
	 * @param array<int,array<string,mixed>> $rows   Bookings.
	 * @param string                         $column Sent-at column.
	 * @param string                         $kind   Mail kind.
	 * @return array{due:int,sent:int,skipped:int}
	 */
	private function run_batch( array $rows, string $column, string $kind ): array {
		global $wpdb;
		$sent    = 0;
		$skipped = 0;
		foreach ( $rows as $row ) {
			$claimed = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET %i = %s WHERE id = %d AND %i IS NULL',
					Db::table( 'bookings' ),
					$column,
					Db::now(),
					(int) $row['id'],
					$column
				)
			);
			if ( 1 !== $claimed ) {
				continue;
			}
			$status = 'reminder' === $kind ? $this->mail->send_reminder( $row ) : $this->mail->send_followup( $row );
			if ( MailService::delivered( $status ) ) {
				++$sent;
				continue;
			}
			++$skipped;
			if ( 'blocked_cap' === $status ) {
				// Over the cap today: leave it due so the next run can try.
				$this->bookings->update( (int) $row['id'], array( $column => null ) );
			}
		}
		return array(
			'due'     => count( $rows ),
			'sent'    => $sent,
			'skipped' => $skipped,
		);
	}

	/**
	 * Reject changes inside the cutoff.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @throws BookingError Inside the cutoff.
	 */
	private function assert_before_cutoff( array $booking ): void {
		$slot = strtotime( $booking['slot_start'] . ' UTC' );
		if ( false === $slot || $slot - time() < Config::CHANGE_CUTOFF_HOURS * HOUR_IN_SECONDS ) {
			throw new BookingError( 'too_late', 'Changes close 24 hours before the slot.' );
		}
	}

	/**
	 * Bump the version so an in-flight dispatch decision for the old slot goes stale.
	 *
	 * @param int $booking_id Booking id.
	 */
	private function touch_version( int $booking_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET version = version + 1 WHERE id = %d', Db::table( 'bookings' ), $booking_id ) );
	}

	/**
	 * Load a booking or fail.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string,mixed>
	 * @throws BookingError When missing.
	 */
	private function require_booking( int $booking_id ): array {
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			throw new BookingError( 'not_found', 'Booking not found.', 404 );
		}
		return $booking;
	}
}
