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


        // Max file size (bytes) allowed for scanning; adjustable via filter
        $max_size_bytes = (int) apply_filters( 'kiss_wse_scanner_max_file_size', 1048576 );

        // --- MASTER DEBUG SECTION ---
        echo '<details style="background: #f8f9fa; padding: 15px; margin: 15px 0; border: 2px solid #6c757d; border-radius: 8px;">';
        echo '<summary style="cursor: pointer; font-weight: bold; font-size: 16px; color: #495057; margin-bottom: 15px;">🔧 Debug Information (Click to expand all debugging details)</summary>';

        // --- 1. ADDITIONAL FILE PROCESSING DEBUG ---
        echo '<details style="background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107;">';
        echo '<summary style="cursor: pointer; font-weight: bold; margin-bottom: 10px;">🔍 Form Input Debug (Click to expand)</summary>';
        echo 'Additional file input: <code>' . esc_html( var_export( $additional, true ) ) . '</code><br>';
        echo 'Base directory: <code>' . esc_html( var_export( $base_dir, true ) ) . '</code><br>';
        echo 'Base real path: <code>' . esc_html( var_export( $base_real, true ) ) . '</code><br>';
        echo 'Condition check ($additional && $base_real): ' . ( $additional && $base_real ? 'TRUE' : 'FALSE' ) . '<br>';
        echo 'GET parameters: <code>' . esc_html( var_export( $_GET, true ) ) . '</code><br>';
        echo 'Current URL: <code>' . esc_html( $_SERVER['REQUEST_URI'] ?? 'N/A' ) . '</code><br>';
        echo '</details>';

        if ( $additional && $base_real ) {
            echo '<details style="background: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196f3;">';
            echo '<summary style="cursor: pointer; font-weight: bold; margin-bottom: 10px;">🔍 Additional File Processing Debug (Click to expand)</summary>';
            echo 'Raw input: <code>' . esc_html( $additional ) . '</code><br>';

            $rel_raw = wp_normalize_path( $additional );
            echo 'After wp_normalize_path: <code>' . esc_html( $rel_raw ) . '</code><br>';
            echo 'Base directory: <code>' . esc_html( $base_dir ) . '</code><br>';
            echo 'Base real path: <code>' . esc_html( $base_real ) . '</code><br>';

            // Basic poison null byte check
            if ( strpos( $rel_raw, "\0" ) !== false ) {
                echo '</details>';
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Invalid path provided.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
            } else {
                // Normalize and ensure it is a relative path (no leading slashes)
                $rel = ltrim( $rel_raw, '/\\' );
                echo 'After ltrim: <code>' . esc_html( $rel ) . '</code><br>';

                // Reject traversal and suspicious segments (., .., streams)
                $segments = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
                echo 'Segments: <code>' . esc_html( implode( ', ', $segments ) ) . '</code><br>';
                $invalid  = false;
                foreach ( $segments as $seg ) {
                    if ( $seg === '.' || $seg === '..' ) { $invalid = true; break; }
                    if ( strpos( $seg, ':' ) !== false ) { $invalid = true; break; }
                }
                echo 'Invalid segments check: ' . ( $invalid ? 'FAILED' : 'PASSED' ) . '<br>';

                if ( $invalid ) {
                    echo '</details>';
                    echo '<div class="notice notice-warning"><p>' . esc_html__( 'Invalid path provided.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                } else {
                    // Build candidate path under base without following symlinks
                    $base_root  = rtrim( $base_real, '/\\' );
                    $candidate  = wp_normalize_path( $base_root . '/' . implode( '/', $segments ) );
                    echo 'Base root: <code>' . esc_html( $base_root ) . '</code><br>';
                    echo 'Candidate path: <code>' . esc_html( $candidate ) . '</code><br>';

                    // Deny symlinks in any path segment
                    $walk = $base_root;
                    foreach ( $segments as $seg ) {
                        $walk = $walk . DIRECTORY_SEPARATOR . $seg;
                        if ( is_link( $walk ) ) { $invalid = true; break; }
                    }
                    echo 'Symlink check: ' . ( $invalid ? 'FAILED (symlink found)' : 'PASSED' ) . '<br>';

                    if ( $invalid ) {
                        echo '</details>';
                        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Symlinked paths are not allowed.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                    } elseif ( is_file( $candidate ) ) {
                        echo 'File exists check: PASSED<br>';
                        // Boundary check with slash guard to avoid prefix tricks
                        $cand_norm = wp_normalize_path( $candidate );
                        $base_norm = rtrim( wp_normalize_path( $base_real ), '/\\' ) . '/';
                        echo 'Candidate normalized: <code>' . esc_html( $cand_norm ) . '</code><br>';
                        echo 'Base normalized: <code>' . esc_html( $base_norm ) . '</code><br>';
                        echo 'Boundary check: ' . ( strpos( $cand_norm, $base_norm ) === 0 ? 'PASSED' : 'FAILED' ) . '<br>';
                        if ( strpos( $cand_norm, $base_norm ) === 0 ) {
                            // Enforce .php extension only
                            $extension = strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) );
                            echo 'File extension: <code>' . esc_html( $extension ) . '</code><br>';
                            echo 'Extension check: ' . ( $extension === 'php' ? 'PASSED' : 'FAILED' ) . '<br>';
                            if ( $extension !== 'php' ) {
                                echo '</details>';
                                echo '<div class="notice notice-warning"><p>' . esc_html__( 'Only PHP files can be scanned.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                            } else {
                                // Enforce size limit
                                $size = @filesize( $candidate );
                                echo 'File size: ' . ( $size !== false ? number_format( $size ) . ' bytes' : 'UNKNOWN' ) . '<br>';
                                echo 'Size limit: ' . number_format( $max_size_bytes ) . ' bytes<br>';
                                echo 'Size check: ' . ( $size !== false && $size <= $max_size_bytes ? 'PASSED' : 'FAILED' ) . '<br>';
                                if ( $size === false || $size > $max_size_bytes ) {
                                    echo '</details>';
                                    echo '<div class="notice notice-warning"><p>' . esc_html__( 'File too large to scan. Reduce size or adjust the limit via filter.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                                } else {
                                    // Normalize paths for comparison to avoid duplicates
                                    $normalized_candidate = wp_normalize_path( $candidate );
                                    $normalized_files = array_map( 'wp_normalize_path', $files_to_scan );
                                    echo 'Normalized candidate: <code>' . esc_html( $normalized_candidate ) . '</code><br>';
                                    echo 'Current files in scan list: ' . count( $normalized_files ) . '<br>';
                                    echo 'Duplicate check: ' . ( in_array( $normalized_candidate, $normalized_files, true ) ? 'DUPLICATE FOUND' : 'UNIQUE FILE' ) . '<br>';
                                    echo '</details>'; // Close debug section

                                    if ( ! in_array( $normalized_candidate, $normalized_files, true ) ) {
                                        $files_to_scan[] = $normalized_candidate;
                                        echo '<div class="notice notice-success" style="margin: 10px 0;"><p>';
                                        echo '<strong>✅ ' . esc_html__( 'Additional file added for scanning:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
                                        echo '<code>' . esc_html( str_replace( get_stylesheet_directory(), '', $normalized_candidate ) ) . '</code><br>';
                                        echo '<small style="color: #666;">' . esc_html( $normalized_candidate ) . '</small>';
                                        echo '</p></div>';
                                    } else {
                                        echo '<div class="notice notice-info" style="margin: 10px 0;"><p>';
                                        echo '<strong>ℹ️ ' . esc_html__( 'File already in scan list:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
                                        echo '<code>' . esc_html( str_replace( get_stylesheet_directory(), '', $normalized_candidate ) ) . '</code>';
                                        echo '</p></div>';
                                    }
                                }
                            }
                        } else {
                            echo '</details>'; // Close debug section
                            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Additional file must be inside the active theme directory.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                        }
                    } else {
                        echo 'File exists check: FAILED<br>';
                        echo '</details>'; // Close debug section
                        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Additional file not found. Please check the path.', 'kiss-woo-shipping-debugger' ) . '</p></div>';
                    }
                }
            }
        }

        // Close master debug section
        echo '</details>';

        // --- 2. SHOW SCAN STATUS ---
        if ( empty( $files_to_scan ) ) {
            echo '<div class="notice notice-info"><p>';
            echo '<strong>' . esc_html__( 'No files to scan.', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
            echo esc_html__( 'Default file not found:', 'kiss-woo-shipping-debugger' ) . ' <code>inc/shipping-restrictions.php</code><br>';
            if ( $additional ) {
                echo esc_html__( 'Additional file not found:', 'kiss-woo-shipping-debugger' ) . ' <code>' . esc_html( $additional ) . '</code>';
            } else {
                echo esc_html__( 'No additional file specified.', 'kiss-woo-shipping-debugger' );
            }
            echo '</p></div>';
            return;
        }

        // Show files that will be scanned with theme context
        echo '<div class="notice notice-success"><p>';
        echo '<strong>' . esc_html__( 'Files to scan:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
        echo '<em>' . esc_html__( 'Active theme:', 'kiss-woo-shipping-debugger' ) . ' <code>' . esc_html( get_stylesheet() ) . '</code></em><br>';
        echo '<em>' . esc_html__( 'Theme directory:', 'kiss-woo-shipping-debugger' ) . ' <code>' . esc_html( get_stylesheet_directory() ) . '</code></em><br><br>';
        foreach ( $files_to_scan as $file ) {
            $relative_path = str_replace( get_stylesheet_directory(), '', $file );
            $relative_path = ltrim( $relative_path, '/\\' );
            echo '✓ <code>' . esc_html( $relative_path ) . '</code><br>';
            echo '&nbsp;&nbsp;<small style="color: #666;">' . esc_html( $file ) . '</small><br>';
        }
        echo '</p></div>';

        // --- 3. COLLECT ALL FINDINGS FROM ALL FILES ---
        $all_findings = [];
        $collected_arrays = []; // Master lookup for all arrays found in all files.
        $scan_results = []; // Track results per file

        foreach ( $files_to_scan as $file ) {
            $relative_path = str_replace( get_stylesheet_directory(), '', $file );
            $relative_path = ltrim( $relative_path, '/\\' );
            echo '<h3>' . esc_html__( 'Scanning theme file:', 'kiss-woo-shipping-debugger' ) . ' <code>' . esc_html( $relative_path ) . '</code></h3>';
            echo '<p style="margin: 5px 0; color: #666; font-size: 12px;">' . esc_html__( 'Full path:', 'kiss-woo-shipping-debugger' ) . ' <code>' . esc_html( $file ) . '</code></p>';

            // Debug: Show file status before scanning
            echo '<details style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa;">';
            echo '<summary style="cursor: pointer; font-weight: bold; margin-bottom: 10px;">🔍 Debug Info (Click to expand)</summary>';
            echo 'File exists: ' . ( file_exists( $file ) ? '✅ Yes' : '❌ No' ) . '<br>';
            echo 'File readable: ' . ( is_readable( $file ) ? '✅ Yes' : '❌ No' ) . '<br>';
            echo 'File size: ' . ( file_exists( $file ) ? number_format( filesize( $file ) ) . ' bytes' : 'N/A' ) . '<br>';
            echo '</details>';

            if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
                echo '<p><em>' . esc_html__( 'PHP-Parser not available. Unable to scan file:', 'kiss-woo-shipping-debugger' ) . ' ' . esc_html(wp_make_link_relative($file)) . '</em></p>';
                continue;
            }

            try {
                echo '<details style="background: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107;">';
                echo '<summary style="cursor: pointer; font-weight: bold; margin-bottom: 10px;">📖 Starting file parse... (Click to expand)</summary>';

                $code = file_get_contents( $file );
                echo 'File content loaded: ' . number_format( strlen( $code ) ) . ' characters<br>';

                $parser = $this->create_parser();
                echo 'Parser created successfully<br>';

                $ast = $parser->parse( $code );
                echo 'AST parsed successfully<br>';

                $trav = new \PhpParser\NodeTraverser();
                echo 'Node traverser created<br>';

                // Add visitors. The ArrayCollector must run before the RateAddCallVisitor.
                $trav->addVisitor( new \PhpParser\NodeVisitor\ParentConnectingVisitor() );
                $array_collector = new \KISSShippingDebugger\ArrayCollectorVisitor();
                $trav->addVisitor( $array_collector );
                $rate_visitor = new \KISSShippingDebugger\RateAddCallVisitor();
                $trav->addVisitor( $rate_visitor );
                echo 'Visitors added successfully<br>';

                $trav->traverse( $ast );
                echo '<strong>✅ File traversal completed successfully!</strong><br>';
                echo '</details>';
            } catch ( \Exception $e ) {
                echo '<div style="background: #f8d7da; padding: 10px; margin: 10px 0; border-left: 4px solid #dc3545;">';
                echo '<strong>❌ Error parsing file:</strong><br>';
                echo 'Error: ' . esc_html( $e->getMessage() ) . '<br>';
                echo 'File: ' . esc_html( $e->getFile() ) . '<br>';
                echo 'Line: ' . esc_html( $e->getLine() ) . '<br>';
                echo '</div>';
                continue; // Skip this file and move to the next
            }

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
                'paymentGateways' => $rate_visitor->getPaymentGatewayHookNodes(),
                'paymentFilters'  => $rate_visitor->getPaymentMethodFilterNodes(),
                'checkoutPayment' => $rate_visitor->getCheckoutPaymentHookNodes(),
                'generalWooHooks' => $rate_visitor->getGeneralWooHookNodes(),
            ];

            // Count findings for this file
            $file_findings_count = 0;
            $file_findings_by_type = [];

            foreach($sections as $key => $nodes) {
                if (!empty($nodes)) {
                    $file_findings_by_type[$key] = count($nodes);
                    $file_findings_count += count($nodes);
                }
                foreach($nodes as $node) {
                    $all_findings[] = [
                        'file' => $file,
                        'key'  => $key,
                        'node' => $node,
                    ];
                }
            }

            // Show immediate feedback for this file
            if ($file_findings_count > 0) {
                echo '<div class="notice notice-success" style="margin: 10px 0;"><p>';
                echo '<strong>' . sprintf(
                    esc_html__( '✅ Found %d WooCommerce-related items in this file:', 'kiss-woo-shipping-debugger' ),
                    $file_findings_count
                ) . '</strong><br>';
                foreach ($file_findings_by_type as $type => $count) {
                    echo '• ' . esc_html($this->short_explanation_label($type)) . ': ' . $count . '<br>';
                }
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-warning" style="margin: 10px 0;"><p>';
                echo '<strong>' . esc_html__( '⚠️ No WooCommerce shipping/payment patterns detected in this file.', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
                echo esc_html__( 'This could mean:', 'kiss-woo-shipping-debugger' ) . '<br>';
                echo '• ' . esc_html__( 'The file contains general WooCommerce hooks not related to shipping/payments', 'kiss-woo-shipping-debugger' ) . '<br>';
                echo '• ' . esc_html__( 'The code uses patterns not yet recognized by our scanner', 'kiss-woo-shipping-debugger' ) . '<br>';
                echo '• ' . esc_html__( 'The file doesn\'t contain WooCommerce customizations', 'kiss-woo-shipping-debugger' ) . '<br>';

                // Add debugging info to show what we found vs what we're looking for
                echo '<details style="margin-top: 10px; font-family: monospace; font-size: 12px; background: #f0f8ff; padding: 10px; border-radius: 3px; border-left: 4px solid #0073aa;">';
                echo '<summary style="cursor: pointer; font-weight: bold; margin-bottom: 10px;">🔍 Debug Info: Hook Statistics (Click to expand)</summary>';
                echo '<strong>' . esc_html__( 'Hook Statistics Found in This File:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
                echo '• Total add_action() calls: ' . esc_html( $rate_visitor->getTotalAddActionCalls() ) . '<br>';
                echo '• Total add_filter() calls: ' . esc_html( $rate_visitor->getTotalAddFilterCalls() ) . '<br>';
                echo '• WooCommerce hooks (woocommerce_*, wc_*): ' . esc_html( $rate_visitor->getTotalWooCommerceCalls() ) . '<br>';
                echo '</details>';

                echo '<details style="margin-top: 10px;"><summary style="cursor: pointer; color: #0073aa;">' . esc_html__( 'Click to see what specific patterns we scan for', 'kiss-woo-shipping-debugger' ) . '</summary>';
                echo '<div style="margin-top: 10px; font-family: monospace; font-size: 12px; background: #f5f5f5; padding: 10px; border-radius: 3px;">';
                echo '<strong>' . esc_html__( 'Shipping & Payment Patterns We Look For:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
                echo '• add_filter(\'woocommerce_package_rates\', ...)<br>';
                echo '• add_action(\'woocommerce_cart_calculate_fees\', ...)<br>';
                echo '• $package->add_rate(...)<br>';
                echo '• new WC_Shipping_Rate(...)<br>';
                echo '• $cart->add_fee(...)<br>';
                echo '• unset($rates[...])<br>';
                echo '• $errors->add(...)<br>';
                echo '• Payment gateway hooks<br>';
                echo '• Checkout validation hooks<br>';
                echo '• General WooCommerce hooks (woocommerce_*, wc_*)<br>';
                echo '</div></details>';
                echo '</p></div>';
            }

            $scan_results[$file] = [
                'count' => $file_findings_count,
                'types' => $file_findings_by_type
            ];
        }

        // --- SCAN SUMMARY ---
        $total_findings = count($all_findings);
        $total_files_scanned = count($scan_results);
        $files_with_findings = count(array_filter($scan_results, function($result) { return $result['count'] > 0; }));

        echo '<hr><div class="notice notice-info"><p>';
        echo '<strong>' . esc_html__( 'Scan Summary:', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
        echo sprintf(
            esc_html__( '📁 Files scanned: %d | ✓ Files with findings: %d | 🔍 Total items found: %d', 'kiss-woo-shipping-debugger' ),
            $total_files_scanned,
            $files_with_findings,
            $total_findings
        );
        echo '</p></div>';

        if ($total_findings === 0) {
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>' . esc_html__( 'No shipping or payment-related code detected.', 'kiss-woo-shipping-debugger' ) . '</strong><br>';
            echo esc_html__( 'This could mean:', 'kiss-woo-shipping-debugger' ) . '<br>';
            echo '• ' . esc_html__( 'The files don\'t contain WooCommerce customizations', 'kiss-woo-shipping-debugger' ) . '<br>';
            echo '• ' . esc_html__( 'The code uses patterns not yet recognized by our scanner', 'kiss-woo-shipping-debugger' ) . '<br>';
            echo '• ' . esc_html__( 'The customizations are in other files not being scanned', 'kiss-woo-shipping-debugger' );
            echo '</p></div>';
            return;
        }

        // --- 3. GROUP FINDINGS ---
        $product_groups  = [];
        $function_groups = [];
        $product_keywords = ['Kratom', 'Amanita Mushroom', 'THC-A', 'CBD'];

        foreach ( $all_findings as $finding ) {
            // Group by product keyword
            $description  = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'], true );
            $found_keyword = 'OTHER RULES';
            foreach ( $product_keywords as $keyword ) {
                if ( stripos( $description, $keyword ) !== false ) {
                    $found_keyword = strtoupper( $keyword );
                    break;
                }
            }
            $product_groups[ $found_keyword ][] = $finding;

            // Group by containing function/method
            $scope = $this->getCurrentScopeKey( $finding['node'] );
            if ( $scope === '__global__' ) {
                $scope = __( 'Global Scope', 'kiss-woo-shipping-debugger' );
            } elseif ( 0 === strpos( $scope, 'closure@line:' ) ) {
                $line_no = (int) substr( $scope, strlen( 'closure@line:' ) );
                $scope   = sprintf( __( 'Closure at line %d', 'kiss-woo-shipping-debugger' ), $line_no );
            }
            $group_key = $scope . ' (' . basename( $finding['file'] ) . ')';
            $function_groups[ $group_key ][] = $finding;
        }

        // --- 4. RENDER GROUPED OUTPUT WITH TOGGLE ---
        if ( empty( $product_groups ) && empty( $function_groups ) ) {
            echo '<p><em>' . esc_html__( 'No shipping-related rules found in the scanned files.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
            return;
        }

        // Move "OTHER RULES" to the end of the product grouping.
        if ( isset( $product_groups['OTHER RULES'] ) ) {
            $other_rules = $product_groups['OTHER RULES'];
            unset( $product_groups['OTHER RULES'] );
            $product_groups['OTHER RULES'] = $other_rules;
        }

        // Toggle Styles
        echo '<style>
        .kiss-toggle-control{display:inline-flex;background-color:#e0e0e0;border-radius:15px;padding:2px;border:1px solid #c9c9c9}
        .kiss-toggle-control input[type="radio"]{display:none}
        .kiss-toggle-control label{margin-bottom:0;padding:4px 12px;font-size:13px;line-height:1.5;cursor:pointer;border-radius:13px;transition:all .2s ease-in-out;color:#50575e;font-weight:400}
        .kiss-toggle-control input[type="radio"]:checked+label{background-color:#fff;color:#1d2327;box-shadow:0 1px 1px rgba(0,0,0,.1);font-weight:600}
        .kiss-report-content{display:none;margin-top:20px;padding:15px;border:1px solid #ddd}
        .kiss-report-content.active{display:block}
        </style>';

        // Toggle Control
        echo '<div class="kiss-toggle-control" style="margin-top:15px;margin-bottom:15px;">';
        echo '<input type="radio" id="grouping_product" name="grouping_type" value="product" checked><label for="grouping_product">' . esc_html__( 'Product', 'kiss-woo-shipping-debugger' ) . '</label>';
        echo '<input type="radio" id="grouping_function" name="grouping_type" value="function"><label for="grouping_function">' . esc_html__( 'Functional', 'kiss-woo-shipping-debugger' ) . '</label>';
        echo '</div>';

        // Product Grouping Content
        echo '<div id="grouping-product" class="kiss-report-content active">';
        foreach ( $product_groups as $product => $findings ) {
            printf( '<h4 style="color: red;"><strong>%s</strong></h4>', esc_html( $product ) );
            echo '<ul>';

	        // ReDoS hardening: truncate message and temporarily lower PCRE limits
	        $max_len = (int) apply_filters( 'kiss_wse_format_msg_max_len', 1000 );
	        if ( $max_len > 0 && strlen( $message ) > $max_len ) {
	            $message = substr( $message, 0, $max_len );
	        }
	        $__prev_pcre_bt  = ini_get( 'pcre.backtrack_limit' );
	        $__prev_pcre_rec = ini_get( 'pcre.recursion_limit' );
	        @ini_set( 'pcre.backtrack_limit', '100000' );
	        @ini_set( 'pcre.recursion_limit', '100000' );

            foreach ( $findings as $finding ) {
                $line     = (int) $finding['node']->getLine();
                $filename = basename( $finding['file'] );
                $desc     = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'] );
                printf(
                    '<li><strong>%s</strong> — %s %s</li>',
                    esc_html( $this->short_explanation_label( $finding['key'] ) ),
                    esc_html( $desc ),
                    sprintf( '<span style="opacity:.7;">(%s %d - %s)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ), esc_html( $filename ) )
                );
            }
            echo '</ul>';
        }
        echo '</div>';

        // Functional Grouping Content
        echo '<div id="grouping-function" class="kiss-report-content">';
        foreach ( $function_groups as $func => $findings ) {
            printf( '<h4 style="color: red;"><strong>%s</strong></h4>', esc_html( $func ) );
            echo '<ul>';
            foreach ( $findings as $finding ) {
                $line     = (int) $finding['node']->getLine();
                $filename = basename( $finding['file'] );
                $desc     = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'] );
                printf(
                    '<li><strong>%s</strong> — %s %s</li>',
                    esc_html( $this->short_explanation_label( $finding['key'] ) ),
                    esc_html( $desc ),
                    sprintf( '<span style="opacity:.7;">(%s %d - %s)</span>', esc_html__( 'line', 'kiss-woo-shipping-debugger' ), esc_html( $line ), esc_html( $filename ) )
                );
            }
            echo '</ul>';
        }
        echo '</div>';

        // Toggle Script
        echo '<script>
        document.addEventListener("DOMContentLoaded",function(){
            const radios=document.querySelectorAll("input[name=\'grouping_type\']");
            const contents=document.querySelectorAll(".kiss-report-content");
            function update(){
                const val=document.querySelector("input[name=\'grouping_type\']:checked").value;
                contents.forEach(c=>c.classList.remove("active"));
                const active=document.getElementById("grouping-"+val);
                if(active){active.classList.add("active");}
            }
            radios.forEach(r=>r.addEventListener("change",update));
            update();
        });
        </script>';
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
            case 'paymentGateways': return __( 'Modifies payment gateways', 'kiss-woo-shipping-debugger' );
            case 'paymentFilters':  return __( 'Payment method filtering', 'kiss-woo-shipping-debugger' );
            case 'checkoutPayment': return __( 'Checkout payment hooks', 'kiss-woo-shipping-debugger' );
            case 'generalWooHooks': return __( 'WooCommerce hooks', 'kiss-woo-shipping-debugger' );
            default:            return __( 'Matched code', 'kiss-woo-shipping-debugger' );
        }
    }

    /**
     * Helper function to apply bolding rules to error messages.
     */
    private function format_error_message( string $message ): string {
        // ReDoS hardening: truncate and adjust PCRE limits just for formatting
        $max_fmt_len = (int) apply_filters( 'kiss_wse_format_msg_max_len', 1000 );
        if ( $max_fmt_len > 0 && strlen( $message ) > $max_fmt_len ) {
            $message = substr( $message, 0, $max_fmt_len );
        }
        $prev_bt  = ini_get( 'pcre.backtrack_limit' );
        $prev_rec = ini_get( 'pcre.recursion_limit' );
        @ini_set( 'pcre.backtrack_limit', '100000' );
        @ini_set( 'pcre.recursion_limit', '100000' );

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


        // Restore PCRE limits
        if ( $prev_bt !== false ) { @ini_set( 'pcre.backtrack_limit', (string) $prev_bt ); }
        if ( $prev_rec !== false ) { @ini_set( 'pcre.recursion_limit', (string) $prev_rec ); }

        // 3. Bold state names that appear after "to" or "for"
        $states = ['Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut', 'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire', 'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota', 'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia', 'Wisconsin', 'Wyoming'];
        $states_pattern = implode('|', array_map('preg_quote', $states));
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

                case 'paymentGateways':
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    if ( $cb ) {
                        return sprintf(
                            __( 'Modifies available payment gateways (%s). This can hide/show payment methods based on conditions.', 'kiss-woo-shipping-debugger' ),
                            $cb
                        );
                    }
                    return __( 'Modifies available payment gateways. This can hide/show payment methods based on conditions.', 'kiss-woo-shipping-debugger' );

                case 'paymentFilters':
                    $hook_name = '';
                    if ( property_exists( $node, 'args' ) && isset( $node->args[0] ) ) {
                        $hook_name = $this->extract_string( $node->args[0]->value, $collected_arrays, $current_file );
                    }
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    $summary = __( 'Payment-related action hook.', 'kiss-woo-shipping-debugger' );
                    if ( $hook_name !== '' ) {
                        $summary = sprintf( __( 'Hooks into "%s" for payment processing.', 'kiss-woo-shipping-debugger' ), $hook_name );
                    }
                    if ( $cb ) {
                        $summary .= ' ' . sprintf( __( 'Callback: %s.', 'kiss-woo-shipping-debugger' ), $cb );
                    }
                    return $summary;

                case 'checkoutPayment':
                    $hook_name = '';
                    if ( property_exists( $node, 'args' ) && isset( $node->args[0] ) ) {
                        $hook_name = $this->extract_string( $node->args[0]->value, $collected_arrays, $current_file );
                    }
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';
                    $when = $this->condition_chain_text( $node, $collected_arrays, $current_file );

                    $summary = __( 'Checkout payment section hook.', 'kiss-woo-shipping-debugger' );
                    if ( $hook_name !== '' ) {
                        $summary = sprintf( __( 'Hooks into "%s" during checkout payment display.', 'kiss-woo-shipping-debugger' ), $hook_name );
                    }
                    if ( $cb ) {
                        $summary .= ' ' . sprintf( __( 'Callback: %s.', 'kiss-woo-shipping-debugger' ), $cb );
                    }
                    if ( $when !== '' ) {
                        $summary .= ' ' . sprintf( __( 'Runs when %s.', 'kiss-woo-shipping-debugger' ), $when );
                    }
                    return $summary;

                case 'generalWooHooks':
                    $hook_name = '';
                    $hook_type = 'action';
                    if ( property_exists( $node, 'name' ) && $node->name instanceof \PhpParser\Node\Name ) {
                        $hook_type = $node->name->toString() === 'add_filter' ? 'filter' : 'action';
                    }
                    if ( property_exists( $node, 'args' ) && isset( $node->args[0] ) ) {
                        $hook_name = $this->extract_string( $node->args[0]->value, $collected_arrays, $current_file );
                    }
                    $cb = ( property_exists( $node, 'args' ) && isset( $node->args[1] ) )
                        ? $this->describe_callback( $node->args[1]->value )
                        : '';

                    $summary = sprintf( __( 'WooCommerce %s hook.', 'kiss-woo-shipping-debugger' ), $hook_type );
                    if ( $hook_name !== '' ) {
                        $summary = sprintf( __( 'Hooks into "%s" (%s).', 'kiss-woo-shipping-debugger' ), $hook_name, $hook_type );
                    }
                    if ( $cb ) {
                        $summary .= ' ' . sprintf( __( 'Callback: %s.', 'kiss-woo-shipping-debugger' ), $cb );
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

    /**
     * Convert PHP ini memory values like "128M" or "1G" to bytes.
     */
    private function bytes_from_php_ini_val( $val ): int {
        $v = trim( (string) $val );
        if ( $v === '' || $v === '-1' ) return -1; // -1 means unlimited
        $last = strtolower( $v[strlen($v)-1] );
        $num = (int) $v;
        switch ( $last ) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
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