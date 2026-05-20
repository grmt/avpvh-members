# AVP-PvH Member System - Architectural Mandates

This file contains foundational mandates for the AVP-PvH Member system. All developers (human or AI) MUST adhere to these rules to maintain system integrity.

## 1. Identity & Data Ownership
- **LLDAP is the Single Source of Truth (SSoT) for Identity:** User IDs, emails, and display names reside in the `lldap.users` table.
- **WordPress is the SSoT for Business Data:** Membership status, address history, camp participation, and fees reside in the `pvh_avm_*` tables in the WordPress database (`wpdb`).
- **NO DUPLICATION:** The `email` field must NEVER be duplicated in WordPress tables. Use cross-database JOINs to retrieve identity info.

## 2. Database Integration
- **Cross-DB JOINs:** Always use the `AVPVH_LLDAP_DB` constant (defaulting to `lldap`) when joining with identity data.
- **SQL Pattern:**
  ```php
  $lldap = AVPVH_LLDAP_DB;
  $sql = "SELECT u.email, m.status FROM {$lldap}.users u JOIN {$wpdb->prefix}avm_members m ON m.lldap_user_id = u.user_id ...";
  ```
- **Permissions:** The MariaDB user `wp_user` requires `SELECT` grants on the `lldap` database.

## 3. Authentication & Access Control (Infrastructure)
- **Identity Broker:** Authelia (`auth.avphilipsvanhorne.nl`) handles all authentication against LLDAP.
- **Reverse Proxy:** OpenResty (Nginx) manages the vhost for `avphilipsvanhorne.nl`.
- **Auth Header:** Nginx passes the authenticated user's email via `fastcgi_param HTTP_REMOTE_USER $user;`.
- **Plugin Trust:** `AVPVH_Access` MUST trust `$_SERVER['HTTP_REMOTE_USER']`.
- **No WP Login Forms:** The plugin does not implement login or password management.

## 4. Coding Standards
- **PHP:** Strict typing, PSR-12 inspired, prefix all classes/functions with `AVPVH_`.
- **UI:** Use WordPress admin styles for backend management. Frontend popups use Vanilla CSS.
- **Scripts:** Python import scripts must remain idempotent and handle "family-shared emails" by logging skips.

## 5. Integration Points
- **LLDAP GraphQL:** Use `AVPVH_LLDAP` class for all writes to LLDAP (creation, group updates).
- **Status Sync:** Any change to `pvh_avm_members.status` (active/inactive) MUST trigger a corresponding group update in LLDAP (`leden` vs `ex-leden`).

## 6. Infrastructure Reference
- **Docker Compose:** `/opt/docker/scripts/docker-compose.yml` (service: `wordpress-pvh`)
- **Authelia Config:** `/opt/docker/volumes/authelia/config/configuration.yml`
- **Nginx Config:** `/opt/docker/volumes/openresty/nginx.conf`
