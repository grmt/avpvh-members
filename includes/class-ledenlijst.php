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

        if ($member->directory_consent !== 'granted') {
            return $this->render_consent_gate();
        }

        $leden = AVPVH_DB::get_members_with_address();
        if (!$leden) {
            return '<p>Geen leden gevonden.</p>';
        }

        ob_start();
        ?>
        <div class="avpvh-ledenlijst">
            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p class="avpvh-ledenlijst-melding">Je voorkeuren zijn opgeslagen.</p>
            <?php endif; ?>
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
                        <td><?php echo esc_html(avpvh_format_name($lid)); ?></td>
                        <td>
                            <?php if ($lid->share_email) : ?>
                                <a href="mailto:<?php echo esc_attr($lid->email); ?>"><?php echo esc_html($lid->email); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lid->share_phone) : ?>
                                <?php if ($lid->mobile) : ?>
                                    <?php echo esc_html($lid->mobile); ?><br>
                                <?php endif; ?>
                                <?php echo esc_html($lid->phone); ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($lid->share_address && $lid->street) : ?>
                                <?php echo esc_html($lid->street . ' ' . $lid->house_number); ?><br>
                                <?php echo esc_html($lid->postal_code . ' ' . $lid->city); ?>
                                <?php if ($lid->country && $lid->country !== 'Nederland') : ?>
                                    <br><?php echo esc_html($lid->country); ?>
                                <?php endif; ?>
                            <?php else : ?>
                                —
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

    private function render_consent_gate(): string {
        ob_start();
        ?>
        <div class="avpvh-ledenlijst-toestemming">
            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p>Je keuze is opgeslagen.</p>
            <?php endif; ?>
            <h2>Toestemming voor de ledenlijst</h2>
            <p>
                Om de ledenlijst te kunnen bekijken, geef je toestemming om jouw naam,
                adresgegevens, e-mailadres en telefoonnummer te delen met andere ingelogde
                actieve leden. Deze gegevens worden alleen gebruikt voor onderling contact
                tussen leden (bijvoorbeeld carpoolen) en verschijnen nooit openbaar op internet.
            </p>
            <p>
                Je kunt deze keuze op elk moment aanpassen via je profiel: je kunt losse
                gegevens (zoals je telefoonnummer) afschermen, of je toestemming volledig
                intrekken.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('avpvh_directory_consent'); ?>
                <input type="hidden" name="action" value="avpvh_set_directory_consent">
                <input type="hidden" name="share_email" value="1">
                <input type="hidden" name="share_phone" value="1">
                <input type="hidden" name="share_address" value="1">
                <button type="submit" name="consent" value="granted" class="button button-primary">Ja, ik ga akkoord</button>
                <button type="submit" name="consent" value="declined" class="button">Nee, niet delen</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
