# AVP-PvH Members Plugin

WordPress plugin for AVP Philips van Horne to manage members, camp participation, and fees, integrated with LLDAP and Authelia.

## Features

- **OAuth2 Login:** Members log in with Google or Microsoft. Login flow matches their registered email address to the member database.
- **SSO Fallback:** Authelia/LLDAP username+password login via "Inloggen met wachtwoord".
- **Auto-login via proxy header:** When Authelia is active (e.g. for wp-admin), `HTTP_REMOTE_USER` is trusted for automatic WP session setup.
- **Identity Management:** LLDAP is the single source of truth for identity (emails, user IDs). Cross-DB JOINs between `lldap.users` and `pvh_avm_*` tables.
- **Access Control:** Bypasses post passwords for active members; shows notices to ex-members.
- **Fee Popup:** Notifies members on login if current year's fees are pending.
- **Admin UI:** Member list, detail views, fee management in the WordPress backend.
- **LLDAP connection test:** Test LLDAP credentials from the settings page without saving them.

## Architecture

```
Browser ──► nginx ──auth_request──► Authelia ──LDAP──► LLDAP (MariaDB: lldap.*)
                 └── HTTP_REMOTE_USER header ────────► WordPress
                                                           └── avpvh-members plugin
                                                                 ├── reads lldap.users (email, user_id)
                                                                 └── pvh_avm_* tables (business data)
```

### Authentication flows

| Flow | When |
|------|------|
| Google / Microsoft OAuth2 | Member logs in via `/avpvh-login/` using their Google or Microsoft account |
| Authelia (wachtwoord) | Member logs in via Authelia with LLDAP username + password |
| Proxy header (auto-login) | Authelia session active (e.g. after wp-admin login); `HTTP_REMOTE_USER` set by nginx |

### Authelia access control

- `/wp-admin/**` → `two_factor`
- Everything else → `bypass` (WordPress plugin handles access)

## Login page (`/avpvh-login/`)

The `/avpvh-login/` page is bypassed by Authelia. The plugin renders a login screen with:
- Explanation of which email address to use
- "Inloggen met Google" (if configured)
- "Inloggen met Microsoft" (if configured)
- "Inloggen met wachtwoord" → Authelia

## Setup

1. Register a Google OAuth2 app at Google Cloud Console (External, add test users or verify).
   - Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/google/callback`
2. Register a Microsoft OAuth2 app at portal.azure.com.
   - Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/microsoft/callback`
3. Enter client IDs and secrets in **WP Admin → AVP-PvH Leden → Instellingen**.
4. Ensure the WordPress DB user has `SELECT` on the `lldap` database.
5. Import members using `scripts/import-avpvh-members.py`.

## Deploy

```bash
# Plugin
sudo rsync -a --delete ~/04-src/avpvh-members/ /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/

# Authelia config
sudo cp ~/04-src/avpvh-members/config/authelia-configuration.yml /opt/docker/volumes/authelia/config/configuration.yml
docker compose -f /opt/docker/scripts/docker-compose.yml restart authelia
```

## Test users

Use `scripts/test-user.sh` to add/remove temporary test users:

```bash
./scripts/test-user.sh add garmt.noname garmt.noname@gmail.com Garmt "Noname (test)"
./scripts/test-user.sh remove garmt.noname
```

## Development Workflow

Changes are committed locally, pushed to GitHub, then deployed on the remote host via git pull and rsync.
