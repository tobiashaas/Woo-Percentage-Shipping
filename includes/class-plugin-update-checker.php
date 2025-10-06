<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Update Checker for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.4.0
 */

// Include the Plugin Update Checker library
if (!class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {
    require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
}

/**
 * Initialize GitHub Auto-Updater
 */
function wc_percentage_shipping_init_updater(): void
{
    $plugin_file = __FILE__;
    
    // Find the main plugin file
    $plugin_dir = dirname(__FILE__);
    while ($plugin_dir !== dirname($plugin_dir)) {
        $main_file = $plugin_dir . '/woocommerce-percentage-shipping.php';
        if (file_exists($main_file)) {
            $plugin_file = $main_file;
            break;
        }
        $plugin_dir = dirname($plugin_dir);
    }
    
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/tobiashaas/Woo-Percentage-Shipping',
        $plugin_file,
        'woocommerce-percentage-shipping'
    );
    
    // Configure the update checker
    $updateChecker->setBranch('main');
    
    // Add update information to plugin details
    $updateChecker->addResultFilter(function($pluginInfo, $httpResponse = null) {
        if ($pluginInfo) {
            $pluginInfo->icons = [
                '1x' => 'https://github.com/tobiashaas/Woo-Percentage-Shipping/raw/main/assets/icon-128x128.png',
                '2x' => 'https://github.com/tobiashaas/Woo-Percentage-Shipping/raw/main/assets/icon-256x256.png'
            ];
            
            $pluginInfo->banners = [
                'low' => 'https://github.com/tobiashaas/Woo-Percentage-Shipping/raw/main/assets/banner-772x250.png',
                'high' => 'https://github.com/tobiashaas/Woo-Percentage-Shipping/raw/main/assets/banner-1544x500.png'
            ];
        }
        return $pluginInfo;
    });
    
    // Add update notification to admin
    add_action('admin_notices', function() use ($updateChecker) {
        if ($updateChecker->isUpdateAvailable()) {
            $plugin_data = get_plugin_data($updateChecker->getAbsolutePath());
            $update_info = $updateChecker->getUpdate();
            
            if ($update_info && version_compare($plugin_data['Version'], $update_info->version, '<')) {
                echo '<div class="notice notice-info is-dismissible">';
                echo '<p><strong>' . esc_html__('WooCommerce Percentage Shipping', 'wc-percentage-shipping') . '</strong> ';
                echo sprintf(
                    esc_html__('Version %s is available. %s', 'wc-percentage-shipping'),
                    $update_info->version,
                    '<a href="' . admin_url('plugins.php') . '">' . esc_html__('Update now', 'wc-percentage-shipping') . '</a>'
                );
                echo '</p>';
                echo '</div>';
            }
        }
    });
}

// Initialize the updater
wc_percentage_shipping_init_updater();
