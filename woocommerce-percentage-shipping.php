<?php

declare(strict_types=1);

/**
 * Plugin Name: WooCommerce Percentage Shipping
 * Description: Calculate shipping costs as a percentage of physical products with modern architecture
 * Version: 1.4.0
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
    case VERSION = '1.4.0';
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
        $current_tab = $_GET['tab'] ?? 'general';
        $tabs = [
            'general' => __('General', PluginConfig::TEXTDOMAIN->value),
            'advanced' => __('Advanced', PluginConfig::TEXTDOMAIN->value),
            'performance' => __('Performance', PluginConfig::TEXTDOMAIN->value),
            'security' => __('Security', PluginConfig::TEXTDOMAIN->value),
        ];
        ?>
        <div class="wrap wc-percentage-shipping-admin">
            <!-- Header -->
            <div class="wc-percentage-shipping-header">
                <div class="header-content">
                    <div class="header-main">
                        <h1 class="page-title">
                            <?php echo esc_html__('Percentage Shipping', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="version-badge">v<?php echo esc_html(PluginConfig::VERSION->value); ?></span>
                        </h1>
                        <p class="page-description">
                            <?php echo esc_html__('Modern shipping calculation with advanced filtering and performance optimization', PluginConfig::TEXTDOMAIN->value); ?>
                        </p>
                    </div>
                    <div class="header-status">
                        <?php $this->render_status_badge(); ?>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="quick-actions">
                    <button type="button" class="button button-secondary" id="clear-cache">
                        <span class="dashicons dashicons-update"></span>
                        <?php echo esc_html__('Clear Cache', PluginConfig::TEXTDOMAIN->value); ?>
                    </button>
                    <button type="button" class="button button-secondary" id="export-settings">
                        <span class="dashicons dashicons-download"></span>
                        <?php echo esc_html__('Export Settings', PluginConfig::TEXTDOMAIN->value); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-reports&tab=logs')); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php echo esc_html__('View Logs', PluginConfig::TEXTDOMAIN->value); ?>
                    </a>
                </div>
            </div>

            <?php settings_errors(); ?>
            
            <!-- Navigation Tabs -->
            <nav class="wc-percentage-shipping-tabs">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . PluginConfig::PLUGIN_SLUG->value . '&tab=' . $tab_key)); ?>" 
                       class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons dashicons-<?php echo $this->get_tab_icon($tab_key); ?>"></span>
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            
            <!-- Main Content -->
            <div class="wc-percentage-shipping-content">
                <div class="main-content">
                    <form method="post" action="" class="settings-form">
                        <?php 
                        wp_nonce_field(PluginSecurity::NONCE_ACTION->value, PluginSecurity::NONCE_FIELD->value);
                        settings_fields('wc_percentage_shipping_settings');
                        ?>
                        
                        <?php $this->render_tab_content($current_tab); ?>
                        
                        <div class="form-actions">
                            <?php submit_button(__('Save Changes', PluginConfig::TEXTDOMAIN->value), 'primary', 'submit', false, ['id' => 'save-settings']); ?>
                            <button type="button" class="button button-secondary" id="reset-settings">
                                <?php echo esc_html__('Reset to Defaults', PluginConfig::TEXTDOMAIN->value); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Sidebar -->
                <div class="sidebar">
                    <?php $this->render_live_preview(); ?>
                    <?php $this->render_system_info(); ?>
                    <?php $this->render_quick_links(); ?>
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

    private function render_status_badge(): void
    {
        $enabled = $this->get_option('enabled', 'yes');
        $status_class = $enabled === 'yes' ? 'status-enabled' : 'status-disabled';
        $status_icon = $enabled === 'yes' ? 'dashicons-yes' : 'dashicons-no';
        $status_text = $enabled === 'yes' ? __('Active', PluginConfig::TEXTDOMAIN->value) : __('Inactive', PluginConfig::TEXTDOMAIN->value);
        ?>
        <div class="status-badge <?php echo esc_attr($status_class); ?>">
            <span class="dashicons <?php echo esc_attr($status_icon); ?>"></span>
            <span class="status-text"><?php echo esc_html($status_text); ?></span>
        </div>
        <?php
    }

    private function render_tab_content(string $tab): void
    {
        match ($tab) {
            'general' => $this->render_general_tab(),
            'advanced' => $this->render_advanced_tab(),
            'performance' => $this->render_performance_tab(),
            'security' => $this->render_security_tab(),
            default => $this->render_general_tab()
        };
    }

    private function render_general_tab(): void
    {
        ?>
        <div class="tab-content">
            <div class="settings-section">
                <h3 class="section-title">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php echo esc_html__('Basic Configuration', PluginConfig::TEXTDOMAIN->value); ?>
                </h3>
                <div class="settings-grid">
                    <div class="setting-group">
                        <label for="enabled" class="setting-label">
                            <?php echo esc_html__('Enable Plugin', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Turn the percentage shipping calculation on or off', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <label class="toggle-switch">
                                <input type="checkbox" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[enabled]" value="yes" <?php checked($this->get_option('enabled'), 'yes'); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-group">
                        <label for="percentage" class="setting-label">
                            <?php echo esc_html__('Shipping Percentage', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Percentage of cart value to charge as shipping (0-100)', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <div class="input-group">
                                <input type="number" 
                                       name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[percentage]" 
                                       id="percentage"
                                       value="<?php echo esc_attr($this->get_option('percentage', 10)); ?>" 
                                       min="0" 
                                       max="100" 
                                       step="0.1"
                                       class="percentage-input"
                                       required>
                                <span class="input-suffix">%</span>
                            </div>
                        </div>
                    </div>

                    <div class="setting-group">
                        <label for="minimum_fee" class="setting-label">
                            <?php echo esc_html__('Minimum Fee', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Minimum shipping cost regardless of percentage calculation', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <div class="input-group">
                                <span class="input-prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                <input type="number" 
                                       name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[minimum_fee]" 
                                       id="minimum_fee"
                                       value="<?php echo esc_attr($this->get_option('minimum_fee', 5)); ?>" 
                                       min="0" 
                                       step="0.01"
                                       class="currency-input">
                            </div>
                        </div>
                    </div>

                    <div class="setting-group">
                        <label for="maximum_fee" class="setting-label">
                            <?php echo esc_html__('Maximum Fee', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Maximum shipping cost cap (0 = unlimited)', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <div class="input-group">
                                <span class="input-prefix"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                <input type="number" 
                                       name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[maximum_fee]" 
                                       id="maximum_fee"
                                       value="<?php echo esc_attr($this->get_option('maximum_fee', 100)); ?>" 
                                       min="0" 
                                       step="0.01"
                                       class="currency-input">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_advanced_tab(): void
    {
        ?>
        <div class="tab-content">
            <div class="settings-section">
                <h3 class="section-title">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php echo esc_html__('Advanced Options', PluginConfig::TEXTDOMAIN->value); ?>
                </h3>
                <div class="settings-grid">
                    <div class="setting-group">
                        <label for="include_digital_products" class="setting-label">
                            <?php echo esc_html__('Include Digital Products', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Calculate shipping for virtual/downloadable products', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <label class="toggle-switch">
                                <input type="checkbox" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[include_digital_products]" value="yes" <?php checked($this->get_option('include_digital_products'), 'yes'); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="setting-group full-width">
                        <label for="excluded_categories" class="setting-label">
                            <?php echo esc_html__('Excluded Categories', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Product categories to exclude from shipping calculation', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <?php $this->render_category_selector(); ?>
                        </div>
                    </div>

                    <div class="setting-group">
                        <label for="debug_mode" class="setting-label">
                            <?php echo esc_html__('Debug Mode', PluginConfig::TEXTDOMAIN->value); ?>
                            <span class="wc-percentage-shipping-help-tip" title="<?php echo esc_attr__('Enable detailed logging for troubleshooting', PluginConfig::TEXTDOMAIN->value); ?>">?</span>
                        </label>
                        <div class="setting-control">
                            <label class="toggle-switch">
                                <input type="checkbox" name="<?php echo esc_attr(PluginConfig::OPTION_NAME->value); ?>[debug_mode]" value="yes" <?php checked($this->get_option('debug_mode'), 'yes'); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
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

    private function render_category_selector(): void
    {
        $excluded_categories = $this->get_option('excluded_categories', []);
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        
        if (!empty($categories) && !is_wp_error($categories)) {
            echo '<select name="' . esc_attr(PluginConfig::OPTION_NAME->value) . '[excluded_categories][]" multiple class="category-select">';
            foreach ($categories as $category) {
                $selected = in_array($category->term_id, $excluded_categories, true) ? 'selected' : '';
                echo '<option value="' . esc_attr($category->term_id) . '" ' . $selected . '>' . esc_html($category->name) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<p class="description">' . esc_html__('No product categories found.', PluginConfig::TEXTDOMAIN->value) . '</p>';
        }
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
}

// Initialize the plugin
WC_Percentage_Shipping_Plugin::get_instance();
