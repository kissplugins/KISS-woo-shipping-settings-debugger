<?php
/**
 * Trait providing AST scanning utilities used by the debugger.
 */
trait KISS_WSE_Scanner {
    private function scan_and_render_custom_rules( ?string $additional ): void {
        require_once plugin_dir_path( __FILE__ ) . 'lib/RateAddCallVisitor.php';
        require_once plugin_dir_path( __FILE__ ) . 'lib/ArrayCollectorVisitor.php';
    
        // --- 1. GATHER FILES ---
        $files_to_scan = [];
        $default_file = wp_normalize_path( trailingslashit( get_stylesheet_directory() ) . 'inc/shipping-restrictions.php' );
        if ( file_exists($default_file) ) {
            $files_to_scan[] = $default_file;
        }
    
        $base_dir  = wp_normalize_path( get_stylesheet_directory() );
        $base_real = realpath( $base_dir );
    
        if ( $additional && $base_real ) {
            $rel   = ltrim( wp_normalize_path( $additional ), '/\\' );
            $try   = wp_normalize_path( $base_real . DIRECTORY_SEPARATOR . $rel );
            $real  = realpath( $try );
    
            if ( $real && is_file($real) ) {
                $real_norm = wp_normalize_path( $real );
                $base_norm = wp_normalize_path( $base_real );
                if ( strncmp( $real_norm, $base_norm, strlen( $base_norm ) ) === 0 && !in_array($real, $files_to_scan) ) {
                    $files_to_scan[] = $real;
                }
            } else {
                 echo '<div class="notice notice-warning"><p>' . esc_html__( 'Additional file not found. Please check the path.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
            }
        }
    
        // --- 2. COLLECT ALL FINDINGS FROM ALL FILES ---
        $all_findings = [];
        $collected_arrays = []; // Master lookup for all arrays found in all files.
        foreach ( $files_to_scan as $file ) {
            echo '<h3>Scanning <code>' . esc_html( wp_make_link_relative( $file ) ) . '</code></h3>';

            if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
                echo '<p><em>' . esc_html__( 'PHP-Parser not available. Unable to scan file:', 'kiss-woo-shipping-debugger' ) . ' ' . esc_html(wp_make_link_relative($file)) . '</em></p>';
                continue;
            }
    
            $code   = file_get_contents( $file );
            $parser = $this->create_parser();
            $ast    = $parser->parse( $code );
            $trav   = new \PhpParser\NodeTraverser();

            // Add visitors. The ArrayCollector must run before the RateAddCallVisitor.
            $trav->addVisitor( new \PhpParser\NodeVisitor\ParentConnectingVisitor() );
            $array_collector = new \KISSShippingDebugger\ArrayCollectorVisitor();
            $trav->addVisitor( $array_collector );
            $rate_visitor = new \KISSShippingDebugger\RateAddCallVisitor();
            $trav->addVisitor( $rate_visitor );
            $trav->traverse( $ast );

            // Store the collected arrays for this file.
            $collected_arrays[$file] = $array_collector->getArraysByScope();
    
            $sections = [
                'errors'      => $rate_visitor->getErrorAddNodes(),
                'unsetRates'  => $rate_visitor->getUnsetRateNodes(),
                'filterHooks' => $rate_visitor->getFilterHookNodes(),
                'feeHooks'    => $rate_visitor->getFeeHookNodes(),
                'rateCalls'   => $rate_visitor->getAddRateNodes(),
                'newRates'    => $rate_visitor->getNewRateNodes(),
                'addFees'     => $rate_visitor->getAddFeeNodes(),
            ];
    
            foreach($sections as $key => $nodes) {
                foreach($nodes as $node) {
                    $all_findings[] = [
                        'file' => $file,
                        'key'  => $key,
                        'node' => $node,
                    ];
                }
            }
        }
    
        if ( empty( $all_findings ) ) {
            echo '<p><em>' . esc_html__( 'No shipping-related rules found in the scanned files.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
            return;
        }

        echo '<div id="product-content" class="kiss-group-content active">';
        $this->render_product_grouping( $all_findings, $collected_arrays );
        echo '</div>';

        echo '<div id="functional-content" class="kiss-group-content">';
        $this->render_functional_grouping( $all_findings, $collected_arrays );
        echo '</div>';
    }

    private function render_product_grouping( array $all_findings, array $collected_arrays ): void {
        $grouped_rules = [];
        $product_keywords = ['Kratom', 'Amanita Mushroom', 'THC-A', 'CBD'];

        foreach ( $all_findings as $finding ) {
            $description = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'], true );
            $found_keyword = 'OTHER RULES';

            foreach ( $product_keywords as $keyword ) {
                if ( stripos( $description, $keyword ) !== false ) {
                    $found_keyword = strtoupper( $keyword );
                    break;
                }
            }
            $grouped_rules[ $found_keyword ][] = $finding;
        }

        if ( isset( $grouped_rules['OTHER RULES'] ) ) {
            $other_rules = $grouped_rules['OTHER RULES'];
            unset( $grouped_rules['OTHER RULES'] );
            $grouped_rules['OTHER RULES'] = $other_rules;
        }

        foreach ( $grouped_rules as $product => $findings ) {
            printf( '<h4 style="color: red;"><strong>%s</strong></h4>', esc_html( $product ) );
            echo '<ul>';
            foreach ( $findings as $finding ) {
                $line     = (int) $finding['node']->getLine();
                $filename = basename( $finding['file'] );
                $desc     = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'] );

                printf(
                    '<li><strong>%s</strong> — %s %s</li>',
                    esc_html( $this->short_explanation_label( $finding['key'] ) ),
                    wp_kses_post( $desc ),
                    sprintf( '<span style="opacity:.7;">(%s %d - %s)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ), esc_html( $filename ) )
                );
            }
            echo '</ul>';
        }
    }

    private function render_functional_grouping( array $all_findings, array $collected_arrays ): void {
        $grouped = [];
        foreach ( $all_findings as $finding ) {
            $function = $this->get_enclosing_function_name( $finding['node'] );
            $label    = sprintf( '%s — %s', $function, basename( $finding['file'] ) );
            $grouped[ $label ][] = $finding;
        }

        foreach ( $grouped as $label => $findings ) {
            printf( '<h4 style="color: red;"><strong>%s</strong></h4>', esc_html( $label ) );
            echo '<ul>';
            foreach ( $findings as $finding ) {
                $line     = (int) $finding['node']->getLine();
                $filename = basename( $finding['file'] );
                $desc     = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'] );

                printf(
                    '<li><strong>%s</strong> — %s %s</li>',
                    esc_html( $this->short_explanation_label( $finding['key'] ) ),
                    wp_kses_post( $desc ),
                    sprintf( '<span style="opacity:.7;">(%s %d - %s)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ), esc_html( $filename ) )
                );
            }
            echo '</ul>';
        }
    }

    private function get_enclosing_function_name( \PhpParser\Node $node ): string {
        $current = $node;
        while ( $parent = $current->getAttribute( 'parent' ) ) {
            if ( $parent instanceof \PhpParser\Node\FunctionLike ) {
                if ( $parent instanceof \PhpParser\Node\Stmt\ClassMethod ) {
                    $class = $parent->getAttribute( 'parent' );
                    $class_name = ( $class instanceof \PhpParser\Node\Stmt\ClassLike && $class->name instanceof \PhpParser\Node\Identifier )
                        ? $class->name->toString() . '::'
                        : '';
                    $method = $parent->name instanceof \PhpParser\Node\Identifier ? $parent->name->toString() : '';
                    return $class_name . $method;
                }
                if ( $parent instanceof \PhpParser\Node\Stmt\Function_ ) {
                    return $parent->name instanceof \PhpParser\Node\Identifier ? $parent->name->toString() : '';
                }
                return __( 'Anonymous function', 'kiss-woo-shipping-debugger' );
            }
            $current = $parent;
        }
        return __( 'Global scope', 'kiss-woo-shipping-debugger' );
    }

    private function create_parser() {
        $factory = new \PhpParser\ParserFactory();
        if ( method_exists( $factory, 'createForNewestSupportedVersion' ) ) {
            return $factory->createForNewestSupportedVersion();
        }
        return $factory->create( \PhpParser\ParserFactory::PREFER_PHP7 );
    }

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
     * Helper function to apply bolding rules to error messages.
     */
    private function format_error_message( string $message ): string {
        // 1. Bold specific, high-priority keywords
        $message = str_ireplace(
            ['Kratom'], // Oregon is handled by the state rule below
            ['<strong>Kratom</strong>'],
            $message
        );

        // 2. Bold product names (one or two words) before "products"
        $message = preg_replace(
            '/(\b[\w-]+(?:\s[\w-]+)?)\s+(products)\b/i',
            '<strong>$1</strong> $2',
            $message
        );
        
        // ADDED: Handle product names that appear before "or"
        $message = preg_replace(
            '/(\b[\w-]+(?:\s[\w-]+)?)\s+(or)\b/i',
            '<strong>$1</strong> $2',
            $message
        );

        // 3. Bold state names that appear after "to" or "for"
        $states = ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'];
        $states_pattern = implode('|', $states);
        $message = preg_replace(
            "/\b(to|for)\s+({$states_pattern})\b/i",
            '$1 <strong>$2</strong>',
            $message
        );

        return $message;
    }

    private function describe_node( string $key, \PhpParser\Node $node, array $collected_arrays, string $current_file, bool $raw = false ): string {
        try {
            switch ( $key ) {
                case 'errors':
                    if ( property_exists( $node, 'args' ) && isset( $node->args[1] ) ) {
                        $msg = $this->extract_string( $node->args[1]->value, $collected_arrays, $current_file );
                        if ( $msg !== '' ) {
                            $formatted_msg = $raw ? $msg : $this->format_error_message( $msg );
                            return sprintf(
                                __( 'Adds a checkout error message: “%s”. Customers will be blocked until they resolve it.', 'kiss-woo-shipping-debugger' ),
                                $formatted_msg
                            );
                        }
                    }
                    return __( 'Adds a checkout error message.', 'kiss-woo-shipping-debugger' );

                case 'filterHooks':
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
                    $parts = [];
                    if ( property_exists( $node, 'args' ) ) {
                        $idExpr = isset( $node->args[0] ) ? $node->args[0]->value : null;
                        $id     = $this->string_or_resolved_variable( $node, $idExpr, $collected_arrays, $current_file );

                        $label  = isset( $node->args[1] ) ? $this->extract_string_or_placeholder( $node->args[1]->value, $collected_arrays, $current_file ) : '';
                        $cost   = isset( $node->args[2] ) ? $this->extract_string_or_placeholder( $node->args[2]->value, $collected_arrays, $current_file ) : '';
                        if ( $id !== '' )    { $parts[] = sprintf( __( 'id “%s”', 'kiss-woo-shipping-debugger' ), $id ); }
                        if ( $label !== '' ) { $parts[] = sprintf( __( 'label “%s”', 'kiss-woo-shipping-debugger' ), $label ); }
                        if ( $cost !== '' )  { $parts[] = sprintf( __( 'cost %s', 'kiss-woo-shipping-debugger' ), $cost ); }
                    }
                    $when = $this->condition_chain_text( $node, $collected_arrays, $current_file );
                    $summary = __( 'Instantiates WC_Shipping_Rate directly, creating a shipping option in code.', 'kiss-woo-shipping-debugger' );
                    if ( ! empty( $parts ) ) {
                        $summary .= ' ' . sprintf( __( 'Details: %s.', 'kiss-woo-shipping-debugger' ), implode( ', ', $parts ) );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    return $summary;

                case 'unsetRates':
                    $keyStr = $this->extract_unset_rate_key( $node, $collected_arrays, $current_file );
                    $when   = $this->condition_chain_text( $node, $collected_arrays, $current_file );
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
                        $summary .= ' ' . sprintf( __( 'when %s', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    return $summary;

                case 'addFees':
                    $parts = [];
                    if ( property_exists( $node, 'args' ) ) {
                        $label  = isset( $node->args[0] ) ? $this->extract_string_or_placeholder( $node->args[0]->value, $collected_arrays, $current_file ) : '';

                        if ( isset( $node->args[1] ) && $node->args[1]->value instanceof \PhpParser\Node\Expr\Variable ) {
                            $amount = $this->describe_variable_assignment( $node->args[1]->value );
                        } else {
                            $amount = isset( $node->args[1] ) ? $this->extract_string_or_placeholder( $node->args[1]->value, $collected_arrays, $current_file ) : '';
                        }

                        if ( $label !== '' )  { $parts[] = sprintf( __( 'label “%s”', 'kiss-woo-shipping-debugger' ), $label ); }
                        if ( $amount !== '' ) { $parts[] = sprintf( __( 'amount %s', 'kiss-woo-shipping-debugger' ), $amount ); }
                    }
                    $when    = $this->condition_chain_text( $node, $collected_arrays, $current_file );
                    $summary = __( 'Adds a fee to the cart.', 'kiss-woo-shipping-debugger' );
                    if ( ! empty( $parts ) ) {
                        $summary .= ' ' . sprintf( __( 'Details: %s.', 'kiss-woo-shipping-debugger' ), implode( ', ', $parts ) );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    return $summary;
            }

            return '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private function extract_string( $expr, array $collected_arrays, string $current_file ): string {
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
            return (string) $expr->value;
        }

        if ( $expr instanceof \PhpParser\Node\Scalar\Encapsed ) {
            $out = '';
            foreach ( $expr->parts as $p ) {
                if ( $p instanceof \PhpParser\Node\Scalar\EncapsedStringPart ) {
                    $out .= $p->value;
                } else {
                    $out .= $this->expr_placeholder( $p, $collected_arrays, $current_file );
                }
            }
            return $out;
        }

        if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat ) {
            return $this->extract_string( $expr->left, $collected_arrays, $current_file ) . $this->extract_string( $expr->right, $collected_arrays, $current_file );
        }

        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            $fn = strtolower( $expr->name->toString() );

            $i18n = [ '__', 'esc_html__', 'esc_attr__', '_x', '_nx', '_ex' ];
            if ( in_array( $fn, $i18n, true ) && isset( $expr->args[0] ) ) {
                return $this->extract_string( $expr->args[0]->value, $collected_arrays, $current_file );
            }

            if ( $fn === 'sprintf' && isset( $expr->args[0] ) ) {
                $fmt = $this->extract_string( $expr->args[0]->value, $collected_arrays, $current_file );
                $argTokens = [];
                for ( $i = 1; isset( $expr->args[$i] ); $i++ ) {
                    $argTokens[] = $this->expr_placeholder( $expr->args[$i]->value, $collected_arrays, $current_file );
                }
                $idx = 0;
                $out = preg_replace_callback('/%[%bcdeEufFgGosxX]/', function($m) use (&$idx, $argTokens) {
                    if ($m[0] === '%%') return '%';
                    $token = $argTokens[$idx] ?? '{?}';
                    $idx++;
                    return $token;
                }, $fmt );
                return $out ?? $fmt;
            }
        }
        return $this->expr_placeholder( $expr, $collected_arrays, $current_file );
    }

    private function extract_string_or_placeholder( $expr, array $collected_arrays, string $current_file ): string {
        $s = $this->extract_string( $expr, $collected_arrays, $current_file );
        if ( $s !== '' && $s[0] !== '{' ) {
            return $s;
        }
        return $this->expr_placeholder( $expr, $collected_arrays, $current_file );
    }

    private function expr_placeholder( $expr, array $collected_arrays, string $current_file ): string {
        try {
            if ( $expr instanceof \PhpParser\Node\Expr\Variable ) {
                return '{' . (is_string($expr->name) ? $expr->name : '?') . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
                // This is where we try to resolve the array.
                if ( $expr->var instanceof \PhpParser\Node\Expr\Variable && is_string( $expr->var->name ) ) {
                    $var_name  = $expr->var->name;
                    $scope_key = $this->getCurrentScopeKey( $expr );
                    $file_arrays = $collected_arrays[$current_file] ?? [];

                    if ( isset( $file_arrays[$scope_key][$var_name] ) ) {
                        $array_data = $file_arrays[$scope_key][$var_name];
                        if( is_array($array_data) ) {
                            return $this->format_array_for_display( array_values($array_data) );
                        }
                    }
                }
                // Fallback to old behavior
                $var  = $this->expr_placeholder( $expr->var, $collected_arrays, $current_file );
                $dim  = $expr->dim ? $this->extract_string( $expr->dim, $collected_arrays, $current_file ) : '';
                if ( $dim === '' && $expr->dim ) $dim = $this->expr_placeholder( $expr->dim, $collected_arrays, $current_file );
                return str_replace(['{','}'],'',$var) ? '{' . trim($var, '{}') . '[' . $dim . ']}' : '{array[' . $dim . ']}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
                $obj = trim( $this->expr_placeholder( $expr->var, $collected_arrays, $current_file ), '{}' );
                $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
                return '{' . $obj . '->' . $prop . '}';
            }
            if ( $expr instanceof \PhpParser\Node\Expr\MethodCall ) {
                $obj = trim( $this->expr_placeholder( $expr->var, $collected_arrays, $current_file ), '{}' );
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
                return $this->extract_string( $expr, $collected_arrays, $current_file );
            }
        } catch ( \Throwable $e ) {
            // ignore and fall through
        }
        return '{?}';
    }

    private function describe_callback( $expr ): string {
        try {
            if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) {
                return $expr->value;
            }
            if ( $expr instanceof \PhpParser\Node\Expr\Array_ && isset( $expr->items[1] ) && $expr->items[1]->value instanceof \PhpParser\Node\Scalar\String_ ) {
                $method = $expr->items[1]->value->value;
                return '::' . $method;
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    private function extract_unset_rate_key( \PhpParser\Node $unsetStmt, array $collected_arrays, string $current_file ): string {
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
                    $ph = $this->extract_string( $dim, $collected_arrays, $current_file );
                    if ( $ph === '' ) {
                        $ph = $this->expr_placeholder( $dim, $collected_arrays, $current_file );
                    }
                    return $ph;
                }
            }
        } catch ( \Throwable $e ) {
            // ignore
        }
        return '';
    }

    private function string_or_resolved_variable( \PhpParser\Node $ctx, $expr, array $collected_arrays, string $current_file ): string {
        if ( $expr instanceof \PhpParser\Node\Expr\Variable && is_string( $expr->name ) ) {
            $val = $this->resolve_variable_value( $ctx, $expr->name );
            if ( is_string( $val ) && $val !== '' ) {
                return $val;
            }
        }
        return $this->extract_string_or_placeholder( $expr, $collected_arrays, $current_file );
    }

    private function resolve_variable_value( \PhpParser\Node $fromNode, string $varName ): ?string {
        try {
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
                        $str = $this->extract_string( $as->expr, [], '' ); // No array context here, it's a simple value lookup
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

    private function condition_chain_text( \PhpParser\Node $node, array $collected_arrays, string $current_file ): string {
        $conds = [];
        $cur = $node;
        $limit = 4;
        while ( $limit-- > 0 && $cur ) {
            $parent = $cur->getAttribute('parent');
            if ( $parent instanceof \PhpParser\Node\Stmt\If_ ) {
                $desc = $this->cond_to_text( $parent->cond, $collected_arrays, $current_file );
                if ( $desc !== '' ) $conds[] = $desc;
            }
            $cur = $parent instanceof \PhpParser\Node ? $parent : null;
        }
        if ( empty( $conds ) ) return '';
        $conds = array_values( array_unique( array_filter( $conds ) ) );
        return implode( ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ', $conds );
    }

    private function describe_variable_assignment( \PhpParser\Node\Expr\Variable $var ): string {
        try {
            $varName = is_string( $var->name ) ? $var->name : null;
            if ( ! $varName ) {
                return $this->expr_placeholder( $var, [], '' );
            }

            $scope = null;
            $cur   = $var;
            while ( $cur = $cur->getAttribute( 'parent' ) ) {
                if ( $cur instanceof \PhpParser\Node\FunctionLike ) {
                    $scope = $cur;
                    break;
                }
                if ( ! $cur instanceof \PhpParser\Node ) break;
            }
            if ( ! $scope ) return $this->expr_placeholder( $var, [], '' );

            $finder  = new \PhpParser\NodeFinder();
            /** @var \PhpParser\Node\Expr\Assign[] $assigns */
            $assigns = array_filter(
                $finder->findInstanceOf( $scope, \PhpParser\Node\Expr\Assign::class ),
                fn( $a ) => ( $a->var instanceof \PhpParser\Node\Expr\Variable && $a->var->name === $varName && $a->getLine() < $var->getLine() )
            );

            if ( empty( $assigns ) ) return $this->expr_placeholder( $var, [], '' );
            $lastAssign = end( $assigns );

            if ( $lastAssign->expr instanceof \PhpParser\Node\Expr\Match_ ) {
                return __( 'is determined by conditional logic (a match statement)', 'kiss-woo-shipping-debugger' );
            }
        } catch ( \Throwable $e ) {} // Fall through on error
        return $this->expr_placeholder( $var, [], '' );
    }

    private function cond_to_text( $expr, array $collected_arrays, string $current_file ): string {
        try {
            // Handle isset($restricted_states[$state]) and array_key_exists($state, $restricted_states)
            $is_array_check = false;
            $array_var_node = null;
            if ( $expr instanceof \PhpParser\Node\Expr\Isset_ && isset( $expr->vars[0] ) && $expr->vars[0] instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
                $is_array_check = true;
                $array_var_node = $expr->vars[0]->var;
            } elseif ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name && strtolower($expr->name->toString()) === 'array_key_exists' && isset($expr->args[1]) ) {
                $is_array_check = true;
                $array_var_node = $expr->args[1]->value;
            }

            if( $is_array_check && $array_var_node instanceof \PhpParser\Node\Expr\Variable && is_string( $array_var_node->name ) ) {
                $var_name  = $array_var_node->name;
                $scope_key = $this->getCurrentScopeKey( $expr );
                $file_arrays = $collected_arrays[$current_file] ?? [];
                
                if ( isset( $file_arrays[$scope_key][$var_name] ) ) {
                    $array_data = $file_arrays[$scope_key][$var_name];
                    if( is_array($array_data) && !empty($array_data) ) {
                        $list = $this->format_array_for_display( array_values($array_data) );
                        return sprintf( __( 'the location is one of: %s', 'kiss-woo-shipping-debugger' ), '<strong>' . esc_html( $list ) . '</strong>' );
                    }
                }
            }

            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanAnd ) {
                $left = $this->cond_to_text( $expr->left, $collected_arrays, $current_file );
                $right = $this->cond_to_text( $expr->right, $collected_arrays, $current_file );
                $glue = ' ' . __( 'and', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }
            if ( $expr instanceof \PhpParser\Node\Expr\BinaryOp\BooleanOr ) {
                $left = $this->cond_to_text( $expr->left, $collected_arrays, $current_file );
                $right = $this->cond_to_text( $expr->right, $collected_arrays, $current_file );
                $glue = ' ' . __( 'or', 'kiss-woo-shipping-debugger' ) . ' ';
                return trim( $left ) . $glue . trim( $right );
            }

            $isFreeShip = function($call) use ($collected_arrays, $current_file) {
                return ($call instanceof \PhpParser\Node\Expr\FuncCall)
                    && ($call->name instanceof \PhpParser\Node\Name)
                    && (strtolower($call->name->toString()) === 'strpos')
                    && isset($call->args[1])
                    && strtolower($this->extract_string($call->args[1]->value, $collected_arrays, $current_file)) === 'free_shipping';
            };
            if ( ($expr instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical || $expr instanceof \PhpParser\Node\Expr\BinaryOp\NotEqual)
                 && (
                      ($isFreeShip($expr->left) && $this->is_false_const($expr->right))
                      || ($isFreeShip($expr->right) && $this->is_false_const($expr->left))
                 ) ) {
                return __( 'the rate is a Free Shipping method', 'kiss-woo-shipping-debugger' );
            }

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
                    return $this->simple_expr_text( $expr->left, $collected_arrays, $current_file ) . ' ' . $op . ' ' . $this->simple_expr_text( $expr->right, $collected_arrays, $current_file );
                }
            }

            if ( $expr instanceof \PhpParser\Node\Expr\BooleanNot ) {
                $inner = $this->simple_expr_text( $expr->expr, $collected_arrays, $current_file );
                if ( $this->is_var_named( $expr->expr, 'has_drinks' ) ) {
                    return __( 'the cart does not contain drinks', 'kiss-woo-shipping-debugger' );
                }
                return __( 'not', 'kiss-woo-shipping-debugger' ) . ' ' . $inner;
            }

            if ( $this->is_var_named( $expr, 'has_drinks' ) ) {
                return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
            }

            return $this->simple_expr_text( $expr, $collected_arrays, $current_file );
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

    private function simple_expr_text( $expr, array $collected_arrays, string $current_file ): string {
        if ( $this->is_var_named( $expr, 'has_drinks' ) ) return __( 'the cart contains drinks', 'kiss-woo-shipping-debugger' );
        if ( $this->is_var_named( $expr, 'adjusted_total' ) ) return __( 'the non-drink subtotal', 'kiss-woo-shipping-debugger' );
        if ( $expr instanceof \PhpParser\Node\Scalar\String_ ) return "'" . $expr->value . "'";
        if ( $expr instanceof \PhpParser\Node\Scalar\LNumber || $expr instanceof \PhpParser\Node\Scalar\DNumber ) return (string) $expr->value;
        if ( $expr instanceof \PhpParser\Node\Expr\Variable ) return (is_string($expr->name) ? (string)$expr->name : '{var}');
        if ( $expr instanceof \PhpParser\Node\Expr\PropertyFetch ) {
            $obj = $this->simple_expr_text( $expr->var, $collected_arrays, $current_file );
            $prop = $expr->name instanceof \PhpParser\Node\Identifier ? $expr->name->toString() : '?';
            return $obj . '->' . $prop;
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ArrayDimFetch ) {
            $arr = $this->simple_expr_text( $expr->var, $collected_arrays, $current_file );
            $dim = $expr->dim ? $this->simple_expr_text( $expr->dim, $collected_arrays, $current_file ) : '';
            return $arr . '[' . $dim . ']';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString() . '()';
        }
        if ( $expr instanceof \PhpParser\Node\Expr\ConstFetch && $expr->name instanceof \PhpParser\Node\Name ) {
            return $expr->name->toString();
        }
        return $this->expr_placeholder( $expr, $collected_arrays, $current_file );
    }

    private function condition_mentions_free_shipping( \PhpParser\Node $node ): bool {
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
                        && strtolower($this->extract_string($call->args[1]->value, [], '')) === 'free_shipping';
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

    /**
     * Traverses parent nodes to determine the current function/method/closure scope.
     * Copied from ArrayCollectorVisitor to be available in the description context.
     */
    private function getCurrentScopeKey(\PhpParser\Node $node): string {
        $parent = $node->getAttribute('parent');
        while ($parent) {
            if ($parent instanceof \PhpParser\Node\FunctionLike) {
                if ($parent instanceof \PhpParser\Node\Stmt\ClassMethod) {
                    $className = '__anonymous';
                    $classParent = $parent->getAttribute('parent');
                    if ($classParent instanceof \PhpParser\Node\Stmt\Class_ && $classParent->name instanceof \PhpParser\Node\Identifier) {
                        $className = $classParent->name->toString();
                    }
                    return $className . '::' . $parent->name->toString();
                }

                if ($parent instanceof \PhpParser\Node\Stmt\Function_) {
                    return $parent->name->toString();
                }

                if ($parent instanceof \PhpParser\Node\Expr\Closure) {
                    return 'closure@line:' . $parent->getStartLine();
                }
            }
            $parent = $parent->getAttribute('parent');
        }
        return '__global__';
    }

    /**
     * Formats an array of strings into a human-readable list.
     */
    private function format_array_for_display(array $items): string {
        if ( empty($items) ) {
            return __( 'an empty list', 'kiss-woo-shipping-debugger' );
        }

        // Use only string values, filter out others.
        $string_items = array_filter($items, 'is_string');
        
        if ( empty($string_items) ) {
            return __( 'an empty list', 'kiss-woo-shipping-debugger' );
        }

        return implode(', ', $string_items);
    }
}