# Plan: AVP-PvH Member Login & Database

## Status: Implemented ✓

---

## Context

The AVP Philips van Horne WordPress site (`www.avphilipsvanhorne.nl`) has 50+ blog posts
all password-protected. Members log in with personal accounts (Google, Microsoft, or password)
and see content without needing the password. Former members can log in but see a notice and
content stays locked. Members who haven't paid the current year's dues see a popup on login.

---

## Architecture

```
Browser ──► nginx ──auth_request──► Authelia ──LDAP──► LLDAP (MariaDB: lldap.*)
                 └── HTTP_REMOTE_USER header ─────────► WordPress
                                                            └── avpvh-members plugin
                                                                  ├── reads lldap.users (email, user_id)
                                                                  └── pvh_avm_* tables (business data)
```

| Component | Role |
|-----------|------|
| **LLDAP** | Lightweight LDAP server; stores user accounts in MariaDB `lldap` DB. Exposes LDAP + GraphQL API. |
| **Authelia** | Guards `/wp-admin/**` (two_factor). All other pages bypassed — WordPress handles access. |
| **nginx** | `auth_request` for wp-admin; injects `HTTP_REMOTE_USER` header when Authelia session active. |
| **Plugin** | OAuth2 login (Google/Microsoft), proxy-header auto-login, content access control, fee popup, admin UI. |

### Authelia access control

| Path | Policy |
|------|--------|
| `auth.avphilipsvanhorne.nl` | bypass |
| `leden-admin.avphilipsvanhorne.nl` | two_factor |
| `www.avphilipsvanhorne.nl` `/wp-admin/**` | two_factor |
| `www.avphilipsvanhorne.nl` everything else | bypass |

---

## Login flows

### 1. Google / Microsoft OAuth2
1. Member visits `/avpvh-login/` → sees login options
2. Clicks "Inloggen met Google/Microsoft" → redirected to provider
3. Provider redirects to `/wp-json/avpvh/v1/oauth/{provider}/callback`
4. Plugin fetches email from provider, looks up member by email (cross-DB JOIN)
5. Creates or finds WP user, sets auth cookie, redirects to homepage

### 2. Wachtwoord (Authelia)
1. Member clicks "Inloggen met wachtwoord" → Authelia login page
2. Authenticates with LLDAP username + password
3. Authelia sets session cookie, redirects to `/avpvh-login/`
4. nginx injects `HTTP_REMOTE_USER`; plugin auto-login fires on `init`
5. Redirected to homepage

---

## Database Schema

### lldap.users (owned by LLDAP — read-only from plugin)

| Column | Type |
|--------|------|
| user_id | VARCHAR(255) PK |
| email | VARCHAR(255) UNIQUE |
| lowercase_email | VARCHAR(255) UNIQUE |
| display_name | VARCHAR(255) |
| uuid | VARCHAR(36) UNIQUE |

### pvh_avm_members

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AUTO | |
| lldap_user_id | VARCHAR(255) UNIQUE | FK → lldap.users.user_id |
| wp_user_id | INT NULL | Set on first login |
| first_name, last_name | VARCHAR | |
| status | ENUM('active','inactive','visitor') | |
| joined_year, left_year | YEAR NULL | |

**No `email` column** — fetched by JOIN with `lldap.users`.

### pvh_avm_addresses, pvh_avm_camps, pvh_avm_camp_participation, pvh_avm_fees
Standard relational tables, FK → pvh_avm_members.id.

---

## Plugin files

```
avpvh-members.php           Bootstrap, admin bar hide, wp-login.php redirect, logout_url filter
includes/
  class-db.php              Cross-DB JOINs, member/fee/camp queries
  class-access.php          Login page render, proxy-header auto-login, content access
  class-oauth.php           Google + Microsoft OAuth2 flows
  class-nav-auth.php        Nav login/logout button injection (CSP-safe JSON data tag)
  class-fee-popup.php       Fee popup on login
  class-admin.php           Admin UI: member list, detail, settings, credential tests
  class-lldap.php           LLDAP GraphQL API client + connection test
admin/
  members-list.php
  member-detail.php
assets/
  avpvh.css
  nav-auth.js               Reads config from <script type="application/json">
  login-form.js             Renders login buttons (CSP-safe, no inline JS)
  fee-popup.js / .css
  ledenlijst.js / .css
config/
  authelia-configuration.yml
scripts/
  deploy.sh
  test-user.sh              Add/remove temporary test users
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
