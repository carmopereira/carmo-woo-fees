<?php
/**
 * Plugin Name: carmo-woo-fees
 * Description: Traditional WordPress plugin with wp-scripts support.
 * Author: carmopereira
 * Version:           1.1.0
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
    private const ROLE_CUSTOMER = 'customer';
    private const STANDARD_FEE = 54.69;
    private const PERCENTAGE_FEE_RATE = 0.15;
    private const STATUS_SESSION_KEY = 'carmo_woo_fees_status';
    private const FEE_DECISION_SESSION_KEY = 'carmo_woo_fees_decision';

    public static function init(): void {
        add_action('woocommerce_cart_calculate_fees', [self::class, 'add_checkout_fees']);
        add_filter('woocommerce_store_api_cart_fees', [self::class, 'add_store_api_fees'], 10, 2);
        add_action('woocommerce_checkout_create_order', [self::class, 'ensure_order_fees'], 10, 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_console_logger']);
        add_action('wp_ajax_carmo_woo_fees_status', [self::class, 'ajax_status']);
        add_action('wp_ajax_nopriv_carmo_woo_fees_status', [self::class, 'ajax_status']);
    }

    public static function add_checkout_fees(\WC_Cart $cart): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $logger->debug('=== add_checkout_fees CHAMADO', $log_context);

        $decision = self::get_fee_decision(true);
        self::set_status($decision['passed'], $decision['reason']);
        self::log_status($decision['passed'], $decision['reason']);

        if (!$decision['passed']) {
            $logger->warning('Validação falhou ao adicionar fees ao cart: ' . $decision['reason'], $log_context);
            return;
        }

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        $logger->debug("Adicionando fees ao cart: subtotal=$subtotal, shipping=$shipping_total, base=$base_amount", $log_context);

        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $cart->add_fee(__('Fee', 'carmo-woo-fees'), $percentage_fee, false);
            $logger->info("✓ Fee percentual adicionado ao cart: $percentage_fee", $log_context);
        }

        $cart->add_fee(__('Standard Fee', 'carmo-woo-fees'), self::STANDARD_FEE, false);
        $logger->info("✓ Standard fee adicionado ao cart: " . self::STANDARD_FEE, $log_context);
    }

    public static function add_store_api_fees(array $fees, \WC_Cart $cart): array {
        $decision = self::get_fee_decision(false);
        self::set_status($decision['passed'], $decision['reason']);
        self::log_status($decision['passed'], $decision['reason']);

        if (!$decision['passed']) {
            return $fees;
        }

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $fees[] = [
                'id' => 'carmo-woo-fees-percentage',
                'name' => __('Fee', 'carmo-woo-fees'),
                'amount' => wc_add_number_precision($percentage_fee),
                'taxable' => false,
            ];
        }

        $fees[] = [
            'id' => 'carmo-woo-fees-standard',
            'name' => __('Standard Fee', 'carmo-woo-fees'),
            'amount' => wc_add_number_precision(self::STANDARD_FEE),
            'taxable' => false,
        ];

        return $fees;
    }

    public static function ensure_order_fees(\WC_Order $order, array $data): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $logger->debug('=== ensure_order_fees CHAMADO para Order #' . $order->get_id(), $log_context);

        $decision = self::get_fee_decision(false);
        self::set_status($decision['passed'], $decision['reason']);
        self::log_status($decision['passed'], $decision['reason']);

        if (!$decision['passed']) {
            $logger->warning('Validação falhou: ' . $decision['reason'], $log_context);
            return;
        }

        $existing_fees = $order->get_items('fee');
        $logger->debug('Fees existentes na order: ' . count($existing_fees), $log_context);

        $has_percentage_fee = false;
        $has_standard_fee = false;

        foreach ($existing_fees as $fee_item) {
            $name = $fee_item->get_name();
            $logger->debug('Fee encontrado: ' . $name . ' = ' . $fee_item->get_total(), $log_context);
            if ($name === __('Fee', 'carmo-woo-fees')) {
                $has_percentage_fee = true;
            }
            if ($name === __('Standard Fee', 'carmo-woo-fees')) {
                $has_standard_fee = true;
            }
        }

        // If both fees already exist, nothing to do
        if ($has_percentage_fee && $has_standard_fee) {
            $logger->debug('Ambos os fees já existem, nada a fazer', $log_context);
            return;
        }

        $cart = WC()->cart;
        $subtotal = $cart instanceof \WC_Cart ? (float) $cart->get_subtotal() : (float) $order->get_subtotal();
        $shipping_total = $cart instanceof \WC_Cart ? (float) $cart->get_shipping_total() : (float) $order->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        $logger->debug("Calculando fees: subtotal=$subtotal, shipping=$shipping_total, base=$base_amount", $log_context);

        if (!$has_percentage_fee && $base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $fee_item = new \WC_Order_Item_Fee();
            $fee_item->set_name(__('Fee', 'carmo-woo-fees'));
            $fee_item->set_amount($percentage_fee);
            $fee_item->set_total($percentage_fee);
            $fee_item->set_total_tax(0);
            $fee_item->set_taxes(['total' => [], 'subtotal' => []]);
            $fee_item->set_tax_class('');
            $fee_item->set_tax_status('none');
            $order->add_item($fee_item);
            $logger->info("✓ Fee percentual adicionado: $percentage_fee", $log_context);
        }

        if (!$has_standard_fee) {
            $standard_fee_item = new \WC_Order_Item_Fee();
            $standard_fee_item->set_name(__('Standard Fee', 'carmo-woo-fees'));
            $standard_fee_item->set_amount(self::STANDARD_FEE);
            $standard_fee_item->set_total(self::STANDARD_FEE);
            $standard_fee_item->set_total_tax(0);
            $standard_fee_item->set_taxes(['total' => [], 'subtotal' => []]);
            $standard_fee_item->set_tax_class('');
            $standard_fee_item->set_tax_status('none');
            $order->add_item($standard_fee_item);
            $logger->info("✓ Standard fee adicionado: " . self::STANDARD_FEE, $log_context);
        }

        // Recalculate order totals to include the fees
        $order->calculate_totals();
        $logger->debug('Totais recalculados. Novo total: ' . $order->get_total(), $log_context);

        // Save the order to persist fees to database
        $order->save();
        $logger->info('=== Order #' . $order->get_id() . ' guardada com fees', $log_context);
    }

    public static function enqueue_assets(): void {
        if (!is_checkout()) {
            return;
        }

        $asset_file = plugin_dir_path(__FILE__) . 'build/index.asset.php';
        if (!file_exists($asset_file)) {
            return;
        }

        $asset = include $asset_file;
        $dependencies = $asset['dependencies'] ?? [];
        $version = $asset['version'] ?? filemtime(plugin_dir_path(__FILE__) . 'build/index.js');

        wp_enqueue_script(
            'carmo-woo-fees',
            plugin_dir_url(__FILE__) . 'build/index.js',
            $dependencies,
            $version,
            true
        );
    }

    public static function enqueue_console_logger(): void {
        if (!is_checkout()) {
            return;
        }

        $handle = wp_script_is('wc-blocks-checkout', 'registered') ? 'wc-blocks-checkout' : 'wc-checkout';
        wp_enqueue_script($handle);

        $payload = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('carmo_woo_fees_status'),
        ];

        $inline_script = sprintf(
            'window.carmoWooFees=%s;document.addEventListener("DOMContentLoaded",function(){var logStatus=function(){console.log("[carmo-woo-fees] Checking fee status...");var data=new FormData();data.append("action","carmo_woo_fees_status");data.append("nonce",window.carmoWooFees.nonce);fetch(window.carmoWooFees.ajaxUrl,{method:"POST",credentials:"same-origin",body:data}).then(function(r){return r.json();}).then(function(resp){if(!resp||!resp.success){console.warn("[carmo-woo-fees] No status available");return;}var s=resp.data||{};if(s.passed){console.log("[carmo-woo-fees] ✓ PASSOU -",s.reason||"");}else{console.warn("[carmo-woo-fees] ✗ NÃO PASSOU -",s.reason||"");}}).catch(function(e){console.error("[carmo-woo-fees] Error:",e);});};logStatus();if(window.jQuery){jQuery(document.body).on("updated_checkout payment_method_selected",logStatus);}var emailField=document.querySelector("input[type=email]");if(emailField){var debounceTimer;emailField.addEventListener("blur",function(){clearTimeout(debounceTimer);debounceTimer=setTimeout(logStatus,1000);});}});',
            wp_json_encode($payload)
        );

        wp_add_inline_script($handle, $inline_script, 'after');
    }

    public static function ajax_status(): void {
        check_ajax_referer('carmo_woo_fees_status', 'nonce');

        $status = self::get_status();
        if ($status === null) {
            $decision = self::get_fee_decision(false);
            $status = [
                'passed' => $decision['passed'],
                'reason' => $decision['reason'],
            ];
        }

        wp_send_json_success($status);
    }

    private static function set_status(bool $passed, string $reason): void {
        if (!WC()->session) {
            return;
        }

        WC()->session->set(
            self::STATUS_SESSION_KEY,
            [
                'passed' => $passed,
                'reason' => $reason,
            ]
        );
    }

    private static function get_status(): ?array {
        if (!WC()->session) {
            return null;
        }

        $status = WC()->session->get(self::STATUS_SESSION_KEY);
        if (!is_array($status)) {
            return null;
        }

        return $status;
    }

    private static function cache_fee_decision(array $decision): void {
        if (!WC()->session) {
            return;
        }

        WC()->session->set(
            self::FEE_DECISION_SESSION_KEY,
            array_merge($decision, ['timestamp' => time()])
        );
    }

    private static function get_cached_fee_decision(): ?array {
        if (!WC()->session) {
            return null;
        }

        $cached = WC()->session->get(self::FEE_DECISION_SESSION_KEY);
        if (!is_array($cached)) {
            return null;
        }

        // Cache valid for 5 minutes
        $age = time() - ($cached['timestamp'] ?? 0);
        if ($age > 300) {
            return null;
        }

        return $cached;
    }

    private static function get_fee_decision(bool $require_checkout): array {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $context_info = sprintf(
            'is_checkout=%s, wp_doing_ajax=%s, is_admin=%s, require_checkout=%s',
            is_checkout() ? 'true' : 'false',
            wp_doing_ajax() ? 'true' : 'false',
            is_admin() ? 'true' : 'false',
            $require_checkout ? 'true' : 'false'
        );
        $logger->debug("get_fee_decision() chamado: $context_info", $log_context);

        // Allow frontend AJAX requests, block actual admin orders
        if (is_admin() && !wp_doing_ajax()) {
            return [
                'passed' => false,
                'reason' => 'Pedido em admin.',
            ];
        }

        // During AJAX requests, is_checkout() may return false even when in checkout context
        // The hook woocommerce_cart_calculate_fees is only called during checkout anyway
        if ($require_checkout && !is_checkout() && !wp_doing_ajax()) {
            return [
                'passed' => false,
                'reason' => 'Não é checkout.',
            ];
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (empty($user->roles) || !in_array(self::ROLE_CUSTOMER, $user->roles, true)) {
                return [
                    'passed' => false,
                    'reason' => 'Role diferente de customer.',
                ];
            }
        }

        $customer = WC()->customer;
        $country = '';

        // Try to get customer and country
        if ($customer instanceof \WC_Customer) {
            // First try shipping country
            $country = (string) $customer->get_shipping_country();

            // Fallback to billing country if shipping is empty
            if (empty($country)) {
                $country = (string) $customer->get_billing_country();
            }
        }

        // If customer is temporarily unavailable, check cache
        if (empty($country)) {
            $cached_decision = self::get_cached_fee_decision();

            // If we had a recent positive decision, trust it
            if ($cached_decision !== null && $cached_decision['passed'] === true) {
                return [
                    'passed' => true,
                    'reason' => 'Usando decisão em cache (cliente temporariamente indisponível).',
                ];
            }

            // No customer and no cached decision - reject
            return [
                'passed' => false,
                'reason' => 'Cliente WooCommerce indisponível e sem cache.',
            ];
        }

        // Validate country is US
        if (strtoupper($country) !== 'US') {
            $decision = [
                'passed' => false,
                'reason' => sprintf('País de envio "%s" não é US.', $country),
            ];
            self::cache_fee_decision($decision);
            return $decision;
        }

        $decision = [
            'passed' => true,
            'reason' => 'Filtros passaram. Taxas aplicadas.',
        ];
        self::cache_fee_decision($decision);
        return $decision;
    }

    private static function log_status(bool $passed, string $reason): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];
        $prefix = $passed ? 'Filtros passaram: ' : 'Filtros falharam: ';

        $logger->debug($prefix . $reason, $log_context);
    }
}

add_action('woocommerce_init', [Carmo_Woo_Fees::class, 'init']);
