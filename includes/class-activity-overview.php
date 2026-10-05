<?php
defined('ABSPATH') || exit;

class AVPVH_Activity_Overview {

    public function __construct() {
        add_shortcode('avpvh_activiteit_overzicht', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void {
        if (!is_singular()) {
            return;
        }
        global $post;
        if ($post && has_shortcode($post->post_content, 'avpvh_activiteit_overzicht')) {
            wp_enqueue_style('avpvh-activity-overview', plugin_dir_url(dirname(__FILE__)) . 'assets/activity-overview.css', [], avpvh_asset_version('assets/activity-overview.css'));
        }
    }

    public function render(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Je moet ingelogd zijn om dit overzicht te zien.', 'avpvh-members') . '</p>';
        }

        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member || $member->status !== 'active') {
            return '<p>' . esc_html__('Dit overzicht is alleen beschikbaar voor actieve leden.', 'avpvh-members') . '</p>';
        }

        $activity = AVPVH_DB::get_current_camp_activity();
        if (!$activity) {
            return '<p>' . esc_html__('Er is nog geen overzicht beschikbaar.', 'avpvh-members') . '</p>';
        }

        $participations = AVPVH_DB::get_participation_for_activity((int) $activity->id);
        if (!$participations) {
            return '<p>' . esc_html__('Er is nog geen overzicht beschikbaar.', 'avpvh-members') . '</p>';
        }

        $date_range = [];
        if ($activity->start_date && $activity->end_date) {
            $cursor = new DateTime($activity->start_date);
            $end = new DateTime($activity->end_date);
            while ($cursor <= $end) {
                $date_range[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
        }

        ob_start();
        ?>
        <div class="avpvh-activiteit-overzicht">
            <h2><?php echo esc_html($activity->name . ' ' . $activity->year); ?></h2>
            <p class="avpvh-activiteit-overzicht-meta"><?php printf(esc_html__('Laatst bijgewerkt: %s', 'avpvh-members'), esc_html(date_i18n('j-m-Y'))); ?></p>
            <div class="avpvh-activiteit-overzicht-scroll">
                <table class="avpvh-activiteit-overzicht-tabel">
                    <tr>
                        <td><strong><?php esc_html_e('Naam', 'avpvh-members'); ?></strong></td>
                        <td><strong><?php esc_html_e('Nachten', 'avpvh-members'); ?></strong></td>
                        <td><strong><?php esc_html_e('Nawacht', 'avpvh-members'); ?></strong></td>
                        <td><strong><?php esc_html_e('Dieet', 'avpvh-members'); ?></strong></td>
                        <?php foreach ($date_range as $date) : ?>
                            <td><strong><?php echo esc_html(date_i18n('D j-n', strtotime($date))); ?></strong></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php foreach ($participations as $p) :
                        $name = trim($p->first_name . ' ' . ($p->suffix ? $p->suffix . ' ' : '') . $p->last_name);
                        $days = AVPVH_DB::get_participation_days((int) $p->id);
                    ?>
                        <tr>
                            <td><?php echo esc_html($name); ?></td>
                            <td><?php echo esc_html((string) ($p->nights ?? '')); ?></td>
                            <td><?php echo $p->nawacht ? esc_html__('ja', 'avpvh-members') : ''; ?></td>
                            <td><?php echo esc_html($p->diet ?? ''); ?></td>
                            <?php foreach ($date_range as $date) :
                                $status = $days[$date] ?? '';
                                $color = match ($status) {
                                    'n'     => '#c6efce',
                                    'on'    => '#ffeb9c',
                                    '?'     => '#e0e0e0',
                                    default => null,
                                };
                            ?>
                                <td<?php echo $color ? ' style="background:' . esc_attr($color) . '"' : ''; ?>><?php echo esc_html($status); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
