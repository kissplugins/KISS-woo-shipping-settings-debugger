<?php
/**
 * Simple debug test to check WordPress loading
 */

// Load WordPress
require_once '../../../wp-load.php';

echo "WordPress loaded: " . (defined('ABSPATH') ? 'YES' : 'NO') . "\n";
echo "add_action available: " . (function_exists('add_action') ? 'YES' : 'NO') . "\n";
echo "wp_send_json_success available: " . (function_exists('wp_send_json_success') ? 'YES' : 'NO') . "\n";

// Check if our plugin class exists
echo "KISS_WSE_Debugger class exists: " . (class_exists('KISS_WSE_Debugger') ? 'YES' : 'NO') . "\n";

// Check if our functions exist
echo "kiss_wse_test_ajax_callback function exists: " . (function_exists('kiss_wse_test_ajax_callback') ? 'YES' : 'NO') . "\n";

// Check if AJAX actions are registered
global $wp_filter;
$ajax_actions = [];
if (isset($wp_filter['wp_ajax_kiss_wse_test_ajax'])) {
    $ajax_actions[] = 'kiss_wse_test_ajax';
}
if (isset($wp_filter['wp_ajax_kiss_wse_run_single_test'])) {
    $ajax_actions[] = 'kiss_wse_run_single_test';
}

echo "Registered AJAX actions: " . implode(', ', $ajax_actions) . "\n";

// Try to call the function directly
if (function_exists('kiss_wse_test_ajax_callback')) {
    echo "Attempting to call kiss_wse_test_ajax_callback directly...\n";
    try {
        ob_start();
        kiss_wse_test_ajax_callback();
        $output = ob_get_clean();
        echo "Direct call output: " . $output . "\n";
    } catch (Exception $e) {
        echo "Direct call error: " . $e->getMessage() . "\n";
    }
}
