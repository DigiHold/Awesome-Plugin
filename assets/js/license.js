document.addEventListener('DOMContentLoaded', function () {
    const LicenseManager = {
        elements: {
            form: document.getElementById('license-form'),
            activateBtn: document.getElementById('activate-license'),
            deactivateBtn: document.getElementById('deactivate-license'),
            verifyBtn: document.getElementById('verify-license'),
            keyInput: document.getElementById('license_key'),
            messageEl: document.getElementById('license-message'),
            infoEl: document.getElementById('license-info'),
        },

        init() {
            this.bindEvents();
        },

        bindEvents() {
            if (this.elements.form) {
                this.elements.form.addEventListener('submit', (e) => this.handleActivation(e));
            }
            // Bind deactivate and verify buttons
            this.bindActionButtons();
        },

        bindActionButtons() {
            const deactivateBtn = document.getElementById('deactivate-license');
            const verifyBtn = document.getElementById('verify-license');

            if (deactivateBtn) {
                deactivateBtn.addEventListener('click', (e) => this.handleDeactivation(e));
            }
            if (verifyBtn) {
                verifyBtn.addEventListener('click', (e) => this.handleVerification(e));
            }
        },

        async handleActivation(e) {
            e.preventDefault();

            const licenseKey = this.elements.keyInput.value.trim();
            if (!licenseKey) {
                this.showMessage(licenseManager.i18n.enterLicenseKey);
                return;
            }

            const button = this.elements.activateBtn;
            this.setButtonState(button, true, licenseManager.i18n.activating);

            try {
                const response = await this.makeRequest('activate_license', {
                    license_key: licenseKey,
                });
                if (response.success) {
                    this.showMessage(response.data.message, 'success');
					setTimeout(() => {
						location.reload();
					}, 2000);
                } else {
                    this.showMessage(response.data, 'error');
                }
            } catch (error) {
                this.showMessage(licenseManager.i18n.error, 'error');
            } finally {
                this.setButtonState(button, false);
            }
        },

        async handleDeactivation(e) {
            e.preventDefault();

            if (!confirm(licenseManager.i18n.confirmDeactivate)) {
                return;
            }

            const button = e.target;
            this.setButtonState(button, true, licenseManager.i18n.deactivating);

            try {
                const response = await this.makeRequest('deactivate_license');
                if (response.success) {
                    this.showMessage(response.data.message, 'success');
					setTimeout(() => {
						location.reload();
					}, 2000);
                } else {
                    this.showMessage(response.data, 'error');
                }
            } catch (error) {
                this.showMessage(licenseManager.i18n.error, 'error');
            } finally {
                this.setButtonState(button, false);
            }
        },

        async handleVerification(e) {
            e.preventDefault();

            const button = e.target;
            this.setButtonState(button, true, licenseManager.i18n.verifying);

			try {
				const response = await this.makeRequest('verify_license');
				
				// Show message first
				if (response.success) {
					this.showMessage(response.data.message, 'success');
				} else {
					this.showMessage(response.data, 'error');
				}
		
				// Reset button
				this.setButtonState(button, false);
				
				// Wait a bit for the message to be visible before reload
				setTimeout(() => {
					window.location = window.location.href;
				}, 2000);
		
			} catch (error) {
				this.showMessage(licenseManager.i18n.error, 'error');
				this.setButtonState(button, false);
			}
        },

        setButtonState(button, loading, loadingText = '') {
            if (!button) return;

            button.disabled = loading;
            if (loading && loadingText) {
                button.dataset.originalText = button.textContent;
                button.textContent = loadingText;
            } else if (!loading && button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
                delete button.dataset.originalText;
            }
        },

        async makeRequest(action, data = {}) {
            const formData = new FormData();
            formData.append('action', `${licenseManager.pluginSlug}_${action}`);
            formData.append('nonce', licenseManager.nonce);

            Object.entries(data).forEach(([key, value]) => {
                formData.append(key, value);
            });

            const response = await fetch(licenseManager.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            return response.json();
        },

        showMessage(message, type = 'info') {
            const messageEl = this.elements.messageEl;
            if (!messageEl) return;

            messageEl.textContent = message;
            messageEl.className = `license-message message-${type}`;
            messageEl.style.display = 'block';

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    messageEl.style.display = 'none';
                }, 5000);
            }
        }
    };

    // Initialize the license manager
    LicenseManager.init();
});