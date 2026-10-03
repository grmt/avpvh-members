<?php
defined('ABSPATH') || exit;

/**
 * [avpvh_bestuur] — the public "Bestuur" page, built from the member data
 * instead of hand-edited text: the current bestuur comes from the LLDAP
 * groups (bestuur plus the officer roles, see AVPVH_Roles), former
 * bestuursleden from the "oud-bestuurder" kenmerk that AVPVH_Roles sets
 * when someone leaves the bestuur. Rollen & delegatie changes show up
 * here without anyone having to edit the page.
 */
class AVPVH_Bestuur_Page {

    private const OFFICER_ORDER = ['voorzitter', 'secretaris', 'penningmeester'];

    public function __construct() {
        add_shortcode('avpvh_bestuur', [$this, 'render']);
    }

    public function render(): string {
        $memberships = $this->group_memberships();

        $officers = [];
        $others   = [];
        foreach ($memberships as $lldap_uid => $groups) {
            $names = array_map('strtolower', $groups);
            $roles = array_values(array_intersect(self::OFFICER_ORDER, $names));
            if (!$roles && !in_array('bestuur', $names, true)) {
                continue;
            }
            $member = AVPVH_DB::get_member_by_lldap_uid($lldap_uid);
            if (!$member) {
                continue;
            }
            if ($roles) {
                $officers[] = ['member' => $member, 'roles' => $roles];
            } else {
                $others[] = $member;
            }
        }

        // Voorzitter, secretaris, penningmeester first, in that order; the
        // other bestuursleden after, by last name.
        usort($officers, static fn($a, $b) =>
            array_search($a['roles'][0], self::OFFICER_ORDER, true) <=> array_search($b['roles'][0], self::OFFICER_ORDER, true));
        usort($others, static fn($a, $b) => strcasecmp(avpvh_format_name($a, 'list'), avpvh_format_name($b, 'list')));

        $current_ids = array_map(static fn($m) => (int) $m->id, array_merge(array_column($officers, 'member'), $others));
        // Geroyeerde oud-bestuurders keep the kenmerk (it's history) but
        // aren't named on the public page.
        $former = array_values(array_filter(
            $this->oud_bestuurders(),
            static fn($m) => !in_array((int) $m->id, $current_ids, true) && !AVPVH_DB::member_has_flag((int) $m->id, 'geroyeerd')
        ));
        usort($former, static fn($a, $b) => strcasecmp(avpvh_format_name($a, 'list'), avpvh_format_name($b, 'list')));

        ob_start();
        ?>
        <div class="avpvh-bestuur">
            <p>Het bestuur van de vereniging bestaat uit:</p>
            <?php if (!$officers && !$others) : ?>
                <p><em>Het bestuur kon op dit moment niet worden opgehaald.</em></p>
            <?php else : ?>
                <p>
                    <?php foreach ($officers as $officer) : ?>
                        <?php echo esc_html(avpvh_format_name($officer['member'])); ?> (<?php echo esc_html(implode(', ', $officer['roles'])); ?>)<br>
                    <?php endforeach; ?>
                    <?php foreach ($others as $member) : ?>
                        <?php echo esc_html(avpvh_format_name($member)); ?><br>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ($former) : ?>
                <p><strong>Voormalig bestuursleden:</strong></p>
                <p><?php echo esc_html(implode(', ', array_map(static fn($m) => avpvh_format_name($m), $former))); ?></p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // Same 15-minute transient AVPVH_Ledenlijst uses (and that AVPVH_Admin /
    // AVPVH_Roles clear on every group change made through the plugin), so a
    // public page view doesn't cost an LLDAP round-trip each time.
    private function group_memberships(): array {
        $map = get_transient('avpvh_all_group_memberships');
        if ($map === false) {
            $result = AVPVH_LLDAP::get_all_group_memberships();
            $map = is_wp_error($result) ? [] : $result;
            set_transient('avpvh_all_group_memberships', $map, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }
        return is_array($map) ? $map : [];
    }

    private function oud_bestuurders(): array {
        global $wpdb;
        $flag_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}avm_member_flags WHERE slug = %s", 'oud-bestuurder'
        ));
        return $flag_id ? AVPVH_DB::get_members(['flag_id' => [$flag_id]]) : [];
    }
}
