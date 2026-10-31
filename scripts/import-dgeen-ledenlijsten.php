<?php
/**
 * Import DGéén ledenlijst snapshots (from the `scan` repo's already-parsed
 * avpvh/02-DGéén/transcripties/{editie}/page_XX.md tables) into avm_members /
 * avm_addresses, treating each publication date as an address-validity
 * boundary. A dry run unless DGEEN_DRY_RUN=0.
 *
 * Input: JSON produced by the (local, uncommitted) extraction script in
 * the `scan` repo — a list of editions {editie, datum, personen: [{voornaam,
 * suffix, achternaam, straat, huisnummer, postcode, plaats, land,
 * geboortedatum, telefoon, needs_review}]}. It holds names and addresses,
 * so it stays out of git and out of the web root: give its path in
 * DGEEN_JSON, or set $avpvh_dgeen_editions in a wrapper that embeds it.
 *
 *   docker compose run --rm -T -e DGEEN_JSON=/pad/in/container.json wpcli-pvh \
 *     wp eval-file scripts/import-dgeen-ledenlijsten.php
 *   (add -e DGEEN_DRY_RUN=0 for a live run)
 *
 * Persons: linked automatically only on an exact name or a recorded name
 * variant (AVPVH_DB::get_name_key_index()). A looser match — accents,
 * ij/y, tussenvoegsels, a first name in initials — or more than one
 * candidate is reported for a human and left alone: a wrong merge of two
 * people is worse than a check. Only a name with no candidate at all
 * becomes a new person (ex-lid, AVPVH_Admin::create_person()).
 *
 * Addresses: cleaned (AVPVH_Address_Normalizer::clean()) and checked
 * against the country's register (AVPVH_Address_Lookup); a scan or typing
 * slip that is recognizably the same address gets the official spelling.
 * Consecutive snapshots with the same address (AVPVH_Address_Matcher) form one
 * period, until the day before the next snapshot with another address;
 * the last one until the day before the first later edition the person
 * is missing from (only someone in the newest edition stays open).
 * Two different addresses for one person in the same edition keep the
 * first and report the second. Periods are merged into the existing
 * history: a stored row of the same address around those dates is only
 * widened to the editions that list it; nothing runs into another stored
 * address of that person; only one address runs on to the present (the
 * newest start); a period is never written with its end before its start.
 *
 * birth_date / phone only fill an empty field; a different birth date is
 * reported, never overwritten. Rows flagged needs_review are reported only.
 */

defined('ABSPATH') || exit;

global $wpdb;

$dry_run = getenv('DGEEN_DRY_RUN') !== '0';
echo $dry_run ? "=== PROEFDRAAI (niets wordt geschreven) ===\n\n" : "=== ECHTE RUN ===\n\n";

if (!isset($avpvh_dgeen_editions)) {
    $json_path = (string) getenv('DGEEN_JSON');
    if ($json_path === '' || !is_readable($json_path)) {
        fwrite(STDERR, "Geef het JSON-bestand in DGEEN_JSON.\n");
        exit(2);
    }
    $avpvh_dgeen_editions = json_decode((string) file_get_contents($json_path), true);
}
if (!is_array($avpvh_dgeen_editions)) {
    fwrite(STDERR, "Ongeldige JSON.\n");
    exit(2);
}

/**
 * "v/d" (with the slash) is unambiguous in this source: the historical
 * DGéén ledenlijsten consistently use it for "van den". Bare "vd"/"v.d."
 * has no such single meaning here and stays ambiguous.
 */
function avpvh_normalize_dgeen_suffix(string $suffix): string {
    $suffix = trim($suffix);
    if (preg_match('/^v\s*\/\s*d\.?$/iu', $suffix)) {
        return 'van den';
    }
    if (preg_match('/^v\.?\s*d\.?$/iu', $suffix)) {
        return 'vd';
    }
    return $suffix;
}

/** A scanned address as cleaned avm_addresses fields, or null when it holds no street or town. */
function avpvh_dgeen_address(array $p): ?array {
    if (trim((string) ($p['straat'] ?? '')) === '' && trim((string) ($p['plaats'] ?? '')) === '') {
        return null;
    }
    $clean = AVPVH_Address_Normalizer::clean([
        'street'       => (string) ($p['straat'] ?? ''),
        'house_number' => (string) ($p['huisnummer'] ?? ''),
        'postal_code'  => (string) ($p['postcode'] ?? ''),
        'city'         => (string) ($p['plaats'] ?? ''),
        'country'      => (string) ($p['land'] ?? '') ?: 'Nederland',
    ]);
    return array_intersect_key($clean, array_flip(['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country']));
}

$day_before = static fn(string $date): string => gmdate('Y-m-d', strtotime($date . ' -1 day'));
$day_after = static fn(string $date): string => gmdate('Y-m-d', strtotime($date . ' +1 day'));
$report = ['gekoppeld' => 0, 'via_variant' => 0, 'nieuw' => 0, 'controleren' => [], 'meerdere' => [], 'needs_review' => [],
    'verbeterd' => [], 'onbekend' => [], 'zelfde_editie' => [], 'verlengd' => 0, 'toegevoegd' => 0, 'afgesloten' => 0,
    'overgeslagen_periode' => [], 'geboortedatum_ingevuld' => 0, 'telefoon_ingevuld' => 0, 'geboortedatum_conflict' => []];

// --- group the scanned rows per person (strict name key), in date order ---
$people = [];
foreach ($avpvh_dgeen_editions as $edition) {
    foreach ($edition['personen'] ?? [] as $p) {
        $p['suffix'] = avpvh_normalize_dgeen_suffix((string) ($p['suffix'] ?? ''));
        $name = trim((string) preg_replace('/\s+/u', ' ', "{$p['voornaam']} {$p['suffix']} {$p['achternaam']}"));
        if (!empty($p['needs_review'])) {
            $report['needs_review'][] = "{$edition['editie']} ({$edition['datum']}): {$name}";
            continue;
        }
        $key = AVPVH_DB::normalize_person_name((string) $p['voornaam'], $p['suffix'], (string) $p['achternaam']);
        $people[$key] ??= ['name' => $name, 'p' => $p, 'snapshots' => []];
        $people[$key]['snapshots'][] = [$edition['datum'], $p, $edition['editie']];
    }
}
ksort($people);
echo count($people) . ' personen in ' . count($avpvh_dgeen_editions) . " edities\n\n";
$edition_dates = array_values(array_unique(array_column($avpvh_dgeen_editions, 'datum')));
sort($edition_dates);

// --- check every scanned address against its register (Dutch ones in parallel) ---
$scanned = [];
foreach ($people as $person) {
    foreach ($person['snapshots'] as [, $p]) {
        if ($address = avpvh_dgeen_address($p)) {
            $scanned[] = $address;
        }
    }
}
AVPVH_Address_Lookup::prefetch($scanned);
$official = static function (array $address, string $who) use (&$report): array {
    $found = AVPVH_Address_Lookup::lookup($address);
    if ($found && AVPVH_Address_Matcher::probably_same((object) $found['fields'], (object) $address)) {
        $fields = $found['fields'] + ['country' => $address['country']];
        if (AVPVH_Address_Cleanup::format($fields) !== AVPVH_Address_Cleanup::format($address)) {
            $report['verbeterd'][AVPVH_Address_Cleanup::format($address)] = AVPVH_Address_Cleanup::format($fields) . " ({$found['source']})";
        }
        return $fields;
    }
    if (in_array($address['country'], ['', 'Nederland'], true)) {
        $report['onbekend'][AVPVH_Address_Cleanup::format($address)] = $who;
    }
    return $address;
};

$strict = AVPVH_DB::get_name_key_index();
$loose = AVPVH_DB::get_name_key_index(true);
$all_members = $wpdb->get_results("SELECT id, first_name, suffix, last_name FROM {$wpdb->prefix}avm_members") ?: [];

foreach ($people as $key => $person) {
    $p0 = $person['p'];
    $name = $person['name'];
    $snapshots = $person['snapshots'];
    usort($snapshots, fn($a, $b) => strcmp($a[0], $b[0]));

    // --- who is it ---
    $exact = array_values($strict[$key] ?? []);
    if (count($exact) > 1) {
        $report['meerdere'][] = "{$name}: " . implode(', ', array_map(fn($m) => "#{$m->id} " . avpvh_format_name($m), $exact));
        continue;
    }
    $member = $exact[0] ?? null;
    if (!$member) {
        $candidates = $loose[AVPVH_Name_Matcher::loose_key((string) $p0['voornaam'], (string) $p0['achternaam'])] ?? [];
        $surname = AVPVH_Name_Matcher::loose_surname((string) $p0['achternaam']);
        foreach ($all_members as $m) {
            if (AVPVH_Name_Matcher::loose_surname((string) $m->last_name) === $surname
                && AVPVH_Name_Matcher::first_names_compatible((string) $p0['voornaam'], (string) $m->first_name)) {
                $candidates[(int) $m->id] = $m;
            }
        }
        if ($candidates) {
            $report['controleren'][] = "{$name} ~ " . implode(', ', array_map(fn($m) => "#{$m->id} " . avpvh_format_name($m), $candidates))
                . ' — niet gekoppeld; leg zo nodig een naamvariant vast en draai opnieuw';
            continue;
        }
    }

    $member_id = $member ? (int) $member->id : 0;
    if ($member) {
        $report['gekoppeld']++;
        $report['via_variant'] += $member->matched_via !== '' ? 1 : 0;
    } else {
        $report['nieuw']++;
        echo "  NIEUW (ex-lid): {$name}\n";
        if (!$dry_run) {
            $created = AVPVH_Admin::create_person((string) $p0['voornaam'], $p0['suffix'], (string) $p0['achternaam'], null, null, 'inactive', []);
            if (is_wp_error($created)) {
                echo '    MISLUKT: ' . $created->get_error_message() . "\n";
                continue;
            }
            $member_id = $created;
        }
    }

    // --- birth date / phone: only fill what's empty ---
    $birth = null;
    $phone = null;
    foreach ($snapshots as [, $p]) {
        $birth = !empty($p['geboortedatum']) ? $p['geboortedatum'] : $birth;
        $phone = !empty($p['telefoon']) ? $p['telefoon'] : $phone;
    }
    $current = $member_id ? AVPVH_DB::get_member($member_id) : null;
    $fill = [];
    if ($birth && empty($current->birth_date)) {
        $fill['birth_date'] = $birth;
        $report['geboortedatum_ingevuld']++;
    } elseif ($birth && $current && (string) $current->birth_date !== $birth) {
        $report['geboortedatum_conflict'][] = "{$name}: administratie {$current->birth_date}, ledenlijst {$birth}";
    }
    if ($phone && empty($current->phone)) {
        $fill['phone'] = $phone;
        $report['telefoon_ingevuld']++;
    }
    if ($fill && !$dry_run && $member_id) {
        $wpdb->update("{$wpdb->prefix}avm_members", $fill, ['id' => $member_id]);
    }

    // --- address periods from the snapshots ---
    $periods = [];
    $last_date = null;
    foreach ($snapshots as [$date, $p, $edition]) {
        $address = avpvh_dgeen_address($p);
        if (!$address) {
            continue;
        }
        $address = $official($address, $name);
        $open = $periods ? $periods[count($periods) - 1] : null;
        if ($open && $date === $last_date && !AVPVH_Address_Matcher::same($open['address'], $address)) {
            $report['zelfde_editie'][] = "{$name}, {$edition}: tweede adres " . AVPVH_Address_Cleanup::format($address) . ' niet gebruikt';
            continue;
        }
        $last_date = $date;
        if ($open && AVPVH_Address_Matcher::same($open['address'], $address)) {
            $periods[count($periods) - 1]['seen_until'] = $date;
            continue;
        }
        if ($open) {
            $periods[count($periods) - 1]['until'] = $day_before($date);
        }
        // from/until: the period the snapshots imply; seen_*: the editions
        // that actually list this address.
        $periods[] = ['address' => $address, 'from' => $date, 'until' => null, 'seen_from' => $date, 'seen_until' => $date];
    }
    // Someone missing from a later edition left the list then: the last
    // known address ends the day before that edition, it doesn't run on
    // to the present. Only someone in the newest edition stays open.
    if ($periods && $last_date !== null) {
        $later = array_values(array_filter($edition_dates, fn($d) => $d > $last_date));
        if ($later) {
            $periods[count($periods) - 1]['until'] = $day_before($later[0]);
        }
    }

    // --- merge into the member's existing history ---
    $table = "{$wpdb->prefix}avm_addresses";
    $rows = $member_id ? ($wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE member_id = %d", $member_id)) ?: []) : [];
    // A period never runs into another stored address of the same person:
    // it ends the day before a later one starts, and starts the day after
    // an earlier one (that started before it) ends.
    $cap = static function (?string $from, ?string $until, array $address, ?object $self) use (&$rows, $day_before, $day_after): array {
        foreach ($rows as $row) {
            if ($row === $self || AVPVH_Address_Matcher::same($row, $address)) {
                continue;
            }
            if ($row->valid_from !== null && (string) $row->valid_from > (string) $from && ($until === null || $until >= $row->valid_from)) {
                $until = $day_before((string) $row->valid_from);
            }
            if ($row->valid_until !== null && ($row->valid_from === null || (string) $row->valid_from <= (string) $from)
                && (string) $row->valid_until >= (string) $from && ($until === null || (string) $row->valid_until < $until)) {
                $from = $day_after((string) $row->valid_until);
            }
        }
        return [$from, $until];
    };
    foreach ($periods as $period) {
        $shown = AVPVH_Address_Cleanup::format($period['address']);

        // The same address already stored around these dates: widen it to
        // the editions that list it, no further.
        $same = null;
        foreach ($rows as $row) {
            if (AVPVH_Address_Matcher::same($row, $period['address'])
                && ($row->valid_from ?? '0000-01-01') <= ($period['until'] === null ? '9999-12-31' : $day_after($period['until']))
                && ($row->valid_until ?? '9999-12-31') >= $day_before($period['from'])) {
                $same = $row;
                break;
            }
        }
        if ($same) {
            $from = $same->valid_from === null ? null : min((string) $same->valid_from, $period['seen_from']);
            $until = $same->valid_until === null ? null : max((string) $same->valid_until, $period['seen_until']);
            [$from, $until] = $cap($from, $until, $period['address'], $same);
            if (($from !== null && $until !== null && $until < $from) || ($from === $same->valid_from && $until === $same->valid_until)) {
                continue;
            }
            $report['verlengd']++;
            [$same->valid_from, $same->valid_until] = [$from, $until];
            if (!$dry_run) {
                AVPVH_DB::update_address((int) $same->id, $member_id, $from, $until);
            }
            continue;
        }

        // A new period. Still open (the person's last edition): the newest
        // start keeps running, so an older open address of another kind
        // ends the day before; a later stored address ends this one ($cap).
        if ($period['until'] === null) {
            foreach ($rows as $row) {
                if ($row->valid_until === null && !AVPVH_Address_Matcher::same($row, $period['address'])
                    && ($row->valid_from === null || (string) $row->valid_from < $period['from'])) {
                    $report['afgesloten']++;
                    $row->valid_until = $day_before($period['from']);
                    if (!$dry_run) {
                        AVPVH_DB::update_address((int) $row->id, $member_id, $row->valid_from, $row->valid_until);
                    }
                }
            }
        }
        [$from, $until] = $cap($period['from'], $period['until'], $period['address'], null);
        if ($until !== null && $until < $from) {
            $report['overgeslagen_periode'][] = "{$name}: {$shown} ({$period['from']} – {$period['until']}) valt binnen andere adressen";
            continue;
        }
        $report['toegevoegd']++;
        $row = (object) ($period['address'] + ['id' => 0, 'member_id' => $member_id, 'valid_from' => $from, 'valid_until' => $until]);
        if (!$dry_run && $member_id) {
            $wpdb->insert($table, $period['address'] + ['member_id' => $member_id, 'valid_from' => $from, 'valid_until' => $until]);
            $row->id = (int) $wpdb->insert_id;
        }
        $rows[] = $row;
    }
}

// --- report ---
echo "\n=== Samenvatting ===\n";
echo "Gekoppeld aan bestaande personen: {$report['gekoppeld']} (waarvan via naamvariant: {$report['via_variant']})\n";
echo "Nieuw aangemaakt (ex-lid): {$report['nieuw']}\n";
echo "Adresregels toegevoegd: {$report['toegevoegd']}, bestaande verlengd: {$report['verlengd']}, oudere afgesloten: {$report['afgesloten']}\n";
echo "Geboortedatum ingevuld: {$report['geboortedatum_ingevuld']}, telefoon ingevuld: {$report['telefoon_ingevuld']}\n";
$sections = [
    'controleren'            => 'Mogelijk bestaande persoon (niet gekoppeld, niet aangemaakt)',
    'meerdere'               => 'Naam past bij meerdere personen (overgeslagen)',
    'needs_review'           => 'Regels gemarkeerd needs_review (overgeslagen)',
    'geboortedatum_conflict' => 'Andere geboortedatum (niet overschreven)',
    'zelfde_editie'          => 'Twee adressen in dezelfde editie',
    'overgeslagen_periode'   => 'Periode met einde vóór begin (niet geschreven)',
];
foreach ($sections as $section => $title) {
    if ($report[$section]) {
        echo "\n{$title}: " . count($report[$section]) . "\n";
        foreach ($report[$section] as $line) {
            echo "  - {$line}\n";
        }
    }
}
if ($report['verbeterd']) {
    echo "\nAdressen verbeterd naar de officiële schrijfwijze: " . count($report['verbeterd']) . "\n";
    foreach ($report['verbeterd'] as $from => $to) {
        echo "  - {$from} -> {$to}\n";
    }
}
if ($report['onbekend']) {
    echo "\nNederlandse adressen niet (eenduidig) in de BAG: " . count($report['onbekend']) . "\n";
    foreach ($report['onbekend'] as $address => $who) {
        echo "  - {$address} ({$who})\n";
    }
}
echo $dry_run ? "\nProefdraai klaar: niets geschreven (DGEEN_DRY_RUN=0 om toe te passen).\n" : "\nKlaar.\n";
