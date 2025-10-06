<?php

/**
 * Test cases for WC_Percentage_Shipping_Calculator
 * 
 * @package WC_Percentage_Shipping_Tests
 * @since 1.3.0
 */

class Test_WC_Percentage_Shipping_Calculator extends WC_Unit_Test_Case
{
    private WC_Percentage_Shipping_Calculator $calculator;
    
    public function setUp(): void
    {
        parent::setUp();
        
        $options = [
            'percentage' => 10.0,
            'minimum_fee' => 5.0,
            'maximum_fee' => 100.0,
            'include_digital_products' => 'no',
            'excluded_categories' => [],
            'debug_mode' => 'no'
        ];
        
        $this->calculator = new WC_Percentage_Shipping_Calculator($options);
    }
    
    /**
     * Test basic percentage calculation
     */
    public function test_basic_percentage_calculation()
    {
        $package = $this->create_test_package([
            ['price' => 100.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $this->calculator->calculate_shipping_cost($package);
        
        $this->assertEquals(10.0, $cost);
    }
    
    /**
     * Test minimum fee application
     */
    public function test_minimum_fee_application()
    {
        $package = $this->create_test_package([
            ['price' => 10.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $this->calculator->calculate_shipping_cost($package);
        
        // 10% of 10 = 1, but minimum fee is 5
        $this->assertEquals(5.0, $cost);
    }
    
    /**
     * Test maximum fee application
     */
    public function test_maximum_fee_application()
    {
        $package = $this->create_test_package([
            ['price' => 2000.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $this->calculator->calculate_shipping_cost($package);
        
        // 10% of 2000 = 200, but maximum fee is 100
        $this->assertEquals(100.0, $cost);
    }
    
    /**
     * Test digital product exclusion
     */
    public function test_digital_product_exclusion()
    {
        $package = $this->create_test_package([
            ['price' => 100.0, 'quantity' => 1, 'is_virtual' => true],
            ['price' => 50.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $this->calculator->calculate_shipping_cost($package);
        
        // Only physical product (50) should be included: 10% of 50 = 5
        $this->assertEquals(5.0, $cost);
    }
    
    /**
     * Test digital product inclusion
     */
    public function test_digital_product_inclusion()
    {
        $options = [
            'percentage' => 10.0,
            'minimum_fee' => 0.0,
            'maximum_fee' => 0.0,
            'include_digital_products' => 'yes',
            'excluded_categories' => [],
            'debug_mode' => 'no'
        ];
        
        $calculator = new WC_Percentage_Shipping_Calculator($options);
        
        $package = $this->create_test_package([
            ['price' => 100.0, 'quantity' => 1, 'is_virtual' => true],
            ['price' => 50.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $calculator->calculate_shipping_cost($package);
        
        // Both products should be included: 10% of 150 = 15
        $this->assertEquals(15.0, $cost);
    }
    
    /**
     * Test category exclusion
     */
    public function test_category_exclusion()
    {
        // Create a test category
        $category_id = wp_insert_term('Test Category', 'product_cat')['term_id'];
        
        $options = [
            'percentage' => 10.0,
            'minimum_fee' => 0.0,
            'maximum_fee' => 0.0,
            'include_digital_products' => 'no',
            'excluded_categories' => [$category_id],
            'debug_mode' => 'no'
        ];
        
        $calculator = new WC_Percentage_Shipping_Calculator($options);
        
        // Create a product in the excluded category
        $product_id = $this->create_test_product(['price' => 100.0, 'category' => $category_id]);
        
        $package = $this->create_test_package([
            ['product_id' => $product_id, 'price' => 100.0, 'quantity' => 1, 'is_virtual' => false]
        ]);
        
        $cost = $calculator->calculate_shipping_cost($package);
        
        // Product should be excluded, so cost should be 0
        $this->assertEquals(0.0, $cost);
        
        // Clean up
        wp_delete_term($category_id, 'product_cat');
    }
    
    /**
     * Test empty package
     */
    public function test_empty_package()
    {
        $package = ['contents' => []];
        
        $cost = $this->calculator->calculate_shipping_cost($package);
        
        $this->assertEquals(0.0, $cost);
    }
    
    /**
     * Test preview calculation
     */
    public function test_preview_calculation()
    {
        $result = WC_Percentage_Shipping_Calculator::calculate_preview(100, 10, 5, 100);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('calculated', $result);
        $this->assertArrayHasKey('final_cost', $result);
        $this->assertArrayHasKey('explanation', $result);
        
        $this->assertEquals(10.0, $result['calculated']);
        $this->assertEquals(10.0, $result['final_cost']);
    }
    
    /**
     * Create a test package with mock products
     */
    private function create_test_package(array $items): array
    {
        $package = ['contents' => []];
        
        foreach ($items as $item) {
            $product_id = $item['product_id'] ?? $this->create_test_product($item);
            $product = new WC_Product_Simple($product_id);
            $product->set_price($item['price']);
            $product->set_virtual($item['is_virtual'] ?? false);
            $product->save();
            
            $package['contents'][uniqid()] = [
                'data' => $product,
                'quantity' => $item['quantity'] ?? 1
            ];
        }
        
        return $package;
    }
    
    /**
     * Create a test product
     */
    private function create_test_product(array $data): int
    {
        $product = new WC_Product_Simple();
        $product->set_name('Test Product');
        $product->set_price($data['price'] ?? 10.0);
        $product->set_virtual($data['is_virtual'] ?? false);
        
        if (isset($data['category'])) {
            wp_set_object_terms($product->save(), [$data['category']], 'product_cat');
        }
        
        return $product->save();
    }
}
