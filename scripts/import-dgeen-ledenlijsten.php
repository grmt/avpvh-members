<?php
/**
 * Import DGéén ledenlijst snapshots (from the `scan` repo's already-parsed
 * avpvh/02-DGéén/transcripties/*/page_XX.md tables) into avm_members /
 * avm_addresses, treating each publication date as an address-validity
 * boundary.
 *
 * Run via wp eval-file (NOT as a standalone python/pymysql script like the
 * sibling reconcile-members.py/import-avpvh-members.py): leden-admin's
 * LLDAP API now sits behind Authelia two_factor, so a bare Python
 * `_avpvh_import_common.py`-style `lldap_login()` against
 * https://leden-admin.avphilipsvanhorne.nl can no longer reach it
 * unattended. This script instead uses AVPVH_LLDAP/AVPVH_DB/$wpdb from
 * inside WordPress, which talk to LLDAP over the internal Docker network
 * (http://lldap:17170) — no Authelia in the way, same pattern already used
 * for one-off member creation via wp eval-file.
 *
 * Usage:
 *   DGEEN_DRY_RUN=1 docker compose -f docker-compose.yml run --rm -T \
 *     --no-deps -e DGEEN_DRY_RUN=1 wpcli-pvh wp eval-file \
 *     /var/www/html/wp-content-pvh/plugins/avpvh-members/scripts/import-dgeen-ledenlijsten.php
 *   (drop -e DGEEN_DRY_RUN=1, or set it to 0, for a live/committed run)
 *
 * Input JSON is produced by a local (uncommitted, one-off) extraction
 * script that parses the GFM tables in the transcripties/*.md files —
 * see avpvh/02-DGéén/LEDENLIJST-IMPORT-STATUS.md in the `scan` repo for
 * which editions have been run through this and what got fixed in the
 * source .md tables to make them parseable (missing commas, ";" instead
 * of ",", tussenvoegsel-order variants, etc.).
 *
 * Matching: normalize_name_key(voornaam, achternaam), exact only — same
 * philosophy as reconcile-members.py (a false-positive merge of two
 * different people is worse than a false negative that needs a human to
 * check). Non-matches get created as new status='inactive' members, NOT
 * added to the "leden" LLDAP group (historical/former members/contacts,
 * not current members).
 *
 * Per matched (or newly created) person, across all snapshots in date
 * order: consecutive identical addresses are coalesced into a single
 * avm_addresses row (valid_from = first snapshot date with that address,
 * valid_until = day before the next snapshot date with a DIFFERENT
 * address, or NULL if it's the address in the person's last-known
 * snapshot).
 *
 * birth_date / phone are one-time backfills onto avm_members (only if
 * currently empty) — avm_addresses has no phone/birthdate history columns,
 * so older values are never written, only used to fill a gap or compared
 * against the current value for a conflict report (never silently
 * overwritten on a mismatch).
 *
 * Rows flagged needs_review by the extraction script (missing comma in the
 * "Naam" cell, or a non-person entry like "sg Philips van Horne" / "Fam.
 * Volkaert") are excluded from matching/creation entirely and only listed
 * in the report — never guessed at.
 */

global $wpdb;

$dry_run = getenv('DGEEN_DRY_RUN') !== '0' && getenv('DGEEN_DRY_RUN') !== false
    ? getenv('DGEEN_DRY_RUN') === '1'
    : true;
echo $dry_run ? "=== DRY RUN ===\n\n" : "=== LIVE RUN ===\n\n";

$json_path = '/var/www/html/wp-content-pvh/plugins/avpvh-members/scripts/_dgeen_ledenlijsten.json';
$editions = json_decode(file_get_contents($json_path), true);

// --- normalize_name_key equivalent (mirrors _avpvh_import_common.py) ---
$TUSSENVOEGSEL_PREFIXES = ['van der ', 'van den ', 'van de ', 'ten ', 'ter ',
    'de ', 'van ', 'te ', 'von ', 'la ', 'le ', 'du '];

function normalize_name_key(string $first, string $last): string {
    global $TUSSENVOEGSEL_PREFIXES;
    $last = trim($last);
    if (str_contains($last, ',')) {
        $core = explode(',', $last, 2)[0];
    } else {
        $lowered = strtolower($last);
        $core = $last;
        foreach ($TUSSENVOEGSEL_PREFIXES as $prefix) {
            if (str_starts_with($lowered, $prefix)) {
                $core = substr($last, strlen($prefix));
                break;
            }
        }
    }
    return strtolower(trim($first)) . '|' . strtolower(trim($core));
}

// --- load existing members ---
$db_members = [];
$rows = $wpdb->get_results("SELECT id, first_name, last_name, suffix, birth_date, phone, lldap_user_id FROM {$wpdb->prefix}avm_members");
foreach ($rows as $r) {
    $key = normalize_name_key($r->first_name, $r->last_name);
    if (isset($db_members[$key])) {
        echo "  WARNING: duplicate name among DB members, first one wins: {$r->first_name} {$r->last_name} (id={$r->id})\n";
        continue;
    }
    $db_members[$key] = $r;
}

// --- group ledenlijst rows by normalized name, preserving date order ---
$people = [];
$review_needed = [];
foreach ($editions as $ed) {
    $datum = $ed['datum'];
    foreach ($ed['personen'] as $p) {
        if (!empty($p['needs_review'])) {
            // Missing-comma OCR/typesetting artifact that couldn't be
            // resolved by fixing the source .md (or a genuine non-person
            // entry like an institution/family name) — never guessed,
            // reported only, no avm_members/avm_addresses row from these.
            $review_needed[] = [$ed['editie'], $datum, $p];
            continue;
        }
        $key = normalize_name_key($p['voornaam'], $p['achternaam']);
        if (!isset($people[$key])) {
            $people[$key] = ['display' => $p, 'snapshots' => []];
        }
        $people[$key]['snapshots'][] = [$datum, $p];
    }
}

echo count($people) . " unieke personen over " . count($editions) . " snapshots (na normalisatie)\n\n";

if ($review_needed) {
    echo "=== " . count($review_needed) . " regels met needs_review (ontbrekende komma o.i.d.) ===\n";
    foreach ($review_needed as [$ed_name, $d, $p]) {
        echo "  $ed_name ($d): \"{$p['voornaam']} {$p['achternaam']}\" suffix=\"{$p['suffix']}\"\n";
    }
    echo "\n";
}

function addr_key(array $a): string {
    return implode('|', [$a['straat'], $a['huisnummer'], $a['postcode'], $a['plaats'], $a['land']]);
}

// coalesce consecutive identical addresses into periods
function coalesce_periods(array $snapshots): array {
    $periods = [];
    foreach ($snapshots as [$d, $addr]) {
        if (empty($addr['straat']) && empty($addr['plaats'])) continue;
        if ($periods && addr_key(end($periods)['addr']) === addr_key($addr)) continue;
        if ($periods) {
            $prev = new DateTime($d);
            $prev->modify('-1 day');
            $periods[count($periods) - 1]['valid_until'] = $prev->format('Y-m-d');
        }
        $periods[] = ['valid_from' => $d, 'valid_until' => null, 'addr' => $addr];
    }
    return $periods;
}

ksort($people);

$n_matched = 0; $n_created = 0; $n_addr_rows = 0;
$n_birthdate_filled = 0; $n_birthdate_conflict = 0; $n_phone_filled = 0;
$conflicts = [];

foreach ($people as $key => $entry) {
    $p0 = $entry['display'];
    $voornaam = $p0['voornaam']; $achternaam = $p0['achternaam']; $suffix = $p0['suffix'];
    $snapshots = $entry['snapshots'];
    usort($snapshots, fn($a, $b) => strcmp($a[0], $b[0]));

    $db_row = $db_members[$key] ?? null;
    $member_id = null;

    if ($db_row) {
        $n_matched++;
        $member_id = (int) $db_row->id;
    } else {
        $n_created++;
        $uid_base = preg_replace('/[^a-z0-9._-]/', '.', strtolower("$voornaam.$achternaam"));
        $email = "$uid_base@avpvh.local";
        echo "  NIEUW (inactive): $voornaam $suffix $achternaam <$email>\n";
        if (!$dry_run) {
            $existing = AVPVH_LLDAP::get_user_display_name($uid_base);
            if ($existing === null) {
                $created = AVPVH_LLDAP::create_user($uid_base, $email, trim("$voornaam $achternaam"));
                if (is_wp_error($created)) {
                    echo "    LLDAP create FAILED: " . $created->get_error_message() . "\n";
                    continue;
                }
            }
            $member_id = AVPVH_DB::create_member($uid_base, $voornaam, $suffix, $achternaam, null, 'inactive');
        }
    }

    // birth_date / phone backfill from most recent snapshot with a value
    $new_birth = null; $new_phone = null;
    foreach ($snapshots as [$d, $p]) {
        if (!empty($p['geboortedatum'])) $new_birth = $p['geboortedatum'];
        if (!empty($p['telefoon'])) $new_phone = $p['telefoon'];
    }

    if ($db_row) {
        if ($new_birth && empty($db_row->birth_date)) {
            $n_birthdate_filled++;
            if (!$dry_run) $wpdb->update("{$wpdb->prefix}avm_members", ['birth_date' => $new_birth], ['id' => $member_id]);
        } elseif ($new_birth && !empty($db_row->birth_date) && (string)$db_row->birth_date !== $new_birth) {
            $n_birthdate_conflict++;
            $conflicts[] = "$voornaam $achternaam: DB birth_date={$db_row->birth_date} vs ledenlijst=$new_birth";
        }
        if ($new_phone && empty($db_row->phone)) {
            $n_phone_filled++;
            if (!$dry_run) $wpdb->update("{$wpdb->prefix}avm_members", ['phone' => $new_phone], ['id' => $member_id]);
        }
    } elseif (!$dry_run && $member_id && ($new_birth || $new_phone)) {
        $wpdb->update("{$wpdb->prefix}avm_members", ['birth_date' => $new_birth, 'phone' => $new_phone], ['id' => $member_id]);
    }

    // address periods
    $periods = coalesce_periods($snapshots);
    $n_addr_rows += count($periods);
    foreach ($periods as $period) {
        $a = $period['addr'];
        if (!$dry_run && $member_id) {
            $wpdb->insert("{$wpdb->prefix}avm_addresses", [
                'member_id' => $member_id,
                'street' => $a['straat'], 'house_number' => $a['huisnummer'],
                'postal_code' => $a['postcode'], 'city' => $a['plaats'], 'country' => $a['land'],
                'valid_from' => $period['valid_from'], 'valid_until' => $period['valid_until'],
            ]);
        }
    }
}

echo "\n=== Samenvatting ===\n";
echo "Gematchte bestaande leden: $n_matched\n";
echo "Nieuw aan te maken (inactive) leden: $n_created\n";
echo "avm_addresses rijen (na samenvoegen): $n_addr_rows\n";
echo "birth_date ingevuld (was leeg): $n_birthdate_filled\n";
echo "phone ingevuld (was leeg): $n_phone_filled\n";
echo "birth_date CONFLICTEN (niet overschreven): $n_birthdate_conflict\n";
foreach ($conflicts as $c) echo "  - $c\n";

echo $dry_run ? "\nDry-run compleet — niets weggeschreven.\n" : "\nKlaar — gecommit.\n";
