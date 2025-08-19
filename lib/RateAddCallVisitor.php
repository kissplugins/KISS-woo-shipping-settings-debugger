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

class RateAddCallVisitor extends NodeVisitorAbstract {
    /** @var Node[] */
    private array $addRateNodes    = [];
    /** @var Node[] */
    private array $filterHookNodes = [];
    /** @var Node[] */
    private array $feeHookNodes    = [];
    /** @var Node[] */
    private array $errorAddNodes   = [];
    /** @var Node[] */
    private array $unsetRateNodes  = [];
    /** @var Node[] */
    private array $newRateNodes    = [];
    /** @var Node[] */
    private array $addFeeNodes     = [];
    /** @var Node[] */
    private array $checkoutProcessHookNodes = [];
    /** @var Node[] */
    private array $paymentGatewayHookNodes = [];
    /** @var Node[] */
    private array $paymentMethodFilterNodes = [];
    /** @var Node[] */
    private array $checkoutPaymentHookNodes = [];
    /** @var Node[] */
    private array $generalWooHookNodes = [];

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

        // 1) $package->add_rate(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add_rate'
        ) {
            $this->addRateNodes[] = $node;
        }

        // 2) add_filter('woocommerce_package_rates', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_filter'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === 'woocommerce_package_rates'
        ) {
            $this->filterHookNodes[] = $node;
        }

        // 3) add_action('woocommerce_cart_calculate_fees', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && $node->args[0]->value->value === 'woocommerce_cart_calculate_fees'
        ) {
            $this->feeHookNodes[] = $node;
        }

        // 4) $errors->add(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add'
            && $node->var instanceof Variable
            && $node->var->name === 'errors'
        ) {
            $this->errorAddNodes[] = $node;
        }

        // 5) unset($rates[...])
        if ($node instanceof Unset_
            && isset($node->vars[0])
            && $node->vars[0] instanceof ArrayDimFetch
            && $node->vars[0]->var instanceof Variable
            && $node->vars[0]->var->name === 'rates'
        ) {
            $this->unsetRateNodes[] = $node;
        }

        // 6) new WC_Shipping_Rate(...)
        if ($node instanceof New_
            && $node->class instanceof Name
            && $node->class->toString() === 'WC_Shipping_Rate'
        ) {
            $this->newRateNodes[] = $node;
        }

        // 7) $cart->add_fee(...)
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'add_fee'
        ) {
            $this->addFeeNodes[] = $node;
        }
        
        // 8) add_action('woocommerce_checkout_process', ...) or add_action('woocommerce_after_checkout_validation', ...)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && in_array($node->args[0]->value->value, ['woocommerce_checkout_process', 'woocommerce_after_checkout_validation'])
        ) {
            $this->checkoutProcessHookNodes[] = $node;
        }

        // 9) Payment gateway related hooks: add_filter('woocommerce_available_payment_gateways', ...)
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

        // 10) Payment method filtering: add_action with payment-related hooks (expanded patterns)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
        ) {
            $hook_name = $node->args[0]->value->value;
            // More comprehensive payment-related hook detection
            if (strpos($hook_name, 'payment') !== false
                || strpos($hook_name, 'checkout') !== false
                || strpos($hook_name, 'woocommerce_') === 0  // Any WooCommerce hook
                || strpos($hook_name, 'wc_') === 0           // WC prefixed hooks
                || strpos($hook_name, 'gateway') !== false
                || strpos($hook_name, 'billing') !== false
                || strpos($hook_name, 'order') !== false
                || strpos($hook_name, 'cart') !== false
            ) {
                $this->paymentMethodFilterNodes[] = $node;
            }
        }

        // 11) Payment filters: add_filter with payment/WooCommerce-related hooks
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_filter'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
        ) {
            $hook_name = $node->args[0]->value->value;
            // Detect WooCommerce filters that aren't already caught by paymentGatewayHookNodes
            if ((strpos($hook_name, 'woocommerce_') === 0 || strpos($hook_name, 'wc_') === 0)
                && !in_array($hook_name, [
                    'woocommerce_available_payment_gateways',
                    'woocommerce_gateway_title',
                    'woocommerce_gateway_description',
                    'woocommerce_package_rates'  // Already handled in filterHooks
                ])
            ) {
                $this->paymentMethodFilterNodes[] = $node;
            }
        }

        // 12) Checkout payment section hooks (like neo_before_checkout_payment)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->toString() === 'add_action'
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
            && (strpos($node->args[0]->value->value, 'checkout_payment') !== false
                || strpos($node->args[0]->value->value, 'before_checkout_payment') !== false
                || strpos($node->args[0]->value->value, 'after_checkout_payment') !== false
                || strpos($node->args[0]->value->value, 'neo_') === 0)  // Neo theme hooks
        ) {
            $this->checkoutPaymentHookNodes[] = $node;
        }

        // 13) General WooCommerce hooks (catch-all for any WooCommerce hook not already categorized)
        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && in_array($node->name->toString(), ['add_action', 'add_filter'])
            && isset($node->args[0])
            && $node->args[0]->value instanceof String_
        ) {
            $hook_name = $node->args[0]->value->value;
            // Expanded WooCommerce hook detection
            $isWooCommerceRelated = (
                strpos($hook_name, 'woocommerce_') === 0 ||
                strpos($hook_name, 'wc_') === 0 ||
                // WooCommerce AJAX hooks
                (strpos($hook_name, 'wp_ajax_') === 0 && $this->isWooCommerceAjaxHook($hook_name)) ||
                // Theme-specific WooCommerce hooks
                strpos($hook_name, 'shoptimizer_') === 0 ||
                strpos($hook_name, 'neo_') === 0
            );

            // Only catch WooCommerce hooks that haven't been caught by other specific categories
            if ($isWooCommerceRelated && !$this->isAlreadyCategorized($hook_name, $node)) {
                $this->generalWooHookNodes[] = $node;
            }
        }
    }

    public function getAddRateNodes(): array    { return $this->addRateNodes; }
    public function getFilterHookNodes(): array { return $this->filterHookNodes; }
    public function getFeeHookNodes(): array    { return $this->feeHookNodes; }
    public function getErrorAddNodes(): array   { return $this->errorAddNodes; }
    public function getUnsetRateNodes(): array  { return $this->unsetRateNodes; }
    public function getNewRateNodes(): array    { return $this->newRateNodes; }
    public function getAddFeeNodes(): array     { return $this->addFeeNodes; }
    public function getCheckoutProcessHookNodes(): array { return $this->checkoutProcessHookNodes; }
    public function getPaymentGatewayHookNodes(): array { return $this->paymentGatewayHookNodes; }
    public function getPaymentMethodFilterNodes(): array { return $this->paymentMethodFilterNodes; }
    public function getCheckoutPaymentHookNodes(): array { return $this->checkoutPaymentHookNodes; }
    public function getGeneralWooHookNodes(): array { return $this->generalWooHookNodes; }

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
}