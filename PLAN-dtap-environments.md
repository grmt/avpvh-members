# Plan: DTAP Environments for AVP-PvH

## Goal

Set up separate environments for AVP-PvH so changes can be tested safely before production.

## Environments

### D - Development

- Local feature branches
- Fast iteration
- Can include ad-hoc tests and incomplete changes
- No production data unless explicitly sanitized

### T - Test

- Separate full stack
- Own WordPress instance
- Own database
- Own Docker stack
- Used to validate feature branches before acceptance

### A - Acceptance

- Separate full stack
- Mirrors production as closely as possible
- Used for final verification before release

### P - Production

- Current live instance
- Must remain stable
- Only receives approved releases

## Deployment Principles

- Do not treat production as a test target
- Promote the same code through DTAP in order
- Keep environment-specific configuration outside the code where possible
- Use the same deployment mechanism per environment where practical

## Database Principles

- Each environment gets its own database
- Migrations must be idempotent
- Backfills should be rerunnable safely
- Never assume shared state between environments

## Data Handling

- Development data may be synthetic or minimal
- Test data should be representative but safe
- Acceptance data should be production-like where possible
- Production data must be handled carefully and backed up before schema changes

## Code Flow

1. Develop on a feature branch
2. Run syntax and ad-hoc tests locally
3. Deploy to test environment
4. Verify behavior
5. Deploy to acceptance environment
6. Verify release readiness
7. Promote to production

## Operational Notes

- Use environment-specific hostnames such as `test.avphilipsvanhorne.nl`
- Keep DNS, TLS, and proxy settings separate per environment
- Keep WordPress config, LLDAP, Authelia, and database secrets environment-specific

## Rollback

- Each environment should support rollback independently
- Production rollback must not depend on test or acceptance state
- Keep previous release artifacts available until the new release is proven stable

## Open Questions

- Which existing Docker scripts already create or clone a full tenant stack?
- Should test and acceptance share the same base image set but with separate volumes?
- Which parts of the stack are environment-specific today:
  - WordPress
  - MariaDB
  - Authelia
  - LLDAP
  - Nginx / proxy

## Recommendation

Implement `test` first as a full stack clone of production, then add `acceptance` once the deployment flow is stable.
