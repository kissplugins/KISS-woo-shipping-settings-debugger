# Roadmap

- Support scheduled automatic exports for easier backups.
- Provide JSON export option in addition to CSV.
- Allow scanning of plugin files in addition to themes.
- Add unit tests to verify scanning heuristics.
- Provide WP-CLI commands for headless environments.

Grouping the analysis by the function that contains the logic, rather than by the type of action, would provide a much clearer and more intuitive report.

Advantages:

Contextual Grouping: It would tie the specific actions (like creating or removing a rate) directly to the event that triggers them (the hook). Instead of seeing a list of all unset() calls from across the file, you would see the specific unset() calls that happen inside the exclude_drinks_from_free_shipping_threshold function. This is how a developer thinks about the code.

Clearer Narrative: The analysis would tell a more coherent story. For example: "When the woocommerce_package_rates filter runs, it calls the exclude_drinks_from_free_shipping_threshold function, which then does the following..." This is much easier to understand than the current list of disconnected actions.

Reduced Repetition: In the current output, the same conditions (e.g., "when the non-drink subtotal is under $20.00") are repeated for every action inside that conditional block. Grouping by function would allow the scanner to describe the conditions once and then list the resulting actions, making the output more concise.

Challenges:

Implementation Complexity: This approach is more complex to implement. It would require a multi-step process:

First, scan the file to identify all the add_action and add_filter calls.

Extract the name of the function being hooked (e.g., exclude_drinks_from_free_shipping_threshold).

Locate the full function definition for that name within the AST.

Finally, traverse the AST of only that function to find and describe the interesting logic within it.

Handling Closures: The parser would need to be ableto handle anonymous functions passed directly to hooks, which is a common pattern.

Conclusion:

Despite the implementation challenges, the benefit is significant. Switching to a function-based grouping is the right direction for improving readability and abstraction. It moves the analysis from being a simple "list of things found" to a much more insightful "explanation of what the code does."

## Three‑phase improvement plan (totals/coupons visibility and scanning)

### Phase 1 — Diagnostics and UX quick wins
- Totals Influence panel: summarize anything that can affect the final total (fees, discounts, custom totals filters) with line numbers and human‑readable explanations.
- Detect and flag hooks that commonly override totals or discount math:
  - `woocommerce_calculated_total`, `woocommerce_calculate_totals`, `woocommerce_cart_calculate_fees`
  - Coupon‑related: `woocommerce_coupon_get_discount_amount`, `woocommerce_coupon_is_valid`, `woocommerce_applied_coupon`
- Add “Possible totals override detected” warnings when code sets `$cart->set_total()` or returns a custom total from filters.
- Small UX: link each detected hook to its function block; add copy‑as‑code for quick sharing in support chats.

### Phase 2 — Function‑based grouping and Totals report
- Implement the function‑centric grouping described below for clearer narratives.
- Generate a dedicated “Totals Influence Report” that groups all actions/filters by the function that runs during totals calculation, and lists their effects in order of execution.
- Extract key conditions (min subtotal, product/category checks, taxability flags, negative `add_fee` usage) once per function, then list resulting actions (e.g., “adds a -$10 fee before tax, taxable=no”).

### Phase 3 — Coverage and automation
- Optional scanning of selected plugin folders (with guardrails and path whitelists) in addition to themes.
- WP‑CLI commands to run scans and export reports headlessly (CI, staging checks).
- Scheduled exports (CSV/JSON) and scan summaries for recurring audits.
- Unit tests for new detectors and grouping; performance benchmarks on large themes.