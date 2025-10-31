<?php

// % OFF badge on archive thumbnails (ADP-aware)
add_action('woocommerce_before_shop_loop_item_title', function () {
    global $product;
    if ( ! $product ) return;
    if ($product->get_price() === '' || $product->get_price() === null) return;

    // Cache function_exists check
    static $adp_available = null;
    if ($adp_available === null) {
        $adp_available = function_exists('adp_functions');
    }

    // Helper: return [regular_display, active_display] for this product
    $get_pair = function (WC_Product $p) use ($adp_available): ?array {
        // ADP path
        if ( $adp_available && ($pf = adp_functions()) && method_exists($pf, 'getDiscountedProductPrice') ) {
            $res = $pf->getDiscountedProductPrice($p, 1, true);
            if ( is_array($res) ) { // variable: [min_active, max_active]
                $min_active  = isset($res[0]) ? (float) $res[0] : 0.0;
                $min_regular = (float) $p->get_variation_regular_price('min', true);
                return [$min_regular, $min_active];
            }
            if ( is_numeric($res) ) { // simple
                $active  = (float) $res;
                $regular = wc_get_price_to_display($p, ['price' => (float) $p->get_regular_price()]);
                return [$regular, $active];
            }
        }

        // Fallback (native Woo)
        if ( $p->is_type('variable') ) {
            return [
                (float) $p->get_variation_regular_price('min', true),
                (float) $p->get_variation_price('min', true),
            ];
        }
        return [
            wc_get_price_to_display($p, ['price' => (float) $p->get_regular_price()]),
            wc_get_price_to_display($p), // active price
        ];
    };

    [$regular, $active] = $get_pair($product);
    if ($regular <= 0 || $active >= $regular) return;

    $off = (int) round( (($regular - $active) / $regular) * 100 );

    echo '<span class="onsale" aria-label="' . esc_attr__('Discount','woocommerce') . '">-'
      . esc_html($off) . '%</span>';
}, 9);
