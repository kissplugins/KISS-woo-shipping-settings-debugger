<?php
/**
 * Very simple test to check if PHP is working
 */

echo "PHP is working\n";
echo "Current time: " . date('Y-m-d H:i:s') . "\n";

// Test if we can include the main plugin file
try {
    echo "Attempting to include main plugin file...\n";
    include_once 'kiss-woo-shipping-settings-debugger.php';
    echo "Main plugin file included successfully\n";
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}

echo "Test completed\n";
