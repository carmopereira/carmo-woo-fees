<?php
/**
 * Plugin Name: carmo-woo-fees
 * Description: Traditional WordPress plugin with wp-scripts support.
 * Author: carmopereira
 * Version:           1.0.25
 * Text Domain: carmo-woo-fees
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce plugin is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>carmo-woo-fees</strong> requires WooCommerce to be installed and active.';
        echo '</p></div>';
    });
    return;
}

final class Carmo_Woo_Fees {
    public static function init(): void {
        // Simple hook to add fees to cart
        add_action('woocommerce_cart_calculate_fees', [self::class, 'add_fees']);

        // Track when Flexible Shipping method 56 is calculated
        add_filter('flexible_shipping_method_rate_id', [self::class, 'track_method_56_rate_id'], 10, 2);

        // Add browser console debugging
        add_action('wp_footer', [self::class, 'add_console_debug']);

        // AJAX endpoint for debug info
        add_action('wp_ajax_carmo_woo_fees_debug', [self::class, 'ajax_debug_info']);
        add_action('wp_ajax_nopriv_carmo_woo_fees_debug', [self::class, 'ajax_debug_info']);

        // Add settings
        add_filter('woocommerce_settings_tabs_array', [self::class, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_carmo_fees', [self::class, 'settings_tab']);
        add_action('woocommerce_update_options_carmo_fees', [self::class, 'update_settings']);
    }

    /**
     * Get setting value with default fallback
     */
    private static function get_setting(string $key, mixed $default): mixed {
        return get_option('carmo_woo_fees_' . $key, $default);
    }

    /**
     * Add settings tab to WooCommerce settings
     */
    public static function add_settings_tab(array $settings_tabs): array {
        $settings_tabs['carmo_fees'] = __('Carmo Fees', 'carmo-woo-fees');
        return $settings_tabs;
    }

    /**
     * Display settings tab content
     */
    public static function settings_tab(): void {
        woocommerce_admin_fields(self::get_settings());
    }

    /**
     * Save settings
     */
    public static function update_settings(): void {
        woocommerce_update_options(self::get_settings());
    }

    /**
     * Get all settings
     */
    public static function get_settings(): array {
        return [
            [
                'title' => __('Carmo Fees Settings', 'carmo-woo-fees'),
                'type'  => 'title',
                'desc'  => __('Configure fees to apply on checkout for specific shipping methods.', 'carmo-woo-fees'),
                'id'    => 'carmo_woo_fees_section'
            ],
            [
                'title'    => __('Flexible Shipping Method ID', 'carmo-woo-fees'),
                'desc'     => __('Enter the internal ID of the Flexible Shipping method (e.g., 56)', 'carmo-woo-fees'),
                'id'       => 'carmo_woo_fees_shipping_method_id',
                'type'     => 'number',
                'default'  => '56',
                'custom_attributes' => [
                    'min'  => '1',
                    'step' => '1',
                ],
            ],
            [
                'title' => __('Percentage Fee', 'carmo-woo-fees'),
                'type'  => 'title',
                'desc'  => '',
                'id'    => 'carmo_woo_fees_percentage_section'
            ],
            [
                'title'    => __('Percentage Fee Name', 'carmo-woo-fees'),
                'desc'     => __('Name displayed for the percentage fee', 'carmo-woo-fees'),
                'id'       => 'carmo_woo_fees_percentage_name',
                'type'     => 'text',
                'default'  => __('Fee', 'carmo-woo-fees'),
            ],
            [
                'title'    => __('Percentage Fee Rate (%)', 'carmo-woo-fees'),
                'desc'     => __('Percentage to apply (e.g., 15 for 15%)', 'carmo-woo-fees'),
                'id'       => 'carmo_woo_fees_percentage_rate',
                'type'     => 'number',
                'default'  => '15',
                'custom_attributes' => [
                    'min'  => '0',
                    'step' => '0.01',
                ],
            ],
            [
                'title' => __('Standard Fee', 'carmo-woo-fees'),
                'type'  => 'title',
                'desc'  => '',
                'id'    => 'carmo_woo_fees_standard_section'
            ],
            [
                'title'    => __('Standard Fee Name', 'carmo-woo-fees'),
                'desc'     => __('Name displayed for the standard fee', 'carmo-woo-fees'),
                'id'       => 'carmo_woo_fees_standard_name',
                'type'     => 'text',
                'default'  => __('Standard Fee', 'carmo-woo-fees'),
            ],
            [
                'title'    => __('Standard Fee Amount', 'carmo-woo-fees'),
                'desc'     => __('Fixed fee amount to apply', 'carmo-woo-fees'),
                'id'       => 'carmo_woo_fees_standard_amount',
                'type'     => 'number',
                'default'  => '54.69',
                'custom_attributes' => [
                    'min'  => '0',
                    'step' => '0.01',
                ],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'carmo_woo_fees_section'
            ]
        ];
    }

    /**
     * AJAX handler to get debug info
     */
    public static function ajax_debug_info(): void {
        $session_key = WC()->session ? WC()->session->get_customer_id() : 'guest';
        $shipping_method_id = (int) self::get_setting('shipping_method_id', 56);
        $method_rate_id = get_transient('carmo_woo_fees_method_' . $shipping_method_id . '_rate_id_' . $session_key);
        $chosen_methods = WC()->session ? WC()->session->get('chosen_shipping_methods') : [];

        $match_status = 'N/A';
        $match_method = '';

        if (!empty($chosen_methods) && is_array($chosen_methods)) {
            $chosen = $chosen_methods[0];

            // Check method 1: Stored rate ID
            if (!empty($method_rate_id) && $chosen === $method_rate_id) {
                $match_status = '✅ MATCH (via stored rate ID)';
                $match_method = 'Stored Rate ID';
            }
            // Check method 2: Rate ID pattern (ends with :ID)
            elseif (preg_match('/:' . $shipping_method_id . '$/', $chosen)) {
                $match_status = '✅ MATCH (via :' . $shipping_method_id . ' pattern)';
                $match_method = 'Rate ID Pattern';
            }
            // Check method 3: Numeric ID in string
            elseif (strpos($chosen, 'flexible_shipping') !== false) {
                if (preg_match_all('/\d+/', $chosen, $matches)) {
                    if (in_array((string)$shipping_method_id, $matches[0], true)) {
                        $match_status = '✅ MATCH (via numeric ID)';
                        $match_method = 'Numeric ID in String';
                    } else {
                        $match_status = '❌ No match';
                    }
                } else {
                    $match_status = '❌ No match';
                }
            } else {
                $match_status = '❌ No match - not a Flexible Shipping method';
            }
        }

        wp_send_json_success([
            'session_key' => $session_key,
            'method_rate_id' => $method_rate_id,
            'chosen_methods' => $chosen_methods,
            'target_method_id' => $shipping_method_id,
            'match_status' => $match_status,
            'match_method' => $match_method,
        ]);
    }

    /**
     * Add browser console debugging (dynamic)
     * Only enabled when URL contains ?debug=1
     */
    public static function add_console_debug(): void {
        // Only enable debug mode when explicitly requested via URL parameter
        if (!isset($_GET['debug']) || $_GET['debug'] !== '1') {
            return;
        }

        if (!is_checkout() && !is_cart()) {
            return;
        }
        ?>
        <script>
        (function() {
            function logCarmoDebug() {
                console.log('=== Carmo Woo Fees Debug (Updated) ===');
                console.log('Timestamp:', new Date().toLocaleTimeString());

                // Get current shipping method selection from DOM
                const shippingInputs = document.querySelectorAll('input[name^="shipping_method"]');
                const selectedMethod = Array.from(shippingInputs).find(input => input.checked);
                console.log('Selected Shipping Method (DOM):', selectedMethod ? selectedMethod.value : 'none');

                // Make AJAX call to get server-side debug info
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'carmo_woo_fees_debug'
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Session Key:', response.data.session_key);
                            console.log('Stored Method 56 Rate ID:', response.data.method_56_rate_id);
                            console.log('Chosen Shipping Methods (Server):', response.data.chosen_methods);
                            console.log('Target Method ID:', response.data.target_method_id);
                            console.log('Match Status:', response.data.match_status);
                            if (response.data.match_method) {
                                console.log('Match Method:', response.data.match_method);
                            }
                        }
                    }
                });
            }

            // Log on page load
            logCarmoDebug();

            // Log when checkout updates (shipping method changed)
            jQuery(document.body).on('updated_checkout', function() {
                console.log('🔄 Checkout updated - refreshing debug info...');
                setTimeout(logCarmoDebug, 500); // Small delay to let WooCommerce update session
            });

            // Log when cart updates
            jQuery(document.body).on('updated_cart_totals', function() {
                console.log('🔄 Cart updated - refreshing debug info...');
                setTimeout(logCarmoDebug, 500);
            });
        })();
        </script>
        <?php
    }

    /**
     * Track the rate ID for Flexible Shipping method
     *
     * @param string $rate_id The formatted rate ID (e.g., flexible_shipping_1_express)
     * @param array $shipping_method The method settings including internal ID
     * @return string The unmodified rate ID (pass-through filter)
     */
    public static function track_method_56_rate_id(string $rate_id, array $shipping_method): string {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $target_method_id = (int) self::get_setting('shipping_method_id', 56);

        // DEBUG: Log all method data
        $method_id = $shipping_method['id'] ?? null;
        $logger->info("=== Flexible Shipping Method Rate ID Hook ===", $log_context);
        $logger->info("Rate ID: $rate_id", $log_context);
        $logger->info("Method ID: " . var_export($method_id, true), $log_context);
        $logger->info("Full shipping_method array: " . print_r($shipping_method, true), $log_context);

        // Check if this is the target method
        if ($method_id == $target_method_id) {
            // Store the rate ID in a transient for this session
            $session_key = WC()->session ? WC()->session->get_customer_id() : 'guest';
            set_transient('carmo_woo_fees_method_' . $target_method_id . '_rate_id_' . $session_key, $rate_id, HOUR_IN_SECONDS);

            $logger->info("✓✓✓ MATCHED! Tracked method $target_method_id with rate ID: $rate_id (session: $session_key)", $log_context);
        } else {
            $logger->info("Method ID '$method_id' does not match target $target_method_id", $log_context);
        }

        return $rate_id;
    }

    /**
     * Add fees to cart - only for customers and guests
     */
    public static function add_fees(\WC_Cart $cart): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $logger->info('=== add_fees CALLED ===', $log_context);

        // Only apply fees to guests and customers
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (empty($user->roles) || !in_array('customer', $user->roles, true)) {
                $logger->info('Fees not applied - user is not a customer', $log_context);
                return;
            }
        }

        $logger->info('✓ User is guest or customer', $log_context);

        // Only apply fees if shipping country is US
        $customer = WC()->customer;
        if (!$customer instanceof \WC_Customer) {
            $logger->info('Fees not applied - customer object not available', $log_context);
            return;
        }

        $shipping_country = $customer->get_shipping_country();
        if (empty($shipping_country)) {
            $shipping_country = $customer->get_billing_country();
        }

        if (strtoupper($shipping_country) !== 'US') {
            $logger->info("Fees not applied - shipping country is '$shipping_country', not US", $log_context);
            return;
        }

        $logger->info('✓ Shipping country is US', $log_context);

        // Get settings
        $target_method_id = (int) self::get_setting('shipping_method_id', 56);
        $percentage_name = self::get_setting('percentage_name', __('Fee', 'carmo-woo-fees'));
        $percentage_rate = (float) self::get_setting('percentage_rate', 15) / 100;
        $standard_name = self::get_setting('standard_name', __('Standard Fee', 'carmo-woo-fees'));
        $standard_amount = (float) self::get_setting('standard_amount', 54.69);

        // Only apply fees if target Flexible Shipping method is selected
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        $logger->info("DEBUG: Chosen shipping methods: " . print_r($chosen_methods, true), $log_context);

        if (empty($chosen_methods) || !is_array($chosen_methods)) {
            $logger->info('Fees not applied - no shipping method chosen', $log_context);
            return;
        }

        $chosen_method = $chosen_methods[0]; // First shipping method
        $logger->info("DEBUG: Selected method: '$chosen_method'", $log_context);

        // Get the stored rate ID for target method
        $session_key = WC()->session->get_customer_id();
        $logger->info("DEBUG: Session key: '$session_key'", $log_context);

        $method_rate_id = get_transient('carmo_woo_fees_method_' . $target_method_id . '_rate_id_' . $session_key);
        $logger->info("DEBUG: Stored method $target_method_id rate ID: " . var_export($method_rate_id, true), $log_context);

        // Check if target method is selected
        $is_target_method = false;

        // Method 1: Check against stored rate ID (from hook)
        if (!empty($method_rate_id) && $chosen_method === $method_rate_id) {
            $is_target_method = true;
            $logger->info("✓ Matched via stored rate ID", $log_context);
        }

        // Method 2: Fallback - check if rate ID contains :ID (flexible_shipping_single:56)
        if (!$is_target_method && preg_match('/:' . $target_method_id . '$/', $chosen_method)) {
            $is_target_method = true;
            $logger->info("✓ Matched via rate ID pattern (ends with :$target_method_id)", $log_context);
        }

        // Method 3: Additional fallback - check flexible_shipping with target method ID
        if (!$is_target_method && strpos($chosen_method, 'flexible_shipping') !== false) {
            // Extract any numeric IDs from the rate ID
            if (preg_match_all('/\d+/', $chosen_method, $matches)) {
                if (in_array((string)$target_method_id, $matches[0], true)) {
                    $is_target_method = true;
                    $logger->info("✓ Matched via numeric ID in rate string", $log_context);
                }
            }
        }

        if (!$is_target_method) {
            $logger->info("Fees not applied - chosen method '$chosen_method' is not method $target_method_id", $log_context);
            return;
        }

        $logger->info("✓ Flexible Shipping method $target_method_id is selected - applying fees", $log_context);

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        $logger->info("Calculating fees: subtotal=$subtotal, shipping=$shipping_total, base=$base_amount", $log_context);

        // Add percentage fee
        if ($base_amount > 0 && $percentage_rate > 0) {
            $percentage_fee = $base_amount * $percentage_rate;
            $cart->add_fee($percentage_name, $percentage_fee, false);
            $logger->info("✓ Percentage fee added: $percentage_fee ($percentage_name)", $log_context);
        }

        // Add standard fee
        if ($standard_amount > 0) {
            $cart->add_fee($standard_name, $standard_amount, false);
            $logger->info("✓ Standard fee added: $standard_amount ($standard_name)", $log_context);
        }
    }
}

add_action('woocommerce_init', [Carmo_Woo_Fees::class, 'init']);
