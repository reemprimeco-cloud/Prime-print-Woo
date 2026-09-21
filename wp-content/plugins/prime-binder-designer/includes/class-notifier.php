<?php
/**
 * Tell the shop on WhatsApp (Twilio) when a designed order is paid and its
 * print files are ready (§3.5).
 *
 * This repo has no Twilio integration to reuse, so this is a small, optional
 * sender that switches on only when these constants are defined in wp-config
 * (kept out of the database so the token is never stored in it):
 *
 *   BINDER_TWILIO_SID    Account SID
 *   BINDER_TWILIO_TOKEN  Auth token
 *   BINDER_TWILIO_FROM   Sender, e.g. whatsapp:+14155238886
 *   BINDER_SHOP_WHATSAPP The shop's number, e.g. whatsapp:+965XXXXXXXX
 *
 * An existing integration can take over instead: hook `binder_order_print_ready`
 * (order + files) and return true from `binder_notify_handled`, or replace the
 * text with the `binder_notify_message` filter.
 *
 * WhatsApp only allows free-form messages inside 24 hours of the recipient
 * last writing to the sender; outside that window Twilio requires an approved
 * template. BINDER_TWILIO_CONTENT_SID (a template with variables {{1}} order
 * number and {{2}} links) is used when defined.
 *
 * @package PrimeBinderDesigner
 */

defined( 'ABSPATH' ) || exit;

class Binder_Notifier {

	public static function init() {
		add_action( 'binder_order_print_ready', array( __CLASS__, 'send' ), 10, 2 );
	}

	public static function configured() {
		return defined( 'BINDER_TWILIO_SID' ) && defined( 'BINDER_TWILIO_TOKEN' ) && defined( 'BINDER_TWILIO_FROM' ) && defined( 'BINDER_SHOP_WHATSAPP' );
	}

	/**
	 * @param WC_Order $order Order.
	 * @param array    $files Files (see Binder_Order_Integration::maybe_notify).
	 */
	public static function message( $order, array $files ) {
		$lines = array( sprintf( 'New print order #%s — %d design file(s) ready:', $order->get_order_number(), count( $files ) ) );

		foreach ( $files as $f ) {
			$lines[] = sprintf( '• %s — %s: %s', $f['product'], $f['label'], $f['cmyk_url'] ? $f['cmyk_url'] : '(no file)' );
		}

		$lines[] = 'Links expire in 7 days. Order: ' . $order->get_edit_order_url();

		return (string) apply_filters( 'binder_notify_message', implode( "\n", $lines ), $order, $files );
	}

	public static function send( $order, $files ) {
		if ( apply_filters( 'binder_notify_handled', false, $order, $files ) || ! self::configured() ) {
			return;
		}

		$body = array(
			'From' => BINDER_TWILIO_FROM,
			'To'   => BINDER_SHOP_WHATSAPP,
		);

		if ( defined( 'BINDER_TWILIO_CONTENT_SID' ) ) {
			$links               = implode( ' ', array_filter( wp_list_pluck( $files, 'cmyk_url' ) ) );
			$body['ContentSid']       = BINDER_TWILIO_CONTENT_SID;
			$body['ContentVariables'] = wp_json_encode( array( '1' => (string) $order->get_order_number(), '2' => $links ) );
		} else {
			$body['Body'] = self::message( $order, $files );
		}

		$res = wp_remote_post(
			'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( BINDER_TWILIO_SID ) . '/Messages.json',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( BINDER_TWILIO_SID . ':' . BINDER_TWILIO_TOKEN ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'body'    => $body,
			)
		);

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

		// Never put the token or the message in the note; the outcome is what the shop needs.
		$order->add_order_note( $code >= 200 && $code < 300 ? 'WhatsApp notification sent to the shop.' : sprintf( 'WhatsApp notification to the shop FAILED (%s). Download the files from the Print Files box.', $code ? "HTTP {$code}" : 'no connection' ) );
	}
}
