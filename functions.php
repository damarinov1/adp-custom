<?php
/**
 * Plugin Name: ADP Custom Addos
 * Description: Extension of Advanced Dynamic Pricing for Woocommerce
 * Version: 1.1.0
 * Author: Denis Marinov
 * Text Domain: adp-custom
 * Requires Plugins: advanced-dynamic-pricing-for-woocommerce
 */
 
if ( ! defined('ABSPATH') ) exit;

require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__.'/src/Admin.php';

register_activation_hook(__FILE__, function () {
    add_option(\AdpCustom\Admin::OPTION_VISIBILITY, [], false);
    add_option(\AdpCustom\Admin::OPTION_CACHE, [], false);
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce') || !class_exists('ADP\\Factory')) {
        return;
    }
    
    require_once __DIR__ . '/src/frontend-hooks.php';

    (new \AdpCustom\Plugin())->boot();
    (new \AdpCustom\Admin())->boot();
});

add_filter('woocommerce_locate_template', function ($located, $template_name, $template_path) {
    if ($template_name === 'loop/price.php') {
        $plugin_template = __DIR__ . '/templates/woocommerce/loop/price.php';
        
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    return $located;
}, 999, 3);
