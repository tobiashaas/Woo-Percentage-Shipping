/**
 * WooCommerce Percentage Shipping - Modern Admin Interface
 * Vanilla JavaScript implementation with modern features
 */

// DOM Ready equivalent
function domReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback);
    } else {
        callback();
    }
}

// Modern Tooltip System
class ModernTooltip {
    constructor() {
        this.tooltip = null;
        this.init();
    }

    init() {
        this.createTooltipElement();
        this.bindEvents();
    }

    createTooltipElement() {
        this.tooltip = document.createElement('div');
        this.tooltip.className = 'wc-percentage-shipping-tooltip';
        this.tooltip.style.cssText = `
            position: absolute;
            background: #1d2327;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            z-index: 9999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s ease;
            white-space: nowrap;
            max-width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            font-weight: 500;
        `;
        document.body.appendChild(this.tooltip);
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

        event.target.setAttribute('data-original-title', tipText);
        event.target.removeAttribute('title');

        this.tooltip.textContent = tipText;
        this.tooltip.style.opacity = '1';
        this.moveTooltip(event);
    }

    hideTooltip() {
        this.tooltip.style.opacity = '0';
        
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
        this.percentageInput = document.querySelector('input[name*="percentage"]');
        this.minFeeInput = document.querySelector('input[name*="minimum_fee"]');
        this.maxFeeInput = document.querySelector('input[name*="maximum_fee"]');
        this.calculationPreview = document.getElementById('calculation-preview');
        this.finalFeePreview = document.getElementById('final-fee-preview');
        
        if (this.percentageInput && this.calculationPreview) {
            this.bindEvents();
            this.updatePreview();
        }
    }

    bindEvents() {
        const inputs = [this.percentageInput, this.minFeeInput, this.maxFeeInput];
        
        inputs.forEach(input => {
            if (input) {
                input.addEventListener('input', () => this.debounceUpdate());
                input.addEventListener('change', () => this.updatePreview());
            }
        });
    }

    updatePreview() {
        const percentage = parseFloat(this.percentageInput?.value) || 10;
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
        
        const currency = this.getCurrencySymbol();
        
        if (this.calculationPreview) {
            this.calculationPreview.innerHTML = `${currency}${exampleValue} × ${percentage}% = ${currency}${calculated.toFixed(2)}`;
        }
        
        if (this.finalFeePreview) {
            this.finalFeePreview.innerHTML = `${currency}${finalCost.toFixed(2)}`;
        }
    }

    getCurrencySymbol() {
        const currencyElement = document.querySelector('.input-prefix');
        return currencyElement ? currencyElement.textContent : '€';
    }

    debounceUpdate() {
        clearTimeout(this.updateTimeout);
        this.updateTimeout = setTimeout(() => {
            this.updatePreview();
        }, 300);
    }
}

// Enhanced Form Validation
class FormValidation {
    constructor() {
        this.form = document.querySelector('.settings-form');
        this.errors = [];
        
        if (this.form) {
            this.bindEvents();
            this.addVisualValidation();
        }
    }

    bindEvents() {
        this.form.addEventListener('submit', (e) => this.validateForm(e));
        
        const inputs = this.form.querySelectorAll('input[type="number"]');
        inputs.forEach(input => {
            input.addEventListener('blur', () => this.validateField(input));
            input.addEventListener('input', () => this.clearFieldError(input));
        });
    }

    validateForm(event) {
        this.errors = [];
        
        const percentageInput = this.form.querySelector('input[name*="percentage"]');
        const minFeeInput = this.form.querySelector('input[name*="minimum_fee"]');
        const maxFeeInput = this.form.querySelector('input[name*="maximum_fee"]');
        
        if (percentageInput) {
            const percentage = parseFloat(percentageInput.value);
            if (isNaN(percentage) || percentage < 0 || percentage > 100) {
                this.addError(percentageInput, 'Percentage must be between 0 and 100.');
            }
        }
        
        if (minFeeInput && maxFeeInput) {
            const minFee = parseFloat(minFeeInput.value) || 0;
            const maxFee = parseFloat(maxFeeInput.value) || 0;
            
            if (maxFee > 0 && maxFee < minFee) {
                this.addError(maxFeeInput, 'Maximum fee must be higher than minimum fee.');
            }
        }
        
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
        
        const errorElement = document.createElement('div');
        errorElement.className = 'field-error';
        errorElement.textContent = message;
        errorElement.style.cssText = `
            color: #d63638;
            font-size: 12px;
            margin-top: 8px;
            font-weight: 500;
        `;
        
        input.closest('.setting-control').appendChild(errorElement);
    }

    clearFieldError(input) {
        input.classList.remove('error');
        const errorElement = input.closest('.setting-control').querySelector('.field-error');
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
            .input-group.error {
                border-color: #d63638 !important;
                box-shadow: 0 0 0 3px rgba(214, 54, 56, 0.1) !important;
            }
            .input-group:valid {
                border-color: #00a32a;
            }
        `;
        document.head.appendChild(style);
    }
}

// AJAX Handler with Modern Fetch API
class AjaxHandler {
    constructor() {
        this.ajaxUrl = window.wcPercentageShipping?.ajaxUrl || '';
        this.nonce = window.wcPercentageShipping?.nonce || '';
        this.requestQueue = [];
        this.isProcessing = false;
    }

    async clearCache() {
        if (!this.ajaxUrl || !this.nonce) {
            throw new Error('AJAX configuration missing');
        }

        const formData = new FormData();
        formData.append('action', 'wc_percentage_shipping_clear_cache');
        formData.append('nonce', this.nonce);

        const response = await fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.data.message || 'Unknown error');
        }

        return data.data;
    }

    async exportSettings() {
        if (!this.ajaxUrl || !this.nonce) {
            throw new Error('AJAX configuration missing');
        }

        const formData = new FormData();
        formData.append('action', 'wc_percentage_shipping_export');
        formData.append('nonce', this.nonce);

        const response = await fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.data.message || 'Unknown error');
        }

        return data.data;
    }
}

// Quick Actions Handler
class QuickActions {
    constructor() {
        this.ajaxHandler = new AjaxHandler();
        this.bindEvents();
    }

    bindEvents() {
        const clearCacheBtn = document.getElementById('clear-cache');
        const exportBtn = document.getElementById('export-settings');
        const resetBtn = document.getElementById('reset-settings');

        if (clearCacheBtn) {
            clearCacheBtn.addEventListener('click', () => this.handleClearCache());
        }

        if (exportBtn) {
            exportBtn.addEventListener('click', () => this.handleExportSettings());
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.handleResetSettings());
        }
    }

    async handleClearCache() {
        const button = document.getElementById('clear-cache');
        const originalText = button.innerHTML;
        
        try {
            button.innerHTML = '<span class="dashicons dashicons-update"></span> Clearing...';
            button.disabled = true;
            
            await this.ajaxHandler.clearCache();
            this.showNotification('Cache cleared successfully!', 'success');
        } catch (error) {
            this.showNotification('Failed to clear cache: ' + error.message, 'error');
        } finally {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }

    async handleExportSettings() {
        const button = document.getElementById('export-settings');
        const originalText = button.innerHTML;
        
        try {
            button.innerHTML = '<span class="dashicons dashicons-download"></span> Exporting...';
            button.disabled = true;
            
            const data = await this.ajaxHandler.exportSettings();
            this.downloadFile(data, 'wc-percentage-shipping-settings.json');
            this.showNotification('Settings exported successfully!', 'success');
        } catch (error) {
            this.showNotification('Failed to export settings: ' + error.message, 'error');
        } finally {
            button.innerHTML = originalText;
            button.disabled = false;
        }
    }

    handleResetSettings() {
        if (confirm('Are you sure you want to reset all settings to defaults? This action cannot be undone.')) {
            const form = document.querySelector('.settings-form');
            if (form) {
                form.reset();
                this.showNotification('Settings reset to defaults. Click "Save Changes" to apply.', 'warning');
            }
        }
    }

    downloadFile(data, filename) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notice notice-${type} is-dismissible`;
        notification.style.cssText = `
            position: fixed;
            top: 32px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            animation: slideInRight 0.3s ease-out;
        `;
        
        const colors = {
            success: '#00a32a',
            error: '#d63638',
            warning: '#f0b849',
            info: '#2271b1'
        };
        
        notification.style.borderLeftColor = colors[type] || colors.info;
        
        notification.innerHTML = `
            <p style="margin: 0; padding: 12px; font-weight: 500;">${message}</p>
            <button type="button" class="notice-dismiss" style="position: absolute; top: 0; right: 0; border: none; background: none; padding: 8px; cursor: pointer;">
                <span class="screen-reader-text">Dismiss this notice.</span>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            notification.remove();
        }, 5000);
        
        // Manual dismiss
        notification.querySelector('.notice-dismiss').addEventListener('click', () => {
            notification.remove();
        });
    }
}

// Enhanced Admin Interface
class ModernAdminInterface {
    constructor() {
        this.tooltip = new ModernTooltip();
        this.livePreview = new LivePreview();
        this.formValidation = new FormValidation();
        this.quickActions = new QuickActions();
        
        this.init();
    }

    init() {
        this.addEventListeners();
        this.enhanceUI();
        this.addAnimations();
    }

    addEventListeners() {
        // Form submission enhancement
        const form = document.querySelector('.settings-form');
        if (form) {
            form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        }

        // Tab switching enhancement
        const tabs = document.querySelectorAll('.nav-tab');
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => this.handleTabSwitch(e));
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
    }

    enhanceUI() {
        this.addLoadingStates();
        this.enhanceAccessibility();
        this.addFormEnhancements();
    }

    addLoadingStates() {
        const submitButton = document.getElementById('save-settings');
        if (submitButton) {
            submitButton.addEventListener('click', () => {
                submitButton.innerHTML = '<span class="dashicons dashicons-yes"></span> Saving...';
                submitButton.disabled = true;
                
                setTimeout(() => {
                    submitButton.innerHTML = 'Save Changes';
                    submitButton.disabled = false;
                }, 3000);
            });
        }
    }

    enhanceAccessibility() {
        // Add ARIA labels
        const inputs = document.querySelectorAll('input, select');
        inputs.forEach(input => {
            if (!input.getAttribute('aria-label') && !input.getAttribute('aria-labelledby')) {
                const label = input.closest('.setting-group')?.querySelector('.setting-label')?.textContent;
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

    addFormEnhancements() {
        // Add visual feedback for form interactions
        const settingGroups = document.querySelectorAll('.setting-group');
        settingGroups.forEach(group => {
            const input = group.querySelector('input, select');
            if (input) {
                input.addEventListener('focus', () => {
                    group.style.transform = 'translateY(-2px)';
                    group.style.boxShadow = '0 4px 12px rgba(34, 113, 177, 0.15)';
                });
                
                input.addEventListener('blur', () => {
                    group.style.transform = 'translateY(0)';
                    group.style.boxShadow = '0 2px 8px rgba(34, 113, 177, 0.1)';
                });
            }
        });
    }

    addAnimations() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.05); }
                100% { transform: scale(1); }
            }
            
            .setting-group {
                transition: all 0.2s ease;
            }
            
            .button {
                transition: all 0.2s ease;
            }
            
            .button:hover {
                transform: translateY(-1px);
            }
        `;
        document.head.appendChild(style);
    }

    manageFocus(event) {
        const activeElement = document.activeElement;
        const tooltip = document.querySelector('.wc-percentage-shipping-tooltip');
        
        if (tooltip && tooltip.style.opacity === '1') {
            const helpTip = document.querySelector('.wc-percentage-shipping-help-tip:hover');
            if (!helpTip) {
                this.tooltip.hideTooltip();
            }
        }
    }

    handleFormSubmit(event) {
        const form = event.target;
        form.style.opacity = '0.7';
        
        setTimeout(() => {
            form.style.opacity = '1';
        }, 1000);
    }

    handleTabSwitch(event) {
        // Add visual feedback for tab switching
        const tab = event.target;
        tab.style.transform = 'scale(0.95)';
        setTimeout(() => {
            tab.style.transform = 'scale(1)';
        }, 150);
    }

    handleKeyboardShortcuts(event) {
        // Ctrl/Cmd + S to save
        if ((event.ctrlKey || event.metaKey) && event.key === 's') {
            event.preventDefault();
            const form = document.querySelector('.settings-form');
            if (form) {
                form.requestSubmit();
            }
        }
        
        // Ctrl/Cmd + Enter to save
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            const form = document.querySelector('.settings-form');
            if (form) {
                form.requestSubmit();
            }
        }
    }
}

// Initialize everything when DOM is ready
domReady(() => {
    // Initialize modern admin interface
    new ModernAdminInterface();
    
    // Add global styles for animations
    if (!document.querySelector('#wc-percentage-shipping-animations')) {
        const style = document.createElement('style');
        style.id = 'wc-percentage-shipping-animations';
        style.textContent = `
            .wc-percentage-shipping-admin * {
                box-sizing: border-box;
            }
            
            .wc-percentage-shipping-admin {
                scroll-behavior: smooth;
            }
        `;
        document.head.appendChild(style);
    }
});