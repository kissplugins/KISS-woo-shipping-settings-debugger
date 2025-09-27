<?php
/**
 * Plugin Name: KISS Woo Shipping & Payment Settings Debugger
 * Description: Exports UI-based WooCommerce shipping settings and scans theme files for custom shipping and payment rules via AST.
 * Version:     2.7.3
 * Author:      KISS Plugins
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: kiss-woo-shipping-debugger
 */

if ( ! defined( 'ABSPATH' ) ) exit;
define( 'KISS_WSE_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/preview-trait.php';
require_once __DIR__ . '/scanner-trait.php';

// Debug: Log that we're about to load self-test.php
error_log('KISS_WSE: About to load self-test.php');
require_once __DIR__ . '/self-test.php';
error_log('KISS_WSE: self-test.php loaded successfully');

// Load standalone AJAX handlers
require_once __DIR__ . '/ajax-handlers.php';


// Shared helper: sanitize a CSV cell to prevent formula injection
if ( ! function_exists( 'kiss_wse_csv_sanitize_cell' ) ) {
    function kiss_wse_csv_sanitize_cell( $value ) {
        $s = (string) $value;
        if ( $s !== '' ) {
            $first = $s[0];
            if ( $first === '=' || $first === '+' || $first === '-' || $first === '@' ) {
                return "'" . $s;
            }
        }
        return $s;
    }
}


add_action( 'plugins_loaded', 'kiss_wse_initialize_debugger' );
/**
 * Initialize the debugger after plugins load.
 *
 * Displays an admin notice if WooCommerce is inactive.
 */
function kiss_wse_initialize_debugger(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', fn() => printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'KISS Woo Shipping Settings Debugger requires WooCommerce to be active.', 'kiss-woo-shipping-debugger' )
        ));
        return;
    }
    new KISS_Woo_Shipping_Debugger_required_plugin();
}

/**
 * Contains helper methods for testing without affecting production code.
 * This trait is defined here to avoid redeclaration errors.
 */
trait KISS_WSE_Testable {
    public function collect_zone_rows_from_data( array $mock_zones, int $cap = 100 ): array {
        $rows = [];
        $warnings = [];
        $total_rows = 0;

        foreach ($mock_zones as $zone) {
            $zone_name = $zone->get_zone_name();
            // Since this is a mock test, we don't need the full HTML rendering of locations
            // $locations_html = $this->format_zone_locations($zone, 6);
            $methods = $zone->get_shipping_methods();

            $enabled = 0; $disabled = 0;
            foreach ($methods as $m) {
                if ('yes' === $m->enabled) $enabled++; else $disabled++;
            }

            $zone_issues = [];
            if ($enabled === 0) {
                $zone_issues[] = __( 'Zone has no enabled shipping methods.', 'kiss-woo-shipping-debugger' );
            }
            foreach ($methods as $m) {
                if ($m->id === 'free_shipping' && 'yes' === $m->enabled) {
                    $requires = (string)$m->get_option('requires', '');
                    if ($requires === '' || $requires === 'no') {
                        $zone_issues[] = __( 'Free Shipping has no requirement (no minimum and no coupon).', 'kiss-woo-shipping-debugger' );
                        break;
                    }
                }
            }

            if (!empty($zone_issues)) {
                $warnings[] = sprintf('<strong>%s</strong>: %s', esc_html($zone_name), esc_html(implode('; ', $zone_issues)));
            }

            $total_rows++;
            if ($total_rows >= $cap) break;
        }

        $warnings_html = '';
        if (!empty($warnings)) {
            $warnings_html = '⚠️ ' . implode('<br>⚠️ ', $warnings);
        }

        return [ [], $total_rows, $warnings_html ];
    }

    /**
     * A dedicated scanner for the self-test suite.
     * This scans only one file and returns the output as a string, ensuring the test is isolated.
     */
    public function scan_single_file_for_test( string $file_path ): string {
        if ( ! file_exists( $file_path ) ) {
            return 'Test Error: File not found at ' . esc_html($file_path);
        }
        if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
            return 'Test Error: PHP-Parser not available.';
        }

        ob_start();

        require_once plugin_dir_path( __FILE__ ) . 'lib/RateAddCallVisitor.php';
        require_once plugin_dir_path( __FILE__ ) . 'lib/ArrayCollectorVisitor.php';

        $code   = file_get_contents( $file_path );
        $parser = $this->create_parser();

        $ast    = $parser->parse( $code );
        $trav   = new \PhpParser\NodeTraverser();

        // The visitor chain MUST match the main scanner for tests to be accurate.
        $trav->addVisitor( new \PhpParser\NodeVisitor\ParentConnectingVisitor() );
        $array_collector = new \KISSShippingDebugger\ArrayCollectorVisitor();
        $trav->addVisitor( $array_collector );
        $rate_visitor = new \KISSShippingDebugger\RateAddCallVisitor();
        $trav->addVisitor( $rate_visitor );
        $trav->traverse( $ast );

        // Build the data structures needed by the describe_node function.
        $collected_arrays = [ $file_path => $array_collector->getArraysByScope() ];

        $sections = [
            'unsetRates'  => $rate_visitor->getUnsetRateNodes(),
            'newRates'    => $rate_visitor->getNewRateNodes(),
            'errors'      => $rate_visitor->getErrorAddNodes(),
            'paymentGateways' => $rate_visitor->getPaymentGatewayHookNodes(),
            'paymentFilters'  => $rate_visitor->getPaymentMethodFilterNodes(),
            'checkoutProcess' => $rate_visitor->getCheckoutProcessHookNodes(),
            'filterHooks' => $rate_visitor->getFilterHookNodes(),
            'rateCalls'   => $rate_visitor->getAddRateNodes(),
        ];

        $all_findings = [];
        foreach($sections as $key => $nodes) {
            foreach($nodes as $node) {
                $all_findings[] = ['file' => $file_path, 'key' => $key, 'node' => $node];
            }
        }

        // Render the findings using the full-featured describe_node method.
        if ( ! empty( $all_findings ) ) {
            echo '<ul>';
            foreach ( $all_findings as $finding ) {
                $desc = $this->describe_node( $finding['key'], $finding['node'], $collected_arrays, $finding['file'] );
                // Output as plain text to avoid any XSS risk in admin
                printf( '<li>%s</li>', esc_html( $desc ) );
            }
            echo '</ul>';
        }

        return ob_get_clean();
    }
}

class KISS_Woo_Shipping_Debugger_required_plugin {

    private $required_plugin = 'WP-PHP-Parser-loader-main/php-parser-loader.php';
    private $github_repo_zip = 'https://github.com/kissplugins/WP-PHP-Parser-loader/archive/refs/heads/main.zip';
    private $current_plugin;

    public function __construct() {
        $this->current_plugin = plugin_basename(__FILE__);
        add_action('admin_init', [$this, 'check_required_plugin']);
        add_action('admin_post_kiss_install_parser_plugin', [$this, 'install_required_plugin']);

    }

    public function is_plugin_installed( $plugin_file ) {
        return file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
    }
    /**
     * Check if required plugin is installed/active
     */
    public function check_required_plugin() {
        if ( ! current_user_can('install_plugins') ) {
            return;
        }

        // If already active, skip
        if ( is_plugin_active($this->required_plugin) ) {
            new KISS_WSE_Debugger();
            return;
        } 

        // If installed but not active → show Activate button
        if ( file_exists(WP_PLUGIN_DIR . '/' . $this->required_plugin) ) {
            $activate_url = wp_nonce_url(
                self_admin_url('plugins.php?action=activate&plugin=' . $this->required_plugin),
                'activate-plugin_' . $this->required_plugin
            );

            echo '<div class="notice notice-warning"><p>';
            echo 'KISS Woo Shipping Debugger requires <strong>PHP Parser Plugin</strong>. ';
            echo '<a class="button button-primary" href="' . esc_url($activate_url) . '">Activate Plugin</a>';
            echo '</p></div>';
            return;
        }

        if ( !$this->is_plugin_installed( $this->required_plugin ) ) {
            // Not installed → show install button
            $install_url = wp_nonce_url(
                admin_url('admin-post.php?action=kiss_install_parser_plugin'),
                'kiss_install_parser_plugin'
            );

            echo '<div class="notice notice-error"><p>';
            echo 'KISS Woo Shipping Debugger requires <strong>PHP Parser Plugin</strong>. ';
            echo '<a class="button button-primary" href="' . esc_url($install_url) . '">Install Required PHP Parser Plugin</a>';
            echo '</p></div>';
        }
        
    }

    /**
     * Install required plugin from GitHub repo
     */
    public function install_required_plugin() {
        if ( ! current_user_can('install_plugins') || ! check_admin_referer('kiss_install_parser_plugin') ) {
            wp_die('Permission denied');
        }

        include_once ABSPATH . 'wp-admin/includes/file.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        global $wp_filesystem;
        if (!WP_Filesystem()) {
            wp_die(__('Failed to initialize filesystem. Please check your server configuration.', 'kiss-woo-shipping-debugger'), __('Error', 'kiss-woo-shipping-debugger'));
        }
        
        $skin = new class extends WP_Upgrader_Skin {
            protected $silent = true;
            public function feedback($feedback, ...$args) {}
            public function header() {}
            public function footer() {}
        };
        $upgrader = new Plugin_Upgrader($skin);
        ob_start();
        $result   = $upgrader->install($this->github_repo_zip);
        if (ob_get_length()) {
            ob_end_clean();
        }

        if ( is_wp_error($result) ) {
            error_log('Plugin installation failed: ' . $result->get_error_message());
        }

        // Try activating after install
        if ( file_exists(WP_PLUGIN_DIR . '/' . $this->required_plugin) ) {
            activate_plugin($this->required_plugin);
        }

        if (is_plugin_active($this->current_plugin) && ( !file_exists(WP_PLUGIN_DIR . '/' . $this->required_plugin) ) ) {
            deactivate_plugins($this->current_plugin);

            // Clear cache and re-check
            wp_cache_delete('plugins', 'plugins');
            $active_plugins = get_option('active_plugins', []);
            if (in_array($this->current_plugin, $active_plugins)) {
                $active_plugins = array_diff($active_plugins, [$this->current_plugin]);
                update_option('active_plugins', $active_plugins);
            }

            if (is_plugin_active($this->current_plugin)) {
                error_log('Failed to deactivate ' . $this->current_plugin . ' after manual attempt');
                wp_die(__('Failed to deactivate plugin. Please deactivate manually.', 'kiss-woo-shipping-debugger'));
            }
        }

        if (ob_get_length()) {
            $buffered_output = ob_get_clean();
        }

        // Redirect back to Plugins screen
        wp_redirect(admin_url('plugins.php?plugin_status=all&message=plugin_installed_activated'));
        exit;
    }
}

/**
 * Main controller for the Shipping Settings Debugger.
 */
class KISS_WSE_Debugger {
    private string $page_slug = 'kiss-wse-export';

    use KISS_WSE_Preview, KISS_WSE_Testable;

    use KISS_WSE_Scanner {
        scan_and_render_custom_rules as public;
        create_parser as public;
        short_explanation_label as public;
        describe_node as public;
        extract_string as public;
        extract_string_or_placeholder as public;
        expr_placeholder as public;
        describe_callback as public;
        extract_unset_rate_key as public;
        string_or_resolved_variable as public;
        resolve_variable_value as public;
        condition_chain_text as public;
        describe_variable_assignment as public;
        cond_to_text as public;
        is_false_const as public;
        is_var_named as public;
        is_number_like as public;
        simple_expr_text as public;
        condition_mentions_free_shipping as public;
    }

    /**
     * Constructor. Hooks into WordPress admin and ensures PHP-Parser is loaded.
     */
    private ?\PhpParser\Parser $parser;

    public function __construct(?\PhpParser\Parser $parser = null) {
        error_log('KISS_WSE: Constructor called');

        // Load PHP-Parser
        if ( ! class_exists( \PhpParser\ParserFactory::class ) ) {
            error_log('KISS_WSE: PHP-Parser not found, trying to load');
            $this->maybe_require_parser_loader();
        } else {
            error_log('KISS_WSE: PHP-Parser found, creating parser');
            $this->parser = $parser ?? $this->create_parser();
        }

        $plugin_basename = plugin_basename( KISS_WSE_PLUGIN_FILE );
        add_filter( 'plugin_action_links_' . $plugin_basename, [ $this, 'add_action_links' ] );
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_menu', 'kiss_wse_add_self_test_submenu_page' );
        add_action( 'admin_post_' . $this->page_slug, [ $this, 'handle_export' ] );

        // Register AJAX handlers for self-test functionality using init hook
        add_action( 'init', [ $this, 'register_ajax_handlers' ] );
    }

    /**
     * Register AJAX handlers for self-test functionality
     */
    public function register_ajax_handlers() {
        error_log('KISS_WSE: register_ajax_handlers called');
        add_action( 'wp_ajax_kiss_wse_test_ajax', 'kiss_wse_test_ajax_callback' );
        add_action( 'wp_ajax_kiss_wse_run_single_test', 'kiss_wse_run_single_test_callback' );
        add_action( 'wp_ajax_kiss_wse_update_test_timestamp', 'kiss_wse_update_test_timestamp_callback' );
        error_log('KISS_WSE: AJAX handlers registered in register_ajax_handlers method');
    }

    /**
     * Attempt to locate and require a php-parser loader from any plugin folder.
     */
    private function maybe_require_parser_loader(): void {
        foreach ( glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
            $loader = $dir . '/php-parser-loader.php';
            if ( file_exists( $loader ) ) {
                require_once $loader;
                if ( class_exists( \PhpParser\ParserFactory::class ) ) {
                    return;
                }
            }
        }
        error_log( 'KISS WSE Debugger: php-parser-loader.php not found.' );
    }

    /**
     * Add convenient action links on the plugins page.
     */
    public function add_action_links( array $links ): array {
        // Main settings/debugger link
        $settings_url = esc_url( admin_url( 'admin.php?page=' . $this->page_slug ) );
        $settings_text = esc_html__( 'Settings', 'kiss-woo-shipping-debugger' );
        array_unshift( $links, "<a href=\"$settings_url\">$settings_text</a>" );

        // Self-test link for quick access
        $test_url = esc_url( admin_url( 'admin.php?page=kiss-wse-self-test' ) );
        $test_text = esc_html__( 'Self-Test', 'kiss-woo-shipping-debugger' );
        array_unshift( $links, "<a href=\"$test_url\">$test_text</a>" );

        return $links;
    }

    /**
     * Register the Tools submenu page for the debugger UI.
     */
    public function register_menu(): void {
        // Check if WooCommerce is active before adding to WooCommerce menu
        if ( class_exists( 'WooCommerce' ) ) {
            // Add to WooCommerce menu
            add_submenu_page(
                'woocommerce',
                __( 'KISS Woo Shipping & Payment Debugger', 'kiss-woo-shipping-debugger' ),
                __( 'Shipping & Payment Debugger', 'kiss-woo-shipping-debugger' ),
                'manage_woocommerce',
                $this->page_slug,
                [ $this, 'render_page' ]
            );
        } else {
            // Fallback to Tools menu if WooCommerce is not active
            add_submenu_page(
                'tools.php',
                __( 'KISS Woo Shipping & Payment Debugger', 'kiss-woo-shipping-debugger' ),
                __( 'Shipping & Payment Debugger', 'kiss-woo-shipping-debugger' ),
                'manage_options',
                $this->page_slug,
                [ $this, 'render_page' ]
            );
        }
    }

    /**
     * Output the main admin page with scan and export options.
     */
    public function render_page(): void {
        if ( isset( $_GET['wse_additional_file'] ) ) {
            $additional = sanitize_text_field( wp_unslash( $_GET['wse_additional_file'] ) );
            update_option( 'kiss_wse_additional_file', $additional );
        } else {
            $additional = sanitize_text_field( (string) get_option( 'kiss_wse_additional_file', '' ) );
        }

        // If additional is empty, set a default value
        if ( empty( $additional ) ) {
            $additional = 'inc/woo-functions.php';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'KISS Woo Shipping & Payment Settings Debugger & Scanner', 'kiss-woo-shipping-debugger' ) . '</h1>';

        // --- PHP-Parser Status & Self-Test (auto) ---
        $parser_loaded = class_exists( \PhpParser\ParserFactory::class );
        $parser_ok     = false;
        $parser_msg    = '';

        if ( $parser_loaded ) {
            try {
                $parser = $this->parser ?? $this->create_parser();
                // Tiny parse test
                $test_code = "<?php\nfunction _kiss_wse_test(){return 42;} _kiss_wse_test();";
                $ast = $parser->parse( $test_code );
                $parser_ok  = is_array( $ast ) && ! empty( $ast );
                $parser_msg = $parser_ok
                    ? __( 'PHP-Parser is loaded and parsed a test snippet successfully.', 'kiss-woo-shipping-debugger' )
                    : __( 'PHP-Parser is present but could not parse the test snippet.', 'kiss-woo-shipping-debugger' );
            } catch ( \Throwable $e ) {
                $parser_ok  = false;
                $parser_msg = sprintf(
                    /* translators: %s is an error message */
                    __( 'PHP-Parser error: %s', 'kiss-woo-shipping-debugger' ),
                    $e->getMessage()
                );
                error_log( '[KISS WSE Parser Test] ' . $e->getMessage() );
            }
        } else {
            $parser_msg = __( 'PHP-Parser is not loaded. Some scanning features may be unavailable.', 'kiss-woo-shipping-debugger' );
        }

        if ( $parser_loaded && $parser_ok ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html( $parser_msg )
            );
        } else {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html( $parser_msg )
            );
        }

        // --- Custom Rules Scanner UI ---
        echo '<hr/><h2>' . esc_html__( 'Custom Rules Scanner', 'kiss-woo-shipping-debugger' ) . '</h2>';
        echo '<p>' . esc_html__( 'Scans your theme files for shipping and payment-related code via AST.', 'kiss-woo-shipping-debugger' ) . '</p>';
        printf(
            '<form method="get" style="padding:1em;border:1px solid #c3c4c7;background:#fff;">
                <input type="hidden" name="page" value="%1$s">
                <p>
                  <label><strong>%2$s</strong></label><br>
                  <span style="font-family:monospace;">%3$s</span>
                  <input type="text" name="wse_additional_file" class="regular-text" placeholder="inc/woo-functions.php" value="%4$s">
                  <br><em>%6$s</em>
                </p>
                <p><button type="submit" class="button">%5$s</button></p>
             </form>',
            esc_attr( $this->page_slug ),
            esc_html__( 'Scan Additional Theme File (Optional)', 'kiss-woo-shipping-debugger' ),
            esc_html( get_stylesheet_directory() ),
            esc_attr( $additional ),
            esc_html__( 'Scan for Custom Rules', 'kiss-woo-shipping-debugger' ),
            esc_html__( 'Path is relative to the active theme’s root directory (e.g., "woo-functions.php" or "inc/extra.php").', 'kiss-woo-shipping-debugger' )
        );

        try {
            $this->scan_and_render_custom_rules( $additional );
        } catch ( \Throwable $e ) {
            echo '<div class="notice notice-error"><pre>' . esc_html( $e->getMessage() ) . '</pre></div>';
            error_log( '[KISS Scanner] ' . $e->getMessage() );
        }

        // --- UI Settings Export UI + Zones & Methods Preview ---
        echo '<hr/><h2>' . esc_html__( 'UI-Based Settings Export', 'kiss-woo-shipping-debugger' ) . '</h2>';
        echo '<p>' . esc_html__( 'Preview and download WooCommerce shipping settings configured in the admin.', 'kiss-woo-shipping-debugger' ) . '</p>';

        // Export button
        printf(
            '<form method="post" action="%1$s" style="margin-bottom:1em;">%2$s
               <input type="hidden" name="action" value="%3$s">
               <p><button type="submit" class="button button-primary">%4$s</button></p>
             </form>',
            esc_url( admin_url( 'admin-post.php' ) ),
            wp_nonce_field( $this->page_slug, 'wse_nonce', true, false ),
            esc_attr( $this->page_slug ),
            esc_html__( 'Download CSV of UI Settings', 'kiss-woo-shipping-debugger' )
        );

        // Zones preview table + filters + warnings
        $this->render_preview_table();

        echo '</div>';
    }

    /**
     * Stream a CSV export of configured WooCommerce shipping settings.
     */
    public function handle_export(): void {
        // Capability check
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'kiss-woo-shipping-debugger' ), 403 );
        }

        // Nonce verification
        check_admin_referer( $this->page_slug, 'wse_nonce' );


        // Rate limiting: throttle export requests per user/IP
        $window = (int) apply_filters( 'kiss_wse_export_rate_limit_window', 60 ); // seconds
        if ( $window > 0 ) {
            $user_id    = get_current_user_id();
            $identifier = $user_id ? ( 'user_' . $user_id ) : ( 'ip_' . md5( $_SERVER['REMOTE_ADDR'] ?? '' ) );
            $key        = 'kiss_wse_export_rl_' . $identifier;
            if ( get_transient( $key ) ) {
                wp_die( esc_html__( 'Please wait before running another export.', 'kiss-woo-shipping-debugger' ), 429 );
            }
            set_transient( $key, 1, $window );
        }

        // Prepare CSV streaming
        // Security headers
        header( 'X-Content-Type-Options: nosniff' );
        header( 'X-Frame-Options: DENY' );


        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );

        $host     = parse_url( home_url(), PHP_URL_HOST );
        $filename = sanitize_file_name( sprintf( '%s-shipping-%s.csv', (string) $host, wp_date( 'Y-m-d-His' ) ) );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        /**
         * Stream CSV.
         * DRY: allow existing logic to output rows if present.
         */
        if ( method_exists( $this, 'output_csv' ) ) {
            $this->output_csv();
        } elseif ( function_exists( 'kiss_wse_output_csv' ) ) {
            kiss_wse_output_csv();
        } else {
            $out = fopen( 'php://output', 'w' );
            if ( $out ) {
                fputcsv( $out, [ 'notice', 'No export rows available in this build.' ] );
                fclose( $out );
            }
        }

        exit;
    }

    /**
     * Output a sanitized CSV export of WooCommerce shipping zones and methods.
     * Cells are protected against CSV injection by prefixing leading =,+,-,@.
     */
    public function output_csv(): void {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            $out = fopen( 'php://output', 'w' );
            if ( $out ) {
                fputcsv( $out, array_map( 'kiss_wse_csv_sanitize_cell', [ 'notice', 'WooCommerce shipping is not available.' ] ) );
                fclose( $out );
            }
            return;
        }

        $out = fopen( 'php://output', 'w' );
        if ( ! $out ) return;

        // Header row
        fputcsv( $out, array_map( 'kiss_wse_csv_sanitize_cell', [ 'Zone', 'Enabled', 'Disabled', 'Locations', 'Methods' ] ) );

        // Build a list of zone IDs (add 0 for Rest of the world)
        $zone_rows = \WC_Shipping_Zones::get_zones();
        $zone_ids  = [];
        foreach ( $zone_rows as $zr ) {
            if ( isset( $zr['zone_id'] ) ) { $zone_ids[] = (int) $zr['zone_id']; }
        }
        $zone_ids[] = 0; // Rest of the world

        foreach ( $zone_ids as $zone_id ) {
            $zone = new \WC_Shipping_Zone( (int) $zone_id );
            $zone_name = (string) $zone->get_zone_name();

            // Locations CSV (codes only)
            $locs = $zone->get_zone_locations();
            $loc_parts = [];
            foreach ( $locs as $loc ) {
                $code = isset( $loc->code ) ? $loc->code : ( $loc['code'] ?? '' );
                $loc_parts[] = (string) $code;
            }
            $locations_csv = implode( ', ', $loc_parts );

            // Methods and counts
            $methods = $zone->get_shipping_methods();
            $enabled = 0; $disabled = 0;
            $method_titles = [];
            foreach ( $methods as $m ) {
                $is_enabled = ( 'yes' === ( $m->enabled ?? 'no' ) );
                if ( $is_enabled ) { $enabled++; } else { $disabled++; }
                $title = isset( $m->title ) ? (string) $m->title : ( isset( $m->method_title ) ? (string) $m->method_title : (string) ( $m->id ?? '' ) );
                $method_titles[] = $title;
            }
            $methods_csv = implode( ' | ', $method_titles );

            $row = [ $zone_name, (string) $enabled, (string) $disabled, $locations_csv, $methods_csv ];
            // Sanitize for CSV injection and stream
            fputcsv( $out, array_map( 'kiss_wse_csv_sanitize_cell', $row ) );
        }

        fclose( $out );
    }
}

// Initialize the plugin
if ( class_exists( 'KISS_WSE_Debugger' ) ) {
    // Hook into WordPress initialization to ensure proper loading
    add_action( 'plugins_loaded', function() {
        error_log('KISS_WSE: Initializing plugin class');
        try {
            new KISS_WSE_Debugger();
            error_log('KISS_WSE: Plugin class initialized successfully');
        } catch (Exception $e) {
            error_log('KISS_WSE: Plugin initialization error: ' . $e->getMessage());
        } catch (Error $e) {
            error_log('KISS_WSE: Plugin initialization fatal error: ' . $e->getMessage());
        }
    } );
} else {
    // Log error if class doesn't exist
    error_log( 'KISS_WSE_Debugger class not found during plugin initialization' );
}

// Register AJAX handlers using wp_loaded hook to ensure WordPress is fully loaded
add_action( 'wp_loaded', function() {
    error_log('KISS_WSE: wp_loaded hook called, registering AJAX handlers');
    add_action( 'wp_ajax_kiss_wse_test_ajax', 'kiss_wse_test_ajax_callback' );
    add_action( 'wp_ajax_kiss_wse_run_single_test', 'kiss_wse_run_single_test_callback' );
    add_action( 'wp_ajax_kiss_wse_update_test_timestamp', 'kiss_wse_update_test_timestamp_callback' );
    error_log('KISS_WSE: AJAX handlers registered in wp_loaded hook');
} );