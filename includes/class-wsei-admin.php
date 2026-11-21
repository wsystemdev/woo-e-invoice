<?php
/**
 * Order screen: E-invoice box, actions and download.
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class WSEI_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
		add_action( 'admin_post_wsei_action', array( __CLASS__, 'handle' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_wsei_generate', array( __CLASS__, 'action_generate' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_reference' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Register the box on both the legacy and the HPOS order screen.
	 */
	public static function meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'wsei-box', __( 'E-invoice', 'w-system-e-invoice' ), array( __CLASS__, 'render' ), $screen, 'side', 'default' );
		}
	}

	/**
	 * Action URL.
	 *
	 * @param string $what     generate|download|send.
	 * @param int    $order_id Order id.
	 * @return string
	 */
	public static function url( $what, $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wsei_action',
					'do'     => $what,
					'order'  => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'wsei_' . $what . '_' . $order_id
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$xml    = (string) $order->get_meta( '_wsei_xml' );
		$valid  = 'yes' === $order->get_meta( '_wsei_valid' );
		$errors = $order->get_meta( '_wsei_errors' );
		$last   = (string) $order->get_meta( '_wsei_last_error' );
		$peppol = $order->get_meta( '_wsei_peppol' );
		$ref    = (string) $order->get_meta( '_wsei_buyer_reference' );

		echo '<div class="wsei-box">';
		if ( '' === $xml ) {
			echo '<p>' . esc_html__( 'No e-invoice yet.', 'w-system-e-invoice' ) . '</p>';
		} else {
			$fmt = (string) $order->get_meta( '_wsei_format' );
			echo '<p><strong>' . esc_html( strtoupper( $fmt ) ) . '</strong> &middot; ' . esc_html( (string) $order->get_meta( '_wsei_number' ) ) . '<br>';
			if ( $valid ) {
				$rules = 'xrechnung' === $fmt ? __( 'Valid (EN 16931 and XRechnung rules)', 'w-system-e-invoice' ) : __( 'Valid (EN 16931 rules)', 'w-system-e-invoice' );
				echo '<span style="color:#008a20">&#10003; ' . esc_html( $rules ) . '</span>';
			} else {
				echo '<span style="color:#d63638">&#10007; ' . esc_html__( 'Failed validation', 'w-system-e-invoice' ) . '</span>';
			}
			echo '</p>';
			if ( ! $valid && is_array( $errors ) && $errors ) {
				echo '<ul style="list-style:disc;margin-left:1.2em">';
				foreach ( array_slice( $errors, 0, 8 ) as $e ) {
					echo '<li><code>' . esc_html( $e['rule_id'] ) . '</code> ' . esc_html( $e['message'] );
					if ( ! empty( $e['fix'] ) ) {
						echo '<br><em>' . esc_html( $e['fix'] ) . '</em>';
					}
					echo '</li>';
				}
				echo '</ul>';
			}
			$mismatch = (string) $order->get_meta( '_wsei_mismatch' );
			if ( '' !== $mismatch ) {
				echo '<p style="color:#996800">' . esc_html( $mismatch ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( self::url( 'download', $order->get_id() ) ) . '">' . esc_html__( 'Download XML', 'w-system-e-invoice' ) . '</a></p>';
		}
		if ( '' !== $last ) {
			echo '<p style="color:#d63638">' . esc_html__( 'Last attempt failed:', 'w-system-e-invoice' ) . ' ' . esc_html( $last ) . '</p>';
		}
		$label = '' === $xml ? __( 'Create e-invoice', 'w-system-e-invoice' ) : __( 'Create again', 'w-system-e-invoice' );
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( 'generate', $order->get_id() ) ) . '">' . esc_html( $label ) . '</a></p>';

		$mode = WSEI_Settings::get( 'peppol_mode' );
		if ( 'off' !== $mode && $valid ) {
			$btn = 'sandbox' === $mode ? __( 'Send via Peppol (sandbox test)', 'w-system-e-invoice' ) : __( 'Send via Peppol', 'w-system-e-invoice' );
			echo '<p><a class="button" href="' . esc_url( self::url( 'send', $order->get_id() ) ) . '">' . esc_html( $btn ) . '</a></p>';
		}
		if ( is_array( $peppol ) && ! empty( $peppol['at'] ) ) {
			echo '<p><small>' . esc_html__( 'Peppol:', 'w-system-e-invoice' ) . ' ' . esc_html( $peppol['mode'] . ' / ' . $peppol['status'] );
			if ( ! empty( $peppol['receipt_id'] ) ) {
				echo ' &middot; ' . esc_html( $peppol['receipt_id'] );
			}
			if ( ! empty( $peppol['message'] ) ) {
				echo '<br>' . esc_html( $peppol['message'] );
			}
			echo '</small></p>';
		}
		wp_nonce_field( 'wsei_ref', 'wsei_ref_nonce' );
		echo '<p><label for="wsei_buyer_reference">' . esc_html__( 'Buyer reference (Leitweg-ID for public sector)', 'w-system-e-invoice' ) . '</label><br>';
		echo '<input type="text" style="width:100%" id="wsei_buyer_reference" name="wsei_buyer_reference" value="' . esc_attr( $ref ) . '" placeholder="' . esc_attr( (string) $order->get_order_number() ) . '"></p>';
		echo '<p><small>' . esc_html__( 'Saved with the order. Create the e-invoice again after changing it.', 'w-system-e-invoice' ) . '</small></p>';
		echo '</div>';
	}

	/**
	 * Save the buyer reference field.
	 *
	 * @param int $order_id Order id.
	 */
	public static function save_reference( $order_id ) {
		if ( ! isset( $_POST['wsei_ref_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wsei_ref_nonce'] ) ), 'wsei_ref' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$ref = isset( $_POST['wsei_buyer_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['wsei_buyer_reference'] ) ) : '';
		$order->update_meta_data( '_wsei_buyer_reference', $ref );
		$order->save();
	}

	/**
	 * Order actions dropdown.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function order_actions( $actions ) {
		$actions['wsei_generate'] = __( 'Create e-invoice (XRechnung / EN 16931)', 'w-system-e-invoice' );
		return $actions;
	}

	/**
	 * Order action handler.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_generate( $order ) {
		WSEI_Invoices::generate( $order );
	}

	/**
	 * Handle admin-post actions.
	 */
	public static function handle() {
		$do       = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'wsei_' . $do . '_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'w-system-e-invoice' ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'w-system-e-invoice' ), 404 );
		}
		if ( 'download' === $do ) {
			$xml = (string) $order->get_meta( '_wsei_xml' );
			if ( '' === $xml ) {
				wp_die( esc_html__( 'No e-invoice yet.', 'w-system-e-invoice' ), 404 );
			}
			nocache_headers();
			header( 'Content-Type: application/xml; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . WSEI_Invoices::filename( $order ) . '"' );
			echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw XML file download.
			exit;
		}
		$msg = '';
		if ( 'generate' === $do ) {
			$r   = WSEI_Invoices::generate( $order );
			$msg = '' !== $r['error'] ? $r['error'] : ( $r['valid'] ? __( 'E-invoice created and valid.', 'w-system-e-invoice' ) : __( 'E-invoice created but not valid; see the E-invoice box.', 'w-system-e-invoice' ) );
		} elseif ( 'send' === $do ) {
			$r   = WSEI_Invoices::send( $order );
			$msg = $r['message'];
		}
		set_transient( 'wsei_notice_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Show the result of the last action once.
	 */
	public static function notice() {
		$key = 'wsei_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( (string) $msg ) . '</p></div>';
		}
	}
}
