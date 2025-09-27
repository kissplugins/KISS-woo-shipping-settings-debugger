# PROJECT-SELFTESTS

Purpose: Rebuild the plugin’s self-tests using a clean-room approach that is deterministic, AJAX-reliable, and focused on verifying the plugin’s core functionality (scanning, formatting, UX hooks) without depending on WooCommerce runtime flows beyond what’s necessary.

This document has four sub-phases with actionable checklists:
- Phase 1.0: Basic Wiring Tests (smoke and environment validation)
- Phase 1.5: Basic Integration & UX Tests (admin UI and registration sanity)
- Phase 2.0: Advanced Logic Tests (scanner logic and formatting fidelity)
- Phase 2.5: Advanced Performance & Security (performance envelope, rate limiting, security)

## Table of Contents
- Overview and Goals
- Status & Next Steps (as of 2.7.5)
- What Exists Today (extracted from code)
- Evaluation of Current Self-Tests
- Phase 1.0: Basic Wiring Tests (Checklist)
- Phase 1.5: Basic Integration & UX Tests (Checklist)
- Phase 2.0: Advanced Logic Tests (Checklist)
- Phase 2.5: Advanced Performance & Security (Checklist)
- PHPDoc Guidelines for Self-Tests
- Clean-Room Test Dispatch Model
- How We’ll Use This Doc in Code Changes
## Status & Next Steps (as of 2.7.5)
- Phase 1.0: Completed in v2.7.5
  - Single dispatcher with consistent nonce + capability fallback
  - AJAX endpoint pinned to admin-ajax.php and admin notices suppressed during AJAX
  - Fixed null-class fatals by instantiating main class for tests that require it
  - Menu test adjusted to validate callbacks in admin-ajax context
- Phase 1.5: Completed in v2.7.6
  - Changelog preview preserves <strong> while remaining sanitized
  - On-page diagnostic shows AJAX handler registration counts (detects duplicates)
  - CSV injection guard smoke test passes via dispatcher
  - UI debug badges show action registration and capability detection
- Phase 2.0: Planned
  - Deep scanner correctness, array/placeholder resolution, grouping modes, bold formatting fidelity
- Phase 2.5: Planned
  - Performance envelopes, CSV export rate limiting, and security regressions



## Overview and Goals
- Ensure AJAX self-tests are reliable and simple: a single dispatcher, pure tests, stable capability/nonce behavior.
- Validate that the scanner focuses on the active child theme’s inc/ directory and preserves bolding for Product and State/City/County names.
- Keep filtering non-restrictive so useful details aren’t removed from output.
- Reduce flakiness caused by multiple registration points and mixed handlers.

## What Exists Today (extracted from code)
Core files relevant to self-tests:
- self-test.php
  - UI page under WooCommerce menu (Shipping Self-Test)
  - JS runs a series of tests via wp_ajax: kiss_wse_run_single_test
  - Tests defined (IDs):
    - dependency_check (checks WooCommerce and PHP-Parser availability)
    - summarize_method_helper (helper behavior)
    - warning_logic_mock (preview warning detection)
    - ast_scanner_logic (scanner test on a temp file; performance bounded)
    - csv_injection_guard (CSV injection safety)
    - menu_registration (menu and plugin action links registered)
  - Also includes a basic AJAX connectivity test action kiss_wse_test_ajax and a timestamp update handler kiss_wse_update_test_timestamp
- ajax-handlers.php
  - Provides a parallel set of “working” handlers: kiss_wse_working_test_ajax, kiss_wse_working_run_single_test, kiss_wse_working_update_timestamp
  - Registers wp_ajax actions directly
- kiss-woo-shipping-settings-debugger.php (main plugin)
  - Registers AJAX handlers from multiple places (constructor register_ajax_handlers, and again on wp_loaded)
  - Requires self-test.php and ajax-handlers.php
- Test fixture files scanned by the AST:
  - test-bold-formatting.php – ensures messages that include product/state names can be bolded
  - test-payment-functions.php – payment-related detection: gateways, notices, titles, checkout processing
  - test-focused-scanner.php – mixed geographic and payment restrictions, plus some non-signal hooks
  - test-improvements.php – product-category-driven restrictions (Kratom, Amanita, THC-A, state-based checks)
  - test-woo-hooks.php – miscellaneous Woo hooks to exercise detection boundaries
  - verify-improvements.php, test-enhanced-scanner.php – ad hoc harnesses to run scanner against above fixtures

## Evaluation of Current Self-Tests
- Strengths
  - Good coverage themes: environment dependencies, AST scanning, output safety (CSV injection), UI integration checks, and connectivity sanity checks.
  - Includes performance guard in ast_scanner_logic.
  - Attempts to show debug info (AJAX action registered, capability checks, ajaxurl).
- Pain Points (likely sources of AJAX failures/flakiness)
  - Multiple registration points for the same AJAX actions (constructor, wp_loaded, plus separate ajax-handlers.php), which can cause hard-to-reason timing/race conditions.
  - Mixed patterns for nonce and capability checks (some enforced, some commented out); inconsistent.
  - Test dispatcher is monolithic and does a lot, which complicates isolating errors.
  - Ad hoc harness scripts (verify-improvements.php, test-enhanced-scanner.php) bypass consistent admin context.

Conclusion: A clean-room rebuild should centralize registration to a single point, use one dispatcher with small pure tests, and enforce a consistent security pattern (nonce + capability fallback behavior) while keeping results deterministic.

## Phase 1.0: Basic Wiring Tests (Checklist)
Environment and wiring sanity checks. These should all run via a single dispatcher action (e.g., kiss_wse_run_single_test) with minimal side effects.

Attn LLM: Please mark checkboxes upon completion.

- [x] AJAX connectivity: POST to wp_ajax action responds with a structured JSON success payload.
- [x] Capability fallback: current_user_can('manage_woocommerce') OR current_user_can('manage_options') pass policy behaves as designed (reject otherwise).
- [x] Nonce policy: requests require a valid nonce tied to this test suite; failures return clear JSON errors.
- [x] Dependency presence: WooCommerce classes and PHP-Parser classes are detectable (with helpful guidance if missing).

## Phase 1.5: Basic Integration & UX Tests (Checklist)
Admin UI and integration checks.

- [x] Menu & action links registered: WooCommerce submenu exists; plugin action links filter for this plugin basename is registered.
- [x] Changelog preview: changelog.md render helper returns sanitized HTML and does not escape <strong> tags (i.e., bolding preserved where intended).
- [x] CSV injection guard (basic): exporter sanitizes fields and emits safe CSV headers; smoke-test returns PASS without emitting file data.
- [x] Self-Test page localizes ajaxurl and shows debug indicators for action registration and capabilities.
- [x] No duplicate AJAX handlers registered (diagnostic/visual confirmation on Self-Test page).

## Phase 2.0: Advanced Logic Tests (Checklist)
Scanner correctness and formatting fidelity.

- [x] Active theme scan targeting: default scan includes active child theme’s inc/shipping-restrictions.php if present; additional file constrained to same inc/ directory (realpath clamp).
- [x] AST detection – shipping rules: detect unsetting of shipping methods and rate cost alterations under conditions.
- [x] AST detection – payment rules: detect payment-gateway filtering, gateway title/description changes, checkout notices, and custom/related checkout payment hooks (per test-payment-functions.php).
- [x] Mixed logic: detect geographical + payment restrictions in the same fixture (per test-focused-scanner.php) while ignoring non-signal hooks.
- [ ] Product- and location-term-driven logic: detect Kratom, Amanita Mushroom, THC-A rules; do not over-filter meaningful messages.
- [ ] Bold formatting fidelity: retain <strong> for product and State/City/County names; sanitize other HTML safely (wp_kses allowlist for <strong>).
- [ ] Array/placeholder resolution: replace placeholders like {restricted_states} with human-friendly lists when resolvable.
- [ ] Grouping modes: “Product” vs “Functional” grouping both render without error and align on counts.

## Phase 2.5: Advanced Performance & Security (Checklist)
Performance and security nuances.

- [ ] Performance envelope: simple snippet scan < 100ms; moderate fixtures < ~2s; explicit error if exceeded (no white screen/timeouts).
- [ ] CSV export rate limiting: respects configured window; returns clear error when rate limit hit.
- [ ] Security regressions: ensure CSV export and test endpoints enforce nonce, capability fallback, and do not leak sensitive data.
- [ ] Stable registration: AJAX actions are registered once, from one place, avoiding duplicates/races.

## PHPDoc Guidelines for Self-Tests
All self-test server-side code must include PHPDoc blocks:

- [ ] Each test function includes a summary, @since 2.7.3, and @internal tags.
- [ ] Each test function documents parameters (e.g., @param array $request) and return type (@return array { pass: bool, message: string, details?: array }).
- [ ] Note any side effects (e.g., temporary files) and ensure cleanup is documented and implemented.
- [ ] Document security expectations: required capability (manage_woocommerce || manage_options) and nonce requirement.
- [ ] Dispatcher handler PHPDoc describes action name, capability checks, nonce usage, and response format.

## Clean-Room Test Dispatch Model
- Single server-side dispatcher: wp_ajax action kiss_wse_run_single_test.
- Request payload: { test_id, nonce }.
- Security: check_ajax_referer + capability fallback (manage_woocommerce || manage_options).
- Each test is a small, pure function that returns a JSON payload { pass: bool, message: string, details?: object } without side effects beyond temporary files (cleaned up).
- Client iterates a known list of test IDs; UI reports PASS/FAIL per row.
- A separate action updates a stored “last run” timestamp; requires the same nonce/capability policy.

## How We’ll Use This Doc in Code Changes
- Phase 1: Implement dispatcher + Basic Tests under a single registration point; remove/disable parallel ajax-handlers and duplicate registrations; enforce consistent nonce/capability checks; keep output deterministic.
- Phase 2: Implement Advanced Tests using existing fixtures (test-*.php) and scanner entry points; verify bolding and filtering constraints and performance; add rate-limit checks.

This file is the authoritative to-do for rebuilding the self-tests. As we implement, tick each checkbox and keep the list up-to-date.

