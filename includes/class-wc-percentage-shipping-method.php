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
        
        $calculator = new WC_Percentage_Shipping_Calculator($options);
        $cost = $calculator->calculate_shipping_cost($package);
        
        if ($cost <= 0) {
            return;
        }
        
        $rate = [
            'id' => $this->id . ':' . $this->instance_id,
            'label' => $this->title,
            'cost' => $cost,
            'calc_tax' => 'per_order',
        ];
        
        $this->add_rate($rate);
    }
}
