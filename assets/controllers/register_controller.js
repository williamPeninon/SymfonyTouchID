import { Controller } from '@hotwired/stimulus';
import {
    assertValidWebAuthnHost,
    bufferToBase64Url,
    fetchJson,
    isAppleBiometricDevice,
    isInvalidWebAuthnHost,
    isPlatformAuthenticatorAvailable,
    isWebAuthnAvailable,
    localhostSuggestionUrl,
    preferredBiometricLabel,
    preparePublicKeyOptions,
} from '../helpers.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        optionsUrl: String,
        verifyUrl: String,
        deleteUrlTemplate: String,
    };

    static targets = ['panel', 'button', 'list', 'empty', 'error', 'success', 'unsupported'];

    async connect() {
        if (!isAppleBiometricDevice() || !isWebAuthnAvailable()) {
            this.showUnsupported();
            return;
        }

        const available = await isPlatformAuthenticatorAvailable();
        if (!available) {
            this.showUnsupported();
            return;
        }

        if (isInvalidWebAuthnHost()) {
            if (this.hasUnsupportedTarget) {
                this.unsupportedTarget.textContent = this.hostErrorMessage();
                this.unsupportedTarget.hidden = false;
            }
            if (this.hasPanelTarget) {
                this.panelTarget.hidden = true;
            }
            return;
        }

        if (this.hasPanelTarget) {
            this.panelTarget.hidden = false;
        }
        if (this.hasUnsupportedTarget) {
            this.unsupportedTarget.hidden = true;
        }
        this.applyBiometricLabel();
    }

    applyBiometricLabel() {
        const label = preferredBiometricLabel();
        const template = this.element.dataset.addLabelTemplate || 'Ajouter %biometric%';
        const text = template.replace('%biometric%', label);
        const labelEl = this.element.querySelector('[data-webauthn-register-target="addLabel"]');
        if (labelEl) {
            labelEl.textContent = text;
            return;
        }
        if (this.hasButtonTarget) {
            this.buttonTarget.textContent = text;
        }
    }

    hostErrorMessage() {
        return this.element.dataset.hostMessage
            ? `${this.element.dataset.hostMessage} ${localhostSuggestionUrl()}`
            : `${preferredBiometricLabel()} nécessite localhost. Ouvrez ${localhostSuggestionUrl()}`;
    }

    showUnsupported() {
        if (this.hasPanelTarget) {
            this.panelTarget.hidden = true;
        }
        if (this.hasUnsupportedTarget) {
            this.unsupportedTarget.hidden = false;
        }
    }

    async register(event) {
        event.preventDefault();
        this.clearMessages();
        this.setLoading(true);

        try {
            assertValidWebAuthnHost(this.hostErrorMessage());

            const options = await fetchJson(this.optionsUrlValue);
            const publicKey = preparePublicKeyOptions(options);

            const credential = await navigator.credentials.create({ publicKey });
            if (!credential) {
                throw new Error(this.element.dataset.cancelMessage || 'Annulé');
            }

            const payload = {
                name: preferredBiometricLabel(),
                clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                attestationObject: bufferToBase64Url(credential.response.attestationObject),
            };

            const result = await fetchJson(this.verifyUrlValue, payload);
            this.showSuccess(result.message || this.element.dataset.successMessage || 'Empreinte enregistrée.');

            if (result.credential) {
                this.prependCredential(result.credential);
            }
        } catch (error) {
            if (error.name === 'NotAllowedError') {
                this.showError(this.element.dataset.cancelMessage || 'Enregistrement annulé.');
            } else {
                this.showError(error.message || this.element.dataset.errorMessage || 'Échec de l’enregistrement.');
            }
        } finally {
            this.setLoading(false);
        }
    }

    async remove(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const id = button.dataset.credentialId;
        if (!id) {
            return;
        }

        if (!window.confirm(this.element.dataset.confirmDelete || 'Supprimer cette empreinte ?')) {
            return;
        }

        this.clearMessages();
        button.disabled = true;

        try {
            const url = this.deleteUrlTemplateValue.replace('__ID__', id);
            const result = await fetchJson(url, {}, 'DELETE');
            const row = button.closest('[data-webauthn-credential-id]');
            row?.remove();
            this.showSuccess(result.message || this.element.dataset.deletedMessage || 'Empreinte supprimée.');
            this.refreshEmptyState();
        } catch (error) {
            this.showError(error.message || this.element.dataset.errorMessage || 'Suppression impossible.');
            button.disabled = false;
        }
    }

    prependCredential(credential) {
        if (!this.hasListTarget) {
            window.location.reload();
            return;
        }

        const li = document.createElement('li');
        li.className = 'webauthn-credential';
        li.dataset.webauthnCredentialId = String(credential.id);
        li.innerHTML = `
            <div class="webauthn-credential__meta">
                <strong>${this.escapeHtml(credential.name)}</strong>
                <span>${this.escapeHtml(credential.createdAt)}</span>
            </div>
            <button type="button"
                class="btn btn-sm btn-outline-danger"
                data-action="webauthn-register#remove"
                data-credential-id="${credential.id}">
                ${this.escapeHtml(this.element.dataset.deleteLabel || 'Supprimer')}
            </button>
        `;
        this.listTarget.prepend(li);
        this.refreshEmptyState();
    }

    refreshEmptyState() {
        if (!this.hasListTarget || !this.hasEmptyTarget) {
            return;
        }
        const hasItems = this.listTarget.querySelectorAll('[data-webauthn-credential-id]').length > 0;
        this.emptyTarget.hidden = hasItems;
        this.listTarget.hidden = !hasItems;
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

    showSuccess(message) {
        if (this.hasSuccessTarget) {
            this.successTarget.textContent = message;
            this.successTarget.hidden = false;
        }
    }

    clearMessages() {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = '';
            this.errorTarget.hidden = true;
        }
        if (this.hasSuccessTarget) {
            this.successTarget.textContent = '';
            this.successTarget.hidden = true;
        }
    }

    escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
}
