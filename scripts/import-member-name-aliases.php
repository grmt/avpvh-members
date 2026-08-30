<?php
/**
 * Import explicit, member-specific name aliases from ignored local data.
 * Dry-run is the default; set AVPVH_ALIAS_APPLY=1 for an actual import.
 *
 * Local file: scripts/member-name-aliases.local.json
 * {
 *   "aliases": [{
 *     "member_id": 123,
 *     "expected_official_key": "fictief|voorbeeld",
 *     "first_name": "Fictief",
 *     "suffix": "",
 *     "last_name": "Voorbeelt",
 *     "alias_type": "spelling",
 *     "source": "bevestigde ledenadministratie",
 *     "note": ""
 *   }]
 * }
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

    $official = AVPVH_DB::normalize_person_name(
        (string) $member->first_name,
        (string) $member->suffix,
        (string) $member->last_name
    );
    if (!hash_equals(
        (string) ($alias['expected_official_key'] ?? ''),
        $official['normalized_key']
    )) {
        echo "[{$index}] FOUT: verwachte officiële sleutel past niet bij member_id {$member_id}.\n";
        $errors++;
        continue;
    }

    $candidate = AVPVH_DB::normalize_person_name(
        (string) ($alias['first_name'] ?? ''),
        (string) ($alias['suffix'] ?? ''),
        (string) ($alias['last_name'] ?? '')
    );
    $matches = AVPVH_DB::find_members_by_name_or_alias(
        (string) ($alias['first_name'] ?? ''),
        (string) ($alias['suffix'] ?? ''),
        (string) ($alias['last_name'] ?? '')
    );
    $other_ids = array_values(array_filter(
        array_unique(array_map(static fn(object $match): int => (int) $match->id, $matches)),
        static fn(int $id): bool => $id !== $member_id
    ));
    echo "[{$index}] member_id={$member_id}; alias_key={$candidate['normalized_key']}; ";
    echo $other_ids
        ? 'conflict_met_member_ids=' . implode(',', $other_ids) . "\n"
        : "geen conflict\n";
    $planned++;

    if (!empty($alias['review_only'])) {
        echo "[{$index}] Alleen ter beoordeling; wordt ook in apply-modus niet opgeslagen.\n";
        continue;
    }
    if (!$apply) {
        continue;
    }
    $saved = AVPVH_DB::save_member_name_alias($member_id, $alias);
    if (!$saved) {
        echo "[{$index}] FOUT: alias niet opgeslagen (mogelijk al aanwezig).\n";
        $errors++;
    }
}

echo "Gepland: {$planned}; fouten: {$errors}.\n";
if (!$apply) {
    echo "Dry-run compleet; niets gewijzigd.\n";
}
exit($errors ? 1 : 0);
