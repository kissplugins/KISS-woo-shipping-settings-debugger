<?php
/**
 * Visitor to locate shipping and payment-related AST nodes in parsed PHP files.
 */

namespace KISSShippingDebugger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Unset_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Name;
use PhpParser\Node\Identifier;

use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;

class RateAddCallVisitor extends NodeVisitorAbstract {
    /** @var Node[] */
    private array $addRateNodes    = [];
    /** @var Node[] */
    private array $filterHookNodes = [];
    /** @var Node[] */
    private array $errorAddNodes   = [];
    /** @var Node[] */
    private array $unsetRateNodes  = [];
    /** @var Node[] */
    private array $rateCostNodes = [];

    /** @var Node[] */
    private array $newRateNodes    = [];
    /** @var Node[] */
    private array $checkoutProcessHookNodes = [];
    /** @var Node[] */
    private array $paymentGatewayHookNodes = [];
    /** @var Node[] */
    private array $paymentMethodFilterNodes = [];

    // Debug counters
    private int $totalAddActionCalls = 0;
    private int $totalAddFilterCalls = 0;
    private int $totalWooCommerceCalls = 0;

    public function enterNode(Node $node) {
        // Debug: Count all add_action and add_filter calls
        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $funcName = $node->name->toString();
            if ($funcName === 'add_action') {
                $this->totalAddActionCalls++;
                // Check if it's a WooCommerce hook
                if (isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                    $hookName = $node->args[0]->value->value;
                    if (strpos($hookName, 'woocommerce_') === 0 || strpos($hookName, 'wc_') === 0) {
                        $this->totalWooCommerceCalls++;
                    }
                }
            } elseif ($funcName === 'add_filter') {
                $this->totalAddFilterCalls++;
                // Check if it's a WooCommerce hook
                if (isset($node->args[0]) && $node->args[0]->value instanceof String_) {
                    $hookName = $node->args[0]->value->value;
                    if (strpos($hookName, 'woocommerce_') === 0 || strpos($hookName, 'wc_') === 0) {
                        $this->totalWooCommerceCalls++;
                    }
                }
            }
        }

        // 1) $package->add_rate(...) - Only if it appears to be location-based
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add_rate'
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->addRateNodes[] = $node;
        }

        // 2) add_filter('woocommerce_package_rates', ...) - Only if geographical filtering
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_filter'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === 'woocommerce_package_rates'
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->filterHookNodes[] = $node;
        }

        // 3) $errors->add(...) - Only if related to geographical or payment validation
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add'
            && $node->var instanceof Variable
            && $node->var->name === 'errors'
            && ($this->isGeographicallyRelevant($node) || $this->isPaymentRelevant($node))
        ) {
            $this->errorAddNodes[] = $node;
        }

        // 4) unset($rates[...]) - Only if geographical conditions are present
        if ($node instanceof Unset_
            && isset($node->vars[0])
            && $node->vars[0] instanceof ArrayDimFetch
            && $node->vars[0]->var instanceof Variable
            && $node->vars[0]->var->name === 'rates'
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->unsetRateNodes[] = $node;
        }

        // 5) new WC_Shipping_Rate(...) - Only if location-based
        if ($node instanceof New_
            && $node->class instanceof Name
            && $node->class->toString() === 'WC_Shipping_Rate'
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->newRateNodes[] = $node;
        }

        // 5b) Rate cost adjustments: $rate->cost = X or $rate->set_cost(X)
        if ($node instanceof Assign
            && $node->var instanceof PropertyFetch
            && $node->var->name instanceof Identifier
            && strtolower($node->var->name->toString()) === 'cost'
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->rateCostNodes[] = $node;
        }
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && in_array(strtolower($node->name->toString()), ['set_cost','setcost'], true)
            && $this->isGeographicallyRelevant($node)
        ) {
            $this->rateCostNodes[] = $node;
        }

        // 6) add_action('woocommerce_checkout_process', ...)
        // Be permissive here: these hooks are directly tied to checkout validation; include without extra keyword heuristics.
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && in_array($node->args[0]->value->value, ['woocommerce_checkout_process', 'woocommerce_after_checkout_validation'])
        ) {
            $this->checkoutProcessHookNodes[] = $node;
        }

        // 7) Payment gateway related hooks: add_filter('woocommerce_available_payment_gateways', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_filter'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && in_array($node->args[0]->value->value, [
                'woocommerce_available_payment_gateways',
                'woocommerce_gateway_title',
                'woocommerce_gateway_description'
            ])
        ) {
            $this->paymentGatewayHookNodes[] = $node;
        }

        // 8) Specific payment method filtering - Only for targeted payment restrictions
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && in_array($node->name->toString(), ['add_action', 'add_filter'])
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $this->isPaymentRelevant($node)
        ) {
            $hook_name = $node->args[0]->value->value;
            // Only capture payment-specific hooks that are actually relevant
            if (strpos($hook_name, 'payment') !== false
                || strpos($hook_name, 'gateway') !== false
                || strpos($hook_name, 'billing') !== false
                || in_array($hook_name, [
                    'woocommerce_checkout_process',
                    'woocommerce_after_checkout_validation'
                ])
            ) {
                $this->paymentMethodFilterNodes[] = $node;
            }
        }
    }

    public function getAddRateNodes(): array    { return $this->addRateNodes; }
    public function getFilterHookNodes(): array { return $this->filterHookNodes; }
    public function getErrorAddNodes(): array   { return $this->errorAddNodes; }
    public function getUnsetRateNodes(): array  { return $this->unsetRateNodes; }
    public function getNewRateNodes(): array    { return $this->newRateNodes; }
    public function getCheckoutProcessHookNodes(): array { return $this->checkoutProcessHookNodes; }
    public function getPaymentGatewayHookNodes(): array { return $this->paymentGatewayHookNodes; }
    public function getRateCostNodes(): array { return $this->rateCostNodes; }

    public function getPaymentMethodFilterNodes(): array { return $this->paymentMethodFilterNodes; }

    // Debug getters
    public function getTotalAddActionCalls(): int { return $this->totalAddActionCalls; }
    public function getTotalAddFilterCalls(): int { return $this->totalAddFilterCalls; }
    public function getTotalWooCommerceCalls(): int { return $this->totalWooCommerceCalls; }

    /**
     * Check if an AJAX hook is WooCommerce-related
     */
    private function isWooCommerceAjaxHook(string $hook_name): bool {
        // Common WooCommerce AJAX action patterns
        $woocommerceAjaxPatterns = [
            'add_to_cart',
            'remove_from_cart',
            'update_cart',
            'product_remove',
            'apply_filters',
            'checkout',
            'cart',
            'woocommerce',
            'wc_',
            'binoid_', // Site-specific WooCommerce functions
        ];

        foreach ($woocommerceAjaxPatterns as $pattern) {
            if (strpos($hook_name, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a hook is already categorized by other specific detection rules
     */
    private function isAlreadyCategorized(string $hook_name, $node): bool {
        // Skip hooks already handled by specific categories
        $specific_hooks = [
            'woocommerce_package_rates',
            'woocommerce_cart_calculate_fees',
            'woocommerce_checkout_process',
            'woocommerce_after_checkout_validation',
            'woocommerce_available_payment_gateways',
            'woocommerce_gateway_title',
            'woocommerce_gateway_description'
        ];

        if (in_array($hook_name, $specific_hooks)) {
            return true;
        }

        // Skip if already caught by payment filters (but allow some overlap for comprehensive detection)
        if (strpos($hook_name, 'checkout_payment') !== false
            || strpos($hook_name, 'before_checkout_payment') !== false
            || strpos($hook_name, 'after_checkout_payment') !== false
            || strpos($hook_name, 'neo_') === 0
        ) {
            return true;
        }

        return false;
    }

    /**
     * Check if a node is geographically relevant by examining surrounding context
     */
    private function isGeographicallyRelevant(Node $node): bool {
        // Simplified keyword list for better performance
        $geographicalKeywords = [
            'country', 'state', 'city', 'zip', 'postal', 'address',
            'location', 'shipping', 'billing', 'destination', 'zone',
            // Product-based geographical restrictions
            'kratom', 'amanita', 'thc', 'cbd', 'cannabis',
            'product_cat', 'product_id', 'has_term', 'get_cart',
            // Common shipping restriction patterns
            'rates', 'package', 'unset', 'remove', 'restrict', 'checkout', 'error',
            // Key state abbreviations
            'US', 'CA', 'UK', 'AU', 'alabama', 'california', 'texas', 'florida', 'oregon'
        ];

        return $this->nodeContainsKeywords($node, $geographicalKeywords);
    }

    /**
     * Check if a node is payment method relevant
     */
    private function isPaymentRelevant(Node $node): bool {
        // Simplified payment keywords for better performance
        $paymentKeywords = [
            'payment', 'gateway', 'amex', 'american_express', 'paypal', 'stripe',
            'payment_method', 'available_gateways', 'gateways',
            'checkout', 'billing',
            // Product-based payment restrictions
            'kratom', 'amanita', 'thc', 'cbd', 'cannabis',
            'product_cat', 'has_term', 'get_cart'
        ];

        return $this->nodeContainsKeywords($node, $paymentKeywords);
    }

    /**
     * Helper method to check if a node or its context contains specific keywords
     */
    private function nodeContainsKeywords(Node $node, array $keywords): bool {
        // Convert node to string representation for keyword search
        $nodeString = $this->nodeToString($node);

        foreach ($keywords as $keyword) {
            if (stripos($nodeString, $keyword) !== false) {
                return true;
            }
        }

        // Skip parent checking to avoid performance issues and potential infinite loops
        // The current node string should be sufficient for most cases
        return false;
    }

    /**
     * Convert a node to a string representation for keyword searching
     */
    private function nodeToString(Node $node): string {
        // For basic nodes, try to extract meaningful content quickly
        $content = '';

        if ($node instanceof String_) {
            $content = $node->value;
        } elseif ($node instanceof Variable && is_string($node->name)) {
            $content = $node->name;
        } elseif ($node instanceof Identifier) {
            $content = $node->name;
        } else {
            // For other nodes, use a simple approach
            $content = get_class($node);
        }

        // Add any string arguments (limit to first 3 for performance)
        if (property_exists($node, 'args') && is_array($node->args)) {
            $argCount = 0;
            foreach ($node->args as $arg) {
                if ($argCount >= 3) break; // Limit for performance
                if (isset($arg->value) && $arg->value instanceof String_) {
                    $content .= ' ' . $arg->value->value;
                }
                $argCount++;
            }
        }

        // Limit string length for performance
        $result = strtolower($content);
        return strlen($result) > 500 ? substr($result, 0, 500) : $result;
    }
}