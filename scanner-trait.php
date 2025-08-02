<?php
/**
 * Trait providing AST scanning utilities used by the debugger.
 */
trait KISS_WSE_Scanner {
    private function scan_and_render_custom_rules( ?string $additional ): void {
        require_once plugin_dir_path( __FILE__ ) . 'lib/RateAddCallVisitor.php';

        $files = [];
        
        // If an additional file is specified, scan it. Otherwise, scan the default.
        if ( ! empty( $additional ) ) {
            $theme_dir = get_stylesheet_directory();
            $file_path = wp_normalize_path( $theme_dir . '/' . ltrim( $additional, '/' ) );

            // Allow for traversing up to the plugins directory for the self-test file
            if ( strpos( $additional, '../' ) === 0 ) {
                 $file_path = wp_normalize_path( $theme_dir . '/../plugins/' . ltrim( $additional, '../' ) );
            }
            
            if ( file_exists( $file_path ) ) {
                $files[] = $file_path;
            } else {
                 echo '<div class="notice notice-warning"><p>' .
                     esc_html__( 'Additional file not found. Please check the path.', 'kiss-woo-shipping-debugger' ) .
                     '</p></div>';
            }
        } else {
             $files[] = wp_normalize_path( trailingslashit( get_stylesheet_directory() ) . 'inc/shipping-restrictions.php' );
        }


        foreach ( $files as $file ) {
            printf( '<h3>Scanning <code>%s</code></h3>', esc_html( wp_make_link_relative( $file ) ) );
            if ( ! file_exists( $file ) ) {
                echo '<p><em>' . esc_html__( 'File not found.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
                echo '<p><em>' . esc_html__( 'PHP-Parser not available. Unable to scan this file.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            $code   = file_get_contents( $file );
            $parser = $this->create_parser();
            $ast    = $parser->parse( $code );

            // Traverse the entire AST to find all relevant nodes at once.
            $trav = new \PhpParser\NodeTraverser();
            $trav->addVisitor( new \PhpParser\NodeVisitor\ParentConnectingVisitor() );
            $visitor = new \KISSShippingDebugger\RateAddCallVisitor();
            $trav->addVisitor( $visitor );
            $trav->traverse( $ast );

            // Group all found nodes by their parent function.
            $all_nodes = [
                'rateCalls'  => $visitor->getAddRateNodes(),
                'newRates'   => $visitor->getNewRateNodes(),
                'unsetRates' => $visitor->getUnsetRateNodes(),
                'addFees'    => $visitor->getAddFeeNodes(),
                'errors'     => $visitor->getErrorAddNodes(),
            ];

            $grouped_by_function = [];
            foreach ( $all_nodes as $key => $nodes ) {
                foreach ( $nodes as $node ) {
                    $func_name = $this->get_parent_function_name( $node );
                    if ( ! isset( $grouped_by_function[ $func_name ] ) ) {
                        $grouped_by_function[ $func_name ] = [];
                    }
                    $grouped_by_function[ $func_name ][] = [ 'type' => $key, 'node' => $node ];
                }
            }

            if ( empty( $grouped_by_function ) ) {
                echo '<p><em>' . esc_html__( 'No shipping-related logic found in this file.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
                continue;
            }

            // Render the results, grouped by function.
            foreach ( $grouped_by_function as $func_name => $items ) {
                printf( '<h4>%s <code>%s</code></h4>', esc_html__( 'Function:', 'kiss-woo-shipping-debugger' ), esc_html( $func_name ) );
                echo '<ul>';
                foreach ( $items as $item ) {
                    $line = (int) $item['node']->getLine();
                    $desc = $this->describe_node( $item['type'], $item['node'] );
                    printf(
                        '<li><strong>%s</strong> — %s %s</li>',
                        esc_html( $this->short_explanation_label( $item['type'] ) ),
                        wp_kses_post( $desc ), // Use wp_kses_post to allow for safe HTML in descriptions
                        sprintf( '<span style="opacity:.7;">(%s %d)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ) )
                    );
                }
                echo '</ul>';
            }
        }
    }

    /**
     * Get the name of the parent function for a given AST node.
     */
    private function get_parent_function_name( \PhpParser\Node $node ): string {
        $current = $node;
        while ( $current = $current->getAttribute( 'parent' ) ) {
            if ( $current instanceof \PhpParser\Node\FunctionLike ) {
                if ( $current instanceof \PhpParser\Node\Stmt\Function_ && $current->name instanceof \PhpParser\Node\Identifier ) {
                    return $current->name->toString() . '()';
                }
                if ( $current instanceof \PhpParser\Node\Expr\Closure ) {
                    return __( 'anonymous function', 'kiss-woo-shipping-debugger' );
                }
                if ( $current instanceof \PhpParser\Node\Stmt\ClassMethod && $current->name instanceof \PhpParser\Node\Identifier) {
                    $className = $this->get_parent_class_name($current);
                    return $className . '::' . $current->name->toString() . '()';
                }
            }
             if ( ! $current instanceof \PhpParser\Node ) break;
        }
        return __( 'global scope', 'kiss-woo-shipping-debugger' );
    }

     /**
     * Get the name of the parent class for a given AST node.
     */
    private function get_parent_class_name( \PhpParser\Node $node ): string {
        $current = $node;
        while ( $current = $current->getAttribute( 'parent' ) ) {
            if ( $current instanceof \PhpParser\Node\Stmt\Class_ ) {
                return $current->name instanceof \PhpParser\Node\Identifier ? $current->name->toString() : 'class';
            }
             if ( ! $current instanceof \PhpParser\Node ) break;
        }
        return '';
    }

    /**
     * Create and return a PHP-Parser instance using the newest supported version.
     * Kept as a helper for DRY use across the parser self-test and the scanner.
     */
    private function create_parser() {
        $factory = new \PhpParser\ParserFactory();
        if ( method_exists( $factory, 'createForNewestSupportedVersion' ) ) {
            return $factory->createForNewestSupportedVersion();
        }
        return $factory->create( \PhpParser\ParserFactory::PREFER_PHP7 );
    }

    /**
     * Produce a short label used in list items.
     */
    private function short_explanation_label( string $key ): string {
        switch ( $key ) {
            case 'filterHooks': return __( 'Modifies shipping rates', 'kiss-woo-shipping-debugger' );
            case 'feeHooks':    return __( 'Adjusts cart fees/totals', 'kiss-woo-shipping-debugger' );
            case 'rateCalls':   return __( 'Adds a custom rate', 'kiss-woo-shipping-debugger' );
            case 'newRates':    return __( 'Creates a rate object', 'kiss-woo-shipping-debugger' );
            case 'unsetRates':  return __( 'Removes a rate', 'kiss-woo-shipping-debugger' );
            case 'addFees':     return __( 'Adds a cart fee', 'kiss-woo-shipping-debugger' );
            case 'errors':      return __( 'Checkout rule', 'kiss-woo-shipping-debugger' );
            default:            return __( 'Matched code', 'kiss-woo-shipping-debugger' );
        }
    }

    /**
     * Return a human-readable explanation for a given node.
     * Conservative and static: uses simple extraction heuristics, no execution.
     *
     * @param string          $key  Section key.
     * @param \PhpParser\Node $node Matched node.
     * @return string
     */
    private function describe_node( string $key, \PhpParser\Node $node ): string {
        try {
            $when = $this->condition_chain_text( $node );

            switch ( $key ) {
                case 'errors':
                    $summary = __( 'Adds a checkout error message', 'kiss-woo-shipping-debugger' );
                    if ( property_exists( $node, 'args' ) && isset( $node->args[1] ) ) {
                        $msg = $this->extract_string( $node->args[1]->value );
                        if ( $msg !== '' ) {
                            $msg = $this->bold_product_and_state_names( esc_html($msg) );
                            $summary .= sprintf( ': “<em>%s</em>”', $msg );
                        }
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'when %s', 'kiss-woo-shipping-debugger' ), '<strong>' . esc_html($when) . '</strong>' );
                    }
                    return $summary . '.';

                case 'filterHooks':
                    // add_filter('woocommerce_package_rates', callback, ...)
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    if ( $cb ) {
                        return sprintf(
                            __( 'Theme code hooks into WooCommerce package rates (%s) to change which shipping options appear.', 'kiss-woo-shipping-debugger' ),
                            $cb
                        );
                    }
                    return __( 'Theme code hooks into WooCommerce package rates to change which shipping options appear.', 'kiss-woo-shipping-debugger' );

                case 'feeHooks':
                    // add_action('woocommerce_cart_calculate_fees', callback, ...)
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    if ( $cb ) {
                        return sprintf(
                            __( 'Runs during cart fee calculation (%s). This can add discounts/surcharges and affect totals.', 'kiss-woo-shipping-debugger' ),
                            $cb
                        );
                    }
                    return __( 'Runs during cart fee calculation. This can add discounts/surcharges and affect totals.', 'kiss-woo-shipping-debugger' );

                case 'rateCalls':
                    return __( 'Calls add_rate() to insert a custom shipping option programmatically.', 'kiss-woo-shipping-debugger' );

                case 'newRates':
                    // new WC_Shipping_Rate(id, label, cost, meta, method_id)
                    $parts = [];
                    if ( property_exists( $node, 'args' ) ) {
                        // Try to resolve ID from a variable assignment in scope
                        $idExpr = isset( $node->args[0] ) ? $node->args[0]->value : null;
                        $id     = $this->string_or_resolved_variable( $node, $idExpr );

                        $label  = isset( $node->args[1] ) ? $this->extract_string_or_placeholder( $node->args[1]->value ) : '';
                        $cost   = isset( $node->args[2] ) ? $this->extract_string_or_placeholder( $node->args[2]->value ) : '';
                        if ( $id !== '' )    { $parts[] = sprintf( __( 'id “%s”', 'kiss-woo-shipping-debugger' ), $id ); }
                        if ( $label !== '' ) { $parts[] = sprintf( __( 'label “%s”', 'kiss-woo-shipping-debugger' ), $label ); }
                        if ( $cost !== '' )  { $parts[] = sprintf( __( 'cost %s', 'kiss-woo-shipping-debugger' ), $cost ); }
                    }
                    $summary = __( 'Instantiates WC_Shipping_Rate directly, creating a shipping option in code.', 'kiss-woo-shipping-debugger' );
                    if ( ! empty( $parts ) ) {
                        $summary .= ' ' . sprintf( __( 'Details: %s.', 'kiss-woo-shipping-debugger' ), implode( ', ', $parts ) );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), '<strong>' . esc_html($when) . '</strong>' );
                    }
                    return $summary;

                case 'unsetRates':
                    // unset($rates['key']) or dynamic
                    $keyStr = $this->extract_unset_rate_key( $node );
                    $summary = '';
                    if ( $this->condition_mentions_free_shipping( $node ) ) {
                        $summary = __( 'Removes the free shipping rate', 'kiss-woo-shipping-debugger' );
                    } elseif ( $keyStr !== '' ) {
                        $summary = sprintf(
                            __( 'Removes a shipping rate by key (%s)', 'kiss-woo-shipping-debugger' ),
                            '<code>' . esc_html($keyStr) . '</code>'
                        );
                    } else {
                        $summary = __( 'Removes one or more shipping rates from the available options', 'kiss-woo-shipping-debugger' );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'when %s', 'kiss-woo-shipping-debugger' ), '<strong>' . esc_html($when) . '</strong>' );
                    }
                    return $summary;

                case 'addFees':
                    // add_fee( name, amount, ... )
                    $parts = [];
                    if ( property_exists( $node, 'args' ) ) {
                        $label  = isset( $node->args[0] ) ? $this->extract_string_or_placeholder( $node->args[0]->value ) : '';

                        // If amount is a variable, try to describe its assignment (e.g., from a match expression)
                        if ( isset( $node->args[1] ) && $node->args[1]->value instanceof \PhpParser\Node\Expr\Variable ) {
                            $amount = $this->describe_variable_assignment( $node->args[1]->value );
                        } else {
                            $amount = isset( $node->args[1] ) ? $this->extract_string_or_placeholder( $node->args[1]->value ) : '';
                        }

                        if ( $label !== '' )  { $parts[] = sprintf( __( 'label “%s”', 'kiss-woo-shipping-debugger' ), $label ); }
                        if ( $amount !== '' ) { $parts[] = sprintf( __( 'amount %s', 'kiss-woo-shipping-debugger' ), $amount ); }
                    }
                    $summary = __( 'Adds a fee to the cart.', 'kiss-woo-shipping-debugger' );
                    if ( ! empty( $parts ) ) {
                        $summary .= ' ' . sprintf( __( 'Details: %s.', 'kiss-woo-shipping-debugger' ), implode( ', ', $parts ) );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), '<strong>' . esc_html($when) . '</strong>' );
                    }
                    return $summary;
            }

            return '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }
    
    /**
     * Attempts to bold product and state names in a string based on keywords.
     */
    private function bold_product_and_state_names(string $text): string {
        // Bold names after "to "
        $text = preg_replace_callback( '/to\s+([A-Z][a-zA-Z\s]+)/', function( $matches ) {
            return 'to <strong>' . $matches[1] . '</strong>';
        }, $text );

        // Bold names before " products"
        $text = preg_replace_callback( '/([A-Z][a-zA-Z\s]+)\s+products/', function( $matches ) {
            return '<strong>' . $matches[1] . '</strong> products';
        }, $text );

        return $text;
    }


    /**
     * Extract a string from an expression. Handles:
     * - direct strings
     * - interpolated strings
     * - concatenation
     * - translation wrappers: __("..."), esc_html__(), etc.
     * - sprintf("fmt %s", $x) -> "fmt {x}"
     * For non-literals, we render placeholders like {var}, {func()}, {obj->prop}, {arr[key]}.
     */
    private function extract_string( $expr ): string {
        // Direct string
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
            return (string) $expr->value;
        }

        // Interpolated strings
        if ( $expr instanceof \PhpParser\Node\Scalar\Encapsed ) {
            $out = '';
            foreach ( $expr->parts as $p ) {
                if ( $p instanceof \PhpParser\Node\Scalar\EncapsedStringPart ) {
                    $out .= $p->value;
                } else {
                    $out .= $this->expr_placeholder( $p, true ); // simplify placeholders
                }
            }
            return $out;
        }

        // Concatenation
        if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat ) {
            return $this->extract_string( $expr->left ) . $this->extract_string( $expr->right );
        }

        // Translation wrappers
        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            $fn = strtolower( $expr->name->toString() );

            // i18n wrappers like __("string", "domain")
            $i18n = [ '__', 'esc_html__', 'esc_attr__', '_x', '_nx', '_ex' ];
            if ( in_array( $fn, $i18n, true ) && isset( $expr->args[0] ) ) {
                return $this->extract_string( $expr->args[0]->value );
            }

            // sprintf("format %s ...", args...)
            if ( $fn === 'sprintf' && isset( $expr->args[0] ) ) {
                $fmt = $this->extract_string( $expr->args[0]->value );
                $argTokens = [];
                for ( $i = 1; isset( $expr->args[$i] ); $i++ ) {
                    $argTokens[] = $this->expr_placeholder( $expr->args[$i]->value, true ); // simplify placeholders
                }
                // Replace %s/%d etc. sequentially (simple heuristic)
                $idx = 0;
                $out = preg_replace_callback('/%[%bcdeEufFgGosxX]/', function($m) use (&$idx, $argTokens) {
                    if ($m[0] === '%%') return '%';
                    $token = $argTokens[$idx] ?? '[?]';
                    $idx++;
                    return $token;
                }, $fmt );
                return $out ?? $fmt;
            }
        }

        // Fallback placeholder
        return $this->expr_placeholder( $expr );
    }

    /**
     * Like extract_string(), but if it's not a clear string, return a placeholder.
     */
    private function extract_string_or_placeholder( $expr ): string {
        $s = $this->extract_string( $expr );
        if ( $s !== '' && $s[0] !== '[' ) {
            return $s;
        }
        return $this->expr_placeholder( $expr );
    }

    /**
     * Render a readable placeholder for an arbitrary expression.
     * Examples: $postcode -> {postcode}, $arr[$state] -> {arr[$state]}, $obj->method() -> {obj->method()}
     */
    private function expr_placeholder( $expr, bool $simplify = false ): string {
        if ($simplify) {
             if ($expr instanceof \PhpParser\Node\Expr\ArrayDimFetch) {
                // Try to get a meaningful name from the array variable
                if ($expr->var instanceof \PhpParser\Node\Expr\Variable && is_string($expr->var->name)) {
                     if (strpos(strtolower($expr->var->name), 'state') !== false) return '[state name]';
                     if (strpos(strtolower($expr->var->name), 'postcode') !== false) return '[postcode]';
                     if (strpos(strtolower($expr->var->name), 'city') !== false) return '[city name]';
                }
                return '[value]';
             }
             if ($expr instanceof \PhpParser\Node\Expr\Variable) return '[' . (is_string($expr->name) ? $expr->name : 'variable') . ']';
             return '[value]';
        }

        try {
            if ( $expr instanceof \PhpParser\Node\Expr\Variable ) {
                return '{' . (is_string($expr->name) ? $expr->name : '?') . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
                $var  = $this->expr_placeholder( $expr->var );
                $dim  = $expr->dim ? $this->extract_string( $expr->dim ) : '';
                if ( $dim === '' && $expr->dim ) $dim = $this->expr_placeholder( $expr->dim );
                return str_replace(['{','}'],'',$var) ? '{' . trim($var, '{}') . '[' . $dim . ']}' : '{array[' . $dim . ']}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
                $obj = trim( $this->expr_placeholder( $expr->var ), '{}' );
                $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $obj . '->' . $prop . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\MethodCall ) {
                $obj = trim( $this->expr_placeholder( $expr->var ), '{}' );
                $meth = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $obj . '->' . $meth . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\StaticCall ) {
                $cls = $expr->class instanceof \PhpParser\Node\Name ? $expr->class->toString() : '?';
                $meth = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $cls . '::' . $meth . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
                return $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber ) {
                return (string) $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->name instanceof \PhpParser\Node\Name ) {
                return '{' . $expr->name->toString() . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
                return '{' . $expr->name->toString() . '()}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat ) {
                return $this->extract_string( $expr ); // handled already
            }
        } catch ( \Throwable $e ) {
            // ignore and fall through
        }
        return '{?}';
    }

    /**
     * Describe a callback (string function name or array(Class/obj, 'method')).
     */
    private function describe_callback( $expr ): string {
        try {
            if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
                return $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Expr\Array_ && isset( $expr->items[1] ) && $expr->items[1]->value instanceof \PhpParser\Node\Scalar\String_ ) {
                $method = $expr->items[1]->value->value;
                // Class/variable part may be complex; keep simple:
                return '::' . $method;
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    /**
     * Extract the rate key from unset($rates['key']) if available.
     * Returns a literal or a readable placeholder. Attempts local variable resolution.
     */
    private function extract_unset_rate_key( \PhpParser\Node $unsetStmt ): string {
        try {
            if ( $unsetStmt instanceof \PhpParser\Node\Stmt\Unset_ && isset( $unsetStmt->vars[0] ) && $unsetStmt->vars[0] instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {

                $dim = $unsetStmt->vars[0]->dim;
                if ( $dim instanceof \PhpParser\Node\Scalar\String_ ) {
                    return $dim->value;
                }
                if ( $dim instanceof \PhpParser\Node\Expr\Variable && is_string( $dim->name ) ) {
                    $resolved = $this->resolve_variable_value( $unsetStmt, $dim->name );
                    if ( is_string( $resolved ) && $resolved !== '' ) {
                        return $resolved;
                    }
                }
                if ( $dim ) {
                    // Render readable placeholder for dynamic key
                    $ph = $this->extract_string( $dim );
                    if ( $ph === '' ) {
                        $ph = $this->expr_placeholder( $dim );
                    }
                    return $ph;
                }
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    /**
     * If $expr is a variable, try to resolve a string assignment in the same function-like scope.
     * Otherwise, return extract_string_or_placeholder().
     */
    private function string_or_resolved_variable( \PhpParser\Node $ctx, $expr ): string {
        if ( $expr instanceof \PhpParser\Node\Expr\Variable && is_string( $expr->name ) ) {
            $val = $this->resolve_variable_value( $ctx, $expr->name );
            if ( is_string( $val ) && $val !== '' ) {
                return $val;
            }
        }
        return $this->extract_string_or_placeholder( $expr );
    }

    /**
     * Resolve the most recent scalar string assigned to $varName in the same function-like scope
     * before the line of $fromNode.
     */
    private function resolve_variable_value( \PhpParser\Node $fromNode, string $varName ): ?string {
        try {
            // Find nearest function-like ancestor
            $cur = $fromNode;
            $scope = null;
            while ( $cur ) {
                if ( $cur instanceof \PhpParser\Node\FunctionLike ) { $scope = $cur; break; }
                $cur = $cur->getAttribute('parent');
                if ( ! $cur instanceof \PhpParser\Node ) break;
            }
            if ( ! $scope ) return null;

            $finder = new \PhpParser\NodeFinder();
            /** @var \PhpParser\Node\Expr\Assign[] $assigns */
            $assigns = $finder->findInstanceOf( $scope, \PhpParser\Node\Expr\Assign::class );

            $line = $fromNode->getLine();
            $best   = null;
            $bestLn = -1;

            foreach ( $assigns as $as ) {
                if ( $as->var instanceof \PhpParser\Node\Expr\Variable && is_string( $as->var->name ) && $as->var->name === $varName ) {
                    $ln = (int) $as->getLine();
                    if ( $ln < $line && $ln > $bestLn ) {
                        // Accept simple strings or expressions we can stringify
                        $str = $this->extract_string( $as->expr );
                        if ( $str === '' ) {
                            if ( $as->expr instanceof \PhpParser\Node\Scalar\String_ ) {
                                $str = $as->expr->value;
                            }
                        }
                        if ( $str !== '' ) {
                            $best   = $str;
                            $bestLn = $ln;
                        }
                    }
                }
            }
            return $best;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * Return a natural-language description of the chain of enclosing conditions
     * for a node (nearest first), e.g. "the cart contains drinks and the non-drink subtotal is under $20".
     */
    private function condition_chain_text( \PhpParser\Node $node ): string {
        $conds = [];
        $cur = $node;
        $limit = 4; // keep short
        while ( $limit-- > 0 && $cur ) {
            $parent = $cur->getAttribute('parent');
            if ( $parent instanceof \PhpParser\Node\Stmt\If_ ) {
                $desc = $this->cond_to_text( $parent->cond );
                if ( $desc !== '' ) $conds[] = $desc;
            }
            $cur = $parent instanceof \PhpParser\Node ? $parent : null;
        }
        if ( empty( $conds ) ) return '';
        // De-duplicate simple repeats
        $conds = array_values( array_unique( array_filter( $conds ) ) );
        return implode( ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ', $conds );
    }

    /**
     * Finds the assignment for a variable and returns a human-readable description of it.
     * Special-cased for match expressions.
     */
    private function describe_variable_assignment( \PhpParser\Node\Expr\Variable $var ): string {
        try {
            $varName = is_string( $var->name ) ? $var->name : null;
            if ( ! $varName ) {
                return $this->expr_placeholder( $var );
            }

            // Find nearest function-like ancestor to limit scope
            $scope = null;
            $cur   = $var;
            while ( $cur = $cur->getAttribute( 'parent' ) ) {
                if ( $cur instanceof \PhpParser\Node\FunctionLike ) {
                    $scope = $cur;
                    break;
                }
                if ( ! $cur instanceof \PhpParser\Node ) break;
            }
            if ( ! $scope ) return $this->expr_placeholder( $var );

            // Find the last assignment to this variable before its use
            $finder  = new \PhpParser\NodeFinder();
            /** @var \PhpParser\Node\Expr\Assign[] $assigns */
            $assigns = array_filter(
                $finder->findInstanceOf( $scope, \PhpParser\Node\Expr\Assign::class ),
                fn( $a ) => ( $a->var instanceof \PhpParser\Node\Expr\Variable && $a->var->name === $varName && $a->getLine() < $var->getLine() )
            );

            if ( empty( $assigns ) ) return $this->expr_placeholder( $var );
            $lastAssign = end( $assigns );

            if ( $lastAssign->expr instanceof \PhpParser\Node\Expr\Match_ ) {
                return __( 'is determined by conditional logic (a match statement)', 'kiss-woo-shipping-debugger' );
            }
        } catch ( \Throwable $e ) {} // Fall through on error
        return $this->expr_placeholder( $var );
    }

    /**
     * Convert a boolean expression into a short readable phrase.
     */
    private function cond_to_text( $expr ): string {
        try {
            // (A && B) / (A || B)
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd ) {
                $left = $this->cond_to_text( $expr->left );
                $right = $this->cond_to_text( $expr->right );
                $glue = ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanOr ) {
                $left = $this->cond_to_text( $expr->left );
                $right = $this->cond_to_text( $expr->right );
                $glue = ' ' . __( 'or', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }

            // Special-case: strpos( ... , 'free_shipping') !== false  (or !=)
            $isFreeShip = function($call) {
                return ($call instanceof \PhpParser\Node\Expr\FuncCall)
                    && ($call->name instanceof \PhpParser\Node\Name)
                    && (strtolower($call->name->toString()) === 'strpos')
                    && isset($call->args[1])
                    && strtolower($this->extract_string($call->args[1]->value)) === 'free_shipping';
            };
            if ( ($expr instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical || $expr instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual)
                 && (
                      ($isFreeShip($expr->left) && $this->is_false_const($expr->right))
                      || ($isFreeShip($expr->right) && $this->is_false_const($expr->left))
                 ) ) {
                return __( 'the rate is a Free Shipping method', 'kiss-woo-shipping-debugger' );
            }

            // Comparisons with friendly variable names
            $opMap = [
                \PhpParser\Node\Expr\BinaryOp\Smaller::class        => '<',
                \PhpParser\Node\Expr\BinaryOp\SmallerOrEqual::class => '<=',
                \PhpParser\Node\Expr\BinaryOp\Greater::class        => '>',
                \PhpParser\Node\Expr\BinaryOp\GreaterOrEqual::class => '>=',
                \PhpParser\Node\Expr\BinaryOp\Equal::class          => '==',
                \PhpParser\Node\Expr\BinaryOp\NotEqual::class       => '!=',
                \PhpParser\Node\Expr\BinaryOp\Identical::class      => '===',
                \PhpParser\Node\Expr\BinaryOp\NotIdentical::class   => '!==',
            ];
            foreach ( $opMap as $cls => $op ) {
                if ( $expr instanceof $cls ) {
                    // adjusted_total < number → "the non-drink subtotal is under $N"
                    if ( $this->is_var_named( $expr->left, 'adjusted_total' ) && $this->is_number_like( $expr->right ) ) {
                        $num = $this->price_to_text( (float) $expr->right->value );
                        switch ( $op ) {
                            case '<':  return sprintf( __( 'the non-drink subtotal is under %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '<=': return sprintf( __( 'the non-drink subtotal is at most %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '>':  return sprintf( __( 'the non-drink subtotal is over %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            case '>=': return sprintf( __( 'the non-drink subtotal is at least %s', 'kiss-woo-shipping-debugger' ), esc_html( $num ) );
                            default:   return 'adjusted_total ' . $op . ' ' . $num;
                        }
                    }
                    $left_text = $this->simple_expr_text($expr->left);
                    $right_text = $this->simple_expr_text($expr->right);
                    return $left_text . ' ' . $op . ' ' . $right_text;
                }
            }

            // Negation
            if ( $expr instanceof \PhpParser\Node\Expr\BooleanNot ) {
                $inner = $this->simple_expr_text( $expr->expr );
                if ( $this->is_var_named( $expr->expr, 'has_drinks' ) ) {
                    return __( 'the cart does not contain drinks', 'kiss-woo-shipping-debugger' );
                }
                return __( 'not', 'kiss-woo-shipping-debugger' ) . ' ' . $inner;
            }

            // Bare variables with friendly names
            if ( $this->is_var_named( $expr, 'has_drinks' ) ) {
                return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
            }
            
            // Function calls like has_term(...)
            if ($expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name && $expr->name->toString() === 'has_term') {
                if (isset($expr->args[0], $expr->args[1]) && $expr->args[1]->value instanceof \PhpParser\Node\Scalar\String_ && $expr->args[1]->value->value === 'product_cat') {
                    $terms = $this->extract_string($expr->args[0]->value);
                    return sprintf(__('cart contains product from category %s', 'kiss-woo-shipping-debugger'), '<code>' . esc_html($terms) . '</code>');
                }
            }
            
            // isset() calls
            if ($expr instanceof \PhpParser\Node\Expr\Isset_) {
                $vars = array_map([$this, 'simple_expr_text'], $expr->vars);
                return sprintf(__('%s is set', 'kiss-woo-shipping-debugger'), implode(', ', $vars));
            }


            // Fallback
            return $this->simple_expr_text( $expr );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private function is_false_const( $expr ): bool {
        return $expr instanceof \PhpParser\Node\Expr\ConstFetch
            && $expr->name instanceof \PhpParser\Node\Name
            && strtolower($expr->name->toString()) === 'false';
    }

    private function is_var_named( $expr, string $name ): bool {
        return $expr instanceof \PhpParser\Node\Expr\Variable
            && is_string( $expr->name )
            && $expr->name === $name;
    }

    private function is_number_like( $expr ): bool {
        return $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber;
    }

    /**
     * Render a short text for simple expressions used in conditions.
     */
    private function simple_expr_text( $expr ): string {
        if ( $this->is_var_named( $expr, 'has_drinks' ) ) return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
        if ( $this->is_var_named( $expr, 'adjusted_total' ) ) return __( 'the non-drink subtotal', 'kiss-woo-shipping-debugger' );
        if ( $this->is_var_named( $expr, 'state' ) ) return __( 'the state', 'kiss-woo-shipping-debugger' );
        if ( $this->is_var_named( $expr, 'postcode' ) ) return __( 'the postcode', 'kiss-woo-shipping-debugger' );
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) return "'" . $expr->value . "'";
        if ( $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber ) return (string) $expr->value;
        if ( $expr instanceof \PhpParser\Node\Expr\Variable ) return (is_string($expr->name) ? (string)$expr->name : '{var}');
        if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
            $obj = $this->simple_expr_text( $expr->var );
            $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
            return $obj . '->' . $prop;
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
            $arr = $this->simple_expr_text( $expr->var );
            $dim = $expr->dim ? $this->simple_expr_text( $expr->dim ) : '';
            return $arr . '[' . $dim . ']';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString() . '()';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString();
        }
        return $this->expr_placeholder( $expr );
    }

    /**
     * Heuristic: check if the immediate condition mentions free shipping.
     */
    private function condition_mentions_free_shipping( \PhpParser\Node $node ): bool {
        // Look at nearest If_ condition above the node for a strpos(..., 'free_shipping') !== false
        $cur = $node;
        $steps = 2;
        while ( $steps-- > 0 && $cur ) {
            $parent = $cur->getAttribute('parent');
            if ( $parent instanceof \PhpParser\Node\Stmt\If_ ) {
                $cond = $parent->cond;
                $isFree = function($call) {
                    return ($call instanceof \PhpParser\Node\Expr\FuncCall)
                        && ($call->name instanceof \PhpParser\Node\Name)
                        && (strtolower($call->name->toString()) === 'strpos')
                        && isset($call->args[1])
                        && strtolower($this->extract_string($call->args[1]->value)) === 'free_shipping';
                };
                if ( ($cond instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical || $cond instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual)
                     && (
                          ($isFree($cond->left) && $this->is_false_const($cond->right))
                          || ($isFree($cond->right) && $this->is_false_const($cond->left))
                     ) ) {
                    return true;
                }
            }
            $cur = $parent instanceof \PhpParser\Node ? $parent : null;
        }
        return false;
    }
}