# Microsoft ID Login

## Purpose

`microsoft-id-login` signs in WordPress users with Microsoft Entra ID. It authenticates identity only. WordPress remains authoritative for users, roles and capabilities.

## Architecture

- Separate plugin: `wp-content/plugins/microsoft-id-login/`
- Separate Entra app registrations for DEV and PROD, for example `SSF Web Login DEV` and `SSF Web Login PROD`
- Authorization Code Flow with PKCE
- Scopes: `openid profile email`
- Durable account mapping: Microsoft `tid` + `oid` stored on the WordPress user
- No automatic WordPress user creation
- No automatic role assignment
- No Memlist integration
- No SharePoint permissions
- No reuse of `ssf_member_portal_graph_configuration`

## Backend Configuration

Open **SSF -> System -> Inloggning** and configure the Microsoft login profiles.

The backend contains two separate profiles:

- `development`
- `production`

Each profile has its own enabled flag, Tenant ID, Application ID / Client ID and Client Secret. The Client Secret is never displayed. Leaving the secret field empty keeps the existing saved secret; use the clear checkbox only when the saved secret should be removed.

The plugin may be installed in both DEV and PROD. Microsoft login is disabled by default and only becomes active in the current environment when that environment's profile is complete and explicitly enabled.

Server constants can still be used as emergency overrides outside Git:

```php
define('SSF_M365_LOGIN_ENABLED', 'true');
define('SSF_M365_LOGIN_TENANT_ID', 'YOUR-SSF-TENANT-ID');
define('SSF_M365_LOGIN_CLIENT_ID', 'YOUR-LOGIN-APP-CLIENT-ID');
define('SSF_M365_LOGIN_CLIENT_SECRET', getenv('SSF_M365_LOGIN_CLIENT_SECRET') ?: '');
```

Missing configuration or a disabled profile means no Microsoft login button, no login start and no callback authentication acceptance. Normal WordPress username/password login remains available.

## Exact DEV Redirect URI

Register this Web redirect URI in the DEV Entra app:

```text
https://ssfb.se/dev/ssf-auth/microsoft/callback/
```

The plugin generates this from `home_url()` and does not hardcode `/dev`.

## Exact PROD Redirect URI

Register this Web redirect URI in the PROD Entra app:

```text
https://ssfb.se/ssf-auth/microsoft/callback/
```

## Entra App Registration

1. Open Microsoft Entra admin center.
2. Go to App registrations.
3. Create a new registration.
4. Name: `SSF Web Login DEV`.
5. Supported account type: Accounts in this organizational directory only.
6. Platform: Web.
7. Redirect URI: `https://ssfb.se/dev/ssf-auth/microsoft/callback/`.
8. Create a client secret and store it outside Git.
9. Copy Application Client ID to the matching backend profile.
10. Use SSF Directory Tenant ID as Tenant ID in the matching backend profile.
11. Do not add Microsoft Graph application permissions.
12. Do not add SharePoint permissions.

For production, create a separate app registration named `SSF Web Login PROD` and use the PROD redirect URI above. Do not reuse DEV Client ID or DEV Client Secret in PROD.

## Authentication Flow

The login button starts Authorization Code Flow with PKCE, state and nonce. The callback exchanges the code server-side, cryptographically validates the ID token against tenant-specific Microsoft discovery/JWKS, enforces issuer/audience/tenant/expiry/nonce, then maps `tid + oid` to an existing WordPress user.

## Account Linking

An already authenticated WordPress user can open their profile and choose **Koppla Microsoft 365-konto**. The callback requires the original WordPress session to still be valid and links the validated `tid + oid` to the current user only if it is not already linked elsewhere.

Users can unlink from their own profile with nonce protection.

## Admin Backend

Open **SSF -> System -> Inloggning**. The page title is **Microsoft ID Login**.

The backend shows:

- Environment-specific status and configuration overview.
- Callback URL with a copy button.
- Technical connection test for tenant/client configuration, OpenID discovery and JWKS.
- Real Microsoft login test mode that exercises the OAuth/OIDC roundtrip without changing WordPress roles or permission groups.
- Current user's Microsoft account link status.
- Linked and unlinked WordPress users.
- Admin unlink for Microsoft mappings only.
- WordPress permission groups and the exact capabilities each group grants.

## WordPress Permission Groups

Microsoft Entra is used only for authentication. Authorization stays in WordPress.

The module stores SSF permission groups in WordPress user meta and grants the mapped WordPress capabilities through `user_has_cap`. It does not read Entra groups, Microsoft 365 groups, SharePoint groups or app roles, and it never promotes a Microsoft user to WordPress administrator.

Admins with SSF login/permission capability can manage groups from **SSF -> System -> Inloggning** or the WordPress user profile screen. Changes are recorded in `ssf_microsoft_login_permission_audit`.

## How To Test

1. Configure the matching environment profile.
2. Activate `Microsoft ID Login` in the target environment.
3. Visit `https://ssfb.se/dev/wp-login.php`.
4. Confirm normal WordPress login remains visible.
5. Confirm **Logga in med Microsoft 365** appears.
6. Open **SSF -> System -> Inloggning** and run **Testa Microsoft-konfiguration**.
7. Login as an existing WordPress user and link the Microsoft account from the profile page or account card.
8. Run **Testa riktig Microsoft-inloggning** from the admin page.
9. Log out and sign in with Microsoft 365.
10. Confirm an unlinked Microsoft user receives a friendly denial and no WordPress user is created.
11. Confirm permission groups are assigned in WordPress only.
12. Run `scripts/tests/*.ps1`.

## Disable Immediately

Set:

```php
define('SSF_M365_LOGIN_ENABLED', 'false');
```

or disable the active backend profile. The button and callback access will stop because the feature requires complete configuration and explicit enablement.

## Production Activation Checklist

- Create a production Entra app registration named `SSF Web Login PROD`.
- Register production redirect URI `https://ssfb.se/ssf-auth/microsoft/callback/`.
- Configure the production profile or production constants outside Git.
- Re-review tenant and token validation.
- Re-review account linking policy.
- Enable Microsoft login in PROD only after explicit approval.
- Run production readiness checks.
- Deploy only after explicit production approval.
