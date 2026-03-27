<?php
/**
 * Plugin Name: SRK Variation to Add-Ons Migrator
 * Description: Converts variable products into simple products with WooCommerce Product Add-Ons image-based fields in batches, with searchable product selection, logs, skipped handling, variation image migration, and unmatched product reporting.
 * Version: 1.3.0
 * Author: Sumon Rahman Kabbo
 * Author URI: https://sumonrahmankabbo.com/
 * Text Domain: srk-variation-to-addons-migrator
 */

if (!defined('ABSPATH')) {
    exit;
}

class SRK_Variation_To_Addons_Migrator {
    const OPTION_STATE = 'srk_vtam_state';
    const NONCE_ACTION = 'srk_vtam_nonce_action';
    const MENU_SLUG = 'srk-variation-to-addons-migrator';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_srk_vtam_reset_run', [$this, 'ajax_reset_run']);
        add_action('wp_ajax_srk_vtam_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_srk_vtam_run_batch', [$this, 'ajax_run_batch']);
        add_action('wp_ajax_srk_vtam_search_products', [$this, 'ajax_search_products']);
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Variation to Add-Ons',
            'Variation to Add-Ons',
            'manage_woocommerce',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'woocommerce_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('srk-vtam-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.3.0');
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_script('srk-vtam-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery', 'wc-enhanced-select'], '1.3.0', true);

        wp_localize_script('srk-vtam-admin', 'SRKVTAM', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'batchFailed' => __('Batch failed.', 'srk-variation-to-addons-migrator'),
                'requestFailed' => __('Batch request failed.', 'srk-variation-to-addons-migrator'),
                'searching' => __('Search for a product by name or ID', 'srk-variation-to-addons-migrator'),
            ],
        ]);
    }

    private function get_state() {
        $state = get_option(self::OPTION_STATE, []);
        return is_array($state) ? $state : [];
    }

    private function save_state($state) {
        update_option(self::OPTION_STATE, $state, false);
    }

    private function default_state() {
        return [
            'running' => false,
            'offset' => 0,
            'batch_size' => 10,
            'converted' => 0,
            'skipped' => 0,
            'failed' => 0,
            'done' => false,
            'last_message' => '',
            'started_at' => current_time('mysql'),
            'finished_at' => '',
            'log_lines' => [],
            'failed_products' => [],
            'skipped_products' => [],
            'selected_product_id' => 0,
            'selected_product_name' => '',
            'total_products' => 0,
        ];
    }

    private function append_log(&$state, $line) {
        $state['log_lines'][] = '[' . current_time('H:i:s') . '] ' . wp_strip_all_tags($line);
        if (count($state['log_lines']) > 500) {
            $state['log_lines'] = array_slice($state['log_lines'], -500);
        }
    }

    private function get_variable_product_ids($selected_product_id = 0) {
        if ($selected_product_id > 0) {
            $product = wc_get_product($selected_product_id);
            if ($product && $product->is_type('variable')) {
                return [(int) $selected_product_id];
            }
            return [];
        }

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'tax_query' => [[
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => ['variable'],
            ]],
        ]);

        return array_map('intval', $ids);
    }

    private function product_has_addons($product_id) {
        $addons = get_post_meta($product_id, '_product_addons', true);
        return is_array($addons) && !empty($addons);
    }

    private function product_has_variation_attributes($product) {
        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'get_variation') && $attribute->get_variation()) {
                return true;
            }
        }

        return false;
    }

    private function get_attribute_option_label($attribute_name, $option_slug, $product) {
        $option_label = $option_slug;

        if (taxonomy_exists($attribute_name)) {
            $term = get_term_by('slug', $option_slug, $attribute_name);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
        }

        $raw_attributes = $product->get_attributes();
        if (isset($raw_attributes[$attribute_name]) && is_object($raw_attributes[$attribute_name]) && method_exists($raw_attributes[$attribute_name], 'get_options')) {
            foreach ((array) $raw_attributes[$attribute_name]->get_options() as $raw_option) {
                if ((string) sanitize_title($raw_option) === (string) $option_slug) {
                    return (string) $raw_option;
                }
            }
        }

        return $option_label;
    }

    private function get_variation_image_id($variation_id, $parent_product_id = 0) {
        $variation_id = absint($variation_id);
        $parent_product_id = absint($parent_product_id);

        if ($variation_id > 0) {
            $image_id = get_post_thumbnail_id($variation_id);
            if ($image_id) {
                return absint($image_id);
            }

            $image_id = get_post_meta($variation_id, '_thumbnail_id', true);
            if ($image_id) {
                return absint($image_id);
            }
        }

        if ($parent_product_id > 0) {
            $image_id = get_post_thumbnail_id($parent_product_id);
            if ($image_id) {
                return absint($image_id);
            }
        }

        return 0;
    }

    private function get_live_counts($selected_product_id = 0) {
        $ids = $this->get_variable_product_ids($selected_product_id);
        $counts = [
            'variable_with_default' => 0,
            'variable_without_addons' => 0,
            'variable_with_addons' => 0,
            'remaining_names' => [],
            'scope_total' => count($ids),
        ];

        foreach ($ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                continue;
            }

            $has_default = $this->product_has_variation_attributes($product) || count($product->get_children()) > 0;
            $has_addons  = $this->product_has_addons($product_id);

            if ($has_default) {
                $counts['variable_with_default']++;
            }

            if ($has_addons) {
                $counts['variable_with_addons']++;
            } else {
                $counts['variable_without_addons']++;
            }

            if ($has_default) {
                $counts['remaining_names'][] = get_the_title($product_id) . ' (#' . $product_id . ')';
            }
        }

        return $counts;
    }

    private function get_status_payload() {
        $state = $this->get_state();
        $selected_product_id = !empty($state['selected_product_id']) ? absint($state['selected_product_id']) : 0;
        $counts = $this->get_live_counts($selected_product_id);

        return [
            'state' => $state,
            'counts' => $counts,
        ];
    }

    public function ajax_reset_run() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $selected_product_id = isset($_POST['selected_product_id']) ? absint($_POST['selected_product_id']) : 0;
        $state = $this->default_state();
        $state['selected_product_id'] = $selected_product_id;
        $state['selected_product_name'] = $selected_product_id ? get_the_title($selected_product_id) : '';
        $state['total_products'] = count($this->get_variable_product_ids($selected_product_id));
        $this->save_state($state);
        wp_send_json_success($this->get_status_payload());
    }

    public function ajax_get_status() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        wp_send_json_success($this->get_status_payload());
    }

    public function ajax_search_products() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $term = isset($_GET['term']) ? wc_clean(wp_unslash($_GET['term'])) : '';
        $results = [];

        if ($term === '') {
            wp_send_json($results);
        }

        $query_args = [
            'status' => ['publish', 'private', 'draft', 'pending'],
            'type'   => 'variable',
            'limit'  => 20,
            'return' => 'ids',
            'orderby' => 'title',
            'order'  => 'ASC',
            's'      => $term,
        ];

        if (ctype_digit($term)) {
            $query_args['include'] = [absint($term)];
            unset($query_args['s']);
        }

        $ids = wc_get_products($query_args);

        if (!empty($ids)) {
            foreach ($ids as $product_id) {
                $results[] = [
                    'id' => $product_id,
                    'text' => sprintf('%s (#%d)', get_the_title($product_id), $product_id),
                ];
            }
        }

        wp_send_json($results);
    }

    public function ajax_run_batch() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        if (!class_exists('WC_Product_Addons')) {
            wp_send_json_error(['message' => 'WooCommerce Product Add-Ons plugin is not active.']);
        }

        $batch_size = isset($_POST['batch_size']) ? max(1, min(100, absint($_POST['batch_size']))) : 10;
        $reset = !empty($_POST['reset']);
        $selected_product_id = isset($_POST['selected_product_id']) ? absint($_POST['selected_product_id']) : 0;

        $state = $this->get_state();
        if ($reset || empty($state)) {
            $state = $this->default_state();
            $state['selected_product_id'] = $selected_product_id;
            $state['selected_product_name'] = $selected_product_id ? get_the_title($selected_product_id) : '';
            $state['total_products'] = count($this->get_variable_product_ids($selected_product_id));
        } else {
            if ($selected_product_id !== (!empty($state['selected_product_id']) ? absint($state['selected_product_id']) : 0)) {
                $state = $this->default_state();
                $state['selected_product_id'] = $selected_product_id;
                $state['selected_product_name'] = $selected_product_id ? get_the_title($selected_product_id) : '';
                $state['total_products'] = count($this->get_variable_product_ids($selected_product_id));
            }
        }

        $state['running'] = true;
        $state['done'] = false;
        $state['batch_size'] = $batch_size;

        $all_ids = $this->get_variable_product_ids($selected_product_id);
        $state['total_products'] = count($all_ids);

        if ($selected_product_id > 0 && empty($all_ids)) {
            $state['running'] = false;
            $state['done'] = true;
            $this->append_log($state, 'Selected product is not a variable product or could not be found.');
            $this->save_state($state);
            wp_send_json_success($this->get_status_payload());
        }

        $offset = isset($state['offset']) ? (int) $state['offset'] : 0;
        $batch_ids = array_slice($all_ids, $offset, $batch_size);

        if (empty($batch_ids)) {
            $state['running'] = false;
            $state['done'] = true;
            $state['finished_at'] = current_time('mysql');
            $this->append_log($state, 'Run completed. No more variable products remain.');
            $this->save_state($state);
            wp_send_json_success($this->get_status_payload());
        }

        foreach ($batch_ids as $product_id) {
            $result = $this->convert_product($product_id);

            if ($result['status'] === 'converted') {
                $state['converted']++;
                $this->append_log($state, 'Converted: ' . $result['name'] . ' (#' . $product_id . ')');
            } elseif ($result['status'] === 'skipped') {
                $state['skipped']++;
                $state['skipped_products'][$product_id] = $result['name'] . ' (#' . $product_id . ') - ' . $result['reason'];
                $this->append_log($state, 'Skipped: ' . $result['name'] . ' (#' . $product_id . ') - ' . $result['reason']);
            } else {
                $state['failed']++;
                $state['failed_products'][$product_id] = $result['name'] . ' (#' . $product_id . ') - ' . $result['reason'];
                $this->append_log($state, 'Failed: ' . $result['name'] . ' (#' . $product_id . ') - ' . $result['reason']);
            }
        }

        $state['offset'] = $offset + count($batch_ids);

        if ($state['offset'] >= count($all_ids)) {
            $state['running'] = false;
            $state['done'] = true;
            $state['finished_at'] = current_time('mysql');
            $this->append_log($state, 'Run completed. All batches finished.');
        }

        $this->save_state($state);
        wp_send_json_success($this->get_status_payload());
    }

    private function convert_product($product_id) {
        $product = wc_get_product($product_id);
        $name = get_the_title($product_id);

        if (!$product || !$product->is_type('variable')) {
            return ['status' => 'failed', 'name' => $name, 'reason' => 'Not a variable product'];
        }

        if ($this->product_has_addons($product_id)) {
            return ['status' => 'skipped', 'name' => $name, 'reason' => 'Already has Product Add-Ons'];
        }

        $children = $product->get_children();
        $variation_attrs = $product->get_variation_attributes();

        if (empty($children) || empty($variation_attrs)) {
            return ['status' => 'failed', 'name' => $name, 'reason' => 'Missing variations or variation attributes'];
        }

        $available_variations = $product->get_available_variations();
        if (empty($available_variations)) {
            return ['status' => 'failed', 'name' => $name, 'reason' => 'No available variations found'];
        }

        $all_prices = [];
        foreach ($available_variations as $variation_data) {
            if (isset($variation_data['display_price']) && $variation_data['display_price'] !== '') {
                $all_prices[] = (float) $variation_data['display_price'];
            }
        }

        if (empty($all_prices)) {
            return ['status' => 'failed', 'name' => $name, 'reason' => 'Could not determine lowest variation price'];
        }

        $lowest_price = min($all_prices);
        $addons = [];
        $position = 0;

        foreach ($variation_attrs as $attribute_name => $attribute_options) {
            $option_price_map = [];
            $option_image_map = [];
            $option_label_map = [];
            $variation_key = 'attribute_' . sanitize_title($attribute_name);

            foreach ($available_variations as $variation_data) {
                $variation_price = isset($variation_data['display_price']) ? (float) $variation_data['display_price'] : 0;

                if (!isset($variation_data['attributes'][$variation_key])) {
                    continue;
                }

                $option_slug = $variation_data['attributes'][$variation_key];
                if ($option_slug === '') {
                    continue;
                }

                if (!isset($option_price_map[$option_slug])) {
                    $option_price_map[$option_slug] = $variation_price;
                } else {
                    $option_price_map[$option_slug] = min($option_price_map[$option_slug], $variation_price);
                }

                if (!isset($option_label_map[$option_slug])) {
                    $option_label_map[$option_slug] = $this->get_attribute_option_label($attribute_name, $option_slug, $product);
                }

                if (empty($option_image_map[$option_slug])) {
                    $variation_id = !empty($variation_data['variation_id']) ? absint($variation_data['variation_id']) : 0;
                    $variation_image_id = $this->get_variation_image_id($variation_id, $product_id);
                    if ($variation_image_id > 0) {
                        $option_image_map[$option_slug] = $variation_image_id;
                    }
                }
            }

            if (empty($option_price_map)) {
                continue;
            }

            $addon_options = [];

            foreach ($attribute_options as $option_slug) {
                $option_label = isset($option_label_map[$option_slug])
                    ? $option_label_map[$option_slug]
                    : $this->get_attribute_option_label($attribute_name, $option_slug, $product);

                $option_price = 0;
                if (isset($option_price_map[$option_slug])) {
                    $option_price = (float) $option_price_map[$option_slug] - (float) $lowest_price;
                }

                $option = [
                    'label'      => $option_label,
                    'price'      => wc_format_decimal($option_price, 2),
                    'price_type' => 'flat_fee',
                ];

                if (!empty($option_image_map[$option_slug])) {
                    $option['image'] = absint($option_image_map[$option_slug]);
                }

                $addon_options[] = $option;
            }

            $addon_title = wc_attribute_label($attribute_name, $product);

            $addons[] = [
                'name'        => $addon_title,
                'title_format' => 'label',
                'description_enable' => 0,
                'description' => '',
                'type'        => 'multiple_choice',
                'display'     => 'images',
                'position'    => $position,
                'options'     => $addon_options,
                'required'    => 1,
                'restrictions' => 0,
                'restrictions_type' => 'any_text',
                'adjust_price' => 0,
                'price_type'  => 'flat_fee',
                'price'       => 0,
                'min'         => 0,
                'max'         => 0,
                'wc_booking_person_qty_multiplier' => 0,
                'wc_booking_block_qty_multiplier' => 1,
                'wc_accommodation_booking_block_qty_multiplier' => 1,
            ];

            $position++;
        }

        if (empty($addons)) {
            return ['status' => 'failed', 'name' => $name, 'reason' => 'Could not generate add-on fields'];
        }

        update_post_meta($product_id, '_srk_vtam_backup_product_addons', get_post_meta($product_id, '_product_addons', true));
        update_post_meta($product_id, '_srk_vtam_backup_product_attributes', get_post_meta($product_id, '_product_attributes', true));
        update_post_meta($product_id, '_srk_vtam_backup_children', $children);
        update_post_meta($product_id, '_srk_vtam_backup_type', $product->get_type());
        update_post_meta($product_id, '_srk_vtam_backup_regular_price', get_post_meta($product_id, '_regular_price', true));
        update_post_meta($product_id, '_srk_vtam_backup_price', get_post_meta($product_id, '_price', true));

        update_post_meta($product_id, '_product_addons', $addons);
        update_post_meta($product_id, '_product_addons_exclude_global', '1');

        wp_set_object_terms($product_id, 'simple', 'product_type');
        delete_post_meta($product_id, '_product_attributes');

        update_post_meta($product_id, '_regular_price', wc_format_decimal($lowest_price, 2));
        update_post_meta($product_id, '_price', wc_format_decimal($lowest_price, 2));
        delete_post_meta($product_id, '_sale_price');
        delete_post_meta($product_id, '_min_variation_price');
        delete_post_meta($product_id, '_max_variation_price');
        delete_post_meta($product_id, '_min_variation_regular_price');
        delete_post_meta($product_id, '_max_variation_regular_price');
        delete_post_meta($product_id, '_min_price_variation_id');
        delete_post_meta($product_id, '_max_price_variation_id');
        delete_post_meta($product_id, '_default_attributes');

        foreach ($children as $child_id) {
            wp_delete_post($child_id, true);
        }

        clean_post_cache($product_id);
        wc_delete_product_transients($product_id);

        return ['status' => 'converted', 'name' => $name, 'reason' => ''];
    }

    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $status = $this->get_status_payload();
        $state = $status['state'];
        $counts = $status['counts'];
        ?>
        <div class="wrap srk-vtam-wrap">
            <h1>SRK Variation to Add-Ons Migrator</h1>
            <p>This tool converts variable products into simple products, sets the product price from the lowest variation price, creates Product Add-Ons image fields, skips products already having Product Add-Ons, removes default variation data, and deletes child variations after successful conversion.</p>

            <div class="srk-vtam-grid">
                <div class="srk-vtam-card">
                    <h2>Controls</h2>
                    <p>
                        <label for="srk-vtam-product-search"><strong>Select one product</strong></label><br>
                        <select id="srk-vtam-product-search" style="width: 100%;">
                            <option value=""><?php esc_html_e('All variable products', 'srk-variation-to-addons-migrator'); ?></option>
                            <?php if (!empty($state['selected_product_id'])) : ?>
                                <option value="<?php echo esc_attr($state['selected_product_id']); ?>" selected="selected"><?php echo esc_html($state['selected_product_name'] . ' (#' . absint($state['selected_product_id']) . ')'); ?></option>
                            <?php endif; ?>
                        </select>
                        <span class="description">Leave empty to run all variable products. Search and select a product to run only that product.</span>
                    </p>
                    <p>
                        <label for="srk-vtam-batch-size"><strong>Batch size</strong></label><br>
                        <input type="number" id="srk-vtam-batch-size" value="<?php echo esc_attr(!empty($state['batch_size']) ? $state['batch_size'] : 10); ?>" min="1" max="100">
                    </p>
                    <p>
                        <button class="button button-primary" id="srk-vtam-run">Run</button>
                        <button class="button" id="srk-vtam-reset">Reset Run</button>
                    </p>
                    <p id="srk-vtam-status-text"></p>
                </div>

                <div class="srk-vtam-card">
                    <h2>Live Counts</h2>
                    <p>Products in current scope: <strong id="srk-count-scope"><?php echo esc_html($counts['scope_total']); ?></strong></p>
                    <p>Variable products with default attributes/variations: <strong id="srk-count-default"><?php echo esc_html($counts['variable_with_default']); ?></strong></p>
                    <p>Variable products without Product Add-Ons: <strong id="srk-count-without"><?php echo esc_html($counts['variable_without_addons']); ?></strong></p>
                    <p>Variable products already having Product Add-Ons: <strong id="srk-count-with"><?php echo esc_html($counts['variable_with_addons']); ?></strong></p>
                    <p>Already converted in this run: <strong id="srk-count-converted"><?php echo esc_html(isset($state['converted']) ? $state['converted'] : 0); ?></strong></p>
                    <p>Skipped in this run: <strong id="srk-count-skipped"><?php echo esc_html(isset($state['skipped']) ? $state['skipped'] : 0); ?></strong></p>
                    <p>Failed in this run: <strong id="srk-count-failed"><?php echo esc_html(isset($state['failed']) ? $state['failed'] : 0); ?></strong></p>
                </div>
            </div>

            <div class="srk-vtam-grid">
                <div class="srk-vtam-card">
                    <h2>Live Log</h2>
                    <div id="srk-vtam-log" class="srk-vtam-log"><?php
                        if (!empty($state['log_lines'])) {
                            echo esc_html(implode("\n", $state['log_lines']));
                        }
                    ?></div>
                </div>

                <div class="srk-vtam-card">
                    <h2>Products That Could Not Be Changed</h2>
                    <div id="srk-vtam-failed-list" class="srk-vtam-list"><?php
                        $failed_list = [];
                        if (!empty($state['failed_products'])) {
                            $failed_list = array_values($state['failed_products']);
                        } elseif (!empty($counts['remaining_names'])) {
                            $failed_list = $counts['remaining_names'];
                        }
                        echo esc_html(implode("\n", $failed_list));
                    ?></div>
                </div>
            </div>
        </div>
        <?php
    }
}

new SRK_Variation_To_Addons_Migrator();
