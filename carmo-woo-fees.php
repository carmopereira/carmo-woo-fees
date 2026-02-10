<?php
/**
 * Plugin Name: carmo-woo-fees
 * Description: Traditional WordPress plugin with wp-scripts support.
 * Author: carmopereira
 * Version:           1.0.13
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
     * Add fees to cart - no conditions, just add them
     */
    public static function add_fees(\WC_Cart $cart): void {
        $logger = wc_get_logger();
        $log_context = ['source' => 'carmo-woo-fees'];

        $logger->info('=== add_fees CHAMADO ===', $log_context);

        $subtotal = (float) $cart->get_subtotal();
        $shipping_total = (float) $cart->get_shipping_total();
        $base_amount = $subtotal + $shipping_total;

        $logger->info("Calculando fees: subtotal=$subtotal, shipping=$shipping_total, base=$base_amount", $log_context);

        // Add percentage fee
        if ($base_amount > 0) {
            $percentage_fee = $base_amount * self::PERCENTAGE_FEE_RATE;
            $cart->add_fee(__('Fee', 'carmo-woo-fees'), $percentage_fee, false);
            $logger->info("✓ Fee percentual adicionado: $percentage_fee", $log_context);
        }

        // Add standard fee
        $cart->add_fee(__('Standard Fee', 'carmo-woo-fees'), self::STANDARD_FEE, false);
        $logger->info("✓ Standard fee adicionado: " . self::STANDARD_FEE, $log_context);
    }
}

add_action('woocommerce_init', [Carmo_Woo_Fees::class, 'init']);
