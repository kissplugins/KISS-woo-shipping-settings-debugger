# Changelog

## 2.3.2
Cover multiple Product bolding via new Regex functions
Self Test stable now

## 2.3.1
* **Fix:** Corrected a regression where the scanner would only scan the default file OR the additional file, but not both. It now correctly scans the default `inc/shipping-restrictions.php` file first, and then scans the user-provided additional file.
* **UX:** Clarified the instructional text for the "Scan Additional File" input field to be more precise about the expected path.

## 2.3.0
* **Feature:** Integrated a new self-test functionality directly into the plugin. This includes a `self-test.php` file with a variety of shipping logic examples for regression testing.
* **Enhancement:** Added a "Self-Test" page under the "WooCommerce" > "Shipping Debugger" menu to provide instructions and a one-click link to run the test scan.
* **Enhancement:** Added a "Self Test" link to the plugin's action links on the "All Plugins" page for quick access to the scanner with the self-test file pre-selected.
* **Enhancement:** The admin menu for the debugger is now located under the main "WooCommerce" menu for better organization.
* **Enhancement:** The scanner output now attempts to bold suspected product and state names within restriction messages to improve readability.

## 2.2.0
* **Enhancement:** Significantly improved the human-readability of the Custom Rules Scanner output.
  * The scanner now describes the conditions (`if` statements) that trigger each rule, providing crucial context. For example, it will now say a rule runs "when the state is 'CA'".
  * Placeholders for dynamic content in error messages are now simplified (e.g., `{restricted_states[{state}]}` is now displayed as `[state name]`), making the output cleaner and more intuitive.
  * Added specific parsing for `has_term()` and `isset()` to produce more descriptive condition summaries.

## 1.0.11
* **Enhancement:** The Custom Rules Scanner now groups results by each hooked callback function, analyzing shipping logic inside individual functions.

## 1.0.10
* **Enhancement:** Remembers the optional additional file to scan by saving it in the database.
=======
## 1.0.9
* **Enhancement:** The scanner's description for `add_fee()` calls is now more abstract and human-readable. Instead of showing variable placeholders like `{surcharge}`, it now describes the conditional logic (e.g., from a `match` statement) that determines the fee amount, providing a clearer explanation of the rule.

## 1.0.8
* **Enhancement:** The Custom Rules Scanner now provides rich, human-readable analysis for `add_fee()` calls. It extracts the fee's name, its value (even if variable), and the parent conditions, making the output far more descriptive and abstract.

## 1.0.7
* **Fix:** Eliminated "Undefined array key zone_id" by iterating zone IDs (and explicitly adding zone ID 0 for Rest of World).
* **UX:** Price outputs in the Zones & Methods preview now render as clean text (no Woo price HTML).
  * Added `price_to_text()` helper and smarter Flat Rate handling (numeric vs expression).
  * Tidied method lines spacing; badges and titles are consistent.

## 1.0.6
* **Feature:** Restored the “Shipping Zones & Methods Preview” table with deep links to edit zones/methods.
* **UX:** Added owner-friendly enhancements:
  * Status badges (Enabled/Disabled), per-zone enabled/disabled counts.
  * Warnings for common issues (e.g., zone with no enabled methods; Free Shipping with no requirement).
  * Quick filters: “Only show zones with issues” and “Show only enabled methods”.
  * Concise locations summary (e.g., “US (2 states), CA (3 provinces) … +N more”).
  * Preview is capped to 100 rows; shows “And X more rows…” if applicable.

## 1.0.5
* **UX:** More human-friendly descriptions:
  * “Free Shipping” detection now reads as “the rate is a Free Shipping method”.
  * Common variable names are translated (e.g., has_drinks → “the cart contains drinks”, adjusted_total → “the non-drink subtotal”).
  * Comparisons like `adjusted_total < 20` render as “the non-drink subtotal is under $20”.
  * Resolves simple in-scope string assignments for variables (e.g., `{custom_rate_id}` → `drinks_shipping_flat`).
* **Fix:** Corrected a typo in action links registration.

## 1.0.4
* **UX:** Adds context from surrounding conditions for matches:
  * Shows WHEN-conditions for `unset($rates[...])`, `new WC_Shipping_Rate(...)`, and `add_fee()`.
  * Detects common patterns like `strpos($rate->method_id, 'free_shipping')` to say “free shipping rate”.
  * Includes IDs/labels/costs for new `WC_Shipping_Rate` where available.

## 1.0.3
* **UX:** Improved human-readable messages:
  * Extracts messages built with concatenation, `sprintf()`, and interpolated strings for `$errors->add()`.
  * Shows dynamic placeholders (e.g., `{restricted_states[$state]}`) when parts are non-literal.
  * Attempts to display the key used in `unset($rates[...])` even when dynamic, via readable placeholders.
* **Fix:** Avoid duplicate output by relying on a single instantiation path (no extra instantiation at file end).

## 1.0.2
* **UX:** Renamed the `$errors->add()` section to “Checkout validation ($errors->add)”.
* **UX:** Scanner now shows human-readable explanations:
  * Extracts and displays error message strings passed to `$errors->add()`.
  * Adds short plain-English descriptions for filters, fee hooks, `add_rate()`, `new WC_Shipping_Rate`, `unset($rates[])`, and `add_fee()`.

## 1.0.1
* **Security:** Added capability check (`manage_woocommerce`) and nonce verification to the CSV export handler, with proper CSV streaming headers and a hard `exit;` after output.
* **Security:** Restricted “additional file” scanning to the active child theme’s `/inc/` directory using `realpath` clamping and base‐path verification.
* **Developer Experience:** Settings page now automatically detects whether PHP-Parser is loaded and performs a self-test on page load, displaying the result via an admin notice.
* **Correctness:** Fixed `RateAddCallVisitor` imports and node usage:
  * Added missing `use` statements for `PhpParser\Node\Name` and `PhpParser\Node\Identifier`.
  * Corrected `Unset_` to `PhpParser\Node\Stmt\Unset_` (was incorrectly under `Expr`).
  * Declared typed array properties for collected node lists to avoid dynamic properties on newer PHP versions.

## 1.0.0
* **Refactor:** Switched from native `token_get_all()` scanning to a full PHP-Parser AST–based analysis for theme files.
* **Feature:** Dynamically discover and require `php-parser-loader.php` from any active plugin folder.
* **Enhancement:** Unified UI-settings export (CSV download and preview table) and AST-based code scanner into a single plugin file.
* **Improvement:** Updated the “Custom Rules Scanner” visitor to report precise `add_rate()` calls by AST node and line number.
* **Maintenance:** Bumped minimum WP/PHP requirements (WP 6.0+, PHP 7.4+) and versioned to 1.0.0 for the AST migration release.

## 0.7.0
* **Additional File Scanning:** On the "KISS Shipping Debugger" tools page, you will now find a field to enter the path to an additional file within your theme folder (e.g., `/inc/woo-functions.php`) to scan for rules.
* **Enhanced Rule Interpretation:** The scanner is now more powerful and can detect:
  * Functions that hook into `woocommerce_package_rates` to modify shipping prices.
  * Direct cost modifications (e.g., `$rate->cost = 10;`).
  * Cost additions/subtractions (e.g., `$rate->cost += 5;`).
  * Rules that programmatically `unset()` or remove a shipping method.
  * The creation of new shipping rates using `new WC_Shipping_Rate()`.
* **Improved UI:** The scanner results are now organized by the file they were found in, making the output clearer when scanning multiple files.

## 0.6.0
* **Enhancement:** The "Zone Name" in the UI settings preview table is now a direct link to the corresponding WooCommerce shipping zone editor page.
* **Enhancement:** The Custom Rules Scanner now provides a descriptive message for empty or placeholder translation strings, preventing empty bullet points in the output.

## 0.3.0
* **Enhancement:** Plugin renamed to "KISS Woo Shipping Settings Debugger" for clarity.
* **Enhancement:** Added a convenient "Export Settings" link on the main plugins page for one-click access.
* **Enhancement:** Renamed the Tools menu item for consistency.
* **Refactor:** Updated class names, function names, and text domain to align with the new plugin name. Version incremented.

## 0.2.0
* **Major Stability Update:** Added `set_time_limit(0)` to prevent PHP timeouts on large exports.
* **Robustness:** The plugin now checks if WooCommerce is active before running, preventing fatal errors.
* **Robustness:** Added a `try...catch` block and output buffering to prevent "headers already sent" errors and ensure graceful failure.
* **Enhancement:** Now uses `wp_date()` to ensure filenames and timestamps correctly use the site's configured timezone.
* **Enhancement:** Improved data retrieval logic to be more consistent with modern WooCommerce practices.

## 0.1.0
* Initial release.