<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Crm\CrmPipeline;
use Quillmoor\Booking\Crm\CrmPort;
use Quillmoor\Booking\Db;
use Quillmoor\Booking\Security\Signer;
use Quillmoor\Booking\Services;

class CrmAndTriageTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	private function fake_crm(): void {
		$test = $this;
		add_filter(
			'quillmoor_crm_port',
			static fn() => new class( $test ) implements CrmPort {
				public function __construct( private CrmAndTriageTest $test ) {}
				public function sync_contact( string $email, string $full_name, bool $consent, array $add_tags, array $remove_tags ): bool {
					$this->test->calls[] = compact( 'email', 'consent', 'add_tags', 'remove_tags' );
					return true;
				}
				public function tags_for( string $email ): array {
					return array();
				}
			}
		);
		Services::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'quillmoor_crm_port' );
		parent::tear_down();
	}

	private int $listing = 0;

	/** A stay quote on the shared test listing. */
	private function quote( string $email = 'a@example.com', string $note = '', bool $consent = false ): int {
		if ( 0 === $this->listing ) {
			$this->listing = $this->stay_listing();
		}
		$offset = 10 + 3 * $this->row_count( 'bookings' );
		return $this->stay_quote( $this->listing, $this->day( $offset ), $this->day( $offset + 2 ), 2, array(), $email, 0, $note, $consent );
	}

	/** A confirmed stay, with its host signed in. */
	private function confirmed_as_host(): int {
		$host = $this->make_host();
		$id   = $this->paid_stay( $this->stay_listing( array(), $host ), $this->day( 10 ), $this->day( 12 ) );
		wp_set_current_user( $host );
		return $id;
	}

	private function review( int $id, string $do ): array {
		return $this->admin_post(
			'booking',
			array(
				'booking_id' => $id,
				'do'         => $do,
				'_wpnonce'   => wp_create_nonce( 'qhs_booking_' . $id ),
			)
		);
	}

	private function signed( string $route, array $payload_data ) {
		$payload = (string) wp_json_encode( $payload_data );
		$time    = (string) time();
		$nonce   = bin2hex( random_bytes( 16 ) );
		return $this->rest(
			'POST',
			$route,
			array( 'payload' => $payload ),
			array(
				'X-QHS-Key-Id'    => 'n8n',
				'X-QHS-Timestamp' => $time,
				'X-QHS-Nonce'     => $nonce,
				'X-QHS-Signature' => 'v1=' . Signer::mac( str_repeat( 'n', 40 ), $time, $nonce, $payload ),
			)
		);
	}

	public function test_an_event_sets_one_stage_and_clears_the_others(): void {
		$this->fake_crm();
		$id = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		$this->assertTrue( Services::pipeline()->apply( 'booking.confirmed', $id ) );

		$call = end( $this->calls );
		$this->assertContains( 'stage-confirmed', $call['add_tags'] );
		$this->assertContains( 'type-stay', $call['add_tags'] );
		$this->assertContains( 'stage-quote', $call['remove_tags'] );
		$this->assertContains( 'stage-requested', $call['remove_tags'] );
		$this->assertNotContains( 'stage-confirmed', $call['remove_tags'] );
	}

	/** @dataProvider stages */
	public function test_each_event_maps_to_one_stage( string $event, string $stage ): void {
		$this->fake_crm();
		$id = $this->paid_table( $this->dining_listing(), $this->seating( 3 ), 2 );

		Services::pipeline()->apply( $event, $id );

		$call = end( $this->calls );
		$this->assertSame( 1, count( array_filter( $call['add_tags'], static fn( string $tag ): bool => str_starts_with( $tag, 'stage-' ) ) ) );
		$this->assertContains( $stage, $call['add_tags'] );
		$this->assertContains( 'type-dining', $call['add_tags'] );
		$this->assertNotContains( $stage, $call['remove_tags'] );
	}

	public static function stages(): array {
		return array(
			'quote'        => array( 'booking.quote', 'stage-quote' ),
			'hold'         => array( 'booking.hold', 'stage-hold' ),
			'hold expired' => array( 'booking.hold_expired', 'stage-quote' ),
			'paid'         => array( 'booking.deposit_paid', 'stage-deposit-paid' ),
			'requested'    => array( 'booking.requested', 'stage-requested' ),
			'confirmed'    => array( 'booking.confirmed', 'stage-confirmed' ),
			'moved'        => array( 'booking.rescheduled', 'stage-confirmed' ),
			'arrived'      => array( 'booking.arrived', 'stage-arrived' ),
			'completed'    => array( 'booking.completed', 'stage-completed' ),
			'no-show'      => array( 'booking.no_show', 'stage-no-show' ),
			'declined'     => array( 'booking.declined', 'stage-declined' ),
			'cancelled'    => array( 'booking.cancelled', 'stage-cancelled' ),
		);
	}

	public function test_unknown_events_and_bookings_are_skipped(): void {
		$this->fake_crm();
		$id = $this->quote();

		$this->assertFalse( Services::pipeline()->apply( 'booking.note_received', $id ) );
		$this->assertFalse( Services::pipeline()->apply( 'booking.quote', 999999 ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_the_demo_tag_only_on_the_demo_site(): void {
		$this->fake_crm();
		$id = $this->quote();

		$this->env( 'QHS_DEMO_SITE', '1' );
		Services::pipeline()->apply( 'booking.quote', $id );
		$last = end( $this->calls );
		$this->assertContains( CrmPipeline::DEMO_TAG, $last['add_tags'] );

		$this->env( 'QHS_DEMO_SITE', '0' );
		Services::pipeline()->apply( 'booking.quote', $id );
		$last = end( $this->calls );
		$this->assertNotContains( CrmPipeline::DEMO_TAG, $last['add_tags'] );
	}

	public function test_the_tag_list_covers_every_stage(): void {
		foreach ( CrmPipeline::STAGES as $stage ) {
			$this->assertContains( $stage, CrmPipeline::all_tags() );
		}
		$this->assertContains( 'type-dining', CrmPipeline::all_tags() );
		$this->assertSame( CrmPipeline::all_tags(), array_values( array_unique( CrmPipeline::all_tags() ) ) );
	}

	public function test_no_consent_means_no_consent_tag(): void {
		$this->fake_crm();
		$id = $this->quote();

		Services::pipeline()->apply( 'booking.quote', $id );

		$call = end( $this->calls );
		$this->assertFalse( $call['consent'] );
		$this->assertNotContains( CrmPipeline::CONSENT_TAG, $call['add_tags'] );
		$this->assertContains( CrmPipeline::CONSENT_TAG, $call['remove_tags'] );
	}

	public function test_a_typed_consent_box_waits_for_the_address_owner(): void {
		$id       = $this->quote( 'someone@example.com', '', true );
		$customer = Services::bookings()->customer( (int) Services::bookings()->find( $id )['customer_id'] );

		$this->assertSame( 0, (int) $customer['marketing_consent'] );
		$this->assertSame( 1, (int) $customer['consent_pending'] );
	}

	public function test_confirming_the_email_applies_pending_consent(): void {
		$id    = $this->quote( 'someone@example.com', '', true );
		$cid   = (int) Services::bookings()->find( $id )['customer_id'];
		$token = 'known-token';
		Services::bookings()->update_customer(
			$cid,
			array(
				'verify_token_hash' => hash( 'sha256', $token ),
				'verify_expires_at' => Db::now( 600 ),
			)
		);

		$response = $this->rest( 'POST', '/verify-email', array( 'token' => $token ) );

		$customer = Services::bookings()->customer( $cid );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $customer['email_verified_at'] );
		$this->assertSame( 1, (int) $customer['marketing_consent'] );
		$this->assertSame( '2026-11-02', $customer['consent_text_version'] );
	}

	public function test_a_bad_verify_token_fails(): void {
		$this->assertSame( 400, $this->rest( 'POST', '/verify-email', array( 'token' => 'wrong' ) )->get_status() );
	}

	public function test_a_quote_cannot_rename_a_verified_customer(): void {
		$user = $this->customer_user( 'real@example.com' );
		$this->quote( 'real@example.com' );

		$this->assertSame( 'Linked Person', Services::bookings()->customer_by_user( $user )['full_name'] );
	}

	public function test_a_signed_in_customer_can_withdraw_consent(): void {
		$this->fake_crm();
		$user = $this->customer_user( 'real@example.com' );
		wp_set_current_user( $user );

		$this->rest( 'POST', '/consent', array( 'marketing_consent' => true ) );
		$this->assertSame( 1, (int) Services::bookings()->customer_by_user( $user )['marketing_consent'] );

		$this->rest( 'POST', '/consent', array( 'marketing_consent' => false ) );
		$call = end( $this->calls );
		$this->assertSame( 0, (int) Services::bookings()->customer_by_user( $user )['marketing_consent'] );
		$this->assertContains( CrmPipeline::CONSENT_TAG, $call['remove_tags'] );
	}

	public function test_crm_sync_callback_runs_once_per_event(): void {
		$this->fake_crm();
		$id    = $this->quote();
		$event = (int) $GLOBALS['wpdb']->get_var( 'SELECT id FROM ' . Db::table( 'outbox' ) . " WHERE booking_id = {$id} AND event = 'booking.quote'" );

		$first  = $this->signed( '/n8n/crm-sync', array( 'event_id' => $event ) );
		$second = $this->signed( '/n8n/crm-sync', array( 'event_id' => $event ) );

		$this->assertSame( 'synced', $first->get_data()['result'] );
		$this->assertSame( 'already_synced', $second->get_data()['result'] );
		$this->assertCount( 1, $this->calls );
	}

	public function test_a_note_queues_triage_for_n8n(): void {
		$id = $this->quote( 'a@example.com', 'We arrive late, around midnight' );

		$this->assertSame( 1, $this->row_count( 'outbox', "target = 'triage' AND booking_id = {$id}" ) );
		$payload = json_decode( (string) $GLOBALS['wpdb']->get_var( 'SELECT payload FROM ' . Db::table( 'outbox' ) . " WHERE target = 'triage' AND booking_id = {$id}" ), true );
		$this->assertSame(
			array(
				'booking_id' => $id,
				'note'       => 'We arrive late, around midnight',
			),
			$payload
		);
		$this->assertSame( 0, $this->row_count( 'outbox', "target = 'triage' AND booking_id = " . $this->quote( 'b@example.com' ) ) );
	}

	public function test_triage_has_a_daily_cap(): void {
		$this->env( 'QHS_TRIAGE_DAILY_CAP', '1' );

		$this->quote( 'a@example.com', 'First note' );
		$this->quote( 'b@example.com', 'Second note' );

		$this->assertSame( 1, $this->row_count( 'outbox', "target = 'triage'" ) );
	}

	public function test_ai_suggestion_never_changes_the_booking(): void {
		$id     = $this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$before = Services::bookings()->find( $id );

		$response = $this->signed(
			'/n8n/triage',
			array(
				'booking_id' => $id,
				'priority'   => 'urgent',
				'reason'     => 'Leak',
				'source'     => 'ai',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( $before, Services::bookings()->find( $id ) );
		$this->assertSame( 1, $this->row_count( 'triage', "status = 'pending'" ) );
	}

	public function test_unknown_priorities_are_refused(): void {
		$id = $this->quote();

		$response = $this->signed(
			'/n8n/triage',
			array(
				'booking_id' => $id,
				'priority'   => 'cancel the booking',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_only_the_host_accepting_changes_priority(): void {
		$id = $this->confirmed_as_host();
		$this->signed(
			'/n8n/triage',
			array(
				'booking_id' => $id,
				'priority'   => 'urgent',
				'reason'     => 'Late arrival',
				'source'     => 'ai',
			)
		);

		$this->assertSame( 'success', $this->review( $id, 'triage_accept' )['notice']['kind'] );

		$this->assertSame( 'urgent', Services::bookings()->find( $id )['priority'] );
		$this->assertSame( 1, $this->row_count( 'triage', "status = 'accepted' AND reviewed_by = " . get_current_user_id() ) );
	}

	public function test_dismissing_keeps_the_priority(): void {
		$id = $this->confirmed_as_host();
		$this->signed(
			'/n8n/triage',
			array(
				'booking_id' => $id,
				'priority'   => 'urgent',
				'reason'     => 'Late arrival',
				'source'     => 'ai',
			)
		);

		$this->review( $id, 'triage_dismiss' );

		$this->assertSame( 'normal', Services::bookings()->find( $id )['priority'] );
		$this->assertSame( 1, $this->row_count( 'triage', "status = 'dismissed'" ) );
	}

	public function test_another_host_cannot_review_a_suggestion(): void {
		$id = $this->confirmed_as_host();
		$this->signed(
			'/n8n/triage',
			array(
				'booking_id' => $id,
				'priority'   => 'urgent',
			)
		);
		wp_set_current_user( $this->make_host() );

		$this->assertSame( 404, $this->review( $id, 'triage_accept' )['die'] );
		$this->assertSame( 'normal', Services::bookings()->find( $id )['priority'] );
		$this->assertSame( 1, $this->row_count( 'triage', "status = 'pending' AND source = 'fallback'" ) );
	}

	public function test_real_fluentcrm_contact_gets_a_stage_tag(): void {
		$this->env( 'QHS_DEMO_SITE', '1' );
		$id = $this->quote( 'crm-real@example.com' );

		$this->assertTrue( Services::pipeline()->apply( 'booking.quote', $id ) );

		$tags = Services::crm()->tags_for( 'crm-real@example.com' );
		$this->assertContains( 'stage-quote', $tags );
		$this->assertContains( 'qhs-demo', $tags );
	}
}
