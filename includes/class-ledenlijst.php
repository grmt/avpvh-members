<?php
defined('ABSPATH') || exit;

class AVPVH_Ledenlijst {

    public function __construct() {
        add_shortcode('avpvh_ledenlijst', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void {
        if (!is_singular()) {
            return;
        }
        global $post;
        if ($post && has_shortcode($post->post_content, 'avpvh_ledenlijst')) {
            wp_enqueue_script('avpvh-ledenlijst', plugin_dir_url(dirname(__FILE__)) . 'assets/ledenlijst.js', [], '1.0', true);
            wp_enqueue_style('avpvh-ledenlijst', plugin_dir_url(dirname(__FILE__)) . 'assets/ledenlijst.css', [], '1.0');
        }
    }

    public function render(): string {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om de ledenlijst te zien.</p>';
        }

        $member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$member || $member->status !== 'active') {
            return '<p>De ledenlijst is alleen beschikbaar voor actieve leden.</p>';
        }

        $leden = AVPVH_DB::get_members_with_address();
        if (!$leden) {
            return '<p>Geen leden gevonden.</p>';
        }

        ob_start();
        ?>
        <div class="avpvh-ledenlijst">
            <input type="search" id="avpvh-ledenlijst-zoek" placeholder="Zoeken…" class="avpvh-ledenlijst-zoek">
            <table class="avpvh-ledenlijst-tabel">
                <thead>
                    <tr>
                        <th>Naam</th>
                        <th>E-mail</th>
                        <th>Telefoon</th>
                        <th>Adres</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leden as $lid) : ?>
                    <tr>
                        <td><?php echo esc_html($lid->first_name . ' ' . $lid->last_name); ?></td>
                        <td><a href="mailto:<?php echo esc_attr($lid->email); ?>"><?php echo esc_html($lid->email); ?></a></td>
                        <td>
                            <?php if ($lid->mobile) : ?>
                                <?php echo esc_html($lid->mobile); ?><br>
                            <?php endif; ?>
                            <?php echo esc_html($lid->phone); ?>
                        </td>
                        <td>
                            <?php if ($lid->street) : ?>
                                <?php echo esc_html($lid->street . ' ' . $lid->house_number); ?><br>
                                <?php echo esc_html($lid->postal_code . ' ' . $lid->city); ?>
                                <?php if ($lid->country && $lid->country !== 'Nederland') : ?>
                                    <br><?php echo esc_html($lid->country); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
