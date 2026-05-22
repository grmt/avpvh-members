# AVP-PvH Member System - Architectural Mandates

This file contains foundational mandates for the AVP-PvH Member system. All developers (human or AI) MUST adhere to these rules to maintain system integrity.

## 1. Identity & Data Ownership

- **LLDAP is the Single Source of Truth (SSoT) for Identity:** User IDs, emails, and display names reside in `lldap.users`.
- **WordPress is the SSoT for Business Data:** Membership status, address history, camp participation, and fees reside in `pvh_avm_*` tables.
- **NO DUPLICATION:** The `email` field must NEVER be duplicated in WordPress tables. Use cross-database JOINs.

## 2. Database Integration

- **Cross-DB JOINs:** Always use the `AVPVH_LLDAP_DB` constant (defaulting to `lldap`) when joining with identity data.
- **SQL Pattern:**
  ```php
  $lldap = AVPVH_LLDAP_DB;
  $sql = "SELECT u.email, m.status FROM {$lldap}.users u JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id ...";
  ```
- **Permissions:** MariaDB user `wp_user` requires `SELECT` grants on the `lldap` database.

## 3. Authentication & Access Control

- **Primary login:** OAuth2 via Google or Microsoft (`class-oauth.php`). Email from provider is matched against `lldap.users.lowercase_email`.
- **Fallback login:** Authelia username+password → sets `HTTP_REMOTE_USER` header → `class-access.php` auto-login.
- **Authelia scope:** Only guards `/wp-admin/**` (two_factor). All other pages bypassed — WordPress plugin handles access control.
- **No WP login forms:** `wp-login.php` redirects to `/avpvh-login/`.
- **CSP:** Never use `wp_localize_script` for frontend config. Use `<script type="application/json">` in `wp_footer` instead.

## 4. Coding Standards

- **PHP:** Strict typing, PSR-12 inspired, prefix all classes/functions with `AVPVH_`.
- **UI:** WordPress admin styles for backend. Vanilla CSS for frontend.
- **Scripts:** Python import scripts must be idempotent.

## 5. Integration Points

- **LLDAP GraphQL:** Use `AVPVH_LLDAP` class for all writes to LLDAP.
- **Status Sync:** Changes to `pvh_avm_members.status` SHOULD trigger corresponding group update in LLDAP (`leden` / `ex-leden`).

## 6. Infrastructure Reference

- **Docker Compose:** `/opt/docker/scripts/docker-compose.yml`
- **Authelia Config:** `/opt/docker/volumes/authelia/config/configuration.yml` (source: `config/authelia-configuration.yml`)
- **WordPress content:** `/opt/docker/volumes/html/wp-content-pvh/`
- **Deploy:** `sudo rsync -a --delete ~/04-src/avpvh-members/ /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/`
