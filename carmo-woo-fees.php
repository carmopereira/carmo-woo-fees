<?php
/**
 * Plugin Name: carmo-woo-fees
 * Description: Traditional WordPress plugin with wp-scripts support.
 * Author: carmopereira
 * Version:           1.0.17
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
    private const STANDARD_FEE = 54.69;
    private const PERCENTAGE_FEE_RATE = 0.15;

    public static function init(): void {
        // Simple hook to add fees to cart
        add_action('woocommerce_cart_calculate_fees', [self::class, 'add_fees']);
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

        $logger->info('✓ Shipping country is US - applying fees', $log_context);

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        $logger->info("Calculating fees: subtotal=$subtotal, shipping=$shipping_total, base=$base_amount", $log_context);

        // Add percentage fee
        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $cart->add_fee(__('Fee', 'carmo-woo-fees'), $percentage_fee, false);
            $logger->info("✓ Percentage fee added: $percentage_fee", $log_context);
        }

        // Add standard fee
        $cart->add_fee(__('Standard Fee', 'carmo-woo-fees'), self::STANDARD_FEE, false);
        $logger->info("✓ Standard fee added: " . self::STANDARD_FEE, $log_context);
    }
}

add_action('woocommerce_init', [Carmo_Woo_Fees::class, 'init']);
