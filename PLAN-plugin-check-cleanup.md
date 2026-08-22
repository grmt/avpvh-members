# Plugin Check cleanup — remaining backlog

Started 2026-08-22 on `main` (fixed the highest-severity findings there,
before the branch-workflow rule in AGENTS.md was actually followed — see
commits `4bad90c`..`9889278`). All *further* work continues on this
branch, `fix/plugin-check-remaining-findings`, per AGENTS.md.

CI (`.github/workflows/plugin-check.yml`) runs report-only
(`continue-on-error: true`) — it won't block merges while this backlog is
worked through. Check `gh run list --repo grmt/avpvh-members` /
`gh api repos/grmt/avpvh-members/check-runs/<id>/annotations` for the
current live finding list; Plugin Check's own output is paginated/ordered
by file, so clearing one batch reveals the next — this list was captured
after the `member_select()` false-positive suppressions landed, so it's
the first previously-hidden layer, not necessarily the last.

## Already fixed (on `main`, commits `4bad90c`..`9889278`)

- Unsanitized `$_SERVER['HTTP_X_FORWARDED_FOR']`/`REMOTE_ADDR'` in
  `class-oauth.php` and `class-db.php`'s `log_attempt()`.
- 11 `wp_redirect()` → `wp_safe_redirect()` (internal targets only).
- `$provider` escaped in a `wp_die()` message.
- Login-config JSON via `wp_print_inline_script_tag()` instead of raw echo.
- `date()` → `current_time()` in `class-fee-popup.php` (×3),
  `class-ledenlijst.php`, `class-admin.php`, `class-db.php`'s
  `get_members()` fee-year default.
- `get_login_attempts()`'s `LIMIT $limit` → `$wpdb->prepare(..., %d)`.
- `direct_db_queries` check excluded entirely (architectural — see the
  workflow file's own comment).
- 7 `member_select()`/`$lldap`/dynamic-`$sql` false positives in
  `class-db.php` suppressed with verified-safe `phpcs:ignore`/
  `phpcs:disable`+`phpcs:enable` (had to fix placement once already —
  PHPCS only honors an inline ignore on the *exact* flagged line or the
  line immediately before it, not a few lines up through a wrapping
  function call).

## Next batch (found via the CI run after the above landed)

- [ ] `admin/members-list.php:19` — `date()`
- [ ] `admin/class-members-list-table.php:23` — `date()`
- [ ] `admin/class-members-list-table.php:66-69` — `$_GET['s']`,
      `$_GET['f_first_name']`, `$_GET['f_suffix']` not unslashed/
      sanitized before use (admin list-table search/filter fields). The
      accompanying "Processing form data without nonce verification"
      warnings are likely not worth acting on — WP core's own list
      tables don't nonce their search/filter GET params either, since
      it's a read-only display filter, not a state change.
- [ ] `includes/class-xlsx-writer.php:69` — `unlink()` →
      `wp_delete_file()`
- [ ] `includes/class-activity-participation-export.php:49` — `date()`
- [ ] `includes/class-media-protection.php:105` — `rename()` →
      `WP_Filesystem::move()`
- [ ] `includes/class-media-protection.php:121` — unprepared
      interpolated `{$att_id}` in
      `"SELECT guid FROM {$wpdb->posts} WHERE ID={$att_id}"` — check
      whether `$att_id` is cast to int before this point; if not, that's
      a real fix (cast or `$wpdb->prepare()` with `%d`), not a false
      positive like the `member_select()` cases.
- [ ] `includes/class-media-protection.php:20` — "Processing form data
      without nonce verification" (×2) — check what's actually being
      read here before deciding real-fix vs. not-applicable.
- [ ] `README.md` — Plugin Check wants `readme.txt` headers ("Tested up
      to", "License", "Stable Tag"). Not applicable — this plugin has no
      `readme.txt` and was never meant for wordpress.org distribution.
      Either add `exclude-checks: readme_*`-style codes to the workflow
      (mirroring the `direct_db_queries` exclusion, with the same kind
      of documented reasoning) or just leave it, since these are
      warnings-not-blocking under `continue-on-error`.

## Not planned to fix (verified false positives / deliberate)

- One `wp_redirect()` in `class-oauth.php`'s `start()` — must stay
  external (Google/Microsoft's own authorize endpoint); `wp_safe_redirect`
  would block it.
- `$naam`/`$vcard_attrs` in `class-ledenlijst.php` — already built from
  `esc_html()`/`esc_url()`/`esc_attr()` pieces; re-escaping would corrupt
  the markup.
- Two raw `.xlsx` binary export echoes (`class-ledenlijst.php`,
  `class-admin.php`) — escaping would corrupt the file.
- One `error_log()` in `class-oauth.php` — deliberate diagnostic logging
  added this session, the only failure signal for a non-fatal identity
  link error.
- `direct_db_queries` check, plugin-wide — this plugin's whole DB layer
  is custom `$wpdb` queries joining WordPress and LLDAP tables, not
  something WP's post/option-oriented object-cache APIs are built
  around, and there's no persistent object cache backend wired to
  WordPress on this site anyway.

## Reminders for whoever picks this up

- **Never commit to `main` directly** — see AGENTS.md and
  `fix/plugin-check-remaining-findings` (this branch). Push here, stop
  for review/merge.
- Every commit that touches `avpvh-members.php` should bump the
  `Version:` header (patch bump + current short HEAD hash), matching
  every commit in this session's history.
- Deploy via targeted `rsync -e ssh <file> grmt@avpvh.nl:/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/<path>/`,
  verify with a `diff` against the server copy, and smoke-test any
  touched query/handler via `wp eval` on the `wpcli-pvh` compose service
  before considering a fix done.
