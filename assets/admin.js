/**
 * WooCommerce Percentage Shipping Admin
 */

// DOM Ready equivalent
function domReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback);
    } else {
        callback();
    }
}

// Vanilla JavaScript Tooltip System
class VanillaTooltip {
    constructor() {
        this.tooltip = null;
        this.init();
    }

    init() {
        // Create tooltip element
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'wc-percentage-shipping-tooltip';
        this.tooltip.style.cssText = `
            position: absolute;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
            max-width: 300px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        `;
        document.body.appendChild(this.tooltip);

        // Bind events to help tip elements
        this.bindEvents();
    }

    bindEvents() {
        const helpTips = document.querySelectorAll('.wc-percentage-shipping-help-tip');
        
        helpTips.forEach(tip => {
            tip.addEventListener('mouseenter', (e) => this.showTooltip(e));
            tip.addEventListener('mouseleave', () => this.hideTooltip());
            tip.addEventListener('mousemove', (e) => this.moveTooltip(e));
        });
    }

    showTooltip(event) {
        const tipText = event.target.getAttribute('title') || event.target.getAttribute('data-tip');
        if (!tipText) return;

        // Store original title and remove it to prevent browser tooltip
        event.target.setAttribute('data-original-title', tipText);
        event.target.removeAttribute('title');

        this.tooltip.textContent = tipText;
        this.tooltip.style.opacity = '1';
        this.moveTooltip(event);
    }

    hideTooltip() {
        this.tooltip.style.opacity = '0';
        
        // Restore original title
        const elements = document.querySelectorAll('[data-original-title]');
        elements.forEach(element => {
            const originalTitle = element.getAttribute('data-original-title');
            if (originalTitle) {
                element.setAttribute('title', originalTitle);
                element.removeAttribute('data-original-title');
            }
        });
    }

    moveTooltip(event) {
        const tooltipRect = this.tooltip.getBoundingClientRect();
        const x = event.pageX + 15;
        const y = event.pageY - tooltipRect.height - 5;
        
        this.tooltip.style.left = x + 'px';
        this.tooltip.style.top = y + 'px';
    }
}

// Live Preview Calculator
class LivePreview {
    constructor() {
        this.percentageInput = document.querySelector('input[name="wc_percentage_shipping_options[percentage]"]');
        this.minFeeInput = document.querySelector('input[name="wc_percentage_shipping_options[minimum_fee]"]');
        this.maxFeeInput = document.querySelector('input[name="wc_percentage_shipping_options[maximum_fee]"]');
        this.previewContainer = document.querySelector('.preview-example');
        
        if (this.percentageInput && this.previewContainer) {
            this.bindEvents();
            this.updatePreview(); // Initial update
        }
    }

    bindEvents() {
        const inputs = [this.percentageInput, this.minFeeInput, this.maxFeeInput];
        
        inputs.forEach(input => {
            if (input) {
                input.addEventListener('input', () => this.updatePreview());
                input.addEventListener('change', () => this.updatePreview());
            }
        });
    }

    updatePreview() {
        const percentage = parseFloat(this.percentageInput.value) || 10;
        const minFee = parseFloat(this.minFeeInput?.value) || 0;
        const maxFee = parseFloat(this.maxFeeInput?.value) || 0;
        
        const exampleValue = 50;
        const calculated = exampleValue * (percentage / 100);
        let finalCost = calculated;
        
        if (minFee > 0 && calculated < minFee) {
            finalCost = minFee;
        } else if (maxFee > 0 && calculated > maxFee) {
            finalCost = maxFee;
        }
        
        const strings = window.wcPercentageShipping?.strings || {};
        const currency = this.getCurrencySymbol();
        
        this.previewContainer.innerHTML = `
            <p><strong>${strings.cartValue || 'Cart value:'}</strong> ${currency}${exampleValue}</p>
            <p><strong>${strings.calculation || 'Calculation:'}</strong> ${currency}${exampleValue} × ${percentage}% = ${currency}${calculated.toFixed(2)}</p>
            <p><strong>${strings.finalFee || 'Final fee:'}</strong> ${currency}${finalCost.toFixed(2)}</p>
        `;
    }

    getCurrencySymbol() {
        // Try to get WooCommerce currency symbol, fallback to generic symbol
        const currencyElement = document.querySelector('span:contains("€"), span:contains("$"), span:contains("£")');
        return currencyElement ? currencyElement.textContent : '€';
    }
}

// Enhanced Form Validation
class FormValidation {
    constructor() {
        this.form = document.querySelector('form');
        this.errors = [];
        
        if (this.form) {
            this.bindEvents();
            this.addVisualValidation();
        }
    }

    bindEvents() {
        this.form.addEventListener('submit', (e) => this.validateForm(e));
        
        // Real-time validation
        const inputs = this.form.querySelectorAll('input[type="number"]');
        inputs.forEach(input => {
            input.addEventListener('blur', () => this.validateField(input));
            input.addEventListener('input', () => this.clearFieldError(input));
        });
    }

    validateForm(event) {
        this.errors = [];
        
        const percentageInput = this.form.querySelector('input[name="wc_percentage_shipping_options[percentage]"]');
        const minFeeInput = this.form.querySelector('input[name="wc_percentage_shipping_options[minimum_fee]"]');
        const maxFeeInput = this.form.querySelector('input[name="wc_percentage_shipping_options[maximum_fee]"]');
        
        // Validate percentage
        if (percentageInput) {
            const percentage = parseFloat(percentageInput.value);
            if (isNaN(percentage) || percentage < 0 || percentage > 100) {
                this.addError(percentageInput, window.wcPercentageShipping?.strings?.percentageError || 'Percentage must be between 0 and 100.');
            }
        }
        
        // Validate fee relationship
        if (minFeeInput && maxFeeInput) {
            const minFee = parseFloat(minFeeInput.value) || 0;
            const maxFee = parseFloat(maxFeeInput.value) || 0;
            
            if (maxFee > 0 && maxFee < minFee) {
                this.addError(maxFeeInput, window.wcPercentageShipping?.strings?.feeError || 'Maximum fee must be higher than minimum fee.');
            }
        }
        
        // Show errors and prevent submission if any
        if (this.errors.length > 0) {
            event.preventDefault();
            this.showErrors();
            return false;
        }
        
        return true;
    }

    validateField(input) {
        this.clearFieldError(input);
        
        const value = parseFloat(input.value);
        const min = parseFloat(input.getAttribute('min')) || 0;
        const max = parseFloat(input.getAttribute('max')) || Infinity;
        
        if (isNaN(value) || value < min || value > max) {
            this.addError(input, `Value must be between ${min} and ${max === Infinity ? '∞' : max}`);
        }
    }

    addError(input, message) {
        this.errors.push({ input, message });
        input.classList.add('error');
        
        // Add error message
        const errorElement = document.createElement('div');
        errorElement.className = 'field-error';
        errorElement.textContent = message;
        errorElement.style.color = '#d63638';
        errorElement.style.fontSize = '12px';
        errorElement.style.marginTop = '5px';
        
        input.parentNode.appendChild(errorElement);
    }

    clearFieldError(input) {
        input.classList.remove('error');
        const errorElement = input.parentNode.querySelector('.field-error');
        if (errorElement) {
            errorElement.remove();
        }
    }

    showErrors() {
        const firstError = this.errors[0];
        if (firstError) {
            firstError.input.focus();
            firstError.input.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    addVisualValidation() {
        const style = document.createElement('style');
        style.textContent = `
            .wc-percentage-shipping-admin input.error {
                border-color: #d63638 !important;
                box-shadow: 0 0 0 2px rgba(214, 54, 56, 0.1) !important;
            }
            .wc-percentage-shipping-admin input:valid {
                border-color: #00a32a;
            }
        `;
        document.head.appendChild(style);
    }
}

// AJAX Handler (Using Fetch API instead of jQuery.ajax)
class AjaxHandler {
    constructor() {
        this.ajaxUrl = window.wcPercentageShipping?.ajaxUrl || '';
        this.nonce = window.wcPercentageShipping?.nonce || '';
        this.requestQueue = [];
        this.isProcessing = false;
    }

    async previewCalculation(cartValue, percentage, minFee, maxFee) {
        if (!this.ajaxUrl || !this
