<?php
/**
 * Signed message envelope.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Config;
use Quillmoor\Booking\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Signs and verifies the envelope in PLAN.md section 5.
 */
final class Signer {

	/**
	 * Sign a payload string.
	 *
	 * @param string $key_id  Key id.
	 * @param string $payload JSON document as a string.
	 * @return array{headers: array<string,string>, body: string}
	 */
	public function sign( string $key_id, string $payload ): array {
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		return array(
			'headers' => array(
				'X-QHS-Key-Id'    => $key_id,
				'X-QHS-Timestamp' => $timestamp,
				'X-QHS-Nonce'     => $nonce,
				'X-QHS-Signature' => 'v1=' . self::mac( Config::hmac_secret( $key_id ), $timestamp, $nonce, $payload ),
			),
			'body'    => (string) wp_json_encode( array( 'payload' => $payload ) ),
		);
	}

	/**
	 * Compute the MAC.
	 *
	 * @param string $secret    Shared secret.
	 * @param string $timestamp Unix seconds.
	 * @param string $nonce     Nonce.
	 * @param string $payload   Payload string.
	 */
	public static function mac( string $secret, string $timestamp, string $nonce, string $payload ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $payload, $secret );
	}

	/**
	 * Verify a message. The nonce is stored only after the signature passes, so an attacker
	 * cannot burn nonces or fill the table.
	 *
	 * @param string   $key_id    Claimed key id.
	 * @param string   $timestamp Timestamp header.
	 * @param string   $nonce     Nonce header.
	 * @param string   $signature Signature header.
	 * @param string   $payload   Payload string.
	 * @param string[] $allowed   Key ids this endpoint accepts.
	 * @return bool
	 */
	public function verify( string $key_id, string $timestamp, string $nonce, string $signature, string $payload, array $allowed ): bool {
		if ( ! in_array( $key_id, $allowed, true ) ) {
			return false;
		}
		$secret = Config::hmac_secret( $key_id );
		if ( strlen( $secret ) < 32 ) {
			return false;
		}
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > Config::SIGNATURE_WINDOW ) {
			return false;
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
			return false;
		}
		$expected = 'v1=' . self::mac( $secret, $timestamp, $nonce, $payload );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}
		return $this->remember_nonce( $key_id, $nonce );
	}

	/**
	 * Store a nonce. The unique key rejects a replay even when two copies race.
	 *
	 * @param string $key_id Key id.
	 * @param string $nonce  Nonce.
	 */
	private function remember_nonce( string $key_id, string $nonce ): bool {
		global $wpdb;
		$rows = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (key_id, nonce, expires_at) VALUES (%s, %s, %s)',
				Db::table( 'nonces' ),
				$key_id,
				$nonce,
				Db::now( 2 * Config::SIGNATURE_WINDOW )
			)
		);
		return 1 === $rows;
	}

	/**
	 * Remove expired nonces.
	 */
	public function purge(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', Db::table( 'nonces' ), Db::now() ) );
	}
}
