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
php -l admin/email-log.php
php -l includes/class-email-log.php
```

Shell scripts:

```bash
bash -n scripts/manage-directory-group.sh
bash -n scripts/deploy.sh
bash -n scripts/release.sh
bash -n scripts/promote-scrubbed-main.sh
```

## PHPUnit and coverage

CI runs every `scripts/test-*.php` through PHPUnit (`grmt/wp-plugin-ci`
`quality.yml`), on PHP 8.4 and 8.5, with coverage on 8.4 for SonarQube Cloud.
Each script runs as its own PHP process through `tests/scripts/<name>.phpt`;
a run fails when its output has `[FAIL]`, `FAIL`, a PHP error, warning,
notice or deprecation. A new script needs its `.phpt`:

```bash
bin/make-script-tests        # writes the missing tests/scripts/*.phpt
```

`tests/ScriptTestsTest.php` fails while one is missing. SonarQube Cloud's
quality gate asks for 80% coverage of new code, so new or changed code in
`includes/` and `admin/` needs a script that runs it.

Locally (needs PHP 8.4+ with dom, xml and mbstring, and pcov for coverage),
for example in a container:

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --coverage-text   # with pcov or xdebug
```

The scripts also still run on their own: `php scripts/test-<name>.php`.

## Python import scripts

`tests/python/` holds pytest tests for the Python scripts in `scripts/`
(currently `import-avpvh-camps.py`). They generate their workbooks in the
test, use fictitious names and need no database. CI runs them in the
`quality.yml` pytest job and passes the coverage of `scripts/` to SonarQube
Cloud, so new Python code also needs 80% coverage. Locally:

```bash
python3 -m venv .venv && .venv/bin/pip install -r scripts/requirements-test.txt pytest pytest-cov
.venv/bin/python -m pytest --cov=scripts --cov-report=term-missing tests/python
```

## Saved Ad-hoc Tests

```bash
php scripts/test-identity-helpers.php
php scripts/test-identity-limit.php
php scripts/test-admin-email-return.php
php scripts/test-email-log.php
php scripts/test-email-identity-flow.php
php scripts/test-participation-form-flow.php
php scripts/test-profile-submission-flow.php
php scripts/test-member-account-flow.php
php scripts/test-tenant-invalid-config.php
php scripts/test-directory-consent.php
php scripts/test-newsletter-consent.php
php scripts/test-ledenlijst-export.php
php scripts/test-participation-export.php
php scripts/test-xlsx-writer.php
php scripts/test-profile-form-render.php
php scripts/test-ledenlijst-render.php
php scripts/test-db-upgrade-steps.php
php scripts/test-member-merge-execute.php
php scripts/test-member-status.php
php scripts/test-member-status-direct.php
php scripts/test-member-detail-groups.php
php scripts/test-role-labels.php
php scripts/test-address-normalizer.php
php scripts/test-name-matcher-loose.php
php scripts/test-proxy-session-identity.php
php scripts/test-blogger-capabilities.php
php scripts/test-page-permissions.php
```

## Visitor deletion (integration, needs Docker)

`scripts/test-visitor-delete.php` runs against a disposable MariaDB and
WordPress' real `class-wpdb.php`; without `AVPVH_VISITOR_TEST_DB` it skips.

```bash
docker network create vdel-net
docker run -d --rm --name vdel-db --network vdel-net -e MARIADB_ROOT_PASSWORD=fixture \
  -e MARIADB_DATABASE=visitor_fixtures mariadb:11.4
docker run --rm --network vdel-net -v "$PWD":/repo:ro --entrypoint php \
  -e AVPVH_VISITOR_TEST_DB=visitor_fixtures -e AVPVH_VISITOR_TEST_PASSWORD=fixture \
  -e AVPVH_VISITOR_TEST_HOST=vdel-db \
  -e AVPVH_VISITOR_TEST_WPDB=/usr/src/wordpress/wp-includes/class-wpdb.php \
  wordpress:php8.4-fpm-alpine /repo/scripts/test-visitor-delete.php
docker stop vdel-db
```

Any image with PHP 8.4, mysqli and WordPress under `/usr/src/wordpress`
works. To check that a refactor keeps every deletion plan the same, run it
once with `AVPVH_VISITOR_TEST_CLASS=<old copy of the class>` and once
without, each with `AVPVH_VISITOR_TEST_DUMP=<file>`, and compare the files.

## What These Cover

- email normalization
- identity limit logic
- Workspace group label mapping
- directory and newsletter consent handlers: nonce/session checks, own and
  household targets, delegation limited to confirmed minors under 16, audited
  sharing choices, subscription changes and redirects
- real XLSX exports: all combinations of directory sharing flags, placeholder
  email suppression, attendance dates/status colors, empty activities and
  literal formula-looking notes

## Notes

- PHPUnit runs the scripts through their generated wrappers. New scripts need
  `bin/make-script-tests` before running the suite.
- The workflow scripts call the production handlers with fictional records and
  in-memory WordPress, database and directory boundaries. They check submitted
  data, rejected requests and resulting writes without contacting live services.
- The XLSX writer and export tests open generated archives and validate their
  XML when `ext-zip` is available; those archive checks skip otherwise. Use PHP
  with `ext-zip` and PCOV/Xdebug to measure the full workbook-generation path.
- Consent tests invoke the production handlers; their shared WordPress/database
  stand-ins live in `tests/fixtures/consent.php`. They record writes and intercept
  redirects/errors, so rejected requests can be checked for side effects.
- These tests complement the integration tests, which require their documented
  disposable services.
- Keep ad-hoc scripts in the repo so they can be rerun later.
