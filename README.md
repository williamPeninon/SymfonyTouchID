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

### Recipe Flex (recommandée)

Le dépôt embarque une recipe sous `flex/`. Dans le `composer.json` de **votre application** :

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://api.github.com/repos/williamPeninon/SymfonyTouchID/contents/flex/index.json?ref=main",
                "flex://defaults"
            ]
        }
    }
}
```

Puis `composer require wpconsulting/touch-id-bundle` : Flex enregistre le bundle, copie `config/packages/wp_consulting_touch_id.yaml` et `config/routes/touch_id.yaml`.

Sans Flex, enregistrez le bundle à la main :

```php
// config/bundles.php
WpConsulting\TouchIdBundle\TouchIdBundle::class => ['all' => true],
```

### Schéma base de données

```bash
php bin/console touch-id:install
# ou : php bin/console touch-id:install --dump-sql
# ou : SQL de référence dans Resources/schema.sql
# ou : migration Flex flex/.../migrations/Version20260726210000.php (adapter la FK user)
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
    # Sélecteurs CSS du champ email sur la page login (découplé du formulaire hôte)
    email_input_selector: '#username, input[name="_username"], input[name="email"], input[type="email"]'
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

Helpers fournis par le bundle :

| Helper | Description |
|---|---|
| `touch_id_manager` (global) | Service `TouchIdManager` |
| `touch_id_credentials(user)` | Liste des credentials de l’utilisateur |
| `touch_id_redirect_path` (global / fonction) | URL de redirect post-login |
| `touch_id_email_input_selector` (global) | Sélecteurs CSS email login |

```twig
{# Login — email_input / redirect_url optionnels #}
{% include '@TouchId/touch_id/_login_button.html.twig' %}

{# Compte #}
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_credentials(app.user)
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


|                    | Déverrouillage appareil | Passkey du site                       |
| ------------------ | ----------------------- | ------------------------------------- |
| **Rôle**           | Ouvre l’écran / le Mac  | Connecte au **SaaS**                  |
| **Où ça s’active** | Réglages système        | Compte → Mot de passe **sur le site** |
| **Lié à**          | L’appareil              | Compte + **hostname** (RP ID)         |


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


|                              | Face (déverrouillage) | Empreinte (passkey site) |
| ---------------------------- | --------------------- | ------------------------ |
| Réglages Samsung → Biométrie | Oui                   | Oui                      |
| Déverrouille l’écran         | Oui                   | Oui                      |
| Valide une passkey web       | En général **non**    | **Oui**                  |




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



## Configurer les ID clients OAuth 2.0 sur Google

Console : [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services** → **Credentials**.


| Type de client      | À quoi ça sert                                                         | Obligatoire pour ce bundle ?                                   |
| ------------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------- |
| **Application Web** | « Se connecter avec Google » sur le site Symfony (OAuth login)         | Non (hors WebAuthn) — utile sur le même SaaS                   |
| **Android**         | Lier une app native au site (Digital Asset Links / Credential Manager) | Non si **web seul** ; oui si app Android + partage de passkeys |


Les passkeys **web seules** (Chrome / Samsung Internet) n’exigent **pas** d’ID client OAuth. Créez-en un seulement pour Google Sign-In et/ou une app Android.

### 1. Créer ou sélectionner un projet

1. Ouvrir [https://console.cloud.google.com/](https://console.cloud.google.com/)
2. Sélecteur de projet (en haut) → **Nouveau projet** (ex. `mon-saas-auth`) → **Créer**
3. Vérifier que le bon projet est sélectionné



### 2. Configurer l’écran de consentement OAuth

Avant le premier ID client, Google demande un **écran de consentement** :

1. Menu **APIs & Services** → **OAuth consent screen** (ou **Google Auth platform** → **Branding** / **Audience** selon la nouvelle UI)
2. Type d’utilisateur :
  - **External** : comptes Google grand public (tests + prod)
  - **Internal** : uniquement les comptes de votre organisation Google Workspace
3. Renseigner :
  - Nom de l’appli
  - E-mail d’assistance utilisateur
  - Domaines autorisés (ex. `votredomaine.com`) si vous en avez
  - E-mail de contact développeur
4. **Scopes** : pour un login Google basique, garder au minimum :
  - `openid`
  - `.../auth/userinfo.email`
  - `.../auth/userinfo.profile`
5. En mode External + tests : ajouter les **e-mails testeurs** (sinon seuls les testeurs peuvent se connecter tant que l’appli n’est pas en production / vérifiée)
6. Enregistrer



### 3. Créer un ID client OAuth 2.0 — type « Application Web »

Pour le bouton **Se connecter avec Google** du site :

1. **APIs & Services** → **Credentials**
2. **+ Créer des identifiants** → **ID client OAuth**
3. Type d’application : **Application Web**
4. Nom : ex. `SaaS Web Login`
5. **URI de redirection autorisés** (callback Symfony, à adapter) :

  | Environnement | Exemple d’URI                                      |
  | ------------- | -------------------------------------------------- |
  | Local         | `https://localhost:8000/connect/google/check`      |
  | Local HTTP    | `http://localhost:8000/connect/google/check`       |
  | ngrok         | `https://xxxx.ngrok-free.app/connect/google/check` |
  | Production    | `https://votredomaine.com/connect/google/check`    |

  - Ajouter **chaque** hostname utilisé (localhost, ngrok, prod) — un mismatch d’URI = erreur `redirect_uri_mismatch`.
  - Le chemin doit être **exactement** celui de votre route OAuth check (souvent `/connect/google/check` avec knpu/oauth2-client-bundle).
6. (Optionnel) **Origines JavaScript autorisées** : `https://localhost:8000`, `https://votredomaine.com`, etc. — utile si vous utilisez des lib Google côté navigateur (GIS). Pour un flux serveur classique (redirection), les URI de redirection suffisent.
7. **Créer** → copier :
  - **ID client** (`xxxx.apps.googleusercontent.com`)
  - **Code secret du client**



#### Brancher dans Symfony (exemple)

```env
# .env.local
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
```

```yaml
# config/packages/knpu_oauth2_client.yaml (exemple)
knpu_oauth2_client:
    clients:
        google:
            type: google
            client_id: '%env(GOOGLE_CLIENT_ID)%'
            client_secret: '%env(GOOGLE_CLIENT_SECRET)%'
            redirect_route: connect_google_check
            redirect_params: {}
```

Ne **jamais** committer le secret ; utilisez `.env.local` / secrets de déploiement.

### 4. Créer un ID client OAuth 2.0 — type « Android »

Uniquement si vous avez une **app Android** liée au site (partage passkeys / passwords via Digital Asset Links) :

1. **Credentials** → **+ Créer des identifiants** → **ID client OAuth**
2. Type : **Android**
3. Nom : ex. `SaaS Android`
4. **Nom du package** : celui de l’app (`com.example.myapp` dans `build.gradle` / Play Console)
5. **Empreinte SHA-1** (Google demande souvent SHA-1 pour le client Android ; pour `assetlinks.json` vous aurez aussi besoin du **SHA-256**) :

```bash
# Debug
keytool -list -v -keystore ~/.android/debug.keystore -alias androiddebugkey -storepass android -keypass android

# Release / Play App Signing : Play Console → votre app → Configuration → Intégrité de l’app → SHA-1 / SHA-256
```

1. **Créer** (pas de secret client pour le type Android)

Utilisez le **même** SHA-256 dans le fichier `assetlinks.json` du site (section suivante).

### 5. (Optionnel) ID client iOS

Si vous avez une app iOS native :

1. Type **iOS**
2. **Bundle ID** = celui de Xcode (`com.example.myapp`)
3. Pas de secret ; utile pour Sign in with Google / services Google côté app — **sans lien direct** avec WebAuthn web.



### 6. Checklist OAuth

- [ ] Bon **projet** sélectionné dans la console
- [ ] Écran de consentement renseigné (+ testeurs si External / Testing)
- [ ] Client **Web** créé avec **toutes** les URI de redirection (local + tunnel + prod)
- [ ] `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` dans l’environnement Symfony
- [ ] Route callback accessible en HTTPS (ou localhost)
- [ ] En cas d’erreur `redirect_uri_mismatch` : comparer l’URI exacte de la barre d’adresse / des logs avec celle déclarée dans la console (schéma, host, port, chemin, trailing slash)

Documentation Google : [Créer des ID client OAuth](https://developers.google.com/identity/protocols/oauth2).

---



## Digital Asset Links (app Android + site)

En plus du client OAuth Android, publiez `https://VOTRE_DOMAINE/.well-known/assetlinks.json` (`Content-Type: application/json`, HTTP 200, **sans** redirect) :

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

Voir [Credential sharing](https://developers.google.com/identity/credential-sharing/set-up) et [Credential Manager prerequisites](https://developer.android.com/identity/credential-manager/prerequisites).

---



## Récap appareils


| Appareil       | Libellé UI typique  | Méthode passkey web            | Remarque                                                        |
| -------------- | ------------------- | ------------------------------ | --------------------------------------------------------------- |
| Mac            | Touch ID            | Empreinte Mac                  | Pas de Face ID                                                  |
| iPhone / iPad  | Face ID             | Face ID (ou Touch ID)          | —                                                               |
| Samsung Galaxy | Samsung Fingerprint | **Empreinte**                  | Face déverrouille l’écran, **pas** les passkeys web en pratique |
| Autre Android  | Fingerprint         | Empreinte                      | Selon OEM / Credential Manager                                  |
| Windows        | Windows Hello       | Hello (empreinte / face / PIN) | Selon matériel                                                  |


