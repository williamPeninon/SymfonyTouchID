# Security Policy

## Supported versions

| Version | Supported |
| ------- | --------- |
| 3.0.x (`passkey-bundle`) | Yes |
| `touch-id-bundle` (legacy) | No — migrate to `passkey-bundle` ^3.0 |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security reports.

Email **security concerns** related to this bundle via the contact form on [wpconsulting.fr](https://wpconsulting.fr), with subject `[SECURITY] passkey-bundle`.

Include:
- Affected version / commit
- Reproduction steps
- Impact assessment (auth bypass, RP ID spoofing, CSRF, etc.)

We aim to acknowledge within **5 business days** and ship a fix or mitigation as soon as practical.

## Scope notes

This bundle implements WebAuthn **platform** passkeys and may publish reverse-proxy trust settings in **dev** (`config/packages/dev/framework.yaml`). Production deployments must configure `trusted_proxies` / `trusted_headers` for their reverse proxy — do not reuse the dev ngrok defaults in prod.
