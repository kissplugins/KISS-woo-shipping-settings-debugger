<?php
/**
 * This file contains a collection of shipping-related code snippets intended for use
 * with the KISS Woo Shipping Debugger's self-test functionality. It is not meant to be
 * activated as a standalone plugin but rather to be scanned by the main debugger tool.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// --- Test Case Functions ---

/**
 * Scenario 1: A class that handles complex shipping modifications.
 */
class KISS_WST_Test_Cases {

    public function __construct() {
        // These hooks are for demonstration and are not active unless this file is included by a test runner.
        add_action( 'woocommerce_checkout_process', [ $this, 'state_based_restrictions' ] );
        add_filter( 'woocommerce_package_rates', [ $this, 'modify_shipping_rates' ], 10, 2 );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_handling_fee' ] );
    }

    /**
     * Restrict shipping of certain products to specific states.
     */
    public function state_based_restrictions() {
        $shipping_country = 'US'; // Mock data for testing
        $shipping_state = 'CA';   // Mock data for testing

        if ( $shipping_country === 'US' && $shipping_state === 'CA' ) {
            if ( has_term( 'restricted-product', 'product_cat' ) ) {
                wc_add_notice( 'We cannot ship Restricted Products to <strong>California</strong>.', 'error' );
            }
        }
        
        if ($shipping_state === 'NY') {
            wc_add_notice( 'Shipping to <strong>New York</strong> is currently unavailable for all items.', 'error' );
        }
    }

    /**
     * Modify available shipping rates based on cart contents.
     */
    public function modify_shipping_rates( $rates, $package ) {
        // Unset a specific flat rate.
        if ( isset( $rates['flat_rate:1'] ) ) {
            unset( $rates['flat_rate:1'] );
        }

        $has_heavy_item = false;
        foreach ($package['contents'] as $item) {
            if ($item['data']->get_weight() > 50) {
                $has_heavy_item = true;
                break;
            }
        }

        // Add a new rate if a heavy item is in the cart.
        if ($has_heavy_item) {
            $new_rate = new WC_Shipping_Rate( 'heavy_item_surcharge', 'Heavy Item Surcharge', 25.00 );
            $rates['heavy_item_surcharge'] = $new_rate;
        }
        
        return $rates;
    }

    /**
     * Add a fee for special products.
     */
    public function add_handling_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $special_product_in_cart = false;
        foreach ( $cart->get_cart() as $cart_item ) {
            if ( has_term( 'fragile', 'product_tag', $cart_item['product_id'] ) ) {
                $special_product_in_cart = true;
                break;
            }
        }

        if ( $special_product_in_cart ) {
            $cart->add_fee( 'Fragile Item Handling Fee', 7.50 );
        }
    }
}


/**
 * Scenario 4: A simple procedural function to add a checkout error.
 */
function kiss_wst_simple_checkout_validation() {
    $postcode = '90210'; // Mock data
    $restricted_postcodes = ['90210', '10001'];
    if (in_array($postcode, $restricted_postcodes)) {
        wc_add_notice( 'We do not ship to your postcode.', 'error');
    }
}
add_action('woocommerce_after_checkout_validation', 'kiss_wst_simple_checkout_validation');