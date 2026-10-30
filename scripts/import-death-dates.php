<?php
/**
 * Records dates of death from a list, sets the kenmerken it names, and
 * adds people who aren't in the administration yet as ex-lid with
 * kenmerk Overleden. A dry run unless AVPVH_APPLY=1.
 *
 * The list stays out of git (scripts/*.local.txt), one person per line:
 *   naam | JJJJ-MM-DD (of leeg) | kenmerk-slugs met komma's | #lid-id (optioneel)
 * Lines starting with # are comments. Without an id the name is matched
 * (full name, else first + last name); a name that matches more than one
 * member is skipped, give its id.
 *
 * Run inside WordPress, the list from a file or set as $avpvh_death_lines
 * by a wrapper that embeds it:
 *   AVPVH_DEATHS_FILE=/pad/naar/lijst wp eval-file scripts/import-death-dates.php
 *   AVPVH_APPLY=1 AVPVH_DEATHS_FILE=/pad/naar/lijst wp eval-file scripts/import-death-dates.php
 */

defined('ABSPATH') || exit;

global $wpdb;

$apply = getenv('AVPVH_APPLY') === '1';
if (!isset($avpvh_death_lines)) {
    $file = (string) getenv('AVPVH_DEATHS_FILE');
    if ($file === '' || !is_readable($file)) {
        fwrite(STDERR, "Geef de lijst in AVPVH_DEATHS_FILE.\n");
        exit(2);
    }
    $avpvh_death_lines = file($file, FILE_IGNORE_NEW_LINES);
}

$fold = static fn(string $value): string => AVPVH_Address_Normalizer::fold($value);
$flag_ids = [];
foreach (AVPVH_DB::get_all_flags() as $flag) {
    $flag_ids[$flag->slug] = (int) $flag->id;
}
$members = $wpdb->get_results("SELECT id, first_name, suffix, last_name FROM {$wpdb->prefix}avm_members") ?: [];

/** "Anna van Dijk" -> ['Anna', 'van', 'Dijk']; a capitalised "De" stays part of the last name (Belgian). */
$split_name = static function (string $name): array {
    if (preg_match('/^(\S+)\s+((?:van|de|den|der|het|ter|ten|te)(?:\s+(?:van|de|den|der|het))*)\s+(.+)$/u', $name, $m)) {
        return [$m[1], $m[2], $m[3]];
    }
    $parts = preg_split('/\s+/u', $name, 2);
    return [$parts[0], '', $parts[1] ?? ''];
};

$find = static function (string $name) use ($members, $fold, $split_name): array {
    [$first, , $last] = $split_name($name);
    $full = $fold($name);
    $exact = array_filter($members, fn($m) => $fold(trim("{$m->first_name} {$m->suffix} {$m->last_name}")) === $full);
    if ($exact) {
        return array_values($exact);
    }
    return array_values(array_filter($members, fn($m) => $fold($m->last_name) === $fold($last)
        && ($fold($m->first_name) === $fold($first) || str_starts_with($fold($m->first_name), mb_substr($fold($first), 0, 3)))));
};

$counts = ['bijgewerkt' => 0, 'nieuw' => 0, 'overgeslagen' => 0];
foreach ($avpvh_death_lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    [$name, $date, $slugs, $id] = array_map('trim', array_pad(explode('|', $line), 4, ''));
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo "OVERGESLAGEN {$name}: datum '{$date}' is geen JJJJ-MM-DD\n";
        $counts['overgeslagen']++;
        continue;
    }
    $wanted = array_values(array_filter(array_map('trim', explode(',', $slugs))));
    $unknown = array_diff($wanted, array_keys($flag_ids));
    if ($unknown) {
        echo "OVERGESLAGEN {$name}: onbekend kenmerk " . implode(', ', $unknown) . "\n";
        $counts['overgeslagen']++;
        continue;
    }

    $hits = $id !== '' ? array_values(array_filter($members, fn($m) => (int) $m->id === (int) ltrim($id, '#'))) : $find($name);
    if (count($hits) > 1) {
        echo "OVERGESLAGEN {$name}: meerdere leden (" . implode(', ', array_map(fn($m) => "#{$m->id} " . avpvh_format_name($m), $hits)) . "); geef een #id\n";
        $counts['overgeslagen']++;
        continue;
    }

    if (!$hits) {
        [$first, $suffix, $last] = $split_name($name);
        echo "NIEUW        {$name} -> voornaam '{$first}', tussenvoegsel '{$suffix}', achternaam '{$last}', ex-lid, kenmerken " . implode(',', $wanted) . ($date ? ", overleden {$date}" : ', geen datum') . "\n";
        if ($apply) {
            $member_id = AVPVH_Admin::create_person($first, $suffix, $last, null, null, 'inactive', array_map(fn($slug) => $flag_ids[$slug], $wanted));
            if (is_wp_error($member_id)) {
                echo "  MISLUKT: " . $member_id->get_error_message() . "\n";
                continue;
            }
            if ($date) {
                AVPVH_DB::set_death_date($member_id, $date);
            }
            echo "  aangemaakt als #{$member_id}\n";
        }
        $counts['nieuw']++;
        continue;
    }

    $member = AVPVH_DB::get_member((int) $hits[0]->id);
    $current = array_map(fn($flag) => $flag->slug, AVPVH_DB::get_flags_for_member((int) $member->id));
    $add = array_values(array_diff($wanted, $current));
    $open = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d AND (valid_until IS NULL OR valid_until > %s)",
        $member->id, $date ?: '9999-12-31'
    ));
    $changes = array_filter([
        $date && $date !== ($member->death_date ?? null) ? "overleden {$date}" : null,
        $add ? 'kenmerken + ' . implode(',', $add) : null,
        $date && $open ? "{$open} adres(sen) eindigen op {$date}" : null,
    ]);
    echo ($changes ? 'BIJWERKEN    ' : 'KLOPT AL     ') . "{$name} -> #{$member->id} " . avpvh_format_name($member) . ($changes ? ': ' . implode('; ', $changes) : '') . "\n";
    if ($apply && $changes) {
        if ($add) {
            AVPVH_DB::set_member_flags((int) $member->id, array_map(fn($slug) => $flag_ids[$slug], array_merge($current, $add)));
        }
        if ($date) {
            $result = AVPVH_DB::set_death_date((int) $member->id, $date);
            if ($result['later']) {
                echo "  LET OP: {$result['later']} adres(sen) begint pas na de overlijdensdatum\n";
            }
        }
        if (AVPVH_DB::member_has_inactivating_flag((int) $member->id)) {
            AVPVH_Roles::strip_bestuur_roles((int) $member->id);
        }
        AVPVH_Roles::sync_status_group((int) $member->id);
    }
    $counts[$changes ? 'bijgewerkt' : 'overgeslagen'] += $changes ? 1 : 0;
}

echo "\n" . ($apply ? 'Toegepast' : 'Proefdraai (niets gewijzigd; AVPVH_APPLY=1 om toe te passen)') . ": {$counts['bijgewerkt']} bij te werken, {$counts['nieuw']} nieuw, {$counts['overgeslagen']} overgeslagen.\n";
