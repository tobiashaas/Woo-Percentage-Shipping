<?php

/**
 * Test cases for WC_Percentage_Shipping_Validator
 * 
 * @package WC_Percentage_Shipping_Tests
 * @since 1.3.0
 */

class Test_WC_Percentage_Shipping_Validator extends WP_UnitTestCase
{
    /**
     * Test boolean sanitization
     */
    public function test_boolean_sanitization()
    {
        $input = [
            'enabled' => 'yes',
            'include_digital_products' => 'on',
            'debug_mode' => 1
        ];
        
        $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
        
        $this->assertEquals('yes', $output['enabled']);
        $this->assertEquals('yes', $output['include_digital_products']);
        $this->assertEquals('yes', $output['debug_mode']);
    }
    
    /**
     * Test percentage sanitization
     */
    public function test_percentage_sanitization()
    {
        $test_cases = [
            ['input' => 50, 'expected' => 50.0],
            ['input' => 150, 'expected' => 100.0], // Max 100
            ['input' => -10, 'expected' => 0.0],   // Min 0
            ['input' => 'invalid', 'expected' => 0.0],
            ['input' => 25.567, 'expected' => 25.57] // Rounded to 2 decimals
        ];
        
        foreach ($test_cases as $case) {
            $input = ['percentage' => $case['input']];
            $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
            
            $this->assertEquals($case['expected'], $output['percentage'], 
                "Failed for input: {$case['input']}");
        }
    }
    
    /**
     * Test fee sanitization
     */
    public function test_fee_sanitization()
    {
        $test_cases = [
            ['input' => 25.50, 'expected' => 25.50],
            ['input' => -5, 'expected' => 0.0],     // Min 0
            ['input' => 'invalid', 'expected' => 0.0],
            ['input' => 10.999, 'expected' => 11.0] // Rounded to 2 decimals
        ];
        
        foreach ($test_cases as $case) {
            $input = ['minimum_fee' => $case['input']];
            $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
            
            $this->assertEquals($case['expected'], $output['minimum_fee'], 
                "Failed for input: {$case['input']}");
        }
    }
    
    /**
     * Test category ID sanitization
     */
    public function test_category_sanitization()
    {
        $input = [
            'excluded_categories' => ['1', '2', 'invalid', 3, 0, -1]
        ];
        
        $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
        
        $expected = [1, 2, 3]; // Only valid positive integers
        $this->assertEquals($expected, $output['excluded_categories']);
    }
    
    /**
     * Test fee relationship validation
     */
    public function test_fee_relationship_validation()
    {
        $input = [
            'minimum_fee' => 50,
            'maximum_fee' => 25  // Invalid: max < min
        ];
        
        $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
        
        // Maximum fee should be adjusted to minimum fee
        $this->assertEquals(50.0, $output['maximum_fee']);
        
        // Should have generated a settings error
        $errors = get_settings_errors(PluginConfig::OPTION_NAME->value);
        $this->assertNotEmpty($errors);
    }
    
    /**
     * Test AJAX parameter validation
     */
    public function test_ajax_param_validation()
    {
        $params = [
            'cart_value' => '100.50',
            'percentage' => '15.5',
            'minimum_fee' => '5.00',
            'maximum_fee' => '50'
        ];
        
        $validated = WC_Percentage_Shipping_Validator::validate_ajax_params($params);
        
        $this->assertEquals(100.50, $validated['cart_value']);
        $this->assertEquals(15.5, $validated['percentage']);
        $this->assertEquals(5.0, $validated['minimum_fee']);
        $this->assertEquals(50.0, $validated['maximum_fee']);
    }
    
    /**
     * Test AJAX parameter validation with invalid data
     */
    public function test_ajax_param_validation_invalid()
    {
        $params = [
            'cart_value' => -10,      // Should become 0
            'percentage' => 150,      // Should become 100
            'minimum_fee' => 'invalid', // Should become 0
            'maximum_fee' => -5       // Should become 0
        ];
        
        $validated = WC_Percentage_Shipping_Validator::validate_ajax_params($params);
        
        $this->assertEquals(0.0, $validated['cart_value']);
        $this->assertEquals(100.0, $validated['percentage']);
        $this->assertEquals(0.0, $validated['minimum_fee']);
        $this->assertEquals(0.0, $validated['maximum_fee']);
    }
    
    /**
     * Test complete options sanitization
     */
    public function test_complete_options_sanitization()
    {
        $input = [
            'enabled' => 'yes',
            'percentage' => 12.5,
            'minimum_fee' => 3.50,
            'maximum_fee' => 75.00,
            'include_digital_products' => 'no',
            'excluded_categories' => ['1', '2', 'invalid'],
            'debug_mode' => 'yes'
        ];
        
        $output = WC_Percentage_Shipping_Validator::sanitize_options($input);
        
        $expected = [
            'enabled' => 'yes',
            'percentage' => 12.5,
            'minimum_fee' => 3.5,
            'maximum_fee' => 75.0,
            'include_digital_products' => 'no',
            'excluded_categories' => [1, 2],
            'debug_mode' => 'yes'
        ];
        
        $this->assertEquals($expected, $output);
    }
}
