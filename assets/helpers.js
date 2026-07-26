/**
 * WebAuthn helpers for platform biometrics:
 * Apple Touch ID / Face ID, Samsung / Android fingerprint & face, Windows Hello, etc.
 */

export function isMacPlatform() {
    const ua = navigator.userAgent || '';
    const platform = navigator.platform || navigator.userAgentData?.platform || '';
    // iPadOS desktop mode reports as Mac — exclude real iPads via touch points.
    const looksMac = /Mac|Macintosh/.test(platform) || /Macintosh/.test(ua);
    const isIpadDesktop = looksMac && navigator.maxTouchPoints > 1;
    return looksMac && !isIpadDesktop;
}

export function isIosDevice() {
    const ua = navigator.userAgent || '';
    const platform = navigator.platform || navigator.userAgentData?.platform || '';
    if (/iPhone|iPod/.test(platform) || /iPhone|iPod/.test(ua)) {
        return true;
    }
    if (/iPad/.test(platform) || /iPad/.test(ua)) {
        return true;
    }
    // iPadOS 13+ desktop mode
    return /Mac|Macintosh/.test(platform) && navigator.maxTouchPoints > 1;
}

export function isAndroidDevice() {
    return /Android/i.test(navigator.userAgent || '');
}

export function isSamsungDevice() {
    const ua = navigator.userAgent || '';
    // Chrome on Galaxy often exposes SM-XXXX; Samsung Internet adds SamsungBrowser.
    return /Samsung|SamsungBrowser|SM-[A-Z0-9]+|Galaxy/i.test(ua);
}

export function isWindowsDevice() {
    const platform = navigator.platform || navigator.userAgentData?.platform || '';
    return /Win/i.test(platform) || /Windows/i.test(navigator.userAgent || '');
}

/** @deprecated Prefer supportsPlatformBiometricUi() — kept for backward compatibility. */
export function isAppleBiometricDevice() {
    return isMacPlatform() || isIosDevice();
}

/**
 * Any device that may expose a platform authenticator.
 * Final availability is still checked via isPlatformAuthenticatorAvailable().
 */
export function supportsPlatformBiometricUi() {
    return isAppleBiometricDevice()
        || isAndroidDevice()
        || isWindowsDevice()
        || isWebAuthnAvailable();
}

/**
 * Best-effort label for the device biometric.
 * WebAuthn does not expose fingerprint vs face — we infer from the platform.
 *
 * Samsung note: screen-unlock Face recognition is usually a *weak* biometric.
 * Samsung Pass / Credential Manager typically only offers fingerprint for
 * passkeys (FIDO / strong UV). Face cannot be forced from the website.
 */
export function preferredBiometricLabel() {
    if (isIosDevice()) {
        return 'Face ID';
    }
    if (isMacPlatform()) {
        return 'Touch ID';
    }
    if (isSamsungDevice()) {
        return 'Samsung Fingerprint';
    }
    if (isAndroidDevice()) {
        return 'Fingerprint';
    }
    if (isWindowsDevice()) {
        return 'Windows Hello';
    }
    return 'Biometrics';
}

/** @deprecated Face is not available for Samsung passkeys; fingerprint only. */
export function samsungBiometricChoices() {
    return [
        { name: 'Samsung Fingerprint', label: 'Samsung Fingerprint' },
    ];
}

export function isWebAuthnAvailable() {
    return typeof window.PublicKeyCredential !== 'undefined'
        && typeof navigator.credentials?.create === 'function'
        && typeof navigator.credentials?.get === 'function';
}

/** IPs are invalid WebAuthn rpIds — browsers throw "This is an invalid domain". */
export function isInvalidWebAuthnHost(hostname = window.location.hostname) {
    const host = (hostname || '').replace(/^\[|\]$/g, '');
    return host === '127.0.0.1' || host === '::1' || /^\d{1,3}(?:\.\d{1,3}){3}$/.test(host);
}

export function localhostSuggestionUrl() {
    const port = window.location.port ? `:${window.location.port}` : '';
    return `${window.location.protocol}//localhost${port}${window.location.pathname}${window.location.search}${window.location.hash}`;
}

export function assertValidWebAuthnHost(message) {
    if (!isInvalidWebAuthnHost()) {
        return;
    }
    const fallback = `${preferredBiometricLabel()} requires localhost. Open ${localhostSuggestionUrl()}`;
    throw new Error(message || fallback);
}

export async function isPlatformAuthenticatorAvailable() {
    if (!isWebAuthnAvailable() || !PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
        return false;
    }
    try {
        return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        return false;
    }
}

export function bufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

export function base64UrlToBuffer(base64url) {
    const padding = '='.repeat((4 - (base64url.length % 4)) % 4);
    const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const buffer = new ArrayBuffer(raw.length);
    const bytes = new Uint8Array(buffer);
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }
    return buffer;
}

/** Convert base64url strings (from server) into ArrayBuffers. */
export function preparePublicKeyOptions(options) {
    const publicKey = structuredClone(options.publicKey ?? options);
    publicKey.challenge = base64UrlToBuffer(publicKey.challenge);

    if (publicKey.user?.id) {
        publicKey.user.id = base64UrlToBuffer(publicKey.user.id);
    }

    if (Array.isArray(publicKey.excludeCredentials)) {
        publicKey.excludeCredentials = publicKey.excludeCredentials.map((cred) => ({
            ...cred,
            id: base64UrlToBuffer(cred.id),
        }));
    }

    if (Array.isArray(publicKey.allowCredentials)) {
        publicKey.allowCredentials = publicKey.allowCredentials.map((cred) => ({
            ...cred,
            id: base64UrlToBuffer(cred.id),
        }));
    }

    return publicKey;
}

export async function fetchJson(url, body = {}, method = 'POST') {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: method === 'GET' || method === 'DELETE' ? undefined : JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.message || 'Request failed');
        error.status = response.status;
        error.data = data;
        throw error;
    }
    return data;
}

/** Friendlier messages for Android Credential Manager failures. */
export function formatWebAuthnError(error, androidMessage, fallback) {
    const message = String(error?.message || '');
    const name = error?.name || '';

    if (name === 'NotAllowedError') {
        return null; // caller handles cancel
    }

    if (
        name === 'NotReadableError'
        || /credential manager/i.test(message)
        || /unknown error occurred while talking/i.test(message)
    ) {
        return androidMessage || fallback || message || 'Android Credential Manager error.';
    }

    return message || fallback || 'Biometric failure.';
}
