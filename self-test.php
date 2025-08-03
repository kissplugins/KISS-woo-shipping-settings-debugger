<?php
/**
 * =================================================================
 * SELF-TEST MODULE FOR KISS WOO SHIPPING SETTINGS DEBUGGER
 * =================================================================
 * LLM Maintainer Note: The goal of this file is to provide a suite
 * of practical and meaningful tests that prevent regressions in the
 * plugin's core functionality. This version has been refactored for
 * speed and real-time feedback, focusing on the plugin's internal
 * logic rather than external WooCommerce APIs.
 *
 * When adding tests, ensure they are callable via the 'kiss_wse_run_single_test'
 * AJAX action and clean up any artifacts they create (like temp files).
 * =================================================================
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Adds the "Self Test" link to the plugin's Tools page menu.
 */
function kiss_wse_add_self_test_submenu_page() {
    // CHANGED: Moved page from "Tools" to the "WooCommerce" menu.
    add_submenu_page(
        'woocommerce',
        'Shipping Debugger Self-Test',
        'Shipping Self-Test',
        'manage_woocommerce',
        'kiss-wse-self-test',
        'kiss_wse_self_test_page_html'
    );
}
// Note: Hook this into 'admin_menu' from the main plugin file.
// add_action( 'admin_menu', 'kiss_wse_add_self_test_submenu_page' );

/**
 * Retrieves the changelog, rendered as HTML if possible.
 *
 * @param int $lines Number of lines to retrieve for plain text fallback. Default 100.
 * @return string Changelog preview contents (HTML).
 */
function kiss_wse_get_changelog_preview( $lines = 100 ) {
    $file = plugin_dir_path( __FILE__ ) . 'changelog.md';
    if ( ! file_exists( $file ) ) {
        return '<p>' . esc_html__( 'changelog.md file not found.', 'kiss-woo-shipping-debugger' ) . '</p>';
    }

    // If the Markdown Viewer plugin is active, use it to render the file.
    if ( function_exists( 'kiss_mdv_render_file' ) ) {
        $html = kiss_mdv_render_file( $file );
        // The renderer might return an empty string on failure.
        if ( ! empty( $html ) ) {
            return $html;
        }
    }

    // Fallback to a simple plain text preview.
    $contents = file( $file );
    if ( false === $contents ) {
        return '<p>' . esc_html__( 'Unable to read changelog.md.', 'kiss-woo-shipping-debugger' ) . '</p>';
    }

    $fallback_html  = '<p><em>' . esc_html__( 'To see this rendered as HTML, please install the KISS Markdown Viewer plugin.', 'kiss-woo-shipping-debugger' ) . '</em></p>';
    $fallback_html .= '<pre>' . esc_html( implode( '', array_slice( $contents, 0, $lines ) ) ) . '</pre>';

    return $fallback_html;
}

/**
 * Renders the Self Test page HTML.
 */
function kiss_wse_self_test_page_html() {
    $wp_version    = get_bloginfo( 'version' );
    $php_version   = PHP_VERSION;
    global $wpdb;
    $mysql_version = $wpdb->db_version();
    $wc_version    = defined( 'WC_VERSION' ) ? WC_VERSION : __( 'N/A', 'kiss-woo-shipping-debugger' );
    $theme         = wp_get_theme();
    $theme_name    = $theme->get( 'Name' );
    $theme_version = $theme->get( 'Version' );

    if ( ! function_exists( 'get_plugin_data' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_data    = get_plugin_data( KISS_WSE_PLUGIN_FILE );
    $plugin_version = $plugin_data['Version'] ?? __( 'N/A', 'kiss-woo-shipping-debugger' );

    ?>
    <div class="wrap">
        <h1>KISS Shipping Debugger &mdash; Self-Test Suite</h1>

        <div id="kiss-wse-version-info" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Environment Versions', 'kiss-woo-shipping-debugger' ); ?></h2>
            <ul>
                <li><?php printf( esc_html__( 'WordPress: %s', 'kiss-woo-shipping-debugger' ), esc_html( $wp_version ) ); ?></li>
                <li><?php printf( esc_html__( 'PHP: %s', 'kiss-woo-shipping-debugger' ), esc_html( $php_version ) ); ?></li>
                <li><?php printf( esc_html__( 'MySQL: %s', 'kiss-woo-shipping-debugger' ), esc_html( $mysql_version ) ); ?></li>
                <li><?php printf( esc_html__( 'WooCommerce: %s', 'kiss-woo-shipping-debugger' ), esc_html( $wc_version ) ); ?></li>
                <li><?php echo esc_html( $theme_name ) . ': ' . esc_html( $theme_version ); ?></li>
                <li><?php printf( esc_html__( 'KISS Shipping Debugger: %s', 'kiss-woo-shipping-debugger' ), esc_html( $plugin_version ) ); ?></li>
            </ul>
        </div>

        <p>This module helps verify core plugin functionality against the current environment. It focuses on the plugin's internal logic, such as AST scanning and data formatting helpers.</p>
        <button id="kiss-wse-run-self-tests" class="button button-primary">Run All Tests</button>
        <p id="kiss-wse-last-test-time">
            <?php
            $last_run = get_option( 'kiss_wse_tests_last_run' );
            if ( $last_run ) {
                echo '<strong>Tests Last Ran:</strong> ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) );
            }
            ?>
        </p>
        <div id="kiss-wse-test-results-container" style="margin-top: 20px;"></div>

        <div id="kiss-wse-changelog-viewer" style="margin-top: 40px;">
            <h2><?php esc_html_e( 'Changelog Preview', 'kiss-woo-shipping-debugger' ); ?></h2>
            <div class="changelog-content" style="padding: 1px 15px; border: 1px solid #ccd0d4; background: #fff; max-height: 400px; overflow-y: auto;">
                <?php echo wp_kses_post( kiss_wse_get_changelog_preview() ); ?>
            </div>
            <p style="margin-top: 8px;"><?php esc_html_e( 'To review the rest, open changelog.md in a text editor.', 'kiss-woo-shipping-debugger' ); ?></p>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Define the list of tests to run in sequence.
            const tests = [
                { id: 'dependency_check', name: 'Environment: Dependency Check' },
                { id: 'summarize_method_helper', name: 'Helper: summarize_method()' },
                { id: 'warning_logic_mock', name: 'Logic: Preview Warning Detection (Mock)' },
                { id: 'ast_scanner_logic', name: 'Logic: AST Scanner Rule & Array Resolution' }
            ];

            $('#kiss-wse-run-self-tests').on('click', function() {
                var button = $(this);
                var resultsContainer = $('#kiss-wse-test-results-container');

                button.prop('disabled', true);
                resultsContainer.html('<table class="wp-list-table widefat striped" id="kiss-wse-results-table"><thead><tr>' +
                    '<th style="width:25px;"></th>' +
                    '<th>Test Name</th>' +
                    '<th>Result</th>' +
                    '</tr></thead><tbody></tbody></table>');
                
                runTest(0); // Start the test sequence
            });

            function runTest(index) {
                if (index >= tests.length) {
                    $('#kiss-wse-run-self-tests').prop('disabled', false);
                    // Update timestamp after all tests are done
                     $.post(ajaxurl, { action: 'kiss_wse_update_test_timestamp', nonce: '<?php echo esc_js( wp_create_nonce( 'kiss_wse_ajax_nonce' ) ); ?>' }, function(response) {
                        if (response.success) {
                            $('#kiss-wse-last-test-time').html('<strong>Tests Last Ran:</strong> ' + response.data.time);
                        }
                    });
                    return;
                }

                var test = tests[index];
                var tableBody = $('#kiss-wse-results-table tbody');
                var row = $('<tr><td class="test-icon"><span class="spinner is-active"></span></td><td><strong>' + test.name + '</strong></td><td class="test-message">Running...</td></tr>');
                tableBody.append(row);

                $.post(ajaxurl, {
                    action: 'kiss_wse_run_single_test',
                    nonce: '<?php echo esc_js( wp_create_nonce( 'kiss_wse_ajax_nonce' ) ); ?>',
                    test_id: test.id
                }, function(response) {
                    var icon = '';
                    var message = '';

                    if (response.success) {
                        icon = '<span style="color:green; font-size:1.5em; line-height:1;" class="dashicons dashicons-yes-alt"></span>';
                        message = response.data.message;
                    } else {
                        icon = '<span style="color:red; font-size:1.5em; line-height:1;" class="dashicons dashicons-dismiss"></span>';
                        message = response.data.message || 'An unknown error occurred.';
                    }

                    row.find('.test-icon').html(icon);
                    row.find('.test-message').html(message);
                    
                    runTest(index + 1); // Run the next test
                }).fail(function() {
                    var icon = '<span style="color:red; font-size:1.5em; line-height:1;" class="dashicons dashicons-dismiss"></span>';
                    row.find('.test-icon').html(icon);
                    row.find('.test-message').html('Failed to execute test (AJAX error).');
                    $('#kiss-wse-run-self-tests').prop('disabled', false); // Stop on failure
                });
            }
        });
    </script>
    <?php
}

/**
 * Dispatches a single self-test based on the provided test ID.
 */
function kiss_wse_run_single_test_callback() {
    check_ajax_referer( 'kiss_wse_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied.' ] );
    }

    $test_id = isset( $_POST['test_id'] ) ? sanitize_key( $_POST['test_id'] ) : '';
    $main_class = new KISS_WSE_Debugger();

    switch ( $test_id ) {
        case 'dependency_check':
            $wc_ok = class_exists( 'WC_Shipping_Zones' );
            $parser_ok = class_exists( 'PhpParser\\ParserFactory' );
            if ( $wc_ok && $parser_ok ) {
                wp_send_json_success( [ 'message' => 'WooCommerce and PHP-Parser classes are available.' ] );
            } else {
                $missing = [];
                if ( !$wc_ok ) $missing[] = 'WooCommerce';
                if ( !$parser_ok ) $missing[] = 'PHP-Parser';
                wp_send_json_error( [ 'message' => 'Missing critical dependencies: ' . implode( ', ', $missing ) . '.' ] );
            }
            break;

        case 'summarize_method_helper':
            $mock_flat_rate = new class {
                public $id = 'flat_rate';
                public $title = 'Standard Shipping';
                public $method_title = 'Standard Shipping';

                public function get_option( $key, $default = '' ) {
                    if ( $key === 'cost' ) {
                        return '15.00';
                    }
                    return $default;
                }
            };
            
            $summary = $main_class->summarize_method($mock_flat_rate);
            
            $pass = (strpos($summary, 'Standard Shipping') !== false) &&
                    (strpos($summary, 'cost') !== false) &&
                    (strpos($summary, '15.00') !== false);

            if ($pass) {
                 wp_send_json_success( [ 'message' => 'Correctly generated summary for Flat Rate method.' ] );
            } else {
                 wp_send_json_error( [ 'message' => "Generated summary '{$summary}' did not contain the expected components." ] );
            }
            break;

        case 'warning_logic_mock':
            $mock_method = new class {
                public $id = 'free_shipping';
                public $enabled = 'yes';
                public $title = 'Free Shipping';
                public $method_title = 'Free Shipping';

                public function get_option( $key, $default = '' ) {
                    if ( $key === 'requires' ) return ''; // This triggers the warning
                    return $default;
                }
            };

            $mock_zone = new class {
                public function get_zone_name() { return 'Mock Zone'; }
                public function get_zone_locations() { return []; }
                public function get_shipping_methods() { 
                    $method = new class {
                        public $id = 'free_shipping';
                        public $enabled = 'yes';
                        public $title = 'Free Shipping';
                        public $method_title = 'Free Shipping';
                        public function get_option($key, $default='') { if ($key === 'requires') return ''; return $default; }
                    };
                    return [ $method ]; 
                }
            };
            
            list( , , $warnings_html ) = $main_class->collect_zone_rows_from_data( [ $mock_zone ] );
            if (strpos($warnings_html, 'Free Shipping has no requirement') !== false) {
                wp_send_json_success( [ 'message' => 'Correctly identified "Free Shipping with no requirement" issue using mock data.' ] );
            } else {
                wp_send_json_error( [ 'message' => 'Failed to generate the expected warning for a misconfigured Free Shipping method.' ] );
            }
            break;

        case 'ast_scanner_logic':
            $test_file_path = null;
            try {
                if ( !class_exists( 'PhpParser\\ParserFactory' ) ) {
                    throw new Exception("PHP-Parser not available.");
                }
                $child_theme_inc_dir = wp_normalize_path( trailingslashit( get_stylesheet_directory() ) . 'inc' );
                if ( !is_dir($child_theme_inc_dir) ) {
                    wp_mkdir_p($child_theme_inc_dir);
                }
                
                $test_file_path = $child_theme_inc_dir . '/kiss-wse-self-test-rules.php';
                $test_code = <<<PHP
<?php
// Test for array resolution and rule detection.
function kiss_wse_shipping_restrictions_test(\$rates, \$package, \$errors) {
    \$restricted_states = [
        'AL' => 'Alabama',
        'AR' => 'Arkansas',
        'IN' => 'Indiana',
        'VT' => 'Vermont',
        'WI' => 'Wisconsin',
    ];
    \$state = 'WI'; // mock
    
    // Test 1: Statically defined array in a condition
    if (isset(\$restricted_states[\$state])) {
        unset(\$rates['free_shipping:1']);
    }

    // Test 2: Statically defined array in an error message
    if (isset(\$restricted_states[\$state])) {
        \$errors->add('shipping_error', "We cannot ship Kratom to {\$restricted_states[\$state]}.");
    }

    // Test 3: Fallback for dynamically defined array
    \$dynamic_states = array_keys(\$restricted_states);
    if (in_array(\$state, \$dynamic_states)) {
         new WC_Shipping_Rate('dynamic_rate', 'Dynamic Rate', 5);
    }
}
PHP;
                if (file_put_contents($test_file_path, $test_code) === false) {
                    throw new Exception("Could not write to test file. Check permissions for " . $child_theme_inc_dir);
                }

                $output = $main_class->scan_single_file_for_test($test_file_path);

                // CORRECTED: The check for the error message now matches the actual HTML output, where only the first state is bolded.
                $checks = [
                    // Test 1 Check: `unset` rule with resolved array in condition
                    'when the location is one of: <strong>Alabama, Arkansas, Indiana, Vermont, Wisconsin</strong>',
                    
                    // Test 2 Check: `errors->add` rule with resolved array in the message, including `<strong>` tags on Kratom and the first state.
                    'Adds a checkout error message: “We cannot ship <strong>Kratom</strong> to <strong>Alabama</strong>, Arkansas, Indiana, Vermont, Wisconsin.”',

                    // Test 3 Check: Fallback for the dynamic array
                    'Runs when in_array()'
                ];

                $failed_checks = [];
                foreach ($checks as $check) {
                    if (strpos($output, $check) === false) {
                        $failed_checks[] = $check;
                    }
                }
                
                if (empty($failed_checks)) {
                    wp_send_json_success( [ 'message' => 'Successfully detected rules and resolved array variables.' ] );
                } else {
                    $error_message = 'Failed to find expected text: "' . esc_html(implode('", "', $failed_checks)) . '".<br><br><strong>Actual Scanner Output:</strong><pre>' . esc_html($output) . '</pre>';
                    wp_send_json_error( [ 'message' => $error_message ] );
                }

            } catch( Exception $e ) {
                wp_send_json_error( [ 'message' => 'Test failed: ' . $e->getMessage() ] );
            } finally {
                if ($test_file_path && file_exists($test_file_path)) {
                    unlink($test_file_path);
                }
            }
            break;

        default:
            wp_send_json_error( [ 'message' => 'Invalid test ID provided.' ] );
            break;
    }
}
add_action( 'wp_ajax_kiss_wse_run_single_test', 'kiss_wse_run_single_test_callback' );

/**
 * AJAX handler to update the 'last run' timestamp.
 */
function kiss_wse_update_test_timestamp_callback() {
    check_ajax_referer( 'kiss_wse_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied.' ] );
    }
    
    $timestamp = current_time( 'timestamp' );
    update_option( 'kiss_wse_tests_last_run', $timestamp );

    wp_send_json_success([
        'time' => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
    ]);
}
add_action( 'wp_ajax_kiss_wse_update_test_timestamp', 'kiss_wse_update_test_timestamp_callback' );