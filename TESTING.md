# Testing

## Quick Checks

Run these after changes:

```bash
php -l includes/class-db.php
php -l includes/class-oauth.php
php -l includes/class-access.php
php -l includes/class-admin.php
php -l includes/class-member-profile-form.php
php -l includes/class-nav-auth.php
php -l admin/member-detail.php
```

Shell scripts:

```bash
bash -n scripts/backfill-member-identities.sh
bash -n scripts/test-user.sh
bash -n scripts/deploy.sh
bash -n scripts/release.sh
```

## Saved Ad-hoc Tests

```bash
php scripts/test-identity-helpers.php
php scripts/test-identity-limit.php
php scripts/test-role-labels.php
```

## What These Cover

- email normalization
- identity limit logic
- Workspace group label mapping

## Notes

- There is no PHPUnit suite in this repo.
- These tests are smoke checks, not full integration tests.
- Keep ad-hoc scripts in the repo so they can be rerun later.
