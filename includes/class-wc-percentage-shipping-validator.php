<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Input validation and sanitization for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping
 * @since 1.3.0
 */
final class WC_Percentage_Shipping_Validator
{
    /**
     * Sanitize and validate plugin options
     * 
     * @param array $input Raw input data
     * @return array Sanitized and validated options
     */
    public static function sanitize_options(array $input): array
    {
        $output = [];
        
        // Boolean fields
        $output['enabled'] = self::sanitize_boolean($input['enabled'] ?? 'no');
        $output['include_digital_products'] = self::sanitize_boolean($input['include_digital_products'] ?? 'no');
        $output['debug_mode'] = self::sanitize_boolean($input['debug_mode'] ?? 'no');
        
        // Numeric fields with validation
        $output['percentage'] = self::sanitize_percentage($input['percentage'] ?? 10.0);
        $output['minimum_fee'] = self::sanitize_positive_number($input['minimum_fee'] ?? 0.0);
        $output['maximum_fee'] = self::sanitize_positive_number($input['maximum_fee'] ?? 0.0);
        
        // Array fields
        $output['excluded_categories'] = self::sanitize_category_ids($input['excluded_categories'] ?? []);
        
        // Validate business logic
        self::validate_fee_relationship($output);
        
        return $output;
    }
    
    /**
     * Sanitize boolean values
     * 
     * @param mixed $value Input value
     * @return string 'yes' or 'no'
     */
    private static function sanitize_boolean(mixed $value): string
    {
        return in_array($value, ['yes', 'on', '1', 1, true], true) ? 'yes' : 'no';
    }
    
    /**
     * Sanitize percentage value (0-100)
     * 
     * @param mixed $value Input value
     * @return float Sanitized percentage
     */
    private static function sanitize_percentage(mixed $value): float
    {
        $percentage = max(0.0, min(100.0, (float) $value));
        return round($percentage, 2);
    }
    
    /**
     * Sanitize positive number
     * 
     * @param mixed $value Input value
     * @return float Sanitized positive number
     */
    private static function sanitize_positive_number(mixed $value): float
    {
        $number = max(0.0, (float) $value);
        return round($number, 2);
    }
    
    /**
     * Sanitize category IDs array
     * 
     * @param mixed $categories Input categories
     * @return array Array of valid category IDs
     */
    private static function sanitize_category_ids(mixed $categories): array
    {
        if (!is_array($categories)) {
            return [];
        }
        
        return array_map('intval', array_filter($categories, 'is_numeric'));
    }
    
    /**
     * Validate fee relationship (max >= min)
     * 
     * @param array &$output Output array (passed by reference)
     * @return void
     */
    private static function validate_fee_relationship(array &$output): void
    {
        if ($output['maximum_fee'] > 0 && $output['maximum_fee'] < $output['minimum_fee']) {
            add_settings_error(
                PluginConfig::OPTION_NAME->value,
                'fee_mismatch',
                __('Maximum fee must be higher than minimum fee.', PluginConfig::TEXTDOMAIN->value)
            );
            $output['maximum_fee'] = $output['minimum_fee'];
        }
    }
    
    /**
     * Validate AJAX request parameters
     * 
     * @param array $params Request parameters
     * @return array Validated parameters
     * @throws InvalidArgumentException
     */
    public static function validate_ajax_params(array $params): array
    {
        $validated = [];
        
        $validated['cart_value'] = max(0.0, (float) ($params['cart_value'] ?? 0));
        $validated['percentage'] = max(0, min(100, (float) ($params['percentage'] ?? 10)));
        $validated['minimum_fee'] = max(0.0, (float) ($params['minimum_fee'] ?? 0));
        $validated['maximum_fee'] = max(0.0, (float) ($params['maximum_fee'] ?? 0));
        
        return $validated;
    }
}
