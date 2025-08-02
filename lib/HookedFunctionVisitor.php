<?php
namespace KISSShippingDebugger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Visitor that collects functions hooked into specific WooCommerce hooks.
 */
class HookedFunctionVisitor extends NodeVisitorAbstract {
    /** @var array<string,Function_> */
    private array $functions = [];
    /** @var array<string,ClassMethod> */
    private array $methods = [];
    /** @var array<int,array{hook:string,callback:mixed}> */
    private array $hooks = [];

    public function enterNode(Node $node) {
        if ($node instanceof Function_) {
            $this->functions[$node->name->toString()] = $node;
        }
        if ($node instanceof ClassMethod) {
            $this->methods[$node->name->toString()] = $node;
        }
        if ($node instanceof FuncCall && $node->name instanceof Name && isset($node->args[0]) && $node->args[0]->value instanceof String_) {
            $func = $node->name->toString();
            if (in_array($func, ['add_filter','add_action'], true)) {
                $hookName = $node->args[0]->value->value;
                if (in_array($hookName, ['woocommerce_package_rates','woocommerce_cart_calculate_fees'], true)) {
                    $cb = $node->args[1]->value ?? null;
                    $this->hooks[] = ['hook' => $hookName, 'callback' => $cb];
                }
            }
        }
    }

    private function resolve_callback($cb) {
        if ($cb instanceof \PhpParser\Node\Expr\Closure) {
            return $cb;
        }
        if ($cb instanceof Name) {
            $name = $cb->toString();
            return $this->functions[$name] ?? ($this->methods[$name] ?? null);
        }
        if ($cb instanceof String_) {
            $name = $cb->value;
            return $this->functions[$name] ?? ($this->methods[$name] ?? null);
        }
        if ($cb instanceof Array_ && isset($cb->items[1]) && $cb->items[1]->value instanceof String_) {
            $method = $cb->items[1]->value->value;
            return $this->methods[$method] ?? null;
        }
        return null;
    }

    /**
     * @return array<int,array{hook:string,function:Node\FunctionLike}>
     */
    public function getHookedFunctions(): array {
        $out = [];
        foreach ($this->hooks as $h) {
            $fn = $this->resolve_callback($h['callback']);
            if ($fn) {
                $out[] = ['hook' => $h['hook'], 'function' => $fn];
            }
        }
        return $out;
    }
}
