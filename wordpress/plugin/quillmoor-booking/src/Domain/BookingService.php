<?php
/**
 * Booking use cases.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Domain;

use Quillmoor\Booking\Alerts;
use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Mail\MailService;
use Quillmoor\Booking\Outbox;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates quotes, holds, payment, host decisions, arrival and changes for stays and tables.
 */
final class BookingService {

	/**
	 * Build the service.
	 *
	 * @param BookingRepository $bookings     Bookings.
	 * @param StateMachine      $machine      State machine.
	 * @param NightClaims       $nights       Night claims for stays.
	 * @param TableSlots        $tables       Seat capacity for dining.
	 * @param Availability      $availability Availability.
	 * @param Outbox            $outbox       Outbox.
	 * @param MailService       $mail         Mail.
	 */
	public function __construct(
		private BookingRepository $bookings,
		private StateMachine $machine,
		private NightClaims $nights,
		private TableSlots $tables,
		private Availability $availability,
		private Outbox $outbox,
		private MailService $mail
	) {
	}

	/**
	 * Price a request and open a booking in the quote state.
	 *
	 * @param array{listing_id:int,check_in:string,check_out:string,slot_start:string,guests:int,extras:string[],email:string,full_name:string,note:string,marketing_consent:bool} $input Validated request.
	 * @param int $user_id Signed-in user or 0.
	 * @return array{booking_id:int,price:array<string,mixed>}
	 * @throws BookingError When the listing or the request breaks a rule.
	 */
	public function create_quote( array $input, int $user_id ): array {
		$listing = Listing::find( $input['listing_id'] );
		if ( null === $listing || ! $listing->is_published() ) {
			throw new BookingError( 'not_found', 'That place is not available.', 404 );
		}

		$quote = $this->price_request( $listing, $input['check_in'], $input['check_out'], $input['slot_start'], $input['guests'], $input['extras'] );

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
				'listing_id'    => $listing->id,
				'listing_type'  => $listing->type->value,
				'check_in'      => $quote['check_in'],
				'check_out'     => $quote['check_out'],
				'slot_start'    => $quote['slot_start'],
				'guests'        => $input['guests'],
				'extras'        => (string) wp_json_encode( array_values( array_unique( $input['extras'] ) ) ),
				'line_items'    => (string) wp_json_encode( $quote['price']['line_items'] ),
				'total_cents'   => $quote['price']['total_cents'],
				'deposit_cents' => $quote['price']['deposit_cents'],
				'valid_until'   => Db::now( DAY_IN_SECONDS ),
				'created_at'    => Db::now(),
			)
		);

		$booking_id = $this->bookings->insert(
			array(
				'quote_id'      => $quote_id,
				'customer_id'   => (int) $customer['id'],
				'listing_id'    => $listing->id,
				'listing_type'  => $listing->type->value,
				'host_user_id'  => $listing->host_id,
				'location'      => $listing->location_slug(),
				'state'         => BookingState::Quote->value,
				'check_in'      => $quote['check_in'],
				'check_out'     => $quote['check_out'],
				'slot_start'    => $quote['slot_start'],
				'slot_end'      => $quote['slot_end'],
				'guests'        => $input['guests'],
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
			'price'      => $quote['price'],
		);
	}

	/**
	 * Validate dates against the listing and price them. Shared by quotes, previews and changes.
	 *
	 * @param Listing  $listing    Listing.
	 * @param string   $check_in   Arrival, stays only.
	 * @param string   $check_out  Departure, stays only.
	 * @param string   $slot_start Seating, dining only.
	 * @param int      $guests     Guests.
	 * @param string[] $extras     Extra keys.
	 * @return array{check_in:?string,check_out:?string,slot_start:string,slot_end:string,price:array{line_items:array<int,array{label:string,cents:int}>,total_cents:int,deposit_cents:int,nights:int}}
	 * @throws BookingError On any rule.
	 */
	public function price_request( Listing $listing, string $check_in, string $check_out, string $slot_start, int $guests, array $extras ): array {
		if ( ListingType::Stay === $listing->type ) {
			$this->availability->assert_stay_range( $listing, $check_in, $check_out );
			$price = Pricing::stay( $listing, $check_in, $check_out, $guests, $extras );
			return array(
				'check_in'   => $check_in,
				'check_out'  => $check_out,
				'slot_start' => $check_in . ' ' . $listing->check_in_time . ':00',
				'slot_end'   => $check_out . ' ' . $listing->check_out_time . ':00',
				'price'      => $price,
			);
		}

		if ( ! $this->availability->is_bookable_slot( $listing, $slot_start ) ) {
			throw new BookingError( 'slot_invalid', 'That time is not bookable.', 400 );
		}
		$price = Pricing::dining( $listing, $guests, $extras );
		return array(
			'check_in'   => null,
			'check_out'  => null,
			'slot_start' => $slot_start,
			'slot_end'   => gmdate( 'Y-m-d H:i:s', (int) strtotime( $slot_start . ' UTC' ) + $listing->seating_minutes * MINUTE_IN_SECONDS ),
			'price'      => $price,
		);
	}

	/**
	 * Confirm an address from its emailed token and apply any consent waiting on it.
	 *
	 * @param string $token Raw token.
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
	 * Hold the nights or seats of a quote for ten minutes.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function hold( int $booking_id, string $actor ): array {
		$booking = $this->require_booking( $booking_id );

		if ( BookingState::Quote->value !== $booking['state'] ) {
			throw new BookingError( 'not_quotable', 'This reservation is already on hold.' );
		}
		$quote = $this->bookings->quote( (int) $booking['quote_id'] );
		if ( null === $quote || (string) $quote['valid_until'] < Db::now() ) {
			throw new BookingError( 'quote_expired', 'This price has expired. Please start again.' );
		}
		$listing = Listing::find( (int) $booking['listing_id'] );
		if ( null === $listing || ! $listing->is_published() ) {
			throw new BookingError( 'not_found', 'That place is not available.', 404 );
		}
		// Dates are checked again: the quote may be a day old.
		$this->price_request( $listing, (string) $booking['check_in'], (string) $booking['check_out'], (string) $booking['slot_start'], (int) $booking['guests'], $this->quote_extras( $quote ) );
		if ( $this->bookings->active_holds( (int) $booking['customer_id'] ) >= Config::MAX_ACTIVE_HOLDS ) {
			throw new BookingError( 'too_many_holds', 'You already have reservations on hold.', 429 );
		}

		$expires = Db::now( Config::HOLD_MINUTES * MINUTE_IN_SECONDS );
		if ( ! $this->acquire( $listing, $booking, $expires ) ) {
			throw new BookingError(
				'unavailable',
				ListingType::Stay === $listing->type ? 'Those dates were just taken. Please choose other dates.' : 'That time just filled up. Please choose another time.'
			);
		}

		$this->bookings->update( $booking_id, array( 'hold_expires_at' => $expires ) );
		if ( ! $this->machine->transition( $booking_id, BookingState::Quote, BookingState::Hold, $actor ) ) {
			$this->release_claims( $booking );
			throw new BookingError( 'not_quotable', 'This reservation is already on hold.' );
		}

		return $this->require_booking( $booking_id );
	}

	/**
	 * Claim nights or seats for a booking.
	 *
	 * @param Listing             $listing Listing.
	 * @param array<string,mixed> $booking Booking row.
	 * @param string|null         $expires Hold expiry, or null when permanent.
	 */
	private function acquire( Listing $listing, array $booking, ?string $expires ): bool {
		if ( ListingType::Stay === $listing->type ) {
			$nights = Pricing::night_dates( (string) $booking['check_in'], Pricing::nights( (string) $booking['check_in'], (string) $booking['check_out'] ) );
			return $this->nights->acquire( $listing->id, $nights, (int) $booking['id'], $expires );
		}
		return $this->tables->acquire( $listing->id, (string) $booking['slot_start'], $listing->covers, (int) $booking['guests'], (int) $booking['id'], $expires );
	}

	/**
	 * Free whatever a booking holds.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	private function release_claims( array $booking ): void {
		if ( ListingType::Dining->value === $booking['listing_type'] ) {
			$this->tables->release( (int) $booking['id'] );
			return;
		}
		$this->nights->release( (int) $booking['id'] );
	}

	/**
	 * Return expired holds to the quote state and free their nights or seats.
	 *
	 * @return int Holds released.
	 */
	public function release_expired_holds(): int {
		$released = 0;
		foreach ( $this->bookings->expired_hold_ids() as $id ) {
			$booking = $this->bookings->find( $id );
			if ( null !== $booking && $this->machine->transition( $id, BookingState::Hold, BookingState::Quote, 'system', 'hold expired' ) ) {
				$this->release_claims( $booking );
				$this->bookings->update( $id, array( 'hold_expires_at' => null ) );
				++$released;
			}
		}
		return $released;
	}

	/**
	 * Decline requests the host left unanswered.
	 *
	 * @return int Requests declined.
	 */
	public function expire_requests(): int {
		$declined = 0;
		foreach ( $this->bookings->stale_request_ids( Config::REQUEST_HOURS ) as $id ) {
			try {
				$this->decline( $id, 'system', 'no answer within ' . Config::REQUEST_HOURS . ' hours' );
				++$declined;
			} catch ( BookingError $e ) {
				continue;
			}
		}
		return $declined;
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
			$this->late_payment( $booking );
			return;
		}
		if ( BookingState::Hold->value !== $booking['state'] ) {
			return;
		}
		if ( ! $this->make_permanent( $booking ) ) {
			// The hold lapsed and someone else took the nights or seats before the sweep ran.
			$this->machine->transition( $booking_id, BookingState::Hold, BookingState::Quote, 'system', 'hold lost before payment' );
			$this->release_claims( $booking );
			$fresh = $this->bookings->find( $booking_id );
			if ( null !== $fresh ) {
				$this->late_payment( $fresh );
			}
			return;
		}
		$this->mark_paid( $booking_id );
	}

	/**
	 * Make a booking's claims permanent.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	private function make_permanent( array $booking ): bool {
		if ( ListingType::Dining->value === $booking['listing_type'] ) {
			return $this->tables->make_permanent( (int) $booking['id'] );
		}
		return $this->nights->make_permanent( (int) $booking['id'], Pricing::nights( (string) $booking['check_in'], (string) $booking['check_out'] ) );
	}

	/**
	 * Move a paid hold forward: straight to confirmed for instant book, otherwise to the host.
	 *
	 * @param int $booking_id Booking id.
	 */
	private function mark_paid( int $booking_id ): void {
		if ( ! $this->machine->transition( $booking_id, BookingState::Hold, BookingState::DepositPaid, 'system', 'payment complete' ) ) {
			return;
		}
		$this->bookings->update( $booking_id, array( 'hold_expires_at' => null ) );

		$booking = $this->require_booking( $booking_id );
		$listing = Listing::find( (int) $booking['listing_id'] );
		if ( null !== $listing && $listing->instant_book ) {
			$this->machine->transition( $booking_id, BookingState::DepositPaid, BookingState::Confirmed, 'system', 'instant book' );
			$this->mail->send_confirmation( $this->require_booking( $booking_id ) );
			return;
		}

		$this->bookings->update( $booking_id, array( 'requested_at' => Db::now() ) );
		$this->machine->transition( $booking_id, BookingState::DepositPaid, BookingState::Requested, 'system', 'sent to the host' );
		$this->mail->send_request_received( $this->require_booking( $booking_id ) );
	}

	/**
	 * Payment arrived after the hold was released: claim again, or cancel and flag a refund.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	private function late_payment( array $booking ): void {
		$booking_id = (int) $booking['id'];
		$listing    = Listing::find( (int) $booking['listing_id'] );

		if ( null !== $listing && $this->acquire( $listing, $booking, null ) ) {
			if ( $this->machine->transition( $booking_id, BookingState::Quote, BookingState::Hold, 'system', 'reclaimed for late payment' ) ) {
				$this->mark_paid( $booking_id );
				return;
			}
			$this->release_claims( $booking );
		}

		$this->machine->transition( $booking_id, BookingState::Quote, BookingState::Cancelled, 'system', 'payment after hold expired' );
		$this->bookings->update( $booking_id, array( 'refund_status' => 'flagged' ) );
		Alerts::log( 'payment', 'Deposit for booking ' . $booking_id . ' arrived after its dates were lost. Refund needed.' );
	}

	/**
	 * Host accepts a request.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed>
	 * @throws BookingError When not waiting for an answer.
	 */
	public function confirm( int $booking_id, string $actor ): array {
		if ( ! $this->machine->transition( $booking_id, BookingState::Requested, BookingState::Confirmed, $actor, 'accepted by the host' ) ) {
			throw new BookingError( 'not_requested', 'This booking is not waiting for an answer.' );
		}
		$booking = $this->require_booking( $booking_id );
		$this->mail->send_confirmation( $booking );
		return $booking;
	}

	/**
	 * Host turns a request down. The nights or seats are freed and the deposit is refunded.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @param string $reason     Reason shown in the history.
	 * @return array<string,mixed>
	 * @throws BookingError When not waiting for an answer.
	 */
	public function decline( int $booking_id, string $actor, string $reason = '' ): array {
		$booking = $this->require_booking( $booking_id );
		if ( ! $this->machine->transition( $booking_id, BookingState::Requested, BookingState::Declined, $actor, '' === $reason ? 'declined by the host' : $reason ) ) {
			throw new BookingError( 'not_requested', 'This booking is not waiting for an answer.' );
		}
		$this->release_claims( $booking );
		$this->bookings->update( $booking_id, array( 'refund_status' => (int) $booking['order_id'] > 0 ? 'flagged' : 'none' ) );
		$fresh = $this->require_booking( $booking_id );
		$this->mail->send_declined( $fresh );
		return $fresh;
	}

	/**
	 * Mark the guest as arrived: checked in for a stay, seated for a table.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed>
	 * @throws BookingError Outside the arrival window or in the wrong state.
	 */
	public function arrive( int $booking_id, string $actor ): array {
		$booking = $this->require_booking( $booking_id );
		$start   = strtotime( $booking['slot_start'] . ' UTC' );
		if ( false === $start || time() < $start - Config::ARRIVAL_WINDOW_HOURS * HOUR_IN_SECONDS ) {
			throw new BookingError( 'too_early', 'Guests can be checked in from 36 hours before arrival.' );
		}
		if ( ! $this->machine->transition( $booking_id, BookingState::Confirmed, BookingState::Arrived, $actor ) ) {
			throw new BookingError( 'not_confirmed', 'Only confirmed bookings can be checked in.' );
		}
		$this->bookings->update( $booking_id, array( 'arrived_at' => Db::now() ) );
		return $this->require_booking( $booking_id );
	}

	/**
	 * Finish a stay or a meal.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed>
	 * @throws BookingError When the guest has not arrived.
	 */
	public function complete( int $booking_id, string $actor ): array {
		if ( ! $this->machine->transition( $booking_id, BookingState::Arrived, BookingState::Completed, $actor ) ) {
			throw new BookingError( 'not_arrived', 'Only guests who arrived can be completed.' );
		}
		$this->bookings->update( $booking_id, array( 'completed_at' => Db::now() ) );
		return $this->require_booking( $booking_id );
	}

	/**
	 * Record a guest who never came. The rest of the nights or the seats are freed.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed>
	 * @throws BookingError Before the start time or in the wrong state.
	 */
	public function no_show( int $booking_id, string $actor ): array {
		$booking = $this->require_booking( $booking_id );
		$start   = strtotime( $booking['slot_start'] . ' UTC' );
		if ( false === $start || time() < $start ) {
			throw new BookingError( 'too_early', 'A no-show can only be recorded after the start time.' );
		}
		if ( ! $this->machine->transition( $booking_id, BookingState::Confirmed, BookingState::NoShow, $actor ) ) {
			throw new BookingError( 'not_confirmed', 'Only confirmed bookings can be marked as a no-show.' );
		}
		$this->release_claims( $booking );
		return $this->require_booking( $booking_id );
	}

	/**
	 * Cancel a booking and free its nights or seats.
	 *
	 * A guest who cancels inside the listing's refund window keeps the reservation off but loses
	 * the deposit. A host or manager cancellation always refunds.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $actor      Actor label.
	 * @param bool   $by_guest   Whether the guest cancelled.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function cancel( int $booking_id, string $actor, bool $by_guest = true ): array {
		$booking = $this->require_booking( $booking_id );
		$state   = BookingState::from( (string) $booking['state'] );

		if ( ! $state->can_go_to( BookingState::Cancelled ) ) {
			throw new BookingError( 'not_cancellable', 'This booking can no longer be cancelled.' );
		}
		if ( ! $this->machine->transition( $booking_id, $state, BookingState::Cancelled, $actor, $by_guest ? 'cancelled by the guest' : 'cancelled by the host' ) ) {
			throw new BookingError( 'conflict', 'This booking just changed. Please refresh.' );
		}
		$this->release_claims( $booking );

		if ( (int) $booking['order_id'] > 0 ) {
			$refund = 'flagged';
			if ( $by_guest ) {
				$listing = Listing::find( (int) $booking['listing_id'] );
				$cutoff  = null === $listing ? 0 : $listing->refund_cutoff_hours();
				$start   = (int) strtotime( $booking['slot_start'] . ' UTC' );
				$refund  = $start - time() >= $cutoff * HOUR_IN_SECONDS ? 'flagged' : 'forfeited';
			}
			$this->bookings->update( $booking_id, array( 'refund_status' => $refund ) );
		}

		return $this->require_booking( $booking_id );
	}

	/**
	 * Move a paid booking to other dates or another time. The new nights or seats are taken first,
	 * so a failed change leaves the original booking untouched.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $check_in   New arrival, stays.
	 * @param string $check_out  New departure, stays.
	 * @param string $slot_start New seating, dining.
	 * @param string $actor      Actor label.
	 * @return array<string,mixed> Updated booking.
	 * @throws BookingError On any refusal.
	 */
	public function change_dates( int $booking_id, string $check_in, string $check_out, string $slot_start, string $actor ): array {
		$booking = $this->require_booking( $booking_id );
		$state   = BookingState::from( (string) $booking['state'] );
		if ( ! in_array( $state, array( BookingState::Requested, BookingState::Confirmed ), true ) ) {
			throw new BookingError( 'not_changeable', 'Only upcoming bookings can be changed.' );
		}
		$this->assert_before_cutoff( $booking );

		$listing = Listing::find( (int) $booking['listing_id'] );
		$quote   = $this->bookings->quote( (int) $booking['quote_id'] );
		if ( null === $listing || null === $quote ) {
			throw new BookingError( 'not_found', 'That place is not available.', 404 );
		}
		$next = $this->price_request( $listing, $check_in, $check_out, $slot_start, (int) $booking['guests'], $this->quote_extras( $quote ) );
		if ( $next['slot_start'] === $booking['slot_start'] && $next['check_out'] === $booking['check_out'] ) {
			throw new BookingError( 'same_dates', 'Those are already your dates.', 400 );
		}

		if ( ListingType::Stay === $listing->type ) {
			$old   = Pricing::night_dates( (string) $booking['check_in'], Pricing::nights( (string) $booking['check_in'], (string) $booking['check_out'] ) );
			$new   = Pricing::night_dates( (string) $next['check_in'], $next['price']['nights'] );
			$added = array_values( array_diff( $new, $old ) );
			if ( array() !== $added && ! $this->nights->acquire( $listing->id, $added, $booking_id, null ) ) {
				throw new BookingError( 'unavailable', 'Those dates are not free. Please choose other dates.' );
			}
			$this->nights->release_nights( $booking_id, array_values( array_diff( $old, $new ) ) );
		} else {
			if ( ! $this->tables->acquire( $listing->id, $next['slot_start'], $listing->covers, (int) $booking['guests'], $booking_id, null ) ) {
				throw new BookingError( 'unavailable', 'That time is full. Please choose another time.' );
			}
			$this->tables->release( $booking_id, (string) $booking['slot_start'] );
		}

		$this->bookings->update(
			$booking_id,
			array(
				'check_in'         => $next['check_in'],
				'check_out'        => $next['check_out'],
				'slot_start'       => $next['slot_start'],
				'slot_end'         => $next['slot_end'],
				'reminder_sent_at' => null,
				'staff_user_id'    => 0,
			)
		);
		// The deposit was paid on the old total and stays as paid. The balance follows the new total.
		$this->bookings->update_quote(
			(int) $quote['id'],
			array(
				'check_in'    => $next['check_in'],
				'check_out'   => $next['check_out'],
				'slot_start'  => $next['slot_start'],
				'line_items'  => (string) wp_json_encode( $next['price']['line_items'] ),
				'total_cents' => $next['price']['total_cents'],
			)
		);
		$this->touch_version( $booking_id );
		$this->bookings->log( $booking_id, $state->value, $actor, 'dates changed' );
		$this->outbox->record( 'booking.rescheduled', $booking_id );

		return $this->require_booking( $booking_id );
	}

	/**
	 * Apply a dispatch decision: a team member for the arrival. A stale version is ignored, so a
	 * late answer about old dates cannot assign anyone.
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
			Alerts::log( 'dispatch', 'Nobody free to welcome booking ' . $booking_id . ': ' . $reason );
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

		global $wpdb;
		// Conditional on the version, so a change made while this ran wins over the decision.
		$done = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET staff_user_id = %d WHERE id = %d AND version = %d AND state = %s',
				Db::table( 'bookings' ),
				(int) $users[0],
				$booking_id,
				$version,
				BookingState::Confirmed->value
			)
		);
		if ( 1 !== $done ) {
			return 'stale';
		}
		$this->bookings->log( $booking_id, BookingState::Confirmed->value, 'dispatch', substr( 'team member assigned: ' . $reason, 0, 190 ) );
		return 'assigned';
	}

	/**
	 * Add an internal note, never shown to the guest.
	 *
	 * @param int    $booking_id Booking id.
	 * @param int    $author_id  Author.
	 * @param string $body       Plain text.
	 * @throws BookingError When empty.
	 */
	public function add_note( int $booking_id, int $author_id, string $body ): int {
		$body = trim( $body );
		if ( '' === $body ) {
			throw new BookingError( 'empty_note', 'Write a note first.', 400 );
		}
		$this->require_booking( $booking_id );
		return $this->bookings->add_note( $booking_id, $author_id, mb_substr( $body, 0, 1000 ) );
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
				'SELECT * FROM %i WHERE state = %s AND reminder_sent_at IS NULL AND slot_start > %s AND slot_start <= %s LIMIT 50',
				Db::table( 'bookings' ),
				BookingState::Confirmed->value,
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
	 * Extras stored on a quote.
	 *
	 * @param array<string,mixed> $quote Quote row.
	 * @return string[]
	 */
	private function quote_extras( array $quote ): array {
		$extras = json_decode( (string) $quote['extras'], true );
		return is_array( $extras ) ? array_map( 'strval', $extras ) : array();
	}

	/**
	 * Reject date changes inside the cutoff.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 * @throws BookingError Inside the cutoff.
	 */
	private function assert_before_cutoff( array $booking ): void {
		$start = strtotime( $booking['slot_start'] . ' UTC' );
		if ( false === $start || $start - time() < Config::CHANGE_CUTOFF_HOURS * HOUR_IN_SECONDS ) {
			throw new BookingError( 'too_late', 'Changes close 24 hours before arrival.' );
		}
	}

	/**
	 * Bump the version so an in-flight dispatch decision for the old dates goes stale.
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
