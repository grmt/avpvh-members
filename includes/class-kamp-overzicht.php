<?php
defined('ABSPATH') || exit;

class AVPVH_Kamp_Overzicht {

    public function __construct() {
        add_shortcode('avpvh_kamp_overzicht', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void {
        if (!is_singular()) {
            return;
        }
        global $post;
        if ($post && has_shortcode($post->post_content, 'avpvh_kamp_overzicht')) {
            wp_enqueue_style('avpvh-kamp-overzicht', plugin_dir_url(dirname(__FILE__)) . 'assets/kamp-overzicht.css', [], avpvh_asset_version('assets/kamp-overzicht.css'));
        }
    }

    public function render(): string {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om dit overzicht te zien.</p>';
        }

        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member || $member->status !== 'active') {
            return '<p>Dit overzicht is alleen beschikbaar voor actieve leden.</p>';
        }

        $camp = AVPVH_DB::get_current_camp();
        if (!$camp) {
            return '<p>Er is nog geen overzicht beschikbaar.</p>';
        }

        $participations = AVPVH_DB::get_participation_for_camp((int) $camp->id);
        if (!$participations) {
            return '<p>Er is nog geen overzicht beschikbaar.</p>';
        }

        $date_range = [];
        if ($camp->start_date && $camp->end_date) {
            $cursor = new DateTime($camp->start_date);
            $end = new DateTime($camp->end_date);
            while ($cursor <= $end) {
                $date_range[] = $cursor->format('Y-m-d');
                $cursor->modify('+1 day');
            }
        }

        ob_start();
        ?>
        <div class="avpvh-kamp-overzicht">
            <h2><?php echo esc_html($camp->name . ' ' . $camp->year); ?></h2>
            <p class="avpvh-kamp-overzicht-meta">Laatst bijgewerkt: <?php echo esc_html(date_i18n('j-m-Y')); ?></p>
            <div class="avpvh-kamp-overzicht-scroll">
                <table class="avpvh-kamp-overzicht-tabel">
                    <tr>
                        <td><strong>Naam</strong></td>
                        <td><strong>Nachten</strong></td>
                        <td><strong>Nawacht</strong></td>
                        <td><strong>Dieet</strong></td>
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
                            <td><?php echo $p->nawacht ? 'ja' : ''; ?></td>
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
