<?php
/**
 * Visitor to locate and collect literal array assignments within the AST.
 */

namespace KISSShippingDebugger;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;

class ArrayCollectorVisitor extends NodeVisitorAbstract {

    /**
     * Stores collected arrays, keyed by scope and then by variable name.
     * e.g., ['MyClass::myMethod' => ['my_array' => [ ... ]]]
     *
     * @var array<string, array<string, array<mixed>>>
     */
    public array $arrays_by_scope = [];

    public function enterNode(Node $node) {
        // We are looking for assignments of literal arrays, e.g., $foo = [ ... ];
        if ($node instanceof Expr\Assign && $node->expr instanceof Expr\Array_) {
            $varName = $node->var instanceof Expr\Variable ? $node->var->name : null;

            if ($varName && is_string($varName)) {
                $scopeKey = $this->getCurrentScopeKey($node);
                $this->arrays_by_scope[$scopeKey][$varName] = $this->resolveArray($node->expr);
            }
        }
    }

    /**
     * Converts an AST Array_ node into a native PHP array.
     * This method only handles scalar keys and values for simplicity and security.
     *
     * @param Expr\Array_ $array_node
     * @return array<mixed>
     */
    private function resolveArray(Expr\Array_ $array_node): array {
        $out = [];
        foreach ($array_node->items as $item) {
            if (!$item instanceof Expr\ArrayItem) {
                continue;
            }

            // Resolve the key. Null for items like `['a', 'b']`.
            $key = null;
            if ($item->key instanceof Scalar) {
                $key = $item->key->value;
            }

            // Resolve the value.
            $value = null;
            if ($item->value instanceof Scalar) {
                $value = $item->value->value;
            } elseif ($item->value instanceof Expr\Array_) {
                // Allow one level of nesting for simple multi-dimensional arrays.
                $value = $this->resolveArray($item->value);
            } else {
                // For non-scalar values (variables, function calls), we can't resolve statically.
                // Store a placeholder to indicate it's dynamic.
                $value = '{dynamic_value}';
            }

            if ($key !== null) {
                $out[$key] = $value;
            } else {
                $out[] = $value;
            }
        }
        return $out;
    }

    /**
     * Traverses parent nodes to determine the current function/method/closure scope.
     *
     * @param Node $node
     * @return string The scope key (e.g., 'MyClass::myMethod', 'my_function', 'closure@line:123', '__global__').
     */
    private function getCurrentScopeKey(Node $node): string {
        $parent = $node->getAttribute('parent');
        while ($parent) {
            if ($parent instanceof FunctionLike) {
                if ($parent instanceof ClassMethod) {
                    $className = '__anonymous';
                    $classParent = $parent->getAttribute('parent');
                    if ($classParent instanceof Class_ && $classParent->name instanceof Node\Identifier) {
                        $className = $classParent->name->toString();
                    }
                    return $className . '::' . $parent->name->toString();
                }

                if ($parent instanceof Function_) {
                    return $parent->name->toString();
                }

                if ($parent instanceof Closure) {
                    return 'closure@line:' . $parent->getStartLine();
                }
            }
            $parent = $parent->getAttribute('parent');
        }

        return '__global__';
    }

    /**
     * Public getter for the collected arrays.
     *
     * @return array<string, array<string, array<mixed>>>
     */
    public function getArraysByScope(): array {
        return $this->arrays_by_scope;
    }
}