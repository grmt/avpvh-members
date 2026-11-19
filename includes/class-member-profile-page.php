<?php
defined('ABSPATH') || exit;

/**
 * The page behind [avpvh_member_profile]: which profile a viewer gets and in
 * which role, the summary card and the profile form. The blocks below the
 * form are in AVPVH_Member_Profile_Sections; saving is in
 * AVPVH_Member_Profile_Form.
 *
 * Views: 'own' (the viewer's own profile), 'household' (a household member's,
 * see AVPVH_Member_Profile_Form::can_edit_member()), 'admin' (a member admin
 * on someone else's) and 'request_only' (a housemate's partner: only asking
 * them to verify an e-mail address).
 */
class AVPVH_Member_Profile_Page {

    /**
     * The member to show for ?member_id=$requested_id, and the view.
     *
     * @return array{0: ?object, 1: string}
     */
    public static function resolve(?object $own_member, int $requested_id): array {
        $view = 'own';
        if ($requested_id > 0 && AVPVH_Roles::can_manage_members()) {
            $view = 'admin';
        } elseif ($requested_id > 0 && $own_member && AVPVH_Member_Profile_Form::can_edit_member($own_member, $requested_id)) {
            $view = 'edit';
        } elseif ($requested_id > 0 && $own_member && AVPVH_Member_Profile_Form::can_request_identity_only($own_member, $requested_id)) {
            $view = 'request_only';
        }
        $member = $view === 'own' ? $own_member : AVPVH_DB::get_member($requested_id);
        if ($view === 'edit') {
            $view = $member && (int) $member->id === (int) $own_member->id ? 'own' : 'household';
        }
        return [$member, $view];
    }

    public static function render(object $member, ?object $own_member, string $view): void {
        if ($view === 'request_only') {
            self::render_request_only($member);
            return;
        }
        ?>
        <div class="avpvh-member-profile-form avpvh-member-profile-form--no-banner">
            <?php self::render_edit_note($member, $view); ?>

            <?php self::render_summary_card($member, $view === 'admin'); ?>

            <?php self::render_profile_form($member, $own_member, $view === 'admin'); ?>

            <?php AVPVH_Member_Profile_Sections::render_all($member, $view); ?>
        </div>
        <?php
    }

    private static function render_request_only(object $member): void {
        ?>
        <div class="avpvh-member-profile-form avpvh-member-profile-form--no-banner">
            <p class="avpvh-profile-note">
                <?php
                printf(
                    /* translators: %s: member name */
                    esc_html__('Je kunt hier een verzoek sturen aan %s om een e-mailadres te verifiëren. Andere gegevens van dit profiel kun je niet bewerken.', 'avpvh-members'),
                    '<strong>' . esc_html(avpvh_format_name($member)) . '</strong>'
                );
                ?>
            </p>
            <?php AVPVH_Member_Profile_Sections::render_identity_request_only($member); ?>
        </div>
        <?php
    }

    /** Whose profile an admin or household member is editing. */
    private static function render_edit_note(object $member, string $view): void {
        ?>
        <?php if ($view === 'admin') : ?>
            <p class="avpvh-profile-note">
                <?php
                printf(
                    /* translators: %s: member name */
                    esc_html__('Beheerder bewerkt: %s', 'avpvh-members'),
                    '<strong>' . esc_html(avpvh_format_name($member)) . '</strong>'
                );
                ?>
            </p>
        <?php elseif ($view === 'household') : ?>
            <p class="avpvh-profile-note">
                <?php
                printf(
                    /* translators: %s: member name */
                    esc_html__('Je bewerkt het profiel van: %s', 'avpvh-members'),
                    '<strong>' . esc_html(avpvh_format_name($member)) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
    }

    private static function render_summary_card(object $member, bool $is_admin_edit): void {
        $user = wp_get_current_user();
        $groups = self::member_groups($member);
        $role_lines = AVPVH_Roles::describe_member_roles((int) $member->id);
        [$housemates, $family_elsewhere, $extended_family] = self::household_groups($member);
        ?>
        <div class="avpvh-summary-card">
            <div class="avpvh-summary-card__name"><?php echo esc_html(avpvh_format_name($member)); ?></div>
            <div class="avpvh-summary-card__badges">
                <?php foreach (self::summary_badges($member) as $badge) : ?>
                    <span class="avpvh-badge"><?php echo esc_html($badge); ?></span>
                <?php endforeach; ?>
            </div>
            <div class="avpvh-summary-card__row">
                <span class="avpvh-summary-card__label"><?php esc_html_e('Ingelogd als:', 'avpvh-members'); ?></span>
                <?php echo esc_html($user->user_email); ?>
            </div>
            <?php if ($is_admin_edit) :
                $member_email = AVPVH_Member_Merge::real_email((string) ($member->email ?? ''));
            ?>
                <div class="avpvh-summary-card__row">
                    <span class="avpvh-summary-card__label"><?php esc_html_e('E-mail lid:', 'avpvh-members'); ?></span>
                    <?php echo $member_email !== '' ? esc_html($member_email) : '<em>' . esc_html__('geen', 'avpvh-members') . '</em>'; ?>
                    (<a href="#avpvh-admin-emails"><?php esc_html_e('beheren', 'avpvh-members'); ?></a>)
                </div>
            <?php endif; ?>
            <?php if ($groups) : ?>
                <div class="avpvh-summary-card__row">
                    <span class="avpvh-summary-card__label"><?php esc_html_e('Groepen:', 'avpvh-members'); ?></span>
                    <?php echo esc_html(implode(', ', $groups)); ?>
                </div>
            <?php endif; ?>
            <?php if ($role_lines) : ?>
                <div class="avpvh-summary-card__row">
                    <span class="avpvh-summary-card__label"><?php esc_html_e('Rollen:', 'avpvh-members'); ?></span>
                    <?php echo esc_html(implode(', ', $role_lines)); ?>
                </div>
            <?php endif; ?>
            <?php
            if (count($housemates) > 1) {
                self::render_member_links(__('Huisgenoten:', 'avpvh-members'), $housemates, (int) $member->id);
            }
            if ($family_elsewhere) {
                self::render_member_links(__('Familie (elders wonend):', 'avpvh-members'), $family_elsewhere, (int) $member->id);
            }
            if ($extended_family) {
                self::render_member_links(__('Ook verbonden (partner van familielid):', 'avpvh-members'), $extended_family, (int) $member->id, __('E-mailverificatie aanvragen', 'avpvh-members'));
            }
            ?>
        </div>
        <?php
    }

    /**
     * Badges describe $member (the profile being viewed), not the viewer: a
     * real WP admin viewing someone else's profile must never see their own
     * "Beheerder" badge appear on that person.
     */
    private static function summary_badges(object $member): array {
        $target_user = !empty($member->wp_user_id) ? get_userdata((int) $member->wp_user_id) : false;
        $member_role = $target_user ? (string) get_user_meta($target_user->ID, 'avpvh_member_role', true) : '';
        $status_label = match ($member->status) {
            'active'   => __('Actief lid', 'avpvh-members'),
            'inactive' => __('Oud lid', 'avpvh-members'),
            default    => __('Bezoeker', 'avpvh-members'),
        };
        return array_filter([
            $target_user ? self::role_label($target_user) : '',
            $member_role !== '' ? AVPVH_Roles::get_role_label($member_role) : '',
            $status_label,
        ]);
    }

    private static function role_label(\WP_User $user): string {
        if (empty($user->roles)) {
            return __('Gebruiker', 'avpvh-members');
        }

        return match ($user->roles[0]) {
            'administrator' => __('Beheerder', 'avpvh-members'),
            'editor'         => __('Redacteur', 'avpvh-members'),
            'author'         => __('Auteur', 'avpvh-members'),
            'contributor'    => __('Medewerker', 'avpvh-members'),
            'subscriber'     => __('Lid', 'avpvh-members'),
            default          => ucfirst(str_replace('_', ' ', $user->roles[0])),
        };
    }

    /** The member's pvh-* directory groups. */
    private static function member_groups(object $member): array {
        if (empty($member->user_id)) {
            return [];
        }
        $groups = AVPVH_Directory::get_user_groups($member->user_id);
        return is_wp_error($groups) ? [] : AVPVH_Directory::only_pvh_groups($groups);
    }

    /**
     * $member's own household (the person being viewed, never the viewer),
     * as [housemates, family living elsewhere, extended family].
     *
     * get_manageable_members() is "family OR same address": split back apart
     * for display, so someone who moved out (e.g. a grown-up child) shows as
     * family rather than as a housemate. Both stay editable; the split is
     * cosmetic. Extended family is one step further: a housemate's own
     * partner (e.g. a child's girlfriend), who can only be asked to verify an
     * e-mail address (can_request_identity_only()).
     */
    private static function household_groups(object $member): array {
        $household = AVPVH_DB::get_manageable_members((int) $member->id);
        $housemates = [];
        $family_elsewhere = [];
        foreach ($household as $hg) {
            if ((int) $hg->id === (int) $member->id || AVPVH_DB::has_same_address((int) $member->id, (int) $hg->id)) {
                $housemates[] = $hg;
            } else {
                $family_elsewhere[] = $hg;
            }
        }
        $household_ids = array_map('intval', wp_list_pluck($household, 'id'));
        $extended_family = array_values(array_filter(
            AVPVH_DB::get_extended_household((int) $member->id),
            fn($m) => !in_array((int) $m->id, $household_ids, true)
        ));
        return [$housemates, $family_elsewhere, $extended_family];
    }

    /** A summary row of names, each linking to that profile except $current_id's own. */
    private static function render_member_links(string $label, array $members, int $current_id, string $title = ''): void {
        ?>
        <div class="avpvh-summary-card__row">
            <span class="avpvh-summary-card__label"><?php echo esc_html($label); ?></span>
            <?php foreach (array_values($members) as $i => $m) : ?>
                <?php echo $i > 0 ? ', ' : ''; ?>
                <?php if ((int) $m->id === $current_id) : ?>
                    <strong><?php echo esc_html(avpvh_format_name($m)); ?></strong>
                <?php else : ?>
                    <a href="<?php echo esc_url(add_query_arg('member_id', $m->id)); ?>"<?php echo $title !== '' ? ' title="' . esc_attr($title) . '"' : ''; ?>><?php echo esc_html(avpvh_format_name($m)); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_profile_form(object $member, ?object $own_member, bool $is_admin_edit): void {
        ?>
        <form id="avpvh-profile-form" method="post">
            <?php wp_nonce_field('avpvh_member_profile', 'avpvh_nonce'); ?>
            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

            <?php
            self::render_personal_fields($member, $is_admin_edit);
            self::render_contact_fields($member);
            self::render_emergency_fields($member, $own_member);
            self::render_address_fields(self::current_address($member));
            self::render_preference_fields($member);
            ?>

            <button type="submit" class="button button-primary" disabled><?php esc_html_e('Profiel bijwerken', 'avpvh-members'); ?></button>
        </form>
        <?php
    }

    private static function render_personal_fields(object $member, bool $is_admin_edit): void {
        $mismatch = avpvh_initials_mismatch($member);
        ?>
        <fieldset class="avpvh-fields-grid">
            <legend><?php esc_html_e('Persoonlijke gegevens', 'avpvh-members'); ?></legend>

            <div class="form-group">
                <label for="first_name"><?php esc_html_e('Voornaam *', 'avpvh-members'); ?></label>
                <input type="text" id="first_name" name="first_name"
                    value="<?php echo esc_attr($member->first_name); ?>" required>
            </div>

            <div class="form-group">
                <label for="suffix"><?php esc_html_e('Tussenvoegsel', 'avpvh-members'); ?></label>
                <input type="text" id="suffix" name="suffix"
                    value="<?php echo esc_attr($member->suffix ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="last_name"><?php esc_html_e('Achternaam *', 'avpvh-members'); ?></label>
                <input type="text" id="last_name" name="last_name"
                    value="<?php echo esc_attr($member->last_name); ?>" required>
            </div>

            <div class="form-group">
                <label for="passport_name"><?php esc_html_e('Paspoortnaam', 'avpvh-members'); ?></label>
                <input type="text" id="passport_name" name="passport_name"
                    value="<?php echo esc_attr($member->passport_name ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="initials"><?php esc_html_e('Voorletters (zoals op de bankrekening)', 'avpvh-members'); ?></label>
                <input type="text" id="initials" name="initials"
                    value="<?php echo esc_attr($member->initials ?? ''); ?>" placeholder="<?php esc_attr_e('bv. S.J.M.', 'avpvh-members'); ?>">
                <?php if ($mismatch) : ?>
                    <p class="description" style="color:#b32d2e;font-weight:600">
                        <?php
                        printf(
                            /* translators: %s: calculated initials */
                            esc_html__('&#9888; Komt niet overeen met de paspoortnaam (die geeft %s).', 'avpvh-members'),
                            esc_html($mismatch)
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($is_admin_edit) {
                self::render_admin_personal_fields($member);
            } ?>

            <div class="form-group">
                <label for="diet"><?php esc_html_e('Eetgewoontes / allergieën', 'avpvh-members'); ?></label>
                <input type="text" id="diet" name="diet"
                    value="<?php echo esc_attr($member->diet ?? ''); ?>"
                    placeholder="<?php esc_attr_e('bv. vegetarisch, notenallergie', 'avpvh-members'); ?>">
            </div>
        </fieldset>
        <?php
    }

    /** Birth date (or only the year) and student status: member admins only. */
    private static function render_admin_personal_fields(object $member): void {
        $birth_value = !empty($member->birth_year) ? (string) $member->birth_year : '';
        if (!empty($member->birth_date)) {
            $birth_value = $member->birth_date;
        }
        ?>
        <div class="form-group">
            <label for="birth_date"><?php esc_html_e('Geboortedatum', 'avpvh-members'); ?></label>
            <input type="text" id="birth_date" name="birth_date" inputmode="numeric"
                pattern="\d{4}(-\d{2}-\d{2})?" placeholder="<?php esc_attr_e('JJJJ-MM-DD of alleen JJJJ', 'avpvh-members'); ?>"
                value="<?php echo esc_attr($birth_value); ?>">
            <p class="avpvh-field-hint"><?php esc_html_e('Volledige datum (JJJJ-MM-DD), of alleen het geboortejaar als de exacte datum niet bekend is.', 'avpvh-members'); ?></p>
        </div>
        <div class="form-group">
            <label for="is_student">
                <input type="checkbox" id="is_student" name="is_student" value="1" <?php checked(!empty($member->is_student)); ?>>
                <?php esc_html_e('Scholier/student', 'avpvh-members'); ?>
            </label>
        </div>
        <?php
    }

    private static function render_contact_fields(object $member): void {
        ?>
        <fieldset class="avpvh-fields-grid">
            <legend><?php esc_html_e('Contactgegevens', 'avpvh-members'); ?></legend>

            <div class="form-group">
                <label for="phone"><?php esc_html_e('Telefoon vast', 'avpvh-members'); ?></label>
                <input type="tel" id="phone" name="phone"
                    value="<?php echo esc_attr($member->phone); ?>">
            </div>

            <div class="form-group">
                <label for="mobile"><?php esc_html_e('Mobiel', 'avpvh-members'); ?></label>
                <input type="tel" id="mobile" name="mobile"
                    value="<?php echo esc_attr($member->mobile); ?>">
            </div>
        </fieldset>
        <?php
    }

    private static function render_emergency_fields(object $member, ?object $own_member): void {
        $emergency_member_id = (int) ($member->emergency_member_id ?? 0);
        $manual_hidden = $emergency_member_id ? ' hidden' : '';
        ?>
        <fieldset class="avpvh-fields-grid">
            <legend><?php esc_html_e('Noodcontact', 'avpvh-members'); ?></legend>
            <p class="avpvh-field-hint avpvh-fields-grid__full"><?php esc_html_e('Wie we bellen als er tijdens een activiteit iets met je gebeurt. Alleen zichtbaar voor jou, je huisgenoten en de beheerders.', 'avpvh-members'); ?></p>

            <div class="form-group avpvh-fields-grid__full">
                <label for="emergency_member_id"><?php esc_html_e('Lid van de vereniging', 'avpvh-members'); ?></label>
                <select id="emergency_member_id" name="emergency_member_id">
                    <option value=""><?php esc_html_e('— geen lid, vul hieronder in —', 'avpvh-members'); ?></option>
                    <?php foreach (AVPVH_Member_Profile_Form::emergency_candidates($member, $own_member) as $candidate_id => $candidate_name) : ?>
                        <option value="<?php echo esc_attr((string) $candidate_id); ?>" <?php selected($emergency_member_id, $candidate_id); ?>><?php echo esc_html($candidate_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="avpvh-field-hint"><?php esc_html_e('Is je noodcontact lid? Kies die persoon dan hier: we gebruiken de naam, het telefoonnummer en het adres uit diens profiel.', 'avpvh-members'); ?></p>
            </div>

            <div class="form-group avpvh-emergency-manual"<?php echo esc_attr($manual_hidden); ?>>
                <label for="emergency_contact"><?php esc_html_e('Naam', 'avpvh-members'); ?></label>
                <input type="text" id="emergency_contact" name="emergency_contact" maxlength="200"
                    value="<?php echo esc_attr($member->emergency_contact); ?>">
            </div>

            <div class="form-group">
                <label for="emergency_relation"><?php esc_html_e('Relatie', 'avpvh-members'); ?></label>
                <input type="text" id="emergency_relation" name="emergency_relation" maxlength="100"
                    placeholder="<?php esc_attr_e('bijv. partner, vader, buurvrouw', 'avpvh-members'); ?>"
                    value="<?php echo esc_attr($member->emergency_relation ?? ''); ?>">
            </div>

            <div class="form-group avpvh-emergency-manual"<?php echo esc_attr($manual_hidden); ?>>
                <label for="emergency_phone"><?php esc_html_e('Telefoon', 'avpvh-members'); ?></label>
                <input type="tel" id="emergency_phone" name="emergency_phone" maxlength="30"
                    value="<?php echo esc_attr($member->emergency_phone ?? ''); ?>">
            </div>

            <div class="form-group avpvh-emergency-manual"<?php echo esc_attr($manual_hidden); ?>>
                <label for="emergency_address"><?php esc_html_e('Adres', 'avpvh-members'); ?></label>
                <input type="text" id="emergency_address" name="emergency_address" maxlength="255"
                    placeholder="<?php esc_attr_e('straat, huisnummer, postcode, plaats', 'avpvh-members'); ?>"
                    value="<?php echo esc_attr($member->emergency_address ?? ''); ?>">
            </div>
        </fieldset>
        <?php
    }

    /** The address valid today, if any. */
    private static function current_address(object $member): ?object {
        $today = current_time('Y-m-d');
        foreach (AVPVH_DB::get_addresses($member->id) as $addr) {
            if ((!$addr->valid_from || $addr->valid_from <= $today) && (!$addr->valid_until || $addr->valid_until >= $today)) {
                return $addr;
            }
        }
        return null;
    }

    private static function render_address_fields(?object $current_address): void {
        ?>
        <fieldset class="avpvh-fields-grid" data-avpvh-address>
            <legend><?php esc_html_e('Adres', 'avpvh-members'); ?></legend>

            <div class="form-group">
                <label for="street"><?php esc_html_e('Straat', 'avpvh-members'); ?></label>
                <input type="text" id="street" name="street"
                    value="<?php echo esc_attr($current_address->street ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="house_number"><?php esc_html_e('Huisnummer', 'avpvh-members'); ?></label>
                <input type="text" id="house_number" name="house_number" inputmode="numeric"
                    value="<?php echo esc_attr($current_address->house_number ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="house_number_addition"><?php esc_html_e('Toevoeging', 'avpvh-members'); ?></label>
                <input type="text" id="house_number_addition" name="house_number_addition" maxlength="20"
                    placeholder="<?php esc_attr_e('bijv. A of 1 hoog', 'avpvh-members'); ?>"
                    value="<?php echo esc_attr($current_address->house_number_addition ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="postal_code"><?php esc_html_e('Postcode', 'avpvh-members'); ?></label>
                <input type="text" id="postal_code" name="postal_code"
                    value="<?php echo esc_attr($current_address->postal_code ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="city"><?php esc_html_e('Woonplaats', 'avpvh-members'); ?></label>
                <input type="text" id="city" name="city"
                    value="<?php echo esc_attr($current_address->city ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="country"><?php esc_html_e('Land', 'avpvh-members'); ?></label>
                <input type="text" id="country" name="country"
                    value="<?php echo esc_attr($current_address->country ?? 'Nederland'); ?>">
            </div>
        </fieldset>
        <?php
    }

    private static function render_preference_fields(object $member): void {
        $target_user_id = !empty($member->wp_user_id) ? (int) $member->wp_user_id : get_current_user_id();
        $current_user_locale = (string) get_user_meta($target_user_id, 'locale', true) ?: determine_locale();
        ?>
        <fieldset class="avpvh-fields-grid">
            <legend><?php esc_html_e('Voorkeuren', 'avpvh-members'); ?></legend>

            <div class="form-group">
                <label for="user_locale"><?php esc_html_e('Taal', 'avpvh-members'); ?></label>
                <select id="user_locale" name="user_locale">
                    <?php foreach (AVPVH_I18n::get_supported_locales() as $loc_code => $loc_name) : ?>
                        <option value="<?php echo esc_attr($loc_code); ?>" <?php selected($current_user_locale, $loc_code); ?>>
                            <?php echo esc_html($loc_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="avpvh-field-hint"><?php esc_html_e('Kies je gewenste taal voor de website.', 'avpvh-members'); ?></p>
            </div>
        </fieldset>
        <?php
    }
}
