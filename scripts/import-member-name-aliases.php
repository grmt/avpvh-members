<?php
/**
 * Import explicit, member-specific name aliases from ignored local data.
 * Dry-run is the default; set AVPVH_ALIAS_APPLY=1 for an actual import.
 *
 * Run inside WordPress with:
 *   wp eval-file scripts/import-member-name-aliases.php
 *   AVPVH_ALIAS_APPLY=1 wp eval-file scripts/import-member-name-aliases.php
 *
 * Local file (override with AVPVH_ALIAS_CONFIG; must match scripts/*.local.*
 * so Git never tracks it): scripts/member-name-aliases.local.json
 * {
 *   "aliases": [{
 *     "member_id": 123,
 *     "expected_official_key": "<AVPVH_Name_Matcher key of the official name>",
 *     "first_name": "Fictief",
 *     "suffix": "",
 *     "last_name": "Voorbeelt",
 *     "alias_type": "spelling",
 *     "source": "bevestigde ledenadministratie",
 *     "note": "",
 *     "review_only": false
 *   }]
 * }
 *
 * expected_official_key guards against a wrong member_id: the alias is only
 * saved when the member's current official name still has that key. Saving
 * goes through AVPVH_DB::save_name_alias(), the same as the Naamvarianten
 * section in Ledendetail.
 */

defined('ABSPATH') || exit;

$config_path = getenv('AVPVH_ALIAS_CONFIG')
    ?: __DIR__ . '/member-name-aliases.local.json';
$apply = getenv('AVPVH_ALIAS_APPLY') === '1';

if (!is_file($config_path)) {
    fwrite(STDERR, "Lokale configuratie ontbreekt: {$config_path}\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($config_path), true);
if (!is_array($config) || !isset($config['aliases']) || !is_array($config['aliases'])) {
    fwrite(STDERR, "Ongeldige configuratie: verwacht een aliases-array.\n");
    exit(1);
}

echo $apply ? "=== ALIASES TOEPASSEN ===\n" : "=== ALIAS DRY-RUN ===\n";
$errors = 0;
$planned = 0;

foreach ($config['aliases'] as $index => $alias) {
    $member_id = absint($alias['member_id'] ?? 0);
    $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
    if (!$member) {
        echo "[{$index}] FOUT: member_id bestaat niet.\n";
        $errors++;
        continue;
    }

    $official_key = AVPVH_DB::normalize_person_name(
        (string) $member->first_name,
        (string) $member->suffix,
        (string) $member->last_name
    );
    if (!hash_equals($official_key, (string) ($alias['expected_official_key'] ?? ''))) {
        echo "[{$index}] FOUT: verwachte officiële sleutel past niet bij member_id {$member_id}.\n";
        $errors++;
        continue;
    }

    $first  = (string) ($alias['first_name'] ?? '');
    $suffix = (string) ($alias['suffix'] ?? '');
    $last   = (string) ($alias['last_name'] ?? '');
    $alias_key = AVPVH_DB::normalize_person_name($first, $suffix, $last);
    $other_ids = array_values(array_filter(
        array_map(static fn(object $match): int => (int) $match->id, AVPVH_DB::find_members_by_name_or_alias($first, $suffix, $last)),
        static fn(int $id): bool => $id !== $member_id
    ));
    // Print a hash, not the key itself: the key is the name.
    echo "[{$index}] member_id={$member_id}; alias_hash=" . substr(hash('sha256', $alias_key), 0, 12) . '; ';
    echo $other_ids
        ? 'conflict_met_member_ids=' . implode(',', array_unique($other_ids)) . "\n"
        : "geen conflict\n";
    $planned++;

    if (!empty($alias['review_only'])) {
        echo "[{$index}] Alleen ter beoordeling; wordt ook in apply-modus niet opgeslagen.\n";
        continue;
    }
    if (!$apply) {
        continue;
    }
    $error = AVPVH_DB::save_name_alias($member_id, $alias);
    if ($error === 'dubbel') {
        echo "[{$index}] Al aanwezig; overgeslagen.\n";
    } elseif ($error !== '') {
        echo "[{$index}] FOUT: alias niet opgeslagen ({$error}).\n";
        $errors++;
    }
}

echo "Gepland: {$planned}; fouten: {$errors}.\n";
if (!$apply) {
    echo "Dry-run compleet; niets gewijzigd.\n";
}
exit($errors ? 1 : 0);
