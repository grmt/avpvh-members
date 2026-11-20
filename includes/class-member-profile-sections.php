<?php
defined('ABSPATH') || exit;

/**
 * The blocks below the profile form on [avpvh_member_profile]: login
 * addresses, relationships, directory and newsletter consent, blogging, the
 * change history and the membership request. See AVPVH_Member_Profile_Page.
 */
class AVPVH_Member_Profile_Sections {

    /** Everything below the profile form, per view (see AVPVH_Member_Profile_Page). */
    public static function render_all(object $member, string $view): void {
        if ($view === 'admin') {
            self::render_admin_emails($member);
        } else {
            self::render_identities($member, $view === 'household');
        }
        self::render_relationships($member);
        if ($view !== 'admin') {
            self::render_directory_consent($member);
            self::render_newsletter_consent($member);
        }
        if ($view === 'own' && AVPVH_Roles::member_is_blogger((int) $member->id)) {
            self::render_blogging();
        }
        echo "\n<!-- Audit Trail -->\n";
        self::render_audit_trail($member);
        if ($view === 'own' && $member->status === 'visitor') {
            self::render_membership_request();
        }
    }

    /**
     * Self-service e-mail/identity management: shows the member's login
     * identities (up to 3 in total, any mix of providers — e.g. two
     * Google-verified addresses is fine), lets them verify-and-add a
     * Google/Microsoft account or a plain e-mail address (via a one-time
     * confirmation link — see class-email-identity.php), and remove one
     * (with a warning if it's the address they're currently logged in
     * with). Removing is only allowed while at least 2 *verified*
     * identities remain — an admin-added, never-verified extra doesn't
     * count as a safe fallback (see AVPVH_DB::ensure_identity()'s
     * $verified param).
     *
     * The "verify and add" links are self-only: completing an add is only
     * proof of *whoever completes it*, not proof that it's actually
     * $member. A household member editing someone else's profile (e.g. a
     * spouse) could otherwise attach their own account as a valid login for
     * that person. Household members can still remove an existing identity
     * here (that's just as visible either way, since it e-mails both
     * parties), just not add a new one on someone else's behalf.
     */
    public static function render_identities(object $member, bool $is_household_edit = false): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        $current_email = wp_get_current_user()->user_email;
        ?>
        <fieldset class="avpvh-identities">
            <legend><?php esc_html_e('Inlog-e-mailadressen', 'avpvh-members'); ?></legend>
            <p>
                <?php esc_html_e('Je kunt tot drie e-mailadressen koppelen om mee in te loggen, in elke combinatie van methodes. Om een adres toe te voegen moet je er daadwerkelijk mee inloggen, zodat we weten dat het echt van jou is.', 'avpvh-members'); ?>
            </p>

            <?php self::render_identity_notice($member); ?>

            <table class="avpvh-identities-table">
                <thead class="screen-reader-text"><tr><th scope="col"><?php esc_html_e('E-mailadres', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Inlogmethode', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Verificatie', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Primair', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Acties', 'avpvh-members'); ?></th></tr></thead>
                <?php
                foreach ($identities as $identity) {
                    self::render_identity_row($member, $identity, $identities, strcasecmp($identity->email, $current_email) === 0);
                }
                ?>
                <?php if (!$identities) : ?>
                    <tr><td colspan="5"><em><?php esc_html_e('Nog geen e-mailadressen gekoppeld.', 'avpvh-members'); ?></em></td></tr>
                <?php endif; ?>
            </table>

            <?php
            if (count($identities) < 3 && $is_household_edit) {
                self::render_identity_request_form($member);
            } elseif (count($identities) < 3) {
                self::render_identity_add_options($member);
            }
            ?>
        </fieldset>
        <?php
    }

    private static function provider_label(string $provider): string {
        $labels = [
            'email'     => __('E-mail (link)', 'avpvh-members'),
            'google'    => 'Google',
            'microsoft' => 'Microsoft',
        ];
        return $labels[$provider] ?? $provider;
    }

    private static function render_identity_row(object $member, object $identity, array $identities, bool $is_current_login): void {
        ?>
                    <tr>
                        <td><?php echo esc_html($identity->email); ?></td>
                        <td><?php echo esc_html(self::provider_label((string) $identity->provider)); ?></td>
                        <td>
                            <?php if (empty($identity->verified_at)) : ?>
                                <span class="avpvh-identity-unverified" title="<?php esc_attr_e('Toegevoegd door een beheerder, niet zelf geverifieerd', 'avpvh-members'); ?>"><?php esc_html_e('Niet geverifieerd', 'avpvh-members'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $identity->is_primary ? esc_html__('Primair', 'avpvh-members') : ''; ?></td>
                        <td>
                            <?php if (!$identity->is_primary) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                                title="<?php esc_attr_e('Wordt ook het contactadres van je account, los van waarmee je inlogt.', 'avpvh-members'); ?>">
                                <?php wp_nonce_field('avpvh_make_primary_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_make_primary_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e('Maak primair', 'avpvh-members'); ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if (AVPVH_DB::can_delete_identity($identities, (int) $identity->id)) :
                                $confirm_msg = $is_current_login
                                    ? __('Let op: dit is het adres waarmee je nu bent ingelogd. Als je het verwijdert, kun je daar niet meer mee inloggen. Doorgaan?', 'avpvh-members')
                                    : __('Dit e-mailadres verwijderen?', 'avpvh-members');
                            ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                                onsubmit="return confirm('<?php echo esc_js($confirm_msg); ?>');">
                                <?php wp_nonce_field('avpvh_remove_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_remove_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button"><?php esc_html_e('Verwijderen', 'avpvh-members'); ?></button>
                            </form>
                            <?php else : ?>
                            <span title="<?php esc_attr_e('Je hebt maar één geverifieerd adres — voeg eerst een tweede toe om te kunnen verwijderen', 'avpvh-members'); ?>">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
        <?php
    }

    /** Ask $member by e-mail to verify an address themselves (household and request-only views). */
    private static function render_identity_request_form(object $member): void {
        ?>
                <p class="avpvh-identities-add">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avpvh_request_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_request_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                        <button type="submit" class="button"><?php printf(/* translators: %s: first name */ esc_html__('Vraag %s om een e-mailadres te verifiëren', 'avpvh-members'), esc_html($member->first_name)); ?></button>
                    </form>
                    <small>
                        <?php printf(/* translators: %s: first name */ esc_html__('Toevoegen kan alleen door %s zelf (moet zelf inloggen om te verifiëren dat het adres echt van diegene is) — dit stuurt een e-mail met instructies.', 'avpvh-members'), esc_html($member->first_name)); ?>
                    </small>
                </p>
        <?php
    }

    /** Verify-and-add a login address on one's own profile: OAuth providers or an e-mail link. */
    private static function render_identity_add_options(object $member): void {
        ?>
                <div class="avpvh-identities-add">
                    <p class="avpvh-identities-add-intro"><?php esc_html_e('Voeg een e-mailadres toe en verifieer het:', 'avpvh-members'); ?></p>

                    <?php foreach (AVPVH_OAuth::configured_providers() as $key => $provider) : ?>
                        <div class="avpvh-identities-add-option">
                            <a class="button" href="<?php echo esc_url(AVPVH_OAuth::add_identity_url($key, (int) $member->id)); ?>">
                                <?php printf(/* translators: %s: login provider, e.g. Google */ esc_html__('Met %s', 'avpvh-members'), esc_html($provider['label'])); ?>
                            </a>
                            <small class="avpvh-field-hint"><?php printf(/* translators: %s: login provider, e.g. Google */ esc_html__('Handig als je browser al bij %s is ingelogd — dan hoef je geen wachtwoord in te typen.', 'avpvh-members'), esc_html($provider['label'])); ?></small>
                        </div>
                    <?php endforeach; ?>

                    <div class="avpvh-identities-add-option">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avpvh_start_email_identity'); ?>
                            <input type="hidden" name="action" value="avpvh_start_email_identity">
                            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                            <label class="screen-reader-text" for="avpvh-new-identity-email"><?php esc_html_e('E-mailadres', 'avpvh-members'); ?></label>
                            <input type="email" id="avpvh-new-identity-email" name="email" placeholder="naam@voorbeeld.nl" required>
                            <button type="submit" class="button"><?php esc_html_e('Met een e-maillink', 'avpvh-members'); ?></button>
                        </form>
                        <small class="avpvh-field-hint"><?php esc_html_e('Voor adressen zonder Google- of Microsoft-account — je krijgt een bevestigingslink toegestuurd om te verifiëren.', 'avpvh-members'); ?></small>
                    </div>
                </div>
        <?php
    }

    /**
     * Stripped-down version of render_identities() for the extended-household
     * case (can_request_identity_only(), e.g. a housemate's partner living
     * elsewhere): just the request button, no identities table — someone
     * outside the household proper shouldn't see the member's existing
     * verified e-mail addresses, only be able to ask for a new one.
     */
    public static function render_identity_request_only(object $member): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        ?>
        <fieldset class="avpvh-identities">
            <legend><?php esc_html_e('Inlog-e-mailadres verifiëren', 'avpvh-members'); ?></legend>

            <?php if (!empty($_GET['identity_requested'])) : ?>
                <p class="avpvh-identity-notice"><?php printf(/* translators: %s: first name */ esc_html__('Verzoek verstuurd — %s heeft een e-mail gekregen met instructies.', 'avpvh-members'), esc_html($member->first_name)); ?></p>
            <?php endif; ?>

            <?php if (count($identities) < 3) : ?>
                <?php self::render_identity_request_form($member); ?>
            <?php else : ?>
                <p><em><?php printf(/* translators: %s: first name */ esc_html__('%s heeft al het maximum van drie inlog-e-mailadressen.', 'avpvh-members'), esc_html($member->first_name)); ?></em></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Family/partner/guardian relationships — see AVPVH_DB's "Relationships"
     * section for the data model. Shown on the profile in every view and on
     * Ledendetail. A relationship can give rights over another member (see
     * AVPVH_DB::is_family_member()), so only the board adds or removes them;
     * members see theirs.
     */
    public static function render_relationships(object $member): void {
        $relationships = AVPVH_DB::get_relationships((int) $member->id);
        $can_manage = AVPVH_Roles::can_manage_members();
        $needs_step_up = $can_manage && class_exists('AVPVH_Access') && !AVPVH_Access::current_session_has_2fa();
        $can_manage = $can_manage && !$needs_step_up;
        ?>
        <fieldset class="avpvh-relationships">
            <legend><?php esc_html_e('Relaties', 'avpvh-members'); ?></legend>

            <?php self::render_relationship_notice(); ?>

            <table class="avpvh-identities-table">
                <thead class="screen-reader-text"><tr><th scope="col"><?php esc_html_e('Relatie', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Periode', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Acties', 'avpvh-members'); ?></th></tr></thead>
                <?php
                foreach ($relationships as $rel) {
                    self::render_relationship_row($member, $rel, $can_manage);
                }
                ?>
                <?php if (!$relationships) : ?>
                    <tr><td colspan="3"><em><?php esc_html_e('Nog geen relaties vastgelegd.', 'avpvh-members'); ?></em></td></tr>
                <?php endif; ?>
            </table>

            <?php if ($needs_step_up) : ?>
                <p class="avpvh-field-hint"><a href="<?php echo esc_url(admin_url()); ?>"><?php esc_html_e('Log in met tweestapsverificatie om relaties te wijzigen.', 'avpvh-members'); ?></a></p>
            <?php elseif (!$can_manage) : ?>
                <p class="avpvh-field-hint"><?php esc_html_e('Klopt er iets niet of mist er een relatie? Het bestuur past relaties aan.', 'avpvh-members'); ?></p>
            <?php else : ?>
                <?php self::render_relationship_add_form($member); ?>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /** The one-off notice after adding or removing a relationship. */
    private static function render_relationship_notice(): void {
        $notices = [
            'relationship_added'     => [__('Relatie toegevoegd.', 'avpvh-members'), ''],
            'relationship_removed'   => [__('Relatie verwijderd.', 'avpvh-members'), ''],
            'relationship_duplicate' => [__('Deze relatie staat al hieronder — niet nogmaals toegevoegd.', 'avpvh-members'), ' avpvh-identity-notice--error'],
            'relationship_error'     => [__('Opslaan is niet gelukt — probeer het opnieuw.', 'avpvh-members'), ' avpvh-identity-notice--error'],
        ];
        foreach ($notices as $arg => [$text, $modifier]) {
            if (!empty($_GET[$arg])) {
                echo '<p class="avpvh-identity-notice' . esc_attr($modifier) . '">' . esc_html($text) . '</p>';
                return;
            }
        }
    }

    private static function render_relationship_row(object $member, object $rel, bool $can_manage): void {
        ?>
                    <tr>
                        <td>
                            <strong><?php echo $rel->other_member ? esc_html(avpvh_format_name($rel->other_member)) : esc_html__('(onbekend lid)', 'avpvh-members'); ?></strong>
                            <?php esc_html_e('is', 'avpvh-members'); ?> <?php echo esc_html($rel->label); ?>
                            <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong>
                        </td>
                        <td>
                            <?php if ($rel->valid_from || $rel->valid_until) : ?>
                                <?php echo esc_html(trim(($rel->valid_from ?: '…') . ' – ' . ($rel->valid_until ?: '…'))); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($can_manage) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                onsubmit="return confirm('<?php echo esc_js(__('Deze relatie verwijderen?', 'avpvh-members')); ?>');">
                                <?php wp_nonce_field('avpvh_remove_relationship'); ?>
                                <input type="hidden" name="action" value="avpvh_remove_relationship">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="relationship_id" value="<?php echo esc_attr($rel->id); ?>">
                                <button type="submit" class="button"><?php esc_html_e('Verwijderen', 'avpvh-members'); ?></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
        <?php
    }

    /** The board's form to add a relationship; loads the member list only for them. */
    private static function render_relationship_add_form(object $member): void {
        $labels = AVPVH_DB::get_relationship_labels();
        $all_members = array_filter(
            AVPVH_DB::get_members(),
            fn($m) => (int) $m->id !== (int) $member->id
        );
        usort($all_members, fn($a, $b) => strcasecmp($a->last_name, $b->last_name) ?: strcasecmp($a->first_name, $b->first_name));
        $activities = AVPVH_DB::get_activities();
        ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avpvh-relationship-add">
                <?php wp_nonce_field('avpvh_add_relationship'); ?>
                <input type="hidden" name="action" value="avpvh_add_relationship">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label for="rel_related_member_id"><?php esc_html_e('Persoon', 'avpvh-members'); ?></label>
                    <select id="rel_related_member_id" name="related_member_id" required>
                        <option value="">— <?php esc_html_e('Kies', 'avpvh-members'); ?> —</option>
                        <?php foreach ($all_members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>" data-avpvh-plain="1"><?php echo esc_html(avpvh_format_name($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="screen-reader-text" for="rel_related_member_filter"><?php esc_html_e('Filter op naam', 'avpvh-members'); ?></label>
                    <input type="text" id="rel_related_member_filter" placeholder="<?php echo esc_attr__('Filter op naam&hellip;', 'avpvh-members'); ?>" autocomplete="off" style="width:9em;margin-left:.3rem;font-size:.85em;padding:.1rem .3rem;box-sizing:border-box">
                    <script type="application/json" id="rel_related_member_data"><?php echo wp_json_encode(array_map(fn($m) => [
                        'id'    => (int) $m->id,
                        'label' => avpvh_format_name($m),
                        'first' => $m->first_name,
                        'last'  => $m->last_name,
                    ], $all_members)); ?></script>
                    <script>
                    (function () {
                        var select = document.getElementById('rel_related_member_id');
                        var input = document.getElementById('rel_related_member_filter');
                        var members = JSON.parse(document.getElementById('rel_related_member_data').textContent);
                        if (!select || !input) return;
                        function apply() {
                            var term = input.value.trim().toLowerCase();
                            var selectedValue = select.value;
                            Array.prototype.slice.call(select.querySelectorAll('option[data-avpvh-plain]')).forEach(function (opt) {
                                opt.remove();
                            });
                            members.forEach(function (m) {
                                if (term !== ''
                                    && (m.first || '').toLowerCase().indexOf(term) === -1
                                    && (m.last || '').toLowerCase().indexOf(term) === -1) return;
                                var opt = document.createElement('option');
                                opt.value = m.id;
                                opt.textContent = m.label;
                                opt.setAttribute('data-avpvh-plain', '1');
                                select.appendChild(opt);
                            });
                            select.value = selectedValue;
                        }
                        input.addEventListener('input', apply);
                    })();
                    </script>
                </div>

                <div class="form-group">
                    <label for="rel_label_id"><?php esc_html_e('is', 'avpvh-members'); ?></label>
                    <select id="rel_label_id" name="label_id" required>
                        <?php foreach ($labels as $label) : ?>
                            <option value="<?php echo esc_attr($label->id); ?>"><?php echo esc_html($label->label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="avpvh-field-hint"><?php printf(/* translators: %s: member name */ esc_html__('…van %s.', 'avpvh-members'), esc_html(avpvh_format_name($member))); ?></p>
                </div>

                <div class="form-group">
                    <label for="rel_activity_id"><?php esc_html_e('Periode: activiteit (optioneel — bijv. tijdelijke voogdij tijdens een kamp)', 'avpvh-members'); ?></label>
                    <select id="rel_activity_id" name="activity_id">
                        <option value="">— <?php esc_html_e('Geen, handmatige data hieronder', 'avpvh-members'); ?> —</option>
                        <?php foreach ($activities as $activity) : ?>
                            <option value="<?php echo esc_attr($activity->id); ?>">
                                <?php echo esc_html(($activity->type_name ? $activity->type_name . ': ' : '') . $activity->name . ' (' . $activity->year . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="rel_valid_from"><?php esc_html_e('Geldig van (handmatig, tenzij een activiteit is gekozen hierboven)', 'avpvh-members'); ?></label>
                    <input type="date" id="rel_valid_from" name="valid_from">
                </div>

                <div class="form-group">
                    <label for="rel_valid_until"><?php esc_html_e('Geldig tot (handmatig, tenzij een activiteit is gekozen hierboven)', 'avpvh-members'); ?></label>
                    <input type="date" id="rel_valid_until" name="valid_until">
                </div>

                <button type="submit" class="button"><?php esc_html_e('Relatie toevoegen', 'avpvh-members'); ?></button>
            </form>
        <?php
    }

    /** The one-off notice after an e-mail/identity action, from the query string. */
    private static function render_identity_notice(object $member): void {
        ?>
            <?php if (!empty($_GET['identity_added'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('E-mailadres toegevoegd.', 'avpvh-members'); ?></p>
            <?php elseif (!empty($_GET['identity_error'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error">
                    <?php
                    $errors = [
                        'in_use'        => __('Dat e-mailadres is al aan een ander lid gekoppeld.', 'avpvh-members'),
                        'not_you'       => __('Er ging iets mis met de verificatie — probeer het opnieuw.', 'avpvh-members'),
                        'limit'         => __('Dit adres kon niet worden toegevoegd (maximaal drie).', 'avpvh-members'),
                        'invalid_email' => __('Dat is geen geldig e-mailadres.', 'avpvh-members'),
                        'too_many_mails' => __('Er zijn het afgelopen uur al te veel bevestigingsmails verstuurd. Probeer het later opnieuw.', 'avpvh-members'),
                        'last_identity' => __('Dit is je enige geverifieerde inlogadres en kan niet weg. Voeg eerst een ander adres toe en log daar één keer mee in.', 'avpvh-members'),
                    ];
                    echo esc_html($errors[sanitize_key(wp_unslash($_GET['identity_error']))] ?? __('Er ging iets mis.', 'avpvh-members'));
                    ?>
                </p>
            <?php elseif (!empty($_GET['identity_removed'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('E-mailadres verwijderd.', 'avpvh-members'); ?></p>
            <?php elseif (!empty($_GET['identity_primary'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('Primair e-mailadres gewijzigd.', 'avpvh-members'); ?></p>
            <?php elseif (!empty($_GET['identity_requested'])) : ?>
                <p class="avpvh-identity-notice"><?php printf(/* translators: %s: first name */ esc_html__('Verzoek verstuurd — %s heeft een e-mail gekregen met instructies.', 'avpvh-members'), esc_html($member->first_name)); ?></p>
            <?php elseif (!empty($_GET['identity_email_sent'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('Bevestigingslink verstuurd — check je inbox om het adres te koppelen.', 'avpvh-members'); ?></p>
            <?php elseif (!empty($_GET['email_updated'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('Contactadres gewijzigd.', 'avpvh-members'); ?></p>
            <?php elseif (!empty($_GET['email_error'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error"><?php esc_html_e('Contactadres niet gewijzigd: geen geldig e-mailadres, of de directory gaf een fout.', 'avpvh-members'); ?></p>
            <?php endif; ?>

        <?php
    }

    /**
     * The e-mail block a member admin sees on someone else's profile
     * (?member_id=), in place of the self-service render_identities(): the
     * account's contact address, and the login addresses. Making one primary
     * or removing one goes through the same handlers as self-service (they
     * already allow member admins and e-mail the change); setting the contact
     * address and adding an unverified login address go through Ledendetail's
     * handlers in AVPVH_Admin, which send the admin back here.
     */
    private static function render_admin_emails(object $member): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        $contact = AVPVH_Member_Merge::real_email((string) ($member->email ?? ''));
        $post_url = admin_url('admin-post.php');
        $provider_labels = [
            'email'     => __('E-mail (link)', 'avpvh-members'),
            'google'    => 'Google',
            'microsoft' => 'Microsoft',
        ];
        ?>
        <fieldset class="avpvh-identities" id="avpvh-admin-emails">
            <legend><?php esc_html_e('E-mailadressen', 'avpvh-members'); ?></legend>
            <?php self::render_identity_notice($member); ?>

            <p><strong><?php esc_html_e('Contactadres', 'avpvh-members'); ?></strong></p>
            <form method="post" action="<?php echo esc_url($post_url); ?>"
                onsubmit="return confirm('<?php echo esc_js(__('Contactadres van het account wijzigen?', 'avpvh-members')); ?>');">
                <?php wp_nonce_field('avpvh_update_email'); ?>
                <input type="hidden" name="action" value="avpvh_update_email">
                <input type="hidden" name="avpvh_return" value="profile">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                <label class="screen-reader-text" for="avpvh-admin-contact-email"><?php esc_html_e('Contactadres', 'avpvh-members'); ?></label>
                <input type="email" id="avpvh-admin-contact-email" name="email" value="<?php echo esc_attr($contact); ?>" placeholder="naam@voorbeeld.nl">
                <button type="submit" class="button"><?php esc_html_e('Wijzigen', 'avpvh-members'); ?></button>
            </form>
            <small class="avpvh-field-hint"><?php esc_html_e('Hier stuurt de vereniging e-mail naartoe. Het volgt het primaire inlogadres als je dat hieronder wijzigt. Leeg laten en opslaan haalt het adres weg.', 'avpvh-members'); ?></small>

            <p><strong><?php esc_html_e('Inlogadressen', 'avpvh-members'); ?></strong></p>
            <table class="avpvh-identities-table">
                <thead class="screen-reader-text"><tr><th scope="col"><?php esc_html_e('E-mailadres', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Inlogmethode', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Verificatie', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Primair', 'avpvh-members'); ?></th><th scope="col"><?php esc_html_e('Acties', 'avpvh-members'); ?></th></tr></thead>
                <?php foreach ($identities as $identity) : ?>
                    <tr>
                        <td><?php echo esc_html($identity->email); ?></td>
                        <td><?php echo esc_html($provider_labels[$identity->provider] ?? $identity->provider); ?></td>
                        <td><?php echo empty($identity->verified_at) ? '<span class="avpvh-identity-unverified">' . esc_html__('Niet geverifieerd', 'avpvh-members') . '</span>' : ''; ?></td>
                        <td><?php echo $identity->is_primary ? esc_html__('Primair', 'avpvh-members') : ''; ?></td>
                        <td>
                            <?php if (!$identity->is_primary) : ?>
                            <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline-block">
                                <?php wp_nonce_field('avpvh_make_primary_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_make_primary_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e('Maak primair', 'avpvh-members'); ?></button>
                            </form>
                            <?php endif; ?>
                            <?php if (AVPVH_DB::can_delete_identity($identities, (int) $identity->id)) : ?>
                            <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline-block"
                                onsubmit="return confirm('<?php echo esc_js(__('Dit e-mailadres verwijderen? Het lid kan er dan niet meer mee inloggen.', 'avpvh-members')); ?>');">
                                <?php wp_nonce_field('avpvh_remove_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_remove_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button button-small"><?php esc_html_e('Verwijderen', 'avpvh-members'); ?></button>
                            </form>
                            <?php else : ?>
                            <span title="<?php esc_attr_e('Het enige geverifieerde inlogadres kan niet weg.', 'avpvh-members'); ?>">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$identities) : ?>
                    <tr><td colspan="5"><em><?php esc_html_e('Nog geen e-mailadressen gekoppeld.', 'avpvh-members'); ?></em></td></tr>
                <?php endif; ?>
            </table>

            <?php if (count($identities) < 3) : ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="avpvh-identities-add">
                <?php wp_nonce_field('avpvh_add_identity'); ?>
                <input type="hidden" name="action" value="avpvh_add_identity">
                <input type="hidden" name="avpvh_return" value="profile">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                <label class="screen-reader-text" for="avpvh-admin-new-identity"><?php esc_html_e('Nieuw inlogadres', 'avpvh-members'); ?></label>
                <input type="email" id="avpvh-admin-new-identity" name="email" placeholder="naam@voorbeeld.nl" required>
                <button type="submit" class="button"><?php esc_html_e('Inlogadres koppelen', 'avpvh-members'); ?></button>
                <small class="avpvh-field-hint"><?php esc_html_e('Wordt als niet-geverifieerd toegevoegd; de inlogmethode staat vast zodra het lid er zelf mee inlogt.', 'avpvh-members'); ?></small>
            </form>
            <?php endif; ?>

            <p><small><a href="<?php echo esc_url(add_query_arg(['page' => 'avpvh-member-detail', 'id' => (int) $member->id], admin_url('admin.php'))); ?>"><?php esc_html_e('Inlogstatistieken in Ledendetail', 'avpvh-members'); ?></a></small></p>
        </fieldset>
        <?php
    }

    /**
     * Granular ledenlijst visibility controls (AVG/GDPR consent).
     */
    private static function render_directory_consent(object $member): void {
        ?>
        <fieldset class="avpvh-directory-consent">
            <legend><?php esc_html_e('Zichtbaarheid in ledenlijst', 'avpvh-members'); ?></legend>

            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('Je voorkeuren zijn opgeslagen.', 'avpvh-members'); ?></p>
            <?php endif; ?>

            <?php if ($member->directory_consent === 'granted') : ?>
                <p>
                    <?php esc_html_e('Zoals beschreven in de privacyverklaring zijn je gegevens alleen zichtbaar voor andere actieve leden, maar alleen als ze ingelogd zijn. Hieronder kan je door het vinkje te verwijderen aangeven welke gegevens je ook voor hen wil verbergen.', 'avpvh-members'); ?>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('Je deelt momenteel geen gegevens met andere leden in de ledenlijst.', 'avpvh-members'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_directory_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_directory_consent">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_email" value="1" <?php checked($member->share_email); ?>>
                        <?php esc_html_e('E-mailadres tonen in de ledenlijst', 'avpvh-members'); ?>
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_phone" value="1" <?php checked($member->share_phone); ?>>
                        <?php esc_html_e('Telefoonnummer tonen in de ledenlijst', 'avpvh-members'); ?>
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_address" value="1" <?php checked($member->share_address); ?>>
                        <?php esc_html_e('Adres tonen in de ledenlijst', 'avpvh-members'); ?>
                    </label>
                </div>

                <button type="submit" name="consent" value="granted" class="button button-primary"><?php esc_html_e('Voorkeuren opslaan', 'avpvh-members'); ?></button>
                <?php if ($member->directory_consent === 'granted') : ?>
                    <button type="submit" name="consent" value="declined" class="button"><?php esc_html_e('Toestemming volledig intrekken', 'avpvh-members'); ?></button>
                <?php endif; ?>
            </form>
        </fieldset>
        <?php
    }

    /**
     * Self-service opt-in for activity/newsletter e-mails — a real
     * AVPVH_DB member flag under the hood ('nieuwsbrief', see class-db.php's
     * "Member flags" section), not a dedicated column, so the same flag can
     * also be filtered on in the admin Ledenbeheer list and targeted by
     * AVPVH_Admin::handle_send_newsletter().
     */
    private static function render_newsletter_consent(object $member): void {
        $has_flag = AVPVH_DB::member_has_flag((int) $member->id, 'nieuwsbrief');
        ?>
        <fieldset class="avpvh-newsletter-consent">
            <legend><?php esc_html_e('Nieuwsbrief & activiteiten-mail', 'avpvh-members'); ?></legend>

            <?php if (!empty($_GET['newsletter_saved'])) : ?>
                <p class="avpvh-identity-notice"><?php esc_html_e('Je voorkeur is opgeslagen.', 'avpvh-members'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_set_newsletter_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_newsletter_consent">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="newsletter" value="1" <?php checked($has_flag); ?>>
                        <?php esc_html_e('Ik wil e-mail ontvangen over activiteiten en de nieuwsbrief', 'avpvh-members'); ?>
                    </label>
                </div>

                <button type="submit" class="button button-primary"><?php esc_html_e('Voorkeur opslaan', 'avpvh-members'); ?></button>
            </form>
        </fieldset>
        <?php
    }

    private static function render_blogging(): void {
        ?>
        <fieldset class="avpvh-blogging">
            <legend><?php esc_html_e('Bloggen', 'avpvh-members'); ?></legend>
            <p><?php esc_html_e('Als blogger kun je eigen conceptberichten schrijven en ter beoordeling indienen. Publiceren, media uploaden en pagina\'s bewerken kan niet. Blogbeheer vraagt om tweestapsverificatie (2FA).', 'avpvh-members'); ?></p>
            <p><a class="button" href="<?php echo esc_url(admin_url('edit.php')); ?>"><?php esc_html_e('Naar blogbeheer', 'avpvh-members'); ?></a></p>
        </fieldset>
        <?php
    }

    /**
     * Show audit trail of changes.
     */
    private static function render_audit_trail(object $member): void {
        $audit_log = AVPVH_DB::get_member_audit_log($member->id, 50);

        if (empty($audit_log)) {
            echo '<p style="color: #666; margin-top: 2rem;">' . esc_html__('Nog geen wijzigingen vastgelegd.', 'avpvh-members') . '</p>';
            return;
        }

        ?>
        <fieldset>
            <legend><?php esc_html_e('Wijzigingsgeschiedenis', 'avpvh-members'); ?></legend>
            <p style="font-size: 0.9em; color: #666;"><?php esc_html_e('Alle wijzigingen aan je profiel worden hieronder bijgehouden:', 'avpvh-members'); ?></p>

            <div class="avpvh-audit-table-wrap">
            <table class="avpvh-audit-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Datum', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Veld', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Gewijzigd door', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Oude waarde', 'avpvh-members'); ?></th>
                        <th><?php esc_html_e('Nieuwe waarde', 'avpvh-members'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_log as $entry): ?>
                        <tr>
                            <td class="avpvh-audit-date">
                                <?php echo esc_html(mysql2date('Y-m-d', $entry->changed_at)); ?><br><?php echo esc_html(mysql2date('H:i', $entry->changed_at)); ?>
                            </td>
                            <td>
                                <code><?php echo esc_html($entry->field_name); ?></code>
                            </td>
                            <td>
                                <?php echo esc_html($entry->user_login ?? __('Systeem', 'avpvh-members')); ?>
                            </td>
                            <td class="avpvh-audit-old">
                                <code><?php echo esc_html($entry->old_value ?? __('(leeg)', 'avpvh-members')); ?></code>
                            </td>
                            <td class="avpvh-audit-new">
                                <code><?php echo esc_html($entry->new_value ?? __('(leeg)', 'avpvh-members')); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </fieldset>
        <?php
    }

    /** How a visitor applies for membership. */
    private static function render_membership_request(): void {
        ?>
                <fieldset id="lidmaatschap">
                    <legend><?php esc_html_e('Lidmaatschap aanvragen', 'avpvh-members'); ?></legend>
                    <p><?php esc_html_e('Je kunt lidmaatschap aanvragen bij de secretaris. Zorg dat je voorletters, naam en adres in je profiel compleet zijn.', 'avpvh-members'); ?></p>
                    <p><?php esc_html_e('Voor meerderjarigen zijn twee voordrachten van leden nodig. Laat beide leden hun voordracht per e-mail aan de secretaris sturen. Voor minderjarigen moet minstens één wettelijke vertegenwoordiger zelf lid zijn.', 'avpvh-members'); ?></p>
                    <p><?php esc_html_e('Het bestuur beslist over je toelating. De secretaris laat je de beslissing schriftelijk weten.', 'avpvh-members'); ?></p>
                    <?php $contact_page = get_page_by_path('contact'); ?>
                    <p><a class="button" href="<?php echo esc_url($contact_page ? get_permalink($contact_page) : home_url('/?page_id=25')); ?>"><?php esc_html_e('Neem contact op met de secretaris', 'avpvh-members'); ?></a></p>
                    <p><a href="<?php echo esc_url(home_url('/over-de-vereniging/statuten-en-hr/')); ?>"><?php esc_html_e('Statuten en huishoudelijk reglement', 'avpvh-members'); ?></a></p>
                </fieldset>
        <?php
    }
}
