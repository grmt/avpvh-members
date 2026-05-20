# Plan: AVP-PvH Member Login & Database

## Context

The AVP Philips van Horne WordPress site (pvh tenant, `www.avphilipsvanhorne.nl`) has 50+ blog posts
all password-protected with the single password "Voegenteller". The club wants members to log in with
personal accounts and see content without needing the password. Former members can log in but see a
notice and content stays locked. Members who haven't paid the current year's membership dues see a
popup on login. Member data, address history, excavation camp participation (2010–2026), and fee
tracking all live in custom DB tables created from existing XLS files.

---

## Architecture

```
Browser ──► nginx ──auth_request──► Authelia ──LDAP──► LLDAP (MariaDB: lldap.*)
                 └── X-Remote-User header ──────────► WordPress
                                                          └── avpvh-members plugin
                                                                ├── reads lldap.users (email, user_id)
                                                                └── pvh_avm_* tables (business data)
```

| Component | Role |
|-----------|------|
| **LLDAP** | Lightweight LDAP server; stores user accounts in MariaDB database `lldap` (same server as WordPress). Exposes LDAP + GraphQL API + web UI. |
| **Authelia** | Identity broker; authenticates against LLDAP. Guards protected paths on `www.avphilipsvanhorne.nl`. Passes `Remote-User` (email) header after auth. |
| **nginx** | `auth_request /authelia` for protected locations; injects `X-Remote-User` header; bypasses auth for public paths. |
| **Plugin** | Reads `X-Remote-User` → auto-logs WP user in. Checks `pvh_avm_members.status` for content bypass + fee popup. Admin UI manages members + calls LLDAP GraphQL API. |
| **lldap.users** | Identity: `user_id`, `email`, `display_name`. Single source of truth for login credentials. |
| **pvh_avm_members** | Business data: first/last name, phone, status, fees, camps, addresses. Linked to LLDAP by `lldap_user_id`. Email is NOT duplicated here. |

### Public vs protected pages

Authelia `access_control` for `www.avphilipsvanhorne.nl`:

| Path pattern | Policy |
|---|---|
| `/wp-content/**`, `/wp-json/**`, `/wp-login.php` | bypass |
| `/`, `/over-ons/**`, `/contact/**` (configurable) | bypass |
| `/wp-admin/**` | two_factor |
| everything else (blog posts) | one_factor |

---

## Deliverables

1. **LLDAP Docker service** — MariaDB backend (`lldap` database)
2. **Authelia config update** — LDAP backend + `avphilipsvanhorne.nl` session cookie + access rules
3. **nginx config update** — `auth_request` + header injection for pvh vhost
4. **WordPress plugin** — `avpvh-members` (proxy-header auto-login, content access, admin UI)
5. **Python import scripts** — members from XLS (LLDAP user + DB row), camps from XLS

---

## Database Schema

### lldap.users (owned by LLDAP — read-only from plugin)

| Column | Type |
|--------|------|
| user_id | VARCHAR(255) PK — login uid, e.g. `j.jansen` |
| email | VARCHAR(255) UNIQUE |
| lowercase_email | VARCHAR(255) UNIQUE |
| display_name | VARCHAR(255) |
| password_hash | BLOB |
| uuid | VARCHAR(36) UNIQUE |
| creation_date | DATETIME |
| modified_date | DATETIME |
| password_modified_date | DATETIME |

### pvh_avm_members (plugin-owned, in wordpress_pvh database)

| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AUTO | |
| lldap_user_id | VARCHAR(255) UNIQUE NOT NULL | FK to lldap.users.user_id |
| wp_user_id | INT NULL | Auto-created on first proxy-auth login |
| first_name | VARCHAR(100) | |
| last_name | VARCHAR(100) | |
| birth_date | DATE NULL | |
| phone | VARCHAR(30) | |
| mobile | VARCHAR(30) | |
| emergency_contact | VARCHAR(200) | |
| status | ENUM('active','inactive','visitor') | |
| joined_year | YEAR NULL | |
| left_year | YEAR NULL | |
| created_at / updated_at | TIMESTAMP | |

**No `email` column** — email is fetched by JOIN with `lldap.users`.

### pvh_avm_addresses (unchanged, FK → pvh_avm_members.id)
### pvh_avm_camps (unchanged)
### pvh_avm_camp_participation (unchanged, FK → pvh_avm_members.id)
### pvh_avm_fees (unchanged, FK → pvh_avm_members.id)

### Required MariaDB grant

The WordPress DB user needs SELECT on lldap.* for cross-DB JOINs:

```sql
GRANT SELECT ON lldap.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
```

---

## Infrastructure Changes

### 1. LLDAP Docker service

```yaml
lldap:
  image: lldap/lldap:stable
  restart: unless-stopped
  expose:
    - 3890    # LDAP
    - 17170   # web UI + GraphQL API
  networks:
    - web_network
  environment:
    - LLDAP_JWT_SECRET_FILE=/run/secrets/lldap_jwt_secret
    - LLDAP_LDAP_BASE_DN=dc=avpvh,dc=nl
    - LLDAP_DATABASE_URL=mysql://wordpress:PASSWORD@mariadb:3306/lldap
    - LLDAP_LDAP_USER_PASS_FILE=/run/secrets/lldap_admin_password
  secrets:
    - lldap_jwt_secret
    - lldap_admin_password
```

### 2. Authelia configuration additions

```yaml
authentication_backend:
  ldap:
    address: ldap://lldap:3890
    base_dn: dc=avpvh,dc=nl
    username_attribute: uid
    additional_users_dn: ou=people
    users_filter: (&({username_attribute}={input})(objectClass=person))
    additional_groups_dn: ou=groups
    groups_filter: (member={dn})
    group_name_attribute: cn
    mail_attribute: mail
    user: uid=admin,ou=people,dc=avpvh,dc=nl
    password: <lldap admin password>

session:
  cookies:
    - name: authelia_session
      domain: rechtspreker.nl
      authelia_url: https://auth.rechtspreker.nl
      expiration: 1 hour
      inactivity: 5 minutes
    - name: avpvh_session
      domain: avphilipsvanhorne.nl
      authelia_url: https://auth.avphilipsvanhorne.nl
      expiration: 8 hours
      inactivity: 30 minutes
```

### 3. nginx changes (pvh vhost)

```nginx
location /authelia {
    internal;
    proxy_pass http://authelia:9091/api/authz/auth-request;
    proxy_set_header X-Original-URL $scheme://$http_host$request_uri;
    proxy_set_header Content-Length "";
    proxy_pass_request_body off;
}

location / {
    auth_request /authelia;
    auth_request_set $user $upstream_http_remote_user;
    error_page 401 =302 https://auth.avphilipsvanhorne.nl/?rd=$scheme://$http_host$request_uri;
    proxy_set_header X-Remote-User $user;
    # ... existing proxy_pass to WordPress
}
```

---

## Plugin Structure

```
avpvh-members.php
includes/
  class-db.php          dbDelta schema + cross-DB query helpers (JOINs with lldap.users)
  class-access.php      proxy-header auto-login + password_required bypass + ex-member notice
  class-fee-popup.php   wp_login hook, wp_footer modal
  class-admin.php       admin menu + mark-fee-paid handler
  class-lldap.php       LLDAP GraphQL API client (create / update / delete users)
admin/
  members-list.php
  member-detail.php
assets/
  fee-popup.js
  fee-popup.css
```

### Key query pattern (class-db.php)

```php
// All member queries JOIN across database boundary
$lldap = defined('AVPVH_LLDAP_DB') ? AVPVH_LLDAP_DB : 'lldap';

SELECT u.user_id, u.email, u.display_name,
       m.id, m.lldap_user_id, m.wp_user_id,
       m.first_name, m.last_name, m.status, m.phone ...
FROM {$lldap}.users u
JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id
WHERE ...
```

### class-access.php — proxy header auto-login

On `init`: reads `$_SERVER['HTTP_X_REMOTE_USER']` (email), looks up member by email (cross-DB JOIN),
auto-provisions WP user on first login, calls `wp_set_current_user` + `wp_set_auth_cookie`.

### class-lldap.php — GraphQL API client

Authenticates via `POST /auth/simple/login` → Bearer JWT.
Wraps: `createUser`, `updateUser`, `deleteUser`, `addUserToGroup`, `removeUserFromGroup`.
Credentials stored in `wp_options`: `avpvh_lldap_url`, `avpvh_lldap_password`.

---

## Import Scripts

### scripts/import-avpvh-members.py

For each row in XLS:
1. Create LLDAP user via GraphQL API → get `user_id` back
2. Add to group `leden` (active) or `ex-leden` (inactive)
3. INSERT `pvh_avm_members` with `lldap_user_id`, first/last name, phone, status, etc.
4. INSERT `pvh_avm_addresses`
5. INSERT `pvh_avm_fees` (active members only, current year, pending)

WP user creation is deferred to first login. Idempotent on email.

### scripts/import-avpvh-camps.py

Unchanged logic; matches participants by last_name + first_name against `pvh_avm_members`.

---

## Login Flow

1. Member visits protected blog post → nginx `auth_request` → Authelia detects no session → redirects to `https://auth.avphilipsvanhorne.nl`.
2. Member logs in with email + password → Authelia verifies against LLDAP → sets `avpvh_session` cookie.
3. Redirected back → nginx injects `X-Remote-User: <email>` → WordPress receives request.
4. Plugin `init` hook reads header → JOIN `lldap.users` + `pvh_avm_members` by email → auto-logs WP user in.
5. `password_required` filter → active member → `false` → post content shown.
6. Password reset / forgot password → handled entirely by Authelia + LLDAP. No login form in plugin.

---

## File Paths to Create/Modify

| Path | Action |
|------|--------|
| `/opt/docker/scripts/docker-compose.yml` | Modify: add `lldap` service |
| `/opt/docker/volumes/authelia/config/configuration.yml` | Modify: LDAP backend + pvh domain |
| `/opt/docker/volumes/openresty/<pvh-vhost>.conf` | Modify: auth_request + headers |
| `avpvh-members.php` | Create |
| `includes/class-db.php` | Create |
| `includes/class-access.php` | Create |
| `includes/class-fee-popup.php` | Create |
| `includes/class-admin.php` | Create |
| `includes/class-lldap.php` | Create |
| `admin/members-list.php` | Create |
| `admin/member-detail.php` | Create |
| `assets/fee-popup.js` | Create |
| `assets/fee-popup.css` | Create |
| `scripts/import-avpvh-members.py` | Create |
| `scripts/import-avpvh-camps.py` | Create |

No `templates/login-page.php` — login handled entirely by Authelia.

---

## Verification

1. Start LLDAP; confirm `lldap` database + tables created in MariaDB; grant SELECT to wordpress user.
2. Restart Authelia; confirm login at `auth.avphilipsvanhorne.nl` works against LLDAP.
3. Run `import-avpvh-members.py` — verify ~94 active + ~24 inactive in LLDAP web UI and in DB.
4. Reload nginx pvh vhost; visit protected post unauthenticated → redirect to Authelia.
5. Log in as active member → post content visible, no password form.
6. Log in as ex-member → "lidmaatschap beëindigd" notice.
7. Set 2026 fee pending for test user → log in → fee popup appears.
8. Mark fee paid in WP admin → log in again → no popup.
9. Admin: member list, detail tabs (contact/addresses, camps, fees), sync via LLDAP API.
10. Run `import-avpvh-camps.py` — check matched/unmatched log.
