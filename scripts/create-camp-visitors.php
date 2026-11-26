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
 *   import-avpvh-camps.py <archive> --dry-run --unmatched-out camp-unmatched.local.json
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
 * for an entry that is not a person. For someone who already exists under
 * another spelling, set "alias_of" to that member's id: the name is then
 * recorded as a name variant (Naamvarianten, type spelling) instead of
 * creating a new person. Entries still marked "review": true are refused
 * until the flag is cleared. A name that already matches someone (official
 * name or variant) is skipped, so re-running is safe.
 * Creation goes through AVPVH_Admin::create_person(), the same as Nieuwe
 * persoon: a directory account with a placeholder address (no login) and
 * the member row. Names are not printed, only the entry number.
 */

defined('ABSPATH') || exit;

/**
 * @param array<int, array<string, mixed>> $people
 * @return array{created: int, aliased: int, existing: int, skipped: int, errors: int, lines: string[]}
 */
function avpvh_create_camp_visitors(array $people, bool $apply): array {
    $result = ['created' => 0, 'aliased' => 0, 'existing' => 0, 'skipped' => 0, 'errors' => 0, 'lines' => []];
    foreach ($people as $index => $person) {
        [$outcome, $message] = avpvh_camp_visitor_entry($person, $apply);
        $result[$outcome]++;
        $result['lines'][] = "[{$index}] {$message}";
    }
    return $result;
}

/**
 * Handle one entry of the people file.
 *
 * @param mixed $person
 * @return array{0: string, 1: string} outcome (a result counter) and message
 */
function avpvh_camp_visitor_entry($person, bool $apply): array {
    $alias_of = is_array($person) ? (int) ($person['alias_of'] ?? 0) : 0;
    if (!is_array($person) || (empty($person['create']) && $alias_of <= 0)) {
        return ['skipped', 'create=false; overgeslagen.'];
    }
    if (!empty($person['review'])) {
        return ['errors', 'FOUT: nog "review": true; controleer de naamsplitsing en zet review op false.'];
    }
    $name = [
        'first_name' => trim((string) ($person['first_name'] ?? '')),
        'suffix'     => trim((string) ($person['suffix'] ?? '')),
        'last_name'  => trim((string) ($person['last_name'] ?? '')),
    ];
    if ($name['first_name'] === '' || $name['last_name'] === '') {
        return ['errors', 'FOUT: voornaam of achternaam ontbreekt.'];
    }
    $matches = AVPVH_DB::find_members_by_name_or_alias($name['first_name'], $name['suffix'], $name['last_name']);
    if ($matches) {
        $ids = implode(',', array_unique(array_map(static fn($m): int => (int) $m->id, $matches)));
        return ['existing', "bestaat al (member_id {$ids}); overgeslagen."];
    }
    return $alias_of > 0
        ? avpvh_camp_visitor_alias($alias_of, $name, $apply)
        : avpvh_camp_visitor_create($name, $apply);
}

/**
 * Record the name as a variant of an existing member.
 *
 * @param array{first_name: string, suffix: string, last_name: string} $name
 * @return array{0: string, 1: string}
 */
function avpvh_camp_visitor_alias(int $member_id, array $name, bool $apply): array {
    if (!AVPVH_DB::get_member($member_id)) {
        return ['errors', "FOUT: alias_of member_id {$member_id} bestaat niet."];
    }
    if (!$apply) {
        return ['aliased', "zou als naamvariant van member_id {$member_id} worden vastgelegd."];
    }
    $error = AVPVH_DB::save_name_alias($member_id, $name + ['alias_type' => 'spelling', 'source' => 'kampimport']);
    return $error === ''
        ? ['aliased', "naamvariant vastgelegd bij member_id {$member_id}."]
        : ['errors', "FOUT: naamvariant niet vastgelegd ({$error})."];
}

/**
 * Create the person as Bezoeker.
 *
 * @param array{first_name: string, suffix: string, last_name: string} $name
 * @return array{0: string, 1: string}
 */
function avpvh_camp_visitor_create(array $name, bool $apply): array {
    if (!$apply) {
        return ['created', 'zou als Bezoeker worden aangemaakt.'];
    }
    $member_id = AVPVH_Admin::create_person($name['first_name'], $name['suffix'], $name['last_name'], null, null, 'visitor', []);
    return is_wp_error($member_id)
        ? ['errors', 'FOUT: niet aangemaakt (' . $member_id->get_error_message() . ').']
        : ['created', "aangemaakt als Bezoeker: member_id {$member_id}."];
}

/**
 * Read and check the people file.
 *
 * @return array<int, mixed>|string the people, or an error message
 */
function avpvh_camp_visitors_load(string $file): array|string {
    $input = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_file($file)) {
        $error = "Bestand ontbreekt: {$file}";
    } elseif (!is_array($input) || !isset($input['people']) || !is_array($input['people'])) {
        $error = 'Ongeldig bestand: verwacht een people-array.';
    } elseif (($input['status'] ?? 'visitor') !== 'visitor') {
        $error = 'Alleen status visitor (Bezoeker) wordt ondersteund.';
    }
    return $error ?? $input['people'];
}

/** Print the outcome; returns the exit status. */
function avpvh_camp_visitors_report(array $result, bool $apply): int {
    echo $apply ? "=== BEZOEKERS AANMAKEN ===\n" : "=== DRY-RUN BEZOEKERS ===\n";
    echo implode("\n", $result['lines']), "\n";
    printf(
        "%s: %d; %s: %d; bestond al: %d; overgeslagen: %d; fouten: %d.\n",
        $apply ? 'Aangemaakt' : 'Aan te maken',
        $result['created'],
        $apply ? 'naamvarianten vastgelegd' : 'naamvarianten vast te leggen',
        $result['aliased'] ?? 0,
        $result['existing'], $result['skipped'], $result['errors']
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
