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

        $path = plugin_dir_path(__FILE__) . '../style.css';
        $url  = plugins_url('../style.css', __FILE__);
        $ver  = file_exists($path) ? (string) filemtime($path) : '1.0.0';

        wp_enqueue_style('milliart-adp-addon', $url, [], $ver);
    }
}