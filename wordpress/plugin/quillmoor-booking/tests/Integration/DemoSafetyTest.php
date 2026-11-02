<?php
namespace Quillmoor\Booking\Tests\Integration;

use Quillmoor\Booking\Demo\Banner;
use Quillmoor\Booking\Demo\DemoGuard;
use Quillmoor\Booking\Demo\DemoReset;
use Quillmoor\Booking\Demo\Roles;
use Quillmoor\Booking\Demo\SeedData;
use Quillmoor\Booking\Demo\Seeder;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\ListingType;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Security\BookingPolicy;
use Quillmoor\Booking\Services;
use RuntimeException;

class DemoSafetyTest extends TestCase {

	private function demo_user( string $role ): int {
		$user = $this->user( $role );
		update_user_meta( $user, DemoGuard::META, '1' );
		return $user;
	}

	private function allow_reset(): void {
		$this->env( 'QHS_DEMO_SITE', '1' );
		$this->env( 'QHS_DEMO_HOST', 'example.org' );
		$this->env( 'QHS_DEMO_PASSWORD', 'test-password' );
		$this->unset_env( 'QHS_DISPATCH_INTERNAL_URL' );
	}

	/**
	 * Register the demo photos as attachments without their files, so a reset skips the slow
	 * image import. One test below runs the real import.
	 */
	private function fake_photos(): void {
		foreach ( Seeder::credits() as $credit ) {
			$file = sanitize_file_name( (string) $credit['file'] );
			$id   = (int) self::factory()->attachment->create(
				array(
					'post_title'     => $file,
					'post_mime_type' => 'image/webp',
				)
			);
			update_post_meta( $id, Seeder::PHOTO_META, $file );
		}
	}

	/** @return array<string,Listing> Seeded listings keyed by seed slug. */
	private function seeded(): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => PostTypes::LISTING,
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'meta_key'       => Seeder::SEED_META,
			)
		) as $post ) {
			$out[ (string) get_post_meta( $post->ID, Seeder::SEED_META, true ) ] = Listing::from_post( $post );
		}
		return $out;
	}

	public function test_reset_refuses_without_the_flag(): void {
		$this->env( 'QHS_DEMO_SITE', '0' );
		$this->env( 'QHS_DEMO_HOST', 'example.org' );
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		try {
			DemoReset::run();
			$this->fail( 'Reset should refuse.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Refusing', $e->getMessage() );
		}
		$this->assertSame( 'quote', $this->state( $id ) );
	}

	public function test_reset_refuses_on_another_host(): void {
		$this->env( 'QHS_DEMO_SITE', '1' );
		$this->env( 'QHS_DEMO_HOST', 'booking.oldev.site' );

		$this->assertFalse( DemoReset::is_allowed() );
		$this->expectException( RuntimeException::class );
		DemoReset::run();
	}

	public function test_scheduled_reset_does_nothing_when_not_allowed(): void {
		$this->env( 'QHS_DEMO_SITE', '0' );
		$id = $this->stay_quote( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );

		DemoReset::run_scheduled();

		$this->assertSame( 'quote', $this->state( $id ) );
		$this->assertFalse( Seeder::is_seeded() );
	}

	public function test_reset_wipes_activity_and_seeds_the_fourteen_places(): void {
		$this->allow_reset();
		$this->fake_photos();
		$this->paid_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$this->paid_table( $this->dining_listing(), $this->seating( 3 ), 2 );
		$admin = $this->user( 'administrator' );

		DemoReset::run();

		$listings = $this->seeded();
		$this->assertCount( 14, $listings );
		$this->assertSame( array_keys( SeedData::listings() ), array_values( array_intersect( array_keys( SeedData::listings() ), array_keys( $listings ) ) ) );
		$types = array_count_values( array_map( static fn( Listing $l ): string => $l->type->value, $listings ) );
		$this->assertSame( 9, $types['stay'] );
		$this->assertSame( 5, $types['dining'] );
		foreach ( $listings as $slug => $listing ) {
			$this->assertTrue( $listing->is_published(), $slug );
			$this->assertGreaterThan( 0, $listing->from_cents(), $slug );
			$this->assertNotNull( $listing->location(), $slug );
			$this->assertNotNull( $listing->point(), $slug );
			$this->assertNotSame( array(), $listing->categories(), $slug );
			$this->assertTrue( user_can( $listing->host_id, BookingPolicy::CAP_HOST ), $slug );
			$this->assertSame( Seeder::type_of( $slug ), $listing->type, $slug );
			if ( ListingType::Dining === $listing->type ) {
				$this->assertNotSame( array(), $listing->periods, $slug );
			}
		}
		// Only the sample activity is left: no test booking survived.
		$this->assertSame( 4, $this->row_count( 'bookings' ) );
		$this->assertSame( 0, $this->row_count( 'bookings', "listing_id NOT IN (SELECT post_id FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '" . Seeder::SEED_META . "')" ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $admin ) );
		$this->assertTrue( Seeder::is_seeded() );
	}

	public function test_reset_seeds_hosts_and_terms(): void {
		$this->allow_reset();
		$this->fake_photos();

		DemoReset::run();

		foreach ( SeedData::hosts() as $login => $host ) {
			$user = get_user_by( 'login', $login );
			$this->assertInstanceOf( \WP_User::class, $user, $login );
			$this->assertContains( Roles::HOST, $user->roles, $login );
			$this->assertTrue( DemoGuard::is_demo_user( $user->ID ), $login );
			$this->assertSame( $host['name'], $user->display_name );
		}
		$this->assertTrue( wp_check_password( 'test-password', get_user_by( 'login', DemoReset::HOST_LOGIN )->user_pass ) );
		$this->assertFalse( wp_check_password( 'test-password', get_user_by( 'login', 'host-mara' )->user_pass ) );
		foreach ( SeedData::locations() as $slug => $location ) {
			$term = get_term_by( 'slug', $slug, PostTypes::LOCATION );
			$this->assertInstanceOf( \WP_Term::class, $term, $slug );
			$this->assertSame( $location['zone'], get_term_meta( $term->term_id, 'qm_zone', true ) );
		}
		$this->assertCount( count( SeedData::categories() ), get_terms( array( 'taxonomy' => PostTypes::CATEGORY, 'hide_empty' => false ) ) );
		$this->assertCount( count( SeedData::amenities() ), get_terms( array( 'taxonomy' => PostTypes::AMENITY, 'hide_empty' => false ) ) );
	}

	public function test_reset_adds_sample_activity_with_claims(): void {
		$this->allow_reset();
		$this->fake_photos();

		DemoReset::run();

		$listings = $this->seeded();
		$by_state = array();
		foreach ( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . \Quillmoor\Booking\Db::table( 'bookings' ), ARRAY_A ) as $row ) {
			$by_state[ $row['listing_id'] ] = $row['state'];
		}
		$this->assertSame( 'confirmed', $by_state[ $listings['fern-hollow-cabin']->id ] );
		$this->assertSame( 'requested', $by_state[ $listings['boathouse-on-the-pond']->id ] );
		$this->assertSame( 'completed', $by_state[ $listings['stone-cottage-in-the-hills']->id ] );
		$this->assertSame( 'confirmed', $by_state[ $listings['the-garden-table']->id ] );

		$fern = $listings['fern-hollow-cabin']->id;
		$this->assertSame( 3, $this->row_count( 'night_claims', "listing_id = {$fern} AND kind = 'booking'" ) );
		$this->assertSame( 2, $this->row_count( 'night_claims', "listing_id = {$fern} AND kind = 'block'" ) );
		$this->assertSame( 3, $this->row_count( 'night_claims', 'listing_id = ' . $listings['boathouse-on-the-pond']->id ) );
		$this->assertSame( 4, (int) $GLOBALS['wpdb']->get_var( 'SELECT SUM(booked) FROM ' . \Quillmoor\Booking\Db::table( 'table_slots' ) . ' WHERE listing_id = ' . $listings['the-garden-table']->id ) );
		$this->assertSame( 0, $this->row_count( 'bookings', 'order_id > 0' ) );
		$this->assertSame( 4, $this->row_count( 'booking_history', "actor = 'seed'" ) );
	}

	public function test_reset_restores_edited_places_and_removes_added_ones(): void {
		$this->allow_reset();
		$this->fake_photos();
		DemoReset::run();
		$listings = $this->seeded();
		$fern     = $listings['fern-hollow-cabin'];
		wp_update_post(
			array(
				'ID'         => $fern->id,
				'post_title' => 'Defaced',
			)
		);
		$this->set_fields( $fern->id, array( 'qm_nightly_price' => 1 ) );
		$added = $this->stay_listing( array(), $fern->host_id );

		DemoReset::run();

		$this->flush_fields();
		$restored = Listing::find( $fern->id );
		$this->assertSame( $fern->title, $restored->title );
		$this->assertSame( $fern->nightly_cents, $restored->nightly_cents );
		$this->assertNull( get_post( $added ) );
		$this->assertCount( 14, $this->seeded() );
	}

	public function test_reset_imports_the_photos_once(): void {
		$this->allow_reset();
		$credits = Seeder::credits();
		$this->assertNotSame( array(), $credits );

		DemoReset::run();

		$photos = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => Seeder::PHOTO_META,
			)
		);
		$this->assertCount( count( $credits ), $photos );
		$fern = $this->seeded()['fern-hollow-cabin'];
		$this->assertNotSame( array(), $fern->photos );
		$list = $fern->photo_list();
		$this->assertNotSame( '', $list[0]['alt'] );
		$this->assertStringContainsString( 'Unsplash', (string) get_post_field( 'post_excerpt', $fern->photos[0] ) );

		Seeder::run( false );

		$this->assertCount(
			count( $credits ),
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'meta_key'       => Seeder::PHOTO_META,
				)
			)
		);
	}

	public function test_reset_restores_accounts_and_passwords(): void {
		$this->allow_reset();
		$this->fake_photos();
		DemoReset::run();
		foreach ( array( DemoReset::CUSTOMER_LOGIN, DemoReset::HOST_LOGIN, DemoReset::MANAGER_LOGIN ) as $login ) {
			$user = get_user_by( 'login', $login );
			$this->assertInstanceOf( \WP_User::class, $user, $login );
			$this->assertTrue( DemoGuard::is_demo_user( $user->ID ), $login );
		}
		$customer = get_user_by( 'login', DemoReset::CUSTOMER_LOGIN );
		wp_set_password( 'changed', $customer->ID );

		DemoReset::run();

		$this->assertTrue( wp_check_password( 'test-password', get_user_by( 'login', DemoReset::CUSTOMER_LOGIN )->user_pass ) );
		$this->assertContains( Roles::MANAGER, get_user_by( 'login', DemoReset::MANAGER_LOGIN )->roles );
		$this->assertNotNull( Services::bookings()->customer_by_user( $customer->ID ) );
	}

	public function test_reset_removes_the_old_staff_login(): void {
		$this->allow_reset();
		$this->fake_photos();
		self::factory()->user->create(
			array(
				'user_login' => 'demo-staff',
				'role'       => Roles::STAFF,
			)
		);

		DemoReset::run();

		$this->assertFalse( get_user_by( 'login', 'demo-staff' ) );
		$this->assertSame( 'rowan', get_user_meta( get_user_by( 'login', 'staff-rowan' )->ID, 'qhs_staff_ref', true ) );
	}

	public function test_reset_keeps_the_email_counter(): void {
		$this->allow_reset();
		$this->fake_photos();
		Services::counters()->take( 'mail:daily', DAY_IN_SECONDS, 50 );

		DemoReset::run();

		$this->assertSame( 1, Services::mail()->used_today() );
	}

	public function test_reset_deletes_booking_orders_only(): void {
		$this->allow_reset();
		$this->fake_photos();
		$id      = $this->held_stay( $this->stay_listing(), $this->day( 10 ), $this->day( 12 ) );
		$deposit = $this->order_for( $id, true );
		$other   = wc_create_order();
		$other->save();

		DemoReset::run();

		$this->assertFalse( wc_get_order( $deposit->get_id() ) );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $other->get_id() ) );
	}

	public function test_reset_also_clears_the_dispatch_service(): void {
		$this->allow_reset();
		$this->fake_photos();
		$this->env( 'QHS_DISPATCH_INTERNAL_URL', 'http://dispatch.test' );
		$this->env( 'DISPATCH_API_KEY', 'key' );

		DemoReset::run();

		$call = end( $this->http );
		$this->assertSame( 'http://dispatch.test/api/v1/assignments', $call['url'] );
		$this->assertSame( 'DELETE', $call['args']['method'] );
		$this->assertSame( 'key', $call['args']['headers']['X-Api-Key'] );
		// The fake transport answers 201, not the 200 the service sends, so an alert is raised.
		$this->assertSame( 1, $this->row_count( 'alerts', "source = 'reset'" ) );
	}

	public function test_demo_users_cannot_edit_themselves(): void {
		$user = $this->demo_user( Roles::CUSTOMER );
		$this->assertFalse( user_can( $user, 'edit_user', $user ) );

		$normal = $this->user( Roles::CUSTOMER );
		$this->assertTrue( user_can( $normal, 'edit_user', $normal ) );
	}

	public function test_rest_api_refuses_a_demo_user_changing_their_email(): void {
		$user = $this->demo_user( Roles::HOST );
		wp_set_current_user( $user );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'email', 'stolen@example.com' );
		$request->set_param( 'password', 'stolen' );

		$this->assertGreaterThanOrEqual( 401, rest_do_request( $request )->get_status() );
		$this->assertNotSame( 'stolen@example.com', get_userdata( $user )->user_email );
	}

	public function test_password_reset_is_off_for_demo_users(): void {
		$this->assertFalse( apply_filters( 'allow_password_reset', true, $this->demo_user( Roles::HOST ) ) );
		$this->assertTrue( apply_filters( 'allow_password_reset', true, $this->user( Roles::HOST ) ) );
	}

	public function test_application_passwords_are_off_for_demo_users(): void {
		$this->assertFalse( wp_is_application_passwords_available_for_user( $this->demo_user( Roles::MANAGER ) ) );
	}

	public function test_profile_saves_are_refused(): void {
		$user   = $this->demo_user( Roles::CUSTOMER );
		$errors = new \WP_Error();

		DemoGuard::refuse_profile_update( $errors, true, (object) array( 'ID' => $user ) );

		$this->assertSame( 'qhs_demo_account', $errors->get_error_code() );
	}

	public function test_demo_admin_is_not_an_administrator(): void {
		$user = $this->demo_user( Roles::MANAGER );

		foreach ( array( 'manage_options', 'activate_plugins', 'edit_users', 'install_plugins', 'edit_theme_options', 'edit_posts', 'manage_woocommerce', 'unfiltered_html' ) as $cap ) {
			$this->assertFalse( user_can( $user, $cap ), $cap );
		}
		$this->assertTrue( user_can( $user, BookingPolicy::CAP_MANAGE ) );
		$this->assertTrue( user_can( $user, 'edit_others_qm_listings' ) );
	}

	public function test_hosts_stay_minimal(): void {
		$host = $this->user( Roles::HOST );

		foreach ( array( 'manage_options', 'edit_posts', 'edit_others_qm_listings', BookingPolicy::CAP_MANAGE, 'manage_woocommerce', 'unfiltered_html' ) as $cap ) {
			$this->assertFalse( user_can( $host, $cap ), $cap );
		}
		$this->assertTrue( user_can( $host, BookingPolicy::CAP_HOST ) );
		$this->assertTrue( user_can( $host, 'publish_qm_listings' ) );
	}

	public function test_customer_and_staff_roles_stay_minimal(): void {
		$this->assertFalse( user_can( $this->user( Roles::CUSTOMER ), BookingPolicy::CAP_WORK ) );
		$this->assertFalse( user_can( $this->user( Roles::CUSTOMER ), BookingPolicy::CAP_HOST ) );
		$this->assertFalse( user_can( $this->user( Roles::STAFF ), BookingPolicy::CAP_MANAGE ) );
		$this->assertFalse( user_can( $this->user( Roles::STAFF ), BookingPolicy::CAP_HOST ) );
	}

	public function test_banner_text_is_exact(): void {
		ob_start();
		Banner::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Demo: fictional places, no real bookings or payments', $html );
		$this->assertStringContainsString( Banner::TEXT, $html );
	}

	public function test_banner_hooks_cover_front_login_and_admin(): void {
		foreach ( array( 'wp_body_open', 'login_header', 'in_admin_header' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook, array( Banner::class, 'render' ) ), $hook );
		}
	}
}
