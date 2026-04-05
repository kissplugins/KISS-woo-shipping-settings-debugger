<?php
/**
 * TEMPORARY: Debug shipping rate filter chain
 * Logs $rates array at each priority stage of woocommerce_package_rates
 * Check wp-content/debug.log for [SHIPPING TRACE] entries
 *
 * DELETE THIS FILE WHEN DONE DEBUGGING
 */

// Priority 8: before role-based-methods (pri 9)
add_filter('woocommerce_package_rates', function($rates, $package) {
    $rate_keys = [];
    foreach ($rates as $key => $rate) {
        $rate_keys[] = $key . ' (' . $rate->get_label() . ' $' . $rate->get_cost() . ')';
    }
    error_log('[SHIPPING TRACE] Pri 8 (BEFORE all filters): ' . implode(', ', $rate_keys));

    // Log cart total for context
    $cart_total = 0;
    foreach ($package['contents'] as $item) {
        $cart_total += (float) $item['line_total'];
    }
    error_log('[SHIPPING TRACE] Package cart total: $' . number_format($cart_total, 2));

    return $rates;
}, 8, 2);

// Priority 10 (earliest at this level — mu-plugins load before plugins/themes):
// This runs AFTER role-based (pri 9) but BEFORE CSP and theme (also pri 10, registered later)
add_filter('woocommerce_package_rates', function($rates, $package) {
    $rate_keys = [];
    foreach ($rates as $key => $rate) {
        $rate_keys[] = $key . ' (' . $rate->get_label() . ' $' . $rate->get_cost() . ')';
    }
    error_log('[SHIPPING TRACE] Pri 10-early (AFTER role-based pri9, BEFORE CSP/theme pri10): ' . implode(', ', $rate_keys));
    if (empty($rates)) {
        error_log('[SHIPPING TRACE] *** ALL RATES STRIPPED BY ROLE-BASED PLUGIN (pri 9) ***');
    }
    return $rates;
}, 10, 2);

// Priority 11: after all pri-10 filters (CSP + theme)
add_filter('woocommerce_package_rates', function($rates, $package) {
    $rate_keys = [];
    foreach ($rates as $key => $rate) {
        $rate_keys[] = $key . ' (' . $rate->get_label() . ' $' . $rate->get_cost() . ')';
    }
    error_log('[SHIPPING TRACE] Pri 11 (AFTER all filters): ' . implode(', ', $rate_keys));
    if (empty($rates)) {
        error_log('[SHIPPING TRACE] *** BUG CONFIRMED: ZERO RATES RETURNED ***');
    }
    return $rates;
}, 11, 2);
