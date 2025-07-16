<?php

declare(strict_types=1);

/**
 * Plugin Name: WooCommerce Percentage Shipping
 * Description: Calculate shipping costs as a percentage of physical products
 * Version: 1.6.2
 * Author: Tobias Haas (Perplexity)
 * Text Domain: wc-percentage-shipping
 * Domain Path: /languages
 * Requires at least: 5.6
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 5.0
 * WC tested up to: 10.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('add_action')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// HPOS Compatibility Declaration
add_action(
    'before_woocommerce_init',
    static function (): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
        }
    }
);

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WooCommerce Percentage Shipping requires WooCommerce to be active.', 'wc-percentage-shipping');
        echo '</p></div>';
    });
    return;
}

// Check PHP version
if (version_compare(PHP_VERSION, '8.0', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WooCommerce Percentage Shipping requires PHP 8.0 or higher.', 'wc-percentage-shipping');
        echo '</p></div>';
    });
    return;
}

/**
 * Plugin Security Configuration
 */
enum PluginSecurity: string 
{
    case NONCE_ACTION = 'wc_percentage_shipping_settings';
    case NONCE_FIELD = 'wc_percentage_shipping_nonce';
    case CAPABILITY = 'manage_woocommerce';
    case MAX_INPUT_LENGTH = '255';
    case AJAX_RATE_LIMIT = '10';
}

/**
 * Plugin Configuration
 */
enum PluginConfig: string 
{
    case VERSION = '1.6.2';
    case TEXTDOMAIN = 'wc-percentage-shipping';
    case OPTION_NAME = 'wc_percentage_shipping_options';
    case PLUGIN_SLUG = 'percentage-shipping';
}

final class WC_Percentage_Shipping_Plugin
{
    private static ?self $instance = null;
    private readonly string $plugin_dir;
    private readonly string $plugin_url;
    private array $ajax_requests = [];

    private function __construct(
        private readonly string $plugin_file = __FILE__
    ) {
        $this->plugin_dir = plugin_dir_path($this->plugin_file);
        $this->plugin_url = plugin_dir_url($this->plugin_file);
        
        $this->define_constants();
        $this->init_hooks();
    }

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function define_constants(): void
    {
        if (!defined('WC_PERCENTAGE_SHIPPING_VERSION')) {
            define('WC_PERCENTAGE_SHIPPING_VERSION', PluginConfig::VERSION->value);
        }
        if (!defined('WC_PERCENTAGE_SHIPPING_DIR')) {
            define('WC_PERCENTAGE_SHIPPING_DIR', $this->plugin_dir);
        }
        if (!defined('WC_PERCENTAGE_SHIPPING_URL')) {
            define('WC_PERCENTAGE_SHIPPING_URL', $this->plugin_url);
        }
    }

    private function init_hooks(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('woocommerce_shipping_init', [$this, 'include_shipping_method']);
        add_filter('woocommerce_shipping_methods', [$this, 'register_shipping_method']);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), [$this, 'settings_link']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_wc_percentage_shipping_preview', [$this, 'ajax_preview_calculation']);
        
        add_action('admin_head', [$this, 'add_security_headers']);
        add_action('wp_scheduled_delete', [$this, 'cleanup_rate_limiting']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            PluginConfig::TEXTDOMAIN->value,
            false,
            dirname(plugin_basename($this->plugin_file)) . '/languages'
        );
    }

    public function add_admin_menu(): void
    {
        if (!current_user_can(PluginSecurity::CAPABILITY->value)) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            __('Percentage Shipping', PluginConfig::TEXTDOMAIN->value),
            __('Percentage Shipping', PluginConfig::TEXTDOMAIN->value),
            PluginSecurity::CAPABILITY->value,
            PluginConfig::PLUGIN_SLUG->value,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'wc_percentage_shipping_settings',
            PluginConfig::OPTION_NAME->value,
            [
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->get_default_options(),
            ]
        );

        add_settings_section(
            'wc_percentage_shipping_main',
            __('Shipping Calculation Settings', PluginConfig::TEXTDOMAIN->value),
            [$this, 'render_section_callback'],
            'wc_percentage_shipping_settings'
        );

        $this->add_settings_fields();
    }

    private function add_settings_fields(): void
    {
        $fields = [
            'enabled' => __('Enable Plugin', PluginConfig::TEXTDOMAIN->value),
            'percentage' => __('Shipping Percentage', PluginConfig::TEXTDOMAIN->value),
            'minimum_fee' => __('Minimum Fee', PluginConfig::TEXTDOMAIN->value),
            'maximum_fee' => __('Maximum Fee', PluginConfig::TEXTDOMAIN->value),
            'include_digital_products' => __('Include Digital Products', PluginConfig::TEXTDOMAIN->value),
            'excluded_categories' => __('Excluded Categories', PluginConfig::TEXTDOMAIN->value),
            'debug_mode' => __('Debug Mode', PluginConfig::TEXTDOMAIN->value),
        ];

        foreach ($fields as $field_id => $field_title) {
            add_settings_field(
                $field_id,
                $field_title,
                [$this, "render_field_{$field_id}"],
                'wc_percentage_shipping_settings',
                'wc_percentage_shipping_main'
            );
        }
    }

    private function get_default_options(): array
    {
        return [
            'enabled' => 'yes',
            'percentage' => 10.0,
            'minimum_fee' => 5.0,
            'maximum_fee' => 100.0,
            'include_digital_products' => 'no',
            'excluded_categories' => [],
            'debug_mode' => 'no',
        ];
    }

    private function get_option(string $key, mixed $default = ''): mixed
    {
        $options = get_option(PluginConfig::OPTION_NAME->value, $this->get_default_options());
        return $options[$key] ?? $default;
    }

    public function render_settings_page(): void
    {
        if (!current_user_can(PluginSecurity::CAPABILITY->value)) {
            wp_die(__('You do not have sufficient permissions to access this page.', PluginConfig::TEXTDOMAIN->value));
        }

        if (isset($_POST['submit'])) {
            $this->handle_form_submission();
        }

        $this->render_admin_page();
    }

    private function handle_form_submission(): void
    {
        if (!isset($_POST[PluginSecurity::NONCE_FIELD->value]) || 
            !wp_verify_nonce($_POST[PluginSecurity::NONCE_FIELD->value], PluginSecurity::NONCE_ACTION->value)) {
            wp_die(__('Security check failed. Please try again.', PluginConfig::TEXTDOMAIN->value));
        }

        if (isset($_POST[PluginConfig::OPTION_NAME->value])) {
            $sanitized_options = $this->sanitize_options($_POST[PluginConfig::OPTION_NAME->value]);
            update_option(PluginConfig::OPTION_NAME->value, $sanitized_options);
            
            add_settings_error(
                PluginConfig::OPTION_NAME->value,
                'settings_updated',
                __('Settings saved successfully!', PluginConfig::TEXTDOMAIN->value),
                'success'
            );
        }
    }

    private function render_admin_page(): void
    {
        ?>
        <div class="wrap wc-percentage-shipping-admin">
            <h1><?php echo esc_html__('WooCommerce Percentage Shipping', PluginConfig::TEXTDOMAIN->value); ?></h1>
            
            <div class="wc-percentage-shipping-header">
                <div class="wc-percentage-shipping-status">
                    <?php 
                    $enabled = $this->get_option('enabled', 'yes');
                    $status_class = $enabled === 'yes' ? 'status-enabled' : 'status-disabled';
                    $status_icon = $enabled === 'yes' ? 'dashicons-yes' : 'dashicons-no';
                    $status_text = $enabled === 'yes' ? __('Enabled', PluginConfig::TEXTDOMAIN->value) : __('Disabled', PluginConfig::TEXTDOMAIN->value);
                    ?>
                    <span class="<?php echo esc_attr($status_class); ?>">
                        <span class="dashicons <?php echo esc_attr($status_icon); ?>"></span>
                        <?php echo esc_html($status_text); ?>
                    </span>
                </div>
            </div>

            <?php settings_errors(); ?>
            
            <div class="wc-percentage-shipping-content">
                <div class="wc-percentage-shipping-main">
                    <form method="post" action="">
                        <?php 
                        wp_nonce_field(PluginSecurity::NONCE_ACTION->value, PluginSecurity::NONCE_FIELD->value);
                        settings_fields('wc_percentage_shipping_settings');
                        do_settings_sections('wc_percentage_shipping_settings');
                        submit_button(__('Save Settings', PluginConfig::TEXTDOMAIN->value), 'primary', 'submit', false);
                        ?>
                    </form>
                </div>
                
                <div class="wc-percentage-shipping-box">
                    <h3><span class="dashicons dashicons-chart-area"></span> <?php echo esc_html__('Current Settings', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <?php $this->render_settings_overview(); ?>
                </div>
                
                <div class="wc-percentage-shipping-box">
                    <h3><span class="dashicons dashicons-calculator"></span> <?php echo esc_html__('Calculation Preview', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <?php $this->render_calculation_preview(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_section_callback(): void
    {
        echo '<div class="wc-percentage-shipping-section-intro">';
        echo '<p class="description">' . esc_html__('Configure the percentage shipping calculation settings below. This plugin uses modern PHP 8+ features and is completely jQuery-free.', PluginConfig::TEXTDOMAIN->value) . '</p>';
        echo '<div class="wc-percentage-shipping-info-box">';
        echo '<h4><span class="dashicons dashicons-info"></span> ' . esc_html__('How it works', PluginConfig::TEXTDOMAIN->value) . '</h4>';
        echo '<ol>';
        echo '<li>' . esc_html__('The plugin analyzes all products in the cart', PluginConfig::TEXTDOMAIN->value) . '</li>';
        echo '<li>' . esc_html__('Filters products according to your settings (physical/digital)', PluginConfig::TEXTDOMAIN->value) . '</li>';
        echo '<li>' . esc_html__('Calculates shipping costs as a percentage of filtered products', PluginConfig::TEXTDOMAIN->value) . '</li>';
        echo '<li>' . esc_html__('Applies minimum and maximum fee limits', PluginConfig::TEXTDOMAIN->value) . '</li>';
        echo '</ol>';
        echo '</div>';
        echo '</div>';
    }

    // Field rendering methods
    public function render_field_enabled(): void
    {
        $enabled = $this->get_option('enabled', 'yes');
        $field_name = PluginConfig::OPTION_NAME->value . '[enabled]';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="yes" <?php checked($enabled, 'yes'); ?> />
            <?php echo esc_html__('Enable percentage shipping calculation', PluginConfig::TEXTDOMAIN->value); ?>
        </label>
        <?php echo $this->render_help_tip(__('When enabled, shipping costs will be calculated as a percentage of selected products.', PluginConfig::TEXTDOMAIN->value)); ?>
        <p class="description"><?php echo esc_html__('Activate this option to use percentage-based shipping calculation.', PluginConfig::TEXTDOMAIN->value); ?></p>
        <?php
    }

    public function render_field_percentage(): void
    {
        $percentage = $this->get_option('percentage', 10.0);
        $field_name = PluginConfig::OPTION_NAME->value . '[percentage]';
        $max_length = (int) PluginSecurity::MAX_INPUT_LENGTH->value;
        ?>
        <input type="number" class="small-text" step="0.01" min="0" max="100" 
               name="<?php echo esc_attr($field_name); ?>" 
               value="<?php echo esc_attr($percentage); ?>" 
               maxlength="<?php echo esc_attr($max_length); ?>" /> %
        <?php echo $this->render_help_tip(__('Enter the percentage (0-100). Example: 10 = 10% of cart total.', PluginConfig::TEXTDOMAIN->value)); ?>
        <p class="description"><?php echo esc_html__('Percentage of product total that will be calculated as shipping costs.', PluginConfig::TEXTDOMAIN->value); ?></p>
        <div class="wc-percentage-shipping-example">
            <strong><?php echo esc_html__('Example:', PluginConfig::TEXTDOMAIN->value); ?></strong>
            <?php echo sprintf(esc_html__('At %d%% and a cart value of %s, shipping costs would be %s', PluginConfig::TEXTDOMAIN->value), 10, wc_price(100), wc_price(10)); ?>
        </div>
        <?php
    }

    public function render_field_minimum_fee(): void
    {
        $minimum_fee = $this->get_option('minimum_fee', 5.0);
        $field_name = PluginConfig::OPTION_NAME->value . '[minimum_fee]';
        $max_length = (int) PluginSecurity::MAX_INPUT_LENGTH->value;
        ?>
        <input type="number" class="small-text" step="0.01" min="0" 
               name="<?php echo esc_attr($field_name); ?>" 
               value="<?php echo esc_attr($minimum_fee); ?>" 
               maxlength="<?php echo esc_attr($max_length); ?>" />
        <?php echo esc_html(get_woocommerce_currency_symbol()); ?>
        <?php echo $this->render_help_tip(__('Minimum shipping fee that will be charged regardless of percentage calculation.', PluginConfig::TEXTDOMAIN->value)); ?>
        <p class="description"><?php echo esc_html__('Minimum shipping cost, even if percentage calculation results in a lower amount.', PluginConfig::TEXTDOMAIN->value); ?></p>
        <?php
    }

    public function render_field_maximum_fee(): void
    {
        $maximum_fee = $this->get_option('maximum_fee', 100.0);
        $field_name = PluginConfig::OPTION_NAME->value . '[maximum_fee]';
        $max_length = (int) PluginSecurity::MAX_INPUT_LENGTH->value;
        ?>
        <input type="number" class="small-text" step="0.01" min="0" 
               name="<?php echo esc_attr($field_name); ?>" 
               value="<?php echo esc_attr($maximum_fee); ?>" 
               maxlength="<?php echo esc_attr($max_length); ?>" />
        <?php echo esc_html(get_woocommerce_currency_symbol()); ?>
        <?php echo $this->render_help_tip(__('Maximum shipping fee that will be charged. Set to 0 for unlimited.', PluginConfig::TEXTDOMAIN->value)); ?>
        <p class="description"><?php echo esc_html__('Maximum shipping cost that will not be exceeded. 0 for unlimited.', PluginConfig::TEXTDOMAIN->value); ?></p>
        <?php
    }

    public function render_field_include_digital_products(): void
    {
        $include_digital = $this->get_option('include_digital_products', 'no');
        $field_name = PluginConfig::OPTION_NAME->value . '[include_digital_products]';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="yes" <?php checked($include_digital, 'yes'); ?> />
            <?php echo esc_html__('Include virtual and downloadable products in calculation', PluginConfig::TEXTDOMAIN->value); ?>
        </label>
        <?php echo $this->render_help_tip(__('By default, only physical products are used for shipping calculation. Enable this option to also include digital products.', PluginConfig::TEXTDOMAIN->value)); ?>
        <div class="wc-percentage-shipping-digital-info">
            <p class="description"><strong><?php echo esc_html__('Digital products include:', PluginConfig::TEXTDOMAIN->value); ?></strong></p>
            <ul class="description">
                <li>• <?php echo esc_html__('Virtual products (services, subscriptions)', PluginConfig::TEXTDOMAIN->value); ?></li>
                <li>• <?php echo esc_html__('Downloadable products (e-books, software, music)', PluginConfig::TEXTDOMAIN->value); ?></li>
            </ul>
            <p class="description"><strong><?php echo esc_html__('Use case:', PluginConfig::TEXTDOMAIN->value); ?></strong> <?php echo esc_html__('Enable this option if you want to charge a "processing fee" for digital products.', PluginConfig::TEXTDOMAIN->value); ?></p>
        </div>
        <?php
    }

    public function render_field_excluded_categories(): void
    {
        $excluded_categories = (array) $this->get_option('excluded_categories', []);
        $field_name = PluginConfig::OPTION_NAME->value . '[excluded_categories][]';
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        
        if (!is_wp_error($terms) && !empty($terms)) {
            echo '<select name="' . esc_attr($field_name) . '" multiple style="height: 150px; width: 300px;">';
            foreach ($terms as $term) {
                $selected = in_array($term->term_id, $excluded_categories, true) ? 'selected' : '';
                echo '<option value="' . esc_attr($term->term_id) . '" ' . $selected . '>' . esc_html($term->name) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p>' . esc_html__('No product categories found.', PluginConfig::TEXTDOMAIN->value) . '</p>';
        }
        
        echo $this->render_help_tip(__('Select product categories to exclude from shipping calculation.', PluginConfig::TEXTDOMAIN->value));
        echo '<p class="description">' . esc_html__('Ctrl/Cmd + click for multiple selection. Products from these categories will not be included in shipping calculation.', PluginConfig::TEXTDOMAIN->value) . '</p>';
    }

    public function render_field_debug_mode(): void
    {
        $debug_mode = $this->get_option('debug_mode', 'no');
        $field_name = PluginConfig::OPTION_NAME->value . '[debug_mode]';
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr($field_name); ?>" value="yes" <?php checked($debug_mode, 'yes'); ?> />
            <?php echo esc_html__('Enable detailed logging', PluginConfig::TEXTDOMAIN->value); ?>
        </label>
        <?php echo $this->render_help_tip(__('Enables detailed logging for troubleshooting. Logs can be found in WooCommerce > Status > Logs.', PluginConfig::TEXTDOMAIN->value)); ?>
        <p class="description"><?php echo esc_html__('Only enable for troubleshooting. Logs are saved under WooCommerce > Status > Logs.', PluginConfig::TEXTDOMAIN->value); ?></p>
        <?php
    }

    private function render_help_tip(string $tip): string
    {
        return '<span class="wc-percentage-shipping-help-tip dashicons dashicons-editor-help" title="' . esc_attr($tip) . '"></span>';
    }

    private function render_settings_overview(): void
    {
        $enabled = $this->get_option('enabled', 'yes');
        $percentage = $this->get_option('percentage', 10.0);
        $minimum_fee = $this->get_option('minimum_fee', 5.0);
        $maximum_fee = $this->get_option('maximum_fee', 100.0);
        $include_digital = $this->get_option('include_digital_products', 'no');
        $debug_mode = $this->get_option('debug_mode', 'no');
        
        echo '<table class="wc-percentage-shipping-overview">';
        echo '<tr><td>' . esc_html__('Status:', PluginConfig::TEXTDOMAIN->value) . '</td><td>';
        echo $enabled === 'yes' ? '<span class="enabled">' . esc_html__('Enabled', PluginConfig::TEXTDOMAIN->value) . '</span>' : '<span class="disabled">' . esc_html__('Disabled', PluginConfig::TEXTDOMAIN->value) . '</span>';
        echo '</td></tr>';
        echo '<tr><td>' . esc_html__('Percentage:', PluginConfig::TEXTDOMAIN->value) . '</td><td>' . esc_html($percentage) . '%</td></tr>';
        echo '<tr><td>' . esc_html__('Minimum Fee:', PluginConfig::TEXTDOMAIN->value) . '</td><td>' . wp_kses_post(wc_price($minimum_fee)) . '</td></tr>';
        echo '<tr><td>' . esc_html__('Maximum Fee:', PluginConfig::TEXTDOMAIN->value) . '</td><td>' . ($maximum_fee > 0 ? wp_kses_post(wc_price($maximum_fee)) : esc_html__('Unlimited', PluginConfig::TEXTDOMAIN->value)) . '</td></tr>';
        echo '<tr><td>' . esc_html__('Digital Products:', PluginConfig::TEXTDOMAIN->value) . '</td><td>' . ($include_digital === 'yes' ? esc_html__('Included', PluginConfig::TEXTDOMAIN->value) : esc_html__('Excluded', PluginConfig::TEXTDOMAIN->value)) . '</td></tr>';
        echo '<tr><td>' . esc_html__('Debug Mode:', PluginConfig::TEXTDOMAIN->value) . '</td><td>' . ($debug_mode === 'yes' ? esc_html__('Enabled', PluginConfig::TEXTDOMAIN->value) : esc_html__('Disabled', PluginConfig::TEXTDOMAIN->value)) . '</td></tr>';
        echo '</table>';
    }

    private function render_calculation_preview(): void
    {
        $percentage = $this->get_option('percentage', 10.0);
        $minimum_fee = $this->get_option('minimum_fee', 5.0);
        $maximum_fee = $this->get_option('maximum_fee', 100.0);
        
        echo '<div class="wc-percentage-shipping-preview">';
        echo '<h4>' . esc_html__('Example calculation:', PluginConfig::TEXTDOMAIN->value) . '</h4>';
        echo '<div class="preview-example">';
        echo '<p><strong>' . esc_html__('Cart value:', PluginConfig::TEXTDOMAIN->value) . '</strong> ' . wp_kses_post(wc_price(50)) . '</p>';
        echo '<p><strong>' . esc_html__('Calculation:', PluginConfig::TEXTDOMAIN->value) . '</strong> ' . wp_kses_post(wc_price(50)) . ' × ' . esc_html($percentage) . '% = ' . wp_kses_post(wc_price(50 * $percentage / 100)) . '</p>';
        
        $calculated = 50 * $percentage / 100;
        if ($minimum_fee > 0 && $calculated < $minimum_fee) {
            echo '<p><strong>' . esc_html__('Minimum fee applied:', PluginConfig::TEXTDOMAIN->value) . '</strong> ' . wp_kses_post(wc_price($minimum_fee)) . '</p>';
        } elseif ($maximum_fee > 0 && $calculated > $maximum_fee) {
            echo '<p><strong>' . esc_html__('Maximum fee applied:', PluginConfig::TEXTDOMAIN->value) . '</strong> ' . wp_kses_post(wc_price($maximum_fee)) . '</p>';
        } else {
            echo '<p><strong>' . esc_html__('Final fee:', PluginConfig::TEXTDOMAIN->value) . '</strong> ' . wp_kses_post(wc_price($calculated)) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    public function sanitize_options(array $input): array
    {
        $output = [];
        
        $output['enabled'] = isset($input['enabled']) && $input['enabled'] === 'yes' ? 'yes' : 'no';
        
        $percentage = isset($input['percentage']) ? floatval($input['percentage']) : 10.0;
        $output['percentage'] = max(0.0, min(100.0, $percentage));
        
        $minimum_fee = isset($input['minimum_fee']) ? floatval($input['minimum_fee']) : 0.0;
        $output['minimum_fee'] = max(0.0, $minimum_fee);
        
        $maximum_fee = isset($input['maximum_fee']) ? floatval($input['maximum_fee']) : 0.0;
        $output['maximum_fee'] = max(0.0, $maximum_fee);
        
        $output['include_digital_products'] = isset($input['include_digital_products']) && $input['include_digital_products'] === 'yes' ? 'yes' : 'no';
        
        $excluded_categories = isset($input['excluded_categories']) ? (array) $input['excluded_categories'] : [];
        $output['excluded_categories'] = array_map('intval', array_filter($excluded_categories, 'is_numeric'));
        
        $output['debug_mode'] = isset($input['debug_mode']) && $input['debug_mode'] === 'yes' ? 'yes' : 'no';
        
        if ($output['maximum_fee'] > 0 && $output['maximum_fee'] < $output['minimum_fee']) {
            add_settings_error(
                PluginConfig::OPTION_NAME->value,
                'fee_mismatch',
                __('Maximum fee must be higher than minimum fee.', PluginConfig::TEXTDOMAIN->value)
            );
            $output['maximum_fee'] = $output['minimum_fee'];
        }
        
        return $output;
    }

    public function settings_link(array $links): array
    {
        $links[] = '<a href="admin.php?page=' . PluginConfig::PLUGIN_SLUG->value . '">' . esc_html__('Settings', PluginConfig::TEXTDOMAIN->value) . '</a>';
        return $links;
    }

    public function include_shipping_method(): void
    {
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-method.php';
    }

    public function register_shipping_method(array $methods): array
    {
        $methods['percentage_shipping'] = 'WC_Percentage_Shipping_Method';
        return $methods;
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if ('woocommerce_page_' . PluginConfig::PLUGIN_SLUG->value !== $hook) {
            return;
        }
        
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'wc-percentage-shipping-admin',
            $this->plugin_url . 'assets/admin.css',
            [],
            PluginConfig::VERSION->value
        );
        
        wp_enqueue_script(
            'wc-percentage-shipping-admin',
            $this->plugin_url . 'assets/admin.js',
            [],
            PluginConfig::VERSION->value,
            true
        );
        
        wp_localize_script(
            'wc-percentage-shipping-admin',
            'wcPercentageShipping',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_percentage_shipping_preview'),
                'strings' => [
                    'cartValue' => __('Cart value:', PluginConfig::TEXTDOMAIN->value),
                    'calculation' => __('Calculation:', PluginConfig::TEXTDOMAIN->value),
                    'finalFee' => __('Final fee:', PluginConfig::TEXTDOMAIN->value),
                    'percentageError' => __('Percentage must be between 0 and 100.', PluginConfig::TEXTDOMAIN->value),
                    'feeError' => __('Maximum fee must be higher than minimum fee.', PluginConfig::TEXTDOMAIN->value),
                ],
            ]
        );
    }

    public function ajax_preview_calculation(): void
    {
        if (!$this->check_rate_limit()) {
            wp_send_json_error(['message' => __('Too many requests. Please try again later.', PluginConfig::TEXTDOMAIN->value)]);
        }

        if (!current_user_can(PluginSecurity::CAPABILITY->value)) {
            wp_send_json_error(['message' => __('Insufficient permissions.', PluginConfig::TEXTDOMAIN->value)]);
        }

        check_ajax_referer('wc_percentage_shipping_preview', 'nonce');
        
        $cart_value = isset($_POST['cart_value']) ? max(0, floatval($_POST['cart_value'])) : 0;
        $percentage = isset($_POST['percentage']) ? max(0, min(100, floatval($_POST['percentage']))) : 10;
        $minimum_fee = isset($_POST['minimum_fee']) ? max(0, floatval($_POST['minimum_fee'])) : 0;
        $maximum_fee = isset($_POST['maximum_fee']) ? max(0, floatval($_POST['maximum_fee'])) : 0;
        
        $calculated = $cart_value * ($percentage / 100);
        
        $final_cost = match (true) {
            $minimum_fee > 0 && $calculated < $minimum_fee => $minimum_fee,
            $maximum_fee > 0 && $calculated > $maximum_fee => $maximum_fee,
            default => $calculated
        };
        
        wp_send_json_success([
            'calculated' => wc_price($calculated),
            'final_cost' => wc_price($final_cost),
            'explanation' => sprintf(
                '%s × %s%% = %s',
                wc_price($cart_value),
                $percentage,
                wc_price($final_cost)
            ),
        ]);
    }

    private function check_rate_limit(): bool
    {
        $user_id = get_current_user_id();
        $key = 'wc_percentage_shipping_rate_limit_' . $user_id;
        $requests = get_transient($key) ?: 0;
        $rate_limit = (int) PluginSecurity::AJAX_RATE_LIMIT->value;
        
        if ($requests >= $rate_limit) {
            return false;
        }
        
        set_transient($key, $requests + 1, 60);
        return true;
    }

    public function add_security_headers(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    public function cleanup_rate_limiting(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_percentage_shipping_rate_limit_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wc_percentage_shipping_rate_limit_%'");
    }
}

// Initialize the plugin
WC_Percentage_Shipping_Plugin::get_instance();
