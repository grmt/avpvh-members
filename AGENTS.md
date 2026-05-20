# AI Agent Guidance - AVP-PvH Member System

Welcome, Agent. This repository is a complex integration between WordPress, LLDAP, Authelia, and an OpenResty-based Docker stack. To ensure system stability and architectural consistency, you MUST read and follow the guidance in these documents:

## 🧭 Steering Documents

1. **[GEMINI.md](./GEMINI.md) (Mandatory)**
   - **Purpose:** Core architectural mandates and technical constraints.
   - **Key takeaway:** LLDAP is the Single Source of Truth for identity. NEVER duplicate emails in WordPress tables. Use cross-database JOINs.

2. **[README.md](./README.md)**
   - **Purpose:** High-level system overview and features.
   - **Key takeaway:** Understand the "Big Picture" of how Authelia, Nginx, and the Plugin interact.

3. **[PLAN.md](./PLAN.md)**
   - **Purpose:** The original design specification and roadmap.
   - **Key takeaway:** Reference this for the "Why" behind the current implementation and to check the status of specific deliverables.

## 🛠 Infrastructure Context

This project is part of a larger multi-tenant stack located at `/opt/docker`.
- **Nginx/OpenResty:** Handles the vhost and Authelia subrequests.
- **Authelia:** The Identity Broker.
- **LLDAP:** The User Directory (MariaDB backend).
- **WordPress Plugin:** Trust proxy headers (`HTTP_REMOTE_USER`) and manages business logic (fees, camps).

## 🧠 Memory & Local Context

- **Private Memory:** Local notes, machine-specific paths, and transient findings are stored in the private memory folder. Do not commit these to the repository.
- **Search First:** Before modifying any SQL or authentication logic, use `grep_search` to find existing patterns in `includes/class-db.php` and `includes/class-access.php`.

## ⚠️ Critical Warning

Do not attempt to "simplify" the database schema by adding identity columns to the WordPress tables. The cross-database join pattern is an intentional design choice to prevent data desynchronization between LLDAP and WordPress.
