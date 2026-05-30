# AI Agent Guidance - AVP-PvH Member System

Welcome, Agent. This repository is a WordPress plugin integrated with LLDAP, Authelia, and a Docker stack. Read the documents below before making any changes.

## Steering Documents

1. **[GEMINI.md](./GEMINI.md)** — Architectural mandates. Read this first.
2. **[README.md](./README.md)** — System overview, setup, deploy instructions.
3. **[PLAN.md](./PLAN.md)** — Design decisions, login flows, file structure, OAuth setup notes.

## Taal & Toon

- **Spreek gebruikers aan met "je/jij"**, niet met "u". Dit geldt voor alle gebruikersgerichte teksten in PHP-templates, foutmeldingen, pop-ups en loginpagina's.

## Key Rules

- **LLDAP is identity SSoT.** Never store email in WordPress tables. Always use cross-DB JOINs via `AVPVH_LLDAP_DB`.
- **Authelia only guards `/wp-admin/`**. All other access control is handled by the plugin.
- **No inline scripts.** CSP blocks `wp_localize_script`. Pass JS config via `<script type="application/json">` in `wp_footer`.
- **No wp-login.php.** It redirects to `/avpvh-login/`. Don't add WP password forms.
- **Deploy:** `sudo rsync -a --delete ~/04-src/avpvh-members/ /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/`
  - ⚠️ Het pad is `wp-content-pvh`, **niet** `wp-content`. Een verkeerd pad deployt stilletjes naar de verkeerde site.

## Infrastructure

- MariaDB is in container `scripts-mysql-1`, accessible via `docker exec scripts-mysql-1 mariadb ...`
- WordPress PvH container: `scripts-wordpress-pvh-1`
- WordPress DB prefix: `pvh_`
- Content volume: `/opt/docker/volumes/html/wp-content-pvh/`
