# WP Consulting Touch ID Bundle

Bundle Symfony pour la connexion sans mot de passe via **WebAuthn / passkeys** sur l’authenticator plateforme de l’appareil :

- Apple **Touch ID** (Mac) et **Face ID** (iPhone / iPad)
- Samsung / Android **empreinte** (Credential Manager / Samsung Pass)
- Windows Hello

> **Important :** déverrouiller le téléphone ou le Mac avec Face / empreinte **ne crée pas** une passkey pour votre site. Il faut **enregistrer** une passkey depuis le compte utilisateur (page « Mot de passe »), sur **le même appareil** et **la même URL** (hostname = RP ID).

---

## Installation (Packagist)

```bash
composer require wpconsulting/touch-id-bundle
```

```php
// config/bundles.php
WpConsulting\TouchIdBundle\TouchIdBundle::class => ['all' => true],
```

## Configuration

```yaml
# config/packages/wp_consulting_touch_id.yaml
wp_consulting_touch_id:
    user_class: App\Entity\User
    user_repository: App\Repository\UserRepository
    rp_name: 'My App'
    login_authenticator: form_login
    default_redirect_route: app_account
    success_handler: App\Security\LoginSuccessHandler # optionnel
    translation_domain: TouchIdBundle
    translation_prefix: ''
```

```yaml
# config/routes/touch_id.yaml
touch_id:
    resource: '@TouchIdBundle/config/routes.yaml'
```

```yaml
# config/packages/security.yaml
access_control:
    - { path: ^/webauthn/login, roles: PUBLIC_ACCESS }
```

## User & repository

```php
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

class User implements UserInterface, TouchIdUserInterface
{
    public function getTouchIdDisplayName(): string
    {
        return $this->getFullName() ?: (string) $this->getEmail();
    }
}
```

```php
use WpConsulting\TouchIdBundle\Contract\TouchIdUserRepositoryInterface;
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

class UserRepository extends ServiceEntityRepository implements TouchIdUserRepositoryInterface
{
    public function findOneForTouchIdByEmail(string $email): ?TouchIdUserInterface
    {
        $user = $this->findOneBy(['email' => $email]);

        return $user instanceof TouchIdUserInterface ? $user : null;
    }
}
```

## Base de données

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Stimulus

```json
{
    "controllers": {
        "@wpconsulting/touch-id-bundle": {
            "login": { "enabled": true, "fetch": "lazy" },
            "register": { "enabled": true, "fetch": "lazy" }
        }
    }
}
```

## Twig

```twig
{# Login #}
{% include '@TouchId/touch_id/_login_button.html.twig' %}

{# Compte (utilisateur connecté) #}
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_manager.listCredentials(app.user)
} %}
```

Traductions CTA embarquées : domaine `TouchIdBundle` (`fr`, `en`, `es`, `de`).

---

## Comment ça marche (WebAuthn)

1. L’utilisateur se connecte **avec mot de passe**.
2. Sur **Compte → Mot de passe**, il clique **Ajouter …** (Touch ID / Face ID / empreinte selon l’appareil).
3. Le navigateur ouvre le dialogue système (Touch ID, Face ID, Credential Manager…).
4. Une **clé publique** est stockée en base (`web_authn_credential`) ; la clé privée reste sur l’appareil.
5. Sur `/login`, le CTA biométrie appelle `navigator.credentials.get` et authentifie l’utilisateur.

Deux notions à ne pas confondre :

| | Déverrouillage appareil | Passkey du site |
|---|---|---|
| **Rôle** | Ouvre l’écran / le Mac | Connecte au **SaaS** |
| **Où ça s’active** | Réglages système | Compte → Mot de passe **sur le site** |
| **Lié à** | L’appareil | Compte + **hostname** (RP ID) |

Une passkey créée sur `localhost` **ne marche pas** sur `xxx.ngrok-free.app` ni en production (RP ID différent). Il faut réenregistrer sur chaque hostname utilisé.

---

## Mac (Touch ID)

### Ce qui est supporté

- **Touch ID** sur Mac avec capteur d’empreinte (Touch Bar ou Power button).
- Safari et Chrome récents (WebAuthn platform authenticator).

### Ce qui n’est pas « Face ID » sur Mac

- Les Mac n’ont **pas** Face ID. Le libellé UI du bundle affiche **Touch ID**.
- Face ID = iPhone / iPad uniquement.

### Comment enregistrer sur le site

1. Ouvrir le site en `http://localhost:PORT` ou `https://localhost:PORT` (**pas** `127.0.0.1` — WebAuthn refuse les IP).
2. Se connecter avec email / mot de passe.
3. **Compte → Mot de passe → Ajouter Touch ID**.
4. Valider le dialogue macOS (empreinte ou mot de passe Mac).
5. La credential apparaît dans la liste ; elle peut ensuite servir sur la page de login **sur ce même Mac et cette même URL**.

### Limites Mac

- Touch ID doit être activé dans **Réglages système → Touch ID et mot de passe**.
- Si l’utilisateur refuse le dialogue → `NotAllowedError` (annulé).
- Passkey Mac ≠ utilisable sur iPhone / Samsung (autre authenticator) : chaque appareil enregistre **sa** passkey.

---

## iPhone / iPad (Face ID)

### Ce qui est supporté

- **Face ID** (ou Touch ID sur anciens modèles).
- Safari (et Chrome iOS via le stack WebKit / passkeys).

### Comment enregistrer

1. Même hostname que pour la connexion (HTTPS public, ou tunnel type ngrok **sans** réécrire `Host: localhost`).
2. Connexion mot de passe → **Compte → Mot de passe → Ajouter Face ID**.
3. Valider Face ID / code appareil.
4. Login biométrique possible ensuite sur **cet** iPhone / iPad.

---

## Samsung / Android (empreinte — pas Face pour les passkeys web)

### Ce qui est supporté

- **Empreinte** via **Google Password Manager / Credential Manager** ou **Samsung Pass** (passkeys, One UI 6+).
- Chrome ou Samsung Internet en **HTTPS**.

### Pourquoi le SaaS ne propose que l’empreinte (même si Face est activé)

Sur Galaxy, il y a **deux biométries différentes** :

1. **Reconnaissance faciale (écran)**  
   - Sert à **déverrouiller le téléphone**.  
   - Classée en général comme biométrie **faible / convenience**.  
   - **N’est en pratique pas proposée** dans le dialogue passkey / WebAuthn / Samsung Pass.

2. **Empreinte**  
   - Biométrie **forte** acceptée pour FIDO / passkeys.  
   - C’est **ce** que Credential Manager affiche quand le site appelle WebAuthn.  
   - Samsung documente les passkeys avec **fingerprint** ([Samsung Pass](https://www.samsung.com/us/apps/samsung-pass/)).

Le site **ne peut pas forcer** le visage dans ce prompt : c’est le système Samsung / Android qui choisit les méthodes autorisées pour les passkeys. Ce n’est **pas** un bug du bundle ni un oubli de configuration SaaS.

| | Face (déverrouillage) | Empreinte (passkey site) |
|---|---|---|
| Réglages Samsung → Biométrie | Oui | Oui |
| Déverrouille l’écran | Oui | Oui |
| Valide une passkey web | En général **non** | **Oui** |

### Comment enregistrer sur le site (Samsung)

1. Activer au moins une **empreinte** (Réglages → Biométrie). Face peut rester activé pour l’écran, mais ne servira pas au site.
2. Ouvrir le **même hostname** que pour le login (prod, ou ngrok HTTPS ; RP ID = hostname public).
3. Se connecter avec mot de passe.
4. **Compte → Mot de passe → Ajouter Samsung Fingerprint** (ou libellé équivalent).
5. Au prompt système : **poser le doigt** (pas le visage).
6. Vérifier que la passkey apparaît dans la liste.
7. Se déconnecter et tester le CTA biométrie sur `/login`.

### Limites Samsung / Android

- Message type *« aucune biométrie enregistrée »* sur le login → **aucune passkey en base pour ce compte / cet appareil / ce hostname**, pas « Face non détecté ».
- Passkey créée sur Mac ou sur un autre domaine → **inutilisable** sur le Galaxy avec cette URL.
- Erreurs Credential Manager fréquentes : pas de compte Google, écran non verrouillé, Chrome trop vieux, options WebAuthn trop strictes (le bundle utilise déjà `userVerification: preferred` et des options assainies pour Android).
- App Android native en plus du web : voir section Google Cloud / Digital Asset Links ci-dessous. **Web seul = pas besoin de Google Cloud Console.**

---

## Windows Hello

- Empreinte, PIN ou caméra Hello selon le PC.
- Edge / Chrome avec Hello activé.
- Même flux : enregistrer depuis Compte → Mot de passe, puis login.

---

## Développement local & tunnels

- **Interdit pour WebAuthn :** `127.0.0.1`, `::1`, toute IP nue → erreur *invalid domain*.
- **OK :** `http://localhost:8000`, `https://localhost:…`
- **ngrok :** ne pas forcer `--host-header=localhost` (sinon RP ID = `localhost` alors que l’origine navigateur est le domaine ngrok → échec). Le Host / `X-Forwarded-Host` vu par Symfony doit être le hostname public.
- Trusted proxies / hosts Symfony doivent accepter le tunnel si vous en utilisez un.

---

## Google Cloud Console (uniquement si app Android native)

Les passkeys **web seules** (Chrome / Samsung Internet) fonctionnent **sans** [Google Cloud Console](https://console.cloud.google.com/).

Utilisez Cloud Console + [Digital Asset Links](https://developers.google.com/identity/credential-sharing/set-up) seulement si vous avez aussi une **app Android** qui doit partager les credentials avec le site.

### 1. Projet

1. https://console.cloud.google.com/
2. Créer / sélectionner un projet

### 2. Client OAuth Android + SHA-256

1. **APIs & Services → Credentials → Create credentials → OAuth client ID**
2. Type **Android**, package name de l’app
3. SHA-256 :
   - Debug : `keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android`
   - Release : Play Console → App signing → SHA-256

### 3. `assetlinks.json`

Publier sur `https://VOTRE_DOMAINE/.well-known/assetlinks.json` (`Content-Type: application/json`, HTTP 200, **sans** redirect) :

```json
[
  {
    "relation": [
      "delegate_permission/common.handle_all_urls",
      "delegate_permission/common.get_login_creds"
    ],
    "target": {
      "namespace": "android_app",
      "package_name": "com.example.myapp",
      "sha256_cert_fingerprints": [
        "AA:BB:CC:...:FF"
      ]
    }
  }
]
```

Voir aussi [Credential Manager prerequisites](https://developer.android.com/identity/credential-manager/prerequisites).

---

## Récap appareils

| Appareil | Libellé UI typique | Méthode passkey web | Remarque |
|---|---|---|---|
| Mac | Touch ID | Empreinte Mac | Pas de Face ID |
| iPhone / iPad | Face ID | Face ID (ou Touch ID) | — |
| Samsung Galaxy | Samsung Fingerprint | **Empreinte** | Face déverrouille l’écran, **pas** les passkeys web en pratique |
| Autre Android | Fingerprint | Empreinte | Selon OEM / Credential Manager |
| Windows | Windows Hello | Hello (empreinte / face / PIN) | Selon matériel |

---

## Publier sur Packagist

1. Pousser le dépôt Git public  
2. https://packagist.org/packages/submit  
3. `git tag v1.3.1 && git push --tags`
