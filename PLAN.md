# Plan: AVP-PvH Member Login & Database

## Status: Implemented ✓

---

## Context

The AVP Philips van Horne WordPress site (`www.avphilipsvanhorne.nl`) has 50+ blog posts
all password-protected. Members log in with personal accounts (Google, Microsoft, or password)
and see content without needing the password. Former members can log in but see a notice and
content stays locked.

---

## Architecture

```
Browser ──► nginx ──auth_request──► Authelia ──LDAP──► OpenLDAP (ou=avpvh,dc=nl)
                 └── HTTP_REMOTE_USER header ─────────► WordPress
                                                            └── avpvh-members plugin
                                                                  ├── pvh_avm_directory_users (read cache)
                                                                  └── pvh_avm_* tables (business data)
```

| Component | Role |
|-----------|------|
| **OpenLDAP** | LDAP directory server (`ou=avpvh,dc=nl`); stores user accounts, emails, display names, and groups. |
| **Authelia** | Enforces `two_factor` for `/wp-admin/**` and `one_factor` for `/avpvh-sso/`. All other pages bypassed — WordPress handles access. |
| **nginx** | `auth_request` subrequest to Authelia; injects `HTTP_REMOTE_USER` header when Authelia session active. |
| **Plugin** | OAuth2 login (Google/Microsoft), proxy-header auto-login, content access control, admin UI, directory cache. |

### Authelia access control

| Path | Policy | Notes |
|------|--------|-------|
| `auth.avphilipsvanhorne.nl` | bypass | Authelia portal |
| `www.avphilipsvanhorne.nl` `/avpvh-sso/?` | one_factor | SSO callback for password login |
| `www.avphilipsvanhorne.nl` `/wp-admin/**` | two_factor | Admin backend (step-up 2FA) |
| `www.avphilipsvanhorne.nl` everything else | bypass | Handled by WordPress plugin |

---

## Login flows

### 1. Google / Microsoft OAuth2 (1FA)
1. Member visits `/avpvh-login/` → sees login options
2. Clicks "Inloggen met Google/Microsoft" → redirected to provider
3. Provider redirects to `/wp-json/avpvh/v1/oauth/{provider}/callback`
4. Plugin fetches email from provider, looks up member in directory cache
5. Creates or finds WP user, sets auth cookie, redirects to target or home

### 2. Password Login via Authelia (1FA) & Step-Up (2FA)
1. Member clicks "Inloggen met wachtwoord" → directed to Authelia with `?rd=https://www.avphilipsvanhorne.nl/avpvh-sso/`
2. Authenticates with OpenLDAP username + password (1FA)
3. Because `/avpvh-sso/` only requires `one_factor`, Authelia does not ask for 2FA; it sets session cookie and redirects to `/avpvh-sso/`
4. nginx passes request to WordPress with `HTTP_REMOTE_USER`
5. `AVPVH_Access::auto_login_from_proxy_header()` authenticates WP user; `handle_sso_callback()` redirects to target or home
6. If the user later navigates to an administrative area (`/wp-admin/**`), Authelia's `two_factor` policy kicks in and prompts for 2FA (step-up authentication).

---

## Database Schema

### pvh_avm_directory_users (OpenLDAP cache — read-only from plugin queries)

| Column | Type |
|--------|------|
| uid | VARCHAR(191) PK |
| mail | VARCHAR(255) |
| lowercase_mail | VARCHAR(255) KEY |
| display_name | VARCHAR(255) |
| entry_uuid | VARCHAR(64) |
| synced_at | DATETIME |

### pvh_avm_members

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AUTO | |
| lldap_user_id | VARCHAR(255) UNIQUE | Directory UID (e.g. `jan.jansen`) |
| wp_user_id | INT NULL | Set on first login |
| first_name, last_name | VARCHAR | |
| status | ENUM('active','inactive','visitor') | |
| joined_year, left_year | YEAR NULL | |
| directory_consent | ENUM('pending','granted','declined') | AVG/GDPR consent to appear in member directory |
| directory_consent_at | TIMESTAMP NULL | When consent was last changed |
| share_email, share_phone, share_address | TINYINT(1) | Per-field opt-out once consent is granted |

**No `email` column** — fetched by JOIN with `pvh_avm_directory_users`.

### pvh_avm_addresses, pvh_avm_camps, pvh_avm_camp_participation, pvh_avm_fees
Standard relational tables, FK → pvh_avm_members.id.

---

## Plugin files

```
avpvh-members.php           Bootstrap, admin bar hide, wp-login.php redirect, logout_url filter
includes/
  class-db.php              Member/fee/camp queries, directory cache JOINs
  class-directory.php       Directory abstraction (OpenLDAP client + cache)
  class-access.php          Login page render, proxy-header auto-login, SSO callback, content access
  class-oauth.php           Google + Microsoft OAuth2 flows
  class-nav-auth.php        Nav login/logout button injection (CSP-safe JSON data tag)
  class-admin.php           Admin UI: member list, detail, settings
admin/
  members-list.php
  member-detail.php
assets/
  avpvh.css
  nav-auth.js               Reads config from <script type="application/json">
  login-form.js             Renders login buttons (CSP-safe, no inline JS)
  ledenlijst.js / .css
config/
  authelia-configuration.local.yml
scripts/
  import-avpvh-members.py
  import-avpvh-camps.py
```

---

## OAuth2 setup

### Google
- Project: separate project in Google Cloud Console (not shared with other apps)
- User type: External; test users added manually (max 100 without verification)
- Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/google/callback`

### Microsoft
- App registration in Azure portal under a dedicated Microsoft account
- Supported account types: Personal Microsoft accounts only
- Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/microsoft/callback`
- Client secret expires after 24 months — renew and update in WP settings

---

## CSP compatibility

`wp_localize_script` generates inline `<script>` tags blocked by Authelia's CSP.
Solution: data passed via `<script type="application/json" id="...">` tags in wp_footer,
read by JS via `JSON.parse(document.getElementById(...).textContent)`.
