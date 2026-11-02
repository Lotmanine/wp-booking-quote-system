<?php
/**
 * PDF documents.
 *
 * @package Quillmoor\Booking
 */

namespace Quillmoor\Booking\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Quillmoor\Booking\Domain\BookingView;
use Quillmoor\Booking\Listings\Pricing;
use Quillmoor\Booking\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the quote and the booking confirmation. Remote resources stay off.
 */
final class PdfRenderer {

	/**
	 * Quote PDF.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function quote( array $booking ): string {
		return self::render( 'Quote', $booking, 'Valid until ' );
	}

	/**
	 * Booking confirmation PDF.
	 *
	 * @param array<string,mixed> $booking Booking row.
	 */
	public static function confirmation( array $booking ): string {
		return self::render( 'Booking confirmation', $booking, '' );
	}

	/**
	 * Build one document.
	 *
	 * @param string              $title       Heading.
	 * @param array<string,mixed> $booking     Booking row.
	 * @param string              $valid_label Label for the validity line.
	 */
	private static function render( string $title, array $booking, string $valid_label ): string {
		$quote    = Services::bookings()->quote( (int) $booking['quote_id'] );
		$customer = Services::bookings()->customer( (int) $booking['customer_id'] );
		$lines    = json_decode( (string) ( $quote['line_items'] ?? '[]' ), true );

		$rows = '';
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$rows .= '<tr><td>' . esc_html( (string) $line['label'] ) . '</td><td class="r">' . esc_html( Pricing::money( (int) $line['cents'] ) ) . '</td></tr>';
		}

		$state = BookingView::state_label( $booking );

		$html = '<html><head><meta charset="utf-8"><style>
			body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#1c2b26}
			h1{font-size:22px;color:#14342b;margin:0 0 4px}
			.banner{background:#fff3cd;border:1px solid #d9b44a;padding:8px;margin-bottom:16px;font-weight:bold}
			table{width:100%;border-collapse:collapse;margin:14px 0}
			td,th{padding:6px 4px;border-bottom:1px solid #ddd;text-align:left}
			.r{text-align:right}
			.t td{font-weight:bold;border-top:2px solid #14342b}
			</style></head><body>'
			. '<div class="banner">Demo: fictional company, no real bookings or payments. Places and prices are fictional.</div>'
			. '<h1>Quillmoor</h1>'
			. '<p><strong>' . esc_html( $title ) . '</strong> ' . esc_html( BookingView::ref( $booking ) ) . '</p>'
			. '<p>Guest: ' . esc_html( (string) ( $customer['full_name'] ?? '' ) ) . '<br>'
			. 'Place: ' . esc_html( BookingView::listing_title( $booking ) ) . '<br>'
			. 'When: ' . esc_html( BookingView::when( $booking ) ) . ( BookingView::is_dining( $booking ) ? ' UTC' : '' ) . '<br>'
			. 'Status: ' . esc_html( $state )
			. ( '' !== $valid_label ? '<br>' . esc_html( $valid_label . (string) ( $quote['valid_until'] ?? '' ) ) . ' UTC' : '' )
			. '</p>'
			. '<table><tr><th>Item</th><th class="r">Fictional price</th></tr>' . $rows
			. '<tr class="t"><td>Total</td><td class="r">' . esc_html( Pricing::money( (int) ( $quote['total_cents'] ?? 0 ) ) ) . '</td></tr>'
			. '<tr><td>Deposit</td><td class="r">' . esc_html( Pricing::money( (int) ( $quote['deposit_cents'] ?? 0 ) ) ) . '</td></tr></table>'
			. '</body></html>';

		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'Helvetica' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'letter' );
		$dompdf->render();
		return (string) $dompdf->output();
	}
}
