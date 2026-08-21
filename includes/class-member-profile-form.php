<?php
defined('ABSPATH') || exit;

class AVPVH_Member_Profile_Form {

    public function __construct() {
        add_shortcode('avpvh_member_profile', [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_avpvh_save_member_profile', [$this, 'handle_save_profile']);
        add_action('admin_post_avpvh_remove_identity', [$this, 'handle_remove_identity']);
        add_action('admin_post_avpvh_make_primary_identity', [$this, 'handle_make_primary_identity']);
        add_action('admin_post_avpvh_request_identity', [$this, 'handle_request_identity']);
        add_action('admin_post_avpvh_add_relationship', [$this, 'handle_add_relationship']);
        add_action('admin_post_avpvh_remove_relationship', [$this, 'handle_remove_relationship']);
    }

    /**
     * Render member profile form shortcode.
     * Usage: [avpvh_member_profile]
     */
    public function render_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p style="color: red;">Please log in to edit your profile.</p>';
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        $requested_id = (int) ($_GET['member_id'] ?? 0);
        $is_admin_edit = current_user_can('manage_options') && $requested_id > 0;

        $is_identity_request_only = false;
        if ($is_admin_edit) {
            $member = AVPVH_DB::get_member($requested_id);
        } elseif ($requested_id > 0 && $own_member && $this->can_edit_member($own_member, $requested_id)) {
            $member = AVPVH_DB::get_member($requested_id);
        } elseif ($requested_id > 0 && $own_member && $this->can_request_identity_only($own_member, $requested_id)) {
            $member = AVPVH_DB::get_member($requested_id);
            $is_identity_request_only = true;
        } else {
            $member = $own_member;
        }

        if (!$member) {
            return '<p style="color: red;">Member profile not found.</p>';
        }

        if ($is_identity_request_only) {
            ob_start();
            ?>
            <div class="avpvh-member-profile-form avpvh-member-profile-form--no-banner">
                <p class="avpvh-profile-note">
                    Je kunt hier een verzoek sturen aan <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong>
                    om een e-mailadres te verifiëren. Andere gegevens van dit profiel kun je niet bewerken.
                </p>
                <?php $this->render_identity_request_only($member); ?>
            </div>
            <?php
            return ob_get_clean();
        }

        $is_household_edit = !$is_admin_edit && $own_member && (int) $member->id !== (int) $own_member->id;
        // Used by both the summary card and the family-relation picker below
        // — always $member's own household (the person being viewed/edited),
        // never the viewer's, which differs whenever an admin edits someone
        // else's profile.
        $member_household = AVPVH_DB::get_manageable_members((int) $member->id);
        // get_manageable_members() is "family OR same address" — split that
        // back apart for display, so someone who moved out (e.g. a grown-up
        // child) shows as family rather than misleadingly as a housemate.
        // Both stay linked/editable — the split is cosmetic, not a change
        // in who's manageable.
        $housemates = [];
        $family_elsewhere = [];
        foreach ($member_household as $hg) {
            if ((int) $hg->id === (int) $member->id || AVPVH_DB::has_same_address((int) $member->id, (int) $hg->id)) {
                $housemates[] = $hg;
            } else {
                $family_elsewhere[] = $hg;
            }
        }
        // One step further than the household itself: a housemate's own
        // partner (e.g. a child's girlfriend). Not "editable family" the way
        // an actual housemate is (can_edit_member() is still gated on
        // get_manageable_members() alone) — linking through here only reaches
        // the identity-request-only view (can_request_identity_only()).
        $household_ids = wp_list_pluck($member_household, 'id');
        $extended_family = array_values(array_filter(
            AVPVH_DB::get_extended_household((int) $member->id),
            fn($m) => !in_array((int) $m->id, array_map('intval', $household_ids), true)
        ));

        // Get current address
        $addresses = AVPVH_DB::get_addresses($member->id);
        $current_address = null;
        $today = current_time('Y-m-d');

        foreach ($addresses as $addr) {
            if (
                (!$addr->valid_from || $addr->valid_from <= $today) &&
                (!$addr->valid_until || $addr->valid_until >= $today)
            ) {
                $current_address = $addr;
                break;
            }
        }

        ob_start();
        ?>
        <div class="avpvh-member-profile-form avpvh-member-profile-form--no-banner">
            <?php if ($is_admin_edit) : ?>
                <p class="avpvh-profile-note">Administrator editing: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong></p>
            <?php elseif ($is_household_edit) : ?>
                <p class="avpvh-profile-note">Je bewerkt het profiel van: <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong></p>
            <?php endif; ?>

            <?php if (is_user_logged_in()) : ?>
                <?php
                $user = wp_get_current_user();
                // Badges describe $member (the profile being viewed), not the
                // viewer — a real WP admin viewing someone else's profile must
                // never see their own "Beheerder" badge appear on that person.
                $target_user = !empty($member->wp_user_id) ? get_userdata((int) $member->wp_user_id) : false;
                $member_role = $target_user ? (string) get_user_meta($target_user->ID, 'avpvh_member_role', true) : '';
                $member_role_label = $this->member_role_label($member_role);
                $status_label = $member->status === 'active' ? 'Actief lid' : ($member->status === 'inactive' ? 'Oud lid' : 'Bezoeker');
                $badges = array_filter([$target_user ? $this->role_label($target_user) : '', $member_role_label, $status_label]);

                $lldap_groups = [];
                if (!empty($member->user_id)) {
                    $lldap_groups = AVPVH_LLDAP::get_user_groups($member->user_id);
                    if (is_wp_error($lldap_groups)) {
                        $lldap_groups = [];
                    }
                }
                ?>
                <div class="avpvh-summary-card">
                    <div class="avpvh-summary-card__name"><?php echo esc_html(avpvh_format_name($member)); ?></div>
                    <div class="avpvh-summary-card__badges">
                        <?php foreach ($badges as $badge) : ?>
                            <span class="avpvh-badge"><?php echo esc_html($badge); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="avpvh-summary-card__row">
                        <span class="avpvh-summary-card__label">Ingelogd als:</span>
                        <?php echo esc_html($user->user_email); ?>
                    </div>
                    <?php if ($lldap_groups) : ?>
                        <div class="avpvh-summary-card__row">
                            <span class="avpvh-summary-card__label">Groepen:</span>
                            <?php echo esc_html(implode(', ', wp_list_pluck($lldap_groups, 'displayName'))); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (count($housemates) > 1) : ?>
                        <div class="avpvh-summary-card__row">
                            <span class="avpvh-summary-card__label">Huisgenoten:</span>
                            <?php foreach ($housemates as $i => $hg) : ?>
                                <?php echo $i > 0 ? ', ' : ''; ?>
                                <?php if ((int) $hg->id === (int) $member->id) : ?>
                                    <strong><?php echo esc_html(avpvh_format_name($hg)); ?></strong>
                                <?php else : ?>
                                    <a href="<?php echo esc_url(add_query_arg('member_id', $hg->id)); ?>"><?php echo esc_html(avpvh_format_name($hg)); ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($family_elsewhere) : ?>
                        <div class="avpvh-summary-card__row">
                            <span class="avpvh-summary-card__label">Familie (elders wonend):</span>
                            <?php foreach ($family_elsewhere as $i => $hg) : ?>
                                <?php echo $i > 0 ? ', ' : ''; ?>
                                <a href="<?php echo esc_url(add_query_arg('member_id', $hg->id)); ?>"><?php echo esc_html(avpvh_format_name($hg)); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($extended_family) : ?>
                        <div class="avpvh-summary-card__row">
                            <span class="avpvh-summary-card__label">Ook verbonden (partner van familielid):</span>
                            <?php foreach ($extended_family as $i => $ef) : ?>
                                <?php echo $i > 0 ? ', ' : ''; ?>
                                <a href="<?php echo esc_url(add_query_arg('member_id', $ef->id)); ?>" title="E-mailverificatie aanvragen"><?php echo esc_html(avpvh_format_name($ef)); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form id="avpvh-profile-form" method="post">
                <?php wp_nonce_field('avpvh_member_profile', 'avpvh_nonce'); ?>
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <fieldset class="avpvh-fields-grid">
                    <legend>Persoonlijke gegevens</legend>

                    <div class="form-group">
                        <label for="first_name">Voornaam *</label>
                        <input type="text" id="first_name" name="first_name"
                            value="<?php echo esc_attr($member->first_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="suffix">Tussenvoegsel</label>
                        <input type="text" id="suffix" name="suffix"
                            value="<?php echo esc_attr($member->suffix ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="last_name">Achternaam *</label>
                        <input type="text" id="last_name" name="last_name"
                            value="<?php echo esc_attr($member->last_name); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="passport_name">Paspoortnaam</label>
                        <input type="text" id="passport_name" name="passport_name"
                            value="<?php echo esc_attr($member->passport_name ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="initials">Voorletters (zoals op de bankrekening)</label>
                        <input type="text" id="initials" name="initials"
                            value="<?php echo esc_attr($member->initials ?? ''); ?>" placeholder="bv. S.J.M.">
                        <?php $mismatch = avpvh_initials_mismatch($member); ?>
                        <?php if ($mismatch) : ?>
                            <p class="description" style="color:#b32d2e;font-weight:600">
                                &#9888; Komt niet overeen met de paspoortnaam (die geeft <?php echo esc_html($mismatch); ?>).
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_admin_edit) :
                        $birth_value = !empty($member->birth_date) ? $member->birth_date : (!empty($member->birth_year) ? (string) $member->birth_year : '');
                        ?>
                        <div class="form-group">
                            <label for="birth_date">Geboortedatum</label>
                            <input type="text" id="birth_date" name="birth_date" inputmode="numeric"
                                pattern="\d{4}(-\d{2}-\d{2})?" placeholder="JJJJ-MM-DD of alleen JJJJ"
                                value="<?php echo esc_attr($birth_value); ?>">
                            <p class="avpvh-field-hint">Volledige datum (JJJJ-MM-DD), of alleen het geboortejaar als de exacte datum niet bekend is.</p>
                        </div>
                        <div class="form-group">
                            <label for="is_student">
                                <input type="checkbox" id="is_student" name="is_student" value="1" <?php checked(!empty($member->is_student)); ?>>
                                Scholier/student
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="diet">Eetgewoontes / allergieën</label>
                        <input type="text" id="diet" name="diet"
                            value="<?php echo esc_attr($member->diet ?? ''); ?>"
                            placeholder="bv. vegetarisch, notenallergie">
                    </div>
                </fieldset>

                <fieldset class="avpvh-fields-grid">
                    <legend>Contact Information</legend>

                    <div class="form-group">
                        <label for="phone">Phone (Home)</label>
                        <input type="tel" id="phone" name="phone"
                            value="<?php echo esc_attr($member->phone); ?>">
                    </div>

                    <div class="form-group">
                        <label for="mobile">Mobile (Cell)</label>
                        <input type="tel" id="mobile" name="mobile"
                            value="<?php echo esc_attr($member->mobile); ?>">
                    </div>

                    <div class="form-group">
                        <label for="emergency_contact">Emergency Contact Name</label>
                        <input type="text" id="emergency_contact" name="emergency_contact"
                            value="<?php echo esc_attr($member->emergency_contact); ?>">
                    </div>
                </fieldset>

                <fieldset class="avpvh-fields-grid">
                    <legend>Address</legend>

                    <div class="form-group">
                        <label for="street">Street</label>
                        <input type="text" id="street" name="street"
                            value="<?php echo esc_attr($current_address->street ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="house_number">House Number</label>
                        <input type="text" id="house_number" name="house_number"
                            value="<?php echo esc_attr($current_address->house_number ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="postal_code">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code"
                            value="<?php echo esc_attr($current_address->postal_code ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city"
                            value="<?php echo esc_attr($current_address->city ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country"
                            value="<?php echo esc_attr($current_address->country ?? 'Nederland'); ?>">
                    </div>
                </fieldset>

                <button type="submit" class="button button-primary">Update Profile</button>
            </form>

            <?php if (!$is_admin_edit) : ?>
                <?php $this->render_identities($member, $is_household_edit); ?>
            <?php endif; ?>

            <?php $this->render_relationships($member); ?>

            <?php if (!$is_admin_edit) : ?>
                <?php $this->render_directory_consent($member); ?>
                <?php $this->render_newsletter_consent($member); ?>
            <?php endif; ?>

            <!-- Audit Trail -->
            <?php $this->render_audit_trail($member); ?>
        </div>
        <?php

        return ob_get_clean();
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
    private function render_identities($member, bool $is_household_edit = false): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        $current_user = wp_get_current_user();
        $provider_labels = [
            'email'     => 'E-mail (link)',
            'google'    => 'Google',
            'microsoft' => 'Microsoft',
        ];
        ?>
        <fieldset class="avpvh-identities">
            <legend>Inlog-e-mailadressen</legend>
            <p>
                Je kunt tot drie e-mailadressen koppelen om mee in te loggen, in elke
                combinatie van methodes. Om een adres toe te voegen moet je er daadwerkelijk
                mee inloggen, zodat we weten dat het echt van jou is.
            </p>

            <?php if (!empty($_GET['identity_added'])) : ?>
                <p class="avpvh-identity-notice">E-mailadres toegevoegd.</p>
            <?php elseif (!empty($_GET['identity_error'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error">
                    <?php
                    $errors = [
                        'in_use'        => 'Dat e-mailadres is al aan een ander lid gekoppeld.',
                        'not_you'       => 'Er ging iets mis met de verificatie — probeer het opnieuw.',
                        'limit'         => 'Dit adres kon niet worden toegevoegd (maximaal drie).',
                        'invalid_email' => 'Dat is geen geldig e-mailadres.',
                        'last_identity' => 'Je hebt maar één geverifieerd inlog-e-mailadres — verifieer en voeg eerst een tweede toe voordat je iets verwijdert.',
                    ];
                    echo esc_html($errors[$_GET['identity_error']] ?? 'Er ging iets mis.');
                    ?>
                </p>
            <?php elseif (!empty($_GET['identity_removed'])) : ?>
                <p class="avpvh-identity-notice">E-mailadres verwijderd.</p>
            <?php elseif (!empty($_GET['identity_primary'])) : ?>
                <p class="avpvh-identity-notice">Primair e-mailadres gewijzigd.</p>
            <?php elseif (!empty($_GET['identity_requested'])) : ?>
                <p class="avpvh-identity-notice">Verzoek verstuurd — <?php echo esc_html($member->first_name); ?> heeft een e-mail gekregen met instructies.</p>
            <?php elseif (!empty($_GET['identity_email_sent'])) : ?>
                <p class="avpvh-identity-notice">Bevestigingslink verstuurd — check je inbox om het adres te koppelen.</p>
            <?php endif; ?>

            <?php $verified_count = count(array_filter($identities, fn($i) => !empty($i->verified_at))); ?>
            <table class="avpvh-identities-table">
                <?php foreach ($identities as $identity) :
                    $is_current_login = strcasecmp($identity->email, $current_user->user_email) === 0;
                    $label = $provider_labels[$identity->provider] ?? $identity->provider;
                ?>
                    <tr>
                        <td><?php echo esc_html($identity->email); ?></td>
                        <td><?php echo esc_html($label); ?></td>
                        <td>
                            <?php if (empty($identity->verified_at)) : ?>
                                <span class="avpvh-identity-unverified" title="Toegevoegd door een beheerder, niet zelf geverifieerd">Niet geverifieerd</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $identity->is_primary ? 'Primair' : ''; ?></td>
                        <td>
                            <?php if (!$identity->is_primary) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                                title="Wordt ook het contactadres van je account, los van waarmee je inlogt.">
                                <?php wp_nonce_field('avpvh_make_primary_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_make_primary_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button button-small">Maak primair</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($verified_count > 1) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block"
                                onsubmit="return confirm('<?php echo $is_current_login
                                    ? esc_js('Let op: dit is het adres waarmee je nu bent ingelogd. Als je het verwijdert, kun je daar niet meer mee inloggen. Doorgaan?')
                                    : esc_js('Dit e-mailadres verwijderen?'); ?>');">
                                <?php wp_nonce_field('avpvh_remove_identity'); ?>
                                <input type="hidden" name="action" value="avpvh_remove_identity">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="identity_id" value="<?php echo esc_attr($identity->id); ?>">
                                <button type="submit" class="button">Verwijderen</button>
                            </form>
                            <?php else : ?>
                            <span title="Je hebt maar één geverifieerd adres — voeg eerst een tweede toe om te kunnen verwijderen">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$identities) : ?>
                    <tr><td colspan="5"><em>Nog geen e-mailadressen gekoppeld.</em></td></tr>
                <?php endif; ?>
            </table>

            <?php if ($is_household_edit) : ?>
                <?php if (count($identities) < 3) : ?>
                <p class="avpvh-identities-add">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avpvh_request_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_request_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                        <button type="submit" class="button">Vraag <?php echo esc_html($member->first_name); ?> om een e-mailadres te verifiëren</button>
                    </form>
                    <small>
                        Toevoegen kan alleen door <?php echo esc_html($member->first_name); ?> zelf (moet zelf
                        inloggen om te verifiëren dat het adres echt van diegene is) — dit stuurt een e-mail met
                        instructies.
                    </small>
                </p>
                <?php endif; ?>
            <?php elseif (count($identities) < 3) :
                $configured = AVPVH_OAuth::configured_providers();
            ?>
                <div class="avpvh-identities-add">
                    <p class="avpvh-identities-add-intro">Voeg een e-mailadres toe en verifieer het:</p>

                    <?php foreach ($configured as $key => $provider) : ?>
                        <div class="avpvh-identities-add-option">
                            <a class="button" href="<?php echo esc_url(AVPVH_OAuth::add_identity_url($key, (int) $member->id)); ?>">
                                Met <?php echo esc_html($provider['label']); ?>
                            </a>
                            <small class="avpvh-field-hint">Handig als je browser al bij <?php echo esc_html($provider['label']); ?> is ingelogd — dan hoef je geen wachtwoord in te typen.</small>
                        </div>
                    <?php endforeach; ?>

                    <div class="avpvh-identities-add-option">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('avpvh_start_email_identity'); ?>
                            <input type="hidden" name="action" value="avpvh_start_email_identity">
                            <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                            <input type="email" name="email" placeholder="naam@voorbeeld.nl" required>
                            <button type="submit" class="button">Met een e-maillink</button>
                        </form>
                        <small class="avpvh-field-hint">Voor adressen zonder Google- of Microsoft-account — je krijgt een bevestigingslink toegestuurd om te verifiëren.</small>
                    </div>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Stripped-down version of render_identities() for the extended-household
     * case (can_request_identity_only(), e.g. a housemate's partner living
     * elsewhere): just the request button, no identities table — someone
     * outside the household proper shouldn't see the member's existing
     * verified e-mail addresses, only be able to ask for a new one.
     */
    private function render_identity_request_only(object $member): void {
        $identities = AVPVH_DB::get_member_identities((int) $member->id);
        ?>
        <fieldset class="avpvh-identities">
            <legend>Inlog-e-mailadres verifiëren</legend>

            <?php if (!empty($_GET['identity_requested'])) : ?>
                <p class="avpvh-identity-notice">Verzoek verstuurd — <?php echo esc_html($member->first_name); ?> heeft een e-mail gekregen met instructies.</p>
            <?php endif; ?>

            <?php if (count($identities) < 3) : ?>
                <p class="avpvh-identities-add">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('avpvh_request_identity'); ?>
                        <input type="hidden" name="action" value="avpvh_request_identity">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                        <button type="submit" class="button">Vraag <?php echo esc_html($member->first_name); ?> om een e-mailadres te verifiëren</button>
                    </form>
                    <small>
                        Toevoegen kan alleen door <?php echo esc_html($member->first_name); ?> zelf (moet zelf
                        inloggen om te verifiëren dat het adres echt van diegene is) — dit stuurt een e-mail met
                        instructies.
                    </small>
                </p>
            <?php else : ?>
                <p><em><?php echo esc_html($member->first_name); ?> heeft al het maximum van drie inlog-e-mailadressen.</em></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * Family/partner/guardian relationships — see AVPVH_DB's "Relationships"
     * section for the data model. Available on both self/household and
     * admin edits (unlike identities, which are self-service-only).
     */
    private function render_relationships($member): void {
        $relationships = AVPVH_DB::get_relationships((int) $member->id);
        $labels = AVPVH_DB::get_relationship_labels();
        $all_members = array_filter(
            AVPVH_DB::get_members(),
            fn($m) => (int) $m->id !== (int) $member->id
        );
        usort($all_members, fn($a, $b) => strcasecmp($a->last_name, $b->last_name) ?: strcasecmp($a->first_name, $b->first_name));
        $activities = AVPVH_DB::get_activities();
        ?>
        <fieldset class="avpvh-relationships">
            <legend>Relaties</legend>

            <?php if (!empty($_GET['relationship_added'])) : ?>
                <p class="avpvh-identity-notice">Relatie toegevoegd.</p>
            <?php elseif (!empty($_GET['relationship_removed'])) : ?>
                <p class="avpvh-identity-notice">Relatie verwijderd.</p>
            <?php elseif (!empty($_GET['relationship_duplicate'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error">Deze relatie staat al hieronder — niet nogmaals toegevoegd.</p>
            <?php elseif (!empty($_GET['relationship_error'])) : ?>
                <p class="avpvh-identity-notice avpvh-identity-notice--error">Opslaan is niet gelukt — probeer het opnieuw.</p>
            <?php endif; ?>

            <table class="avpvh-identities-table">
                <?php foreach ($relationships as $rel) : ?>
                    <tr>
                        <td>
                            <strong><?php echo $rel->other_member ? esc_html(avpvh_format_name($rel->other_member)) : '(onbekend lid)'; ?></strong>
                            is <?php echo esc_html($rel->label); ?>
                            <strong><?php echo esc_html(avpvh_format_name($member)); ?></strong>
                        </td>
                        <td>
                            <?php if ($rel->valid_from || $rel->valid_until) : ?>
                                <?php echo esc_html(trim(($rel->valid_from ?: '…') . ' – ' . ($rel->valid_until ?: '…'))); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                onsubmit="return confirm('<?php echo esc_js('Deze relatie verwijderen?'); ?>');">
                                <?php wp_nonce_field('avpvh_remove_relationship'); ?>
                                <input type="hidden" name="action" value="avpvh_remove_relationship">
                                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">
                                <input type="hidden" name="relationship_id" value="<?php echo esc_attr($rel->id); ?>">
                                <button type="submit" class="button">Verwijderen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$relationships) : ?>
                    <tr><td colspan="3"><em>Nog geen relaties vastgelegd.</em></td></tr>
                <?php endif; ?>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="avpvh-relationship-add">
                <?php wp_nonce_field('avpvh_add_relationship'); ?>
                <input type="hidden" name="action" value="avpvh_add_relationship">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label for="rel_related_member_id">Persoon</label>
                    <select id="rel_related_member_id" name="related_member_id" required>
                        <option value="">— Kies —</option>
                        <?php foreach ($all_members as $m) : ?>
                            <option value="<?php echo esc_attr($m->id); ?>"><?php echo esc_html(avpvh_format_name($m)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="rel_label_id">is</label>
                    <select id="rel_label_id" name="label_id" required>
                        <?php foreach ($labels as $label) : ?>
                            <option value="<?php echo esc_attr($label->id); ?>"><?php echo esc_html($label->label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="avpvh-field-hint">…van <?php echo esc_html(avpvh_format_name($member)); ?>.</p>
                </div>

                <div class="form-group">
                    <label for="rel_activity_id">Periode: activiteit (optioneel — bijv. tijdelijke voogdij tijdens een kamp)</label>
                    <select id="rel_activity_id" name="activity_id">
                        <option value="">— Geen, handmatige data hieronder —</option>
                        <?php foreach ($activities as $activity) : ?>
                            <option value="<?php echo esc_attr($activity->id); ?>">
                                <?php echo esc_html(($activity->type_name ? $activity->type_name . ': ' : '') . $activity->name . ' (' . $activity->year . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="rel_valid_from">Geldig van (handmatig, tenzij een activiteit is gekozen hierboven)</label>
                    <input type="date" id="rel_valid_from" name="valid_from">
                </div>

                <div class="form-group">
                    <label for="rel_valid_until">Geldig tot (handmatig, tenzij een activiteit is gekozen hierboven)</label>
                    <input type="date" id="rel_valid_until" name="valid_until">
                </div>

                <button type="submit" class="button">Relatie toevoegen</button>
            </form>
        </fieldset>
        <?php
    }

    /**
     * Granular ledenlijst visibility controls (AVG/GDPR consent).
     */
    private function render_directory_consent($member): void {
        ?>
        <fieldset class="avpvh-directory-consent">
            <legend>Zichtbaarheid in ledenlijst</legend>

            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p>Je voorkeuren zijn opgeslagen.</p>
            <?php endif; ?>

            <?php if ($member->directory_consent === 'granted') : ?>
                <p>
                    Zoals beschreven in de privacyverklaring zijn je gegevens alleen
                    zichtbaar voor andere actieve leden, maar alleen als ze ingelogd zijn.
                    Hieronder kan je door het vinkje te verwijderen aangeven welke gegevens
                    je ook voor hen wil verbergen.
                </p>
            <?php else : ?>
                <p>Je deelt momenteel geen gegevens met andere leden in de ledenlijst.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_directory_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_directory_consent">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_email" value="1" <?php checked($member->share_email); ?>>
                        E-mailadres tonen in de ledenlijst
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_phone" value="1" <?php checked($member->share_phone); ?>>
                        Telefoonnummer tonen in de ledenlijst
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="share_address" value="1" <?php checked($member->share_address); ?>>
                        Adres tonen in de ledenlijst
                    </label>
                </div>

                <button type="submit" name="consent" value="granted" class="button button-primary">Voorkeuren opslaan</button>
                <?php if ($member->directory_consent === 'granted') : ?>
                    <button type="submit" name="consent" value="declined" class="button">Toestemming volledig intrekken</button>
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
    private function render_newsletter_consent($member): void {
        $has_flag = AVPVH_DB::member_has_flag((int) $member->id, 'nieuwsbrief');
        ?>
        <fieldset class="avpvh-newsletter-consent">
            <legend>Nieuwsbrief &amp; activiteiten-mail</legend>

            <?php if (!empty($_GET['newsletter_saved'])) : ?>
                <p>Je voorkeur is opgeslagen.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_set_newsletter_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_newsletter_consent">
                <input type="hidden" name="member_id" value="<?php echo esc_attr($member->id); ?>">

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="newsletter" value="1" <?php checked($has_flag); ?>>
                        Ik wil e-mail ontvangen over activiteiten en de nieuwsbrief
                    </label>
                </div>

                <button type="submit" class="button button-primary">Voorkeur opslaan</button>
            </form>
        </fieldset>
        <?php
    }

    /**
     * Show audit trail of changes.
     */
    private function render_audit_trail($member): void {
        $audit_log = AVPVH_DB::get_member_audit_log($member->id, 50);

        if (empty($audit_log)) {
            echo '<p style="color: #666; margin-top: 2rem;">No changes recorded yet.</p>';
            return;
        }

        ?>
        <hr style="margin: 2rem 0;">

        <fieldset>
            <legend>Change History</legend>
            <p style="font-size: 0.9em; color: #666;">All changes to your profile are tracked below:</p>

            <div class="avpvh-audit-table-wrap">
            <table class="avpvh-audit-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Field</th>
                        <th>Changed By</th>
                        <th>Old Value</th>
                        <th>New Value</th>
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
                                <?php echo esc_html($entry->user_login ?? 'System'); ?>
                            </td>
                            <td class="avpvh-audit-old">
                                <code><?php echo esc_html($entry->old_value ?? '(empty)'); ?></code>
                            </td>
                            <td class="avpvh-audit-new">
                                <code><?php echo esc_html($entry->new_value ?? '(empty)'); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Handle form submission via AJAX.
     */
    public function handle_save_profile(): void {
        check_ajax_referer('avpvh_member_profile', 'avpvh_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error('Not authenticated');
        }

        $member = $this->get_target_member_for_save();

        if (!$member) {
            wp_send_json_error('Member profile not found');
        }

        $is_admin_edit = current_user_can('manage_options') && !empty($_POST['member_id']);
        $member_data = $this->sanitize_member_data($_POST, $is_admin_edit);

        try {
            // Update member with audit trail
            if ($member_data) {
                $formats = array_fill(0, count($member_data), '%s');
                AVPVH_DB::update_member_with_audit(
                    $member->id,
                    $member_data,
                    $formats
                );
            }

            // Update address if provided
            if (!empty($_POST['street']) || !empty($_POST['postal_code'])) {
                $address_data = [
                    'street' => sanitize_text_field($_POST['street'] ?? ''),
                    'house_number' => sanitize_text_field($_POST['house_number'] ?? ''),
                    'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
                    'city' => sanitize_text_field($_POST['city'] ?? ''),
                    'country' => sanitize_text_field($_POST['country'] ?? 'Nederland'),
                    'valid_from' => current_time('Y-m-d'),
                ];

                global $wpdb;
                $wpdb->insert(
                    "{$wpdb->prefix}avm_addresses",
                    ['member_id' => $member->id] + $address_data,
                    ['%d'] + array_fill(0, count($address_data), '%s')
                );
            }

            wp_send_json_success('Profile updated successfully!');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    private function get_target_member_for_save(): ?object {
        $wp_user = wp_get_current_user();
        $own_member = AVPVH_DB::get_member_by_wp_user($wp_user->ID);

        $member_id = (int) ($_POST['member_id'] ?? 0);
        if ($member_id > 0 && $this->can_edit_member($own_member, $member_id)) {
            return AVPVH_DB::get_member($member_id);
        }

        return $own_member;
    }

    /**
     * Admins can edit any member; everyone else can edit their own profile or
     * a household member's (same family link or current address).
     */
    private function can_edit_member(?object $own_member, int $target_member_id): bool {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!$own_member) {
            return false;
        }
        foreach (AVPVH_DB::get_manageable_members((int) $own_member->id) as $m) {
            if ((int) $m->id === $target_member_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wider than can_edit_member() — also reaches a housemate's own partner
     * (AVPVH_DB::get_extended_household(), e.g. a child's girlfriend who
     * lives elsewhere), but only for requesting identity verification, not
     * for editing anything else. Deliberately kept separate from
     * can_edit_member() rather than widening it, since that would also open
     * up name/address/relationship editing for that wider circle.
     */
    private function can_request_identity_only(?object $own_member, int $target_member_id): bool {
        if (!$own_member) {
            return false;
        }
        foreach (AVPVH_DB::get_extended_household((int) $own_member->id) as $m) {
            if ((int) $m->id === $target_member_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove one of the member's login identities (self-service). Keeps at
     * least one identity, and e-mails the change to the person who made it
     * plus — if they edited a household member's profile rather than their
     * own — to the affected member too.
     */
    public function handle_remove_identity(): void {
        check_admin_referer('avpvh_remove_identity');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id   = (int) ($_POST['member_id'] ?? 0);
        $identity_id = (int) ($_POST['identity_id'] ?? 0);
        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        if (!$member_id || !$this->can_edit_member($own_member, $member_id)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        $identities = AVPVH_DB::get_member_identities($member_id);
        // Gated on *verified* identities, not the raw count — an admin-added,
        // unverified extra shouldn't count as a safe fallback to remove down to.
        $verified_count = count(array_filter($identities, fn($i) => !empty($i->verified_at)));
        if ($verified_count <= 1) {
            wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_error' => 'last_identity'], wp_get_referer() ?: home_url('/member-profile/')));
            exit;
        }

        $removed_email = null;
        $removed_provider = null;
        foreach ($identities as $identity) {
            if ((int) $identity->id === $identity_id) {
                $removed_email = $identity->email;
                $removed_provider = $identity->provider;
                break;
            }
        }

        if ($removed_email) {
            AVPVH_DB::delete_identity_by_id($member_id, $identity_id);
            self::notify_identity_change($member, $own_member, 'verwijderd', $removed_provider, $removed_email);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_removed' => '1'], wp_get_referer() ?: home_url('/member-profile/')));
        exit;
    }

    /**
     * Designate one of the member's identities as primary — self-service
     * equivalent of the admin's "Maak primair" button in Ledendetail. Also
     * pushes that address to the LLDAP account's own contact e-mail
     * (member-detail.php's "E-mail" field, used for correspondence, separate
     * from Inlogadressen/login), replacing whatever was there before — e.g.
     * an old address nobody uses for either purpose anymore.
     */
    public function handle_make_primary_identity(): void {
        check_admin_referer('avpvh_make_primary_identity');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id   = (int) ($_POST['member_id'] ?? 0);
        $identity_id = (int) ($_POST['identity_id'] ?? 0);
        $own_member  = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        if (!$member_id || !$this->can_edit_member($own_member, $member_id)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        $target = null;
        foreach (AVPVH_DB::get_member_identities($member_id) as $identity) {
            if ((int) $identity->id === $identity_id) {
                $target = $identity;
                break;
            }
        }

        if ($target) {
            AVPVH_DB::set_primary_identity($member_id, $identity_id);
            $result = AVPVH_LLDAP::update_user($member->lldap_user_id, ['email' => $target->email]);
            if (is_wp_error($result)) {
                error_log("AVPVH_Member_Profile_Form: failed to sync primary identity ({$target->email}) to LLDAP for member {$member_id}: " . $result->get_error_message());
            }
            self::notify_identity_change($member, $own_member, 'als primair adres ingesteld', $target->provider, $target->email);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_primary' => '1'], wp_get_referer() ?: home_url('/member-profile/')));
        exit;
    }

    /**
     * A household member (e.g. a spouse) asks another household member to
     * verify-and-add a new login e-mail. This only sends a request e-mail —
     * it never starts the OAuth flow itself, since only the target member
     * can complete that (see class-oauth.php). The link in the e-mail just
     * points at their own profile, where the real "Verifieer en voeg toe"
     * buttons render fresh for their own session once they're logged in.
     */
    public function handle_request_identity(): void {
        check_admin_referer('avpvh_request_identity');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id  = (int) ($_POST['member_id'] ?? 0);
        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());

        $has_access = $member_id && (
            $this->can_edit_member($own_member, $member_id)
            || $this->can_request_identity_only($own_member, $member_id)
        );
        if (!$has_access) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        $member = AVPVH_DB::get_member($member_id);
        if (!$member) {
            wp_die('Lid niet gevonden.', 'Fout', ['response' => 404]);
        }

        if ((int) $member->id === (int) $own_member->id) {
            wp_die('Je kunt geen verzoek naar jezelf sturen.', 'Fout', ['response' => 400]);
        }

        $requester_user = wp_get_current_user();
        $requester_name = $requester_user->display_name ?: $requester_user->user_login;
        $subject = 'AV Philips van Horne — vraag om e-mailadres te verifiëren';
        // Links straight into Authelia's own reset-password flow with the
        // username field prefilled (see nginx-config: custom/authelia-custom.js
        // prefillResetUsername() + njs/authelia-nonce.js) — the member only
        // has to confirm the flow themselves, not first go find where to
        // start it. Authelia's users_filter matches on username OR mail, so
        // prefilling with the e-mail address works even though LLDAP's
        // actual username differs from it.
        $reset_url = add_query_arg('username', rawurlencode($member->email), AVPVH_Nav_Auth::AUTHELIA_URL . '/reset-password/step1');
        $body = esc_html($requester_name) . " heeft dit e-mailadres (" . $member->email . ") toegevoegd aan jouw profiel "
            . "bij AV Philips van Horne en vraagt of je het wilt verifiëren als inlogmethode.\n\n"
            . "Alleen jij kunt dit zelf afronden — " . esc_html($requester_name) . " kan dit niet namens jou doen. "
            . "Klik hieronder om het adres te verifiëren en een wachtwoord in te stellen:\n" . $reset_url . "\n\n"
            . "Was dit geen terecht verzoek? Dan hoef je niets te doen.";
        wp_mail($member->email, $subject, $body);
        set_transient(self::identity_request_transient_key($member_id), get_current_user_id(), 3 * DAY_IN_SECONDS);

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'identity_requested' => '1'], wp_get_referer() ?: home_url('/member-profile/')));
        exit;
    }

    public function handle_add_relationship(): void {
        check_admin_referer('avpvh_add_relationship');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id         = (int) ($_POST['member_id'] ?? 0);
        $related_member_id = (int) ($_POST['related_member_id'] ?? 0);
        $label_id           = (int) ($_POST['label_id'] ?? 0);
        $valid_from         = sanitize_text_field($_POST['valid_from'] ?? '') ?: null;
        $valid_until        = sanitize_text_field($_POST['valid_until'] ?? '') ?: null;

        // Picking an activity ("een kamp of weekend") is an alternative to
        // typing dates by hand — its real start/end date becomes the
        // relationship's validity period (e.g. temporary voogdij for the
        // activity's duration), overriding whatever was typed manually.
        $activity_id = (int) ($_POST['activity_id'] ?? 0);
        if ($activity_id) {
            $activity = AVPVH_DB::get_activity($activity_id);
            if ($activity) {
                $valid_from  = $activity->start_date ?: $valid_from;
                $valid_until = $activity->end_date ?: $valid_until;
            }
        }

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        if (!$member_id || !$this->can_edit_member($own_member, $member_id)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        if (!$related_member_id || !$label_id || !AVPVH_DB::get_member($related_member_id)) {
            wp_die('Ongeldige relatie.', 'Fout', ['response' => 400]);
        }

        $duplicate = AVPVH_DB::relationship_exists($member_id, $related_member_id, $label_id, $valid_from, $valid_until);
        $saved = !$duplicate && AVPVH_DB::add_relationship(
            $member_id, $related_member_id, $label_id,
            $valid_from, $valid_until, '', get_current_user_id()
        );

        $status = $saved ? 'relationship_added' : ($duplicate ? 'relationship_duplicate' : 'relationship_error');
        wp_safe_redirect(add_query_arg(
            ['member_id' => $member_id, $status => '1'],
            wp_get_referer() ?: home_url('/member-profile/')
        ));
        exit;
    }

    public function handle_remove_relationship(): void {
        check_admin_referer('avpvh_remove_relationship');

        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn.', 'Fout', ['response' => 403]);
        }

        $member_id        = (int) ($_POST['member_id'] ?? 0);
        $relationship_id  = (int) ($_POST['relationship_id'] ?? 0);

        $own_member = AVPVH_DB::get_member_by_wp_user(get_current_user_id());
        if (!$member_id || !$this->can_edit_member($own_member, $member_id)) {
            wp_die('Geen toegang.', 'Fout', ['response' => 403]);
        }

        if ($relationship_id) {
            AVPVH_DB::remove_relationship($relationship_id);
        }

        wp_safe_redirect(add_query_arg(['member_id' => $member_id, 'relationship_removed' => '1'], wp_get_referer() ?: home_url('/member-profile/')));
        exit;
    }

    /**
     * E-mails everyone who should know an identity changed: the person who
     * made the change, and — only when they edited someone else's profile —
     * the affected member as well, at their remaining identities. For a
     * successful *add* specifically, also notifies whoever requested it via
     * handle_request_identity() (if anyone did, within the request's 3-day
     * window) — adding is self-only (see class-oauth.php), so that's the
     * only way a requester learns it actually happened.
     */
    public static function notify_identity_change(object $member, ?object $actor_member, string $action, string $provider, string $email, ?\WP_User $actor_user = null): void {
        $provider_labels = ['email' => 'e-mailadres', 'google' => 'Google-account', 'microsoft' => 'Microsoft-account'];
        $provider_label = $provider_labels[$provider] ?? $provider;
        $member_name = avpvh_format_name($member);

        $actor_user = $actor_user ?: wp_get_current_user();
        $is_self = $actor_member && (int) $actor_member->id === (int) $member->id;

        $subject = 'AV Philips van Horne — inloggegevens gewijzigd';
        $body_actor = "Er is zojuist een {$provider_label} ({$email}) {$action} bij het profiel van {$member_name}.\n\n"
            . "Was jij dit niet? Neem dan contact op met het bestuur.";
        wp_mail($actor_user->user_email, $subject, $body_actor);

        if (!$is_self) {
            $body_member = "Er is zojuist een {$provider_label} ({$email}) {$action} bij jouw profiel door "
                . esc_html($actor_user->display_name ?: $actor_user->user_login) . ".\n\n"
                . "Was jij dit niet? Neem dan contact op met het bestuur.";
            foreach (AVPVH_DB::get_member_identities((int) $member->id) as $identity) {
                wp_mail($identity->email, $subject, $body_member);
            }
        }

        if ($action === 'toegevoegd') {
            $requester_id = get_transient(self::identity_request_transient_key((int) $member->id));
            if ($requester_id && (int) $requester_id !== (int) $actor_user->ID) {
                delete_transient(self::identity_request_transient_key((int) $member->id));
                $requester_user = get_userdata((int) $requester_id);
                if ($requester_user) {
                    $body_requester = "Je verzoek is opgevolgd: er is zojuist een {$provider_label} ({$email}) "
                        . "toegevoegd aan het profiel van {$member_name}.";
                    wp_mail($requester_user->user_email, $subject, $body_requester);
                }
            }
        }
    }

    private static function identity_request_transient_key(int $member_id): string {
        return 'avpvh_identity_request_' . $member_id;
    }

    /**
     * Fields every member (or a household member editing on their behalf)
     * may update themselves; admin-only fields (baptism name, birth date)
     * are added on top for the admin edit screen.
     */
    // Passport name must read exactly as printed in the passport — no
    // parenthetical nicknames/notes (a leftover habit from the old,
    // free-text doopnaam field this was merged from).
    private static function strip_brackets(string $value): string {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[()\[\]{}]/', '', $value)));
    }

    /** Free-typed "s j m", "SJM", "S.J.M." all become the one canonical "S.J.M." — so stored initials compare reliably against a passport name's derived initials, and against bank-export text (avpvh-bookkeeping normalizes both the same way before comparing). */
    private static function normalize_initials(string $raw): string {
        $letters = preg_replace('/[^A-Za-z]/', '', $raw);
        if ($letters === '') {
            return '';
        }
        return implode('.', str_split(mb_strtoupper($letters))) . '.';
    }

    private function sanitize_member_data(array $data, bool $is_admin): array {
        $fields = [
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'suffix' => sanitize_text_field($data['suffix'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'passport_name' => self::strip_brackets(sanitize_text_field($data['passport_name'] ?? '')),
            'initials' => self::normalize_initials(sanitize_text_field($data['initials'] ?? '')),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
            'mobile' => sanitize_text_field($data['mobile'] ?? ''),
            'emergency_contact' => sanitize_text_field($data['emergency_contact'] ?? ''),
            'diet' => sanitize_text_field($data['diet'] ?? ''),
        ];

        if ($is_admin) {
            [$fields['birth_date'], $fields['birth_year']] = self::parse_birth_date(sanitize_text_field($data['birth_date'] ?? ''));
            $fields['is_student'] = !empty($data['is_student']) ? 1 : 0;
        }

        return $fields;
    }

    /**
     * The Geboortedatum field accepts either a full "JJJJ-MM-DD" or, when
     * the exact date is genuinely unknown, just a year — real but
     * imprecise beats avpvh-bookkeeping's "no birth date at all, assume
     * adult" fallback. Returns [birth_date, birth_year], always exactly
     * one of the two non-null (or both null for an empty/invalid input) —
     * the two columns are mutually exclusive, never both set at once.
     */
    private static function parse_birth_date(string $raw): array {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return [$raw, null];
        }
        if (preg_match('/^(\d{4})$/', $raw, $m)) {
            $year = (int) $m[1];
            if ($year >= 1900 && $year <= (int) current_time('Y')) {
                return [null, $year];
            }
        }
        return [null, null];
    }

    private function role_label(\WP_User $user): string {
        if (empty($user->roles)) {
            return 'Gebruiker';
        }

        return match ($user->roles[0]) {
            'administrator' => 'Beheerder',
            'editor'         => 'Redacteur',
            'author'         => 'Auteur',
            'contributor'    => 'Medewerker',
            'subscriber'     => 'Lid',
            default          => ucfirst(str_replace('_', ' ', $user->roles[0])),
        };
    }

    private function member_role_label(string $role): string {
        if ($role === '') {
            return '';
        }

        return match (strtolower($role)) {
            'bestuur'      => 'Bestuur',
            'feest'        => 'Feest',
            'boek'         => 'Boek',
            'fiscus'       => 'Fiscus',
            'secretariaat'  => 'Secretariaat',
            default        => ucfirst($role),
        };
    }

    /**
     * Enqueue form assets.
     */
    public function enqueue_assets(): void {
        if (is_singular() && has_shortcode(get_post()->post_content ?? '', 'avpvh_member_profile')) {
            wp_enqueue_style(
                'avpvh-registration',
                plugin_dir_url(dirname(__FILE__)) . 'assets/registration.css',
                [],
                avpvh_asset_version('assets/registration.css')
            );

            wp_enqueue_script(
                'avpvh-registration',
                plugin_dir_url(dirname(__FILE__)) . 'assets/registration.js',
                ['jquery'],
                avpvh_asset_version('assets/registration.js'),
                true
            );

            wp_localize_script('avpvh-registration', 'avpvhRegistration', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
            ]);
        }
    }
}

new AVPVH_Member_Profile_Form();
