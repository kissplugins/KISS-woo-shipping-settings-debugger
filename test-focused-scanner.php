<?php
/**
 * Test file to verify the focused scanner works correctly
 * This file contains examples of geographical and payment method restrictions
 */

// Geographical restrictions example
add_filter('woocommerce_package_rates', function($rates, $package) {
    $destination_country = $package['destination']['country'];
    $destination_state = $package['destination']['state'];
    
    // Remove free shipping for certain states
    if ($destination_country === 'US' && in_array($destination_state, ['CA', 'NY'])) {
        foreach ($rates as $rate_id => $rate) {
            if (strpos($rate_id, 'free_shipping') !== false) {
                unset($rates[$rate_id]);
            }
        }
    }
    
    return $rates;
}, 10, 2);

// Payment method restrictions example
add_filter('woocommerce_available_payment_gateways', function($gateways) {
    global $woocommerce;
    
    $shipping_country = $woocommerce->customer->get_shipping_country();
    
    // Disable American Express for certain countries
    if ($shipping_country === 'CA' && isset($gateways['american_express'])) {
        unset($gateways['american_express']);
    }
    
    return $gateways;
});

// Checkout validation based on location
add_action('woocommerce_checkout_process', function() {
    $billing_country = $_POST['billing_country'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Block certain payment methods for specific countries
    if ($billing_country === 'US' && $payment_method === 'paypal') {
        wc_add_notice('PayPal is not available for US customers.', 'error');
    }
});

// Example that should NOT be detected (general WooCommerce hook without geographical/payment focus)
add_action('woocommerce_before_shop_loop', function() {
    echo '<div class="custom-message">Welcome to our shop!</div>';
});

// Example that should NOT be detected (cart fee without geographical/payment context)
add_action('woocommerce_cart_calculate_fees', function() {
    WC()->cart->add_fee('Processing Fee', 5);
});
