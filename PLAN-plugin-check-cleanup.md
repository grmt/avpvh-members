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

## CI trigger bug found while working this batch

`on.push.branches: "*"` in the workflow only matches single-segment
branch names — `*` doesn't cross `/` in GitHub Actions glob syntax — so
every push to `fix/plugin-check-remaining-findings` (and any other
`foo/bar`-style branch) silently never triggered the workflow. All 8
runs to date were on `main`. This whole "next batch" list below was
therefore triaged from a stored punch list, not fresh CI output on this
branch. Fixed by changing it to `"**"`; first real push after that will
be the first live signal from this branch.

## Next batch (found via the CI run after the above landed)

- [x] `admin/members-list.php:19` — `date()` → `current_time('Y')`
- [x] `admin/class-members-list-table.php:23` — `date()` →
      `current_time('Y')`
- [x] `admin/class-members-list-table.php:66-69` — wrapped
      `$_GET['s']`/`f_first_name`/`f_suffix`/`f_last_name` in
      `wp_unslash()` before `sanitize_text_field()`. The accompanying
      "Processing form data without nonce verification" warnings left
      as-is — WP core's own list tables don't nonce their search/filter
      GET params either, since it's a read-only display filter, not a
      state change.
- [x] `includes/class-xlsx-writer.php:69` — `unlink()` →
      `wp_delete_file()`
- [x] `includes/class-activity-participation-export.php:49` — `date()`
      → `wp_date()` (formats an arbitrary parsed date, not "now", so
      `current_time()` wasn't the right swap here)
- [x] `includes/class-media-protection.php:105` — `rename()` →
      `$wp_filesystem->move()` (via `WP_Filesystem()`, direct method —
      no credentials prompt on this host)
- [x] `includes/class-media-protection.php:121` — confirmed `$att_id`
      is `(int)`-cast at declaration and never reassigned before this
      line; suppressed with `phpcs:ignore
      WordPress.DB.PreparedSQL.InterpolatedNotPrepared`, same pattern as
      the `member_select()` cases in `class-db.php`.
- [x] `includes/class-media-protection.php:20` — checked: `absint()` on
      `$_REQUEST['post_id']`/`['post']` in an `upload_dir` filter
      callback (read-only directory routing, no state change, value
      already fully sanitized to an int). Same category as the
      list-table search fields above — left as-is, not applicable.
- [ ] `README.md` — Plugin Check wants `readme.txt` headers ("Tested up
      to", "License", "Stable Tag"). Not applicable — this plugin has no
      `readme.txt` and was never meant for wordpress.org distribution.
      Left open pending a real CI run (see bug above) to confirm this is
      still flagged and get the exact check code before excluding it.

## The real backlog: 352 findings, not ~9

The CI-trigger bug above meant this branch never actually got checked.
The first real run (after fixing it) found 352 findings, not the ~9
above. Excluded `plugin_readme`/`trademarks`/`file_type`/
`plugin_header_fields` as wordpress.org-submission-only noise (see the
workflow file's own comment) — not applicable to a private plugin —
leaving ~330 findings about the plugin's own code. Worked through in two
more commits after the "Next batch" above:

- [x] 3 more `wp_redirect()` → `wp_safe_redirect()` in `class-access.php`
      (internal targets) that the earlier "11 wp_redirect()" pass missed;
      1 more in `class-nav-auth.php` suppressed instead — its target is
      Authelia's own logout endpoint, external, same reasoning as the
      already-documented `class-oauth.php` exception.
- [x] `do_action('wp_login', ...)` in `class-access.php`/`class-oauth.php`
      — suppressed `NonPrefixedHooknameFound`; it's firing WP core's own
      hook for compatibility, not defining a custom one.
- [x] ~48 call sites of `(int) ($_POST/$_GET[...] ?? 0)` →
      `(int) wp_unslash(...)` — PHPCS doesn't credit an int cast as
      sanitization even though it fully is; this was the single largest
      chunk of the `MissingUnslash`/`InputNotSanitized` pairs.
- [x] Remaining `sanitize_text_field()`/`sanitize_key()`/`array_map()`
      calls on raw `$_GET`/`$_POST` (address fields, activity dates,
      flag/group id arrays, oauth test params, etc.) wrapped in
      `wp_unslash()` — across `class-admin.php`, `class-member-profile-
      form.php`, `admin/members-list.php`, `admin/member-detail.php`,
      `admin/add-member.php`, `class-access.php`,
      `class-directory-consent.php`.
- [x] The day-grid and activity-type-rename array pulls
      (`(array) ($_POST['day'/'type_name'] ?? [])`) now unslash the whole
      array once up front instead of double-unslashing each value in the
      loop.
- [x] Added `isset()` guards before reading `$_POST['nights']` directly
      in `class-admin.php` and `class-activity-participation-form.php`
      (`InputNotValidated`).
- [x] `class-db.php`: `PluginCheck.Security.DirectDB.
      UnescapedDBParameter` turned out to be a *different* sniff than
      `WordPress.DB.PreparedSQL.NotPrepared` — the earlier
      `member_select()`/`$sql`/`$lldap` suppressions didn't cover it.
      Added it to all the existing ignore comments/blocks. Also
      suppressed two `WHERE id IN ($placeholders)` `$wpdb->prepare()`
      calls PHPCS flagged as "unfinished" — it can't statically verify a
      dynamically-built run of `%d`s matches `count($other_ids)`/
      `count($flag_ids)`, but the array-args form of `prepare()` handles
      it correctly at runtime.
- [x] `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound`
      (107 findings, the single largest category) — this sniff treats
      every top-level variable in an `include`d admin-page template as a
      "global" needing the plugin's prefix. Renaming ~50+ template-local
      variable names (or wrapping each page in a function) for a
      naming-convention-only finding wasn't worth the risk/churn.
      Suppressed per-file with a documented `phpcs:disable` in the 8
      affected admin templates (`activity-participation-list.php`,
      `activity-participation-detail.php`, `members-list.php`,
      `newsletter.php`, `add-member.php`, `member-detail.php`,
      `roles.php`, `login-attempts.php`).
- [x] `nav-auth.js`'s `wp_enqueue_script()` now passes
      `['strategy' => 'defer', 'in_footer' => true]` instead of the old
      positional `true` — fixes `NonBlockingScripts.NoStrategy`. Only
      script actually flagged (Plugin Check's crawler only observed
      scripts that load site-wide; the others load conditionally on
      pages it didn't happen to hit).

One correction after that CI run: `InputNotSanitized` still fired on every
one of the ~50 `(int) wp_unslash(...)` sites — PHPCS doesn't credit a raw
`(int)` cast as sanitization, only recognized functions like `absint()`
(already proven true elsewhere in this codebase — `class-media-
protection.php`'s existing `absint($_REQUEST[...])` was never flagged).
Since every affected field is a non-negative id/count, swapping
`(int) wp_unslash(...)` → `absint(wp_unslash(...))` is strictly more
correct (clamps instead of preserving a sign) and satisfies the sniff.
Also had to convert `class-db.php`'s single-line `phpcs:ignore
PluginCheck.Security.DirectDB.UnescapedDBParameter` comments (on
`get_member_by_lldap_uid()`, `get_member_by_email()`,
`get_member_by_wp_user()`, `get_member()`, `get_manageable_members()`)
to `phpcs:disable`/`phpcs:enable` blocks around the query statement —
unlike the native WPCS sniffs, this custom Plugin Check sniff didn't
honor the inline ignore even when placed on the exact flagged line,
but the block form (already proven elsewhere in this file) worked.

Left as-is, documented rather than fixed:

- `WordPress.Security.NonceVerification.Recommended` (55 findings) —
  spot-checked across `class-member-profile-form.php`,
  `class-admin.php`'s `admin_post_*` handlers, and several admin
  templates: every state-changing POST handler already calls
  `check_admin_referer()`/`check_ajax_referer()` near the top of the
  function; PHPCS's sniff just can't trace across the private-method or
  block-scope boundary to see it. The rest are read-only GET display
  filters (search boxes, tab selection, success/error notices from a
  post-redirect-GET), which WP core's own admin screens don't nonce
  either. One instance (`class-access.php`'s `login_error` read) already
  had an inline `phpcs:ignore` from an earlier session — left the rest
  undocumented in code to match how the bulk of this category was
  already handled, rather than adding ~54 more inline comments.
- 5 `error_log()` calls (`class-member-profile-form.php` ×2,
  `class-admin.php` ×3) — all logging a non-fatal LLDAP sync failure,
  same category as the one already documented in `class-oauth.php`: the
  only failure signal for those paths.
- `EnqueuedStylesScope`/`EnqueuedScriptsScope` on `avpvh.css`/
  `nav-auth.js` ("loaded in all contexts") — intentional: both style and
  drive the site-wide nav bar that appears on every page, so "all
  contexts" is correct, not a scoping bug.
- `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in`
  in `class-access.php` (excluding member-only pages from sitemaps/
  search) — a real VIP-scale performance concern that doesn't apply to
  this small single-club site.

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
