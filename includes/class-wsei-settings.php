<?php
/**
 * Settings tab: WooCommerce > Settings > E-Invoice.
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class WSEI_Settings {

	const OPTION = 'wsei_settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_wsei', array( __CLASS__, 'output' ) );
		add_action( 'woocommerce_update_options_wsei', array( __CLASS__, 'save' ) );
	}

	/**
	 * Default values, derived from the WooCommerce store address where possible.
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		$country = (string) get_option( 'woocommerce_default_country', 'DE' );
		$country = strtoupper( substr( $country, 0, 2 ) );
		return array(
			'api_key'         => '',
			'format'          => 'xrechnung',
			'auto_status'     => 'completed',
			'attach_email'    => 'yes',
			'only_b2b'        => 'no',
			'seller_name'     => (string) get_bloginfo( 'name' ),
			'seller_vat_id'   => '',
			'seller_tax_no'   => '',
			'seller_reg_no'   => '',
			'small_business'  => 'no',
			'seller_street'   => trim( get_option( 'woocommerce_store_address', '' ) . ' ' . get_option( 'woocommerce_store_address_2', '' ) ),
			'seller_postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
			'seller_city'     => (string) get_option( 'woocommerce_store_city', '' ),
			'seller_country'  => $country,
			'seller_email'    => (string) get_option( 'admin_email', '' ),
			'seller_phone'    => '',
			'seller_contact'  => '',
			'seller_iban'     => '',
			'seller_bic'      => '',
			'payment_terms'   => '',
			'peppol_mode'     => 'off',
			'peppol_auto'     => 'no',
			'vat_meta_keys'   => '_wsevv_vat_number,_billing_vat_id,_billing_vat,_vat_number,vat_number,_billing_eu_vat_number,billing_vat',
		);
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get( $key ) {
		$opts = get_option( self::OPTION, array() );
		$opts = is_array( $opts ) ? $opts : array();
		$def  = self::defaults();
		if ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
			return (string) $opts[ $key ];
		}
		return isset( $def[ $key ] ) ? (string) $def[ $key ] : '';
	}

	/**
	 * Add the tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$tabs['wsei'] = __( 'E-Invoice', 'w-system-e-invoice' );
		return $tabs;
	}

	/**
	 * Field definitions.
	 *
	 * @return array
	 */
	public static function fields() {
		$d   = self::defaults();
		$opt = self::OPTION;
		$f   = function ( $key, $title, $type = 'text', $extra = array() ) use ( $d, $opt ) {
			return array_merge(
				array(
					'id'      => $opt . '[' . $key . ']',
					'title'   => $title,
					'type'    => $type,
					'default' => $d[ $key ],
				),
				$extra
			);
		};
		return array(
			array(
				'title' => __( 'E-invoices (XRechnung / EN 16931)', 'w-system-e-invoice' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: link to the free API key page */
					__( 'Creates a structured e-invoice (UBL XML) for each order through the W System API and checks it against the official EN 16931 and XRechnung rules. Without an API key the free tier allows 20 calls per day per server IP address. %s', 'w-system-e-invoice' ),
					'<a href="https://api.wsystem.dev/account/free-key?ref=woo-einvoice" target="_blank" rel="noopener">' . esc_html__( 'Get a free API key', 'w-system-e-invoice' ) . '</a>'
				),
				'id'    => 'wsei_general',
			),
			$f( 'api_key', __( 'API key (optional)', 'w-system-e-invoice' ), 'password', array( 'desc_tip' => __( 'Raises the quota and is required for live Peppol sending.', 'w-system-e-invoice' ) ) ),
			$f(
				'format',
				__( 'Format', 'w-system-e-invoice' ),
				'select',
				array(
					'options' => array(
						'xrechnung'   => __( 'XRechnung 3.0 (UBL), Germany', 'w-system-e-invoice' ),
						'en16931'     => __( 'EN 16931 (UBL), EU standard', 'w-system-e-invoice' ),
						'peppol-bis3' => __( 'Peppol BIS Billing 3.0 (UBL)', 'w-system-e-invoice' ),
					),
				)
			),
			$f(
				'auto_status',
				__( 'Create automatically', 'w-system-e-invoice' ),
				'select',
				array(
					'options' => array(
						'completed'  => __( 'When the order is completed', 'w-system-e-invoice' ),
						'processing' => __( 'When the order is processing (paid)', 'w-system-e-invoice' ),
						'off'        => __( 'Never (manual only)', 'w-system-e-invoice' ),
					),
				)
			),
			$f( 'only_b2b', __( 'Business customers only', 'w-system-e-invoice' ), 'checkbox', array( 'desc' => __( 'Create automatically only when the billing company or a VAT number is set.', 'w-system-e-invoice' ) ) ),
			$f( 'attach_email', __( 'Attach to customer emails', 'w-system-e-invoice' ), 'checkbox', array( 'desc' => __( 'Attach the XML to the "Completed order" and "Customer invoice" emails when it passed validation.', 'w-system-e-invoice' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'wsei_general',
			),
			array(
				'title' => __( 'Seller (your company)', 'w-system-e-invoice' ),
				'type'  => 'title',
				'desc'  => __( 'XRechnung requires a seller contact with name, phone and email. EN 16931 requires a VAT ID or, without one, a registration number.', 'w-system-e-invoice' ),
				'id'    => 'wsei_seller',
			),
			$f( 'seller_name', __( 'Company name', 'w-system-e-invoice' ) ),
			$f( 'seller_vat_id', __( 'VAT ID', 'w-system-e-invoice' ), 'text', array( 'placeholder' => 'DE123456789' ) ),
			$f( 'seller_tax_no', __( 'Tax number', 'w-system-e-invoice' ), 'text', array( 'desc_tip' => __( 'Only needed if you have no VAT ID.', 'w-system-e-invoice' ) ) ),
			$f( 'seller_reg_no', __( 'Registration number', 'w-system-e-invoice' ), 'text', array( 'placeholder' => 'HRB 12345 B', 'desc_tip' => __( 'Commercial register number. Required by EN 16931 when you have no VAT ID.', 'w-system-e-invoice' ) ) ),
			$f( 'small_business', __( 'Small business (no VAT charged)', 'w-system-e-invoice' ), 'checkbox', array( 'desc' => __( 'You charge no VAT under a small business scheme (for example Kleinunternehmerregelung, section 19 UStG). Lines are marked VAT exempt (category E) with that reason.', 'w-system-e-invoice' ) ) ),
			$f( 'seller_street', __( 'Street', 'w-system-e-invoice' ) ),
			$f( 'seller_postcode', __( 'Postcode', 'w-system-e-invoice' ) ),
			$f( 'seller_city', __( 'City', 'w-system-e-invoice' ) ),
			$f( 'seller_country', __( 'Country code', 'w-system-e-invoice' ), 'text', array( 'placeholder' => 'DE' ) ),
			$f( 'seller_contact', __( 'Contact name', 'w-system-e-invoice' ) ),
			$f( 'seller_phone', __( 'Phone', 'w-system-e-invoice' ) ),
			$f( 'seller_email', __( 'Email', 'w-system-e-invoice' ), 'email' ),
			$f( 'seller_iban', __( 'IBAN', 'w-system-e-invoice' ), 'text', array( 'desc_tip' => __( 'Used for orders paid by bank transfer.', 'w-system-e-invoice' ) ) ),
			$f( 'seller_bic', __( 'BIC', 'w-system-e-invoice' ) ),
			$f( 'payment_terms', __( 'Payment terms', 'w-system-e-invoice' ), 'text', array( 'placeholder' => __( 'Payable within 14 days', 'w-system-e-invoice' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'wsei_seller',
			),
			array(
				'title' => __( 'Peppol', 'w-system-e-invoice' ),
				'type'  => 'title',
				'desc'  => __( 'Optional. Sandbox runs the whole send flow (VAT, Peppol and sanctions checks, validation) but transmits nothing. Live sending needs an API key, a Peppol mandate for your VAT ID and an approved W System account; the API tells you what is missing.', 'w-system-e-invoice' ),
				'id'    => 'wsei_peppol',
			),
			$f(
				'peppol_mode',
				__( 'Peppol sending', 'w-system-e-invoice' ),
				'select',
				array(
					'options' => array(
						'off'     => __( 'Off', 'w-system-e-invoice' ),
						'sandbox' => __( 'Sandbox (test, nothing is delivered)', 'w-system-e-invoice' ),
						'live'    => __( 'Live', 'w-system-e-invoice' ),
					),
				)
			),
			$f( 'peppol_auto', __( 'Send automatically', 'w-system-e-invoice' ), 'checkbox', array( 'desc' => __( 'Send each automatically created, valid e-invoice of a business customer over Peppol.', 'w-system-e-invoice' ) ) ),
			$f( 'vat_meta_keys', __( 'Buyer VAT ID order fields', 'w-system-e-invoice' ), 'text', array( 'desc_tip' => __( 'Comma-separated order meta keys searched for the customer VAT ID (other VAT plugins).', 'w-system-e-invoice' ) ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'wsei_peppol',
			),
		);
	}

	/**
	 * Render.
	 */
	public static function output() {
		woocommerce_admin_fields( self::fields() );
	}

	/**
	 * Save.
	 */
	public static function save() {
		woocommerce_update_options( self::fields() );
	}
}
