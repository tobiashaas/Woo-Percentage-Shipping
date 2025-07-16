<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class WC_Percentage_Shipping_Method extends WC_Shipping_Method
{
    public function __construct(int $instance_id = 0)
    {
        $this->id = 'percentage_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Percentage Shipping', PluginConfig::TEXTDOMAIN->value);
        $this->method_description = __('Calculate shipping costs as a percentage of selected products', PluginConfig::TEXTDOMAIN->value);
        $this->supports = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];
        
        $this->init();
    }
    
    public function init(): void
    {
        $this->init_form_fields();
        $this->init_settings();
        
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->title = $this->get_option('title', __('Percentage Shipping', PluginConfig::TEXTDOMAIN->value));
        
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }
    
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', PluginConfig::TEXTDOMAIN->value),
                'type' => 'checkbox',
                'label' => __('Enable this shipping method', PluginConfig::TEXTDOMAIN->value),
                'default' => 'yes',
            ],
            'title' => [
                'title' => __('Method Title', PluginConfig::TEXTDOMAIN->value),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', PluginConfig::TEXTDOMAIN->value),
                'default' => __('Percentage Shipping', PluginConfig::TEXTDOMAIN->value),
                'desc_tip' => true,
            ],
        ];
    }
    
    public function calculate_shipping($package = []): void
    {
        $options = get_option(PluginConfig::OPTION_NAME->value, []);
        
        if (($options['enabled'] ?? 'yes') !== 'yes') {
            return;
        }
        
        $percentage = (float) ($options['percentage'] ?? 10);
        $min_fee = (float) ($options['minimum_fee'] ?? 0);
        $max_fee = (float) ($options['maximum_fee'] ?? 0);
        $include_digital = ($options['include_digital_products'] ?? 'no') === 'yes';
        $excluded_categories = (array) ($options['excluded_categories'] ?? []);
        $debug = ($options['debug_mode'] ?? 'no') === 'yes';
        
        $total = 0.0;
        $debug_lines = [];
        
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            
            // Check if digital products should be excluded
            if (!$include_digital && ($product->is_virtual() || $product->is_downloadable())) {
                $debug && $debug_lines[] = sprintf(__('Excluded (digital): %s', PluginConfig::TEXTDOMAIN->value), $product->get_name());
                continue;
            }
            
            // Check excluded categories
            if ($excluded_categories) {
                $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
                if (is_array($cats) && array_intersect($excluded_categories, $cats)) {
                    $debug && $debug_lines[] = sprintf(__('Excluded (category): %s', PluginConfig::TEXTDOMAIN->value), $product->get_name());
                    continue;
                }
            }
            
            $line_total = (float) $product->get_price() * (int) $item['quantity'];
            $total += $line_total;
            $debug && $debug_lines[] = sprintf(__('Included: %s = %s', PluginConfig::TEXTDOMAIN->value), $product->get_name(), wc_price($line_total));
        }
        
        if ($total <= 0) {
            return;
        }
        
        $cost = $total * ($percentage / 100);
        
        $cost = match (true) {
            $min_fee > 0 && $cost < $min_fee => $min_fee,
            $max_fee > 0 && $cost > $max_fee => $max_fee,
            default => $cost
        };
        
        $rate = [
            'id' => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost' => $cost,
            'calc_tax' => 'per_order',
        ];
        
        $this->add_rate($rate);
        
        if ($debug) {
            $debug_lines[] = sprintf(__('Calculation base: %s', PluginConfig::TEXTDOMAIN->value), wc_price($total));
            $debug_lines[] = sprintf(__('Percentage: %s%%', PluginConfig::TEXTDOMAIN->value), $percentage);
            $debug_lines[] = sprintf(__('Digital products: %s', PluginConfig::TEXTDOMAIN->value), $include_digital ? __('included', PluginConfig::TEXTDOMAIN->value) : __('excluded', PluginConfig::TEXTDOMAIN->value));
            $debug_lines[] = sprintf(__('Final cost: %s', PluginConfig::TEXTDOMAIN->value), wc_price($cost));
            wc_get_logger()->info(__('Percentage Shipping: ', PluginConfig::TEXTDOMAIN->value) . implode(' | ', $debug_lines), ['source' => 'wc-percentage-shipping']);
        }
    }
}
