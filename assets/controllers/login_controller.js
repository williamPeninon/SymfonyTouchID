import { Controller } from '@hotwired/stimulus';
import {
    assertValidWebAuthnHost,
    bufferToBase64Url,
    fetchJson,
    formatWebAuthnError,
    isInvalidWebAuthnHost,
    localhostSuggestionUrl,
    preferredBiometricLabel,
    preparePublicKeyOptions,
    supportsPlatformBiometricUi,
    isPlatformAuthenticatorAvailable,
    isWebAuthnAvailable,
} from '../helpers.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        verifyUrl: String,
    };

    static targets = ['button', 'error', 'divider'];

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

        if (isInvalidWebAuthnHost()) {
            this.showError(this.hostErrorMessage());
        }
    }

    applyBiometricLabel() {
        const label = preferredBiometricLabel();
        const labelEl = this.element.querySelector('[data-webauthn-login-target="label"]');
        if (labelEl) {
            const template = this.element.dataset.loginLabelTemplate || 'Se connecter avec %biometric%';
            labelEl.textContent = template.replace('%biometric%', label);
        }
    }

    hostErrorMessage() {
        return this.element.dataset.hostMessage
            ? `${this.element.dataset.hostMessage} ${localhostSuggestionUrl()}`
            : `${preferredBiometricLabel()} nécessite localhost. Ouvrez ${localhostSuggestionUrl()}`;
    }

    async login(event) {
        event.preventDefault();
        this.clearError();
        this.setLoading(true);

        try {
            assertValidWebAuthnHost(this.hostErrorMessage());

            const emailInput = document.getElementById('username');
            const email = emailInput?.value?.trim() || '';

            const options = await fetchJson(this.optionsUrlValue, email ? { email } : {});
            const publicKey = preparePublicKeyOptions(options);

            const credential = await navigator.credentials.get({ publicKey });
            if (!credential) {
                throw new Error(this.element.dataset.cancelMessage || 'Annulé');
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

            window.location.href = '/compte';
        } catch (error) {
            if (error.name === 'NotAllowedError') {
                this.showError(this.element.dataset.noPasskeyMessage || this.element.dataset.cancelMessage || 'Connexion annulée.');
            } else {
                this.showError(formatWebAuthnError(error, this.element.dataset.errorMessage) || this.element.dataset.errorMessage || 'Échec de la connexion.');
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
