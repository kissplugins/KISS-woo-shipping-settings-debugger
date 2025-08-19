<?php
/**
 * Test file to verify our improvements to the scanner
 * This should now detect product-based restrictions that were previously filtered out
 */

// Test 1: Kratom product restriction (should be detected now)
add_filter('woocommerce_package_rates', function($rates, $package) {
    $cart = WC()->cart->get_cart();
    
    foreach ($cart as $cart_item) {
        $product_id = $cart_item['product_id'];
        if (has_term('kratom', 'product_cat', $product_id)) {
            // Remove free shipping for Kratom products
            foreach ($rates as $rate_id => $rate) {
                if (strpos($rate_id, 'free_shipping') !== false) {
                    unset($rates[$rate_id]);
                }
            }
            break;
        }
    }
    
    return $rates;
}, 10, 2);

// Test 2: Amanita Mushroom restriction (should be detected now)
add_action('woocommerce_checkout_process', function() {
    $cart = WC()->cart->get_cart();
    
    foreach ($cart as $cart_item) {
        $product_id = $cart_item['product_id'];
        if (has_term('amanita-mushroom', 'product_cat', $product_id)) {
            global $woocommerce;
            $woocommerce->add_error('Amanita Mushroom products cannot be shipped to California.');
        }
    }
});

// Test 3: THC-A product payment restriction (should be detected now)
add_filter('woocommerce_available_payment_gateways', function($gateways) {
    $cart = WC()->cart->get_cart();
    
    foreach ($cart as $cart_item) {
        $product_id = $cart_item['product_id'];
        if (has_term('thc-a', 'product_cat', $product_id)) {
            // Remove American Express for THC-A products
            unset($gateways['stripe_amex']);
            break;
        }
    }
    
    return $gateways;
});

// Test 4: State-based restriction (should definitely be detected)
add_filter('woocommerce_package_rates', function($rates, $package) {
    $destination_state = $package['destination']['state'];
    
    if (in_array($destination_state, ['CA', 'NY', 'TX'])) {
        foreach ($rates as $rate_id => $rate) {
            if (strpos($rate_id, 'express_shipping') !== false) {
                unset($rates[$rate_id]);
            }
        }
    }
    
    return $rates;
}, 10, 2);
