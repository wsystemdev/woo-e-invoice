<?php
/**
 * Creating, storing, attaching and sending e-invoices.
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * E-invoice lifecycle for orders.
 */
class WSEI_Invoices {

	/**
	 * Temporary files written for email attachments, removed on shutdown.
	 *
	 * @var string[]
	 */
	private static $tmp = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		// Priority 5: before WooCommerce sends the status email (priority 10).
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_status' ), 5, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_status' ), 5, 2 );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'attach' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Automatic creation on status change.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public static function on_status( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$want = WSEI_Settings::get( 'auto_status' );
		if ( 'off' === $want || ! $order->has_status( $want ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( '_wsei_xml' ) ) {
			return;
		}
		if ( 'yes' === WSEI_Settings::get( 'only_b2b' ) && ! WSEI_Mapper::is_b2b( $order ) ) {
			return;
		}
		$res = self::generate( $order );
		if ( $res['valid'] && 'off' !== WSEI_Settings::get( 'peppol_mode' ) && 'yes' === WSEI_Settings::get( 'peppol_auto' ) && WSEI_Mapper::is_b2b( $order ) ) {
			self::send( $order );
		}
	}

	/**
	 * Create and validate the e-invoice, store it on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array{valid:bool,error:string,errors:array}
	 */
	public static function generate( $order ) {
		$body = WSEI_Mapper::invoice( $order );
		$res  = WSEI_API::post( '/einvoice/create', $body );
		if ( ! $res['ok'] ) {
			$order->update_meta_data( '_wsei_last_error', $res['error'] );
			$order->save();
			/* translators: %s: error message */
			$order->add_order_note( sprintf( __( 'E-invoice could not be created: %s', 'w-system-e-invoice' ), $res['error'] ) );
			return array(
				'valid'  => false,
				'error'  => $res['error'],
				'errors' => array(),
			);
		}
		$d      = $res['data'];
		$valid  = ! empty( $d['valid'] );
		$errors = array();
		foreach ( isset( $d['errors'] ) && is_array( $d['errors'] ) ? $d['errors'] : array() as $e ) {
			$errors[] = array(
				'rule_id' => isset( $e['rule_id'] ) ? (string) $e['rule_id'] : '',
				'message' => isset( $e['explain']['what'] ) ? (string) $e['explain']['what'] : ( isset( $e['message'] ) ? (string) $e['message'] : '' ),
				'fix'     => isset( $e['explain']['fix'] ) ? (string) $e['explain']['fix'] : ( isset( $e['fix'] ) ? (string) $e['fix'] : '' ),
			);
		}
		$totals   = isset( $d['totals'] ) && is_array( $d['totals'] ) ? $d['totals'] : array();
		$mismatch = '';
		if ( isset( $totals['total_with_vat'] ) && abs( (float) $totals['total_with_vat'] - (float) $order->get_total() ) > 0.004 ) {
			/* translators: 1: e-invoice total, 2: order total */
			$mismatch = sprintf( __( 'The e-invoice total (%1$s) differs from the order total (%2$s), usually by rounding. Check the tax rounding settings.', 'w-system-e-invoice' ), $totals['total_with_vat'], wc_format_decimal( $order->get_total(), 2 ) );
		}
		$order->update_meta_data( '_wsei_xml', isset( $d['xml'] ) ? (string) $d['xml'] : '' );
		$order->update_meta_data( '_wsei_valid', $valid ? 'yes' : 'no' );
		$order->update_meta_data( '_wsei_errors', $errors );
		$order->update_meta_data( '_wsei_format', $body['format'] );
		$order->update_meta_data( '_wsei_number', $body['invoice_number'] );
		$order->update_meta_data( '_wsei_totals', $totals );
		$order->update_meta_data( '_wsei_mismatch', $mismatch );
		$order->update_meta_data( '_wsei_created', gmdate( 'c' ) );
		$order->delete_meta_data( '_wsei_last_error' );
		$order->save();
		if ( $valid ) {
			/* translators: %s: format name */
			$order->add_order_note( sprintf( __( 'E-invoice (%s) created and passed validation.', 'w-system-e-invoice' ), $body['format'] ) );
		} else {
			/* translators: 1: format name, 2: number of errors */
			$order->add_order_note( sprintf( __( 'E-invoice (%1$s) created but failed validation with %2$d error(s). See the E-invoice box on the order.', 'w-system-e-invoice' ), $body['format'], count( $errors ) ) );
		}
		return array(
			'valid'  => $valid,
			'error'  => '',
			'errors' => $errors,
		);
	}

	/**
	 * Send the order's e-invoice over Peppol (sandbox or live).
	 *
	 * @param WC_Order $order Order.
	 * @return array{ok:bool,message:string}
	 */
	public static function send( $order ) {
		$mode = WSEI_Settings::get( 'peppol_mode' );
		if ( 'off' === $mode ) {
			return array(
				'ok'      => false,
				'message' => __( 'Peppol sending is off in the settings.', 'w-system-e-invoice' ),
			);
		}
		$body = array(
			'invoice' => WSEI_Mapper::invoice( $order, 'peppol-bis3' ),
			'mode'    => 'live' === $mode ? 'live' : 'sandbox',
		);
		if ( 'live' !== $mode ) {
			$body['transport'] = 'mock';
		}
		$res  = WSEI_API::post( '/einvoice/send', $body );
		$d    = $res['data'];
		$info = array(
			'mode'        => $body['mode'],
			'at'          => gmdate( 'c' ),
			'status'      => isset( $d['status'] ) ? (string) $d['status'] : ( $res['ok'] ? 'ok' : 'failed' ),
			'transmitted' => ! empty( $d['transmission']['transmitted'] ) || ! empty( $d['transmitted'] ),
			'receipt_id'  => isset( $d['receipt_id'] ) ? (string) $d['receipt_id'] : '',
			'message'     => '',
		);
		if ( ! $res['ok'] ) {
			$info['message'] = isset( $d['message'] ) && is_string( $d['message'] ) ? $d['message'] : $res['error'];
		} elseif ( ! empty( $d['warnings'] ) && is_array( $d['warnings'] ) ) {
			$info['message'] = (string) reset( $d['warnings'] );
		}
		$order->update_meta_data( '_wsei_peppol', $info );
		$order->save();
		if ( $res['ok'] ) {
			$note = 'sandbox' === $info['mode']
				/* translators: %s: receipt id */
				? sprintf( __( 'Peppol sandbox send simulated (nothing delivered). Receipt %s.', 'w-system-e-invoice' ), $info['receipt_id'] )
				/* translators: 1: status, 2: receipt id */
				: sprintf( __( 'Peppol send: %1$s. Receipt %2$s.', 'w-system-e-invoice' ), $info['status'], $info['receipt_id'] );
		} else {
			/* translators: %s: reason */
			$note = sprintf( __( 'Peppol send refused: %s', 'w-system-e-invoice' ), $info['message'] );
		}
		$order->add_order_note( $note );
		return array(
			'ok'      => $res['ok'],
			'message' => $note,
		);
	}

	/**
	 * File name for the XML download/attachment.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function filename( $order ) {
		$fmt    = (string) $order->get_meta( '_wsei_format' );
		$prefix = 'xrechnung' === $fmt ? 'XRechnung' : 'e-invoice';
		$num    = sanitize_file_name( (string) $order->get_meta( '_wsei_number' ) );
		return $prefix . '-' . ( '' !== $num ? $num : $order->get_id() ) . '.xml';
	}

	/**
	 * Attach the valid XML to customer emails.
	 *
	 * @param array  $attachments Paths.
	 * @param string $email_id    Email id.
	 * @param mixed  $order       Object the email is about.
	 * @return array
	 */
	public static function attach( $attachments, $email_id, $order ) {
		$ids = apply_filters( 'wsei_attach_email_ids', array( 'customer_completed_order', 'customer_invoice' ) );
		if ( 'yes' !== WSEI_Settings::get( 'attach_email' ) || ! in_array( $email_id, $ids, true ) || ! $order instanceof WC_Order ) {
			return $attachments;
		}
		$xml = (string) $order->get_meta( '_wsei_xml' );
		if ( '' === $xml || 'yes' !== $order->get_meta( '_wsei_valid' ) ) {
			return $attachments;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'wsei-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $attachments;
		}
		$path = $dir . '/' . self::filename( $order );
		if ( false !== file_put_contents( $path, $xml ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			self::$tmp[]   = $path;
			$attachments[] = $path;
		}
		return $attachments;
	}

	/**
	 * Remove temporary attachment files.
	 */
	public static function cleanup() {
		foreach ( self::$tmp as $path ) {
			wp_delete_file( $path );
			@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		self::$tmp = array();
	}
}
