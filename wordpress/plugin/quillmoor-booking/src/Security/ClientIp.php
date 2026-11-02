<?php
/**
 * Client address resolution.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Trusts X-Forwarded-For only when the connection comes from a configured proxy.
 */
final class ClientIp {

	/**
	 * Resolve the client address.
	 */
	public static function resolve(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' === $forwarded || ! self::from_trusted_proxy( $remote ) ) {
			return $remote;
		}
		// The right-most entry was appended by the proxy we trust; anything left of it is client text.
		$parts = array_map( 'trim', explode( ',', $forwarded ) );
		$last  = (string) end( $parts );
		return filter_var( $last, FILTER_VALIDATE_IP ) ? $last : $remote;
	}

	/**
	 * Hash an address for storage and counter keys.
	 *
	 * @param string $ip Address.
	 */
	public static function hash( string $ip ): string {
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Whether an address is inside a trusted proxy range.
	 *
	 * @param string $ip Address.
	 */
	private static function from_trusted_proxy( string $ip ): bool {
		foreach ( explode( ',', Config::get( 'QHS_TRUSTED_PROXIES' ) ) as $cidr ) {
			$cidr = trim( $cidr );
			if ( '' !== $cidr && self::in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * IPv4 CIDR match.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr Range.
	 */
	public static function in_cidr( string $ip, string $cidr ): bool {
		$parts  = explode( '/', $cidr, 2 );
		$subnet = ip2long( $parts[0] );
		$addr   = ip2long( $ip );
		$bits   = (int) ( $parts[1] ?? 32 );
		if ( false === $subnet || false === $addr || $bits < 0 || $bits > 32 ) {
			return false;
		}
		$mask = 0 === $bits ? 0 : ( -1 << ( 32 - $bits ) );
		return ( $addr & $mask ) === ( $subnet & $mask );
	}
}
