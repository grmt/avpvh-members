# AVP-PvH Members Plugin

WordPress plugin for AVP Philips van Horne to manage members, camp participation, and fees, integrated with OpenLDAP and Authelia.

## Features

- **OAuth2 Login:** Members log in with Google or Microsoft. Login flow matches their registered email address to the member database.
- **SSO Password Login:** Authelia username+password login via "Inloggen met wachtwoord" using a 1FA SSO callback (`/avpvh-sso/`).
- **Step-Up 2FA:** Normal member access requires only 1FA (OAuth or password). Stepping up to administrative areas (`/wp-admin/**`) triggers Authelia two-factor authentication (TOTP/WebAuthn).
- **Auto-login via proxy header:** When an Authelia session is active, `HTTP_REMOTE_USER` is trusted for automatic WP session setup.
- **Identity Management:** OpenLDAP (`ou=avpvh,dc=nl`) is the single source of truth for identity (emails, user IDs, groups). Fast local SQL JOINs via the `pvh_avm_directory_users` cache table.
- **Access Control:** Bypasses post passwords for active members; shows notices to ex-members.
- **Admin UI:** Member list, detail views, fee management, and roles/delegation in the WordPress backend.

## Architecture

```
Browser ──► nginx ──auth_request──► Authelia ──LDAP──► OpenLDAP (ou=avpvh,dc=nl)
                 └── HTTP_REMOTE_USER header ────────► WordPress
                                                           └── avpvh-members plugin
                                                                 ├── pvh_avm_directory_users (read cache)
                                                                 └── pvh_avm_* tables (business data)
```

### Authentication flows

| Flow | When | Factor Level |
|------|------|--------------|
| Google / Microsoft OAuth2 | Member logs in via `/avpvh-login/` using their personal account | 1FA |
| Authelia (wachtwoord) | Member logs in via Authelia with username + password via `/avpvh-sso/` | 1FA |
| Proxy header (auto-login) | Authelia session active; `HTTP_REMOTE_USER` passed by nginx to WordPress | 1FA or 2FA |
| Step-Up 2FA | Member accesses `/wp-admin/**` or admin tools | 2FA (TOTP/WebAuthn) |

### Authelia access control

- `/wp-admin/**` → `two_factor`
- `/avpvh-sso/` → `one_factor` (SSO callback for password login)
- Everything else → `bypass` (WordPress plugin handles access)

## Login page (`/avpvh-login/`)

The `/avpvh-login/` page is bypassed by Authelia. The plugin renders a login screen with:
- Explanation of which email address to use
- "Inloggen met Google" (if configured)
- "Inloggen met Microsoft" (if configured)
- "Inloggen met wachtwoord" → Authelia 1FA portal, returning to `/avpvh-sso/`

## Setup

1. Register a Google OAuth2 app at Google Cloud Console (External, add test users or verify).
   - Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/google/callback`
2. Register a Microsoft OAuth2 app at portal.azure.com.
   - Redirect URI: `https://www.avphilipsvanhorne.nl/wp-json/avpvh/v1/oauth/microsoft/callback`
3. Enter client IDs and secrets in **WP Admin → AVP-PvH Leden → Instellingen**.
4. Import members using `scripts/import-avpvh-members.py`.

### Camp participation imports

The camp workbook has its own fixed `totaal inschrijvingen` grid and is not
processed by the configurable generic sheet importer in **Activiteit
betalingen**. These two camp-specific scripts accept either an overview
workbook or the camp archive directory. Always run a dry-run before writing
production data.

```bash
# Import/update participation records from the newest workbook in a directory.
python3 scripts/import-avpvh-camps.py /path/to/exports/ --latest --dry-run
python3 scripts/import-avpvh-camps.py /path/to/exports/ --latest

# Refresh the display snapshot from the newest .xlsx export in a directory.
python3 scripts/sync-kamp-overzicht.py /path/to/exports/ --dry-run
python3 scripts/sync-kamp-overzicht.py /path/to/exports/
```

`import-avpvh-camps.py` exits with status 2 when names remain unmatched or
ambiguous, after reporting them for manual review. It reads names from column
D, the day grid from E-T, and nawacht/notes/diet from W-Z. An import replaces
that participant's day-by-day attendance for the camp. The overview option
name is derived from the campaign year (for example
`avpvh_kamp_2026_overzicht`); use `--year` or `--option-name` when the year
cannot be inferred correctly.

## Deploy

```bash
# Plugin
sudo rsync -a --delete ~/03-src/avpvh-members/ /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/

# Authelia config
sudo cp ~/03-src/avpvh-members/config/authelia-configuration.local.yml /opt/docker/volumes/authelia/config/configuration.yml
docker compose -f /opt/docker/scripts/docker-compose.yml restart authelia
```

## Development Workflow

- Never commit or push directly to `main`.
- Create a feature branch: `git checkout -b <type>/<short-description>`.
- Push to origin and open a PR.
