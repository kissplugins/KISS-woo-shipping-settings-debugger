<?php
/**
 * Quick verification script to test our scanner improvements
 */

// Include WordPress if not already loaded
if (!defined('ABSPATH')) {
    // This would need to be adjusted based on the actual WordPress path
    require_once('../../../wp-load.php');
}

// Initialize the debugger
if (!class_exists('KISS_WSE_Debugger')) {
    require_once('kiss-woo-shipping-settings-debugger.php');
}

// Create an instance of the debugger
$debugger = new KISS_WSE_Debugger();

echo "<h2>Testing Scanner Improvements</h2>\n";

// Test our improvements file
$test_file = __DIR__ . '/test-improvements.php';
if (file_exists($test_file)) {
    echo "<h3>Scanning test-improvements.php</h3>\n";
    $output = $debugger->scan_single_file_for_test($test_file);
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
    echo $output;
    echo "</div>\n";
} else {
    echo "<p>Test file not found: $test_file</p>\n";
}

// Test the existing payment functions file
$payment_test_file = __DIR__ . '/test-payment-functions.php';
if (file_exists($payment_test_file)) {
    echo "<h3>Scanning test-payment-functions.php</h3>\n";
    $output = $debugger->scan_single_file_for_test($payment_test_file);
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
    echo $output;
    echo "</div>\n";
} else {
    echo "<p>Payment test file not found: $payment_test_file</p>\n";
}

// Test the focused scanner file
$focused_test_file = __DIR__ . '/test-focused-scanner.php';
if (file_exists($focused_test_file)) {
    echo "<h3>Scanning test-focused-scanner.php</h3>\n";
    $output = $debugger->scan_single_file_for_test($focused_test_file);
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
    echo $output;
    echo "</div>\n";
} else {
    echo "<p>Focused test file not found: $focused_test_file</p>\n";
}

echo "<h3>Summary</h3>\n";
echo "<p>If the improvements are working correctly, you should see:</p>\n";
echo "<ul>\n";
echo "<li><strong>Kratom</strong> product restrictions being detected and displayed</li>\n";
echo "<li><strong>Amanita Mushroom</strong> restrictions being detected</li>\n";
echo "<li><strong>THC-A</strong> payment restrictions being detected</li>\n";
echo "<li>State names like <strong>California</strong>, <strong>New York</strong>, <strong>Texas</strong> being bolded</li>\n";
echo "<li>Product names being bolded in restriction messages</li>\n";
echo "</ul>\n";
