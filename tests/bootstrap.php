<?php

/**
 * Test bootstrap file for WooCommerce Percentage Shipping
 * 
 * @package WC_Percentage_Shipping_Tests
 * @since 1.3.0
 */

// Define test environment
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__FILE__) . '/../vendor/yoast/phpunit-polyfills');

// Load WordPress test environment
if (getenv('WP_TESTS_DIR')) {
    require_once getenv('WP_TESTS_DIR') . '/includes/functions.php';
} else {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
}

/**
 * Load the plugin
 */
function _manually_load_plugin() {
    require dirname(__FILE__) . '/../woocommerce-percentage-shipping.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment
if (getenv('WP_TESTS_DIR')) {
    require getenv('WP_TESTS_DIR') . '/includes/bootstrap.php';
} else {
    require '/tmp/wordpress-tests-lib/includes/bootstrap.php';
}

// Load WooCommerce test helpers
if (class_exists('WC_Unit_Test_Case')) {
    // WooCommerce test framework is available
} else {
    // Create a basic test case for WooCommerce functionality
    class WC_Unit_Test_Case extends WP_UnitTestCase {
        protected function setUp(): void {
            parent::setUp();
            
            // Set up WooCommerce environment
            if (!class_exists('WooCommerce')) {
                $this->markTestSkipped('WooCommerce is not available');
            }
            
            // Activate WooCommerce
            activate_plugin('woocommerce/woocommerce.php');
            
            // Clear any cached data
            wp_cache_flush();
        }
        
        protected function tearDown(): void {
            parent::tearDown();
            
            // Clean up
            wp_cache_flush();
        }
    }
}
