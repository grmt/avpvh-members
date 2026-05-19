# Plan: AVP-PvH Member Login & Database

## Context

The AVP Philips van Horne WordPress site (pvh tenant, `www.avphilipsvanhorne.nl`) has 50+ blog posts all password-protected with the single password "Voegenteller". The club wants members to log in with personal accounts and see content without needing the password. Former members can log in but see a notice and content stays locked. Members who haven't paid the current year's membership dues see a popup on login. Member data, address history, excavation camp participation (2010–2026), and fee tracking all live in custom DB tables created from existing XLS files.

---

## Deliverables

1. **Custom WordPress plugin** — `avpvh-members` (pvh-tenant only)
2. **Python import scripts** — members from XLS, camp participation from excavation XLS files
3. **Fee tracking schema** — designed to hold data back to 1975, initially populated from XLS/manual entry

---

## Database Schema

All tables use the `pvh_` WordPress table prefix so they live inside the shared `wpdb` database alongside all other pvh tables.

**`pvh_avm_members`**
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AUTO | |
| wp_user_id | INT NULL | FK to pvh_users.ID; NULL for ex-leden not yet provisioned |
| last_name | VARCHAR(100) | |
| first_name | VARCHAR(100) | |
| birth_date | DATE NULL | |
| email | VARCHAR(150) | |
| phone | VARCHAR(30) | |
| mobile | VARCHAR(30) | |
| emergency_contact | VARCHAR(200) | |
| status | ENUM('active','inactive','visitor') | active = current member |
| joined_year | YEAR NULL | |
| left_year | YEAR NULL | |
| created_at / updated_at | TIMESTAMP | |

**`pvh_avm_addresses`** (history)
| Column | Type |
|--------|------|
| id | INT PK |
| member_id | INT FK → pvh_avm_members |
| street / house_number / postal_code / city / country | VARCHAR |
| valid_from | DATE NULL |
| valid_until | DATE NULL — NULL = current address |

**`pvh_avm_camps`**
| Column | Type |
|--------|------|
| id | INT PK |
| name | VARCHAR(150) — e.g. "Goeblange VII" |
| year | YEAR |
| location | VARCHAR(150) — e.g. "Goeblange, Luxemburg" |

**`pvh_avm_camp_participation`**
| Column | Type |
|--------|------|
| id | INT PK |
| member_id | INT FK |
| camp_id | INT FK |
| nights | TINYINT NULL |
| nawacht | TINYINT DEFAULT 0 |
| diet | VARCHAR(50) NULL |
| notes | TEXT NULL |

**`pvh_avm_fees`** (supports years back to 1975)
| Column | Type |
|--------|------|
| id | INT PK |
| member_id | INT FK |
| year | SMALLINT (supports 1975+) |
| amount_due | DECIMAL(8,2) NULL |
| amount_paid | DECIMAL(8,2) NULL |
| paid_date | DATE NULL |
| status | ENUM('paid','pending','waived') |

---

## Plugin Structure

`/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/`

```
avpvh-members.php          main file: register activation hook, load includes
includes/
  class-db.php             dbDelta table creation, helper query methods
  class-access.php         password_required filter + ex-member notice
  class-fee-popup.php      wp_login hook, wp_footer modal output
  class-admin.php          register admin menu pages
admin/
  members-list.php         searchable table: name, status, last payment year, camps
  member-detail.php        address history, camp list, fee history per member
assets/
  fee-popup.js             simple modal, dismiss sets cookie for 7 days
  fee-popup.css
```

### Access control logic (`class-access.php`)

```php
// Active members: bypass password on all posts
add_filter('password_required', function($required, $post) {
    if (!is_user_logged_in()) return $required;
    $member = avpvh_get_member_by_wp_user(get_current_user_id());
    if ($member && $member->status === 'active') return false;
    return $required;
}, 10, 2);

// Ex-members: show notice in content (password form still shows above it)
add_filter('the_content', function($content) {
    if (!is_user_logged_in()) return $content;
    $member = avpvh_get_member_by_wp_user(get_current_user_id());
    if ($member && $member->status === 'inactive') {
        return '<div class="avpvh-notice">Uw lidmaatschap is beëindigd. Neem contact op met het bestuur.</div>';
    }
    return $content;
});
```

### Fee popup logic (`class-fee-popup.php`)

- On `wp_login`: query `pvh_avm_fees` for current year + member. If no `paid` row → set user meta `_avpvh_show_fee_popup` = current year.
- On `wp_footer`: if user is logged in and meta matches current year and no dismiss cookie → output modal HTML.
- Modal dismiss button sets a cookie (`avpvh_fee_dismissed`) valid 7 days and clears the meta via AJAX.
- Meta is cleared permanently when an admin marks the fee as paid in the admin UI.

---

## Import Scripts

### `scripts/import-avpvh-members.py`

- Reads `ledenlijst bijgewerkt mei 2026.xlsx.xlsx` (sheet "Leden" → active, sheet "ex-leden" → inactive)
- Connects to MariaDB on `localhost:6603` using password from `/opt/docker/secrets/compose/mysql_password.txt`
- For each active member:
  - Creates WP user via `docker compose exec wpcli-pvh wp user create ...` (subscriber role, random password)
  - Inserts into `pvh_avm_members` with `wp_user_id`
  - Inserts current address into `pvh_avm_addresses` (valid_until = NULL)
  - Inserts a `pvh_avm_fees` row for 2026 with status `pending` (admin will mark paid)
- For each ex-member (sheet "ex-leden"):
  - Creates WP user (subscriber role)
  - Inserts with `status = 'inactive'`
  - Inserts address
- Skips rows that already exist (idempotent on email)

### `scripts/import-avpvh-camps.py`

- Iterates over excavation XLS files in `/home/grmt/avpvh_drive/xls/Opgravingen/` and the 2026 Goeblange folder
- For each file: extracts year and location from directory/filename, creates `pvh_avm_camps` row
- Reads "totaal inschrijvingen" sheet: matches participant names to `pvh_avm_members` by last_name + first_name (fuzzy if needed)
- Inserts `pvh_avm_camp_participation` rows (nights, nawacht, diet from columns)
- Unmatched names logged to stdout for manual review

---

## Login Flow

Standard `wp-login.php` is not exposed to members. Instead:

1. A custom WordPress page (slug `/inloggen/`) renders a single **email field** form.
2. On submit the plugin checks the submitted email against `pvh_avm_members.email`.
   - **Unknown email** → "Dit e-mailadres is niet bij ons bekend."
   - **Known member** → trigger `retrieve_password()` to send WP's standard password-reset link to that address. Message: "Er is een inloglink naar uw e-mailadres gestuurd."
3. Member clicks the link in the email → lands on the WP set-password page → sets their own password.
4. On subsequent logins: same email form; if a WP password is already set the form also shows a password field. Alternatively they can always request a fresh link.
5. `lost_password` / password-reset is blocked for any email not in `pvh_avm_members` (via `allow_password_reset` filter).

**Import change**: WP users are created with a random unusable password (`wp_generate_password(64)`). Members never know this password — they only ever authenticate via the emailed link.

### New hooks in `class-access.php`

```php
// Block password reset for non-members
add_filter('allow_password_reset', function($allow, $user_id) {
    return avpvh_get_member_by_wp_user($user_id) !== null;
}, 10, 2);

// Redirect wp-login.php to custom page for non-admins
add_action('login_init', function() {
    if (current_user_can('manage_options')) return;
    wp_redirect(home_url('/inloggen/'));
    exit;
});
```

### New file: `templates/login-page.php`
Page template used by the `/inloggen/` page. Renders the email (+ optional password) form and handles AJAX or POST submission.

---

## Admin UI

WordPress admin menu "AVP-PvH Leden" with two pages:

1. **Member list** — search by name/status/year, shows last paid year, camp count. Links to detail.
2. **Member detail** — tabs: contact info + address history | camps participated | fee history. Fee rows have "mark as paid" button (updates `pvh_avm_fees`, clears popup meta).

---

## File Paths to Create/Modify

| Path | Action |
|------|--------|
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/avpvh-members.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/includes/class-db.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/includes/class-access.php` | Create (content access + login flow) |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/templates/login-page.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/includes/class-fee-popup.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/includes/class-admin.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/admin/members-list.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/admin/member-detail.php` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/assets/fee-popup.js` | Create |
| `/opt/docker/volumes/html/wp-content-pvh/plugins/avpvh-members/assets/fee-popup.css` | Create |
| `/opt/docker/scripts/import-avpvh-members.py` | Create |
| `/opt/docker/scripts/import-avpvh-camps.py` | Create |

---

## Verification

1. Activate plugin via WP-CLI: `wp plugin activate avpvh-members --allow-root ...`
2. Confirm tables created: `wp db query "SHOW TABLES LIKE 'pvh_avm_%'" ...`
3. Run `import-avpvh-members.py` — verify member count matches XLS (~94 active, ~24 inactive)
4. Run `import-avpvh-camps.py` — check matched/unmatched participant log
5. Log in as an active member → confirm password-protected post content is visible, no password form
6. Log in as an ex-member → confirm password form + notice visible, content NOT accessible
7. Set a 2026 fee row to `pending` for a test user → log in → confirm popup appears
8. Mark fee as paid in admin → log in again → confirm no popup
9. Admin: search member, view address history, view camp list, view fee history
