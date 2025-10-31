<?php
/**
 * Dual Currency Hooks
 *
 * Integrates dual currency display into WooCommerce pages
 */

namespace AdpCustom;

class DualCurrencyHooks {

    private static $adp_available = null;

    /**
     * Check if ADP functions are available (cached)
     */
    private static function is_adp_available(): bool
    {
        if (self::$adp_available === null) {
            self::$adp_available = function_exists('adp_functions');
        }
        return self::$adp_available;
    }

    /**
     * Initialize all hooks
     */
    public static function init() 
    {
        if (!DualCurrency::is_enabled()) {
            error_log('ADP Dual Currency: Feature is DISABLED');
            return;
        }

        // Single product page (high priority for Elementor compatibility)
        add_filter('woocommerce_get_price_html', [__CLASS__, 'single_product_price'], 100, 2);

        // Variable product specific hooks
        add_filter('woocommerce_variable_price_html', [__CLASS__, 'variable_product_price'], 100, 2);
        add_filter('woocommerce_variable_sale_price_html', [__CLASS__, 'variable_product_price'], 100, 2);

        // Variation price (when user selects options)
        add_filter('woocommerce_available_variation', [__CLASS__, 'variation_data'], 100, 3);

        // Additional high-priority filter for Elementor templates
        $elementor_loaded = did_action('elementor/loaded');

        if ($elementor_loaded) {
            add_filter('woocommerce_get_price_html', [__CLASS__, 'elementor_product_price'], 999, 2);
        }

        // Elementor widget support
        add_action('elementor/widget/before_render_content', [__CLASS__, 'elementor_before_render']);
        add_action('elementor/widget/after_render_content', [__CLASS__, 'elementor_after_render']);

        // Cart & Mini Cart - use VERY high priority to run after everything else
        add_filter('woocommerce_cart_item_price', [__CLASS__, 'cart_item_price'], PHP_INT_MAX, 3);
        add_filter('woocommerce_cart_item_subtotal', [__CLASS__, 'cart_item_subtotal'], PHP_INT_MAX, 3);

        // Nuclear option: Use output buffering to modify cart HTML after rendering
        add_action('woocommerce_before_cart', [__CLASS__, 'start_cart_capture'], 1);
        add_action('woocommerce_after_cart', [__CLASS__, 'end_cart_capture'], 999);

        // Cart totals
        add_filter('woocommerce_cart_subtotal', [__CLASS__, 'cart_total_html'], 100, 3);
        add_filter('woocommerce_cart_totals_order_total_html', [__CLASS__, 'simple_total_html'], 100);

        // Cart discount/savings
        add_filter('woocommerce_cart_totals_coupon_html', [__CLASS__, 'cart_discount_html'], 100, 3);
        add_filter('woocommerce_coupon_discount_amount_html', [__CLASS__, 'simple_price_html'], 100);

        // Side cart / mini cart specific
        add_filter('woocommerce_widget_cart_subtotal_html', [__CLASS__, 'simple_price_html'], 100);

        // Checkout
        add_filter('woocommerce_checkout_item_subtotal', [__CLASS__, 'cart_item_subtotal'], 100, 3);

        // Order received / Thank you page
        add_filter('woocommerce_order_formatted_line_subtotal', [__CLASS__, 'order_line_subtotal'], 100, 3);
        add_filter('woocommerce_get_formatted_order_total', [__CLASS__, 'order_total_html'], 100, 2);

        // My Account - Order details
        // Uses the same hooks as order received page

        // Order emails
        add_filter('woocommerce_email_order_item_quantity', [__CLASS__, 'email_order_item_quantity'], 100, 2);
        add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_after_order_table'], 10, 4);

        // Side cart checkout button - use output buffering
        add_action('woocommerce_widget_shopping_cart_before_buttons', [__CLASS__, 'start_button_buffer'], 1);
        add_action('woocommerce_widget_shopping_cart_after_buttons', [__CLASS__, 'end_button_buffer'], 999);

        // XooWoo Side Cart specific hooks
        add_action('xoo_wsc_footer_start', [__CLASS__, 'start_button_buffer'], 1);
        add_action('xoo_wsc_footer_end', [__CLASS__, 'end_button_buffer'], 999);
    }

    /**
     * Elementor: Enable price filter before widget renders
     */
    public static function elementor_before_render($widget) 
    {
        if (!DualCurrency::is_enabled()) {
            return;
        }

        $widget_name = $widget->get_name();

        // Target Elementor product price widgets (multiple possible names)
        $price_widgets = [
            'woocommerce-product-price',
            'wc-product-price',
            'wc-price',
            'product-price',
            'woocommerce-price'
        ];

        if (in_array($widget_name, $price_widgets)) {
            // Force our filter to run with higher priority
            add_filter('woocommerce_get_price_html', [__CLASS__, 'elementor_product_price'], 999, 2);
        }
    }

    /**
     * Elementor: Clean up after widget renders
     */
    public static function elementor_after_render($widget) 
    {
        $widget_name = $widget->get_name();

        $price_widgets = [
            'woocommerce-product-price',
            'wc-product-price',
            'wc-price',
            'product-price',
            'woocommerce-price'
        ];

        if (in_array($widget_name, $price_widgets)) {
            remove_filter('woocommerce_get_price_html', [__CLASS__, 'elementor_product_price'], 999);
        }
    }

    /**
     * Elementor product price (very high priority)
     */
    public static function elementor_product_price($price_html, $product) 
    {
        if (!DualCurrency::is_enabled() || empty($price_html)) {
            return $price_html;
        }

        // Don't double-wrap
        if (strpos($price_html, 'dual-currency') !== false || strpos($price_html, 'eur-equivalent') !== false) {
            return $price_html;
        }

        if (!is_object($product) || !method_exists($product, 'get_type')) {
            return $price_html;
        }

        // Variable products
        if ($product->is_type('variable')) {
            $min_regular = (float) $product->get_variation_regular_price('min', true);
            $max_regular = (float) $product->get_variation_regular_price('max', true);
            $min_active = (float) $product->get_variation_price('min', true);
            $max_active = (float) $product->get_variation_price('max', true);

            $suffix = $product->get_price_suffix();
            $prefix = esc_html__('From', 'adp-custom');

            // Single price point
            if ($min_active === $max_active) {
                if ($min_regular > 0 && $min_regular > $min_active) {
                    return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix);
                } else {
                    return DualCurrency::format_regular_dual_price($min_active, $suffix);
                }
            } else {
                // Price range
                if ($min_regular > 0 && $min_regular > $min_active) {
                    return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix, $prefix);
                } else {
                    return DualCurrency::format_regular_dual_price($min_active, $suffix, $prefix);
                }
            }
        }

        // Simple products - check for ADP discount
        $adp_price = null;
        if (self::is_adp_available()) {
            $pf = adp_functions();
            if (is_object($pf) && method_exists($pf, 'getDiscountedProductPrice')) {
                $res = $pf->getDiscountedProductPrice($product, 1, true);
                if (is_numeric($res)) {
                    $adp_price = (float) $res;
                }
            }
        }

        $regular_price = (float) $product->get_regular_price();
        // Use WooCommerce sale price if available, otherwise use ADP
        $sale_price = $product->get_sale_price() ? (float) $product->get_sale_price() : null;
        $active_price = $adp_price ?? $sale_price ?? wc_get_price_to_display($product);

        if ($active_price <= 0) {
            return $price_html;
        }

        $suffix = $product->get_price_suffix();

        // Has discount?
        if ($regular_price > 0 && $active_price < $regular_price) {
            $result = DualCurrency::format_discounted_dual_price($regular_price, $active_price, $suffix);
            return $result;
        } else {
            $result = DualCurrency::format_regular_dual_price($active_price, $suffix);
            return $result;
        }
    }

    /**
     * Single product page price
     */
    public static function single_product_price($price_html, $product) 
    {
        if (!DualCurrency::is_enabled() || empty($price_html)) {
            return $price_html;
        }

        // Don't double-wrap if already processed
        if (strpos($price_html, 'dual-currency') !== false || strpos($price_html, 'eur-equivalent') !== false) {
            return $price_html;
        }

        // Skip if not a product object
        if (!is_object($product) || !method_exists($product, 'get_type')) {
            return $price_html;
        }

        // Skip in product loops (they use our template)
        if ((is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy()) && !is_product()) {
            return $price_html;
        }

        // Variable products - handle them too for single product pages
        if ($product->is_type('variable')) {
            // On single product page, process variable product
            if (is_product()) {
                $min_regular = (float) $product->get_variation_regular_price('min', true);
                $max_regular = (float) $product->get_variation_regular_price('max', true);
                $min_active = (float) $product->get_variation_price('min', true);
                $max_active = (float) $product->get_variation_price('max', true);

                $suffix = $product->get_price_suffix();
                $prefix = esc_html__('From', 'adp-custom');

                // Single price point
                if ($min_active === $max_active) {
                    if ($min_regular > $min_active) {
                        return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix);
                    } else {
                        return DualCurrency::format_regular_dual_price($min_active, $suffix);
                    }
                } else {
                    // Price range
                    if ($min_regular > $min_active) {
                        return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix, $prefix);
                    } else {
                        return DualCurrency::format_regular_dual_price($min_active, $suffix, $prefix);
                    }
                }
            }
            return $price_html;
        }

        // Simple products
        // Check if we have ADP discounted price
        $adp_price = null;
        if (self::is_adp_available()) {
            $pf = adp_functions();
            if (is_object($pf) && method_exists($pf, 'getDiscountedProductPrice')) {
                $res = $pf->getDiscountedProductPrice($product, 1, true);
                if (is_numeric($res)) {
                    $adp_price = (float) $res;
                }
            }
        }

        $regular_price = (float) $product->get_regular_price();
        $active_price = $adp_price ?? wc_get_price_to_display($product);

        if ($active_price <= 0) {
            return $price_html;
        }

        $suffix = $product->get_price_suffix();

        // Has discount?
        if ($regular_price > 0 && $active_price < $regular_price) {
            return DualCurrency::format_discounted_dual_price($regular_price, $active_price, $suffix);
        } else {
            return DualCurrency::format_regular_dual_price($active_price, $suffix);
        }
    }

    /**
     * Variable product main price display
     */
    public static function variable_product_price($price_html, $product) 
    {
        if (!DualCurrency::is_enabled() || empty($price_html)) {
            return $price_html;
        }

        // Don't double-wrap
        if (strpos($price_html, 'dual-currency') !== false || strpos($price_html, 'eur-equivalent') !== false) {
            return $price_html;
        }

        if (!is_object($product) || !method_exists($product, 'get_type')) {
            return $price_html;
        }

        $min_regular = (float) $product->get_variation_regular_price('min', true);
        $max_regular = (float) $product->get_variation_regular_price('max', true);
        $min_active = (float) $product->get_variation_price('min', true);
        $max_active = (float) $product->get_variation_price('max', true);

        $suffix = $product->get_price_suffix();
        $prefix = esc_html__('From', 'adp-custom');

        // Single price point
        if ($min_active === $max_active) {
            if ($min_regular > 0 && $min_regular > $min_active) {
                return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix);
            } else {
                return DualCurrency::format_regular_dual_price($min_active, $suffix);
            }
        } else {
            // Price range
            if ($min_regular > 0 && $min_regular > $min_active) {
                return DualCurrency::format_discounted_dual_price($min_regular, $min_active, $suffix, $prefix);
            } else {
                return DualCurrency::format_regular_dual_price($min_active, $suffix, $prefix);
            }
        }
    }

    /**
     * Variation data (modifies price_html in variation JSON)
     */
    public static function variation_data($variation_data, $product, $variation) 
    {
        if (!DualCurrency::is_enabled()) {
            return $variation_data;
        }

        if (!isset($variation_data['price_html']) || empty($variation_data['price_html'])) {
            return $variation_data;
        }

        $price_html = $variation_data['price_html'];

        // Don't double-wrap
        if (strpos($price_html, 'dual-currency') !== false || strpos($price_html, 'eur-equivalent') !== false) {
            return $variation_data;
        }

        // Get variation prices
        $regular_price = (float) $variation->get_regular_price();
        $sale_price = $variation->get_sale_price() ? (float) $variation->get_sale_price() : null;

        // Check for ADP discount
        $adp_price = null;
        if (self::is_adp_available()) {
            $pf = adp_functions();
            if (is_object($pf) && method_exists($pf, 'getDiscountedProductPrice')) {
                $res = $pf->getDiscountedProductPrice($variation, 1, true);
                if (is_numeric($res)) {
                    $adp_price = (float) $res;
                }
            }
        }

        $active_price = $adp_price ?? $sale_price ?? (float) $variation->get_price();
        $suffix = $product->get_price_suffix();

        // Generate dual currency HTML
        if ($regular_price > 0 && $active_price < $regular_price) {
            $variation_data['price_html'] = DualCurrency::format_discounted_dual_price($regular_price, $active_price, $suffix);
        } else {
            $variation_data['price_html'] = DualCurrency::format_regular_dual_price($active_price, $suffix);
        }

        return $variation_data;
    }

    /**
     * Cart item price (single unit price)
     */
    public static function cart_item_price($price_html, $cart_item, $cart_item_key)
    {
        if (!DualCurrency::is_enabled() || empty($price_html)) {
            return $price_html;
        }

        // Don't double-wrap
        if (strpos($price_html, 'eur-equivalent') !== false || strpos($price_html, 'dual-currency') !== false) {
            return $price_html;
        }

        if (!isset($cart_item['data']) || !is_object($cart_item['data'])) {
            return $price_html;
        }

        $product = $cart_item['data'];
        $price = (float) $product->get_price();

        if ($price <= 0) {
            return $price_html;
        }

        // Check if item has a discount
        $regular_price = (float) $product->get_regular_price();

        if ($regular_price > 0 && $regular_price > $price) {
            // Has discount
            return DualCurrency::format_discounted_dual_price($regular_price, $price);
        } else {
            // No discount - add EUR in parentheses
            $eur_amount = DualCurrency::bgn_to_eur($price);
            $eur_formatted = DualCurrency::format_eur_price($eur_amount);
            return $price_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
        }
    }

    /**
     * Cart item subtotal (unit price × quantity)
     */
    public static function cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key)
    {
        if (!DualCurrency::is_enabled() || empty($subtotal_html)) {
            return $subtotal_html;
        }

        // Don't double-wrap
        if (strpos($subtotal_html, 'eur-equivalent') !== false || strpos($subtotal_html, 'dual-currency') !== false) {
            return $subtotal_html;
        }

        if (!isset($cart_item['line_total']) || !isset($cart_item['line_subtotal'])) {
            return $subtotal_html;
        }

        $line_total = (float) $cart_item['line_total'];
        $line_subtotal = (float) $cart_item['line_subtotal']; // Before discount

        if ($line_total <= 0) {
            return $subtotal_html;
        }

        // Has discount?
        if ($line_subtotal > 0 && $line_subtotal > $line_total) {
            return DualCurrency::format_discounted_dual_price($line_subtotal, $line_total);
        } else {
            $eur_amount = DualCurrency::bgn_to_eur($line_total);
            $eur_formatted = DualCurrency::format_eur_price($eur_amount);
            return $subtotal_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
        }
    }

    /**
     * Cart totals (subtotal, total, etc.)
     */
    public static function cart_total_html($subtotal_html, $compound, $cart) 
    {
        if (!DualCurrency::is_enabled() || empty($subtotal_html)) {
            return $subtotal_html;
        }

        // Don't double-wrap
        if (strpos($subtotal_html, 'eur-equivalent') !== false || strpos($subtotal_html, 'dual-currency') !== false) {
            return $subtotal_html;
        }

        if (!is_object($cart) || !method_exists($cart, 'get_subtotal')) {
            return $subtotal_html;
        }

        $amount = (float) $cart->get_subtotal();

        if ($amount <= 0) {
            return $subtotal_html;
        }

        $eur_amount = DualCurrency::bgn_to_eur($amount);
        $eur_formatted = DualCurrency::format_eur_price($eur_amount);

        return $subtotal_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
    }

    /**
     * Simple total HTML (used for cart total)
     */
    public static function simple_total_html($total_html) 
    {
        if (!DualCurrency::is_enabled() || empty($total_html)) {
            return $total_html;
        }

        // Don't double-wrap
        if (strpos($total_html, 'eur-equivalent') !== false || strpos($total_html, 'dual-currency') !== false) {
            return $total_html;
        }

        if (!WC() || !WC()->cart) {
            return $total_html;
        }

        $total = WC()->cart->get_total('');

        if ($total <= 0) {
            return $total_html;
        }

        $eur_amount = DualCurrency::bgn_to_eur((float) $total);
        $eur_formatted = DualCurrency::format_eur_price($eur_amount);

        return $total_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
    }

    /**
     * Order line item subtotal (order received, my account)
     */
    public static function order_line_subtotal($subtotal_html, $item, $order) 
    {
        if (!DualCurrency::is_enabled() || empty($subtotal_html)) {
            return $subtotal_html;
        }

        // Don't double-wrap
        if (strpos($subtotal_html, 'eur-equivalent') !== false || strpos($subtotal_html, 'dual-currency') !== false) {
            return $subtotal_html;
        }

        if (!is_object($order) || !method_exists($order, 'get_line_total')) {
            return $subtotal_html;
        }

        $line_total = (float) $order->get_line_total($item);
        $line_subtotal = (float) $order->get_line_subtotal($item);

        if ($line_total <= 0) {
            return $subtotal_html;
        }

        // Has discount?
        if ($line_subtotal > 0 && $line_subtotal > $line_total) {
            return DualCurrency::format_discounted_dual_price($line_subtotal, $line_total);
        } else {
            $eur_amount = DualCurrency::bgn_to_eur($line_total);
            $eur_formatted = DualCurrency::format_eur_price($eur_amount);
            return $subtotal_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
        }
    }

    /**
     * Order total (order received, my account)
     */
    public static function order_total_html($total_html, $order)
    {
        if (!DualCurrency::is_enabled() || empty($total_html)) {
            return $total_html;
        }

        // Don't double-wrap
        if (strpos($total_html, 'eur-equivalent') !== false || strpos($total_html, 'dual-currency') !== false) {
            return $total_html;
        }

        if (!is_object($order) || !method_exists($order, 'get_total')) {
            return $total_html;
        }

        $total = (float) $order->get_total();

        if ($total <= 0) {
            return $total_html;
        }

        $eur_amount = DualCurrency::bgn_to_eur($total);
        $eur_formatted = DualCurrency::format_eur_price($eur_amount);

        return $total_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
    }

    /**
     * Email order item quantity (we'll add price here)
     */
    public static function email_order_item_quantity($qty_display, $item) 
    {
        // This filter gives us a place to inject, but we'll mainly rely on CSS
        return $qty_display;
    }

    /**
     * After order table in emails - add EUR totals note
     */
    public static function email_after_order_table($order, $sent_to_admin, $plain_text, $email) 
    {
        if (!DualCurrency::is_enabled()) {
            return;
        }

        // Only add for customer emails
        if ($sent_to_admin) {
            return;
        }

        $order_total = (float) $order->get_total();
        $eur_total = DualCurrency::bgn_to_eur($order_total);
        $eur_formatted = DualCurrency::format_eur_price($eur_total);

        if ($plain_text) {
            echo "\n" . sprintf(
                esc_html__('Order total in EUR: %s', 'adp-custom'),
                $eur_formatted
            ) . "\n";
        } else {
            echo '<p style="margin-top: 20px; font-size: 14px; color: #666;">';
            echo sprintf(
                esc_html__('Order total in EUR: %s', 'adp-custom'),
                '<strong>' . esc_html($eur_formatted) . '</strong>'
            );
            echo '</p>';
        }
    }

    /**
     * Simple price HTML - adds EUR in parentheses to any price
     * Used for savings, discounts, mini-cart totals, etc.
     */
    public static function simple_price_html($price_html) 
    {
        if (!DualCurrency::is_enabled() || empty($price_html)) {
            return $price_html;
        }

        // Don't double-wrap
        if (strpos($price_html, 'eur-equivalent') !== false || strpos($price_html, 'dual-currency') !== false) {
            return $price_html;
        }

        // Extract numeric value from HTML using regex
        // Match patterns like: "10,95 лв." or "10.95 лв." or just numbers
        if (preg_match('/([0-9]+[,.]?[0-9]*)\s*(?:лв\.|BGN)?/', strip_tags($price_html), $matches)) {
            $amount_str = str_replace(',', '.', $matches[1]);
            $amount = (float) $amount_str;

            if ($amount > 0) {
                $eur_amount = DualCurrency::bgn_to_eur($amount);
                $eur_formatted = DualCurrency::format_eur_price($eur_amount);
                return $price_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
            }
        }

        return $price_html;
    }

    /**
     * Cart discount/coupon HTML
     */
    public static function cart_discount_html($coupon_html, $coupon, $discount_amount_html)
    {
        if (!DualCurrency::is_enabled() || empty($coupon_html)) {
            return $coupon_html;
        }

        // Don't double-wrap
        if (strpos($coupon_html, 'eur-equivalent') !== false) {
            return $coupon_html;
        }

        // Get the discount amount
        if (is_object($coupon) && method_exists($coupon, 'get_amount')) {
            $amount = (float) $coupon->get_amount();
            if ($amount > 0) {
                $eur_amount = DualCurrency::bgn_to_eur($amount);
                $eur_formatted = DualCurrency::format_eur_price($eur_amount);
                return $coupon_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
            }
        }

        return $coupon_html;
    }

    /**
     * Start output buffering for side cart buttons
     */
    public static function start_button_buffer() 
    {
        ob_start();
    }

    /**
     * End output buffering and modify button HTML to add EUR
     */
    public static function end_button_buffer() 
    {
        $buttons_html = ob_get_clean();

        // Always echo something to prevent empty output
        if (empty($buttons_html)) {
            echo '';
            return;
        }

        // If dual currency is disabled, just output as-is
        if (!DualCurrency::is_enabled()) {
            echo $buttons_html;
            return;
        }

        // Method 1: Match and replace within <span class="woocommerce-Price-amount amount">
        $buttons_html = preg_replace_callback(
            '/<span class="woocommerce-Price-amount amount">([^<]+)<span class="woocommerce-Price-currencySymbol">([^<]+)<\/span><\/span>/i',
            function($matches) {
                $full_match = $matches[0];
                $price_text = $matches[1]; // e.g., "200,00&nbsp;"
                $currency_symbol = $matches[2]; // e.g., "лв."

                // Extract number
                $price_clean = str_replace(['&nbsp;', ' ', ','], ['', '', '.'], $price_text);
                $bgn_amount = (float) $price_clean;

                if ($bgn_amount > 0) {
                    $eur_amount = DualCurrency::bgn_to_eur($bgn_amount);
                    $eur_formatted = DualCurrency::format_eur_price($eur_amount);

                    // Return original + EUR in parentheses
                    return $full_match . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
                }

                return $full_match;
            },
            $buttons_html
        );

        // Method 2: Fallback for simple text patterns
        $buttons_html = preg_replace_callback(
            '/([0-9]+[,.]?[0-9]*)\s*&nbsp;\s*(лв\.|BGN)/i',
            function($matches) {
                $full_match = $matches[0];
                $bgn = str_replace(',', '.', $matches[1]);
                $bgn_amount = (float) $bgn;

                // Skip if already has EUR (from Method 1)
                if (strpos($full_match, 'eur-equivalent') !== false) {
                    return $full_match;
                }

                if ($bgn_amount > 0) {
                    $eur_amount = DualCurrency::bgn_to_eur($bgn_amount);
                    $eur_formatted = DualCurrency::format_eur_price($eur_amount);
                    return $full_match . ' (' . $eur_formatted . ')';
                }

                return $full_match;
            },
            $buttons_html
        );

        echo $buttons_html;
    }

    /**
     * Start capturing cart output
     */
    public static function start_cart_capture()
    {
        if (!DualCurrency::is_enabled()) {
            return;
        }

        // Only apply to main cart page, not AJAX requests or side cart
        if (wp_doing_ajax() || !is_cart()) {
            return;
        }

        ob_start();
    }

    /**
     * End capturing cart output and modify prices
     */
    public static function end_cart_capture()
    {
        if (!DualCurrency::is_enabled()) {
            return;
        }

        // Only apply to main cart page, not AJAX requests or side cart
        if (wp_doing_ajax() || !is_cart()) {
            return;
        }

        $cart_html = ob_get_clean();

        if (empty($cart_html)) {
            return;
        }

        // Add EUR prices to product-price cells
        $cart_html = preg_replace_callback(
            '/<td class="product-price"[^>]*>(.*?)<\/td>/s',
            function($matches) {
                $cell_content = $matches[1];
                return '<td class="product-price" data-title="Цена">' . self::add_eur_to_price_cell($cell_content) . '</td>';
            },
            $cart_html
        );

        // Add EUR prices to product-subtotal cells
        $cart_html = preg_replace_callback(
            '/<td class="product-subtotal"[^>]*>(.*?)<\/td>/s',
            function($matches) {
                $cell_content = $matches[1];
                return '<td class="product-subtotal" data-title="Общо">' . self::add_eur_to_price_cell($cell_content) . '</td>';
            },
            $cart_html
        );

        echo $cart_html;
    }

    /**
     * Add EUR equivalent to a price cell's HTML
     */
    private static function add_eur_to_price_cell($html)
    {
        // Pattern 1: Sale price with del/ins tags
        if (preg_match('/<del[^>]*>.*?(\d+[,.]\d+).*?<\/del>.*?<ins[^>]*>.*?(\d+[,.]\d+).*?<\/ins>/s', $html, $matches)) {
            $regular_bgn = (float) str_replace(',', '.', $matches[1]);
            $sale_bgn = (float) str_replace(',', '.', $matches[2]);

            $regular_eur = DualCurrency::bgn_to_eur($regular_bgn);
            $sale_eur = DualCurrency::bgn_to_eur($sale_bgn);

            $regular_eur_formatted = DualCurrency::format_eur_price($regular_eur);
            $sale_eur_formatted = DualCurrency::format_eur_price($sale_eur);

            // Add EUR line after the existing price HTML
            $html .= '<br><small class="eur-price"><del>' . esc_html($regular_eur_formatted) . '</del> <ins>' . esc_html($sale_eur_formatted) . '</ins></small>';

            return $html;
        }

        // Pattern 2: Regular price (no discount)
        if (preg_match('/(\d+[,.]\d+)\s*(?:&nbsp;)?(?:<span[^>]*>)?(?:&#1083;&#1074;|лв)/s', $html, $matches)) {
            $bgn = (float) str_replace(',', '.', $matches[1]);
            $eur = DualCurrency::bgn_to_eur($bgn);
            $eur_formatted = DualCurrency::format_eur_price($eur);

            // Add EUR in parentheses after the existing price
            $html .= ' <small class="eur-price">(' . esc_html($eur_formatted) . ')</small>';

            return $html;
        }

        return $html;
    }
}
