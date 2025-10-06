/**
 * Woo Percentage Shipping - Modern Admin Interface
 * Modern vertical sidebar navigation with search functionality
 */

// DOM Ready
function domReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback);
    } else {
        callback();
    }
}

// Modern Vertical Navigation System
class ModernNavigation {
    constructor() {
        this.sections = document.querySelectorAll('.nav-section');
        this.navItems = document.querySelectorAll('.nav-item');
        this.tabContents = document.querySelectorAll('.tab-content');
        this.searchInput = document.getElementById('settings-search');
        this.init();
    }

    init() {
        this.bindEvents();
        this.setActiveTab('basic-settings');
    }

    bindEvents() {
        // Section toggle events
        this.sections.forEach(section => {
            const header = section.querySelector('.nav-section-header');
            if (header) {
                header.addEventListener('click', () => this.toggleSection(section));
            }
        });

        // Nav item click events
        this.navItems.forEach(item => {
            item.addEventListener('click', (e) => this.handleNavClick(e));
        });

        // Search functionality
        if (this.searchInput) {
            this.searchInput.addEventListener('input', (e) => this.handleSearch(e));
            this.searchInput.addEventListener('keydown', (e) => this.handleSearchKeyboard(e));
        }

        // Global keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleGlobalKeyboard(e));
    }

    toggleSection(section) {
        const content = section.querySelector('.nav-section-content');
        const toggle = section.querySelector('.nav-toggle');
        
        if (!content || !toggle) return;

        const isExpanded = content.style.display !== 'none';
        
        if (isExpanded) {
            content.style.display = 'none';
            section.classList.remove('expanded');
            toggle.classList.remove('dashicons-arrow-up-alt2');
            toggle.classList.add('dashicons-arrow-down-alt2');
        } else {
            content.style.display = 'block';
            section.classList.add('expanded');
            toggle.classList.remove('dashicons-arrow-down-alt2');
            toggle.classList.add('dashicons-arrow-up-alt2');
        }
    }

    handleNavClick(event) {
        event.preventDefault();
        const tabId = event.currentTarget.getAttribute('data-tab');
        if (tabId) {
            this.setActiveTab(tabId);
        }
    }

    setActiveTab(tabId) {
        // Remove active class from all nav items
        this.navItems.forEach(item => item.classList.remove('active'));
        
        // Add active class to current nav item
        const activeItem = document.querySelector(`[data-tab="${tabId}"]`);
        if (activeItem) {
            activeItem.classList.add('active');
            
            // Expand parent section
            const parentSection = activeItem.closest('.nav-section');
            if (parentSection) {
                const content = parentSection.querySelector('.nav-section-content');
                const toggle = parentSection.querySelector('.nav-toggle');
                
                if (content && toggle) {
                    content.style.display = 'block';
                    parentSection.classList.add('expanded');
                    toggle.classList.remove('dashicons-arrow-down-alt2');
                    toggle.classList.add('dashicons-arrow-up-alt2');
                }
            }
        }

        // Hide all tab contents
        this.tabContents.forEach(content => content.classList.remove('active'));
        
        // Show active tab content
        const activeContent = document.getElementById(`tab-${tabId}`);
        if (activeContent) {
            activeContent.classList.add('active');
        }

        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.replaceState({}, '', url);
    }

    handleSearch(event) {
        const query = event.target.value.toLowerCase().trim();
        
        if (query === '') {
            this.showAllItems();
            return;
        }

        this.navItems.forEach(item => {
            const text = item.textContent.toLowerCase();
            const parentSection = item.closest('.nav-section');
            
            if (text.includes(query)) {
                item.style.display = 'block';
                item.classList.add('search-highlight');
                // Expand parent section
                if (parentSection) {
                    const content = parentSection.querySelector('.nav-section-content');
                    const toggle = parentSection.querySelector('.nav-toggle');
                    
                    if (content && toggle) {
                        content.style.display = 'block';
                        parentSection.classList.add('expanded');
                        toggle.classList.remove('dashicons-arrow-down-alt2');
                        toggle.classList.add('dashicons-arrow-up-alt2');
                    }
                }
            } else {
                item.style.display = 'none';
                item.classList.remove('search-highlight');
            }
        });

        // Hide sections with no visible items
        this.sections.forEach(section => {
            const visibleItems = section.querySelectorAll('.nav-item:not([style*="display: none"])');
            if (visibleItems.length === 0 && query !== '') {
                section.style.display = 'none';
            } else {
                section.style.display = 'block';
            }
        });
    }

    showAllItems() {
        this.navItems.forEach(item => {
            item.style.display = 'block';
            item.classList.remove('search-highlight');
        });
        
        this.sections.forEach(section => {
            section.style.display = 'block';
        });
    }

    handleSearchKeyboard(event) {
        if (event.key === 'Escape') {
            this.searchInput.value = '';
            this.showAllItems();
            this.searchInput.blur();
        } else if (event.key === 'Enter') {
            event.preventDefault();
            const firstMatch = document.querySelector('.nav-item.search-highlight');
            if (firstMatch) {
                firstMatch.click();
            }
        }
    }

    handleGlobalKeyboard(event) {
        // Ctrl+K or Cmd+K for search focus
        if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
            event.preventDefault();
            if (this.searchInput) {
                this.searchInput.focus();
                this.searchInput.select();
            }
        }

        // Ctrl+S or Cmd+S for save
        if ((event.ctrlKey || event.metaKey) && event.key === 's') {
            event.preventDefault();
            const form = document.getElementById('settings-form');
            if (form) {
                form.requestSubmit();
            }
        }
    }
}

// Live Preview Calculator
class LivePreview {
    constructor() {
        this.percentageInput = document.querySelector('input[name*="percentage"]');
        this.minFeeInput = document.querySelector('input[name*="minimum_fee"]');
        this.maxFeeInput = document.querySelector('input[name*="maximum_fee"]');
        
        this.init();
    }

    init() {
        if (this.percentageInput) {
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
        
        // Update preview elements if they exist
        const cartValueEl = document.getElementById('cart-value');
        const percentageCalcEl = document.getElementById('percentage-calculation');
        const finalCostEl = document.getElementById('final-cost');
        
        if (cartValueEl) {
            cartValueEl.textContent = `${currency}50.00`;
        }
        
        if (percentageCalcEl) {
            percentageCalcEl.textContent = `${currency}50.00 × ${percentage}% = ${currency}${calculated.toFixed(2)}`;
        }
        
        if (finalCostEl) {
            finalCostEl.textContent = `${currency}${finalCost.toFixed(2)}`;
        }
    }

    getCurrencySymbol() {
        const prefix = document.querySelector('.prefix');
        return prefix ? prefix.textContent : '€';
    }

    debounceUpdate() {
        clearTimeout(this.updateTimeout);
        this.updateTimeout = setTimeout(() => {
            this.updatePreview();
        }, 300);
    }
}

// Form Validation
class FormValidation {
    constructor() {
        this.form = document.getElementById('settings-form');
        this.init();
    }
        
    init() {
        if (this.form) {
            this.bindEvents();
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
        const percentageInput = this.form.querySelector('input[name*="percentage"]');
        const minFeeInput = this.form.querySelector('input[name*="minimum_fee"]');
        const maxFeeInput = this.form.querySelector('input[name*="maximum_fee"]');
        
        let isValid = true;
        
        // Validate percentage
        if (percentageInput) {
            const percentage = parseFloat(percentageInput.value);
            if (isNaN(percentage) || percentage < 0 || percentage > 100) {
                this.showFieldError(percentageInput, 'Percentage must be between 0 and 100.');
                isValid = false;
            }
        }
        
        // Validate fee relationship
        if (minFeeInput && maxFeeInput) {
            const minFee = parseFloat(minFeeInput.value) || 0;
            const maxFee = parseFloat(maxFeeInput.value) || 0;
            
            if (maxFee > 0 && maxFee < minFee) {
                this.showFieldError(maxFeeInput, 'Maximum fee must be higher than minimum fee.');
                isValid = false;
            }
        }
        
        if (!isValid) {
            event.preventDefault();
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
            this.showFieldError(input, `Value must be between ${min} and ${max === Infinity ? '∞' : max}`);
        }
    }

    showFieldError(input, message) {
        input.classList.add('error');
        
        const errorEl = document.createElement('div');
        errorEl.className = 'field-error';
        errorEl.textContent = message;
        errorEl.style.cssText = `
            color: #d63638;
            font-size: 12px;
            margin-top: 4px;
            font-weight: 500;
        `;
        
        const td = input.closest('td');
        if (td) {
            td.appendChild(errorEl);
        }
    }

    clearFieldError(input) {
        input.classList.remove('error');
        const td = input.closest('td');
        if (td) {
            const errorEl = td.querySelector('.field-error');
            if (errorEl) {
                errorEl.remove();
            }
        }
    }
}

// Enhanced Admin Interface
class ModernAdminInterface {
    constructor() {
        this.navigation = new ModernNavigation();
        this.livePreview = new LivePreview();
        this.formValidation = new FormValidation();
        
        this.init();
    }

    init() {
        this.addEventListeners();
        this.enhanceUI();
    }

    addEventListeners() {
        // Form submission enhancement
        const form = document.getElementById('settings-form');
        if (form) {
            form.addEventListener('submit', (e) => this.handleFormSubmit(e));
        }

        // Reset settings button
        const resetBtn = document.getElementById('reset-settings');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.handleResetSettings());
        }
    }

    enhanceUI() {
        this.addLoadingStates();
        this.addVisualFeedback();
        this.addSearchHighlighting();
    }

    addLoadingStates() {
        const submitButton = document.getElementById('save-settings');
        if (submitButton) {
            submitButton.addEventListener('click', () => {
                const originalText = submitButton.textContent;
                submitButton.textContent = 'Saving...';
                submitButton.disabled = true;
                
                setTimeout(() => {
                    submitButton.textContent = originalText;
                    submitButton.disabled = false;
                }, 2000);
            });
        }
    }

    addVisualFeedback() {
        // Add hover effects to form rows
        const formRows = document.querySelectorAll('.form-table tr');
        formRows.forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.backgroundColor = '#f8f9fa';
            });
            
            row.addEventListener('mouseleave', () => {
                row.style.backgroundColor = '';
            });
        });

        // Add focus effects to inputs
        const inputs = document.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.style.borderColor = '#2271b1';
                input.style.boxShadow = '0 0 0 2px rgba(34, 113, 177, 0.1)';
            });
            
            input.addEventListener('blur', () => {
                input.style.borderColor = '#ddd';
                input.style.boxShadow = 'none';
            });
        });
    }

    addSearchHighlighting() {
        // Add CSS for search highlighting
        if (!document.querySelector('#search-highlight-styles')) {
        const style = document.createElement('style');
            style.id = 'search-highlight-styles';
        style.textContent = `
                .nav-item.search-highlight {
                    background: #fff3cd !important;
                    color: #856404 !important;
                    font-weight: 500;
                }
                
                .nav-item.search-highlight::before {
                    content: "🔍";
                    margin-right: 8px;
            }
        `;
        document.head.appendChild(style);
    }
}

    handleFormSubmit(event) {
        const form = event.target;
        form.style.opacity = '0.8';
        
        setTimeout(() => {
            form.style.opacity = '1';
        }, 1000);
    }

    handleResetSettings() {
        if (confirm('Are you sure you want to reset all settings to defaults? This action cannot be undone.')) {
            const form = document.getElementById('settings-form');
            if (form) {
                form.reset();
                this.showNotification('Settings reset to defaults. Click "Save Changes" to apply.', 'warning');
            }
        }
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
        
        notification.innerHTML = `
            <p style="margin: 0; padding: 12px; font-weight: 500;">${message}</p>
            <button type="button" class="notice-dismiss" style="position: absolute; top: 0; right: 0; border: none; background: none; padding: 8px; cursor: pointer;">
                <span class="screen-reader-text">Dismiss this notice.</span>
            </button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-dismiss after 4 seconds
        setTimeout(() => {
            notification.remove();
        }, 4000);
        
        // Manual dismiss
        notification.querySelector('.notice-dismiss').addEventListener('click', () => {
            notification.remove();
        });
    }
}

// Initialize everything when DOM is ready
domReady(() => {
    new ModernAdminInterface();
    
    // Add global styles for animations
    if (!document.querySelector('#wc-admin-animations')) {
        const style = document.createElement('style');
        style.id = 'wc-admin-animations';
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
            
            .form-table tr {
                transition: background-color 0.2s ease;
            }
            
            input, select, textarea {
                transition: all 0.2s ease;
            }
        `;
        document.head.appendChild(style);
    }
});