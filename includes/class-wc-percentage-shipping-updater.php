<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple GitHub Auto-Updater for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.4.0
 */
final class WC_Percentage_Shipping_Updater
{
    private const GITHUB_REPO = 'tobiashaas/Woo-Percentage-Shipping';
    private const PLUGIN_SLUG = 'woocommerce-percentage-shipping';
    private const TRANSIENT_KEY = 'wc_percentage_shipping_update_check';
    private const TRANSIENT_EXPIRY = 12 * HOUR_IN_SECONDS; // Check every 12 hours

    public function __construct()
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_action('admin_notices', [$this, 'show_update_notice']);
    }

    public function check_for_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();
        $current_version = $this->get_current_version();

        if ($remote_version && version_compare($current_version, $remote_version, '<')) {
            $plugin_file = plugin_basename(__FILE__);
            $plugin_file = str_replace('includes/class-wc-percentage-shipping-updater.php', 'woocommerce-percentage-shipping.php', $plugin_file);
            
            $transient->response[$plugin_file] = (object) [
                'slug' => self::PLUGIN_SLUG,
                'plugin' => $plugin_file,
                'new_version' => $remote_version,
                'url' => 'https://github.com/' . self::GITHUB_REPO,
                'package' => $this->get_download_url($remote_version),
                'icons' => [
                    '1x' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/icon-128x128.png',
                    '2x' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/icon-256x256.png'
                ],
                'banners' => [
                    'low' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/banner-772x250.png',
                    'high' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/banner-1544x500.png'
                ]
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args)
    {
        if ('plugin_information' !== $action || self::PLUGIN_SLUG !== $args->slug) {
            return $result;
        }

        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            return $result;
        }

        return (object) [
            'name' => 'WooCommerce Percentage Shipping',
            'slug' => self::PLUGIN_SLUG,
            'version' => $remote_version,
            'author' => 'Tobias Haas',
            'author_profile' => 'https://github.com/tobiashaas',
            'requires' => '6.8',
            'tested' => '6.8',
            'requires_php' => '8.3',
            'last_updated' => $this->get_release_date(),
            'homepage' => 'https://github.com/' . self::GITHUB_REPO,
            'download_link' => $this->get_download_url($remote_version),
            'sections' => [
                'description' => 'Modern shipping calculation plugin that computes shipping costs as a percentage of cart value with advanced filtering options.',
                'changelog' => $this->get_changelog(),
                'installation' => 'Upload the plugin files to your WordPress installation, or install directly through the WordPress admin interface.',
                'faq' => 'For support and documentation, please visit the GitHub repository.'
            ],
            'icons' => [
                '1x' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/icon-128x128.png',
                '2x' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/icon-256x256.png'
            ],
            'banners' => [
                'low' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/banner-772x250.png',
                'high' => 'https://github.com/' . self::GITHUB_REPO . '/raw/main/assets/banner-1544x500.png'
            ]
        ];
    }

    public function show_update_notice(): void
    {
        if (!current_user_can('update_plugins')) {
            return;
        }

        $remote_version = $this->get_remote_version();
        $current_version = $this->get_current_version();

        if ($remote_version && version_compare($current_version, $remote_version, '<')) {
            echo '<div class="notice notice-info is-dismissible">';
            echo '<p><strong>' . esc_html__('WooCommerce Percentage Shipping', 'wc-percentage-shipping') . '</strong> ';
            printf(
                /* translators: %1$s: remote version, %2$s: update link */
                esc_html__('Version %1$s is available. %2$s', 'wc-percentage-shipping'),
                $remote_version,
                '<a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Update now', 'wc-percentage-shipping') . '</a>'
            );
            echo '</p>';
            echo '</div>';
        }
    }

    private function get_remote_version(): ?string
    {
        $cached_version = get_transient(self::TRANSIENT_KEY . '_version');
        if ($cached_version !== false) {
            return $cached_version;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version')
                ]
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['tag_name'])) {
            return null;
        }

        $version = ltrim($data['tag_name'], 'v');
        set_transient(self::TRANSIENT_KEY . '_version', $version, self::TRANSIENT_EXPIRY);

        return $version;
    }

    private function get_current_version(): string
    {
        $plugin_file = WP_PLUGIN_DIR . '/woocommerce-percentage-shipping/woocommerce-percentage-shipping.php';
        if (!file_exists($plugin_file)) {
            return '0.0.0';
        }

        $plugin_data = get_plugin_data($plugin_file);
        return $plugin_data['Version'] ?? '0.0.0';
    }

    private function get_download_url(string $version): string
    {
        return 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/tags/v' . $version . '.zip';
    }

    private function get_release_date(): string
    {
        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version')
                ]
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return date('Y-m-d');
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data['published_at'] ?? date('Y-m-d');
    }

    private function get_changelog(): string
    {
        return '
<h4>Latest Updates</h4>
<ul>
    <li>Modern settings page redesign with tab-based interface</li>
    <li>Live preview with real-time calculation updates</li>
    <li>Enhanced accessibility and responsive design</li>
    <li>Performance optimizations and security improvements</li>
</ul>
<p>For the complete changelog, please visit our <a href="https://github.com/' . self::GITHUB_REPO . '/releases">GitHub releases page</a>.</p>
        ';
    }
}
