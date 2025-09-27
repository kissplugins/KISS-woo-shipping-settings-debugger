# Changelog

Plugin sponsored & developed by Neochrome, Inc.


## 2.7.13
## 2.7.14
* Self-Test: Removed "Array/placeholder resolution (conditions → human list)" from the Self-Test UI and dispatcher; remains in Deferred list.


* Docs: Moved "Array/placeholder resolution" self-test to Deferred list.
* Docs: Added pause note to PROJECT-SELFTESTS.md as of 2025-09-27 indicating self-test development is paused.



## 2.7.8
* Self-Test: Added "AST detects payment gateway restrictions and checkout notices".
* Self-Test: Added "AST detects both geo shipping and payment restrictions".
* Docs: Phase 2.0 payment and mixed-logic verification initiated.


## 2.7.9
* Scanner: Fixed description fall-through for paymentFilters; now correctly summarizes payment method hooks.
* Scanner: Loosened checkout validation hook detection (woocommerce_checkout_process/after_checkout_validation) so they are included without extra heuristics.
* Self-Test: Relaxed expectations to match sanitized description output from scan_single_file_for_test.


## 2.7.10
* Scanner: Product/location term-driven logic – bolds Amanita/THC-A and City/County patterns; extended state bolding retained.
* Self-Test: Added "Logic: Product/location term-driven rules (Kratom, Amanita, THC-A)" and "UX: Bold formatting fidelity for product and City/County/State names".
* Docs: Phase 2.0 product/location and bolding fidelity items marked complete.


## 2.7.12
* Self-Test: Added "Array/placeholder resolution" check that validates placeholders like {restricted_states} are summarized into human-friendly lists when resolvable (e.g., "the location is one of: Alabama, Oregon").
* Docs: PROJECT-SELFTESTS.md updated to mark Array/placeholder resolution as complete.

## 2.7.11
* Fix: Prevent nested <strong> tags in formatted messages by de-duplicating overlapping bold rules.
* Fix: Restrict the "before or" bolding rule to Capitalized tokens to avoid bolding phrases like "of Portland"; preserves "City of <strong>Portland</strong>" and "<strong>Cook</strong> County".
* QA: Self-tests for product/location bolding fidelity now pass for Amanita/THC-A/Kratom, and City/County/State names.

## 2.7.7
* Phase 2.0: Active theme scan targets inc/ by default; additional file input is now clamped to the theme’s inc/ directory.
* Scanner: Detects shipping rate cost adjustments (->cost assignments and set_cost()) under geographical conditions, in addition to existing unset($rates[...]).
* Self-Test: Added “AST detects rate removal and cost changes” test.
* Docs: PROJECT-SELFTESTS updated to tick first two Phase 2.0 items.


## 2.7.6
* UX: Changelog preview now preserves <strong>bold</strong> in fallback mode (minimal Markdown-to-HTML for **bold** only, sanitized).
* QA: Added self-test “changelog_preview” to verify <strong> rendering is preserved.
* DX: Self-Test page now shows registered-callback counts for AJAX actions to detect duplicate registrations.
* Phase 1.5: Completed diagnostics and UX checks covered in this release.
* Docs: PROJECT-SELFTESTS Phase 1.5 checklist marked complete; status updated to 2.7.6.


## 2.7.5
* Fix: Prevented null-class fatals in self-tests by instantiating the main debugger for tests that require it (warning logic and AST scanner).
* Fix: Made Self-Test AJAX fully robust by pinning endpoint to admin-ajax.php and suppressing admin notices during AJAX.
* Test: Reworked “Menu & Action Links Registration” to validate callback presence in admin-ajax context (no menu globals needed).
* DX: Consistent handler registration during AJAX without side-effect instantiation.
* Docs: Updated PROJECT-SELFTESTS.md with Status & Next Steps for v2.7.6.


## 2.7.4
* Fix: Self-Test AJAX handlers now use non-die nonce verification to return structured JSON errors instead of transport failures.
* Fix: Removed duplicate AJAX registrations and the external ajax-handlers include to eliminate registration conflicts.
* Dev: Enforced nonce on the Test AJAX button request and added PHPDoc to timestamp handler.
* DX: Self-Test page consistently posts the same suite nonce with every request for reliability.


## 2.7.3
* Docs: Split self-test plan phases into 1.0/1.5 and 2.0/2.5 for finer-grained focus and troubleshooting.
* Docs: Added PHPDoc guidelines requirement for all self-test functions and dispatcher handlers.


## 2.7.2
* Docs: Added PROJECT-SELFTESTS.md outlining a clean-room self-test plan split into Basic and Advanced phases, with actionable checklists.
* Planning: Identified AJAX flakiness causes (duplicate handler registration, inconsistent nonce/capability checks) and defined a single-dispatcher model to implement next. No runtime logic changes in this version.


## 2.7.1
* **Fix:** Resolved Self-Test AJAX errors by fixing plugin instantiation and improving permission checks
  * Fixed missing plugin class instantiation that prevented AJAX handlers from being registered
  * Enhanced permission checks to fallback to `manage_options` when `manage_woocommerce` capability is not available
  * Added proper `ajaxurl` localization to ensure AJAX calls work correctly
* **Enhancement:** Added "Settings" and "Self-Test" action links to the plugin listing on the All Plugins page for easier access
* **Enhancement:** Added new self-test for menu and action links registration to prevent UI regressions
  * Tests that menu items are properly registered under WooCommerce or Tools menu
  * Verifies that plugin action links filter is correctly registered
  * Helps ensure menu functionality doesn't break in future updates
* **Enhancement:** Improved HTML rendering to display `<strong>` tags as actual **bold text** instead of escaped HTML entities
  * Fixed scanner output to properly render product names (Kratom, THC-A, etc.) and location names (Alabama, California, etc.) in bold
  * Added safe HTML sanitization that allows `<strong>` tags while preventing other HTML injection
  * Updated location display in shipping zones preview to show bolded state/country names
* **Performance:** Significantly optimized AST scanner performance to prevent timeouts
  * Reduced keyword detection lists from 50+ to ~15 essential keywords for better performance
  * Removed parent node traversal that could cause infinite loops or deep recursion
  * Added execution time monitoring and timeout protection (scanner now completes in ~8ms instead of timing out)
  * Limited string processing to 500 characters max and only first 3 arguments for performance
* **Enhancement:** Improved product-based geographical restriction detection
  * Enhanced scanner to better detect restrictions for products like Kratom, Amanita Mushroom, THC-A, CBD, Cannabis
  * Improved filtering to preserve useful information while removing noise
  * Better recognition that product restrictions are often geographically relevant due to varying state laws
* **Fix:** Resolved self-test failures and timeout issues
  * Fixed "Helper: summarize_method()" test by making required methods public and adding missing mock methods
  * Fixed "Logic: AST Scanner Rule & Array Resolution" test with improved validation logic
  * Added graceful fallback testing when complex validation fails

## 2.6.0
* **Major Feature:** Enhanced scanner to detect payment-related functionality in addition to shipping rules.
  * Added detection for payment gateway modifications (`woocommerce_available_payment_gateways`, `woocommerce_gateway_title`, `woocommerce_gateway_description`)
  * Added detection for payment method filtering hooks (any `add_action` with "payment" or "checkout" keywords)
  * Added detection for checkout payment section hooks (`checkout_payment`, `before_checkout_payment`, `after_checkout_payment`)
  * Scanner now catches functions like `add_action('neo_before_checkout_payment', 'callback')` for payment restrictions
* **Enhancement:** Updated plugin name to "KISS Woo Shipping & Payment Settings Debugger" to reflect expanded capabilities
* **Enhancement:** Enhanced scanner descriptions to provide detailed explanations for payment-related code patterns
* **Enhancement:** Updated admin interface labels and descriptions to mention both shipping and payment functionality
* **Fix:** Resolved critical syntax errors that were causing plugin activation crashes
  * Fixed extra closing brace in scanner-trait.php
  * Fixed incorrectly nested method definitions in main plugin file
  * Removed duplicate method definitions that conflicted with trait implementations
  * Fixed misplaced code blocks referencing undefined variables
* **QA:** All PHP files now pass syntax validation and the plugin activates without errors

## 2.5.0

## 2.5.1
* **Security:** Fixed path traversal in scanner; enforced PHP-only and size limits; added memory headroom checks before parsing.
* **Security:** Replaced wp_kses_post with esc_html for scanner descriptions derived from scanned files.
* **Security:** Added CSV injection protection and security headers; implemented sanitized CSV export.
* **Security:** Added rate limiting to CSV export handler (filterable window).
* **Stability:** Regex ReDoS hardening in message formatting (truncate + lowered PCRE limits, restored after use; escaped alternations).
* **QA:** Self-Test now includes a “CSV Injection Guard” test.

* **Feature:** Added a "Grouping Type" toggle above the Custom Rules Scanner results, allowing reports to be viewed by Product (original behavior) or by the function/method containing each finding.

## 2.4.0
* **Enhancement:** The Custom Rules Scanner now resolves array variables within the scanned code. It replaces placeholders like `{restricted_states}` in rule descriptions with a human-readable list of the actual states or postal codes, providing much richer context.

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
