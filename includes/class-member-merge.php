<?php
defined('ABSPATH') || exit;

/**
 * Merges a duplicate member ("remove") into the member that stays ("keep").
 * Powers the "Leden samenvoegen" admin page — the interactive successor of
 * scripts/merge-duplicate-members.php, which only handled duplicates
 * without any dependent rows.
 *
 * Flow: preview() reports everything that will happen plus a fingerprint
 * of the current state; execute() recomputes that fingerprint and refuses
 * if anything changed in between, then rewrites every reference to the
 * removed member inside one transaction. Only after COMMIT is the removed
 * member's account deleted — a failure there leaves an orphaned account,
 * never half-merged member data.
 *
 * Every column named member_id / *_member_id in another plugin's table
 * (avpvh-bookkeeping, avpvh-gallery, ...) is repointed generically; a
 * unique-key collision there aborts the whole merge rather than guessing
 * which of the two rows is right.
 */
class AVPVH_Member_Merge {

    /** Member columns offered as a per-field keep/remove choice. Name parts are chosen together as 'name'. */
    public const FIELDS = [
        'name'              => 'Naam',
        'passport_name'     => 'Naam in paspoort',
        'initials'          => 'Voorletters',
        'birth_date'        => 'Geboortedatum',
        'birth_year'        => 'Geboortejaar',
        'is_student'        => 'Student',
        'phone'             => 'Telefoon',
        'mobile'            => 'Mobiel',
        'emergency_contact' => 'Noodcontact',
        'diet'              => 'Dieet',
        'status'            => 'Status',
        'joined_year'       => 'Lid sinds',
        'left_year'         => 'Lid tot',
        'directory_consent' => 'Ledenlijst-toestemming',
        'share_email'       => 'E-mail delen',
        'share_phone'       => 'Telefoon delen',
        'share_address'     => 'Adres delen',
        'share_activity_history' => 'Activiteiten delen',
    ];

    public static function get_field_labels(): array {
        return [
            'name'                   => __('Naam', 'avpvh-members'),
            'passport_name'          => __('Naam in paspoort', 'avpvh-members'),
            'initials'               => __('Voorletters', 'avpvh-members'),
            'birth_date'             => __('Geboortedatum', 'avpvh-members'),
            'birth_year'             => __('Geboortejaar', 'avpvh-members'),
            'is_student'             => __('Student', 'avpvh-members'),
            'phone'                  => __('Telefoon', 'avpvh-members'),
            'mobile'                 => __('Mobiel', 'avpvh-members'),
            'emergency_contact'      => __('Noodcontact', 'avpvh-members'),
            'diet'                   => __('Dieet', 'avpvh-members'),
            'status'                 => __('Status', 'avpvh-members'),
            'joined_year'            => __('Lid sinds', 'avpvh-members'),
            'left_year'              => __('Lid tot', 'avpvh-members'),
            'directory_consent'      => __('Ledenlijst-toestemming', 'avpvh-members'),
            'share_email'            => __('E-mail delen', 'avpvh-members'),
            'share_phone'            => __('Telefoon delen', 'avpvh-members'),
            'share_address'          => __('Adres delen', 'avpvh-members'),
            'share_activity_history' => __('Activiteiten delen', 'avpvh-members'),
        ];
    }

    public static function get_field_label(string $field): string {
        $labels = self::get_field_labels();
        return $labels[$field] ?? (self::FIELDS[$field] ?? ucfirst($field));
    }

    // Privacy choices default to the most restrictive of the two rows — a
    // member who opted out on either record must stay opted out (AVG).
    private const RESTRICTIVE_FIELDS = ['directory_consent', 'share_email', 'share_phone', 'share_address', 'share_activity_history'];

    /** member-referencing columns in this plugin's own tables, each handled explicitly in execute(). */
    private const HANDLED_COLUMNS = [
        'avm_addresses.member_id',
        'avm_activity_participation.member_id',
        'avm_fees.member_id',
        'avm_member_identities.member_id',
        'avm_member_flag_assignments.member_id',
        'avm_relationships.member_id',
        'avm_relationships.related_member_id',
        'avm_member_audit_log.member_id',
        'avm_role_delegations.delegated_to_member_id',
        'avm_role_delegations.delegated_by_member_id',
        'avm_member_name_aliases.member_id',
    ];

    public static function get_raw_member(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_members WHERE id = %d",
            $id
        )) ?: null;
    }

    /** Every member, for the two pickers on the page — raw rows, so a member whose account is missing can still be merged away. */
    public static function get_member_options(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, first_name, suffix, last_name, status, birth_date, birth_year
             FROM {$wpdb->prefix}avm_members
             ORDER BY last_name, first_name, id"
        ) ?: [];
    }

    /**
     * Groups of 2+ members sharing a loose name key (accents and every
     * tussenvoegsel ignored, see AVPVH_Name_Matcher::loose_key()), through their
     * official name or a name alias (each row's matched_via says which) —
     * suggestions only, a human decides. A pair found through both routes
     * is listed once.
     */
    public static function find_duplicate_candidates(): array {
        $groups = [];
        foreach (AVPVH_DB::get_name_key_index(true) as $members) {
            if (count($members) < 2) {
                continue;
            }
            ksort($members);
            $signature = implode(',', array_keys($members));
            if (!isset($groups[$signature])) {
                $groups[$signature] = array_values($members);
            }
        }
        return array_values($groups);
    }

    /**
     * Everything the page needs to show before merging. 'blockers' are
     * reasons the merge can't run at all; 'warnings' need the admin's
     * attention but don't stop it.
     */
    public static function preview(int $keep_id, int $remove_id): array|\WP_Error {
        global $wpdb;
        $p = $wpdb->prefix;

        if ($keep_id < 1 || $remove_id < 1) {
            return new \WP_Error('missing', __('Kies twee leden.', 'avpvh-members'));
        }
        if ($keep_id === $remove_id) {
            return new \WP_Error('same', __('Je hebt twee keer hetzelfde lid gekozen.', 'avpvh-members'));
        }
        $keep = self::get_raw_member($keep_id);
        $remove = self::get_raw_member($remove_id);
        if (!$keep || !$remove) {
            return new \WP_Error('not_found', __('Een van beide leden bestaat niet (meer).', 'avpvh-members'));
        }

        $blockers = [];
        $warnings = [];

        if ($remove->wp_user_id !== null) {
            $blockers[] = $keep->wp_user_id === null
                ? __('Het lid dat verdwijnt heeft al eens ingelogd (gekoppelde WordPress-gebruiker). Wissel de richting om, zodat dat lid behouden blijft.', 'avpvh-members')
                : __('Beide leden hebben al eens ingelogd (allebei een gekoppelde WordPress-gebruiker). Dat kan deze pagina niet veilig samenvoegen.', 'avpvh-members');
        }

        $accounts = [];
        foreach (['keep' => $keep, 'remove' => $remove] as $side => $member) {
            $full = AVPVH_DB::get_member((int) $member->id);
            $groups = AVPVH_Directory::get_user_groups((string) $member->lldap_user_id);
            if (is_wp_error($groups)) {
                $warnings[] = sprintf(/* translators: %s: member name */ __('De groepen van %s konden niet worden opgehaald.', 'avpvh-members'), avpvh_format_name($member));
                $groups = [];
            }
            $accounts[$side] = [
                'uid'    => (string) $member->lldap_user_id,
                'exists' => $full !== null,
                'email'  => $full ? (string) $full->email : '',
                'groups' => AVPVH_Directory::only_pvh_groups($groups),
            ];
        }
        $missing_groups = array_values(array_diff($accounts['remove']['groups'], $accounts['keep']['groups']));
        if ($missing_groups) {
            $warnings[] = sprintf(
                /* translators: %s: comma-separated group names */ __('Het lid dat verdwijnt zit in groepen die het behouden lid niet heeft: %s. Die worden niet overgenomen — voeg ze zo nodig daarna toe via Ledendetail.', 'avpvh-members'),
                implode(', ', $missing_groups)
            );
        }
        if (!$accounts['remove']['exists']) {
            $warnings[] = __('Het lid dat verdwijnt heeft geen account (meer); er wordt dus ook geen account verwijderd.', 'avpvh-members');
        }
        $email = [
            'keep'   => self::real_email($accounts['keep']['email']),
            'remove' => self::real_email($accounts['remove']['email']),
        ];
        $email['default'] = $email['keep'] === '' && $email['remove'] !== '' ? 'remove' : 'keep';

        $fields = [];
        foreach (self::FIELDS as $field => $label) {
            $keep_value = self::field_display($keep, $field);
            $remove_value = self::field_display($remove, $field);
            if ($keep_value === $remove_value) {
                continue;
            }
            $fields[$field] = [
                'label'   => $label,
                'keep'    => $keep_value,
                'remove'  => $remove_value,
                'default' => self::default_choice($keep, $remove, $field),
            ];
        }

        $addresses = [
            'keep'   => AVPVH_DB::get_addresses($keep_id),
            'remove' => AVPVH_DB::get_addresses($remove_id),
        ];
        $keep_has_current = (bool) array_filter($addresses['keep'], fn($a) => empty($a->valid_until));
        $address_defaults = [];
        $address_overlaps = [];
        foreach ($addresses['remove'] as $address) {
            $duplicate = (bool) array_filter($addresses['keep'], fn($a) => self::address_key($a) === self::address_key($address));
            $address_defaults[(int) $address->id] = $duplicate ? 'delete' : ($keep_has_current && empty($address->valid_until) ? 'history' : 'move');
            $address_overlaps[(int) $address->id] = array_values(array_filter(
                $addresses['keep'],
                fn($keep_address) => self::address_periods_overlap($keep_address, $address)
            ));
        }

        $participation = [];
        foreach (AVPVH_DB::get_activities_for_member($remove_id) as $row) {
            $participation[] = [
                'name'     => $row->name . ' ' . $row->year,
                'conflict' => AVPVH_DB::get_participation($keep_id, (int) $row->id) !== null,
            ];
        }

        $fees = [];
        foreach (AVPVH_DB::get_fees_for_member($remove_id) as $fee) {
            $existing = AVPVH_DB::get_fee_for_year($keep_id, (int) $fee->year);
            $fees[] = [
                'year'     => (int) $fee->year,
                'status'   => (string) $fee->status,
                'conflict' => $existing !== null,
                'wins'     => self::fee_source_wins($existing, $fee),
            ];
        }

        $counts = [
            'Inlogadressen'          => self::count("{$p}avm_member_identities", 'member_id', $remove_id),
            'Kenmerken'              => self::count("{$p}avm_member_flag_assignments", 'member_id', $remove_id),
            'Relaties'               => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}avm_relationships WHERE member_id = %d OR related_member_id = %d",
                $remove_id, $remove_id
            )),
            'Naamvarianten'          => self::count("{$p}avm_member_name_aliases", 'member_id', $remove_id),
            'Wijzigingslog-regels'   => self::count("{$p}avm_member_audit_log", 'member_id', $remove_id),
            'Roldelegaties'          => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}avm_role_delegations WHERE delegated_to_member_id = %d OR delegated_by_member_id = %d",
                $remove_id, $remove_id
            )),
        ];
        $external = [];
        foreach (self::external_columns() as [$table, $column]) {
            $n = self::count($table, $column, $remove_id);
            if ($n > 0) {
                $external["$table.$column"] = $n;
            }
        }

        $preview = [
            'keep'             => $keep,
            'remove'           => $remove,
            'accounts'         => $accounts,
            'email'            => $email,
            'fields'           => $fields,
            'addresses'        => $addresses,
            'address_defaults' => $address_defaults,
            'address_overlaps' => $address_overlaps,
            'participation'    => $participation,
            'fees'             => $fees,
            'counts'           => array_filter($counts),
            'external'         => $external,
            'blockers'         => $blockers,
            'warnings'         => $warnings,
        ];
        $preview['fingerprint'] = self::fingerprint($preview);
        return $preview;
    }

    /**
     * $choices: ['fields' => [field => 'keep'|'remove'], 'addresses' => [id => 'move'|'history'|'close'|'delete'],
     * 'address_until' => [id => 'Y-m-d', for 'close'], 'email' => see resolve_email()].
     * Returns ['warnings' => post-commit problems, 'notes' => what happened to the e-mail addresses].
     */
    public static function execute(int $keep_id, int $remove_id, array $choices, string $fingerprint): array|\WP_Error {
        global $wpdb;
        $p = $wpdb->prefix;

        $preview = self::preview($keep_id, $remove_id);
        if (is_wp_error($preview)) {
            return $preview;
        }
        if ($preview['blockers']) {
            return new \WP_Error('blocked', implode(' ', $preview['blockers']));
        }
        if (!hash_equals($preview['fingerprint'], $fingerprint)) {
            return new \WP_Error('stale', __('De gegevens van een van beide leden zijn gewijzigd sinds je de vergelijking opende. Bekijk de vergelijking opnieuw.', 'avpvh-members'));
        }

        $keep = $preview['keep'];
        $remove = $preview['remove'];

        $email = self::resolve_email($preview['email'], $choices['email'] ?? []);
        if (is_wp_error($email)) {
            return $email;
        }
        $owner = $email['primary'] !== '' ? AVPVH_DB::get_identity_by_email($email['primary']) : null;
        if ($owner && !in_array((int) $owner->member_id, [$keep_id, $remove_id], true)) {
            return new \WP_Error('email_taken', sprintf(/* translators: %s: e-mail address */ __('%s is al een inlogadres van een ander lid.', 'avpvh-members'), $email['primary']));
        }

        $address_until = [];
        foreach ($preview['addresses']['remove'] as $address) {
            $address_id = (int) $address->id;
            if (($choices['addresses'][$address_id] ?? '') !== 'close') {
                continue;
            }
            $until = (string) ($choices['address_until'][$address_id] ?? '');
            $parsed = \DateTime::createFromFormat('!Y-m-d', $until);
            if (!$parsed || $parsed->format('Y-m-d') !== $until) {
                return new \WP_Error('address_until', sprintf(/* translators: %s: address */ __('Geef een geldige einddatum op voor %s.', 'avpvh-members'), self::format_address($address)));
            }
            if ($address->valid_from && $until < $address->valid_from) {
                return new \WP_Error('address_until', sprintf(/* translators: %s: address */ __('De einddatum voor %s ligt vóór de begindatum.', 'avpvh-members'), self::format_address($address)));
            }
            $address_until[$address_id] = $until;
        }

        $updates = [];
        foreach ($preview['fields'] as $field => $info) {
            $choice = $choices['fields'][$field] ?? $info['default'];
            if ($choice !== 'remove') {
                continue;
            }
            foreach (self::field_columns($field) as $column) {
                $updates[$column] = $remove->$column;
            }
        }
        $final = (object) array_merge((array) $keep, $updates);
        $final_key = AVPVH_Name_Matcher::normalize_person_name($final->first_name, $final->suffix, $final->last_name);

        $wpdb->query('START TRANSACTION');
        try {
            if ($updates) {
                self::check($wpdb->update("{$p}avm_members", $updates, ['id' => $keep_id]), 'lidgegevens bijwerken');
                foreach ($updates as $column => $value) {
                    AVPVH_DB::log_member_change($keep_id, $column, self::str($keep->$column), self::str($value));
                }
            }

            $today = current_time('Y-m-d');
            foreach ($preview['addresses']['remove'] as $address) {
                $address_id = (int) $address->id;
                $action = $choices['addresses'][$address_id] ?? $preview['address_defaults'][$address_id];
                if ($action === 'delete') {
                    self::check($wpdb->delete("{$p}avm_addresses", ['id' => $address_id]), 'adres verwijderen');
                    continue;
                }
                $data = ['member_id' => $keep_id];
                if ($action === 'history' && empty($address->valid_until)) {
                    $data['valid_until'] = $today;
                } elseif ($action === 'close' && isset($address_until[$address_id])) {
                    $data['valid_until'] = $address_until[$address_id];
                }
                self::check($wpdb->update("{$p}avm_addresses", $data, ['id' => $address_id]), 'adres overzetten');
            }

            self::merge_participation($keep_id, $remove_id);
            self::merge_fees($keep_id, $remove_id);

            self::check($wpdb->query($wpdb->prepare(
                "DELETE r FROM {$p}avm_member_flag_assignments r
                 JOIN {$p}avm_member_flag_assignments k ON k.flag_id = r.flag_id AND k.member_id = %d
                 WHERE r.member_id = %d",
                $keep_id, $remove_id
            )), 'dubbele kenmerken opruimen');
            self::repoint("{$p}avm_member_flag_assignments", 'member_id', $remove_id, $keep_id);

            if (self::count("{$p}avm_member_identities", 'member_id', $keep_id) > 0) {
                self::check($wpdb->update("{$p}avm_member_identities", ['is_primary' => 0], ['member_id' => $remove_id]), 'inlogadressen bijwerken');
            }
            self::repoint("{$p}avm_member_identities", 'member_id', $remove_id, $keep_id);

            self::merge_relationships($keep_id, $remove_id);
            self::merge_aliases($keep, $remove, $final_key);

            self::repoint("{$p}avm_member_audit_log", 'member_id', $remove_id, $keep_id);
            self::repoint("{$p}avm_role_delegations", 'delegated_to_member_id', $remove_id, $keep_id);
            self::repoint("{$p}avm_role_delegations", 'delegated_by_member_id', $remove_id, $keep_id);
            self::repoint("{$p}avm_members", 'emergency_member_id', $remove_id, $keep_id);
            // Whoever had one of the two as emergency contact now has the
            // merged member; the merged member itself can't be its own.
            self::check($wpdb->update("{$p}avm_members", ['emergency_member_id' => null], ['id' => $keep_id, 'emergency_member_id' => $keep_id]), 'noodcontact bijwerken');

            foreach (self::external_columns() as [$table, $column]) {
                self::repoint($table, $column, $remove_id, $keep_id);
            }

            if ($wpdb->delete("{$p}avm_members", ['id' => $remove_id], ['%d']) !== 1) {
                throw new \RuntimeException('verwijderen van het dubbele lid mislukt');
            }
            AVPVH_DB::log_member_change(
                $keep_id,
                'samenvoeging',
                'lid #' . $remove_id . ' (' . avpvh_format_name($remove) . ')',
                'samengevoegd in lid #' . $keep_id
            );
            $wpdb->query('COMMIT');
        } catch (\Throwable $exception) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('failed', sprintf(/* translators: %s: error message */ __('Samenvoegen afgebroken, er is niets gewijzigd: %s', 'avpvh-members'), $exception->getMessage()));
        }

        $warnings = [];
        $notes = [];
        $remove_account = $preview['accounts']['remove'];

        if ($remove_account['exists']) {
            $result = AVPVH_Directory::delete_user($remove_account['uid']);
            if (is_wp_error($result)) {
                $warnings[] = sprintf(
                    /* translators: 1: account name, 2: error message */ __('De leden zijn samengevoegd, maar het account "%1$s" kon niet worden verwijderd: %2$s', 'avpvh-members'),
                    $remove_account['uid'],
                    $result->get_error_message()
                );
            }
            AVPVH_Directory::forget_groups($remove_account['uid']);
        }

        // Only after the duplicate's account is gone: the directory may
        // refuse an address another account still holds.
        $old_email = $preview['email']['keep'];
        if ($email['primary'] !== '' && strcasecmp($email['primary'], $old_email) !== 0) {
            $result = AVPVH_Directory::update_user($preview['accounts']['keep']['uid'], ['mail' => $email['primary']]);
            if (is_wp_error($result)) {
                $warnings[] = sprintf(
                    /* translators: 1: e-mail address, 2: error message */ __('Het e-mailadres kon niet worden gewijzigd in %1$s: %2$s', 'avpvh-members'),
                    $email['primary'],
                    $result->get_error_message()
                );
            } else {
                AVPVH_DB::log_member_change($keep_id, 'email', $old_email, $email['primary']);
                $notes[] = sprintf(/* translators: %s: e-mail address */ __('Het e-mailadres is nu %s.', 'avpvh-members'), $email['primary']);
                if (!self::make_primary_identity($keep_id, $email['primary'])) {
                    $warnings[] = __('Het nieuwe e-mailadres kon niet als primair inlogadres worden vastgelegd (maximum bereikt?).', 'avpvh-members');
                }
            }
        }

        // The duplicate's address would otherwise vanish with its account —
        // keep it reachable as a (not yet verified) login address, unless
        // the admin said it was wrong.
        if ($email['extra'] !== '' && !AVPVH_DB::get_identity_by_email($email['extra'])) {
            if (AVPVH_DB::ensure_identity($keep_id, 'email', $email['extra'])) {
                $notes[] = sprintf(/* translators: %s: e-mail address */ __('%s is toegevoegd als extra (onbevestigd) inlogadres.', 'avpvh-members'), $email['extra']);
            } else {
                $warnings[] = __('Het e-mailadres van het verwijderde account kon niet als inlogadres worden toegevoegd (maximum bereikt?).', 'avpvh-members');
            }
        }

        return ['warnings' => $warnings, 'notes' => $notes];
    }

    /**
     * The kept member's contact e-mail after the merge ('primary', '' when
     * neither has one) and the duplicate's address to keep as an extra
     * login ('extra', '' for none). $choice: ['choice' => 'keep'|'remove'|'custom',
     * 'custom' => typed address, 'add_other' => keep the duplicate's address].
     */
    public static function resolve_email(array $email, array $choice): array|\WP_Error {
        $selected = $choice['choice'] ?? $email['default'];
        if ($selected === 'custom') {
            $primary = strtolower(trim((string) ($choice['custom'] ?? '')));
            if ($primary === '' || !is_email($primary) || self::real_email($primary) === '') {
                return new \WP_Error('email_invalid', __('Het gecorrigeerde e-mailadres is ongeldig.', 'avpvh-members'));
            }
        } else {
            $primary = $selected === 'remove' && $email['remove'] !== '' ? $email['remove'] : $email['keep'];
        }
        $extra = $email['remove'] !== '' && ($choice['add_other'] ?? true) && strcasecmp($email['remove'], $primary) !== 0
            ? $email['remove']
            : '';
        return ['primary' => $primary, 'extra' => $extra];
    }

    /** '' for an empty or @avpvh.local placeholder address. */
    public static function real_email(string $email): string {
        $email = trim($email);
        return $email === '' || str_ends_with(strtolower($email), '@avpvh.local') ? '' : $email;
    }

    private static function make_primary_identity(int $member_id, string $email): bool {
        foreach (AVPVH_DB::get_member_identities($member_id) as $identity) {
            if (strcasecmp((string) $identity->email, $email) === 0) {
                return AVPVH_DB::set_primary_identity($member_id, (int) $identity->id);
            }
        }
        return AVPVH_DB::ensure_identity($member_id, 'email', $email, true);
    }

    // -------------------------------------------------------------------

    private static function merge_participation(int $keep_id, int $remove_id): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}avm_activity_participation WHERE member_id = %d",
            $remove_id
        )) ?: [];
        foreach ($rows as $row) {
            $existing = AVPVH_DB::get_participation($keep_id, (int) $row->activity_id);
            if (!$existing) {
                self::check($wpdb->update("{$p}avm_activity_participation", ['member_id' => $keep_id], ['id' => (int) $row->id]), 'deelname overzetten');
                continue;
            }
            // Both took part in the same activity: keep the existing row,
            // filling only what it's missing from the duplicate.
            $fill = [];
            if ($existing->nights === null && $row->nights !== null) {
                $fill['nights'] = $row->nights;
            }
            if (!(int) $existing->nawacht && (int) $row->nawacht) {
                $fill['nawacht'] = $row->nawacht;
            }
            foreach (['diet', 'notes'] as $column) {
                if (trim((string) $existing->$column) === '' && trim((string) $row->$column) !== '') {
                    $fill[$column] = $row->$column;
                }
            }
            if ($fill) {
                self::check($wpdb->update("{$p}avm_activity_participation", $fill, ['id' => (int) $existing->id]), 'deelname aanvullen');
            }
            self::check($wpdb->query($wpdb->prepare(
                "DELETE d FROM {$p}avm_activity_participation_days d
                 JOIN {$p}avm_activity_participation_days k ON k.date = d.date AND k.participation_id = %d
                 WHERE d.participation_id = %d",
                (int) $existing->id, (int) $row->id
            )), 'dubbele deelnamedagen opruimen');
            self::repoint("{$p}avm_activity_participation_days", 'participation_id', (int) $row->id, (int) $existing->id);
            self::check($wpdb->delete("{$p}avm_activity_participation", ['id' => (int) $row->id]), 'dubbele deelname verwijderen');
        }
    }

    private static function merge_fees(int $keep_id, int $remove_id): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach (AVPVH_DB::get_fees_for_member($remove_id) as $fee) {
            $existing = AVPVH_DB::get_fee_for_year($keep_id, (int) $fee->year);
            if ($existing) {
                $loser = self::fee_source_wins($existing, $fee) ? (int) $existing->id : (int) $fee->id;
                self::check($wpdb->delete("{$p}avm_fees", ['id' => $loser]), 'dubbele contributie verwijderen');
                if ($loser === (int) $fee->id) {
                    continue;
                }
            }
            self::check($wpdb->update("{$p}avm_fees", ['member_id' => $keep_id], ['id' => (int) $fee->id]), 'contributie overzetten');
        }
    }

    /** A settled (paid/waived) fee beats a pending one for the same year; otherwise the kept member's row wins. */
    private static function fee_source_wins(?object $existing, object $fee): bool {
        return $existing !== null && $existing->status === 'pending' && $fee->status !== 'pending';
    }

    private static function merge_relationships(int $keep_id, int $remove_id): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}avm_relationships WHERE member_id = %d OR related_member_id = %d",
            $remove_id, $remove_id
        )) ?: [];
        foreach ($rows as $row) {
            $member = (int) $row->member_id === $remove_id ? $keep_id : (int) $row->member_id;
            $related = (int) $row->related_member_id === $remove_id ? $keep_id : (int) $row->related_member_id;
            $duplicate = $member === $related || (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}avm_relationships
                 WHERE member_id = %d AND related_member_id = %d AND label_id = %d AND id <> %d",
                $member, $related, (int) $row->label_id, (int) $row->id
            ));
            if ($duplicate) {
                self::check($wpdb->delete("{$p}avm_relationships", ['id' => (int) $row->id]), 'dubbele relatie verwijderen');
                continue;
            }
            self::check($wpdb->update(
                "{$p}avm_relationships",
                ['member_id' => $member, 'related_member_id' => $related],
                ['id' => (int) $row->id]
            ), 'relatie overzetten');
        }
    }

    /** Moves the duplicate's aliases over and records whichever name was dropped as a new alias, so imports keep finding this person by it. */
    private static function merge_aliases(object $keep, object $remove, string $final_key): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $keep_id = (int) $keep->id;
        $remove_id = (int) $remove->id;

        self::check($wpdb->query($wpdb->prepare(
            "DELETE r FROM {$p}avm_member_name_aliases r
             JOIN {$p}avm_member_name_aliases k ON k.normalized_key = r.normalized_key AND k.member_id = %d
             WHERE r.member_id = %d",
            $keep_id, $remove_id
        )), 'dubbele naamvarianten opruimen');
        self::repoint("{$p}avm_member_name_aliases", 'member_id', $remove_id, $keep_id);

        foreach ([$keep, $remove] as $member) {
            $key = AVPVH_Name_Matcher::normalize_person_name($member->first_name, $member->suffix, $member->last_name);
            if ($key === $final_key) {
                continue;
            }
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}avm_member_name_aliases WHERE member_id = %d AND normalized_key = %s",
                $keep_id, $key
            ));
            if ($exists) {
                continue;
            }
            self::check($wpdb->insert("{$p}avm_member_name_aliases", [
                'member_id'           => $keep_id,
                'first_name'          => $member->first_name,
                'suffix'              => $member->suffix,
                'last_name'           => $member->last_name,
                'alias_type'          => 'historical',
                'normalized_key'      => $key,
                // member_normalized's UNIQUE KEY is (member_id,
                // normalized_key_hash) — this column was never actually
                // populated anywhere, so every row silently kept the
                // column's '' default, and a member's *second* alias ever
                // (from a later, separate merge) collided with their
                // first on that index and aborted the whole merge.
                'normalized_key_hash' => hash('sha256', $key),
                'source'              => 'samenvoeging lid #' . $remove_id,
            ]), 'naamvariant toevoegen');
        }
    }

    /** [table, column] for every integer member_id / *_member_id column outside the explicitly handled set. */
    private static function external_columns(): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME LIKE %s
               AND (COLUMN_NAME = 'member_id' OR COLUMN_NAME LIKE %s)
               AND DATA_TYPE IN ('tinyint','smallint','mediumint','int','bigint')
             ORDER BY TABLE_NAME, COLUMN_NAME",
            $wpdb->esc_like($wpdb->prefix) . '%',
            '%' . $wpdb->esc_like('_member_id')
        )) ?: [];
        $columns = [];
        foreach ($rows as $row) {
            $short = substr($row->TABLE_NAME, strlen($wpdb->prefix)) . '.' . $row->COLUMN_NAME;
            if ($short === 'avm_members.id' || in_array($short, self::HANDLED_COLUMNS, true)) {
                continue;
            }
            $columns[] = [$row->TABLE_NAME, $row->COLUMN_NAME];
        }
        return $columns;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table/$column come from fixed names or information_schema, never from request input
    private static function count(string $table, string $column, int $id): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE `$column` = %d", $id));
    }

    private static function repoint(string $table, string $column, int $from, int $to): void {
        global $wpdb;
        $result = $wpdb->query($wpdb->prepare("UPDATE `$table` SET `$column` = %d WHERE `$column` = %d", $to, $from));
        if ($result === false) {
            throw new \RuntimeException("$table.$column kon niet worden overgezet ({$wpdb->last_error})"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped where it is shown
        }
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

    private static function check(int|bool|null $result, string $step): void {
        global $wpdb;
        if ($result === false) {
            throw new \RuntimeException("$step mislukt ({$wpdb->last_error})"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped where it is shown
        }
    }

    private static function field_columns(string $field): array {
        return match ($field) {
            'name'              => ['first_name', 'suffix', 'last_name'],
            'directory_consent' => ['directory_consent', 'directory_consent_at'],
            'emergency_contact' => ['emergency_contact', 'emergency_member_id', 'emergency_relation', 'emergency_phone', 'emergency_address'],
            default             => [$field],
        };
    }

    public static function field_display(object $member, string $field): string {
        return match ($field) {
            'name'       => avpvh_format_name($member),
            'is_student' => (int) $member->is_student ? 'ja' : 'nee',
            'emergency_contact' => self::emergency_display($member),
            default      => str_starts_with($field, 'share_')
                ? ((int) $member->$field ? 'ja' : 'nee')
                : self::str($member->$field),
        };
    }

    /**
     * Name (relation) · phone · address, empty parts left out. A linked
     * member (emergency_member_id) supplies all three from their own
     * record; the typed-in fields are only the fallback.
     */
    private static function emergency_display(object $member): string {
        [$name, $phone, $address] = [self::str($member->emergency_contact), self::str($member->emergency_phone ?? ''), self::str($member->emergency_address ?? '')];
        $linked = !empty($member->emergency_member_id) ? AVPVH_DB::get_member((int) $member->emergency_member_id) : null;
        if ($linked) {
            $a = AVPVH_DB::current_address((int) $linked->id);
            $name = avpvh_format_name($linked) . ' [lid]';
            $phone = self::str($linked->mobile) !== '' ? self::str($linked->mobile) : self::str($linked->phone);
            $address = $a ? trim(trim("{$a->street} {$a->house_number}") . ', ' . trim("{$a->postal_code} {$a->city}"), ' ,') : '';
        }
        $relation = self::str($member->emergency_relation ?? '');
        $name = trim($name . ($relation !== '' ? " ($relation)" : ''));
        return implode(' · ', array_filter([$name, $phone, $address], fn($part) => $part !== ''));
    }

    private static function default_choice(object $keep, object $remove, string $field): string {
        if (in_array($field, self::RESTRICTIVE_FIELDS, true)) {
            $restrictive = fn(object $m) => $field === 'directory_consent'
                ? ['declined' => 0, 'pending' => 1, 'granted' => 2][$m->$field] ?? 1
                : (int) $m->$field;
            return $restrictive($remove) < $restrictive($keep) ? 'remove' : 'keep';
        }
        if ($field === 'joined_year' && self::is_empty($keep->joined_year) === false && self::is_empty($remove->joined_year) === false) {
            return (int) $remove->joined_year < (int) $keep->joined_year ? 'remove' : 'keep';
        }
        if ($field === 'status') {
            $rank = ['inactive' => 0, 'visitor' => 1, 'active' => 2];
            return $rank[$remove->status] > $rank[$keep->status] ? 'remove' : 'keep';
        }
        foreach (self::field_columns($field) as $column) {
            if (!self::is_empty($keep->$column)) {
                return 'keep';
            }
        }
        return 'remove';
    }

    private static function is_empty(mixed $value): bool {
        return $value === null || $value === '' || $value === '0000-00-00';
    }

    private static function str(mixed $value): string {
        return $value === null ? '' : (string) $value;
    }

    /** Same-address key shared with the household check; '' (no street) never counts as a duplicate. */
    private static function address_key(object $address): string {
        return AVPVH_Address_Normalizer::match_key($address) ?: 'id:' . (int) $address->id;
    }

    private static function format_address(object $address): string {
        return trim("{$address->street} {$address->house_number}, {$address->postal_code} {$address->city}", ' ,');
    }

    /** Address dates are inclusive; an empty boundary means open-ended. */
    public static function address_periods_overlap(object $left, object $right): bool {
        $left_from   = $left->valid_from ?: '0000-01-01';
        $left_until  = $left->valid_until ?: '9999-12-31';
        $right_from  = $right->valid_from ?: '0000-01-01';
        $right_until = $right->valid_until ?: '9999-12-31';

        return $left_from <= $right_until && $right_from <= $left_until;
    }

    private static function fingerprint(array $preview): string {
        global $wpdb;
        $state = [
            (array) $preview['keep'],
            (array) $preview['remove'],
            [$preview['email']['keep'], $preview['email']['remove']],
            $preview['addresses'],
            $preview['participation'],
            $preview['fees'],
            $preview['counts'],
            $preview['external'],
            $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}avm_relationships WHERE member_id = %d OR related_member_id = %d ORDER BY id",
                (int) $preview['remove']->id, (int) $preview['remove']->id
            )),
        ];
        return hash('sha256', wp_json_encode($state));
    }
}
