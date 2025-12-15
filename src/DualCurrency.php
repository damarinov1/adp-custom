<?php
/**
 * Dual Currency Display (BGN + EUR)
 *
 * Handles currency conversion and display for Bulgaria's EUR transition
 */

namespace AdpCustom;

if (!defined('ADP_CUSTOM_DUAL_CURRENCY_ENABLED')) {
    define('ADP_CUSTOM_DUAL_CURRENCY_ENABLED', true);
}
if (!defined('ADP_CUSTOM_USE_MULTICURRENCY_RATE')) {
    define('ADP_CUSTOM_USE_MULTICURRENCY_RATE', true);
}
if (!defined('ADP_CUSTOM_FIXED_EUR_RATE')) {
    define('ADP_CUSTOM_FIXED_EUR_RATE', 1.95583);
}

class DualCurrency {

    public static function is_enabled(): bool
    {
        // Don't cache this result as currency can change during the session
        return ADP_CUSTOM_DUAL_CURRENCY_ENABLED && self::is_bgn_active();
    }

    public static function is_bgn_active(): bool 
    {
        $currency = get_woocommerce_currency();
        return $currency === 'BGN';
    }

    /**
     * Get EUR exchange rate (BGN to EUR)
     *
     * @return float Exchange rate (BGN to EUR)
     */
    public static function get_exchange_rate(): float
    {
        if (ADP_CUSTOM_USE_MULTICURRENCY_RATE) {
            $rate = self::get_multicurrency_rate();
            if ($rate !== null) {
                return $rate;
            }
        }

        return ADP_CUSTOM_FIXED_EUR_RATE;
    }

    /**
     * Try to get exchange rate from WooCommerce Multilingual / WCML
     */
    private static function get_multicurrency_rate(): ?float 
    {
        // Check for WCML (WooCommerce Multilingual)
        if (class_exists('woocommerce_wpml') && function_exists('wcml_get_woocommerce_currency_option')) {
            global $woocommerce_wpml;

            if (isset($woocommerce_wpml->multi_currency)) {
                $currencies = $woocommerce_wpml->multi_currency->get_currencies();

                // Look for EUR currency
                if (isset($currencies['EUR']) && isset($currencies['EUR']['rate'])) {
                    $rate = (float) $currencies['EUR']['rate'];
                    if ($rate > 0) {
                        return $rate;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Convert BGN to EUR
     *
     * @param float $bgn_amount Amount in BGN
     * @return float Amount in EUR
     */
    public static function bgn_to_eur(float $bgn_amount): float 
    {
        $rate = self::get_exchange_rate();

        if ($rate >= 1) {
            // Fixed rate format: 1.95583 BGN = 1 EUR
            return $bgn_amount / $rate;
        } else {
            // Multicurrency format: 1 BGN = 0.51 EUR
            return $bgn_amount * $rate;
        }
    }

    /**
     * Get EUR currency symbol
     */
    public static function get_eur_symbol(): string 
    {
        if (function_exists('get_woocommerce_currency_symbol')) {
            return get_woocommerce_currency_symbol('EUR');
        }

        return '€';
    }

    /**
     * Format price in EUR
     *
     * @param float $eur_amount Amount in EUR
     * @return string Formatted price
     */
    public static function format_eur_price(float $eur_amount): string
    {
        $symbol = self::get_eur_symbol();
        $decimals = wc_get_price_decimals();
        $decimal_sep = wc_get_price_decimal_separator();
        $thousand_sep = wc_get_price_thousand_separator();
        $currency_pos = get_option('woocommerce_currency_pos', 'left');

        $formatted_amount = number_format($eur_amount, $decimals, $decimal_sep, $thousand_sep);

        switch ($currency_pos) {
            case 'left':
                return $symbol . $formatted_amount;
            case 'right':
                return $formatted_amount . $symbol;
            case 'left_space':
                return $symbol . ' ' . $formatted_amount;
            case 'right_space':
                return $formatted_amount . ' ' . $symbol;
            default:
                return $symbol . $formatted_amount;
        }
    }

    /**
     * Add EUR equivalent to regular price HTML
     *
     * @param string $price_html Original price HTML in BGN
     * @param float $bgn_amount Amount in BGN
     * @return string Price with EUR in parentheses
     */
    public static function add_eur_to_regular_price(string $price_html, float $bgn_amount): string 
    {
        if (!self::is_enabled() || $bgn_amount <= 0) {
            return $price_html;
        }

        $eur_amount = self::bgn_to_eur($bgn_amount);
        $eur_formatted = self::format_eur_price($eur_amount);

        return $price_html . ' <span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
    }

    /**
     * Generate dual currency HTML for discounted prices (Option B: stacked)
     *
     * @param float $regular_bgn Regular price in BGN
     * @param float $sale_bgn Sale price in BGN
     * @param string $suffix Price suffix (e.g., " incl. VAT")
     * @param string $prefix Price prefix (e.g., "From")
     * @return string Complete dual currency HTML
     */
    public static function format_discounted_dual_price(
        float $regular_bgn,
        float $sale_bgn,
        string $suffix = '',
        string $prefix = ''
    ): string {
        if (!self::is_enabled()) {
            // Return single currency format
            $bgn_html = '<del class="price__old">' . wc_price($regular_bgn) . $suffix . '</del> ' .
                       '<ins class="price__new">' . wc_price($sale_bgn) . $suffix . '</ins>';
            return '<span class="price">' . ($prefix ? $prefix . ' ' : '') . $bgn_html . '</span>';
        }

        // Convert to EUR
        $regular_eur = self::bgn_to_eur($regular_bgn);
        $sale_eur = self::bgn_to_eur($sale_bgn);

        // Format EUR prices
        $regular_eur_formatted = self::format_eur_price($regular_eur);
        $sale_eur_formatted = self::format_eur_price($sale_eur);

        // Build BGN line
        $bgn_html = '<del class="price__old">' . wc_price($regular_bgn) . $suffix . '</del> ' .
                   '<ins class="price__new">' . wc_price($sale_bgn) . $suffix . '</ins>';

        // Build EUR line
        $eur_html = '<del class="price__old-eur">' . esc_html($regular_eur_formatted) . '</del> ' .
                   '<ins class="price__new-eur">' . esc_html($sale_eur_formatted) . '</ins>';

        // Combine with line break
        $output = '<span class="price dual-currency">';
        if ($prefix) {
            $output .= '<span class="price-prefix">' . esc_html($prefix) . '</span> ';
        }
        $output .= '<span class="price-bgn">' . $bgn_html . '</span>';
        $output .= '<span class="price-eur">' . $eur_html . '</span>';
        $output .= '</span>';

        return $output;
    }

    /**
     * Generate dual currency HTML for regular (non-discounted) prices with option for prefix
     *
     * @param float $price_bgn Price in BGN
     * @param string $suffix Price suffix
     * @param string $prefix Price prefix (e.g., "From")
     * @return string Complete dual currency HTML
     */
    public static function format_regular_dual_price(
        float $price_bgn,
        string $suffix = '',
        string $prefix = ''
    ): string {
        if (!self::is_enabled()) {
            // Return single currency format
            return '<span class="price">' .
                   ($prefix ? $prefix . ' ' : '') .
                   wc_price($price_bgn) . $suffix .
                   '</span>';
        }

        $eur_amount = self::bgn_to_eur($price_bgn);
        $eur_formatted = self::format_eur_price($eur_amount);

        $output = '<span class="price">';
        if ($prefix) {
            $output .= '<span class="price-prefix">' . esc_html($prefix) . '</span> ';
        }
        $output .= wc_price($price_bgn) . $suffix . ' ';
        $output .= '<span class="eur-equivalent">(' . esc_html($eur_formatted) . ')</span>';
        $output .= '</span>';

        return $output;
    }
}
