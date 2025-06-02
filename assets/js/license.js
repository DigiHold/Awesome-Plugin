/**
 * License Management JavaScript for DigiCommerce Licensing System
 * This handles all the AJAX interactions for license activation, deactivation, and verification
 */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    const LicenseManager = {
        // DOM elements
        elements: {
            form: document.getElementById('license-form'),
            activateBtn: document.getElementById('activate-license'),
            deactivateBtn: document.getElementById('deactivate-license'),
            verifyBtn: document.getElementById('verify-license'),
            licenseInput: document.getElementById('license_key'),
            messageContainer: document.getElementById('license-message')
        },

        // Initialize the license manager
        init() {
            this.bindEvents();
            this.validateElements();
        },

        // Validate that required elements exist
        validateElements() {
            if (!window.awesomeLicense) {
                console.error('License manager configuration not found');
                return false;
            }
            return true;
        },

        // Bind event listeners
        bindEvents() {
            // License activation
            if (this.elements.form && this.elements.activateBtn) {
                this.elements.form.addEventListener('submit', (e) => this.handleActivation(e));
                this.elements.activateBtn.addEventListener('click', (e) => this.handleActivation(e));
            }

            // License deactivation
            if (this.elements.deactivateBtn) {
                this.elements.deactivateBtn.addEventListener('click', (e) => this.handleDeactivation(e));
            }

            // License verification
            if (this.elements.verifyBtn) {
                this.elements.verifyBtn.addEventListener('click', (e) => this.handleVerification(e));
            }

            // Enter key support for license input
            if (this.elements.licenseInput) {
                this.elements.licenseInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && this.elements.activateBtn) {
                        e.preventDefault();
                        this.handleActivation(e);
                    }
                });
            }
        },

        // Extract error message from response
        getErrorMessage(response) {
            // Handle different response structures
            if (response.data) {
                // If response.data is a string, return it directly
                if (typeof response.data === 'string') {
                    return response.data;
                }
                // If response.data is an object with message property
                if (typeof response.data === 'object' && response.data.message) {
                    return response.data.message;
                }
                // If response.data is an object, try to stringify it meaningfully
                if (typeof response.data === 'object') {
                    return JSON.stringify(response.data);
                }
            }
            
            // Fallback to default error message
            return awesomeLicense.strings.error;
        },

        // Handle license activation
        async handleActivation(e) {
            e.preventDefault();

            const licenseKey = this.elements.licenseInput?.value?.trim();
            if (!licenseKey) {
                this.showMessage(awesomeLicense.strings.enterLicenseKey, 'error');
                this.elements.licenseInput?.focus();
                return;
            }

            const button = this.elements.activateBtn;
            this.setButtonState(button, true, awesomeLicense.strings.activating);

            try {
                const response = await this.makeRequest('activate_license', {
                    license_key: licenseKey
                });

                if (response.success) {
                    this.showMessage(awesomeLicense.strings.licenseActivated, 'success');
                    // Reload page after successful activation
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    const errorMessage = this.getErrorMessage(response);
                    this.showMessage(errorMessage, 'error');
                    this.setButtonState(button, false);
                }
            } catch (error) {
                console.error('License activation error:', error);
                this.showMessage(awesomeLicense.strings.error, 'error');
                this.setButtonState(button, false);
            }
        },

        // Handle license deactivation
        async handleDeactivation(e) {
            e.preventDefault();

            if (!confirm(awesomeLicense.strings.confirmDeactivate)) {
                return;
            }

            const button = this.elements.deactivateBtn;
            this.setButtonState(button, true, awesomeLicense.strings.deactivating);

            try {
                const response = await this.makeRequest('deactivate_license');

                if (response.success) {
                    this.showMessage(awesomeLicense.strings.licenseDeactivated, 'success');
                    // Reload page after successful deactivation
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    const errorMessage = this.getErrorMessage(response);
                    this.showMessage(errorMessage, 'error');
                    this.setButtonState(button, false);
                }
            } catch (error) {
                console.error('License deactivation error:', error);
                this.showMessage(awesomeLicense.strings.error, 'error');
                this.setButtonState(button, false);
            }
        },

        // Handle license verification
        async handleVerification(e) {
            e.preventDefault();

            const button = this.elements.verifyBtn;
            this.setButtonState(button, true, awesomeLicense.strings.verifying);

            try {
                const response = await this.makeRequest('verify_license');

                // Always show the message first
                if (response.success) {
                    this.showMessage(awesomeLicense.strings.licenseVerified, 'success');
                } else {
                    const errorMessage = this.getErrorMessage(response);
                    this.showMessage(errorMessage, 'error');
                }

                // Reset button state
                this.setButtonState(button, false);

                // Reload page to reflect any changes
                setTimeout(() => {
                    window.location.reload();
                }, 1500);

            } catch (error) {
                console.error('License verification error:', error);
                this.showMessage(awesomeLicense.strings.error, 'error');
                this.setButtonState(button, false);
            }
        },

        // Make AJAX request to WordPress
        async makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', `${awesomeLicense.pluginSlug}_${action}`);
            formData.append('nonce', awesomeLicense.nonce);

            // Add additional data
            Object.entries(data).forEach(([key, value]) => {
                formData.append(key, value);
            });

            const response = await fetch(awesomeLicense.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            
            // Log response for debugging (remove in production)
            console.log('License API Response:', result);
            
            return result;
        },

        // Set button loading state
        setButtonState(button, loading, loadingText = '') {
            if (!button) return;

            button.disabled = loading;
            
            if (loading && loadingText) {
                // Store original text
                if (!button.dataset.originalText) {
                    button.dataset.originalText = button.textContent;
                }
                button.textContent = loadingText;
                button.classList.add('updating-message');
            } else if (!loading) {
                // Restore original text
                if (button.dataset.originalText) {
                    button.textContent = button.dataset.originalText;
                    delete button.dataset.originalText;
                }
                button.classList.remove('updating-message');
            }
        },

        // Show message to user
        showMessage(message, type = 'info') {
            // Remove existing messages
            this.clearMessages();

            // Create message element
            const messageEl = document.createElement('div');
            messageEl.className = `notice notice-${type} is-dismissible`;
            messageEl.innerHTML = `<p>${this.escapeHtml(message)}</p>`;

            // Add dismiss functionality
            const dismissBtn = document.createElement('button');
            dismissBtn.type = 'button';
            dismissBtn.className = 'notice-dismiss';
            dismissBtn.innerHTML = '<span class="screen-reader-text">Dismiss this notice.</span>';
            dismissBtn.addEventListener('click', () => {
                messageEl.remove();
            });
            messageEl.appendChild(dismissBtn);

            // Show message
            if (this.elements.messageContainer) {
                this.elements.messageContainer.appendChild(messageEl);
                this.elements.messageContainer.style.display = 'block';
            } else {
                // Fallback: insert at top of form or page
                const target = this.elements.form || document.querySelector('.wrap') || document.body;
                target.insertBefore(messageEl, target.firstChild);
            }

            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => {
                    if (messageEl.parentNode) {
                        messageEl.remove();
                    }
                }, 5000);
            }

            // Scroll to message
            messageEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        // Clear all messages
        clearMessages() {
            const messages = document.querySelectorAll('.notice');
            messages.forEach(message => {
                // Only remove our dynamically created messages
                if (message.querySelector('.notice-dismiss')) {
                    message.remove();
                }
            });

            if (this.elements.messageContainer) {
                this.elements.messageContainer.style.display = 'none';
            }
        },

        // Escape HTML to prevent XSS
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    LicenseManager.init();

    // Make LicenseManager available globally for debugging
    window.LicenseManager = LicenseManager;
});