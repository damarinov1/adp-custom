<?php
/**
 * Loop Price (customized for ADP discounted prices)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

global $product;
if ( ! $product ) { return; }
if ($product->get_price() === '' || $product->get_price() === null) return;

$suffix = $product->get_price_suffix();
$fmt = function( $amount ) use ( $suffix ) {
    return wc_price( (float) $amount ) . $suffix;
};

// Get the first ADP rule title that applies to this product (ADP-aware)
$get_adp_rule_title = function (WC_Product $p): string {
    // Check cache first
    $cache_key = 'adp_rule_title_' . $p->get_id();
    $cached_title = wp_cache_get($cache_key, 'adp_custom');
    if (false !== $cached_title) {
        return $cached_title;
    }

    if (! function_exists('adp_functions')) return '';

    try {
        $pf = adp_functions();
        if (! is_object($pf) || ! method_exists($pf, 'getActiveRulesForProduct')) return '';
    } catch (Exception $e) {
        error_log('ADP Custom: Error getting adp_functions - ' . $e->getMessage());
        return '';
    }

    // Read visibility map from the admin page (default: show = false)
    $visible_map = function_exists('get_option') && class_exists('\AdpCustom\Admin')
        ? (array) get_option(\AdpCustom\Admin::OPTION_VISIBILITY, [])
        : [];

    // Collect rule OBJECTS (de-duped by ID)
    $collect_rules = function (WC_Product $wc_product) use ($pf): array {
        $out = [];
        try {
            $rules = $pf->getActiveRulesForProduct($wc_product, 50, true);
            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if (is_object($rule) && method_exists($rule, 'getId') && method_exists($rule, 'getTitle')) {
                        $out[(string) $rule->getId()] = $rule;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('ADP Custom: Error collecting rules for product ' . $wc_product->get_id() . ' - ' . $e->getMessage());
        }
        return $out;
    };

    // Gather rules across simple/variable
    $rules = [];
    if ($p->is_type('variable')) {
        $var_ids = method_exists($p, 'get_visible_children') ? $p->get_visible_children() : $p->get_children();
        foreach ($var_ids as $vid) {
            if ($v = wc_get_product($vid)) {
                $rules += $collect_rules($v);
            }
        }
    } else {
        $rules += $collect_rules($p);
    }

    if (!$rules) return '';

    // Respect visibility toggle; default to hide when not set
    foreach ($rules as $id => $rule) {
        if (!isset($visible_map[$id]) || !$visible_map[$id]) {
            continue; // hidden in admin or not explicitly enabled
        }
        $title = trim((string) $rule->getTitle());
        if ($title !== '') {
            wp_cache_set($cache_key, $title, 'adp_custom', HOUR_IN_SECONDS);
            return $title;
        }
    }

    $result = '';
    wp_cache_set($cache_key, $result, 'adp_custom', HOUR_IN_SECONDS);

    return $result;
};

// Echo price HTML + (optional) ADP rule title under it
$render_price = function (string $price_html) use ($product, $get_adp_rule_title) {
    echo $price_html;
    $title = $get_adp_rule_title($product);
    if ($title !== '') {
        $context = 'adp-custom';
        $name    = 'rule_title_' . md5($title);
        do_action('wpml_register_single_string', $context, $name, $title);
        $translated = apply_filters('wpml_translate_single_string', $title, $context, $name);

        echo '<div class="discount-title">' . esc_html($translated) . '</div>';
    }
};

// Cache ADP function checks to avoid repeated function_exists calls
static $adp_available = null;
if ($adp_available === null) {
    $adp_available = function_exists('adp_functions');
}

/**
 * Try to get discounted price/range from
 * Advanced Dynamic Pricing for WooCommerce (AlgolPlus).
 *
 * getDiscountedProductPrice($product, $qty = 1, $for_display = true)
 * - variable: returns [min, max]
 * - simple:   returns float
 */
$adp_min = $adp_max = null;
if ( $adp_available ) {
    try {
        $pf = adp_functions();
        if ( is_object( $pf ) && method_exists( $pf, 'getDiscountedProductPrice' ) ) {
            $res = $pf->getDiscountedProductPrice( $product, 1, true );
            if ( is_array( $res ) ) {
                $adp_min = isset( $res[0] ) ? (float) $res[0] : null;
                $adp_max = isset( $res[1] ) ? (float) $res[1] : null;
            } elseif ( is_numeric( $res ) ) {
                $adp_min = $adp_max = (float) $res;
            }
        }
    } catch (Exception $e) {
        error_log('ADP Custom: Error getting discounted price for product ' . $product->get_id() . ' - ' . $e->getMessage());
    }
}

if ( $product->is_type( 'variable' ) ) {
    $min_regular = (float) $product->get_variation_regular_price( 'min', true );
    $max_regular = (float) $product->get_variation_regular_price( 'max', true );

    // Use ADP range if available, else WooCommerce active prices
    $min_active = $adp_min !== null ? $adp_min : (float) $product->get_variation_price( 'min', true );
    $max_active = $adp_max !== null ? $adp_max : (float) $product->get_variation_price( 'max', true );

    $prefix = esc_html__( 'From', 'adp-custom' );

    // Single price
    if ( $min_active === $max_active ) {
        if ( $min_regular > $min_active ) {
            $render_price(
                '<span class="price"><del class="price__old">' . $fmt($min_regular) . '</del> <ins class="price__new">' . $fmt($min_active) . '</ins></span>'
            );
        } else {
            $render_price(
                '<span class="price"><span class="lowest-price">' . $fmt($min_active) . '</span></span>'
            );
        }
    } else {
        // Range display (show "From" and strike regular if discounted)
        if ( $min_regular > $min_active ) {
            $render_price(
                '<span class="price">' . $prefix . ' ' .
                '<del class="price__from-old">' . $fmt($min_regular) . '</del> ' .
                '<ins class="price__from-new">' . $fmt($min_active) . '</ins>' .
                '</span>'
            );
        } else {
            $render_price(
                '<span class="price"><span class="lowest-price">' . $prefix . ' ' . $fmt($min_active) . '</span></span>'
            );
        }
    }
} else {
    // Simple / other product types
    $regular_raw = (float) $product->get_regular_price();
    $regular     = wc_get_price_to_display( $product, [ 'price' => $regular_raw ] );
    $active      = $adp_min !== null ? $adp_min : wc_get_price_to_display( $product );

    if ( $regular > 0 && $active < $regular ) {
        $render_price(
            '<span class="price"><del class="price__old">' . $fmt($regular) . '</del> <ins class="price__new">' . $fmt($active) . '</ins></span>'
        );
    } else {
        $render_price('<span class="price">' . $fmt($active) . '</span>');
    }
}
