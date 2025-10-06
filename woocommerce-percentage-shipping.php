<?php

declare(strict_types=1);

/**
 * Plugin Name: WooCommerce Percentage Shipping
 * Description: Calculate shipping costs as a percentage of physical products with modern architecture
 * Version: 1.5.1
 * Author: Tobias Haas
 * Text Domain: wc-percentage-shipping
 * Domain Path: /languages
 * Requires at least: 6.8
 * Tested up to: 6.8
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 10.0
 * WC tested up to: 10.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network: false
 * Update URI: https://github.com/tobiashaas/Woo-Percentage-Shipping
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

// WooCommerce dependency is now handled by WordPress via 'Requires Plugins' header
// This provides better UX and prevents installation without WooCommerce

// GitHub Auto-Updater - Simple Implementation
require_once plugin_dir_path(__FILE__) . 'includes/class-wc-percentage-shipping-updater.php';

add_action('init', function() {
    if (is_admin() && current_user_can('update_plugins')) {
        new WC_Percentage_Shipping_Updater();
    }
});

// Check PHP version
if (version_compare(PHP_VERSION, '8.3', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('WooCommerce Percentage Shipping requires PHP 8.3 or higher.', 'wc-percentage-shipping');
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
    case VERSION = '1.5.1';
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
        add_action('woocommerce_settings_saved', [$this, 'clear_cache_on_settings_save']);
        add_action('wp_scheduled_delete', [$this, 'cleanup_old_logs']);
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
            'calculation_method' => __('Calculation Method', PluginConfig::TEXTDOMAIN->value),
            'tiered_rules' => __('Tiered Pricing Rules', PluginConfig::TEXTDOMAIN->value),
            'free_shipping_threshold' => __('Free Shipping Threshold', PluginConfig::TEXTDOMAIN->value),
            'flat_rate_addition' => __('Flat Rate Addition', PluginConfig::TEXTDOMAIN->value),
            'tax_inclusive' => __('Tax Inclusive', PluginConfig::TEXTDOMAIN->value),
            'include_digital_products' => __('Include Digital Products', PluginConfig::TEXTDOMAIN->value),
            'excluded_categories' => __('Excluded Categories', PluginConfig::TEXTDOMAIN->value),
            'included_tags' => __('Included Tags', PluginConfig::TEXTDOMAIN->value),
            'excluded_tags' => __('Excluded Tags', PluginConfig::TEXTDOMAIN->value),
            'included_attributes' => __('Included Attributes', PluginConfig::TEXTDOMAIN->value),
            'excluded_attributes' => __('Excluded Attributes', PluginConfig::TEXTDOMAIN->value),
            'included_skus' => __('Included SKUs', PluginConfig::TEXTDOMAIN->value),
            'excluded_skus' => __('Excluded SKUs', PluginConfig::TEXTDOMAIN->value),
            'stock_status' => __('Stock Status Filter', PluginConfig::TEXTDOMAIN->value),
            'weekend_surcharge' => __('Weekend/Holiday Surcharge', PluginConfig::TEXTDOMAIN->value),
            'customer_group_pricing' => __('Customer Group Pricing', PluginConfig::TEXTDOMAIN->value),
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
            'included_tags' => [],
            'excluded_tags' => [],
            'included_attributes' => [],
            'excluded_attributes' => [],
            'included_skus' => [],
            'excluded_skus' => [],
            'stock_status' => 'all', // all, instock, outofstock
            'calculation_method' => 'cart_total', // cart_total, per_product, tiered
            'tiered_rules' => [],
            'free_shipping_threshold' => 0,
            'flat_rate_addition' => 0,
            'weekend_surcharge' => 0,
            'customer_group_pricing' => [],
            'tax_inclusive' => 'no',
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
            <?php settings_errors(); ?>
            
            <div class="admin-layout">
                <!-- Vertical Sidebar -->
                <div class="admin-sidebar">
                    <!-- Logo -->
                    <div class="sidebar-header">
                        <div class="plugin-logo">
                            <span class="logo-text">Woo Percentage Shipping</span>
                            <span class="version-badge">v<?php echo esc_html(PluginConfig::VERSION->value); ?></span>
                </div>
            </div>

                    <!-- Search -->
                    <div class="sidebar-search">
                        <input type="text" 
                               id="settings-search" 
                               placeholder="Quick search..." 
                               class="search-input">
                        <span class="search-icon dashicons dashicons-search"></span>
                        <span class="search-shortcut">CtrlK</span>
                    </div>
                    
                    <!-- Navigation Sections -->
                    <nav class="sidebar-nav">
                        <div class="nav-section" data-section="general">
                            <div class="nav-section-header">
                                <span class="nav-icon dashicons dashicons-admin-settings"></span>
                                <span class="nav-title">General</span>
                                <span class="nav-toggle dashicons dashicons-arrow-up-alt2"></span>
                            </div>
                            <div class="nav-section-content">
                                <a href="#" class="nav-item active" data-tab="basic-settings">
                                    <?php echo esc_html__('Basic Settings', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="digital-products">
                                    <?php echo esc_html__('Digital Products', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="nav-section" data-section="calculation">
                            <div class="nav-section-header">
                                <span class="nav-icon dashicons dashicons-calculator"></span>
                                <span class="nav-title">Calculation</span>
                                <span class="nav-toggle dashicons dashicons-arrow-down-alt2"></span>
                            </div>
                            <div class="nav-section-content" style="display: none;">
                                <a href="#" class="nav-item" data-tab="calculation-method">
                                    <?php echo esc_html__('Calculation Method', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="tiered-pricing">
                                    <?php echo esc_html__('Tiered Pricing', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="tax-settings">
                                    <?php echo esc_html__('Tax Settings', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="nav-section" data-section="filters">
                            <div class="nav-section-header">
                                <span class="nav-icon dashicons dashicons-filter"></span>
                                <span class="nav-title">Product Filters</span>
                                <span class="nav-toggle dashicons dashicons-arrow-down-alt2"></span>
                            </div>
                            <div class="nav-section-content" style="display: none;">
                                <a href="#" class="nav-item" data-tab="categories">
                                    <?php echo esc_html__('Categories', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="tags">
                                    <?php echo esc_html__('Tags', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="skus">
                                    <?php echo esc_html__('SKUs', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="stock-status">
                                    <?php echo esc_html__('Stock Status', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="nav-section" data-section="pricing">
                            <div class="nav-section-header">
                                <span class="nav-icon dashicons dashicons-money-alt"></span>
                                <span class="nav-title">Pricing</span>
                                <span class="nav-toggle dashicons dashicons-arrow-down-alt2"></span>
                            </div>
                            <div class="nav-section-content" style="display: none;">
                                <a href="#" class="nav-item" data-tab="fee-limits">
                                    <?php echo esc_html__('Fee Limits', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="free-shipping">
                                    <?php echo esc_html__('Free Shipping', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="surcharges">
                                    <?php echo esc_html__('Surcharges', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="customer-groups">
                                    <?php echo esc_html__('Customer Groups', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                            </div>
                        </div>
                        
                        <div class="nav-section" data-section="tools">
                            <div class="nav-section-header">
                                <span class="nav-icon dashicons dashicons-admin-tools"></span>
                                <span class="nav-title">Tools</span>
                                <span class="nav-toggle dashicons dashicons-arrow-down-alt2"></span>
                            </div>
                            <div class="nav-section-content" style="display: none;">
                                <a href="#" class="nav-item" data-tab="preview">
                                    <?php echo esc_html__('Live Preview', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="analytics">
                                    <?php echo esc_html__('Analytics', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                                <a href="#" class="nav-item" data-tab="system-info">
                                    <?php echo esc_html__('System Info', PluginConfig::TEXTDOMAIN->value); ?>
                                </a>
                            </div>
                        </div>
                    </nav>
                </div>
                
                <!-- Main Content Area -->
                <div class="admin-content">
                    <form method="post" action="" class="settings-form" id="settings-form">
                        <?php 
                        wp_nonce_field(PluginSecurity::NONCE_ACTION->value, PluginSecurity::NONCE_FIELD->value);
                        settings_fields('wc_percentage_shipping_settings');
                        ?>
                        
                        <!-- Tab Contents -->
                        <div class="tab-content active" id="tab-basic-settings">
                            <?php $this->render_basic_settings(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-digital-products">
                            <?php $this->render_digital_products(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-calculation-method">
                            <?php $this->render_calculation_method(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-tiered-pricing">
                            <?php $this->render_tiered_pricing(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-tax-settings">
                            <?php $this->render_tax_settings(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-categories">
                            <?php $this->render_categories(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-tags">
                            <?php $this->render_tags(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-skus">
                            <?php $this->render_skus(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-stock-status">
                            <?php $this->render_stock_status(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-fee-limits">
                            <?php $this->render_fee_limits(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-free-shipping">
                            <?php $this->render_free_shipping(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-surcharges">
                            <?php $this->render_surcharges(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-customer-groups">
                            <?php $this->render_customer_groups(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-preview">
                            <?php $this->render_preview_tab(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-analytics">
                            <?php $this->render_analytics_tab(); ?>
                        </div>
                        
                        <div class="tab-content" id="tab-system-info">
                            <?php $this->render_system_tab(); ?>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="button-primary" id="save-settings">
                                <?php echo esc_html__('Save Changes', PluginConfig::TEXTDOMAIN->value); ?>
                            </button>
                            <button type="button" class="button-secondary" id="reset-settings">
                                <?php echo esc_html__('Reset to Defaults', PluginConfig::TEXTDOMAIN->value); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_tab_icon(string $tab): string
    {
        return match ($tab) {
            'general' => 'admin-generic',
            'advanced' => 'admin-tools',
            'performance' => 'performance',
            'security' => 'shield',
            default => 'admin-generic'
        };
    }

    private function render_status_indicator(): void
    {
        $enabled = $this->get_option('enabled', 'yes');
        $status_class = $enabled === 'yes' ? 'status-active' : 'status-inactive';
        $status_text = $enabled === 'yes' ? __('Active', PluginConfig::TEXTDOMAIN->value) : __('Inactive', PluginConfig::TEXTDOMAIN->value);
        ?>
        <div class="status-indicator <?php echo esc_attr($status_class); ?>">
            <span class="status-dot"></span>
            <span class="status-text"><?php echo esc_html($status_text); ?></span>
                </div>
        <?php
    }

    private function render_basic_settings(): void
    {
        ?>
        <h1><?php echo esc_html__('Basic Settings', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure the fundamental shipping calculation settings.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="enabled"><?php echo esc_html__('Enable Plugin', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <label class="switch">
                        <input type="checkbox" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[enabled]" 
                               id="enabled"
                               value="yes" 
                               <?php checked($this->get_option('enabled'), 'yes'); ?>>
                        <span class="slider"></span>
                    </label>
                    <p class="description"><?php echo esc_html__('Turn the percentage shipping calculation on or off', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="percentage"><?php echo esc_html__('Shipping Percentage', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-suffix">
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[percentage]" 
                               id="percentage"
                               value="<?php echo esc_attr($this->get_option('percentage', 10)); ?>" 
                               min="0" 
                               max="100" 
                               step="0.1"
                               class="regular-text"
                               required>
                        <span class="suffix">%</span>
                </div>
                    <p class="description"><?php echo esc_html__('Percentage of cart value to charge as shipping (0-100%)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="minimum_fee"><?php echo esc_html__('Minimum Fee', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[minimum_fee]" 
                               id="minimum_fee"
                               value="<?php echo esc_attr($this->get_option('minimum_fee', 5)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
            </div>
                    <p class="description"><?php echo esc_html__('Lowest shipping cost regardless of percentage calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="maximum_fee"><?php echo esc_html__('Maximum Fee', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[maximum_fee]" 
                               id="maximum_fee"
                               value="<?php echo esc_attr($this->get_option('maximum_fee', 100)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
                    </div>
                    <p class="description"><?php echo esc_html__('Highest shipping cost cap (0 = unlimited)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_digital_products(): void
    {
        ?>
        <h1><?php echo esc_html__('Digital Products', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure how virtual and downloadable products are handled in shipping calculations.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="include_digital_products"><?php echo esc_html__('Include Digital Products', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <label class="switch">
                        <input type="checkbox" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[include_digital_products]" 
                               id="include_digital_products"
                               value="yes" 
                               <?php checked($this->get_option('include_digital_products'), 'yes'); ?>>
                        <span class="slider"></span>
                    </label>
                    <p class="description"><?php echo esc_html__('Calculate shipping for virtual and downloadable products', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }


    private function render_filter_settings(): void
    {
        ?>
        <div class="settings-grid">
            <div class="setting-card">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Digital Products', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Include virtual and downloadable products in shipping calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <label class="switch">
                        <input type="checkbox" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[include_digital_products]" 
                               value="yes" 
                               <?php checked($this->get_option('include_digital_products'), 'yes'); ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <div class="setting-card">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Stock Status Filter', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Filter products by their stock status', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <select name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[stock_status]" class="form-input">
                        <option value="all" <?php selected($this->get_option('stock_status'), 'all'); ?>>
                            <?php echo esc_html__('All Products', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="instock" <?php selected($this->get_option('stock_status'), 'instock'); ?>>
                            <?php echo esc_html__('In Stock Only', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="outofstock" <?php selected($this->get_option('stock_status'), 'outofstock'); ?>>
                            <?php echo esc_html__('Out of Stock Only', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                    </select>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Excluded Categories', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Select product categories to exclude from shipping calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <?php $this->render_category_selector(); ?>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Included Product Tags', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Only include products with these tags (leave empty for all)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <?php $this->render_tag_selector('included_tags'); ?>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Excluded Product Tags', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Exclude products with these tags', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <?php $this->render_tag_selector('excluded_tags'); ?>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Included SKUs', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Only include these specific product SKUs (one per line)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <textarea name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[included_skus]" class="form-input" rows="4" placeholder="<?php echo esc_attr__('SKU-001&#10;SKU-002&#10;SKU-003', PluginConfig::TEXTDOMAIN->value); ?>"><?php echo esc_textarea(implode("\n", $this->get_option('included_skus', []))); ?></textarea>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Excluded SKUs', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Exclude these specific product SKUs (one per line)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <textarea name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[excluded_skus]" class="form-input" rows="4" placeholder="<?php echo esc_attr__('SKU-001&#10;SKU-002&#10;SKU-003', PluginConfig::TEXTDOMAIN->value); ?>"><?php echo esc_textarea(implode("\n", $this->get_option('excluded_skus', []))); ?></textarea>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_pricing_settings(): void
    {
        ?>
        <div class="settings-grid">
            <div class="setting-card">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Free Shipping Threshold', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Minimum cart value for free shipping (0 = disabled)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[free_shipping_threshold]" 
                               value="<?php echo esc_attr($this->get_option('free_shipping_threshold', 0)); ?>" 
                               min="0" 
                               step="0.01"
                               class="form-input">
                    </div>
                </div>
            </div>

            <div class="setting-card">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Flat Rate Addition', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Additional fixed amount added to percentage calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[flat_rate_addition]" 
                               value="<?php echo esc_attr($this->get_option('flat_rate_addition', 0)); ?>" 
                               min="0" 
                               step="0.01"
                               class="form-input">
                    </div>
                </div>
            </div>

            <div class="setting-card">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Weekend/Holiday Surcharge', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Additional percentage charged on weekends and holidays', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <div class="input-with-suffix">
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[weekend_surcharge]" 
                               value="<?php echo esc_attr($this->get_option('weekend_surcharge', 0)); ?>" 
                               min="0" 
                               max="100"
                               step="0.1"
                               class="form-input">
                        <span class="suffix">%</span>
                    </div>
                </div>
            </div>

            <div class="setting-card full-width">
                <div class="setting-header">
                    <h3><?php echo esc_html__('Customer Group Pricing', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <p><?php echo esc_html__('Set different shipping percentages for different customer groups', PluginConfig::TEXTDOMAIN->value); ?></p>
                </div>
                <div class="setting-control">
                    <div class="customer-groups">
                        <div class="group-template" style="display: none;">
                            <div class="group-row">
                                <select class="form-input" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[customer_group_pricing][{{index}}][group]">
                                    <option value="guest"><?php echo esc_html__('Guest Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                    <option value="customer"><?php echo esc_html__('Regular Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                    <option value="wholesale"><?php echo esc_html__('Wholesale Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                </select>
                                <input type="number" placeholder="<?php echo esc_attr__('Percentage', PluginConfig::TEXTDOMAIN->value); ?>" class="form-input" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[customer_group_pricing][{{index}}][percentage]" step="0.1" min="0" max="100">
                                <button type="button" class="button remove-group"><?php echo esc_html__('Remove', PluginConfig::TEXTDOMAIN->value); ?></button>
                            </div>
                        </div>
                        <div class="existing-groups">
                            <?php 
                            $groups = $this->get_option('customer_group_pricing', []);
                            foreach ($groups as $index => $group): 
                            ?>
                            <div class="group-row">
                                <select class="form-input" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[customer_group_pricing][<?php echo esc_attr($index); ?>][group]">
                                    <option value="guest" <?php selected($group['group'] ?? '', 'guest'); ?>><?php echo esc_html__('Guest Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                    <option value="customer" <?php selected($group['group'] ?? '', 'customer'); ?>><?php echo esc_html__('Regular Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                    <option value="wholesale" <?php selected($group['group'] ?? '', 'wholesale'); ?>><?php echo esc_html__('Wholesale Customers', PluginConfig::TEXTDOMAIN->value); ?></option>
                                </select>
                                <input type="number" value="<?php echo esc_attr($group['percentage'] ?? ''); ?>" placeholder="<?php echo esc_attr__('Percentage', PluginConfig::TEXTDOMAIN->value); ?>" class="form-input" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[customer_group_pricing][<?php echo esc_attr($index); ?>][percentage]" step="0.1" min="0" max="100">
                                <button type="button" class="button remove-group"><?php echo esc_html__('Remove', PluginConfig::TEXTDOMAIN->value); ?></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-secondary" id="add-customer-group">
                            <?php echo esc_html__('Add Customer Group', PluginConfig::TEXTDOMAIN->value); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }




    private function render_performance_tab(): void
    {
        ?>
        <div class="tab-content">
            <div class="settings-section">
                <h3 class="section-title">
                    <span class="dashicons dashicons-performance"></span>
                    <?php echo esc_html__('Performance Settings', PluginConfig::TEXTDOMAIN->value); ?>
                </h3>
                <div class="settings-grid">
                    <div class="setting-group">
                        <label class="setting-label">
                            <?php echo esc_html__('Cache Status', PluginConfig::TEXTDOMAIN->value); ?>
                        </label>
                        <div class="setting-control">
                            <div class="cache-info">
                                <span class="cache-status"><?php echo esc_html__('Active', PluginConfig::TEXTDOMAIN->value); ?></span>
                                <span class="cache-details"><?php echo esc_html__('1 hour TTL', PluginConfig::TEXTDOMAIN->value); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="setting-group">
                        <label class="setting-label">
                            <?php echo esc_html__('Rate Limiting', PluginConfig::TEXTDOMAIN->value); ?>
                        </label>
                        <div class="setting-control">
                            <div class="rate-limit-info">
                                <span class="rate-limit-status"><?php echo esc_html__('30 requests/min', PluginConfig::TEXTDOMAIN->value); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_security_tab(): void
    {
        ?>
        <div class="tab-content">
            <div class="settings-section">
                <h3 class="section-title">
                    <span class="dashicons dashicons-shield"></span>
                    <?php echo esc_html__('Security Features', PluginConfig::TEXTDOMAIN->value); ?>
                </h3>
                <div class="security-features">
                    <div class="security-item">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php echo esc_html__('CSRF Protection', PluginConfig::TEXTDOMAIN->value); ?></span>
                    </div>
                    <div class="security-item">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php echo esc_html__('Input Validation', PluginConfig::TEXTDOMAIN->value); ?></span>
                    </div>
                    <div class="security-item">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php echo esc_html__('XSS Protection', PluginConfig::TEXTDOMAIN->value); ?></span>
                    </div>
                    <div class="security-item">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <span><?php echo esc_html__('Rate Limiting', PluginConfig::TEXTDOMAIN->value); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    private function render_live_preview(): void
    {
        ?>
        <div class="sidebar-widget">
            <h3 class="widget-title">
                <span class="dashicons dashicons-calculator"></span>
                <?php echo esc_html__('Live Preview', PluginConfig::TEXTDOMAIN->value); ?>
            </h3>
            <div class="preview-content">
                <div class="preview-example">
                    <p><strong><?php echo esc_html__('Cart value:', PluginConfig::TEXTDOMAIN->value); ?></strong> <?php echo get_woocommerce_currency_symbol(); ?>50.00</p>
                    <p><strong><?php echo esc_html__('Calculation:', PluginConfig::TEXTDOMAIN->value); ?></strong> <span id="calculation-preview"><?php echo get_woocommerce_currency_symbol(); ?>50.00 × 10% = <?php echo get_woocommerce_currency_symbol(); ?>5.00</span></p>
                    <p><strong><?php echo esc_html__('Final fee:', PluginConfig::TEXTDOMAIN->value); ?></strong> <span id="final-fee-preview"><?php echo get_woocommerce_currency_symbol(); ?>5.00</span></p>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_system_info(): void
    {
        ?>
        <div class="sidebar-widget">
            <h3 class="widget-title">
                <span class="dashicons dashicons-info"></span>
                <?php echo esc_html__('System Info', PluginConfig::TEXTDOMAIN->value); ?>
            </h3>
            <div class="system-info">
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html__('Plugin Version:', PluginConfig::TEXTDOMAIN->value); ?></span>
                    <span class="info-value"><?php echo esc_html(PluginConfig::VERSION->value); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html__('PHP Version:', PluginConfig::TEXTDOMAIN->value); ?></span>
                    <span class="info-value"><?php echo esc_html(PHP_VERSION); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html__('WordPress:', PluginConfig::TEXTDOMAIN->value); ?></span>
                    <span class="info-value"><?php echo esc_html(get_bloginfo('version')); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html__('WooCommerce:', PluginConfig::TEXTDOMAIN->value); ?></span>
                    <span class="info-value"><?php echo esc_html(WC()->version); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_quick_links(): void
    {
        ?>
        <div class="sidebar-widget">
            <h3 class="widget-title">
                <span class="dashicons dashicons-admin-links"></span>
                <?php echo esc_html__('Quick Links', PluginConfig::TEXTDOMAIN->value); ?>
            </h3>
            <div class="quick-links">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')); ?>" class="quick-link">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php echo esc_html__('Shipping Zones', PluginConfig::TEXTDOMAIN->value); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-reports&tab=logs')); ?>" class="quick-link">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php echo esc_html__('View Logs', PluginConfig::TEXTDOMAIN->value); ?>
                </a>
                <a href="https://github.com/tobiashaas/Woo-Percentage-Shipping" target="_blank" class="quick-link">
                    <span class="dashicons dashicons-external"></span>
                    <?php echo esc_html__('Documentation', PluginConfig::TEXTDOMAIN->value); ?>
                </a>
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
        return WC_Percentage_Shipping_Validator::sanitize_options($input);
    }

    public function settings_link(array $links): array
    {
        $links[] = '<a href="admin.php?page=' . PluginConfig::PLUGIN_SLUG->value . '">' . esc_html__('Settings', PluginConfig::TEXTDOMAIN->value) . '</a>';
        return $links;
    }

    public function include_shipping_method(): void
    {
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-method.php';
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-validator.php';
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-calculator.php';
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-cache.php';
        require_once $this->plugin_dir . 'includes/class-wc-percentage-shipping-logger.php';
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
        $start_time = microtime(true);
        
        try {
        if (!$this->check_rate_limit()) {
                WC_Percentage_Shipping_Logger::log_security('Rate limit exceeded', [
                    'user_id' => get_current_user_id(),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            wp_send_json_error(['message' => __('Too many requests. Please try again later.', PluginConfig::TEXTDOMAIN->value)]);
        }

        if (!current_user_can(PluginSecurity::CAPABILITY->value)) {
                WC_Percentage_Shipping_Logger::log_security('Insufficient permissions', [
                    'user_id' => get_current_user_id(),
                    'capability_required' => PluginSecurity::CAPABILITY->value
                ]);
            wp_send_json_error(['message' => __('Insufficient permissions.', PluginConfig::TEXTDOMAIN->value)]);
        }

        check_ajax_referer('wc_percentage_shipping_preview', 'nonce');
        
            $params = WC_Percentage_Shipping_Validator::validate_ajax_params($_POST);
            $result = WC_Percentage_Shipping_Calculator::calculate_preview(
                $params['cart_value'],
                $params['percentage'],
                $params['minimum_fee'],
                $params['maximum_fee']
            );
            
            $execution_time = (microtime(true) - $start_time) * 1000;
            
            WC_Percentage_Shipping_Logger::log_performance('AJAX preview calculation', $execution_time, [
                'cart_value' => $params['cart_value'],
                'percentage' => $params['percentage']
            ]);
        
        wp_send_json_success([
                'calculated' => wc_price($result['calculated']),
                'final_cost' => wc_price($result['final_cost']),
                'explanation' => $result['explanation'],
            ]);
            
        } catch (InvalidArgumentException $e) {
            WC_Percentage_Shipping_Logger::error('AJAX validation error', [
                'error_message' => $e->getMessage(),
                'input_data' => $_POST
            ]);
            wp_send_json_error(['message' => $e->getMessage()]);
            
        } catch (Exception $e) {
            WC_Percentage_Shipping_Logger::error('AJAX calculation error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'execution_time_ms' => (microtime(true) - $start_time) * 1000
            ]);
            wp_send_json_error(['message' => __('An error occurred during calculation. Please try again.', PluginConfig::TEXTDOMAIN->value)]);
        }
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

    public function clear_cache_on_settings_save(): void
    {
        // Only clear cache if our plugin settings were saved
        if (isset($_POST[PluginConfig::OPTION_NAME->value])) {
            WC_Percentage_Shipping_Cache::clear_all();
            WC_Percentage_Shipping_Logger::info('Cache cleared due to settings change');
        }
    }

    public function cleanup_old_logs(): void
    {
        WC_Percentage_Shipping_Logger::cleanup_old_logs(30); // Keep logs for 30 days
    }

    public function clear_update_cache(): void
    {
        // Clear WordPress update cache
        delete_site_transient('update_plugins');
        delete_transient('wc_percentage_shipping_update_check');
        delete_transient('wc_percentage_shipping_update_info');
        
        // Force refresh of plugin data
        wp_cache_delete('plugins', 'plugins');
    }

    // Helper methods for rendering tab content
    private function render_category_selector(): void
    {
        $excluded_categories = $this->get_option('excluded_categories', []);
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($categories)) {
            echo '<p>' . esc_html__('No product categories found.', PluginConfig::TEXTDOMAIN->value) . '</p>';
            return;
        }
        ?>
        <select name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[excluded_categories][]" 
                class="form-input" 
                multiple 
                style="height: 120px;">
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo esc_attr($category->term_id); ?>" 
                        <?php selected(in_array($category->term_id, $excluded_categories), true); ?>>
                    <?php echo esc_html($category->name); ?> (<?php echo esc_html($category->count); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function render_tag_selector(string $field_name): void
    {
        $selected_tags = $this->get_option($field_name, []);
        $tags = get_terms([
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($tags)) {
            echo '<p>' . esc_html__('No product tags found.', PluginConfig::TEXTDOMAIN->value) . '</p>';
            return;
        }
        ?>
        <select name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[<?php echo esc_attr($field_name); ?>][]" 
                class="form-input" 
                multiple 
                style="height: 120px;">
            <?php foreach ($tags as $tag): ?>
                <option value="<?php echo esc_attr($tag->term_id); ?>" 
                        <?php selected(in_array($tag->term_id, $selected_tags), true); ?>>
                    <?php echo esc_html($tag->name); ?> (<?php echo esc_html($tag->count); ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private function get_shipping_statistics(): array
    {
        // Get statistics from cache or calculate
        $stats = get_transient('wc_percentage_shipping_stats');
        
        if (false === $stats) {
            $stats = [
                'total_calculations' => 0,
                'average_cost' => '0.00',
                'cache_hit_rate' => 0,
                'avg_calculation_time' => 0,
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'db_queries' => get_num_queries(),
                'total_orders' => 0,
                'total_shipping' => 0,
                'average_shipping' => 0,
            ];
            
            set_transient('wc_percentage_shipping_stats', $stats, HOUR_IN_SECONDS);
        }
        
        return $stats;
    }

    private function render_calculation_method(): void
    {
        ?>
        <h1><?php echo esc_html__('Calculation Method', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Choose how shipping costs are calculated.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="calculation_method"><?php echo esc_html__('Calculation Method', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <select name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[calculation_method]" id="calculation_method" class="regular-text">
                        <option value="cart_total" <?php selected($this->get_option('calculation_method'), 'cart_total'); ?>>
                            <?php echo esc_html__('Cart Total (Default)', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="per_product" <?php selected($this->get_option('calculation_method'), 'per_product'); ?>>
                            <?php echo esc_html__('Per Product', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="tiered" <?php selected($this->get_option('calculation_method'), 'tiered'); ?>>
                            <?php echo esc_html__('Tiered Pricing', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                    </select>
                    <p class="description"><?php echo esc_html__('How to calculate shipping costs based on cart contents', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tax_inclusive"><?php echo esc_html__('Tax Inclusive', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <label class="switch">
                        <input type="checkbox" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tax_inclusive]" 
                               id="tax_inclusive"
                               value="yes" 
                               <?php checked($this->get_option('tax_inclusive'), 'yes'); ?>>
                        <span class="slider"></span>
                    </label>
                    <p class="description"><?php echo esc_html__('Calculate shipping based on tax-inclusive prices', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_tiered_pricing(): void
    {
        ?>
        <h1><?php echo esc_html__('Tiered Pricing', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Define different percentages for different cart value ranges.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php echo esc_html__('Tiered Rules', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="tiered-rules">
                        <div class="rule-template" style="display: none;">
                            <div class="rule-row">
                                <input type="number" placeholder="<?php echo esc_attr__('Min Value', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][{{index}}][min]" step="0.01">
                                <input type="number" placeholder="<?php echo esc_attr__('Max Value', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][{{index}}][max]" step="0.01">
                                <input type="number" placeholder="<?php echo esc_attr__('Percentage', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][{{index}}][percentage]" step="0.1" min="0" max="100">
                                <button type="button" class="button remove-rule"><?php echo esc_html__('Remove', PluginConfig::TEXTDOMAIN->value); ?></button>
                            </div>
                        </div>
                        <div class="existing-rules">
                            <?php 
                            $rules = $this->get_option('tiered_rules', []);
                            foreach ($rules as $index => $rule): 
                            ?>
                            <div class="rule-row">
                                <input type="number" value="<?php echo esc_attr($rule['min'] ?? ''); ?>" placeholder="<?php echo esc_attr__('Min Value', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][<?php echo esc_attr($index); ?>][min]" step="0.01">
                                <input type="number" value="<?php echo esc_attr($rule['max'] ?? ''); ?>" placeholder="<?php echo esc_attr__('Max Value', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][<?php echo esc_attr($index); ?>][max]" step="0.01">
                                <input type="number" value="<?php echo esc_attr($rule['percentage'] ?? ''); ?>" placeholder="<?php echo esc_attr__('Percentage', PluginConfig::TEXTDOMAIN->value); ?>" class="regular-text" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tiered_rules][<?php echo esc_attr($index); ?>][percentage]" step="0.1" min="0" max="100">
                                <button type="button" class="button remove-rule"><?php echo esc_html__('Remove', PluginConfig::TEXTDOMAIN->value); ?></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-secondary" id="add-tiered-rule">
                            <?php echo esc_html__('Add Rule', PluginConfig::TEXTDOMAIN->value); ?>
                        </button>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_tax_settings(): void
    {
        ?>
        <h1><?php echo esc_html__('Tax Settings', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure how taxes are handled in shipping calculations.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="tax_inclusive"><?php echo esc_html__('Tax Inclusive Calculation', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <label class="switch">
                        <input type="checkbox" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[tax_inclusive]" 
                               id="tax_inclusive"
                               value="yes" 
                               <?php checked($this->get_option('tax_inclusive'), 'yes'); ?>>
                        <span class="slider"></span>
                    </label>
                    <p class="description"><?php echo esc_html__('Calculate shipping based on tax-inclusive prices instead of tax-exclusive', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_categories(): void
    {
        ?>
        <h1><?php echo esc_html__('Product Categories', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure which product categories to include or exclude from shipping calculations.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php echo esc_html__('Excluded Categories', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <?php $this->render_category_selector(); ?>
                    <p class="description"><?php echo esc_html__('Select product categories to exclude from shipping calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_tags(): void
    {
        ?>
        <h1><?php echo esc_html__('Product Tags', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure which product tags to include or exclude from shipping calculations.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php echo esc_html__('Included Tags', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <?php $this->render_tag_selector('included_tags'); ?>
                    <p class="description"><?php echo esc_html__('Only include products with these tags (leave empty for all)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label><?php echo esc_html__('Excluded Tags', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <?php $this->render_tag_selector('excluded_tags'); ?>
                    <p class="description"><?php echo esc_html__('Exclude products with these tags', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_skus(): void
    {
        ?>
        <h1><?php echo esc_html__('Product SKUs', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure which specific product SKUs to include or exclude from shipping calculations.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="included_skus"><?php echo esc_html__('Included SKUs', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <textarea name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[included_skus]" id="included_skus" class="large-text" rows="4" placeholder="<?php echo esc_attr__('SKU-001&#10;SKU-002&#10;SKU-003', PluginConfig::TEXTDOMAIN->value); ?>"><?php echo esc_textarea(implode("\n", $this->get_option('included_skus', []))); ?></textarea>
                    <p class="description"><?php echo esc_html__('Only include these specific product SKUs (one per line)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="excluded_skus"><?php echo esc_html__('Excluded SKUs', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <textarea name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[excluded_skus]" id="excluded_skus" class="large-text" rows="4" placeholder="<?php echo esc_attr__('SKU-001&#10;SKU-002&#10;SKU-003', PluginConfig::TEXTDOMAIN->value); ?>"><?php echo esc_textarea(implode("\n", $this->get_option('excluded_skus', []))); ?></textarea>
                    <p class="description"><?php echo esc_html__('Exclude these specific product SKUs (one per line)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_stock_status(): void
    {
        ?>
        <h1><?php echo esc_html__('Stock Status', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure which products to include based on their stock status.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="stock_status"><?php echo esc_html__('Stock Status Filter', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <select name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[stock_status]" id="stock_status" class="regular-text">
                        <option value="all" <?php selected($this->get_option('stock_status'), 'all'); ?>>
                            <?php echo esc_html__('All Products', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="instock" <?php selected($this->get_option('stock_status'), 'instock'); ?>>
                            <?php echo esc_html__('In Stock Only', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                        <option value="outofstock" <?php selected($this->get_option('stock_status'), 'outofstock'); ?>>
                            <?php echo esc_html__('Out of Stock Only', PluginConfig::TEXTDOMAIN->value); ?>
                        </option>
                    </select>
                    <p class="description"><?php echo esc_html__('Filter products by their stock status', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_fee_limits(): void
    {
        ?>
        <h1><?php echo esc_html__('Fee Limits', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Set minimum and maximum shipping fees to control costs.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="minimum_fee"><?php echo esc_html__('Minimum Fee', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[minimum_fee]" 
                               id="minimum_fee"
                               value="<?php echo esc_attr($this->get_option('minimum_fee', 5)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
                    </div>
                    <p class="description"><?php echo esc_html__('Lowest shipping cost regardless of percentage calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="maximum_fee"><?php echo esc_html__('Maximum Fee', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[maximum_fee]" 
                               id="maximum_fee"
                               value="<?php echo esc_attr($this->get_option('maximum_fee', 100)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
                    </div>
                    <p class="description"><?php echo esc_html__('Highest shipping cost cap (0 = unlimited)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_free_shipping(): void
    {
        ?>
        <h1><?php echo esc_html__('Free Shipping', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure free shipping thresholds and conditions.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="free_shipping_threshold"><?php echo esc_html__('Free Shipping Threshold', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[free_shipping_threshold]" 
                               id="free_shipping_threshold"
                               value="<?php echo esc_attr($this->get_option('free_shipping_threshold', 0)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
                    </div>
                    <p class="description"><?php echo esc_html__('Minimum cart value for free shipping (0 = disabled)', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_surcharges(): void
    {
        ?>
        <h1><?php echo esc_html__('Surcharges', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure additional charges and surcharges.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="flat_rate_addition"><?php echo esc_html__('Flat Rate Addition', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-prefix">
                        <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[flat_rate_addition]" 
                               id="flat_rate_addition"
                               value="<?php echo esc_attr($this->get_option('flat_rate_addition', 0)); ?>" 
                               min="0" 
                               step="0.01"
                               class="regular-text">
                    </div>
                    <p class="description"><?php echo esc_html__('Additional fixed amount added to percentage calculation', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="weekend_surcharge"><?php echo esc_html__('Weekend/Holiday Surcharge', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <div class="input-with-suffix">
                        <input type="number" 
                               name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[weekend_surcharge]" 
                               id="weekend_surcharge"
                               value="<?php echo esc_attr($this->get_option('weekend_surcharge', 0)); ?>" 
                               min="0" 
                               max="100"
                               step="0.1"
                               class="regular-text">
                        <span class="suffix">%</span>
                    </div>
                    <p class="description"><?php echo esc_html__('Additional percentage charged on weekends and holidays', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_customer_groups(): void
    {
        ?>
        <h1><?php echo esc_html__('Customer Groups', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Configure different shipping rates for different customer groups.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php echo esc_html__('Customer Group Pricing', PluginConfig::TEXTDOMAIN->value); ?></label>
                </th>
                <td>
                    <p class="description"><?php echo esc_html__('Customer group pricing feature coming soon. This will allow setting different shipping percentages for different customer groups (e.g., wholesale customers, VIP customers).', PluginConfig::TEXTDOMAIN->value); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private function render_preview_tab(): void
    {
        ?>
        <h1><?php echo esc_html__('Live Preview', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('Preview shipping calculations with different cart values.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <div class="preview-container">
            <div class="preview-controls">
                <label for="preview_cart_value"><?php echo esc_html__('Cart Value:', PluginConfig::TEXTDOMAIN->value); ?></label>
                <div class="input-with-prefix">
                    <span class="prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                    <input type="number" id="preview_cart_value" value="100" min="0" step="0.01" class="regular-text">
                </div>
                <button type="button" id="calculate-preview" class="button"><?php echo esc_html__('Calculate', PluginConfig::TEXTDOMAIN->value); ?></button>
            </div>
            
            <div class="preview-results" id="preview-results">
                <div class="preview-loading" style="display: none;">
                    <span class="spinner is-active"></span>
                    <?php echo esc_html__('Calculating...', PluginConfig::TEXTDOMAIN->value); ?>
                </div>
                <div class="preview-content">
                    <!-- Results will be loaded here via AJAX -->
                </div>
            </div>
        </div>
        <?php
    }

    private function render_analytics_tab(): void
    {
        ?>
        <h1><?php echo esc_html__('Analytics & Reporting', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('View shipping statistics and performance metrics.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <div class="analytics-container">
            <div class="analytics-grid">
                <div class="analytics-card">
                    <h3><?php echo esc_html__('Shipping Statistics', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <div class="stats-content">
                        <?php 
                        $stats = $this->get_shipping_statistics();
                        ?>
                        <div class="stat-item">
                            <span class="stat-label"><?php echo esc_html__('Total Orders:', PluginConfig::TEXTDOMAIN->value); ?></span>
                            <span class="stat-value"><?php echo esc_html($stats['total_orders']); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label"><?php echo esc_html__('Total Shipping:', PluginConfig::TEXTDOMAIN->value); ?></span>
                            <span class="stat-value"><?php echo wc_price($stats['total_shipping']); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label"><?php echo esc_html__('Average Shipping:', PluginConfig::TEXTDOMAIN->value); ?></span>
                            <span class="stat-value"><?php echo wc_price($stats['average_shipping']); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="analytics-card">
                    <h3><?php echo esc_html__('Performance Metrics', PluginConfig::TEXTDOMAIN->value); ?></h3>
                    <div class="metrics-content">
                        <div class="metric-item">
                            <span class="metric-label"><?php echo esc_html__('Cache Hit Rate:', PluginConfig::TEXTDOMAIN->value); ?></span>
                            <span class="metric-value"><?php echo esc_html($stats['cache_hit_rate']); ?>%</span>
                        </div>
                        <div class="metric-item">
                            <span class="metric-label"><?php echo esc_html__('Average Calculation Time:', PluginConfig::TEXTDOMAIN->value); ?></span>
                            <span class="metric-value"><?php echo esc_html($stats['avg_calculation_time']); ?>ms</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_system_tab(): void
    {
        ?>
        <h1><?php echo esc_html__('System Information', PluginConfig::TEXTDOMAIN->value); ?></h1>
        <p class="description">
            <?php echo esc_html__('System information and diagnostic data.', PluginConfig::TEXTDOMAIN->value); ?>
        </p>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('Plugin Version', PluginConfig::TEXTDOMAIN->value); ?></th>
                <td><?php echo esc_html(PluginConfig::VERSION->value); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('WordPress Version', PluginConfig::TEXTDOMAIN->value); ?></th>
                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('WooCommerce Version', PluginConfig::TEXTDOMAIN->value); ?></th>
                <td><?php echo esc_html(WC()->version); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('PHP Version', PluginConfig::TEXTDOMAIN->value); ?></th>
                <td><?php echo esc_html(PHP_VERSION); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Cache Status', PluginConfig::TEXTDOMAIN->value); ?></th>
                <td>
                    <?php 
                    $cache_enabled = $this->get_option('cache_enabled', 'yes');
                    echo $cache_enabled === 'yes' ? '<span class="status-active">Enabled</span>' : '<span class="status-inactive">Disabled</span>';
                    ?>
                </td>
            </tr>
        </table>
        <?php
    }

}

// Initialize the plugin
WC_Percentage_Shipping_Plugin::get_instance();
