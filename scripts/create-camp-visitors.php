<?php
/**
 * Create the camp participants that import-avpvh-camps.py could not match
 * as Bezoeker (status visitor), so a second import links their attendance.
 * Dry-run is the default; set AVPVH_CAMP_VISITORS_APPLY=1 to create them.
 *
 * Run inside WordPress (wpcli-pvh) with:
 *   wp eval-file scripts/create-camp-visitors.php
 *   AVPVH_CAMP_VISITORS_APPLY=1 wp eval-file scripts/create-camp-visitors.php
 * On the server the container can't see this checkout: README.md (Camp
 * participation imports) shows the docker compose run that mounts scripts/.
 *
 * Input (override with AVPVH_CAMP_VISITORS_FILE; must match scripts/*.local.*
 * so Git never tracks it): scripts/camp-unmatched.local.json, as written by
 *   import-avpvh-camps.py <archive> --dry-run --unmatched-out scripts/camp-unmatched.local.json
 * {
 *   "status": "visitor",
 *   "people": [{
 *     "name": "Fictief van Voorbeeld",
 *     "first_name": "Fictief", "suffix": "van", "last_name": "Voorbeeld",
 *     "review": false,
 *     "camps": ["2013 Plaats"],
 *     "create": true
 *   }]
 * }
 *
 * Review the file first: correct a wrong split, and set "create" to false
 * for a person who already exists under another spelling (add a name
 * variant to that person instead). Entries still marked "review": true are
 * refused until the flag is cleared. A person whose name (or a recorded
 * variant) already matches someone is skipped, so re-running is safe.
 * Creation goes through AVPVH_Admin::create_person(), the same as Nieuwe
 * persoon: a directory account with a placeholder address (no login) and
 * the member row. Names are not printed, only the entry number.
 */

defined('ABSPATH') || exit;

/**
 * @param array<int, array<string, mixed>> $people
 * @return array{created: int, existing: int, skipped: int, errors: int, lines: string[]}
 */
function avpvh_create_camp_visitors(array $people, bool $apply): array {
    $result = ['created' => 0, 'existing' => 0, 'skipped' => 0, 'errors' => 0, 'lines' => []];
    foreach ($people as $index => $person) {
        $label = "[{$index}]";
        if (!is_array($person) || empty($person['create'])) {
            $result['skipped']++;
            $result['lines'][] = "{$label} create=false; overgeslagen.";
            continue;
        }
        if (!empty($person['review'])) {
            $result['errors']++;
            $result['lines'][] = "{$label} FOUT: nog \"review\": true; controleer de naamsplitsing en zet review op false.";
            continue;
        }
        $first  = trim((string) ($person['first_name'] ?? ''));
        $suffix = trim((string) ($person['suffix'] ?? ''));
        $last   = trim((string) ($person['last_name'] ?? ''));
        if ($first === '' || $last === '') {
            $result['errors']++;
            $result['lines'][] = "{$label} FOUT: voornaam of achternaam ontbreekt.";
            continue;
        }
        $matches = AVPVH_DB::find_members_by_name_or_alias($first, $suffix, $last);
        if ($matches) {
            $ids = implode(',', array_unique(array_map(static fn($m): int => (int) $m->id, $matches)));
            $result['existing']++;
            $result['lines'][] = "{$label} bestaat al (member_id {$ids}); overgeslagen.";
            continue;
        }
        if (!$apply) {
            $result['created']++;
            $result['lines'][] = "{$label} zou als Bezoeker worden aangemaakt.";
            continue;
        }
        $member_id = AVPVH_Admin::create_person($first, $suffix, $last, null, null, 'visitor', []);
        if (is_wp_error($member_id)) {
            $result['errors']++;
            $result['lines'][] = "{$label} FOUT: niet aangemaakt (" . $member_id->get_error_message() . ').';
            continue;
        }
        $result['created']++;
        $result['lines'][] = "{$label} aangemaakt als Bezoeker: member_id {$member_id}.";
    }
    return $result;
}

/**
 * Read and check the people file.
 *
 * @return array<int, mixed>|string the people, or an error message
 */
function avpvh_camp_visitors_load(string $file): array|string {
    if (!is_file($file)) {
        return "Bestand ontbreekt: {$file}";
    }
    $input = json_decode((string) file_get_contents($file), true);
    if (!is_array($input) || !isset($input['people']) || !is_array($input['people'])) {
        return 'Ongeldig bestand: verwacht een people-array.';
    }
    if (($input['status'] ?? 'visitor') !== 'visitor') {
        return 'Alleen status visitor (Bezoeker) wordt ondersteund.';
    }
    return $input['people'];
}

/** Print the outcome; returns the exit status. */
function avpvh_camp_visitors_report(array $result, bool $apply): int {
    echo $apply ? "=== BEZOEKERS AANMAKEN ===\n" : "=== DRY-RUN BEZOEKERS ===\n";
    echo implode("\n", $result['lines']), "\n";
    printf(
        "%s: %d; bestond al: %d; overgeslagen: %d; fouten: %d.\n",
        $apply ? 'Aangemaakt' : 'Aan te maken',
        $result['created'], $result['existing'], $result['skipped'], $result['errors']
    );
    if (!$apply) {
        echo "Dry-run compleet; niets gewijzigd.\n";
    }
    return $result['errors'] ? 1 : 0;
}

if (!defined('AVPVH_CAMP_VISITORS_NO_RUN')) {
    $avpvh_apply = getenv('AVPVH_CAMP_VISITORS_APPLY') === '1';
    $avpvh_people = avpvh_camp_visitors_load(getenv('AVPVH_CAMP_VISITORS_FILE') ?: __DIR__ . '/camp-unmatched.local.json');
    if (is_string($avpvh_people)) {
        fwrite(STDERR, $avpvh_people . "\n");
        exit(1);
    }
    exit(avpvh_camp_visitors_report(avpvh_create_camp_visitors($avpvh_people, $avpvh_apply), $avpvh_apply));
}
