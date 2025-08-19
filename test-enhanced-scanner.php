<?php
/**
 * Test script to verify the enhanced scanner can detect payment-related functions
 */

// Include the necessary files
require_once __DIR__ . '/kiss-woo-shipping-settings-debugger.php';

// Create a test instance
$debugger = new KISS_WSE_Debugger();

// Test the scanner on our test file
$test_file = __DIR__ . '/test-payment-functions.php';

if (file_exists($test_file)) {
    echo "<h2>Testing Enhanced Scanner on Payment Functions</h2>\n";
    echo "<p>Scanning file: " . basename($test_file) . "</p>\n";
    
    try {
        $result = $debugger->scan_single_file_for_test($test_file);
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>\n";
        echo "<h3>Scanner Results:</h3>\n";
        echo $result;
        echo "</div>\n";
    } catch (Exception $e) {
        echo "<div style='border: 1px solid #f00; padding: 10px; margin: 10px 0; background: #fee;'>\n";
        echo "<h3>Error:</h3>\n";
        echo "<p>" . esc_html($e->getMessage()) . "</p>\n";
        echo "</div>\n";
    }
} else {
    echo "<p>Test file not found: $test_file</p>\n";
}
