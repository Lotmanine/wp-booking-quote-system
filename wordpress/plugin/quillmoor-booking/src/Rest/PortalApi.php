<?php
/**
 * Customer portal endpoints.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Rest;

use Quillmoor\Booking\Domain\BookingError;
use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Pdf\PdfRenderer;
use Quillmoor\Booking\Services;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Every handler loads the booking through a policy check first.
 */
final class PortalApi {

	/**
	 * Signed-in customer's bookings.
	 */
	public static function list_mine(): WP_REST_Response|WP_Error {
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return Respond::error( 'not_signed_in', 'Please sign in.', 401 );
		}
		$customer = Services::bookings()->customer_by_user( $user );
		$rows     = null === $customer ? array() : Services::bookings()->for_customer( (int) $customer['id'] );
		return new WP_REST_Response(
			array(
				'bookings'          => array_map( array( Respond::class, 'booking' ), $rows ),
				'marketing_consent' => null !== $customer && (bool) $customer['marketing_consent'],
			)
		);
	}

	/**
	 * One booking.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = Respond::viewable( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		return new WP_REST_Response(
			Respond::booking( $booking ) + array( 'history' => Services::bookings()->history( (int) $booking['id'] ) )
		);
	}

	/**
	 * Move a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function reschedule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = Respond::viewable_for_change( $request, (int) $request['id'] );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		try {
			$moved = Services::service()->reschedule( (int) $booking['id'], sanitize_text_field( (string) $request->get_param( 'slot_start' ) ), Respond::actor( $request )->label() );
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}
		return new WP_REST_Response( Respond::booking( $moved ) );
	}

	/**
	 * Cancel a booking.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = Respond::viewable_for_change( $request, (int) $request['id'] );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		$actor = Respond::actor( $request );
		try {
			$done = Services::service()->cancel( (int) $booking['id'], $actor->label(), ! Services::policy()->is_manager( $actor ) );
		} catch ( BookingError $e ) {
			return Respond::from( $e );
		}
		return new WP_REST_Response( Respond::booking( $done ) );
	}

	/**
	 * Stream a PDF.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function pdf( WP_REST_Request $request ): WP_Error {
		$booking = Respond::viewable( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}
		$kind = (string) $request['kind'];
		if ( 'confirmation' === $kind && in_array( $booking['state'], array( BookingState::Quote->value, BookingState::Hold->value ), true ) ) {
			return Respond::error( 'not_confirmed', 'This booking is not confirmed yet.', 409 );
		}
		$pdf = 'quote' === $kind ? PdfRenderer::quote( $booking ) : PdfRenderer::confirmation( $booking );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $kind . '-QHS-' . (int) $booking['id'] . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}

	/**
	 * Change marketing consent for the signed-in customer.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function consent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return Respond::error( 'not_signed_in', 'Please sign in.', 401 );
		}
		$customer = Services::bookings()->customer_by_user( $user );
		if ( null === $customer ) {
			return Respond::error( 'not_found', 'Customer not found.', 404 );
		}
		$consent = filter_var( $request->get_param( 'marketing_consent' ), FILTER_VALIDATE_BOOLEAN );
		if ( $consent ) {
			Services::service()->record_consent( (int) $customer['id'] );
		} else {
			Services::service()->withdraw_consent( (int) $customer['id'] );
		}
		Services::pipeline()->set_consent( (int) $customer['id'], $consent );
		return new WP_REST_Response( array( 'marketing_consent' => $consent ) );
	}
}
