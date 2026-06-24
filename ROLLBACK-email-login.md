# Rollback Plan: Email Login Identities

## Scope

This rollback plan covers the changes on branch `email-login-identities`:

- new identity table for linked login e-mails
- OAuth identity matching
- Authelia proxy-login identity sync
- admin profile editing changes
- self-service profile changes
- backfill and test helper scripts

## Recommended Rollback Order

### 1. Stop new writes first

- Deploy the previous plugin version or revert the feature commit
- Do not run the backfill script again
- Stop using the admin identity management UI

### 2. Preserve current data

- Export `pvh_avm_member_identities` before deleting anything
- Export any rows created or modified by:
  - `scripts/backfill-member-identities.sh`
  - admin identity actions
  - proxy-login identity sync

### 3. Remove the code change

Preferred:

- `git revert 24c5de1`

Alternative, only if history rewrites are acceptable:

- `git reset --hard d29afd0`

### 4. Roll back the database schema

Only if the new table is no longer needed:

- Drop `pvh_avm_member_identities`
- Keep in mind that this will delete linked Google/Microsoft/email identities

### 5. Roll back imported/backfilled data

- If you need to preserve the old state exactly, restore the exported identity rows into the previous schema
- If no restore is needed, leave the old identity data out after the schema rollback

## Safety Notes

- Do not drop the identity table before exporting it if the table has meaningful data
- If members already use Google/Microsoft logins via the new identity table, removing it will break those logins
- If the backfill already ran, verify whether the table contains only primary e-mail identities or also manual provider links
- If you only want to disable the feature temporarily, code rollback is safer than schema rollback

## Practical Scenarios

### A. Code-only rollback

Use when:

- the code changes cause issues
- you want to keep the new identity data for later

Action:

- revert or reset the commit
- leave the database table intact

### B. Code + schema rollback

Use when:

- the feature must be fully removed
- the new identity data is not needed

Action:

- revert the code
- export the identity table if needed
- drop `pvh_avm_member_identities`

### C. Full rollback including backfill

Use when:

- you need to restore the exact previous behavior and data state

Action:

- revert the code
- export the identity table
- remove the table
- restore any affected data to the previous schema if necessary

## Pre-Rollback Checklist

- Confirm whether any production members have already logged in through the new identity table
- Confirm whether the backfill script has been run
- Confirm whether admins have manually added Google/Microsoft identities
- Confirm whether you need the current identity data for later re-import

## Post-Rollback Checklist

- Verify old login flow still works
- Verify admin pages load
- Verify no code path still references `pvh_avm_member_identities`
- Verify there are no failed queries in the logs

## Operational Commands

### 1. Revert the code commit

Use this if you want a clean history-preserving rollback:

```bash
git revert 24c5de1
```

Use this only if you really want to rewrite branch history:

```bash
git reset --hard d29afd0
```

### 2. Back up the identity table

Before dropping or changing schema:

```bash
docker exec scripts-mysql-1 mariadb -u root -p'$(cat /run/secrets/mysql_root_password)' wpdb \
  -e "SELECT * FROM pvh_avm_member_identities;" > /tmp/pvh_avm_member_identities.sql
```

If shell substitution is not convenient, run the export from inside the container or use a root shell on the host.

### 3. Drop the identity table

Only if you are sure the feature is being removed:

```bash
docker exec scripts-mysql-1 mariadb -u root -p'$(cat /run/secrets/mysql_root_password)' wpdb \
  -e "DROP TABLE pvh_avm_member_identities;"
```

### 4. Restore login behavior

After reverting code and/or schema, verify:

- Google login
- Microsoft login
- Authelia proxy login
- member profile editing
- admin member detail pages

### 5. Re-run backfill only if needed

If you keep the feature and only want to repopulate the table:

```bash
./scripts/backfill-member-identities.sh
```

### 6. Sanity checks

```bash
git status --short
php -l includes/class-db.php
php -l includes/class-oauth.php
php -l includes/class-access.php
php -l includes/class-admin.php
php -l includes/class-member-profile-form.php
php -l admin/member-detail.php
```

## Notes

- The `mariadb -e` examples above are intentionally conservative; if your shell does not expand `$(...)` inside single quotes, use a temporary variable instead.
- For production, prefer dumping the table to a file before dropping it.
- If the identity table has already been used in production, treat the backup as mandatory.
