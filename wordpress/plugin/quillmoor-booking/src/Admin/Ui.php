<?php
/**
 * Admin markup helpers.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Admin;

use Quillmoor\Booking\Domain\BookingState;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Listings\Listing;
use Quillmoor\Booking\Listings\PostTypes;
use Quillmoor\Booking\Security\BookingPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Small, escaped building blocks shared by the booking screens.
 */
final class Ui {

	/**
	 * Notice transient key for the current user.
	 */
	private static function notice_key(): string {
		return 'qhs_notice_' . get_current_user_id();
	}

	/**
	 * Store a notice for the next screen.
	 *
	 * @param string $message Plain text.
	 * @param string $kind    Either "success" or "error".
	 */
	public static function flash( string $message, string $kind = 'success' ): void {
		set_transient(
			self::notice_key(),
			array(
				'message' => $message,
				'kind'    => 'error' === $kind ? 'error' : 'success',
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Print and clear the stored notice.
	 */
	public static function notice(): void {
		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( self::notice_key() );
		printf(
			'<div class="qhs-admin__notice qhs-admin__notice--%s" role="status" data-testid="admin-notice">%s</div>',
			esc_attr( (string) $notice['kind'] ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Screen header with tabs.
	 *
	 * @param string $current Current page slug.
	 * @param string $title   Title.
	 * @param string $lead    Short description.
	 */
	public static function header( string $current, string $title, string $lead ): void {
		$tabs = array(
			'qhs-dashboard' => __( 'Dashboard', 'quillmoor-booking' ),
			'qhs-bookings'  => __( 'Bookings', 'quillmoor-booking' ),
			'qhs-calendar'  => __( 'Calendar', 'quillmoor-booking' ),
			'listings'      => __( 'Listings', 'quillmoor-booking' ),
		);
		if ( current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			$tabs['qhs-activity'] = __( 'Activity', 'quillmoor-booking' );
		}
		$user = wp_get_current_user();
		echo '<div class="wrap qhs-admin"><header class="qhs-admin__head"><div><p class="qhs-admin__eyebrow">';
		echo esc_html( current_user_can( BookingPolicy::CAP_MANAGE ) ? __( 'Quillmoor, all hosts', 'quillmoor-booking' ) : sprintf( /* translators: %s: host name */ __( 'Quillmoor, hosting as %s', 'quillmoor-booking' ), $user->display_name ) );
		echo '</p><h1>' . esc_html( $title ) . '</h1><p class="qhs-admin__lead">' . esc_html( $lead ) . '</p></div>';
		echo '<a class="qhs-admin__btn" href="' . esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::LISTING ) ) . '">' . esc_html__( 'Add a listing', 'quillmoor-booking' ) . '</a></header>';
		echo '<nav class="qhs-admin__tabs" aria-label="' . esc_attr__( 'Booking screens', 'quillmoor-booking' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url = 'listings' === $slug ? admin_url( 'edit.php?post_type=' . PostTypes::LISTING ) : admin_url( 'admin.php?page=' . $slug );
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( $url ),
				$slug === $current ? 'is-active' : '',
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		self::notice();
	}

	/**
	 * Close the screen wrapper.
	 */
	public static function footer(): void {
		echo '<p class="qhs-admin__foot">' . esc_html__( 'Demo data: places, hosts, guests and prices are fictional. Payments run in test mode only.', 'quillmoor-booking' ) . '</p></div>';
	}

	/**
	 * State badge.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function badge( array $booking ): string {
		return sprintf(
			'<span class="qhs-badge qhs-badge--%s" data-testid="state-badge">%s</span>',
			esc_attr( (string) $booking['state'] ),
			esc_html( BookingView::state_label( $booking ) )
		);
	}

	/**
	 * Link to one booking.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function booking_url( array $booking ): string {
		return admin_url( 'admin.php?page=qhs-bookings&booking=' . (int) $booking['id'] );
	}

	/**
	 * Listings the current user may manage, newest first.
	 *
	 * @return Listing[]
	 */
	public static function my_listings(): array {
		$args = array(
			'post_type'      => PostTypes::LISTING,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( ! current_user_can( BookingPolicy::CAP_MANAGE ) ) {
			$args['author'] = get_current_user_id();
		}
		$out = array();
		foreach ( get_posts( $args ) as $post ) {
			$out[] = Listing::from_post( $post );
		}
		return $out;
	}

	/**
	 * Host filter for queries: 0 for managers, else the user.
	 */
	public static function host_scope(): int {
		return current_user_can( BookingPolicy::CAP_MANAGE ) ? 0 : get_current_user_id();
	}

	/**
	 * A one-button form posting a booking action.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $action     Action key.
	 * @param string $label      Button label.
	 * @param string $style      Button style: primary, secondary or danger.
	 * @param string $confirm    Confirmation question, or empty.
	 */
	public static function action_button( int $booking_id, string $action, string $label, string $style = 'secondary', string $confirm = '' ): string {
		return sprintf(
			'<form method="post" action="%1$s" class="qhs-admin__inline"%6$s><input type="hidden" name="action" value="qhs_booking"><input type="hidden" name="booking_id" value="%2$d"><input type="hidden" name="do" value="%3$s">%4$s<button type="submit" class="qhs-admin__btn qhs-admin__btn--%5$s" data-testid="action-%3$s">%7$s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$booking_id,
			esc_attr( $action ),
			wp_nonce_field( 'qhs_booking_' . $booking_id, '_wpnonce', true, false ),
			esc_attr( $style ),
			'' === $confirm ? '' : ' data-confirm="' . esc_attr( $confirm ) . '"',
			esc_html( $label )
		);
	}

	/**
	 * Whether a state still holds nights or seats in the future.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function is_upcoming( array $booking ): bool {
		return BookingState::from( (string) $booking['state'] )->is_live() && (string) $booking['slot_start'] >= gmdate( 'Y-m-d 00:00:00' );
	}
}
