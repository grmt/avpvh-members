<?php
defined('ABSPATH') || exit;

/**
 * Pure name-normalization logic — no DB access. Wrapped by
 * AVPVH_DB::normalize_person_name() / find_members_by_name_or_alias() /
 * get_member_name_variants(), which are the public API (plan.md §7).
 *
 * Replaces the normalize_name_key() copies previously duplicated in
 * scripts/_avpvh_import_common.py, scripts/reconcile-members.py, and
 * inline in scripts/import-dgeen-ledenlijsten.php.
 *
 * pvh_avm_members stores tussenvoegsel five different ways depending on
 * when/how a row was created: cleanly split into the `suffix` column;
 * glued into `last_name` with a comma ("Achternaam, suffix" — legacy bulk
 * import); glued directly onto the front of `last_name` with no comma
 * ("De Voorbeeld"); glued as an abbreviation onto the END of `last_name`
 * ("Achternaam v/d", historical DGéén import); or glued (as a full word or an
 * abbreviation) onto `first_name` ("Voornaam van", "v/d Voornaam", also
 * DGéén). Matching must treat all of these as the same person while
 * NEVER guessing which full tussenvoegsel an abbreviation like "v/d"
 * stands for (van de / van den / van der are all possible) — see plan.md
 * §2/§3. Abbreviations are recognized (so they don't block a match or get
 * silently dropped) but never expanded to a specific canonical form.
 */
class AVPVH_Name_Matcher {

    public const TUSSENVOEGSEL_PREFIXES = [
        'van der ', 'van den ', 'van de ', 'ten ', 'ter ',
        'de ', 'van ', 'te ', 'von ', 'la ', 'le ', 'du ',
    ];

    // Abbreviated tussenvoegsel forms seen glued onto the end of last_name
    // or onto first_name in the historical DGéén data. Checked longest
    // first so "v/dr" isn't mistaken for "v/d" plus a stray "r".
    private const GLUED_ABBREVIATIONS = ['v/dr', 'v.dr.', 'v/d', 'v.d.', 'vd', 'v'];

    /**
     * "first|core-last" match key — same shape this has always been
     * computed in, now also stripping an abbreviated or full-word
     * tussenvoegsel glued onto either end of first_name or last_name.
     * $suffix is accepted (per plan.md §7's prescribed signature) but not
     * part of the key: suffix-column storage is unreliable across rows
     * (see class docblock), so matching has always relied on first name +
     * core surname alone — unchanged here.
     */
    public static function normalize_person_name(string $first_name, string $suffix, string $last_name): string {
        $core_last = self::strip_core_last_name($last_name);
        $core_first = self::strip_glued_tussenvoegsel_from_first_name($first_name);
        return strtolower(trim($core_first)) . '|' . strtolower(trim($core_last));
    }

    /** Back-compat shape for callers that only ever had first/last (no suffix column) to work with. */
    public static function normalize_name_key(string $first_name, string $last_name): string {
        return self::normalize_person_name($first_name, '', $last_name);
    }

    /**
     * Best-effort split of a possibly glue-mangled (first_name, suffix,
     * last_name) triple into clean parts, plus a readable full name. Used
     * to build a human-checkable alias entry when merging a duplicate —
     * NEVER used to silently rewrite a live member row, and never expands
     * an ambiguous abbreviation (returns it as-is; the caller may supply a
     * verified $known_suffix, e.g. from the surviving member's own clean
     * row, to use instead).
     */
    public static function split_glued_name(string $first_name, string $suffix, string $last_name, string $known_suffix = ''): array {
        $core_last = self::strip_core_last_name($last_name);
        $core_first = self::strip_glued_tussenvoegsel_from_first_name($first_name);
        $resolved_suffix = $known_suffix !== '' ? $known_suffix : trim($suffix);

        $display = trim(implode(' ', array_filter([trim($core_first), $resolved_suffix, trim($core_last)])));

        return [
            'first_name' => trim($core_first),
            'suffix'     => $resolved_suffix,
            'last_name'  => trim($core_last),
            'display'    => $display,
        ];
    }

    private static function strip_core_last_name(string $last_name): string {
        $last = trim($last_name);
        if (str_contains($last, ',')) {
            return trim(explode(',', $last, 2)[0]);
        }
        $lowered = strtolower($last);
        foreach (self::TUSSENVOEGSEL_PREFIXES as $prefix) {
            if (str_starts_with($lowered, $prefix)) {
                return trim(substr($last, strlen($prefix)));
            }
        }
        foreach (self::GLUED_ABBREVIATIONS as $abbr) {
            $suffix_pattern = ' ' . $abbr;
            if (strlen($last) > strlen($suffix_pattern)
                && strtolower(substr($last, -strlen($suffix_pattern))) === $suffix_pattern) {
                return trim(substr($last, 0, -strlen($suffix_pattern)));
            }
        }
        return $last;
    }

    private static function strip_glued_tussenvoegsel_from_first_name(string $first_name): string {
        $first = trim($first_name);
        $lowered = strtolower($first);

        // Leading abbreviation: "v/d Voornaam" -> "Voornaam"
        foreach (self::GLUED_ABBREVIATIONS as $abbr) {
            $prefix_pattern = $abbr . ' ';
            if (str_starts_with($lowered, $prefix_pattern)) {
                return trim(substr($first, strlen($prefix_pattern)));
            }
        }

        // Trailing full tussenvoegsel word: "Voornaam van" -> "Voornaam"
        foreach (self::TUSSENVOEGSEL_PREFIXES as $prefix) {
            $word = rtrim($prefix);
            $suffix_pattern = ' ' . $word;
            if (strlen($first) > strlen($suffix_pattern)
                && strtolower(substr($first, -strlen($suffix_pattern))) === $suffix_pattern) {
                return trim(substr($first, 0, -strlen($suffix_pattern)));
            }
        }

        return $first;
    }
}
