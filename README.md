# WP Consulting Touch ID Bundle

[CI](https://github.com/williamPeninon/SymfonyTouchID/actions/workflows/ci.yml)
[Latest Stable Version](https://packagist.org/packages/wpconsulting/touch-id-bundle)
[License](LICENSE)

Bundle Symfony pour l’authentification **WebAuthn / passkeys** via l’authenticator plateforme de l’appareil :


| Plateforme        | Méthode                                           |
| ----------------- | ------------------------------------------------- |
| Mac               | **Touch ID**                                      |
| iPhone / iPad     | **Face ID** (ou Touch ID)                         |
| Samsung / Android | **Empreinte** (Credential Manager / Samsung Pass) |
| Windows           | **Windows Hello**                                 |


> Déverrouiller le téléphone ou le Mac **ne crée pas** une passkey pour votre site. Il faut **enregistrer** une passkey depuis le compte connecté, sur **le même appareil** et le **même hostname** (RP ID).

---



## Prérequis

- PHP `>= 8.2`
- Symfony `^6.4` ou `^7.0`
- Doctrine ORM + Security + Twig + Stimulus (Asset Mapper / UX)

---



## Installation rapide

```bash
composer require wpconsulting/touch-id-bundle
php bin/console touch-id:configure
```

Puis ajoutez les partials Twig (seul câblage manuel restant) :

**Login**

```twig
{% include '@TouchId/touch_id/_login_button.html.twig' %}
```

**Compte (gestion des passkeys)**

```twig
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_credentials(app.user)
} %}
```



### Ce que fait `touch-id:configure`

- publie `config/packages/wp_consulting_touch_id.yaml` et `config/routes/touch_id.yaml`
- publie `config/packages/dev/framework.yaml` (`trusted_proxies` pour ngrok — **dev only**)
- enregistre le bundle dans `config/bundles.php` si besoin
- demande `user_class` + `user_identifier_field`
- implémente `TouchIdUserInterface` sur l’entité User
- active les contrôleurs Stimulus dans `assets/controllers.json`
- ajoute `PUBLIC_ACCESS` pour `^/webauthn/login`
- crée la table `web_authn_credential` (`touch-id:install` / migrations)

```bash
# Exemples
php bin/console touch-id:configure --user-class=App\\Entity\\User
php bin/console touch-id:configure --no-db
php bin/console touch-id:configure -n   # non interactif si user_class déjà dans le YAML
```



### Flex (optionnel)

Pour copier aussi les YAML via Symfony Flex :

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://raw.githubusercontent.com/williamPeninon/SymfonyTouchID/main/flex/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

Sans endpoint custom, Flex peut afficher `auto-generated recipe` : ce n’est pas bloquant, `touch-id:configure` suffit.

---



## Configuration

```yaml
# config/packages/wp_consulting_touch_id.yaml
wp_consulting_touch_id:
    user_class: App\Entity\User          # FQCN de l’entité, pas un namespace
    user_identifier_field: email         # champ Doctrine pour le lookup login
    rp_name: 'My App'
    login_authenticator: form_login
    default_redirect_route: app_account
    # success_handler: App\Security\LoginSuccessHandler
    translation_domain: TouchIdBundle
    translation_prefix: ''
    email_input_selector: '#username, input[name="_username"], input[name="email"], input[type="email"]'
```


| Option                   | Rôle                                                     |
| ------------------------ | -------------------------------------------------------- |
| `user_class`             | Entité User (doit implémenter `TouchIdUserInterface`)    |
| `user_identifier_field`  | Champ utilisé au login WebAuthn (`email`, `username`, …) |
| `rp_name`                | Nom affiché dans le dialogue passkey                     |
| `login_authenticator`    | Authenticator Security passé à `Security::login()`       |
| `default_redirect_route` | Redirection après login biométrique                      |
| `success_handler`        | Handler optionnel post-login                             |
| `email_input_selector`   | Sélecteurs CSS du champ email sur le formulaire login    |


Tant que `user_class` n’est pas une classe valide implémentant `TouchIdUserInterface`, les services métier ne sont pas câblés : `cache:clear` / `asset-map:compile` restent possibles. La commande `touch-id:configure` est **toujours** disponible.

### User

```php
use WpConsulting\TouchIdBundle\Contract\TouchIdUserInterface;

class User implements UserInterface, PasswordAuthenticatedUserInterface, TouchIdUserInterface
{
    public function getUserId(): mixed
    {
        return $this->id;
    }

    public function getUserName(): ?string
    {
        return $this->email;
    }

    public function getUserDisplayName(): string
    {
        return (string) $this->getUserName();
    }
}
```



### Security / firewalls

`touch-id:configure` ajoute :

```yaml
access_control:
    - { path: ^/webauthn/login, roles: PUBLIC_ACCESS }
```

Si vous avez un **firewall admin séparé**, partagez le contexte de session :

```yaml
security:
    firewalls:
        main: { /* … */ }
        admin:
            pattern: ^/admin
            context: main
```

Sinon la session créée sur `/webauthn/*` n’est pas vue par l’admin → 401/403.

### Base de données

```bash
php bin/console touch-id:install
# ou
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Vérification :

```bash
php bin/console doctrine:mapping:info
# → WpConsulting\TouchIdBundle\Entity\WebAuthnCredential
```

---



## Twig



### Login - `_login_button.html.twig`

```twig
{% include '@TouchId/touch_id/_login_button.html.twig' with {
    redirect_url: path('app_account'),
    show_divider: true
} %}
```

Options : `email_input`, `redirect_url`, `button_class`, `show_hint`, `show_divider`, messages i18n…

### Compte - `_manage.html.twig`

```twig
{% include '@TouchId/touch_id/_manage.html.twig' with {
    credentials: touch_id_credentials(app.user)
} %}
```

Options : `add_button_class`, `wrapper_class`, libellés i18n…

### Helpers


| Helper                          | Description                |
| ------------------------------- | -------------------------- |
| `touch_id_credentials(user)`    | Liste des passkeys         |
| `touch_id_manager`              | Service `TouchIdManager`   |
| `touch_id_redirect_path`        | URL de redirect post-login |
| `touch_id_email_input_selector` | Sélecteurs CSS email       |
| `touch_id_translation_domain`   | Domaine de traduction      |


Traductions CTA : `TouchIdBundle` (`fr`, `en`, `es`, `de`).

### CSS

Styles embarqués (`assets/styles/touch-id.css`), chargés via Stimulus `autoimport`.

```css
.touch-id-manage {
    --tid-accent: #0f766e;
    --tid-ink: #1a2332;
}
```

---



## Comment ça marche

1. Connexion classique (email / mot de passe).
2. Sur la page compte, **Ajouter** Touch ID / Face ID / empreinte.
3. Dialogue système → clé publique stockée en base (`web_authn_credential`).
4. Sur `/login`, le bouton biométrie appelle `navigator.credentials.get` et authentifie l’utilisateur.


|       | Déverrouillage appareil | Passkey du site                   |
| ----- | ----------------------- | --------------------------------- |
| Rôle  | Ouvre l’écran / le Mac  | Connecte au **compte applicatif** |
| Où    | Réglages système        | Page compte **sur le site**       |
| Lié à | L’appareil              | Compte + **hostname** (RP ID)     |


Une passkey créée sur `localhost` **ne fonctionne pas** sur `xxx.ngrok-free.app` ni en prod (RP ID différent) : il faut réenregistrer sur chaque hostname.

---



## Plateformes



### Mac (Touch ID)

- Capteur Touch ID + Safari / Chrome récents.
- Ouvrir le site en `http://localhost:…` ou `https://localhost:…` (**pas** `127.0.0.1`).
- Pas de Face ID sur Mac.



### iPhone / iPad (Face ID)

- Safari (ou Chrome iOS / WebKit).
- Même hostname qu’en login ; HTTPS public ou tunnel ngrok **sans** `--host-header=localhost`.



### Samsung / Android (empreinte)

- Passkeys web = **empreinte** (biométrie forte), pas la reconnaissance faciale d’écran.
- Chrome / Samsung Internet en HTTPS.
- Message « aucune biométrie » sur le login = **aucune passkey enregistrée** pour ce compte / appareil / hostname.



### Windows Hello

- Empreinte, PIN ou caméra selon le matériel ; Edge / Chrome.


| Appareil      | Libellé UI          | Méthode passkey |
| ------------- | ------------------- | --------------- |
| Mac           | Touch ID            | Empreinte Mac   |
| iPhone / iPad | Face ID             | Face / Touch ID |
| Samsung       | Samsung Fingerprint | Empreinte       |
| Android       | Fingerprint         | Empreinte       |
| Windows       | Windows Hello       | Hello           |


---



## Dev local & ngrok

- **Interdit :** `127.0.0.1`, IP nues → domaine WebAuthn invalide.
- **OK :** `localhost`, hostname HTTPS public.
- **ngrok :** ne pas forcer `Host: localhost` ; le Host / `X-Forwarded-Host` vu par Symfony doit être le hostname public.
- En dev, `touch-id:configure` peut publier `config/packages/dev/framework.yaml` (`trusted_proxies`). **Ne pas réutiliser ces valeurs en prod** — voir [SECURITY.md](SECURITY.md).

Les passkeys **web seules** n’exigent **pas** de projet Google Cloud / OAuth. OAuth Google ou Digital Asset Links ne concernent que Sign-In Google / apps natives liées au site.

---



## Commandes


| Commande             | Rôle                                            |
| -------------------- | ----------------------------------------------- |
| `touch-id:configure` | Wiring complet de l’app hôte                    |
| `touch-id:install`   | Crée uniquement la table `web_authn_credential` |


---



## Upgrade depuis 1.x

Le package n’est plus un **Composer plugin** forcé : c’est un `symfony-bundle` (**2.0**).

```bash
composer require wpconsulting/touch-id-bundle:^2.0
# retirer allow-plugins.wpconsulting/touch-id-bundle si présent
php bin/console touch-id:configure
```

Détails : [CHANGELOG.md](CHANGELOG.md).

---



## Sécurité & contribution

- Signalement : [SECURITY.md](SECURITY.md)
- Changelog : [CHANGELOG.md](CHANGELOG.md)
- Issues : [GitHub Issues](https://github.com/williamPeninon/SymfonyTouchID/issues)
- Tests : `composer test`

```bash
composer install
composer test
```

---



## Licence

MIT © [WP Consulting](https://wpconsulting.fr)