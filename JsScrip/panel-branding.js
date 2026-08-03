(function () {
    const STORAGE_KEY = 'naap-panel-branding-v1';
    const SETTINGS_KEY = 'panelBranding';
    const DEFAULT_TEXT = 'NAAP Evaluation System';
    const DEFAULT_ICON_HTML = '<i class="fas fa-shield-alt" aria-hidden="true"></i>';
    const MAX_LOGO_SIZE_BYTES = 1024 * 1024;

    let currentBranding = getStoredBranding();
    let brandingModal = null;
    let brandingFileInput = null;
    let brandingTextInput = null;
    let brandingPreviewShield = null;
    let brandingPreviewText = null;
    let brandingFeedback = null;
    let pendingLogoDataUrl = currentBranding.logoDataUrl;

    function getSharedDataApi() {
        if (typeof SharedData !== 'undefined' && SharedData && typeof SharedData === 'object') {
            return SharedData;
        }
        return window.AppData && typeof window.AppData === 'object' ? window.AppData : null;
    }

    function safeStorageAvailable() {
        try {
            return typeof window.localStorage !== 'undefined';
        } catch (error) {
            return false;
        }
    }

    function normalizeBranding(branding) {
        const normalizedText = typeof branding?.text === 'string' && branding.text.trim()
            ? branding.text.trim()
            : DEFAULT_TEXT;
        const normalizedLogoDataUrl = typeof branding?.logoDataUrl === 'string' && branding.logoDataUrl.startsWith('data:image/')
            ? branding.logoDataUrl
            : '';

        return {
            text: normalizedText,
            logoDataUrl: normalizedLogoDataUrl
        };
    }

    function getStoredBranding() {
        const sharedData = getSharedDataApi();
        if (sharedData && typeof sharedData.getSettings === 'function') {
            try {
                const settings = sharedData.getSettings() || {};
                if (settings && settings[SETTINGS_KEY]) {
                    return normalizeBranding(settings[SETTINGS_KEY]);
                }
            } catch (error) {
                // Fall back to local storage when shared settings are unavailable.
            }
        }

        if (!safeStorageAvailable()) {
            return normalizeBranding({});
        }

        try {
            const storedValue = window.localStorage.getItem(STORAGE_KEY);
            if (!storedValue) return normalizeBranding({});
            return normalizeBranding(JSON.parse(storedValue));
        } catch (error) {
            return normalizeBranding({});
        }
    }

    function saveBranding(branding) {
        const normalizedBranding = normalizeBranding(branding);
        const sharedData = getSharedDataApi();

        if (sharedData && typeof sharedData.updateSettings === 'function') {
            sharedData.updateSettings({ [SETTINGS_KEY]: normalizedBranding });
        }

        if (!safeStorageAvailable()) {
            if (sharedData && typeof sharedData.updateSettings === 'function') {
                return normalizedBranding;
            }
            throw new Error('Local storage is unavailable.');
        }

        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(normalizedBranding));
        return normalizedBranding;
    }

    function applyBrandingToContainer(container, branding) {
        if (!container) return;

        const shield = container.querySelector('.logo-shield');
        const textElement = container.querySelector('.logo-text, .header-logo-text');
        if (!shield || !textElement) return;

        if (branding.logoDataUrl) {
            shield.classList.add('has-custom-logo');
            shield.innerHTML = `<img class="brand-logo-image" src="${branding.logoDataUrl}" alt="System logo">`;
        } else {
            shield.classList.remove('has-custom-logo');
            shield.innerHTML = DEFAULT_ICON_HTML;
        }

        textElement.textContent = branding.text;
    }

    function applyBrandingToPage(branding) {
        document.querySelectorAll('.logo-container, .header-logo').forEach(container => {
            applyBrandingToContainer(container, branding);
        });
    }

    function setFeedback(message, isError) {
        if (!brandingFeedback) return;
        brandingFeedback.textContent = message || '';
        brandingFeedback.classList.toggle('is-error', Boolean(isError));
    }

    function updatePreview() {
        if (!brandingPreviewShield || !brandingPreviewText || !brandingTextInput) return;

        const previewBranding = normalizeBranding({
            text: brandingTextInput.value,
            logoDataUrl: pendingLogoDataUrl
        });

        if (previewBranding.logoDataUrl) {
            brandingPreviewShield.classList.add('has-custom-logo');
            brandingPreviewShield.innerHTML = `<img class="brand-logo-image" src="${previewBranding.logoDataUrl}" alt="Logo preview">`;
        } else {
            brandingPreviewShield.classList.remove('has-custom-logo');
            brandingPreviewShield.innerHTML = DEFAULT_ICON_HTML;
        }

        brandingPreviewText.textContent = previewBranding.text;
    }

    function closeBrandingModal() {
        if (!brandingModal) return;
        brandingModal.hidden = true;
        brandingModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('branding-modal-open');
        setFeedback('');
    }

    function openBrandingModal() {
        if (!brandingModal) return;

        currentBranding = getStoredBranding();
        pendingLogoDataUrl = currentBranding.logoDataUrl;
        brandingTextInput.value = currentBranding.text;
        brandingFileInput.value = '';
        updatePreview();
        setFeedback('');

        brandingModal.hidden = false;
        brandingModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('branding-modal-open');
        window.setTimeout(() => {
            brandingTextInput.focus();
            brandingTextInput.select();
        }, 0);
    }

    function buildBrandingModal() {
        if (!document.body || brandingModal) return;

        const modal = document.createElement('div');
        modal.className = 'panel-branding-modal';
        modal.id = 'panelBrandingModal';
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="panel-branding-dialog" role="dialog" aria-modal="true" aria-labelledby="panelBrandingTitle">
                <div class="panel-branding-header">
                    <div>
                        <h3 id="panelBrandingTitle">Update Panel Branding</h3>
                        <p>Change the sidebar logo and system name for every panel.</p>
                    </div>
                    <button type="button" class="panel-branding-close" aria-label="Close branding editor">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <form class="panel-branding-form">
                    <div class="panel-branding-preview">
                        <div class="logo-container">
                            <div class="logo-shield"></div>
                            <h2 class="logo-text"></h2>
                        </div>
                    </div>
                    <label class="panel-branding-field">
                        <span>System Name</span>
                        <input type="text" id="panelBrandingTextInput" maxlength="60" placeholder="${DEFAULT_TEXT}">
                    </label>
                    <label class="panel-branding-field">
                        <span>Logo Image</span>
                        <input type="file" id="panelBrandingFileInput" accept="image/*">
                        <small>Use a square image when possible. Maximum file size: 1 MB.</small>
                    </label>
                    <p class="panel-branding-feedback" id="panelBrandingFeedback" aria-live="polite"></p>
                    <div class="panel-branding-actions">
                        <button type="button" class="btn-cancel panel-branding-reset">Use Default</button>
                        <button type="button" class="btn-cancel panel-branding-cancel">Cancel</button>
                        <button type="submit" class="btn-submit">Save Branding</button>
                    </div>
                </form>
            </div>
        `;

        document.body.appendChild(modal);

        brandingModal = modal;
        brandingFileInput = modal.querySelector('#panelBrandingFileInput');
        brandingTextInput = modal.querySelector('#panelBrandingTextInput');
        brandingPreviewShield = modal.querySelector('.panel-branding-preview .logo-shield');
        brandingPreviewText = modal.querySelector('.panel-branding-preview .logo-text');
        brandingFeedback = modal.querySelector('#panelBrandingFeedback');

        modal.querySelector('.panel-branding-close').addEventListener('click', closeBrandingModal);
        modal.querySelector('.panel-branding-cancel').addEventListener('click', closeBrandingModal);
        modal.querySelector('.panel-branding-reset').addEventListener('click', () => {
            pendingLogoDataUrl = '';
            brandingTextInput.value = DEFAULT_TEXT;
            updatePreview();
            setFeedback('Default branding loaded. Save to apply it everywhere.', false);
        });

        modal.addEventListener('click', event => {
            if (event.target === modal) {
                closeBrandingModal();
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && brandingModal && !brandingModal.hidden) {
                closeBrandingModal();
            }
        });

        brandingTextInput.addEventListener('input', updatePreview);
        brandingFileInput.addEventListener('change', event => {
            const file = event.target.files && event.target.files[0];
            if (!file) {
                return;
            }

            if (file.size > MAX_LOGO_SIZE_BYTES) {
                brandingFileInput.value = '';
                setFeedback('Logo file is too large. Please choose an image smaller than 1 MB.', true);
                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                pendingLogoDataUrl = typeof reader.result === 'string' ? reader.result : '';
                updatePreview();
                setFeedback('');
            };
            reader.onerror = () => {
                setFeedback('The selected image could not be read. Please try a different file.', true);
            };
            reader.readAsDataURL(file);
        });

        modal.querySelector('.panel-branding-form').addEventListener('submit', event => {
            event.preventDefault();

            try {
                currentBranding = saveBranding({
                    text: brandingTextInput.value,
                    logoDataUrl: pendingLogoDataUrl
                });
                applyBrandingToPage(currentBranding);
                closeBrandingModal();
            } catch (error) {
                setFeedback('Branding could not be saved in this browser.', true);
            }
        });
    }

    function enableAdminBrandEditing() {
        if (!document.body.classList.contains('admin-panel')) return;

        const logoContainer = document.querySelector('.sidebar .logo-container');
        if (!logoContainer) return;

        buildBrandingModal();
        logoContainer.classList.add('brand-editable');
        logoContainer.title = 'Double-click to update panel branding';
        logoContainer.addEventListener('dblclick', openBrandingModal);
    }

    function init() {
        currentBranding = getStoredBranding();
        applyBrandingToPage(currentBranding);
        enableAdminBrandEditing();

        const sharedData = getSharedDataApi();
        if (sharedData && typeof sharedData.onDataChange === 'function') {
            sharedData.onDataChange((key, value) => {
                if (key !== (sharedData.KEYS && sharedData.KEYS.SETTINGS)) return;
                currentBranding = normalizeBranding(value && value[SETTINGS_KEY] ? value[SETTINGS_KEY] : {});
                applyBrandingToPage(currentBranding);
            });
        }

        window.addEventListener('storage', event => {
            if (event.key !== STORAGE_KEY) return;
            try {
                currentBranding = normalizeBranding(event.newValue ? JSON.parse(event.newValue) : {});
            } catch (error) {
                currentBranding = getStoredBranding();
            }
            applyBrandingToPage(currentBranding);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
