<?php
/**
 * Plugin Name: carmo-woo-fees
 * Description: Traditional WordPress plugin with wp-scripts support.
 * Author: carmopereira
 * Version:           1.0.3
 * Text Domain: carmo-woo-fees
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Carmo_Woo_Fees {
    private const ROLE_CUSTOMER = 'customer';
    private const STANDARD_FEE = 54.69;
    private const PERCENTAGE_FEE_RATE = 0.15;
    private const STATUS_SESSION_KEY = 'carmo_woo_fees_status';

    public static function init(): void {
        add_action('woocommerce_cart_calculate_fees', [self::class, 'add_checkout_fees']);
        add_filter('woocommerce_store_api_cart_fees', [self::class, 'add_store_api_fees'], 10, 2);
        add_action('woocommerce_checkout_create_order', [self::class, 'ensure_order_fees'], 10, 2);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_console_logger']);
        add_action('wp_ajax_carmo_woo_fees_status', [self::class, 'ajax_status']);
        add_action('wp_ajax_nopriv_carmo_woo_fees_status', [self::class, 'ajax_status']);
    }

    public static function add_checkout_fees(\WC_Cart $cart): void {
        $decision = self::get_fee_decision(true);
        self::set_status($decision['passed'], $decision['reason']);
        self::log_status($decision['passed'], $decision['reason']);

        if (!$decision['passed']) {
            return;
        }

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $cart->add_fee(__('Fee', 'carmo-woo-fees'), $percentage_fee, false);
        }

        $cart->add_fee(__('Standard Fee', 'carmo-woo-fees'), self::STANDARD_FEE, false);
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
        $decision = self::get_fee_decision(false);
        self::set_status($decision['passed'], $decision['reason']);
        self::log_status($decision['passed'], $decision['reason']);

        if (!$decision['passed']) {
            return;
        }

        $existing_fees = $order->get_items('fee');
        foreach ($existing_fees as $fee_item) {
            $name = $fee_item->get_name();
            if ($name === __('Fee', 'carmo-woo-fees') || $name === __('Standard Fee', 'carmo-woo-fees')) {
                return;
            }
        }

        $cart = WC()->cart;
        $subtotal = $cart instanceof \WC_Cart ? (float) $cart->get_subtotal() : (float) $order->get_subtotal();
        $shipping_total = $cart instanceof \WC_Cart ? (float) $cart->get_shipping_total() : (float) $order->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $fee_item = new \WC_Order_Item_Fee();
            $fee_item->set_name(__('Fee', 'carmo-woo-fees'));
            $fee_item->set_amount($percentage_fee);
            $fee_item->set_total($percentage_fee);
            $fee_item->set_tax_class('');
            $fee_item->set_tax_status('none');
            $order->add_item($fee_item);
        }

        $standard_fee_item = new \WC_Order_Item_Fee();
        $standard_fee_item->set_name(__('Standard Fee', 'carmo-woo-fees'));
        $standard_fee_item->set_amount(self::STANDARD_FEE);
        $standard_fee_item->set_total(self::STANDARD_FEE);
        $standard_fee_item->set_tax_class('');
        $standard_fee_item->set_tax_status('none');
        $order->add_item($standard_fee_item);
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
            'window.carmoWooFees=%s;document.addEventListener("DOMContentLoaded",function(){var logStatus=function(){var data=new FormData();data.append("action","carmo_woo_fees_status");data.append("nonce",window.carmoWooFees.nonce);fetch(window.carmoWooFees.ajaxUrl,{method:"POST",credentials:"same-origin",body:data}).then(function(r){return r.json();}).then(function(resp){if(!resp||!resp.success){console.log("[carmo-woo-fees] Sem estado disponível.");return;}var s=resp.data||{};console.log("[carmo-woo-fees]",s.passed?"PASSOU":"NÃO PASSOU",s.reason||"");}).catch(function(){console.log("[carmo-woo-fees] Erro ao obter estado.");});};logStatus();if(window.jQuery){jQuery(document.body).on("updated_checkout",logStatus);}});',
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

    private static function get_fee_decision(bool $require_checkout): array {
        if (is_admin()) {
            return [
                'passed' => false,
                'reason' => 'Pedido em admin.',
            ];
        }

        if ($require_checkout && !is_checkout()) {
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
        if (!$customer instanceof \WC_Customer) {
            return [
                'passed' => false,
                'reason' => 'Cliente WooCommerce indisponível.',
            ];
        }

        $country = (string) $customer->get_shipping_country();
        if (strtoupper($country) !== 'US') {
            return [
                'passed' => false,
                'reason' => sprintf('País de envio "%s" não é US.', $country),
            ];
        }

        return [
            'passed' => true,
            'reason' => 'Filtros passaram. Taxas aplicadas.',
        ];
    }

    private static function log_status(bool $passed, string $reason): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];
        $prefix = $passed ? 'Filtros passaram: ' : 'Filtros falharam: ';

        $logger->debug($prefix . $reason, $log_context);
    }
}

Carmo_Woo_Fees::init();
