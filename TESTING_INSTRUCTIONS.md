# Testing the Enhanced Scanner Debugging

## Important: Theme File Scanning

✅ **The plugin is correctly configured to scan files within your active WordPress theme directory.**

- Default file: `{active-theme}/inc/shipping-restrictions.php`
- Additional files: Any file you specify relative to your theme root (e.g., `inc/woo-functions.php`, `functions.php`)
- The `example-woo-functions.php` in the plugin directory is just temporary for testing

## What I've Added

I've enhanced the scanner with much better debugging to help you understand why the second file isn't showing results. Here's what's new:

### 1. Enhanced Debugging Output
- **Hook Statistics**: Shows total `add_action()` and `add_filter()` calls found
- **WooCommerce Hook Count**: Shows how many WooCommerce-specific hooks were detected
- **Detailed Pattern Breakdown**: Shows exactly which types of patterns were found

### 2. Improved Pattern Detection
- **Expanded WooCommerce Detection**: Now catches more WooCommerce-related patterns including:
  - Standard `woocommerce_*` and `wc_*` hooks
  - WooCommerce AJAX hooks (`wp_ajax_*` with WooCommerce-related names)
  - Theme-specific hooks (`shoptimizer_*`, `neo_*`)
  - Site-specific patterns (`binoid_*` functions)

### 3. Better User Feedback
- **Per-file Results**: Shows immediate feedback for each file scanned
- **Debug Information**: When no patterns are found, shows what was actually detected
- **Pattern Guide**: Expandable section showing what patterns the scanner looks for

## How to Test

### Option 1: Test Theme File Scanning (Recommended)
1. Go to your WordPress admin
2. Navigate to **WooCommerce > Shipping & Payment Debugger**
3. In the "Custom Rules Scanner" section, you'll see:
   - Your active theme name and directory path
   - Default file being scanned: `inc/shipping-restrictions.php` (if it exists)
4. To test with an additional theme file, enter a path like:
   - `functions.php` (your theme's main functions file)
   - `inc/woo-functions.php` (if you have this file in your theme)
   - `woocommerce/functions.php` (if you have WooCommerce customizations)
5. Click "Scan for Custom Rules"
6. You should now see much more detailed output showing:
   - Your active theme information
   - How many files are being scanned from your theme
   - Statistics for each file (even if no specific patterns are found)
   - Debug information about what was detected

### Option 2: Test with the Temporary Example File
1. While `example-woo-functions.php` is still in the plugin directory
2. Enter: `example-woo-functions.php` in the scanner
3. This will test the enhanced debugging with a file that has many WooCommerce hooks



## What You Should See

Based on your `example-woo-functions.php` file, the scanner should now show:

- **Total add_action() calls**: ~50+ (I counted 76 total hooks in your file)
- **Total add_filter() calls**: ~25+
- **WooCommerce hooks**: Many (all the `woocommerce_*` hooks in your file)
- **General WooCommerce Hooks**: Should detect many patterns now

## Why the Original Scanner Showed "No Results"

The original scanner was very specific - it only looked for shipping and payment-related patterns like:
- `woocommerce_package_rates` filters
- `woocommerce_cart_calculate_fees` actions
- Shipping rate modifications
- Payment gateway restrictions

Your `example-woo-functions.php` file contains lots of WooCommerce code, but it's mostly:
- Product display customizations
- Cart/checkout UI enhancements  
- Analytics tracking
- General WooCommerce functionality

These are valid WooCommerce customizations, but they're not specifically about shipping rates or payment restrictions, which is what the scanner was designed to find.

## The Fix

Now the scanner will:
1. ✅ Show you that it's successfully scanning your file
2. ✅ Display statistics about what it found (even if not shipping-related)
3. ✅ Explain why certain patterns weren't detected
4. ✅ Give you confidence that the scanner is working properly

## Next Steps

After testing, you should see that the scanner is working correctly - it's just being selective about what it reports as "shipping and payment related." The enhanced debugging will make this much clearer.

If you want the scanner to report on more general WooCommerce patterns, we could adjust the criteria, but the current focus on shipping/payment patterns is probably more useful for debugging shipping issues.

## Theme File Scanning Capabilities

The plugin is designed to scan files within your **active WordPress theme**, not the plugin directory:

### Default Scanning
- **Automatic**: `{your-theme}/inc/shipping-restrictions.php` (if it exists)
- **Theme Detection**: Shows your active theme name and directory path
- **Security**: Only scans files within your theme directory (prevents directory traversal)

### Additional File Scanning
You can scan any PHP file in your theme by entering its relative path:
- ✅ `functions.php` - Your theme's main functions file
- ✅ `inc/woocommerce.php` - Custom WooCommerce functions
- ✅ `lib/shipping-rules.php` - Custom shipping logic
- ✅ `woocommerce/cart-functions.php` - WooCommerce customizations
- ❌ `../other-theme/file.php` - Security: Can't scan outside your theme
- ❌ `/absolute/path/file.php` - Security: Only relative paths allowed

### File Requirements
- ✅ Must be `.php` files
- ✅ Must be under 1MB (configurable via filter)
- ✅ Must be valid PHP (will show parse errors if not)
- ✅ Must be within your active theme directory

This ensures the scanner focuses on **your theme's customizations** where shipping and payment logic is typically implemented.
