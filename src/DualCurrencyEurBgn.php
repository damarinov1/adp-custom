<?php
/**
 * Dual Currency Display (EUR + BGN)
 *
 * Handles currency conversion and display for EUR showing BGN equivalent
 */

namespace AdpCustom;

if (!defined('ADP_CUSTOM_DUAL_CURRENCY_EUR_BGN_ENABLED')) {
    define('ADP_CUSTOM_DUAL_CURRENCY_EUR_BGN_ENABLED', true);
}
if (!defined('ADP_CUSTOM_FIXED_BGN_RATE')) {
    define('ADP_CUSTOM_FIXED_BGN_RATE', 1.95583);
}

class DualCurrencyEurBgn {

    public static function is_enabled(): bool
    {
        // Don't cache this result as currency/language can change during the session
        return ADP_CUSTOM_DUAL_CURRENCY_EUR_BGN_ENABLED
            && self::is_eur_active()
            && LanguageHelper::is_bulgarian_language();
    }

    public static function is_eur_active(): bool
    {
        $currency = get_woocommerce_currency();
        return $currency === 'EUR';
    }

    /**
     * Convert EUR to BGN
     *
     * @param float $eur_amount Amount in EUR
     * @return float Amount in BGN
     */
    public static function eur_to_bgn(float $eur_amount): float
    {
        return $eur_amount * ADP_CUSTOM_FIXED_BGN_RATE;
    }

    /**
     * Get BGN currency symbol
     */
    public static function get_bgn_symbol(): string
    {
        if (function_exists('get_woocommerce_currency_symbol')) {
            return get_woocommerce_currency_symbol('BGN');
        }

        return 'лв.';
    }

    /**
     * Format price in BGN
     *
     * @param float $bgn_amount Amount in BGN
     * @return string Formatted price
     */
    public static function format_bgn_price(float $bgn_amount): string
    {
        $symbol = self::get_bgn_symbol();
        $decimals = wc_get_price_decimals();
        $decimal_sep = wc_get_price_decimal_separator();
        $thousand_sep = wc_get_price_thousand_separator();
        $currency_pos = get_option('woocommerce_currency_pos', 'left');

        $formatted_amount = number_format($bgn_amount, $decimals, $decimal_sep, $thousand_sep);

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
     * Add BGN equivalent to regular price HTML
     *
     * @param string $price_html Original price HTML in EUR
     * @param float $eur_amount Amount in EUR
     * @return string Price with BGN in parentheses
     */
    public static function add_bgn_to_regular_price(string $price_html, float $eur_amount): string
    {
        if (!self::is_enabled() || $eur_amount <= 0) {
            return $price_html;
        }

        $bgn_amount = self::eur_to_bgn($eur_amount);
        $bgn_formatted = self::format_bgn_price($bgn_amount);

        return $price_html . ' <span class="bgn-equivalent">(' . esc_html($bgn_formatted) . ')</span>';
    }

    /**
     * Generate dual currency HTML for discounted prices (stacked)
     *
     * @param float $regular_eur Regular price in EUR
     * @param float $sale_eur Sale price in EUR
     * @param string $suffix Price suffix (e.g., " incl. VAT")
     * @param string $prefix Price prefix (e.g., "From")
     * @return string Complete dual currency HTML
     */
    public static function format_discounted_dual_price(
        float $regular_eur,
        float $sale_eur,
        string $suffix = '',
        string $prefix = ''
    ): string {
        if (!self::is_enabled()) {
            // Return single currency format
            $eur_html = '<del class="price__old">' . wc_price($regular_eur) . $suffix . '</del> ' .
                       '<ins class="price__new">' . wc_price($sale_eur) . $suffix . '</ins>';
            return '<span class="price">' . ($prefix ? $prefix . ' ' : '') . $eur_html . '</span>';
        }

        // Convert to BGN
        $regular_bgn = self::eur_to_bgn($regular_eur);
        $sale_bgn = self::eur_to_bgn($sale_eur);

        // Format BGN prices
        $regular_bgn_formatted = self::format_bgn_price($regular_bgn);
        $sale_bgn_formatted = self::format_bgn_price($sale_bgn);

        // Build EUR line (primary currency)
        $eur_html = '<del class="price__old">' . wc_price($regular_eur) . $suffix . '</del> ' .
                   '<ins class="price__new">' . wc_price($sale_eur) . $suffix . '</ins>';

        // Build BGN line (secondary currency)
        $bgn_html = '<del class="price__old-bgn">' . esc_html($regular_bgn_formatted) . '</del> ' .
                   '<ins class="price__new-bgn">' . esc_html($sale_bgn_formatted) . '</ins>';

        // Combine with line break (EUR on top, BGN below)
        $output = '<span class="price dual-currency">';
        if ($prefix) {
            $output .= '<span class="price-prefix">' . esc_html($prefix) . '</span> ';
        }
        $output .= '<span class="price-eur">' . $eur_html . '</span>';
        $output .= '<span class="price-bgn">' . $bgn_html . '</span>';
        $output .= '</span>';

        return $output;
    }

    /**
     * Generate dual currency HTML for regular (non-discounted) prices with option for prefix
     *
     * @param float $price_eur Price in EUR
     * @param string $suffix Price suffix
     * @param string $prefix Price prefix (e.g., "From")
     * @return string Complete dual currency HTML
     */
    public static function format_regular_dual_price(
        float $price_eur,
        string $suffix = '',
        string $prefix = ''
    ): string {
        if (!self::is_enabled()) {
            // Return single currency format
            return '<span class="price">' .
                   ($prefix ? $prefix . ' ' : '') .
                   wc_price($price_eur) . $suffix .
                   '</span>';
        }

        $bgn_amount = self::eur_to_bgn($price_eur);
        $bgn_formatted = self::format_bgn_price($bgn_amount);

        $output = '<span class="price">';
        if ($prefix) {
            $output .= '<span class="price-prefix">' . esc_html($prefix) . '</span> ';
        }
        $output .= wc_price($price_eur) . $suffix . ' ';
        $output .= '<span class="bgn-equivalent">(' . esc_html($bgn_formatted) . ')</span>';
        $output .= '</span>';

        return $output;
    }
}
