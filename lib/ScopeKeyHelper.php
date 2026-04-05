<?php
/**
 * Shared helper: determines the function/method/closure scope for a given AST node.
 * Used by both ArrayCollectorVisitor and the scanner trait to avoid duplication.
 */

namespace KISSShippingDebugger;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;

class ScopeKeyHelper {

    /**
     * Traverses parent nodes to determine the current function/method/closure scope.
     *
     * @param Node $node
     * @return string The scope key (e.g., 'MyClass::myMethod', 'my_function', 'closure@line:123', '__global__').
     */
    public static function get( Node $node ): string {
        $parent = $node->getAttribute( 'parent' );
        while ( $parent ) {
            if ( $parent instanceof FunctionLike ) {
                if ( $parent instanceof ClassMethod ) {
                    $className = '__anonymous';
                    $classParent = $parent->getAttribute( 'parent' );
                    if ( $classParent instanceof Class_ && $classParent->name instanceof Node\Identifier ) {
                        $className = $classParent->name->toString();
                    }
                    return $className . '::' . $parent->name->toString();
                }

                if ( $parent instanceof Function_ ) {
                    return $parent->name->toString();
                }

                if ( $parent instanceof Closure ) {
                    return 'closure@line:' . $parent->getStartLine();
                }
            }
            $parent = $parent->getAttribute( 'parent' );
        }

        return '__global__';
    }
}
