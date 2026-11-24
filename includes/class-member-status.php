<?php
defined('ABSPATH') || exit;

/**
 * The membership status on Ledendetail (Lid, Ex-lid, Bezoeker) and the
 * directory groups that go with it. The groups leden and ex-leden follow the
 * status (AVPVH_Roles::STATUS_GROUPS): they are set only through the status,
 * never ticked by hand, so the two can't drift apart.
 */
class AVPVH_Member_Status {

    public static function init(): void {
        add_action('admin_post_avpvh_set_status', [self::class, 'handle_set_status']);
    }

    /** The groups that follow the status, not chosen by hand. */
    public static function status_groups(): array {
        return array_values(array_filter(AVPVH_Roles::STATUS_GROUPS));
    }

    /**
     * Which of the hand-chosen groups to add and remove when the groups form
     * is saved. Only PvH groups outside status_groups() can change; anything
     * else in $posted is ignored, and the member keeps the rest.
     *
     * @return array{0: string[], 1: string[]} [add, remove]
     */
    public static function group_changes(array $all_groups, array $current_groups, array $posted): array {
        $choosable = array_values(array_diff(AVPVH_Directory::only_pvh_groups($all_groups), self::status_groups()));
        $selected = array_values(array_intersect(array_map('strtolower', array_map('sanitize_text_field', $posted)), $choosable));
        $current = array_values(array_intersect(AVPVH_Directory::only_pvh_groups($current_groups), $choosable));
        return [array_values(array_diff($selected, $current)), array_values(array_diff($current, $selected))];
    }

    public static function handle_set_status(): void {
        check_admin_referer('avpvh_set_status');
        if (!AVPVH_Roles::can_manage_members()) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), '', ['response' => 403]);
        }
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $member = $member_id ? AVPVH_DB::get_member($member_id) : null;
        if (!$member || !array_key_exists($status, AVPVH_Roles::STATUS_GROUPS)) {
            wp_die(esc_html__('Lid of status niet gevonden.', 'avpvh-members'), '', ['response' => 400]);
        }
        if (!current_user_can('manage_options') && AVPVH_Roles::member_has_elevated_access($member_id)) {
            wp_die(
                esc_html__('Alleen een beheerder mag het e-mailadres, de inlogadressen of de status van een beheerder of bestuurslid wijzigen.', 'avpvh-members'),
                esc_html__('Geen toegang', 'avpvh-members'),
                ['response' => 403]
            );
        }
        self::redirect($member_id, self::apply($member, $status));
    }

    /** Sets the status and the groups that go with it; the query arguments for the notice. */
    private static function apply(object $member, string $status): array {
        $member_id = (int) $member->id;
        if ($status === $member->status) {
            return ['status_saved' => '1'];
        }
        // Overleden, Geroyeerd and the like make a member inactive; taking
        // the kenmerk away is the deliberate step, not this choice.
        if ($status !== 'inactive' && AVPVH_DB::member_has_inactivating_flag($member_id)) {
            return ['status_error' => 'flag'];
        }
        AVPVH_DB::update_member_with_audit($member_id, ['status' => $status], ['%s']);
        // As with Overleden or Geroyeerd: whoever is no longer a member
        // loses board roles and other elevated groups.
        $stripped = $status !== 'active' && AVPVH_Roles::strip_bestuur_roles($member_id);
        $synced = AVPVH_Roles::sync_status_group($member_id);
        return array_filter([
            'status_saved'     => '1',
            'status_error'     => is_wp_error($synced) ? 'sync' : null,
            'bestuur_stripped' => $stripped ? '1' : null,
        ]);
    }

    private static function redirect(int $member_id, array $args): void {
        wp_safe_redirect(add_query_arg(['page' => 'avpvh-member-detail', 'id' => $member_id] + $args, admin_url('admin.php')));
        exit;
    }

    /** The Status row of Ledendetail: a choice for member admins, the label otherwise. */
    public static function render_status_row(object $member): void {
        $labels = AVPVH_Roles::get_status_labels();
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Status', 'avpvh-members'); ?></th>
            <td>
                <?php if (!AVPVH_Roles::can_manage_members()) : ?>
                    <?php echo esc_html(AVPVH_Roles::get_status_label((string) $member->status)); ?>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                        <?php wp_nonce_field('avpvh_set_status'); ?>
                        <input type="hidden" name="action" value="avpvh_set_status">
                        <input type="hidden" name="member_id" value="<?php echo esc_attr((string) $member->id); ?>">
                        <label class="screen-reader-text" for="avpvh-member-status"><?php esc_html_e('Status', 'avpvh-members'); ?></label>
                        <select id="avpvh-member-status" name="status">
                            <?php foreach (array_keys(AVPVH_Roles::STATUS_GROUPS) as $status) : ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($member->status, $status); ?>><?php echo esc_html($labels[$status] ?? $status); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-small"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button>
                    </form>
                    <p class="description"><?php esc_html_e('Zet het lid ook in de groep leden of ex-leden (een bezoeker in geen van beide). Ex-lid of bezoeker haalt bestuursrollen en beheerrechten weg.', 'avpvh-members'); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /** One checkbox of the groups form; the status groups are shown but can't be changed here. */
    public static function render_group_checkbox(string $group, array $current_groups): void {
        $follows_status = in_array($group, self::status_groups(), true);
        ?>
        <label style="display:inline-block;margin-right:1.5rem"<?php echo $follows_status ? ' title="' . esc_attr__('Volgt de status', 'avpvh-members') . '"' : ''; ?>>
            <input type="checkbox" name="groups[]" value="<?php echo esc_attr($group); ?>"
                <?php checked(in_array($group, $current_groups, true)); ?><?php disabled($follows_status); ?>>
            <?php echo esc_html($group); ?>
        </label>
        <?php
    }

    /** The notice after a status change, if any. */
    public static function render_notice(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, set by handle_set_status()'s redirect
        $error = sanitize_key(wp_unslash($_GET['status_error'] ?? ''));
        $saved = !empty($_GET['status_saved']);
        $stripped = !empty($_GET['bestuur_stripped']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $message = match (true) {
            $error === 'flag' => __('Status niet gewijzigd: dit lid heeft een kenmerk dat inactief maakt (zoals Overleden of Geroyeerd). Haal dat kenmerk eerst weg.', 'avpvh-members'),
            $error === 'sync' => __('Status opgeslagen, maar de groepen leden/ex-leden konden niet worden bijgewerkt. Probeer het opnieuw.', 'avpvh-members'),
            $saved && $stripped => __('Status opgeslagen. Dit lid is daardoor uit het bestuur, de bestuursrollen en de beheergroepen gehaald. Vergeet de KVK niet als diegene daar als bestuurder staat.', 'avpvh-members'),
            $saved => __('Status opgeslagen.', 'avpvh-members'),
            default => '',
        };
        if ($message === '') {
            return;
        }
        $type = $stripped ? 'warning' : 'success';
        if ($error !== '') {
            $type = 'error';
        }
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}
