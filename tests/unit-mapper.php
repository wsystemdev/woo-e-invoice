<?php
// Unit checks for WSEI_Mapper without WordPress (stand-ins for the few WP/WC functions it calls). Run: php tests/unit-mapper.php
define( 'ABSPATH', '/tmp/' );
function __( $s, $d = null ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wc_format_decimal( $v ) { return rtrim( rtrim( number_format( (float) $v, 4, '.', '' ), '0' ), '.' ); }
function apply_filters( $tag, $v ) { return $v; }
function current_time( $f ) { return '2026-11-26'; }
class WSEI_Settings { public static $o = array( 'seller_country' => 'DE', 'seller_name' => 'Shop GmbH', 'seller_iban' => '', 'format' => 'xrechnung', 'vat_meta_keys' => '', 'small_business' => 'no' ); public static function get( $k ) { return isset( self::$o[ $k ] ) ? self::$o[ $k ] : ''; } }
class WC_Tax { public static function get_rate_percent_value( $id ) { return array( 1 => 19, 2 => 7 )[ $id ]; } }
class T_Item { public $a; public function __construct( $a ) { $this->a = $a; } public function __call( $m, $x ) { $k = substr( $m, 4 ); return isset( $this->a[ $k ] ) ? $this->a[ $k ] : null; } }
class T_Tax { public $id; public $p; public function __construct( $id, $p ) { $this->id = $id; $this->p = $p; } public function get_rate_id() { return $this->id; } public function get_rate_percent() { return $this->p; } }
class T_Order { public $a; public $i; public function __construct( $a, $i ) { $this->a = $a; $this->i = $i; } public function get_items( $t ) { return isset( $this->i[ $t ] ) ? $this->i[ $t ] : array(); } public function get_meta( $k ) { return ''; } public function is_paid() { return true; } public function get_date_paid() { return null; } public function __call( $m, $x ) { $k = substr( $m, 4 ); return isset( $this->a[ $k ] ) ? $this->a[ $k ] : ''; } }
require __DIR__ . '/../includes/class-wsei-mapper.php';
$fail = 0; $n = 0;
function ok( $c, $m ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $m\n"; } }
function item( $name, $qty, $net, array $taxes ) { return new T_Item( array( 'name' => $name, 'quantity' => $qty, 'total' => $net, 'total_tax' => array_sum( $taxes ), 'taxes' => array( 'total' => $taxes ), 'product' => null ) ); }
function order( array $lines, array $ship, $total ) { return new T_Order( array( 'billing_country' => 'DE', 'billing_company' => 'Kunde GmbH', 'order_number' => '42', 'currency' => 'EUR', 'total' => $total, 'payment_method' => 'ppcp' ), array( 'line_item' => $lines, 'shipping' => $ship, 'tax' => array( new T_Tax( 1, 19 ), new T_Tax( 2, 7 ) ) ) ); }
function api_total( $b ) { $by = array(); foreach ( $b['lines'] as $l ) { $k = (string) $l['vat_rate']; $by[ $k ] = ( isset( $by[ $k ] ) ? $by[ $k ] : 0 ) + round( $l['quantity'] * $l['unit_price'], 2 ); } $ov = array(); foreach ( isset( $b['vat_amounts'] ) ? $b['vat_amounts'] : array() as $v ) { $ov[ (string) $v['vat_rate'] ] = $v['tax_amount']; } $t = 0; foreach ( $by as $r => $x ) { $x = round( $x, 2 ); $t += $x + ( isset( $ov[ $r ] ) ? $ov[ $r ] : round( $x * (float) $r / 100, 2 ) ); } return round( $t, 2 ); }

// shipping taxed at two rates (German-market split) must become two lines, not one line at 26 %
$o = order( array( item( 'Pen', 3, 25.18, array( 1 => 4.79 ) ), item( 'Book', 2, 24.21, array( 2 => 1.69 ) ), item( 'Coffee', 1, 4.66, array( 2 => 0.33 ) ) ),
	array( item( 'DHL', 1, 4.12, array( 1 => 0.30, 2 => 0.18 ) ) ), 65.46 );
$b = WSEI_Mapper::invoice( $o );
$rates = array(); foreach ( $b['lines'] as $l ) { $rates[] = $l['vat_rate']; }
ok( ! in_array( 26.0, $rates, true ) && 5 === count( $b['lines'] ), 'shipping split per rate, no 26 %: ' . json_encode( $rates ) );
ok( abs( api_total( $b ) - 65.46 ) < 0.001, 'total = order total 65.46, got ' . api_total( $b ) );
// one item 9.99 incl. 19 %: Woo net 8.39 + tax 1.60; unreachable by base x rate -> vat_amounts 1.60
$b1 = WSEI_Mapper::invoice( order( array( item( 'Pen', 1, 8.39, array( 1 => 1.60 ) ) ), array(), 9.99 ) );
ok( isset( $b1['vat_amounts'] ) && 1.6 === $b1['vat_amounts'][0]['tax_amount'] && 8.39 == $b1['lines'][0]['unit_price'], '9.99 gross -> vat_amounts 1.60' );
// 7 x 1.49 gross (8.76 net + 1.67 tax): base x rate is 1.66 and no net within 3 cents reaches 10.43 -> vat_amounts 1.67
$b2 = WSEI_Mapper::invoice( order( array( item( 'Block', 7, 8.76, array( 1 => 1.67 ) ) ), array(), 10.43 ) );
ok( isset( $b2['vat_amounts'] ) && abs( api_total( $b2 ) - 10.43 ) < 0.001, 'unreachable 7 x 1.49: vat_amounts, total 10.43 got ' . api_total( $b2 ) );
// 2.62 net + 0.49 tax (per-line rounding) vs base x rate 0.50: 2.61 + 0.50 reaches the same 3.11 -> one-cent net shift, no vat_amounts
$b4 = WSEI_Mapper::invoice( order( array( item( 'A', 1, 1.31, array( 1 => 0.25 ) ), item( 'B', 1, 1.31, array( 1 => 0.24 ) ) ), array(), 3.11 ) );
ok( ! isset( $b4['vat_amounts'] ) && abs( api_total( $b4 ) - 3.11 ) < 0.001, 'reachable: net shifted one cent, no vat_amounts, total 3.11 got ' . api_total( $b4 ) );
// plain net order unchanged
$b3 = WSEI_Mapper::invoice( order( array( item( 'Service', 3, 100.00, array( 1 => 19.00 ) ) ), array(), 119.00 ) );
ok( ! isset( $b3['vat_amounts'] ) && 100.0 == round( $b3['lines'][0]['quantity'] * $b3['lines'][0]['unit_price'], 2 ), 'net order unchanged' );
echo "$n checks, $fail failed\n";
exit( $fail ? 1 : 0 );
