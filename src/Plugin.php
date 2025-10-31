<?php

namespace AdpCustom;

class Plugin 
{
    public function boot(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueCss'], 99);
    }
    
    public function enqueueCss(): void
    {
        if (!function_exists('is_woocommerce') || (!is_woocommerce() && !is_cart() && !is_checkout())) {
            return;
        }

        $url = plugins_url('../style.css', __FILE__);
        // Use plugin version from main file instead of filemtime for better performance
        $ver = '2.0.0';

        wp_enqueue_style('milliart-adp-addon', $url, [], $ver);
    }
}