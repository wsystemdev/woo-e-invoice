<?php
/**
 * End-to-end smoke test. Run inside a throwaway WordPress + WooCommerce site:
 *   wp plugin activate woocommerce w-system-e-invoice && wp eval-file tests/e2e.php
 * Calls the live W System API (about 6 calls of the free tier).
 *
 * @package WSEI
 */

// phpcs:ignoreFile -- test script.

$GLOBALS['wsei_fail'] = 0;
function wsei_ok( $cond, $label ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$GLOBALS['wsei_fail']++;
	}
}

update_option( 'woocommerce_default_country', 'DE:BE' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option(
	'wsei_settings',
	array(
		'seller_name'     => 'Muster Shop GmbH',
		'seller_vat_id'   => 'DE123456789',
		'seller_street'   => 'Hauptstr. 1',
		'seller_postcode' => '10115',
		'seller_city'     => 'Berlin',
		'seller_country'  => 'DE',
		'seller_email'    => 'shop@example.com',
		'seller_phone'    => '+49 30 1234',
		'seller_contact'  => 'Anna Muster',
		'seller_iban'     => 'DE89370400440532013000',
		'auto_status'     => 'completed',
		'attach_email'    => 'yes',
		'peppol_mode'     => 'sandbox',
		'peppol_auto'     => 'no',
	)
);
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
$rate19 = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'DE', 'tax_rate' => '19.0000', 'tax_rate_name' => 'MwSt', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
$rate7  = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'DE', 'tax_rate' => '7.0000', 'tax_rate_name' => 'MwSt 7', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => 'reduced-rate' ) );

$p1 = new WC_Product_Simple();
$p1->set_name( 'T-Shirt "Berlin"' );
$p1->set_regular_price( '19.99' );
$p1->set_sku( 'TS-BER-' . wp_generate_password( 6, false ) );
$p1->save();
$p2 = new WC_Product_Simple();
$p2->set_name( 'Buch' );
$p2->set_regular_price( '12.99' );
$p2->set_tax_class( 'reduced-rate' );
$p2->save();

function wsei_order( $country, $company, $vat, $payment, $products ) {
	$o = wc_create_order();
	foreach ( $products as $pq ) {
		$o->add_product( $pq[0], $pq[1] );
	}
	$o->set_billing_first_name( 'Max' );
	$o->set_billing_last_name( 'Kunde' );
	$o->set_billing_company( $company );
	$o->set_billing_address_1( 'Ring 2' );
	$o->set_billing_city( 'NL' === $country ? 'Amsterdam' : 'Muenchen' );
	$o->set_billing_postcode( 'NL' === $country ? '1011 AB' : '80331' );
	$o->set_billing_country( $country );
	$o->set_billing_email( 'max@example.com' );
	$o->set_shipping_country( $country );
	$o->set_payment_method( $payment );
	$o->set_payment_method_title( 'bacs' === $payment ? 'Bank transfer' : 'PayPal' );
	$ship = new WC_Order_Item_Shipping();
	$ship->set_method_title( 'DHL Paket' );
	$ship->set_total( '4.12' );
	$o->add_item( $ship );
	if ( $vat ) {
		$o->update_meta_data( '_billing_vat_id', $vat );
		$o->set_billing_country( $country );
	}
	$o->calculate_totals( true );
	if ( 'paypal' === $payment ) {
		$o->set_date_paid( time() );
	}
	$o->save();
	return $o;
}

// 1. Consumer order DE, mixed 19% + 7%, paid by PayPal: auto-create on completion.
$o1 = wsei_order( 'DE', '', '', 'paypal', array( array( $p1, 3 ), array( $p2, 1 ) ) );
$o1->update_status( 'completed' );
$o1 = wc_get_order( $o1->get_id() );
$xml = (string) $o1->get_meta( '_wsei_xml' );
wsei_ok( '' !== $xml, 'order 1: XML stored on completion' );
wsei_ok( 'yes' === $o1->get_meta( '_wsei_valid' ), 'order 1: XRechnung valid; errors=' . wp_json_encode( $o1->get_meta( '_wsei_errors' ) ) . ' ' . $o1->get_meta( '_wsei_last_error' ) );
wsei_ok( false !== strpos( $xml, 'urn:xeinkauf.de:kosit:xrechnung_3.0' ), 'order 1: XRechnung 3.0 customization id' );
wsei_ok( '' === $o1->get_meta( '_wsei_mismatch' ), 'order 1: totals match order (' . $o1->get_total() . ') ' . $o1->get_meta( '_wsei_mismatch' ) );
wsei_ok( false !== strpos( $xml, '<cbc:PaymentMeansCode>68</cbc:PaymentMeansCode>' ), 'order 1: paid online -> means code 68' );
wsei_ok( false !== strpos( $xml, '>7<' ) || false !== strpos( $xml, '>7.00<' ), 'order 1: 7% rate present' );
$att = apply_filters( 'woocommerce_email_attachments', array(), 'customer_completed_order', $o1 );
wsei_ok( 1 === count( $att ) && is_file( $att[0] ) && file_get_contents( $att[0] ) === $xml, 'order 1: XML attached to completed email as ' . ( $att ? basename( $att[0] ) : '-' ) );
$att2 = apply_filters( 'woocommerce_email_attachments', array(), 'new_order', $o1 );
wsei_ok( 0 === count( $att2 ), 'order 1: not attached to admin new_order email' );

// 2. B2B order to NL with VAT ID and no tax (reverse charge), bank transfer.
$o2 = wsei_order( 'NL', 'Kunde BV', 'NL004495445B01', 'bacs', array( array( $p1, 2 ) ) );
$o2->set_cart_tax( 0 );
foreach ( $o2->get_items( array( 'line_item', 'shipping' ) ) as $it ) {
	$it->set_taxes( false );
	$it->save();
}
$o2->update_meta_data( 'is_vat_exempt', 'yes' );
$o2->calculate_totals( false );
$o2->save();
$r2 = WSEI_Invoices::generate( $o2 );
$o2 = wc_get_order( $o2->get_id() );
$x2 = (string) $o2->get_meta( '_wsei_xml' );
wsei_ok( $r2['valid'], 'order 2: B2B reverse charge valid; ' . wp_json_encode( $r2 ) );
wsei_ok( false !== strpos( $x2, '<cbc:ID>AE</cbc:ID>' ), 'order 2: VAT category AE' );
wsei_ok( false !== strpos( $x2, 'DE89370400440532013000' ) && false !== strpos( $x2, '<cbc:PaymentMeansCode>58' ), 'order 2: bank transfer with IBAN (58)' );
wsei_ok( false !== strpos( $x2, 'NL004495445B01' ), 'order 2: buyer VAT ID from order meta' );

// 3. Peppol sandbox send (mock transport, nothing delivered).
$s3 = WSEI_Invoices::send( $o2 );
$o2 = wc_get_order( $o2->get_id() );
$pp = $o2->get_meta( '_wsei_peppol' );
wsei_ok( is_array( $pp ) && 'sandbox' === $pp['mode'] && empty( $pp['transmitted'] ), 'order 3: sandbox send recorded, not transmitted; ' . $s3['message'] );

// 4. Small business (section 19 UStG): category E.
$opts                   = get_option( 'wsei_settings' );
$opts['small_business'] = 'yes';
$opts['seller_vat_id']  = '';
$opts['seller_tax_no']  = '12/345/67890';
$opts['seller_reg_no']  = 'HRB 12345 B';
update_option( 'wsei_settings', $opts );
update_option( 'woocommerce_calc_taxes', 'no' );
$o4 = wsei_order( 'DE', '', '', 'bacs', array( array( $p1, 1 ) ) );
$r4 = WSEI_Invoices::generate( $o4 );
$x4 = (string) wc_get_order( $o4->get_id() )->get_meta( '_wsei_xml' );
wsei_ok( $r4['valid'], 'order 4: small business valid; ' . wp_json_encode( $r4 ) );
wsei_ok( false !== strpos( $x4, '<cbc:ID>E</cbc:ID>' ) && false !== strpos( $x4, '19 UStG' ), 'order 4: category E with section 19 reason' );

// 5. Invalid seller data surfaces rule errors instead of failing silently.
$opts['seller_phone'] = '';
$opts['seller_email'] = 'x';
update_option( 'wsei_settings', $opts );
$r5 = WSEI_Invoices::generate( $o4 );
wsei_ok( ! $r5['valid'] && ( $r5['errors'] || $r5['error'] ), 'order 5: missing seller contact reported: ' . wp_json_encode( array_slice( $r5['errors'], 0, 2 ) ) . $r5['error'] );

echo $GLOBALS['wsei_fail'] ? "\n" . $GLOBALS['wsei_fail'] . " FAILED\n" : "\nALL PASSED\n";
if ( $GLOBALS['wsei_fail'] ) {
	exit( 1 );
}
