<?php
/**
 * Test file with various WooCommerce hooks to test enhanced detection
 */

// Standard WooCommerce action hooks
add_action('woocommerce_init', 'my_woo_init');
add_action('woocommerce_before_checkout_form', 'my_checkout_form');
add_action('woocommerce_after_add_to_cart_button', 'my_add_to_cart');

// WooCommerce filter hooks
add_filter('woocommerce_product_tabs', 'my_product_tabs');
add_filter('woocommerce_checkout_fields', 'my_checkout_fields');

// Cart related hooks
add_action('woocommerce_before_cart', 'my_before_cart');
add_filter('woocommerce_cart_item_name', 'my_cart_item_name');

// Order related hooks
add_action('woocommerce_new_order', 'my_new_order');
add_filter('woocommerce_order_status_changed', 'my_order_status');

// Billing related hooks
add_filter('woocommerce_billing_fields', 'my_billing_fields');

// Gateway related hooks
add_filter('woocommerce_gateway_icon', 'my_gateway_icon');

// Some functions
function my_woo_init() {
    // Initialize WooCommerce customizations
}

function my_checkout_form() {
    echo '<div>Custom checkout message</div>';
}

function my_add_to_cart() {
    echo '<div>Custom add to cart message</div>';
}

function my_product_tabs($tabs) {
    return $tabs;
}

function my_checkout_fields($fields) {
    return $fields;
}

function my_before_cart() {
    echo '<div>Before cart message</div>';
}

function my_cart_item_name($name) {
    return $name;
}

function my_new_order($order_id) {
    // Handle new order
}

function my_order_status($order_id) {
    // Handle order status change
}

function my_billing_fields($fields) {
    return $fields;
}

function my_gateway_icon($icon) {
    return $icon;
}
