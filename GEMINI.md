# AVP-PvH Member System - Architectural Mandates

This file contains foundational mandates for the AVP-PvH Member system. All developers (human or AI) MUST adhere to these rules to maintain system integrity.

## 1. Identity & Data Ownership

- **OpenLDAP is the Single Source of Truth (SSoT) for Identity:** User IDs, emails, display names, passwords, and group memberships reside in OpenLDAP (`ou=avpvh,dc=nl`).
- **WordPress is the SSoT for Business Data:** Membership status, address history, camp participation, and fees reside in `pvh_avm_*` tables.
- **Directory Cache:** OpenLDAP data is mirrored into the read-only MariaDB cache table `pvh_avm_directory_users` by `AVPVH_Directory_Cache`. The plugin automatically updates the cache on writes and via periodic synchronization.

## 2. Database Integration

- **Read Queries:** Member identity queries JOIN against the local cache table `pvh_avm_directory_users`.
- **SQL Pattern:**
  ```php
  $cache = $wpdb->prefix . 'avm_directory_users';
  $sql = "SELECT du.mail AS email, m.status FROM {$cache} du JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = du.uid ...";
  ```
- **Directory Writes:** All directory modifications (user creation, profile updates, group changes) must go through the `AVPVH_Directory` class. Never write directly to directory tables.

## 3. Authentication & Access Control

- **Primary login:** OAuth2 via Google or Microsoft (`class-oauth.php`). Email from provider is matched against `pvh_avm_directory_users.lowercase_mail`.
- **Password login:** Authelia username+password via 1FA SSO callback (`/avpvh-sso/`) → sets `HTTP_REMOTE_USER` header → `class-access.php` auto-login.
- **Step-Up 2FA:** Authelia only enforces `two_factor` for `/wp-admin/**`. Normal members only need 1FA (password or OAuth) to access member pages and photos. When accessing administrative pages (`/wp-admin/`), Authelia prompts for 2FA.
- **No WP login forms:** `wp-login.php` redirects to `/avpvh-login/`.
- **CSP:** Never use `wp_localize_script` for frontend config. Use `<script type="application/json">` in `wp_footer` instead.

## 4. Coding Standards

- **PHP:** Strict typing, PSR-12 inspired, prefix all classes/functions with `AVPVH_`.
- **UI:** WordPress admin styles for backend. Vanilla CSS for frontend.
- **Scripts:** Python import scripts must be idempotent.

## 5. Integration Points

- **Directory API:** Use `AVPVH_Directory` class for all identity and group operations.
- **Status Sync:** Changes to `pvh_avm_members.status` trigger corresponding group update in OpenLDAP (`leden` / `ex-leden`).

## 6. Infrastructure Reference

- **Docker Compose:** `/opt/docker/scripts/docker-compose.yml`
- **Authelia Config:** `/opt/docker/volumes/authelia/config/configuration.yml` (source: `config/authelia-configuration.local.yml`)
- **WordPress content:** `/opt/docker/volumes/html/wp-content-pvh/`
- **Deploy:** `sudo rsync -a --delete ~/03-src/avpvh-members/ /opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/`
