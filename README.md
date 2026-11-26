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
- **Deleting an activity:** *Activiteiten → Activiteit verwijderen* deletes an activity only when nothing refers to it: no participants, and nothing reported by other plugins through the `avpvh_activity_delete_blockers` filter (`fn(array $reasons, int $activity_id): array`, add one readable reason per kind of data; avpvh-bookkeeping reports its rates, fee items, transactions and payment requests). While avpvh-bookkeeping is active without that filter, deleting is blocked. Afterwards `avpvh_activity_deleted` fires with the id and the old row.
- **Ledenbeheer list:** Sorting and filtering sit under the column headers (text filters, or tri-state checklists for Status, Contributie and Kenmerken), with a search box that also matches recorded name variants and a *Kolommen* menu whose choice is remembered per browser. It is the same component as avpvh-bookkeeping's tables (`assets/sortable-table.js`). Links can pre-fill filters: `?page=avpvh-members&f_status=Bezoeker`, `&f_last_name=…`, `&s=…`. After every filter change the table dispatches an `avpvh-table-filtered` event (`detail: {visible, total}`) for page-specific extras such as footer totals. Other plugins use it through the registered handles `avpvh-sortable-table` (script and style, front end and admin).
- **Page permissions:** Which roles see Ledenbeheer, Activiteiten, Nieuwsbrief, Loginpogingen and DMARC-rapporten is a matrix on *Paginarechten*, editable by the voorzitter and the IT-beheerder (directory group `it-beheer`). Defaults keep the original rules (secretaris/penningmeester for members, secretaris for activities and newsletter) plus the IT-beheerder everywhere; DMARC-rapporten is IT-beheerder only by default. That screen shows the report that `dmarc-reports.py` (docker-scripts) publishes as a page (ID in option `avpvh_dmarc_page_id`); the page's own URL redirects to it, or to the home page for anyone else. Instellingen, Leden samenvoegen and Rollen & delegatie keep fixed rules.

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

### WordPress and blogger access

- A member's WordPress user exists for the session only (role `subscriber`).
  Whatever role is stored, members get no content capabilities
  (`AVPVH_Access::filter_member_capabilities()`); WP admins are untouched.
- The directory group `bloggers` gives contributor-level rights: own draft
  posts only, no publishing, media or pages. The profile page links
  bloggers to blog management.
- wp-admin is only for WP admins, bloggers, bestuur and whoever sees a
  plugin admin page (`AVPVH_Roles::current_user_needs_wp_admin()`); others
  are sent to the home page. admin-post/AJAX stay open for front-end forms.

### Security boundaries

REST single items, comments (including submissions), collections and canonical
redirects follow the same content permissions on the main site and tenants.
Administrative REST, AJAX and admin-post actions require a session with verified
2FA; explicit frontend actions retain their own nonce and target checks.
Companion plugins can register frontend exceptions with
`avpvh_mbrs_2fa_frontend_ajax_actions` and
`avpvh_mbrs_2fa_frontend_admin_post_actions`; only add actions that enforce
self-service scope themselves. Application Passwords and authenticated XML-RPC
are disabled on both modes.

OAuth replaces the incoming browser's WordPress session and ends its Authelia
session. Tenant member download links also require the issuing member's current
session and access on each download; e-mail links follow the current share list.
Share e-mail guessing is limited per IP and per share.

New private-media JWTs expire after 15 minutes. The session watchdog refreshes
through WordPress, checking current membership, within the original eight-hour
session boundary. Existing eight-hour JWTs are accepted during migration and
retain their original expiry at OpenResty. Publishing media is still a one-way
operation: moving a previously public page behind an access boundary cannot
revoke copies or old public URLs. DMARC and document-search pages never promote
new uploads to public storage.

### Rechtspreker tenant sites

With `wp-content/avpvh-tenant.php` (or the constant `AVPVH_TENANT_CONFIG`)
the plugin runs as a generic login layer for a Rechtspreker tenant such as
VP40-42 instead of the AVP-PvH member system: Authelia login, local users
per directory account, `administrator` for the tenant's own or an ancestor
admin group, `subscriber` for the member group, and members-only pages.
Optionally members also log in with Google or Microsoft (`oauth` plus a
read-only `directory` in the tenant file): the verified address must match
one directory account, and this route never gives more than `subscriber`.
Private files under `uploads/private/` are checked by the plugin for
nginx's `auth_request` (`/avpvh-file-auth/`), so both logins open them.
No AVP module or table is loaded. Design, configuration and rollout:
`PLAN-rsp-tenant-admin-sso.md`; tests: `scripts/test-tenant-*.php` and
`scripts/tenant-e2e/run.sh` (Docker); server check:
`includes/tenant/healthcheck.php`.

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

### Address dates in member imports

Membership-administration addresses without a start date use **2025-01-01**,
including newly created members in `reconcile-members.py`. An optional
`adres_begindatum` / `Begindatum adres` column preserves an explicit date.
An invalid date stops the import instead of silently using the default.
Reconciliation selects the latest currently valid address and preserves it
when its start date is later than the spreadsheet address. Future address
changes and historical end dates are preserved.

For legacy member addresses with NULL or zero start dates, use
`scripts/backfill-imported-address-dates.php`. Its header documents the
dry-run snapshot and apply commands. Keep the snapshot outside Git; it is
also the backup of the original dates. Visitors are excluded. Apply checks
that the snapshot still matches, changes only `valid_from`, verifies all
other address data and rolls back on failure.

### Name aliases, address overlaps and privacy check

These run with `wp eval-file`; each header documents its options.

- `scripts/import-member-name-aliases.php` adds name variants in bulk from
  the ignored `scripts/member-name-aliases.local.json`. Dry-run by default;
  `AVPVH_ALIAS_APPLY=1` saves through the same code as Ledendetail →
  Naamvarianten.
- `scripts/report-address-overlaps.php` lists members with more than one
  current address (IDs and counts only), split into spelling-only
  duplicates and real conflicts.
- `scripts/check-no-member-names.php` scans the source tree (and with
  `AVPVH_PRIVACY_SCAN_GIT_HISTORY=1` the commit messages) for official
  member names and aliases. Matches are reported as hashes, never names.

`scripts/promote-scrubbed-main.sh` force-pushes a privacy-rewritten branch
to `main`, temporarily lifting branch protection. Only the repository owner
runs it; it asks for typed confirmation.

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
ambiguous, after reporting them for manual review.

Participants who are not in the member administration can be added as
Bezoeker (status `visitor`, name only, no login):

```bash
# 1. Dry-run that also writes the unmatched people (Git-ignored *.local.* file).
python3 scripts/import-avpvh-camps.py /path/to/Opgravingen/ --dry-run \
    --unmatched-out camp-unmatched.local.json   # written to scripts/
# 2. Review the file: fix the proposed first name / tussenvoegsel / last name,
#    clear "review": true, set "alias_of": <member id> for anyone who already
#    exists under another spelling (recorded as a Naamvariant of that person),
#    and "create": false for entries that are not a person.
# 3. Create them (dry-run first), in the WP-CLI container. Mount the
#    checkout's scripts/ read-only: never copy the names file into
#    wp-content, which is web-reachable. The container user must be able to
#    read the file (mode 644 in a folder only you can enter).
cd /opt/docker/scripts
docker compose run -T --rm --no-deps -v "$OLDPWD/scripts:/camp:ro" \
    -e AVPVH_CAMP_VISITORS_FILE=/camp/camp-unmatched.local.json \
    wpcli-pvh wp eval-file /camp/create-camp-visitors.php
docker compose run -T --rm --no-deps -v "$OLDPWD/scripts:/camp:ro" \
    -e AVPVH_CAMP_VISITORS_FILE=/camp/camp-unmatched.local.json \
    -e AVPVH_CAMP_VISITORS_APPLY=1 \
    wpcli-pvh wp eval-file /camp/create-camp-visitors.php
cd -
# 4. Import again: the new Bezoekers now match.
python3 scripts/import-avpvh-camps.py /path/to/Opgravingen/ --dry-run
python3 scripts/import-avpvh-camps.py /path/to/Opgravingen/
```

`create-camp-visitors.php` uses the same `create_person()` as *Nieuwe
persoon* (directory account with a placeholder address, member row), skips
anyone whose name or a recorded variant already matches, and prints entry
numbers and member ids, never names. Ambiguous names (two people with the
same name) stay manual. It reads names from column
D, the day grid from E-T, and nawacht/notes/diet from W-Z. Camps up to 2014
predate that sheet: their `Inschrijving` sheet (header `VOORNAAM & ACHTERNAAM`)
is read instead, and an archive camp folder without an overview falls back to
its top-level workbooks named `*inschrijvingen*` or `*algemeen*`. An import replaces
that participant's day-by-day attendance for the camp. Names are matched on the
official name and on the recorded name variants (Naamvarianten). The camp is
the year's existing Kamp activity, whatever its name (with several, the one
named after the place); a new one is named after the place only (folder
`2021 Goeblange III` gives `Goeblange`), with the folder as kenmerk and the
grid's first and last day as dates. The overview option
name is derived from the campaign year (for example
`avpvh_kamp_2026_overzicht`); use `--year` or `--option-name` when the year
cannot be inferred correctly.

## Deploy

```bash
# Plugin — vanaf je werkstation; deployt `git archive HEAD` (zie DEPLOY.md)
scripts/deploy.sh

# Authelia config
sudo cp ~/03-src/avpvh-members/config/authelia-configuration.local.yml /opt/docker/volumes/authelia/config/configuration.yml
docker compose -f /opt/docker/scripts/docker-compose.yml restart authelia
```

## Development Workflow

- Never commit or push directly to `main`.
- Create a feature branch: `git checkout -b <type>/<short-description>`.
- Push to origin and open a PR.
