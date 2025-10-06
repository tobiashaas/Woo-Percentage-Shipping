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
        if (!this.ajaxUrl || !this.nonce) {
            throw new Error('AJAX configuration missing');
        }

        // Add to request queue
        return new Promise((resolve, reject) => {
            this.requestQueue.push({ cartValue, percentage, minFee, maxFee, resolve, reject });
            this.processQueue();
        });
    }

    async processQueue() {
        if (this.isProcessing || this.requestQueue.length === 0) {
            return;
        }

        this.isProcessing = true;
        const request = this.requestQueue.shift();

        try {
            const formData = new FormData();
            formData.append('action', 'wc_percentage_shipping_preview');
            formData.append('nonce', this.nonce);
            formData.append('cart_value', request.cartValue);
            formData.append('percentage', request.percentage);
            formData.append('minimum_fee', request.minFee);
            formData.append('maximum_fee', request.maxFee);

            const response = await fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            if (data.success) {
                request.resolve(data.data);
            } else {
                throw new Error(data.data.message || 'Unknown error');
            }
        } catch (error) {
            request.reject(error);
        } finally {
            this.isProcessing = false;
            // Process next request in queue
            if (this.requestQueue.length > 0) {
                setTimeout(() => this.processQueue(), 100);
            }
        }
    }
}

// Enhanced Admin Interface
class AdminInterface {
    constructor() {
        this.tooltip = new VanillaTooltip();
        this.livePreview = new LivePreview();
        this.formValidation = new FormValidation();
        this.ajaxHandler = new AjaxHandler();
        
        this.init();
    }

    init() {
        this.addEventListeners();
        this.enhanceUI();
    }

    addEventListeners() {
        // Real-time calculation updates
        const inputs = document.querySelectorAll('input[name*="percentage"], input[name*="minimum_fee"], input[name*="maximum_fee"]');
        inputs.forEach(input => {
            input.addEventListener('input', () => this.debounceUpdate());
        });

        // Form submission enhancement
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        }
    }

    enhanceUI() {
        // Add loading states
        this.addLoadingStates();
        
        // Enhance accessibility
        this.enhanceAccessibility();
        
        // Add keyboard shortcuts
        this.addKeyboardShortcuts();
    }

    addLoadingStates() {
        const submitButton = document.querySelector('#submit');
        if (submitButton) {
            submitButton.addEventListener('click', () => {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
                
                // Re-enable after 3 seconds as fallback
                setTimeout(() => {
                    submitButton.disabled = false;
                    submitButton.textContent = 'Save Settings';
                }, 3000);
            });
        }
    }

    enhanceAccessibility() {
        // Add ARIA labels
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (!input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')) {
                const label = input.closest('tr')?.querySelector('th')?.textContent;
                if (label) {
                    input.setAttribute('aria-label', label.trim());
                }
            }
        });

        // Add focus management
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                this.manageFocus(e);
            }
        });
    }

    addKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const form = document.querySelector('form');
                if (form) {
                    form.requestSubmit();
                }
            }
        });
    }

    manageFocus(event) {
        const activeElement = document.activeElement;
        const tooltip = document.querySelector('.wc-percentage-shipping-tooltip');
        
        if (tooltip && tooltip.style.opacity === '1') {
            // Hide tooltip when navigating away from help tip
            const helpTip = document.querySelector('.wc-percentage-shipping-help-tip:hover');
            if (!helpTip) {
                this.tooltip.hideTooltip();
            }
        }
    }

    debounceUpdate() {
        clearTimeout(this.updateTimeout);
        this.updateTimeout = setTimeout(() => {
            this.updateLivePreview();
        }, 300);
    }

    async updateLivePreview() {
        try {
            const percentage = parseFloat(document.querySelector('input[name*="percentage"]')?.value) || 10;
            const minFee = parseFloat(document.querySelector('input[name*="minimum_fee"]')?.value) || 0;
            const maxFee = parseFloat(document.querySelector('input[name*="maximum_fee"]')?.value) || 0;
            
            const result = await this.ajaxHandler.previewCalculation(50, percentage, minFee, maxFee);
            
            this.displayPreviewResult(result);
        } catch (error) {
            console.warn('Preview update failed:', error);
            // Fallback to local calculation
            this.livePreview.updatePreview();
        }
    }

    displayPreviewResult(result) {
        const previewContainer = document.querySelector('.preview-example');
        if (previewContainer && result) {
            previewContainer.innerHTML = `
                <p><strong>${window.wcPercentageShipping?.strings?.cartValue || 'Cart value:'}</strong> €50.00</p>
                <p><strong>${window.wcPercentageShipping?.strings?.calculation || 'Calculation:'}</strong> ${result.explanation}</p>
                <p><strong>${window.wcPercentageShipping?.strings?.finalFee || 'Final fee:'}</strong> ${result.final_cost}</p>
            `;
            previewContainer.style.animation = 'fadeIn 0.5s ease-in-out';
        }
    }

    handleFormSubmit(event) {
        // Add visual feedback
        const form = event.target;
        form.style.opacity = '0.7';
        
        // Show success message after submission
        setTimeout(() => {
            form.style.opacity = '1';
            this.showSuccessMessage();
        }, 1000);
    }

    showSuccessMessage() {
        // Create temporary success notification
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 32px;
            right: 20px;
            background: #00a32a;
            color: white;
            padding: 12px 20px;
            border-radius: 4px;
            z-index: 9999;
            animation: slideIn 0.3s ease-out;
        `;
        notification.textContent = 'Settings saved successfully!';
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
}

// Initialize everything when DOM is ready
domReady(() => {
    // Initialize admin interface
    new AdminInterface();
    
    // Add CSS animations if not already present
    if (!document.querySelector('#wc-percentage-shipping-animations')) {
        const style = document.createElement('style');
        style.id = 'wc-percentage-shipping-animations';
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    }
});
