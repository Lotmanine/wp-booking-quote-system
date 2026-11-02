<?php
/**
 * Custom tables.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables.
 */
final class Schema {

	public const VERSION = '3';

	/**
	 * Run dbDelta for every table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		foreach ( self::definitions() as $name => $columns ) {
			dbDelta( 'CREATE TABLE ' . Db::table( $name ) . " (\n{$columns}\n) {$charset};" );
		}
		update_option( 'qhs_schema_version', self::VERSION, false );
	}

	/**
	 * Create tables on sites that activated before a schema change.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( 'qhs_schema_version' );
		if ( self::VERSION === $stored ) {
			return;
		}
		if ( '1' === $stored ) {
			self::drop_v1_tables();
		}
		self::install();
	}

	/**
	 * Version 1 booked home-service visits. Its booking rows have no listing, so they cannot be
	 * migrated; the demo starts again from its seed data.
	 */
	private static function drop_v1_tables(): void {
		global $wpdb;
		foreach ( array( 'bookings', 'quotes', 'slot_claims', 'booking_history', 'outbox', 'triage' ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Db::table( $name ) ) );
		}
	}

	/**
	 * Column definitions keyed by short table name.
	 *
	 * @return array<string,string>
	 */
	public static function definitions(): array {
		return array(
			'customers'       => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
email varchar(190) NOT NULL,
full_name varchar(120) NOT NULL DEFAULT '',
email_verified_at datetime NULL,
verify_token_hash char(64) NULL,
verify_expires_at datetime NULL,
marketing_consent tinyint(1) NOT NULL DEFAULT 0,
consent_pending tinyint(1) NOT NULL DEFAULT 0,
consent_at datetime NULL,
consent_text_version varchar(20) NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY email (email),
KEY user_id (user_id)",
			'quotes'          => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
customer_id bigint(20) unsigned NOT NULL,
listing_id bigint(20) unsigned NOT NULL,
listing_type varchar(10) NOT NULL DEFAULT 'stay',
check_in date NULL,
check_out date NULL,
slot_start datetime NULL,
guests smallint(5) unsigned NOT NULL DEFAULT 1,
extras text NOT NULL,
line_items text NOT NULL,
total_cents int(10) unsigned NOT NULL,
deposit_cents int(10) unsigned NOT NULL,
valid_until datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY customer_id (customer_id)",
			'bookings'        => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
quote_id bigint(20) unsigned NOT NULL,
customer_id bigint(20) unsigned NOT NULL,
listing_id bigint(20) unsigned NOT NULL,
listing_type varchar(10) NOT NULL DEFAULT 'stay',
host_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
location varchar(60) NOT NULL DEFAULT '',
state varchar(20) NOT NULL DEFAULT 'quote',
version int(10) unsigned NOT NULL DEFAULT 1,
check_in date NULL,
check_out date NULL,
slot_start datetime NULL,
slot_end datetime NULL,
guests smallint(5) unsigned NOT NULL DEFAULT 1,
hold_expires_at datetime NULL,
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
priority varchar(10) NOT NULL DEFAULT 'normal',
customer_note varchar(500) NOT NULL DEFAULT '',
access_key char(32) NOT NULL,
refund_status varchar(20) NOT NULL DEFAULT 'none',
refund_cents int(10) unsigned NOT NULL DEFAULT 0,
refunded_at datetime NULL,
requested_at datetime NULL,
reminder_sent_at datetime NULL,
followup_sent_at datetime NULL,
arrived_at datetime NULL,
completed_at datetime NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY state_slot (state,slot_start),
KEY customer_id (customer_id),
KEY host_user_id (host_user_id),
KEY staff_user_id (staff_user_id),
KEY listing_id (listing_id)",
			'night_claims'    => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
listing_id bigint(20) unsigned NOT NULL,
night date NOT NULL,
booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
kind varchar(10) NOT NULL,
expires_at datetime NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY listing_night (listing_id,night),
KEY booking_id (booking_id),
KEY expires_at (expires_at)',
			'table_slots'     => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
listing_id bigint(20) unsigned NOT NULL,
slot_start datetime NOT NULL,
capacity smallint(5) unsigned NOT NULL,
booked smallint(5) unsigned NOT NULL DEFAULT 0,
closed tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY listing_slot (listing_id,slot_start)',
			'table_claims'    => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
listing_id bigint(20) unsigned NOT NULL,
slot_start datetime NOT NULL,
covers smallint(5) unsigned NOT NULL,
expires_at datetime NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY booking_slot (booking_id,slot_start),
KEY listing_slot (listing_id,slot_start)',
			'day_closures'    => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
listing_id bigint(20) unsigned NOT NULL,
day date NOT NULL,
created_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY listing_day (listing_id,day)',
			'booking_notes'   => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
author_id bigint(20) unsigned NOT NULL,
body varchar(1000) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id)',
			'booking_history' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
from_state varchar(20) NOT NULL,
to_state varchar(20) NOT NULL,
actor varchar(40) NOT NULL,
reason varchar(190) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id)",
			'outbox'          => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
event varchar(60) NOT NULL,
booking_id bigint(20) unsigned NOT NULL,
target varchar(20) NOT NULL DEFAULT 'events',
payload text NOT NULL,
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
next_attempt_at datetime NOT NULL,
delivered_at datetime NULL,
failed_at datetime NULL,
crm_synced_at datetime NULL,
last_error varchar(190) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY pending (delivered_at,failed_at,next_attempt_at),
KEY booking_id (booking_id)",
			'nonces'          => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
key_id varchar(20) NOT NULL,
nonce char(32) NOT NULL,
expires_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY key_nonce (key_id,nonce),
KEY expires_at (expires_at)',
			'counters'        => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
counter_key varchar(80) NOT NULL,
window_start bigint(20) unsigned NOT NULL,
count_value int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY counter_window (counter_key,window_start)',
			'mail_log'        => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
to_hash char(64) NOT NULL,
kind varchar(30) NOT NULL,
booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
transport varchar(20) NOT NULL DEFAULT '',
status varchar(30) NOT NULL,
subject varchar(190) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY booking_id (booking_id)",
			'triage'          => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
booking_id bigint(20) unsigned NOT NULL,
suggested_priority varchar(10) NOT NULL,
reason varchar(280) NOT NULL DEFAULT '',
source varchar(20) NOT NULL DEFAULT 'ai',
status varchar(20) NOT NULL DEFAULT 'pending',
reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY booking_id (booking_id),
KEY status (status)",
			'alerts'          => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source varchar(40) NOT NULL,
message varchar(500) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at)",
		);
	}
}
