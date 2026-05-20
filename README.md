# AVP-PvH Members Plugin

WordPress plugin for AVP Philips van Horne to manage members, camp participation, and fees, integrated with LLDAP and Authelia.

## Features
- **SSO Integration:** Auto-login via Authelia/Nginx proxy headers (`HTTP_REMOTE_USER`).
- **Identity Management:** Uses LLDAP as the single source of truth for identity (emails, user IDs).
- **Business Data:** Manages member status, address history, excavation camp participation, and fee tracking.
- **Access Control:** Automatically bypasses post passwords for active members; shows notices to ex-members.
- **Fee Popup:** Notifies members on login if current year's fees are pending.
- **Admin UI:** Comprehensive member list and detail views in the WordPress backend.

## Architecture
This plugin is part of a larger infrastructure:
- **LLDAP:** Stores user accounts in a MariaDB database (`lldap`).
- **Authelia:** Handles authentication and session management.
- **Nginx (OpenResty):** Acts as a reverse proxy, triggering Authelia auth subrequests.
- **Cross-DB JOINs:** The plugin joins the WordPress database with the LLDAP database for high-performance identity lookups.

## Implementation Details
See [GEMINI.md](./GEMINI.md) for architectural mandates and coding standards.

## Setup
1. Configure LLDAP connection in **Settings > AVP-PvH Instellingen**.
2. Ensure the WordPress database user has `SELECT` privileges on the `lldap` database.
3. Import members using `scripts/import-avpvh-members.py`.
