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