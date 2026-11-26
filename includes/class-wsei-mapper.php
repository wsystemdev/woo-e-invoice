<?php
/**
 * Maps a WooCommerce order to the JSON body of POST /einvoice/create.
 *
 * @package WSEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order mapper.
 */
class WSEI_Mapper {

	const EU = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' );

	/**
	 * Customer VAT ID from known order meta keys.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function buyer_vat( $order ) {
		$keys = array_filter( array_map( 'trim', explode( ',', WSEI_Settings::get( 'vat_meta_keys' ) ) ) );
		foreach ( $keys as $k ) {
			$v = $order->get_meta( $k );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				return strtoupper( preg_replace( '/[\s.\-]/', '', $v ) );
			}
		}
		return '';
	}

	/**
	 * Whether the order belongs to a business customer.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_b2b( $order ) {
		return '' !== trim( (string) $order->get_billing_company() ) || '' !== self::buyer_vat( $order );
	}

	/**
	 * Invoice number: number of a PDF invoice plugin if present, else the order number.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function invoice_number( $order ) {
		$n = '';
		foreach ( array( '_wcpdf_invoice_number', '_invoice_number' ) as $k ) {
			$v = $order->get_meta( $k );
			if ( is_scalar( $v ) && '' !== (string) $v ) {
				$n = (string) $v;
				break;
			}
		}
		if ( '' === $n ) {
			$n = (string) $order->get_order_number();
		}
		/**
		 * Filters the e-invoice number (BT-1).
		 *
		 * @param string   $n     Invoice number.
		 * @param WC_Order $order Order.
		 */
		return (string) apply_filters( 'wsei_invoice_number', $n, $order );
	}

	/**
	 * VAT rate percent per tax rate id of the order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,float>
	 */
	private static function rates( $order ) {
		$rates = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$pct = method_exists( $tax, 'get_rate_percent' ) ? $tax->get_rate_percent() : null;
			if ( null === $pct || '' === $pct ) {
				$pct = WC_Tax::get_rate_percent_value( $tax->get_rate_id() );
			}
			$rates[ (int) $tax->get_rate_id() ] = (float) $pct;
		}
		return $rates;
	}

	/**
	 * VAT category and rate for one order item.
	 *
	 * @param WC_Order_Item $item  Item.
	 * @param array         $rates Rate map.
	 * @param array         $ctx   Context (category for untaxed lines).
	 * @return array{0:string,1:float}
	 */
	private static function vat_of( $item, array $rates, array $ctx ) {
		$taxes = $item->get_taxes();
		$pct   = 0.0;
		$hit   = false;
		if ( ! empty( $taxes['total'] ) ) {
			foreach ( $taxes['total'] as $rate_id => $amount ) {
				if ( '' !== $amount && null !== $amount && isset( $rates[ (int) $rate_id ] ) ) {
					$pct += $rates[ (int) $rate_id ];
					$hit  = true;
				}
			}
		}
		if ( $hit && $pct > 0 ) {
			return array( 'S', round( $pct, 2 ) );
		}
		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();
		if ( abs( $net ) > 0 && abs( $tax ) > 0 ) {
			return array( 'S', round( $tax / $net * 100, 1 ) );
		}
		return array( $ctx['zero_category'], 0.0 );
	}

	/**
	 * One invoice line from a net amount.
	 *
	 * @param string $desc Description.
	 * @param float  $qty  Quantity.
	 * @param float  $net  Net line amount after discounts.
	 * @param array  $vat  array( category, rate ).
	 * @param string $sku  Seller item id.
	 * @return array
	 */
	/**
	 * Raw lines for one order item, one per VAT rate (BET3). WooCommerce stores one net total per item and a tax amount per rate id;
	 * shipping and fees split over several rates (German-market plugins, "based on cart items") used to be summed into one line
	 * at the SUM of the rates (e.g. 19 % + 7 % = 26 %). Now each rate gets its own line; the net is split by the tax shares.
	 */
	private static function raw_lines( $desc, $qty, $item, array $rates, array $ctx, $sku = '' ) {
		$net   = round( (float) $item->get_total(), 2 );
		$taxes = $item->get_taxes();
		$parts = array();
		if ( ! empty( $taxes['total'] ) ) {
			foreach ( $taxes['total'] as $rate_id => $amount ) {
				if ( '' !== $amount && null !== $amount && isset( $rates[ (int) $rate_id ] ) && $rates[ (int) $rate_id ] > 0 ) {
					$parts[] = array( (float) $rates[ (int) $rate_id ], round( (float) $amount, 2 ) );
				}
			}
		}
		if ( count( $parts ) > 1 ) {
			// Stacked taxes on the SAME base (e.g. compound or state + local rates) add up: keep one line at the summed rate.
			// A price SPLIT over rates (bases implied by tax/rate add up to the net) gets one line per rate.
			// PC-11: a tiny share whose tax rounded to 0.00 has an implied base of 0, so sumEst ≈ maxEst even
			// though this is a split (not stacked). Treat as stacked only when every implied base is the full net.
			$est = array();
			foreach ( $parts as $p ) {
				$est[] = $p[1] / ( $p[0] / 100 );
			}
			$stacked = true;
			foreach ( $est as $k => $e ) {
				// each implied base must equal the full net within that tax's own cent rounding
				if ( abs( $e - $net ) > 0.005 / ( $parts[ $k ][0] / 100 ) + 0.011 ) {
					$stacked = false;
					break;
				}
			}
			if ( ! $stacked ) {
				// split: keep $parts
			} else {
				$parts = array();
			}
		}
		if ( count( $parts ) <= 1 ) {
			$vat = self::vat_of( $item, $rates, $ctx );
			$tax = 1 === count( $parts ) ? $parts[0][1] : round( (float) $item->get_total_tax(), 2 );
			return array( array( 'desc' => $desc, 'qty' => $qty, 'net' => $net, 'tax' => 'S' === $vat[0] ? $tax : 0.0, 'cat' => $vat[0], 'rate' => $vat[1], 'sku' => $sku ) );
		}
		$sum_est = 0.0;
		foreach ( $parts as $p ) {
			$sum_est += $p[1] / ( $p[0] / 100 );
		}
		$out  = array();
		$left = $net;
		foreach ( $parts as $i => $p ) {
			$n     = count( $parts ) - 1 === $i ? round( $left, 2 ) : round( $net * ( $p[1] / ( $p[0] / 100 ) ) / ( $sum_est ? $sum_est : 1 ), 2 );
			$left -= $n;
			$out[] = array( 'desc' => $desc . ' (' . wc_format_decimal( $p[0] ) . ' %)', 'qty' => 1, 'net' => $n, 'tax' => $p[1], 'cat' => 'S', 'rate' => round( $p[0], 2 ), 'sku' => $sku );
		}
		return $out;
	}

	/**
	 * BET3 / PC-4 / PC-5 (same rules as the Shopware plugin's reconcile()). WooCommerce rounds tax per line; the API rounds each line
	 * net to cents (EN 16931) and computes VAT per rate as base x rate. Per rate, where the gross differs, first try a net within 3 cents
	 * on that rate's last line that reaches the same gross (works with every API version). Second pass: if a rate's gross cannot be
	 * reached by any net (e.g. 3 x 9.99 incl. 19 %), shift another rate's net by 1-2 cents so the e-invoice total equals the order total
	 * on today's API. vat_amounts then carries, per rate, the gross WooCommerce charged minus the net sent, wherever base x rate misses it
	 * and the difference is within the API's per-line rounding bound (0.005 per line, min 0.01, max 0.99). API versions without
	 * vat_amounts support ignore the field.
	 */
	private static function reconcile( array $raw ) {
		$groups = array();
		foreach ( $raw as $i => $l ) {
			$raw[ $i ]['net']   = round( (float) $l['net'], 2 );
			$raw[ $i ]['gross'] = round( $raw[ $i ]['net'] + ( 'S' === $l['cat'] ? (float) $l['tax'] : 0.0 ), 2 );
			if ( 'S' === $l['cat'] ) {
				$groups[ (string) $l['rate'] ][] = $i;
			}
		}
		$target = 0.0;
		foreach ( $raw as $l ) {
			$target += $l['gross'];
		}
		$target = round( $target, 2 );
		foreach ( $groups as $rate => $idx ) {
			$r     = (float) $rate;
			$net   = self::gsum( $raw, $idx, 'net' );
			$gross = self::gsum( $raw, $idx, 'gross' );
			if ( abs( round( $net + round( $net * $r / 100, 2 ), 2 ) - $gross ) < 0.001 ) {
				continue;
			}
			$best = null;
			for ( $d = -3; $d <= 3; ++$d ) {
				$n = round( $net + $d / 100, 2 );
				if ( abs( round( $n + round( $n * $r / 100, 2 ), 2 ) - $gross ) < 0.001 && ( null === $best || abs( $d ) < abs( $best ) ) ) {
					$best = $d;
				}
			}
			if ( null !== $best ) {
				$k                = self::last_of( $raw, $idx );
				$raw[ $k ]['net'] = round( $raw[ $k ]['net'] + $best / 100, 2 );
			}
		}
		if ( abs( self::api_total( $raw ) - $target ) > 0.001 ) {
			foreach ( array( 1, -1, 2, -2 ) as $d ) {
				foreach ( $groups as $idx ) {
					$k                = self::last_of( $raw, $idx );
					$try              = $raw;
					$try[ $k ]['net'] = round( $try[ $k ]['net'] + $d / 100, 2 );
					if ( abs( self::api_total( $try ) - $target ) < 0.001 ) {
						$raw = $try;
						break 2;
					}
				}
			}
		}
		$va  = array();
		$tol = min( 0.99, max( 0.01, round( 0.005 * count( $raw ), 2 ) ) );
		foreach ( $groups as $rate => $idx ) {
			$net     = self::gsum( $raw, $idx, 'net' );
			$charged = round( self::gsum( $raw, $idx, 'gross' ) - $net, 2 );
			$calc    = round( $net * (float) $rate / 100, 2 );
			if ( abs( $charged - $calc ) > 0.001 && abs( $charged - $calc ) <= $tol + 0.0001 ) {
				$va[] = array( 'vat_category' => 'S', 'vat_rate' => (float) $rate, 'tax_amount' => $charged );
			}
		}
		return array( $raw, $va );
	}

	private static function gsum( array $raw, array $idx, $f ) {
		$t = 0.0;
		foreach ( $idx as $i ) {
			$t += $raw[ $i ][ $f ];
		}
		return round( $t, 2 );
	}

	/** Line that absorbs a cent shift: the last quantity-1 line of the rate (keeps unit prices exact), else the last line. */
	private static function last_of( array $raw, array $idx ) {
		$k = end( $idx );
		foreach ( $idx as $i ) {
			if ( 1.0 === abs( (float) $raw[ $i ]['qty'] ) ) {
				$k = $i;
			}
		}
		return $k;
	}

	/** Total the API computes: line nets rounded to cents, VAT per category and rate on the summed net. */
	private static function api_total( array $raw ) {
		$by = array();
		foreach ( $raw as $l ) {
			$k        = $l['cat'] . '|' . ( 'S' === $l['cat'] ? $l['rate'] : 0 );
			$by[ $k ] = ( isset( $by[ $k ] ) ? $by[ $k ] : 0.0 ) + round( $l['net'], 2 );
		}
		$t = 0.0;
		foreach ( $by as $k => $net ) {
			$net = round( $net, 2 );
			$t  += $net + round( $net * (float) explode( '|', $k )[1] / 100, 2 );
		}
		return round( $t, 2 );
	}

	private static function line( $desc, $qty, $net, array $vat, $sku = '' ) {
		$qty = (float) $qty;
		$net = round( (float) $net, 2 );
		if ( $qty <= 0 ) {
			$qty = 1;
		}
		if ( $net < 0 ) {
			$qty = -$qty;
		}
		$price = round( $net / $qty, 6 );
		if ( abs( round( $price * $qty, 2 ) - $net ) > 0.001 ) {
			/* translators: 1: quantity, 2: item name */
			$desc  = sprintf( __( '%1$s x %2$s', 'w-system-e-invoice' ), wc_format_decimal( abs( $qty ) ), $desc );
			$qty   = $net < 0 ? -1 : 1;
			$price = abs( $net );
		}
		$line = array(
			'description' => wp_strip_all_tags( html_entity_decode( (string) $desc, ENT_QUOTES, 'UTF-8' ) ),
			'quantity'    => $qty,
			'unit'        => 'C62',
			'unit_price'  => $price,
			'vat_rate'    => $vat[1],
			'vat_category' => $vat[0],
		);
		if ( '' !== $sku ) {
			$line['seller_item_id'] = $sku;
		}
		return $line;
	}

	/**
	 * Build the request body.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $format Format override ('' = setting).
	 * @return array
	 */
	public static function invoice( $order, $format = '' ) {
		$s       = array( 'WSEI_Settings', 'get' );
		$seller_country = strtoupper( call_user_func( $s, 'seller_country' ) );
		$buyer_country  = strtoupper( (string) $order->get_billing_country() );
		$buyer_vat      = self::buyer_vat( $order );
		$small          = 'yes' === call_user_func( $s, 'small_business' );

		$zero = 'Z';
		if ( $small ) {
			$zero = 'E';
		} elseif ( $buyer_country && $buyer_country !== $seller_country && ! in_array( $buyer_country, self::EU, true ) ) {
			$zero = 'G';
		} elseif ( '' !== $buyer_vat && $buyer_country !== $seller_country && in_array( $buyer_country, self::EU, true ) ) {
			$zero = 'K' === apply_filters( 'wsei_intra_eu_category', 'AE', $order ) ? 'K' : 'AE';
		}
		$ctx   = array( 'zero_category' => $zero );
		$rates = self::rates( $order );
		$lines = array();

		$raw = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';
			$raw     = array_merge( $raw, self::raw_lines( $item->get_name(), $item->get_quantity(), $item, $rates, $ctx, $sku ) );
		}
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( 0.0 === round( (float) $item->get_total(), 2 ) ) {
				continue;
			}
			/* translators: %s: shipping method name */
			$raw = array_merge( $raw, self::raw_lines( sprintf( __( 'Shipping: %s', 'w-system-e-invoice' ), $item->get_name() ), 1, $item, $rates, $ctx ) );
		}
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( 0.0 === round( (float) $item->get_total(), 2 ) ) {
				continue;
			}
			$raw = array_merge( $raw, self::raw_lines( $item->get_name(), 1, $item, $rates, $ctx ) );
		}
		$vat_amounts = array();
		if ( ! $small ) {
			list( $raw, $vat_amounts ) = self::reconcile( $raw );
		}
		foreach ( $raw as $r ) {
			$lines[] = self::line( $r['desc'], $r['qty'], $r['net'], array( $r['cat'], $r['rate'] ), $r['sku'] );
		}
		if ( $small ) {
			foreach ( $lines as &$l ) {
				$l['vat_category'] = 'E';
				$l['vat_rate']     = 0;
			}
			unset( $l );
		}

		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company = trim( (string) $order->get_billing_company() );
		$buyer   = array(
			'name'        => '' !== $company ? $company : $name,
			'street'      => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'city'        => (string) $order->get_billing_city(),
			'postal_code' => (string) $order->get_billing_postcode(),
			'country'     => $buyer_country,
			'email'       => (string) $order->get_billing_email(),
		);
		if ( '' !== $company && '' !== $name ) {
			$buyer['contact_name'] = $name;
		}
		if ( '' !== $buyer_vat ) {
			$buyer['vat_id'] = $buyer_vat;
		}

		$seller = array(
			'name'         => call_user_func( $s, 'seller_name' ),
			'street'       => call_user_func( $s, 'seller_street' ),
			'city'         => call_user_func( $s, 'seller_city' ),
			'postal_code'  => call_user_func( $s, 'seller_postcode' ),
			'country'      => $seller_country,
			'email'        => call_user_func( $s, 'seller_email' ),
			'phone'        => call_user_func( $s, 'seller_phone' ),
			'contact_name' => call_user_func( $s, 'seller_contact' ),
		);
		if ( '' !== call_user_func( $s, 'seller_vat_id' ) ) {
			$seller['vat_id'] = strtoupper( preg_replace( '/\s/', '', call_user_func( $s, 'seller_vat_id' ) ) );
		}
		if ( '' !== call_user_func( $s, 'seller_tax_no' ) ) {
			$seller['tax_number'] = call_user_func( $s, 'seller_tax_no' );
		}
		if ( '' !== call_user_func( $s, 'seller_reg_no' ) ) {
			$seller['company_id'] = call_user_func( $s, 'seller_reg_no' );
		}
		$seller = array_filter( $seller, 'strlen' );

		$paid    = $order->get_date_paid() || $order->is_paid();
		$method  = (string) $order->get_payment_method();
		$iban    = preg_replace( '/\s/', '', call_user_func( $s, 'seller_iban' ) );
		$payment = array();
		if ( 'bacs' === $method || ( ! $paid && '' !== $iban ) ) {
			$payment['means_code'] = '58';
		} elseif ( 'cod' === $method ) {
			$payment['means_code'] = '10';
		} elseif ( 'cheque' === $method ) {
			$payment['means_code'] = '20';
		} elseif ( $paid ) {
			$payment['means_code'] = '68';
		} else {
			$payment['means_code'] = '1';
		}
		if ( '' !== $iban && '58' === $payment['means_code'] ) {
			$payment['iban'] = $iban;
			if ( '' !== call_user_func( $s, 'seller_bic' ) ) {
				$payment['bic'] = call_user_func( $s, 'seller_bic' );
			}
			$payment['account_name'] = call_user_func( $s, 'seller_name' );
		}
		$number               = self::invoice_number( $order );
		$payment['reference'] = $number;
		$terms                = call_user_func( $s, 'payment_terms' );
		if ( $paid ) {
			$title = (string) $order->get_payment_method_title();
			$date  = $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d' ) : '';
			/* translators: 1: payment method title, 2: payment date */
			$terms = trim( sprintf( __( 'Paid via %1$s %2$s', 'w-system-e-invoice' ), $title, $date ) );
		}
		if ( '' !== $terms ) {
			$payment['terms'] = $terms;
		}

		$delivered = $order->get_date_completed() ? $order->get_date_completed() : ( $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created() );
		$buyer_ref = trim( (string) $order->get_meta( '_wsei_buyer_reference' ) );

		$body = array(
			'format'          => $format ? $format : call_user_func( $s, 'format' ),
			'invoice_number'  => $number,
			'issue_date'      => current_time( 'Y-m-d' ),
			'delivery_date'   => $delivered ? $delivered->date_i18n( 'Y-m-d' ) : current_time( 'Y-m-d' ),
			'currency'        => (string) $order->get_currency(),
			'buyer_reference' => '' !== $buyer_ref ? $buyer_ref : (string) $order->get_order_number(),
			'order_reference' => (string) $order->get_order_number(),
			'seller'          => $seller,
			'buyer'           => array_filter( $buyer, 'strlen' ),
			'lines'           => $lines,
			'payment'         => $payment,
		);
		if ( ! empty( $vat_amounts ) ) {
			$body['vat_amounts'] = $vat_amounts;
		}
		if ( $paid ) {
			$body['prepaid_amount'] = round( (float) $order->get_total(), 2 );
		}
		if ( $small ) {
			$body['vat_exemption_reason'] = array( 'E' => __( 'No VAT charged under the small business scheme (section 19 UStG).', 'w-system-e-invoice' ) );
		}
		/**
		 * Filters the request body sent to POST /einvoice/create.
		 *
		 * @param array    $body  Body.
		 * @param WC_Order $order Order.
		 */
		return apply_filters( 'wsei_invoice_body', $body, $order );
	}
}
