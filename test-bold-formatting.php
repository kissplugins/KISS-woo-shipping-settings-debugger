<?php
// Test file to verify bold formatting in scanner output
function test_kratom_restrictions() {
    $errors = new WP_Error();
    $errors->add('shipping_error', 'We cannot ship Kratom to Alabama, Arkansas, Indiana.');
    $errors->add('payment_error', 'THC-A products are restricted in California.');
}
