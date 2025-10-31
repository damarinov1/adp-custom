<?php
namespace AdpCustom;

if (!defined('ABSPATH')) exit;

class Admin
{
    const OPTION_VISIBILITY = 'maa_rule_visibility';
    const OPTION_CACHE = 'maa_rule_cache';

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_maa_adp_save', [$this, 'handleSave']);
        add_action('admin_post_maa_adp_scan', [$this, 'handleScan']);
    }

    public function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'ADP Custom',
            'ADP Custom',
            'manage_woocommerce',
            'maa-adp-custom',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $rules = get_option(self::OPTION_CACHE, []);
        $vis   = get_option(self::OPTION_VISIBILITY, []);

        ?>
        <div class="wrap">
            <h1>ADP Custom — Rule Titles</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('maa_adp_scan'); ?>
                <input type="hidden" name="action" value="maa_adp_scan">
                <p><button class="button">Scan rules (update list)</button></p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('maa_adp_save'); ?>
                <input type="hidden" name="action" value="maa_adp_save">

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:100px;">Show?</th>
                            <th style="width:120px;">Rule ID</th>
                            <th>Title</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rules): ?>
                        <?php foreach ($rules as $id => $title): ?>
                            <tr>
                                <td>
                                    <label>
                                        <input type="checkbox" name="vis[<?php echo esc_attr($id); ?>]" value="1"
                                            <?php checked(isset($vis[$id]) ? (bool)$vis[$id] : false); ?>>
                                        Show
                                    </label>
                                </td>
                                <td><code><?php echo esc_html($id); ?></code></td>
                                <td><?php echo esc_html($title); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3">No rules cached yet. Click “Scan rules”.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <p><button class="button button-primary">Save visibility</button></p>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('maa_adp_save');

        $incoming = isset($_POST['vis']) && is_array($_POST['vis']) ? array_map('boolval', $_POST['vis']) : [];
        $cached   = get_option(self::OPTION_CACHE, []);

        // Default to true (shown) if not provided
        $vis = [];
        foreach ($cached as $id => $_) {
            $vis[$id] = isset($incoming[$id]) ? (bool)$incoming[$id] : false;
        }

        update_option(self::OPTION_VISIBILITY, $vis, false);
        wp_safe_redirect(add_query_arg(['page' => 'maa-adp-custom', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handleScan(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        check_admin_referer('maa_adp_scan');

        $rules = $this->collectRules();
        update_option(self::OPTION_CACHE, $rules, false);

        // Ensure any new rules default to "hide"
        $vis = get_option(self::OPTION_VISIBILITY, []);
        foreach ($rules as $id => $title) {
            if (!isset($vis[$id])) $vis[$id] = false;
        }
        update_option(self::OPTION_VISIBILITY, $vis, false);

        wp_safe_redirect(add_query_arg(['page' => 'maa-adp-custom', 'scanned' => '1'], admin_url('admin.php')));
        exit;
    }

    /**
     * Collect rule IDs + titles without hardcoding IDs.
     * Strategy: query products, ask ADP for active rules in empty-cart context,
     * de-dup by rule ID.
     */
    private function collectRules(): array
    {
        if (!function_exists('adp_functions')) return [];

        $found = [];
        $max_products = 2000; // Safety limit to prevent timeout
        $products_scanned = 0;
        $start_time = time();
        $max_execution_time = 25; // Stop before PHP timeout (usually 30s)

        // WPML: get all active languages (fallback to single-lang)
        $langs = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]) ?: [];
        $lang_codes = $langs ? array_keys($langs) : [''];

        foreach ($lang_codes as $code) {
            if ($code) do_action('wpml_switch_language', $code);

            $paged = 1;
            $postsPerPage   = 200;
            do {
                // Safety check: stop if we've scanned enough products
                if ($products_scanned >= $max_products) {
                    break 2; // Break out of both loops
                }

                // Safety check: stop if approaching time limit
                if ((time() - $start_time) >= $max_execution_time) {
                    break 2;
                }

                $q = new \WP_Query([
                    'post_type'      => 'product',
                    'posts_per_page' => $postsPerPage,
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                    'paged'          => $paged,
                    'no_found_rows'  => true,
                    'update_post_term_cache' => false,
                    'update_post_meta_cache' => false,
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                ]);
    
                foreach ($q->posts as $pid) {
                    $products_scanned++;

                    $p = wc_get_product($pid);
                    if (!$p) continue;

                    $collect = function(\WC_Product $prod) use (&$found) {
                        // use_empty_cart=true; big qty surfaces bulk/multi-buy rules
                        $rules = adp_functions()->getActiveRulesForProduct($prod, 50, true);
                        // Also probe qty=1 to catch “at-qty-1” discounts
                        $rules_q1 = adp_functions()->getActiveRulesForProduct($prod, 1, true);
    
                        foreach ([$rules, $rules_q1] as $set) {
                            if (!is_array($set)) continue;
                            foreach ($set as $rule) {
                                if (!is_object($rule)) continue;
                                $id    = method_exists($rule,'getId') ? (string) $rule->getId() : '';
                                $title = method_exists($rule,'getTitle') ? (string) $rule->getTitle() : '';
                                if ($id !== '' && $title !== '') $found[$id] = $title;
                            }
                        }
                    };
    
                    if ($p->is_type('variable')) {
                        $var_ids = method_exists($p, 'get_visible_children') ? $p->get_visible_children() : $p->get_children();
                        foreach ($var_ids as $vid) if ($v = wc_get_product($vid)) $collect($v);
                    } else {
                        $collect($p);
                    }
                }
    
                $paged++;
            } while (!empty($q->posts));
        }
    
        ksort($found, SORT_NATURAL);
        return $found;
    }

}
