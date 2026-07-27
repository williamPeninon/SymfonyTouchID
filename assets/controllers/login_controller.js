import { Controller } from '@hotwired/stimulus';
import '../styles/passkey.css';
import {
    assertValidWebAuthnHost,
    bufferToBase64Url,
    fetchJson,
    formatWebAuthnError,
    isInvalidWebAuthnHost,
    localhostSuggestionUrl,
    preferredBiometricLabel,
    preparePublicKeyOptions,
    resolveLoginHint,
    supportsPlatformBiometricUi,
    isPlatformAuthenticatorAvailable,
    isWebAuthnAvailable,
} from '../helpers.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        verifyUrl: String,
        emailInput: { type: String, default: '#username, input[name="_username"], input[name="email"], input[type="email"]' },
        redirectUrl: { type: String, default: '/' },
    };

    static targets = ['button', 'error', 'divider', 'hint'];

    async connect() {
        if (!supportsPlatformBiometricUi() || !isWebAuthnAvailable()) {
            this.element.hidden = true;
            return;
        }

        const available = await isPlatformAuthenticatorAvailable();
        if (!available) {
            this.element.hidden = true;
            return;
        }

        this.element.hidden = false;
        this.applyBiometricLabel();
        this.applyDeviceHint();

        if (isInvalidWebAuthnHost()) {
            this.showError(this.hostErrorMessage());
        }
    }

    applyBiometricLabel() {
        const label = preferredBiometricLabel();
        const labelEl = this.element.querySelector('[data-passkey-login-target="label"]');
        if (labelEl) {
            const template = this.element.dataset.loginLabelTemplate || 'Sign in with %biometric%';
            labelEl.textContent = template.replace('%biometric%', label);
        }
    }

    applyDeviceHint() {
        if (!this.hasHintTarget) {
            return;
        }
        const ds = this.element.dataset;
        const hint = resolveLoginHint({
            mac: ds.hintMac,
            iphone: ds.hintIphone,
            ipad: ds.hintIpad,
            samsung: ds.hintSamsung,
            samsung_tablet: ds.hintSamsungTablet,
            android: ds.hintAndroid,
            android_tablet: ds.hintAndroidTablet,
            windows: ds.hintWindows,
            generic: ds.hintGeneric,
        });
        if (hint) {
            this.hintTarget.textContent = hint;
        }
    }

    hostErrorMessage() {
        const suggestion = localhostSuggestionUrl();
        if (this.element.dataset.hostMessage) {
            return `${this.element.dataset.hostMessage} ${suggestion}`;
        }
        return `${preferredBiometricLabel()} requires localhost. Open ${suggestion}`;
    }

    resolveEmail() {
        const selectors = String(this.emailInputValue || '')
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);

        for (const selector of selectors) {
            try {
                const el = document.querySelector(selector);
                const value = el?.value?.trim();
                if (value) {
                    return value;
                }
            } catch {
                // invalid selector — skip
            }
        }

        return '';
    }

    async login(event) {
        event.preventDefault();
        this.clearError();
        this.setLoading(true);

        try {
            assertValidWebAuthnHost(this.hostErrorMessage());

            const email = this.resolveEmail();
            const options = await fetchJson(this.optionsUrlValue, email ? { email } : {});
            const publicKey = preparePublicKeyOptions(options);

            const credential = await navigator.credentials.get({ publicKey });
            if (!credential) {
                throw new Error(this.element.dataset.cancelMessage || 'Cancelled');
            }

            const payload = {
                id: bufferToBase64Url(credential.rawId),
                clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                signature: bufferToBase64Url(credential.response.signature),
                userHandle: credential.response.userHandle
                    ? bufferToBase64Url(credential.response.userHandle)
                    : null,
            };

            const result = await fetchJson(this.verifyUrlValue, payload);
            if (result.redirect) {
                window.location.href = result.redirect;
                return;
            }

            window.location.href = this.redirectUrlValue || '/';
        } catch (error) {
            // User dismissed the OS/browser passkey sheet (Cancel / dismiss).
            // Browsers surface this as NotAllowedError or AbortError — not as "no passkey".
            if (error.name === 'NotAllowedError' || error.name === 'AbortError') {
                this.showError(this.element.dataset.cancelMessage || 'Cancelled.');
            } else {
                this.showError(
                    formatWebAuthnError(error, this.element.dataset.androidMessage, this.element.dataset.errorMessage)
                    || error.message
                    || this.element.dataset.errorMessage
                    || 'Biometric sign-in failed.',
                );
            }
        } finally {
            this.setLoading(false);
        }
    }

    setLoading(loading) {
        if (!this.hasButtonTarget) {
            return;
        }
        this.buttonTarget.disabled = loading;
        this.buttonTarget.classList.toggle('is-loading', loading);
    }

    showError(message) {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = message;
            this.errorTarget.hidden = false;
        }
    }

    clearError() {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = '';
            this.errorTarget.hidden = true;
        }
    }
}
