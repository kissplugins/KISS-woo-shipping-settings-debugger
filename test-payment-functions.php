<?php
/**
 * Test file for payment-related functions that should be detected by the scanner
 */

// Example 1: Payment gateway filtering
add_filter('woocommerce_available_payment_gateways', 'restrict_payment_gateways_for_kratom');
function restrict_payment_gateways_for_kratom($gateways) {
    $cart = WC()->cart->get_cart();
    
    foreach ($cart as $cart_item) {
        $product_id = $cart_item['product_id'];
        if (has_term('kratom', 'product_cat', $product_id)) {
            // Remove American Express
            unset($gateways['stripe_amex']);
            break;
        }
    }
    
    return $gateways;
}

// Example 2: Checkout payment notice (the function you mentioned)
function add_notice_for_kratom_products_payment() {
    $cart = WC()->cart->get_cart();

    foreach ( $cart as $cart_item ) {
        $product_id = $cart_item['product_id'];
        if ( has_term( 'kratom', 'product_cat', $product_id ) ) {
            echo '<div class="woocommerce-info">' . __('American Express cards are not allowed for Kratom products.', 'binoid') . '</div>';
            break;
        }
    }
}
add_action('neo_before_checkout_payment', 'add_notice_for_kratom_products_payment');

// Example 3: Payment method title modification
add_filter('woocommerce_gateway_title', 'modify_payment_title_for_special_products', 10, 2);
function modify_payment_title_for_special_products($title, $gateway_id) {
    if ($gateway_id === 'stripe' && has_kratom_in_cart()) {
        return $title . ' (Restricted for some products)';
    }
    return $title;
}

// Example 4: Payment processing hook
add_action('woocommerce_checkout_process', 'validate_payment_for_restricted_products');
function validate_payment_for_restricted_products() {
    $chosen_payment_method = WC()->session->get('chosen_payment_method');
    
    if ($chosen_payment_method === 'stripe_amex') {
        $cart = WC()->cart->get_cart();
        foreach ($cart as $cart_item) {
            if (has_term('kratom', 'product_cat', $cart_item['product_id'])) {
                wc_add_notice('American Express is not allowed for Kratom products.', 'error');
                break;
            }
        }
    }
}

// Example 5: Custom payment hook
add_action('custom_before_payment_section', 'show_payment_restrictions');
function show_payment_restrictions() {
    echo '<div class="payment-notice">Some payment methods may be restricted based on your cart contents.</div>';
}

function has_kratom_in_cart() {
    $cart = WC()->cart->get_cart();
    foreach ($cart as $cart_item) {
        if (has_term('kratom', 'product_cat', $cart_item['product_id'])) {
            return true;
        }
    }
    return false;
}
