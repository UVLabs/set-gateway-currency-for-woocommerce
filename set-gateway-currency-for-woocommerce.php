<?php
/*
Plugin Name: Set Gateway Currency For WooCommerce
Plugin URI: https://uriahsvictor.com
Description: Send the USD equivalent of an XCD amount to payment for processing.
Version: 1.0.0
Author: Uriahs Victor
Author URI: https://uriahsvictor.com
Requires PHP: 7.2
License: GPLv2
*/

/***
 * 
 * This plugin fullfils a particular purpose: 
 * 
 * It displays a desired currency code on the front end and sends a desired currency conversion 
 * amount to the payment gateway.
 * 
 * The currency sent to the payment gateway will still be what is set in WooCommerce->General->Currency options->Currency
 *
 * This can be useful if you want users to browse your website in a local currency that isn't supported by a payment gateway example Paypal
 * but at checkout convert and send the amount to the gateway in a supported currency.
 * 
 * In the code below we're showing the site currency as XCD even though its actually in USD. At checkout we're making the conversion from XCD -> USD and sending that amount to Paypal for processing.
 * 
 * 
 * TODO - Test Taxes - We plugin hasn't been tested with different tax setups.
 * TODO - Get currency conversions from a Currency API and save the rates used for the order so that if a refund occurs we can calculate the correct amount that was refunded based on the rates of day when the order was created.
 * TODO - Full refunds have the email subject "Partial refund" due to the fact that the USD amount refunded is different from what we have saved in the DB. See WC_Email_Customer_Refunded_Order class.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Show our desired currency code.
 * 
 * @param string $format 
 * @param string $currency_pos 
 * @return string 
 */
function uv_add_price_prefix( string $format, string $currency_pos ) : string {
	switch ( $currency_pos ) {
		case 'left':
			$format = '%1$s%2$s&nbsp;' . "<span id='uv-ccode'>XCD</span>"; // Show XCD even though we're actually using USD
			break;
	}

	return $format;
}

add_action( 'woocommerce_price_format', 'uv_add_price_prefix', 1, 2 );

/**
 * 
 * Gets the USD equivalent of an amount.
 * 
 * @param float $amount 
 * @return float 
 */
function uv_get_amount_in_usd( float $amount ) {
	$converted = $amount * 0.369787; // TODO Get rate dynamically from a currency conversion API
	 // Format to 2 decimal places
	$converted_formatted = number_format( $converted, 2, '.', ',' );
	return $converted_formatted;
}

/**
 * 
 * Gets the XCD equivalent of an amount.
 * 
 * @param float $amount 
 * @return float 
 */
function uv_get_amount_in_xcd( float $amount ) {
	$converted = $amount * 2.70426; // TODO Get rate dynamically from a currency conversion API
	// Format to 2 decimal places
	$converted_formatted = number_format( $converted, 2, '.', ',' );
	return $converted_formatted;
}

/**
 * Show the customer the amount they'd be paying in USD.
 * 
 * @return void 
 */
function uv_show_usd_total_at_checkout() : void { 

	$total  = WC()->cart->get_cart_contents_total();
	$total  = uv_get_amount_in_usd( $total );
	$markup = <<<HTML
	
	<div style='text-align: center'>
	<p>
		<span style="font-weight: 800">Total in USD:  $<span id="uv-usd-total">$total</span></span> <br>
		<small><em>You will be billed in USD when checking out with PayPal.</em></small>
	<p>
	
	</div>
	
	HTML;

	echo $markup;
}
add_action( 'woocommerce_review_order_before_payment', 'uv_show_usd_total_at_checkout' );

/**
 * Set the total that should be passed to the gateway.
 * 
 * @param object $order 
 * @param array  $data 
 * @return void 
 */
function uv_set_total_for_gateway( object $order, array $order_data ) : void {

	$total = $order->get_total();

	// Set a WC session variable
	WC()->session->set( 'uv_original_total', $total );

	// Convert our total to the what we want to pass to the Payment gateway
	$converted_total = uv_get_amount_in_usd( $total ); // Set your currency conversion rate here as well.

	// Also save the converted total to the session
	WC()->session->set( 'uv_converted_total', $converted_total );

	// Set the new calculated total for processing by the payment gateway
	$order->set_total( $converted_total );
}
add_action( 'woocommerce_checkout_create_order', 'uv_set_total_for_gateway', 9999, 2 );

/**
 * Save the order totals to the DB.
 * 
 * Both the original total and the converted total needs to be saved for later retrieval and processing.
 * 
 * @param int   $order_id 
 * @param array $order_data 
 * @return void 
 */
function uv_save_order_totals( int $order_id, array $order_data ) : void {

	// Get our original total session values
	$original_total  = WC()->session->get( 'uv_original_total' );
	$converted_total = WC()->session->get( 'uv_converted_total' );

	// We need to save the original total at this point so that we can grab it during sending of emails and on thank you page
	update_post_meta( $order_id, '_uv_original_order_total', sanitize_text_field( $original_total ) );

	// Save the converted total to the order details as well
	update_post_meta( $order_id, '_uv_converted_order_total', sanitize_text_field( $converted_total ) );

}
add_action( 'woocommerce_checkout_update_order_meta', 'uv_save_order_totals', 9999, 2 );

/**
 * Update the order total back to the desired currency amount.
 * 
 * We do this so that the total shown on the store will always be in our desired currency(XCD)
 * 
 * @param int $order_id 
 * @return void 
 */
function uv_update_order_total( int $order_id ) : void {

	// Get the original total 
	$original_total = get_post_meta( $order_id, '_uv_original_order_total', true );
	
	// Set the order total back to the original one
	update_post_meta( $order_id, '_order_total', $original_total );

}
add_action( 'woocommerce_thankyou', 'uv_update_order_total', 9999, 1 );

/**
 * Change the total shown in the order email to be our desired currency(XCD).
 * 
 * @param array  $total_rows 
 * @param object $class_instance 
 * @param string $tax_display 
 * @return array 
 */
function uv_change_order_email_total( array $total_rows, object $class_instance, string $tax_display ) : array {

	$is_refund  = false;
	$refund_key = '';

	foreach ( $total_rows as $row => $row_details ) {
		if ( strpos( $row, 'refund' ) !== false ) {
			$is_refund  = true;
			$refund_key = $row;
			break;
		}
	}

	$order_id = $class_instance->get_id();
	// Grab the original order total that we started with
	$original_order_total = get_post_meta( $order_id, '_uv_original_order_total', true );
	// Show it in the email instead of the converted value.
	$total_rows['order_total']['value'] = wc_price( $original_order_total );

	// If this is a refund lets alter the total a bit.
	if ( $is_refund ) {

		preg_match( '/<\/span>(.*?)<span id/', $total_rows['order_total']['value'], $total_matches );
		preg_match( '/<\/span>(.*?)<span id/', $total_rows[ $refund_key ]['value'], $refund_matches );

		$total = '';

		if ( $total_matches ) {
			$match = $total_matches[1];
			$total = preg_replace( '/[^0-9.,]/', '', $match );
		}

		if ( $refund_matches ) {
			$match  = $refund_matches[1];
			$refund = preg_replace( '/[^0-9.,]/', '', $match );
		}
		
		$total_sub_refund = (float) $total - (float) $refund;
		$total_sub_refund = number_format( $total_sub_refund, 2, '.', ',' );

		// Replace the total with the refund subtracted
		$total_rows['order_total']['value'] = str_replace( $total, $total_sub_refund, $total_rows['order_total']['value'] );

	}

	return $total_rows;
}
add_filter( 'woocommerce_get_order_item_totals', 'uv_change_order_email_total', 9999, 3 );

/**
 * Change the "Total" shown on the order received page to our desired currency (XCD).
 * 
 * The _order_total meta gets set on the woocommerce_thankyou hook which runs AFTER the page is loaded. 
 * In this function we're making sure that the correct value shows on page load.
 * 
 * @param string $formatted_total 
 * @param object $class_instance 
 * @param string $tax_display 
 * @param bool   $display_refunded 
 * @return string 
 */
function uv_change_order_received_total( string $formatted_total, object $class_instance, string $tax_display, bool $display_refunded ) {

	// We only want this code to run on Thank You page.
	if ( ! is_checkout() && empty( is_wc_endpoint_url( 'order-received' ) ) ) {
		return;
	}

	$order_id = $class_instance->get_id();

	// Get our original total we saved during the order
	$original_total = get_post_meta( $order_id, '_uv_original_order_total', true );

	// Find the converted total inside the formatted total string
	preg_match( '/<\/span>(.*?)<span id/', $formatted_total, $matches );

	if ( $matches ) {
		$match   = $matches[1];
		$cleaned = preg_replace( '/[^0-9.,]/', '', $match );
	}

	// Replace the converted total with the original total
	$replaced = str_replace( $cleaned, $original_total, $formatted_total );

	return $replaced;
}
add_filter( 'woocommerce_get_formatted_order_total', 'uv_change_order_received_total', 9999, 4 );

/**
 * 
 * Add extra field to display the converted total to the admin in dashboard.
 * 
 * @param int $order_id 
 * @return void 
 */
function uv_show_admin_converted_total_order_details( int $order_id ) {

	$converted_amount           = get_post_meta( $order_id, '_uv_converted_order_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	// Since we're filtering wc_price via the uv_add_price_prefix function we need to change our currency code back.
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <tr>
    <td class="label">Amount Paid in USD:</td>
    <td width="1%"></td>
    <td class="total">
        $converted_amount_formatted
    </td>
    </tr>
HTML;

	echo $markup;

}
add_action( 'woocommerce_admin_order_totals_after_total', 'uv_show_admin_converted_total_order_details' );

/**
 * 
 * Add extra field to display the converted total to customer when they view a past order.
 * 
 * @param object $order 
 * @return void 
 */
function uv_show_customer_converted_total_order_details( object $order ): void {

	$order_id                   = $order->get_id();
	$converted_amount           = get_post_meta( $order_id, '_uv_converted_order_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	// Since we're filtering wc_price via the uv_add_price_prefix function we need to change our currency code back.
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <p style="text-align: right"><strong>Amount Paid in USD:</strong> $converted_amount_formatted</p>
HTML;

	echo $markup;
}
add_action( 'woocommerce_order_details_after_order_table', 'uv_show_customer_converted_total_order_details' );

/**
 * 
 * Add the converted total to the order email.
 * 
 * @param object $order 
 * @param bool   $sent_to_admin 
 * @param bool   $plain_text 
 * @param object $email 
 * @return void 
 */
function uv_add_converted_total_to_email( object $order, bool $sent_to_admin, bool $plain_text, object $email ): void {

	$order_id                   = $order->get_id();
	$converted_amount           = get_post_meta( $order_id, '_uv_converted_order_total', true );
	$converted_amount_formatted = wc_price( $converted_amount );
	$converted_amount_formatted = str_replace( 'XCD', 'USD', $converted_amount_formatted );
	
	$markup = <<<HTML
    <p><strong>Amount Paid in USD:</strong> $converted_amount_formatted</p>
HTML;

	echo $markup;

}
add_action( 'woocommerce_email_after_order_table', 'uv_add_converted_total_to_email', 9999, 4 );

/**
 * Check if the WooCommerce Analytics table exists.
 *
 * @return bool 
 */
function uv_check_wc_analytics_table() : bool {

	global $wpdb;
	$table_name = $wpdb->prefix . 'wc_order_stats';
	
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
		return false;
	}

	return true;
}

/**
 * 
 * Update the WooCommerce Analytics table with default currency amounts (XCD)
 * 
 * @param int   $refund_id 
 * @param float $negative_total 
 * @return void 
 */
function uv_update_wc_analytics_table( int $refund_id, float $negative_total ) {

	$wc_analytics = uv_check_wc_analytics_table();

	if ( ! $wc_analytics ) {
		return;
	}

	// Maybe there's a more eligant built-in way to update this table?
	global $wpdb;

	$table        = $wpdb->prefix . 'wc_order_stats';
	$data         = array(
		'total_sales' => $negative_total,
		'net_total'   => $negative_total, 
	);
	$where        = array( 
		'order_id' => $refund_id,
	);
	$format       = array(
		'%f',
		'%f',
	);
	$where_format = array(
		'%d',
	);

	$wpdb->update( $table, $data, $where, $format, $where_format );

}

/**
 * 
 * Clear the WooCommerce analytics cache when order details are updated.
 * 
 * @return void 
 */
function uv_clear_wc_analytics_cache() : void {

	if ( ! class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Cache' ) ) {
		return;
	}

	$cache = new \Automattic\WooCommerce\Admin\API\Reports\Cache();
	$cache::invalidate();

}

/**
 * 
 * Handle Partial refunds performed by gateway.
 * 
 * @param int $order_id 
 * @param int $refund_id 
 * @return void 
 */
function uv_handle_partial_refunds( int $order_id, int $refund_id ) : void {

	$refunded_amount = (float) get_post_meta( $refund_id, '_refund_amount', true );

	$original_refund_amount         = uv_get_amount_in_xcd( $refunded_amount );
	$negative_original_refund_total = $original_refund_amount * -1;

	// Update the refund details with the correct values 
	update_post_meta( $refund_id, '_refund_amount', $original_refund_amount );
	update_post_meta( $refund_id, '_order_total', $negative_original_refund_total );

	// Update the WooCommerce Analytics table
	uv_update_wc_analytics_table( $refund_id, $negative_original_refund_total );

	// Clear the WooCommerce Analytics cache
	uv_clear_wc_analytics_cache();

}
add_action( 'woocommerce_order_partially_refunded', 'uv_handle_partial_refunds', 10, 2 );

/**
 * 
 * Handle Full refunds performed by gateway.
 * 
 * @param int $order_id 
 * @param int $refund_id 
 * @return void 
 */
function uv_handle_full_refunds( int $order_id, int $refund_id ) : void {

	$original_order_total = get_post_meta( $order_id, '_uv_original_order_total', true );
	$negative_total       = $original_order_total * -1;

	// Update the refund details with the correct values 
	update_post_meta( $refund_id, '_refund_amount', $original_order_total );
	update_post_meta( $refund_id, '_order_total', $negative_total );

	// Update the WooCommerce Analytics table
	uv_update_wc_analytics_table( $refund_id, $negative_total );

	// Clear the WooCommerce Analytics cache
	uv_clear_wc_analytics_cache();

}
add_action( 'woocommerce_order_fully_refunded', 'uv_handle_full_refunds', 10, 2 );

/**
 * 
 * Handler for the ajax request fired when WooCommerce updates the checkout page.
 * 
 * @return void 
 */
function uv_wc_checkout_update_ajax_handler() : void {

	try {

		$total = WC()->cart->get_cart_contents_total();
		$total = uv_get_amount_in_usd( $total );
		wp_send_json_success( $total );

	} catch ( \Throwable $th ) {
		wp_send_json_error( false );
	}

}
add_action( 'wp_ajax_nopriv_uv_wc_checkout_updated', 'uv_wc_checkout_update_ajax_handler' );
add_action( 'wp_ajax_uv_wc_checkout_updated', 'uv_wc_checkout_update_ajax_handler' );

/**
 * Enqueue WP Util so we can make use of wp object in JavaScript.
 *
 * @return void 
 */
function uv_enqueue_wp_util() : void {
	wp_enqueue_script( 'wp-util' );
}
add_action( 'wp_enqueue_scripts', 'uv_enqueue_wp_util' );

/**
 * 
 * Update the converted price shown when the WooCommerce page is updated
 * 
 * @return void 
 */
function uv_handle_wc_checkout_updates() : void {

	$js = <<<JAVASCRIPT
	<script>
		(function ($) {
			'use strict';

            $(document).ready(
				function () {
					$(document.body).on('updated_checkout', () =>{

						wp.ajax.post( "uv_wc_checkout_updated", {} )
						.done(function(response) {
							
							$('span#uv-usd-total').text( response );

						})
						.fail(function(response) {

							// console.log(response);

						});

					});

				}
			);

		})(jQuery);
	</script>

JAVASCRIPT;
	echo $js;

}
add_action( 'wp_footer', 'uv_handle_wc_checkout_updates', 9999 );
