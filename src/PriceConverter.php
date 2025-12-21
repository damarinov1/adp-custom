<?php
/**
 * Price Converter: BGN to EUR
 *
 * Bulk converts all WooCommerce product prices from BGN to EUR
 * using the official exchange rate (1 EUR = 1.95583 BGN)
 *
 * Features:
 * - AJAX-based chunk processing (20 products per batch)
 * - Real-time progress bar
 * - Background queue processing
 * - Custom logging to adp-custom/logs/
 */

namespace AdpCustom;

class PriceConverter {

    const CHUNK_SIZE = 20; // Products per batch
    const LOG_FILE = 'price-converter.log';

    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Convert Prices BGN → EUR',
            'Price Converter',
            'manage_woocommerce',
            'adp-price-converter',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Initialize AJAX hooks
     */
    public static function init() {
        add_action('wp_ajax_adp_start_conversion', [__CLASS__, 'ajax_start_conversion']);
        add_action('wp_ajax_adp_process_chunk', [__CLASS__, 'ajax_process_chunk']);
        add_action('wp_ajax_adp_get_progress', [__CLASS__, 'ajax_get_progress']);
        add_action('wp_ajax_adp_clear_log', [__CLASS__, 'ajax_clear_log']);
    }

    /**
     * Log message to custom log file
     */
    private static function log($message) {
        $log_dir = plugin_dir_path(dirname(__FILE__)) . 'logs';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        $log_file = $log_dir . '/' . self::LOG_FILE;
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";

        file_put_contents($log_file, $log_message, FILE_APPEND);
    }

    /**
     * Get log contents
     */
    private static function get_log() {
        $log_file = plugin_dir_path(dirname(__FILE__)) . 'logs/' . self::LOG_FILE;
        if (file_exists($log_file)) {
            return file_get_contents($log_file);
        }
        return '';
    }

    /**
     * Clear log file
     */
    private static function clear_log() {
        $log_file = plugin_dir_path(dirname(__FILE__)) . 'logs/' . self::LOG_FILE;
        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }

    /**
     * AJAX: Clear log file
     */
    public static function ajax_clear_log() {
        check_ajax_referer('adp_price_converter', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        self::clear_log();
        wp_send_json_success();
    }

    /**
     * AJAX: Start conversion process
     */
    public static function ajax_start_conversion() {
        check_ajax_referer('adp_price_converter', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        self::clear_log();
        self::log('=== PRICE CONVERSION STARTED ===');
        self::log('Rate: 1 EUR = 1.95583 BGN');

        // Get all active languages from WPML
        $active_languages = apply_filters('wpml_active_languages', null);
        $languages = [];

        if ($active_languages && is_array($active_languages)) {
            foreach ($active_languages as $lang_code => $lang_info) {
                $languages[] = $lang_code;
            }
            self::log('WPML detected. Languages: ' . implode(', ', $languages));
        } else {
            $languages = ['default'];
            self::log('No WPML detected. Processing default language.');
        }

        // Initialize progress
        set_transient('adp_conversion_progress', [
            'status' => 'running',
            'current_language' => $languages[0],
            'languages' => $languages,
            'language_index' => 0,
            'total_languages' => count($languages),
            'offset' => 0,
            'processed' => 0,
            'total' => 0,
            'start_time' => time()
        ], HOUR_IN_SECONDS);

        wp_send_json_success([
            'languages' => $languages,
            'message' => 'Conversion started'
        ]);
    }

    /**
     * AJAX: Process chunk of products
     */
    public static function ajax_process_chunk() {
        check_ajax_referer('adp_price_converter', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $progress = get_transient('adp_conversion_progress');
        if (!$progress || $progress['status'] !== 'running') {
            wp_send_json_error('No active conversion');
        }

        $rate = 1.95583;
        $current_lang = $progress['current_language'];
        $offset = $progress['offset'];

        // Switch to current language if WPML
        if ($current_lang !== 'default') {
            do_action('wpml_switch_language', $current_lang);
        }

        // Get chunk of products
        $products = wc_get_products([
            'limit' => self::CHUNK_SIZE,
            'offset' => $offset,
            'status' => 'publish',
            'orderby' => 'ID',
            'order' => 'ASC'
        ]);

        // Get total if first chunk
        if ($offset === 0) {
            $total = wc_get_products([
                'return' => 'ids',
                'limit' => -1,
                'status' => 'publish'
            ]);
            $progress['total'] = count($total);
            self::log("Language '{$current_lang}': Found " . $progress['total'] . " products");
        }

        $chunk_processed = 0;

        foreach ($products as $product) {
            $updated = false;
            $product_id = $product->get_id();

            // Simple product prices
            $regular = $product->get_regular_price();
            $sale = $product->get_sale_price();

            if ($regular && $regular > 0) {
                $new_regular = round($regular / $rate, 2);
                $product->set_regular_price($new_regular);
                $updated = true;
            }

            if ($sale && $sale > 0) {
                $new_sale = round($sale / $rate, 2);
                $product->set_sale_price($new_sale);
                $updated = true;
            }

            // Variable product - update variations
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $var_regular = $variation->get_regular_price();
                        $var_sale = $variation->get_sale_price();

                        if ($var_regular && $var_regular > 0) {
                            $variation->set_regular_price(round($var_regular / $rate, 2));
                            $updated = true;
                        }

                        if ($var_sale && $var_sale > 0) {
                            $variation->set_sale_price(round($var_sale / $rate, 2));
                        }

                        $variation->save();
                    }
                }
            }

            if ($updated) {
                $product->save();
                $chunk_processed++;
            }

            unset($product);
        }

        $progress['offset'] += self::CHUNK_SIZE;
        $progress['processed'] += $chunk_processed;

        // Check if current language is done
        if (count($products) < self::CHUNK_SIZE) {
            self::log("Language '{$current_lang}': Completed {$progress['processed']} products");

            // Move to next language
            $progress['language_index']++;

            if ($progress['language_index'] < $progress['total_languages']) {
                $progress['current_language'] = $progress['languages'][$progress['language_index']];
                $progress['offset'] = 0;
                $progress['processed'] = 0;
                $progress['total'] = 0;
                self::log("Switching to language: " . $progress['current_language']);
            } else {
                // All done!
                $progress['status'] = 'completed';
                $elapsed = time() - $progress['start_time'];
                $minutes = floor($elapsed / 60);
                $seconds = $elapsed % 60;
                $time_msg = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";

                self::log("=== CONVERSION COMPLETED IN {$time_msg} ===");

                // Clear WooCommerce caches
                wc_delete_product_transients();
            }
        }

        set_transient('adp_conversion_progress', $progress, HOUR_IN_SECONDS);

        wp_send_json_success([
            'status' => $progress['status'],
            'current_language' => $progress['current_language'],
            'language_index' => $progress['language_index'],
            'total_languages' => $progress['total_languages'],
            'offset' => $progress['offset'],
            'processed' => $progress['processed'],
            'total' => $progress['total'],
            'chunk_processed' => $chunk_processed
        ]);
    }

    /**
     * AJAX: Get current progress
     */
    public static function ajax_get_progress() {
        check_ajax_referer('adp_price_converter', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $progress = get_transient('adp_conversion_progress');

        if (!$progress) {
            wp_send_json_success([
                'status' => 'idle',
                'log' => self::get_log()
            ]);
        } else {
            wp_send_json_success(array_merge($progress, [
                'log' => self::get_log()
            ]));
        }
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $stats = self::get_conversion_stats();
        $nonce = wp_create_nonce('adp_price_converter');
        ?>
        <div class="wrap">
            <h1>💱 Convert Prices: BGN → EUR</h1>

            <div class="notice notice-warning">
                <p><strong>⚠️ Warning:</strong> This will convert ALL product prices from BGN to EUR using the official rate <strong>1 EUR = 1.95583 BGN</strong>.</p>
                <p><strong>Important:</strong> Make sure you have a database backup before proceeding!</p>
            </div>

            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📊 Current Status</h2>
                <table class="widefat">
                    <tr>
                        <td><strong>Total Products:</strong></td>
                        <td><?php echo esc_html($stats['total_products']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Variable Products:</strong></td>
                        <td><?php echo esc_html($stats['variable_products']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Total Variations:</strong></td>
                        <td><?php echo esc_html($stats['total_variations']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Current Currency:</strong></td>
                        <td><strong><?php echo esc_html(get_woocommerce_currency()); ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong>Conversion Rate:</strong></td>
                        <td>1 EUR = 1.95583 BGN</td>
                    </tr>
                    <tr>
                        <td><strong>Chunk Size:</strong></td>
                        <td><?php echo self::CHUNK_SIZE; ?> products per batch</td>
                    </tr>
                </table>
            </div>

            <!-- Progress Bar Section -->
            <div id="adp-progress-container" style="display: none; max-width: 800px; margin-top: 20px;">
                <div class="card">
                    <h2>⚡ Conversion Progress</h2>
                    <div id="adp-progress-status" style="margin-bottom: 15px;">
                        <strong>Status:</strong> <span id="adp-status-text">Starting...</span>
                    </div>
                    <div id="adp-progress-language" style="margin-bottom: 15px;">
                        <strong>Language:</strong> <span id="adp-language-text">-</span>
                        (<span id="adp-language-count">0</span>/<span id="adp-language-total">0</span>)
                    </div>
                    <div style="background: #f0f0f1; height: 30px; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                        <div id="adp-progress-bar" style="background: linear-gradient(90deg, #2271b1, #135e96); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">
                            0%
                        </div>
                    </div>
                    <div id="adp-progress-details" style="font-size: 13px; color: #666;">
                        <span id="adp-processed">0</span> / <span id="adp-total">0</span> products processed
                    </div>
                </div>
            </div>

            <!-- Conversion Controls -->
            <div id="adp-controls" style="margin-top: 30px;">
                <button id="adp-start-btn" class="button button-primary button-large" style="height: 50px; font-size: 16px;">
                    🚀 Start Conversion (BGN → EUR)
                </button>
            </div>

            <!-- Log Viewer -->
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;">📋 Conversion Log</h3>
                    <button id="adp-clear-log-btn" class="button button-small">Clear Log</button>
                </div>
                <pre id="adp-log-viewer" style="background: #1e1e1e; color: #d4d4d4; padding: 15px; max-height: 300px; overflow-y: auto; font-size: 12px; font-family: 'Courier New', monospace; border-radius: 4px;"><?php echo esc_html(self::get_log()); ?></pre>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo $nonce; ?>';
            let isProcessing = false;
            let pollInterval;

            // Start conversion
            $('#adp-start-btn').on('click', function() {
                if (isProcessing) return;

                if (!confirm('⚠️ Are you sure you want to convert ALL prices from BGN to EUR?\n\nThis will affect ALL products and variations across all languages.\n\nMake sure you have a backup!')) {
                    return;
                }

                isProcessing = true;
                $('#adp-start-btn').prop('disabled', true).text('⏳ Processing...');
                $('#adp-progress-container').show();
                $('#adp-status-text').text('Initializing...');

                // Start conversion
                $.post(ajaxurl, {
                    action: 'adp_start_conversion',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        processNextChunk();
                        startProgressPolling();
                    } else {
                        alert('Error: ' + response.data);
                        resetUI();
                    }
                });
            });

            // Process next chunk
            function processNextChunk() {
                $.post(ajaxurl, {
                    action: 'adp_process_chunk',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        const data = response.data;
                        updateProgress(data);

                        if (data.status === 'completed') {
                            completeConversion();
                        } else {
                            // Continue processing
                            setTimeout(processNextChunk, 100);
                        }
                    } else {
                        alert('Error during conversion: ' + response.data);
                        resetUI();
                    }
                }).fail(function() {
                    alert('AJAX request failed. Check your connection.');
                    resetUI();
                });
            }

            // Update progress UI
            function updateProgress(data) {
                const percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;

                $('#adp-progress-bar').css('width', percent + '%').text(percent + '%');
                $('#adp-processed').text(data.processed);
                $('#adp-total').text(data.total);
                $('#adp-language-text').text(data.current_language);
                $('#adp-language-count').text(data.language_index + 1);
                $('#adp-language-total').text(data.total_languages);
                $('#adp-status-text').text('Processing ' + data.current_language + '...');
            }

            // Start polling for progress updates
            function startProgressPolling() {
                pollInterval = setInterval(function() {
                    $.post(ajaxurl, {
                        action: 'adp_get_progress',
                        nonce: nonce
                    }, function(response) {
                        if (response.success && response.data.log) {
                            $('#adp-log-viewer').text(response.data.log);
                            $('#adp-log-viewer').scrollTop($('#adp-log-viewer')[0].scrollHeight);
                        }
                    });
                }, 2000);
            }

            // Complete conversion
            function completeConversion() {
                clearInterval(pollInterval);
                $('#adp-status-text').text('✅ Completed!').css('color', 'green');
                $('#adp-progress-bar').css('background', 'linear-gradient(90deg, #00a32a, #008a20)');

                // Final log update
                setTimeout(function() {
                    $.post(ajaxurl, {
                        action: 'adp_get_progress',
                        nonce: nonce
                    }, function(response) {
                        if (response.success && response.data.log) {
                            $('#adp-log-viewer').text(response.data.log);
                            $('#adp-log-viewer').scrollTop($('#adp-log-viewer')[0].scrollHeight);
                        }
                    });
                }, 500);

                alert('✅ Conversion completed successfully!\n\nPlease clear WooCommerce cache and verify some products manually.');
                resetUI();
            }

            // Reset UI
            function resetUI() {
                isProcessing = false;
                $('#adp-start-btn').prop('disabled', false).text('🚀 Start Conversion (BGN → EUR)');
                if (pollInterval) {
                    clearInterval(pollInterval);
                }
            }

            // Clear log
            $('#adp-clear-log-btn').on('click', function() {
                if (!confirm('Clear conversion log?')) return;

                $.post(ajaxurl, {
                    action: 'adp_clear_log',
                    nonce: nonce
                }, function(response) {
                    if (response.success) {
                        $('#adp-log-viewer').text('');
                    }
                });
            });
        });
        </script>

        <style>
            .card { padding: 20px; background: white; }
            .card h2, .card h3 { margin-top: 0; }
        </style>
        <?php
    }

    /**
     * Get conversion statistics
     */
    private static function get_conversion_stats() {
        $args = array(
            'limit' => -1,
            'status' => 'publish',
            'return' => 'ids'
        );

        $all_products = wc_get_products($args);
        $total_products = count($all_products);

        $variable_args = array(
            'limit' => -1,
            'status' => 'publish',
            'type' => 'variable',
            'return' => 'ids'
        );
        $variable_products = wc_get_products($variable_args);
        $variable_count = count($variable_products);

        // Count variations
        $variation_count = 0;
        foreach ($variable_products as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('variable')) {
                $variation_count += count($product->get_children());
            }
        }

        return array(
            'total_products' => $total_products,
            'variable_products' => $variable_count,
            'total_variations' => $variation_count
        );
    }
}
