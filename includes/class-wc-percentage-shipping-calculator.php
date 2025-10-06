<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shipping calculation logic for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.3.0
 */
final class WC_Percentage_Shipping_Calculator
{
    private array $options;
    private bool $debug_mode;
    
    public function __construct(array $options = [])
    {
        $this->options = $options;
        $this->debug_mode = ($options['debug_mode'] ?? 'no') === 'yes';
    }
    
    /**
     * Calculate shipping cost for a package
     * 
     * @param array $package WooCommerce package data
     * @return float Calculated shipping cost
     */
    public function calculate_shipping_cost(array $package): float
    {
        $start_time = microtime(true);
        $cache_hit = false;
        
        try {
            // Check cache first
            $cache_key = WC_Percentage_Shipping_Cache::generate_cache_key($package, $this->options);
            $cached_cost = WC_Percentage_Shipping_Cache::get($cache_key);
            
            if ($cached_cost !== null) {
                $cache_hit = true;
                $execution_time = (microtime(true) - $start_time) * 1000;
                
                WC_Percentage_Shipping_Logger::log_calculation($package, $this->options, (float) $cached_cost, $execution_time);
                WC_Percentage_Shipping_Logger::debug('Cache hit for calculation', [
                    'cache_key' => $cache_key,
                    'cached_cost' => $cached_cost,
                    'execution_time_ms' => $execution_time
                ]);
                
                return (float) $cached_cost;
            }
            
            $eligible_total = $this->get_eligible_cart_total($package);
            
            if ($eligible_total <= 0) {
                WC_Percentage_Shipping_Cache::set($cache_key, 0.0, 1800); // Cache for 30 minutes
                $execution_time = (microtime(true) - $start_time) * 1000;
                
                WC_Percentage_Shipping_Logger::log_calculation($package, $this->options, 0.0, $execution_time);
                WC_Percentage_Shipping_Logger::debug('No eligible products for shipping calculation');
                
                return 0.0;
            }
            
            $percentage = (float) ($this->options['percentage'] ?? 10);
            $min_fee = (float) ($this->options['minimum_fee'] ?? 0);
            $max_fee = (float) ($this->options['maximum_fee'] ?? 0);
            
            $cost = $eligible_total * ($percentage / 100);
            
            // Apply minimum and maximum limits
            $final_cost = match (true) {
                $min_fee > 0 && $cost < $min_fee => $min_fee,
                $max_fee > 0 && $cost > $max_fee => $max_fee,
                default => $cost
            };
            
            $final_cost = round($final_cost, 2);
            
            // Cache the result
            WC_Percentage_Shipping_Cache::set($cache_key, $final_cost, 3600); // Cache for 1 hour
            
            $execution_time = (microtime(true) - $start_time) * 1000;
            
            WC_Percentage_Shipping_Logger::log_calculation($package, $this->options, $final_cost, $execution_time);
            WC_Percentage_Shipping_Logger::debug('Calculation completed', [
                'eligible_total' => $eligible_total,
                'calculated_cost' => $cost,
                'final_cost' => $final_cost,
                'cache_hit' => $cache_hit,
                'execution_time_ms' => $execution_time
            ]);
            
            return $final_cost;
            
        } catch (Exception $e) {
            WC_Percentage_Shipping_Logger::error('Calculation failed', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'package_items' => count($package['contents'] ?? []),
                'execution_time_ms' => (microtime(true) - $start_time) * 1000
            ]);
            
            // Return 0 on error to prevent shipping method from breaking
            return 0.0;
        }
    }
    
    /**
     * Get total value of eligible products in cart
     * 
     * @param array $package WooCommerce package data
     * @return float Total value of eligible products
     */
    private function get_eligible_cart_total(array $package): float
    {
        $total = 0.0;
        $include_digital = ($this->options['include_digital_products'] ?? 'no') === 'yes';
        $excluded_categories = (array) ($this->options['excluded_categories'] ?? []);
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            
            // Skip if product should be excluded
            if (!$this->is_product_eligible($product, $include_digital, $excluded_categories)) {
                continue;
            }
            
            $line_total = (float) $product->get_price() * (int) $item['quantity'];
            $total += $line_total;
        }
        
        return $total;
    }
    
    /**
     * Check if product is eligible for shipping calculation
     * 
     * @param WC_Product $product Product to check
     * @param bool $include_digital Whether to include digital products
     * @param array $excluded_categories Category IDs to exclude
     * @return bool True if eligible
     */
    private function is_product_eligible(WC_Product $product, bool $include_digital, array $excluded_categories): bool
    {
        // Check digital product exclusion
        if (!$include_digital && ($product->is_virtual() || $product->is_downloadable())) {
            if ($this->debug_mode) {
                $this->log_debug(sprintf(
                    __('Excluded (digital): %s', PluginConfig::TEXTDOMAIN->value),
                    $product->get_name()
                ));
            }
            return false;
        }
        
        // Check category exclusion
        if (!empty($excluded_categories)) {
            $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
            
            if (is_array($product_categories) && array_intersect($excluded_categories, $product_categories)) {
                if ($this->debug_mode) {
                    $this->log_debug(sprintf(
                        __('Excluded (category): %s', PluginConfig::TEXTDOMAIN->value),
                        $product->get_name()
                    ));
                }
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Log calculation details
     * 
     * @param float $eligible_total Eligible cart total
     * @param float $calculated_cost Calculated cost before limits
     * @param float $final_cost Final cost after limits
     * @return void
     */
    private function log_calculation(float $eligible_total, float $calculated_cost, float $final_cost): void
    {
        if (!$this->debug_mode) {
            return;
        }
        
        $percentage = (float) ($this->options['percentage'] ?? 10);
        $include_digital = ($this->options['include_digital_products'] ?? 'no') === 'yes';
        
        $debug_lines = [
            sprintf(__('Calculation base: %s', PluginConfig::TEXTDOMAIN->value), wc_price($eligible_total)),
            sprintf(__('Percentage: %s%%', PluginConfig::TEXTDOMAIN->value), $percentage),
            sprintf(__('Digital products: %s', PluginConfig::TEXTDOMAIN->value), 
                $include_digital ? __('included', PluginConfig::TEXTDOMAIN->value) : __('excluded', PluginConfig::TEXTDOMAIN->value)
            ),
            sprintf(__('Calculated cost: %s', PluginConfig::TEXTDOMAIN->value), wc_price($calculated_cost)),
            sprintf(__('Final cost: %s', PluginConfig::TEXTDOMAIN->value), wc_price($final_cost))
        ];
        
        $this->log_debug(implode(' | ', $debug_lines));
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @return void
     */
    private function log_debug(string $message): void
    {
        if (!$this->debug_mode) {
            return;
        }
        
        wc_get_logger()->info(
            __('Percentage Shipping: ', PluginConfig::TEXTDOMAIN->value) . $message,
            ['source' => 'wc-percentage-shipping']
        );
    }
    
    /**
     * Calculate preview cost for admin interface
     * 
     * @param float $cart_value Cart value to calculate for
     * @param float $percentage Percentage to apply
     * @param float $min_fee Minimum fee
     * @param float $max_fee Maximum fee
     * @return array Calculation result
     */
    public static function calculate_preview(float $cart_value, float $percentage, float $min_fee, float $max_fee): array
    {
        $calculated = $cart_value * ($percentage / 100);
        
        $final_cost = match (true) {
            $min_fee > 0 && $calculated < $min_fee => $min_fee,
            $max_fee > 0 && $calculated > $max_fee => $max_fee,
            default => $calculated
        };
        
        return [
            'calculated' => $calculated,
            'final_cost' => $final_cost,
            'explanation' => sprintf(
                '%s × %s%% = %s',
                wc_price($cart_value),
                $percentage,
                wc_price($final_cost)
            )
        ];
    }
}
