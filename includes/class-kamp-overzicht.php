<?php
defined('ABSPATH') || exit;

class AVPVH_Kamp_Overzicht {

    const OPTION_NAME = 'avpvh_kamp_2026_overzicht';

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
            wp_enqueue_style('avpvh-kamp-overzicht', plugin_dir_url(dirname(__FILE__)) . 'assets/kamp-overzicht.css', [], '1.0');
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

        $data = json_decode((string) get_option(self::OPTION_NAME), true);
        if (!is_array($data) || empty($data['grid'])) {
            return '<p>Er is nog geen overzicht beschikbaar.</p>';
        }

        ob_start();
        ?>
        <div class="avpvh-kamp-overzicht">
            <h2><?php echo esc_html($data['title'] ?? 'Overzicht inschrijvingen'); ?></h2>
            <?php if (!empty($data['last_updated'])) : ?>
                <p class="avpvh-kamp-overzicht-meta"><?php echo esc_html($data['last_updated']); ?></p>
            <?php endif; ?>
            <?php if (!empty($data['note'])) : ?>
                <p class="avpvh-kamp-overzicht-meta"><?php echo esc_html($data['note']); ?></p>
            <?php endif; ?>
            <div class="avpvh-kamp-overzicht-scroll">
                <table class="avpvh-kamp-overzicht-tabel">
                    <?php foreach ($data['grid'] as $row) : ?>
                        <tr>
                            <?php foreach ($row as $cell) :
                                $style = [];
                                if (!empty($cell['c'])) {
                                    $style[] = 'background:' . $cell['c'];
                                }
                                if (!empty($cell['b'])) {
                                    $style[] = 'font-weight:bold';
                                }
                            ?>
                                <td<?php echo $style ? ' style="' . esc_attr(implode(';', $style)) . '"' : ''; ?>><?php echo esc_html($cell['v'] ?? ''); ?></td>
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
