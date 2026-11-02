<?php
/**
 * Cloudflare Turnstile verification.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Security;

use Quillmoor\Booking\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side token check. No secret or no answer means no pass.
 */
final class Turnstile {

	private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Verify a token.
	 *
	 * @param string $token Widget token.
	 * @param string $ip    Client address.
	 */
	public function verify( string $token, string $ip ): bool {
		$secret = Config::get( 'TURNSTILE_SECRET_KEY' );
		if ( '' === $secret || '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && true === ( $body['success'] ?? false );
	}
}
