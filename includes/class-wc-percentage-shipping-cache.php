<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caching layer for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.3.0
 */
final class WC_Percentage_Shipping_Cache
{
    private const CACHE_GROUP = 'wc_percentage_shipping';
    private const CACHE_EXPIRY = 3600; // 1 hour
    
    /**
     * Get cached calculation result
     * 
     * @param string $cache_key Cache key
     * @return mixed|null Cached result or null if not found
     */
    public static function get(string $cache_key)
    {
        if (!wp_using_ext_object_cache()) {
            return get_transient(self::get_transient_key($cache_key));
        }
        
        return wp_cache_get($cache_key, self::CACHE_GROUP);
    }
    
    /**
     * Set cached calculation result
     * 
     * @param string $cache_key Cache key
     * @param mixed $data Data to cache
     * @param int $expiry Cache expiry in seconds
     * @return bool True on success
     */
    public static function set(string $cache_key, mixed $data, int $expiry = self::CACHE_EXPIRY): bool
    {
        if (!wp_using_ext_object_cache()) {
            return set_transient(self::get_transient_key($cache_key), $data, $expiry);
        }
        
        return wp_cache_set($cache_key, $data, self::CACHE_GROUP, $expiry);
    }
    
    /**
     * Delete cached calculation result
     * 
     * @param string $cache_key Cache key
     * @return bool True on success
     */
    public static function delete(string $cache_key): bool
    {
        if (!wp_using_ext_object_cache()) {
            return delete_transient(self::get_transient_key($cache_key));
        }
        
        return wp_cache_delete($cache_key, self::CACHE_GROUP);
    }
    
    /**
     * Generate cache key for calculation
     * 
     * @param array $package Package data
     * @param array $options Plugin options
     * @return string Cache key
     */
    public static function generate_cache_key(array $package, array $options): string
    {
        // Create a hash of relevant data for caching
        $relevant_data = [
            'contents' => array_map(function($item) {
                return [
                    'product_id' => $item['data']->get_id(),
                    'quantity' => $item['quantity'],
                    'price' => $item['data']->get_price(),
                    'is_virtual' => $item['data']->is_virtual(),
                    'is_downloadable' => $item['data']->is_downloadable(),
                ];
            }, $package['contents'] ?? []),
            'options' => [
                'percentage' => $options['percentage'] ?? 10,
                'minimum_fee' => $options['minimum_fee'] ?? 0,
                'maximum_fee' => $options['maximum_fee'] ?? 0,
                'include_digital_products' => $options['include_digital_products'] ?? 'no',
                'excluded_categories' => $options['excluded_categories'] ?? [],
            ]
        ];
        
        return 'calc_' . md5(serialize($relevant_data));
    }
    
    /**
     * Clear all plugin cache
     * 
     * @return bool True on success
     */
    public static function clear_all(): bool
    {
        if (!wp_using_ext_object_cache()) {
            global $wpdb;
            
            // Delete all transients for this plugin
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_GROUP . '_%'
            ));
            
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_timeout_' . self::CACHE_GROUP . '_%'
            ));
            
            return true;
        }
        
        return wp_cache_flush_group(self::CACHE_GROUP);
    }
    
    /**
     * Get transient key for non-object cache
     * 
     * @param string $cache_key Original cache key
     * @return string Transient key
     */
    private static function get_transient_key(string $cache_key): string
    {
        return self::CACHE_GROUP . '_' . $cache_key;
    }
    
    /**
     * Warm up cache with common calculations
     * 
     * @param array $common_packages Common package configurations
     * @return void
     */
    public static function warm_up_cache(array $common_packages): void
    {
        $options = get_option(PluginConfig::OPTION_NAME->value, []);
        $calculator = new WC_Percentage_Shipping_Calculator($options);
        
        foreach ($common_packages as $package) {
            $cache_key = self::generate_cache_key($package, $options);
            
            if (self::get($cache_key) === null) {
                $cost = $calculator->calculate_shipping_cost($package);
                self::set($cache_key, $cost, self::CACHE_EXPIRY);
            }
        }
    }
}
