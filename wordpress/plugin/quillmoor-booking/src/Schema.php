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

	public const VERSION = '1';

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
		if ( self::VERSION !== get_option( 'qhs_schema_version' ) ) {
			self::install();
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
			'quotes'          => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
customer_id bigint(20) unsigned NOT NULL,
service varchar(40) NOT NULL,
size varchar(40) NOT NULL,
area varchar(40) NOT NULL,
extras text NOT NULL,
line_items text NOT NULL,
total_cents int(10) unsigned NOT NULL,
deposit_cents int(10) unsigned NOT NULL,
valid_until datetime NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY customer_id (customer_id)',
			'bookings'        => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
quote_id bigint(20) unsigned NOT NULL,
customer_id bigint(20) unsigned NOT NULL,
state varchar(20) NOT NULL DEFAULT 'quote',
version int(10) unsigned NOT NULL DEFAULT 1,
service varchar(40) NOT NULL,
area varchar(40) NOT NULL,
slot_start datetime NULL,
hold_expires_at datetime NULL,
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
staff_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
job_status varchar(20) NOT NULL DEFAULT 'scheduled',
priority varchar(10) NOT NULL DEFAULT 'normal',
customer_note varchar(500) NOT NULL DEFAULT '',
access_key char(32) NOT NULL,
refund_status varchar(20) NOT NULL DEFAULT 'none',
reminder_sent_at datetime NULL,
followup_sent_at datetime NULL,
completed_at datetime NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY state_slot (state,slot_start),
KEY customer_id (customer_id),
KEY staff_user_id (staff_user_id)",
			'slot_claims'     => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
area varchar(40) NOT NULL,
slot_start datetime NOT NULL,
unit tinyint(3) unsigned NOT NULL DEFAULT 1,
booking_id bigint(20) unsigned NOT NULL,
expires_at datetime NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slot_unit (area,slot_start,unit),
KEY booking_id (booking_id),
KEY expires_at (expires_at)',
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
