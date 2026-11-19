<?php
defined('ABSPATH') || exit;

class AVPVH_Ledenlijst {

    public function __construct() {
        add_shortcode('avpvh_ledenlijst', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_post_avpvh_export_ledenlijst', [$this, 'handle_export']);
    }

    // Strips a free-form Dutch phone string (spaces, dashes, slashes, dots)
    // down to a valid tel: URI — keeps a leading "+" for international
    // numbers, digits only otherwise.
    private static function tel_href(string $number): string {
        $digits = preg_replace('/[^0-9+]/', '', $number);
        return 'tel:' . $digits;
    }

    public function enqueue(): void {
        if (!is_singular()) {
            return;
        }
        global $post;
        if ($post && has_shortcode($post->post_content, 'avpvh_ledenlijst')) {
            wp_enqueue_script('avpvh-ledenlijst', plugin_dir_url(dirname(__FILE__)) . 'assets/ledenlijst.js', [], avpvh_asset_version('assets/ledenlijst.js'), true);
            wp_enqueue_style('avpvh-ledenlijst', plugin_dir_url(dirname(__FILE__)) . 'assets/ledenlijst.css', [], avpvh_asset_version('assets/ledenlijst.css'));
        }
    }

    public function render(): string {
        $data = $this->get_leden_data();
        if (!$data) {
            return is_user_logged_in()
                ? '<p>' . esc_html__('De ledenlijst is alleen beschikbaar voor actieve leden.', 'avpvh-members') . '</p>'
                : '<p>' . esc_html__('Je moet ingelogd zijn om de ledenlijst te zien.', 'avpvh-members') . '</p>';
        }
        ['own_member' => $own_member, 'is_admin' => $is_admin, 'leden' => $leden, 'group_map' => $group_map] = $data;
        if (!$leden) {
            return '<p>' . esc_html__('Geen leden gevonden.', 'avpvh-members') . '</p>';
        }
        $editable_ids = self::editable_ids($own_member, $is_admin);

        ob_start();
        ?>
        <div class="avpvh-ledenlijst">
            <?php self::render_intro(); ?>

            <?php self::render_controls($leden, $group_map); ?>

            <div class="avpvh-ledenlijst-tabel-scroll">
            <table class="avpvh-ledenlijst-tabel" id="avpvh-ledenlijst-tabel">
                <?php self::render_table_head(); ?>
                <tbody>
                <?php foreach ($leden as $lid) {
                    self::render_row($lid, $group_map[$lid->lldap_user_id] ?? [], $editable_ids === null || in_array((int) $lid->id, $editable_ids, true));
                } ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * IDs whose name links to the (edit) profile page, or null for everyone.
     * A name is only a link for members you're actually allowed to edit there
     * — own profile, household, or admin — otherwise it's a dead end
     * (member-profile silently falls back to your own profile for an
     * unauthorized member_id).
     */
    private static function editable_ids(object $own_member, bool $is_admin): ?array {
        if ($is_admin) {
            return null;
        }
        // wpdb returns column values as strings, so these must be cast to int
        // explicitly — the strict in_array() check would otherwise never
        // match (97 !== "97").
        return array_map('intval', array_column(AVPVH_DB::get_manageable_members((int) $own_member->id), 'id'));
    }

    private static function render_intro(): void {
        ?>
            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p class="avpvh-ledenlijst-melding"><?php esc_html_e('Je voorkeuren zijn opgeslagen.', 'avpvh-members'); ?></p>
            <?php endif; ?>
            <p class="avpvh-ledenlijst-uitleg">
                <?php
                echo wp_kses(sprintf(
                    /* translators: 1: privacy policy URL, 2: profile page URL */
                    __('Deze lijst wordt gedeeld met alle ingelogde actieve leden, zoals beschreven in de <a href="%1$s" target="_blank" rel="noopener">privacyverklaring</a>. Je kunt via <a href="%2$s">je profiel</a> losse gegevens afschermen of je gegevens volledig verbergen.', 'avpvh-members'),
                    esc_url('https://www.avphilipsvanhorne.nl/wp-content/uploads/public/2024/04/Privacy-Verklaring.pdf'),
                    esc_url(home_url('/member-profile/'))
                ), ['a' => ['href' => [], 'target' => [], 'rel' => []]]);
                ?>
            </p>
        <?php
    }

    /** Sorted filter values: the cities members share, and every group shown. */
    private static function filter_options(array $leden, array $group_map): array {
        $cities = [];
        $groups = [];
        foreach ($leden as $lid) {
            if ($lid->share_address && $lid->city) {
                $cities[$lid->city] = true;
            }
            foreach ($group_map[$lid->lldap_user_id] ?? [] as $g) {
                $groups[$g] = true;
            }
        }
        ksort($cities, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        return [array_keys($cities), array_keys($groups)];
    }

    private static function render_controls(array $leden, array $group_map): void {
        [$cities, $groups] = self::filter_options($leden, $group_map);
        ?>
            <div class="avpvh-ledenlijst-controls">
                <label class="screen-reader-text" for="avpvh-ledenlijst-zoek"><?php esc_html_e('Zoeken in de ledenlijst', 'avpvh-members'); ?></label>
                <input type="search" id="avpvh-ledenlijst-zoek" placeholder="<?php esc_attr_e('Zoeken…', 'avpvh-members'); ?>" class="avpvh-ledenlijst-zoek">

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="plaats">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false"><?php esc_html_e('Plaats', 'avpvh-members'); ?> ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <?php foreach ($cities as $city) : ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($city); ?>"> <?php echo esc_html($city); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="groep">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false"><?php esc_html_e('Groep', 'avpvh-members'); ?> ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <?php foreach ($groups as $group) : ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($group); ?>"> <?php echo esc_html(ucfirst($group)); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a class="avpvh-ledenlijst-export"
                   href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'avpvh_export_ledenlijst', admin_url('admin-post.php')), 'avpvh_export_ledenlijst')); ?>"><?php esc_html_e('Exporteer naar Excel', 'avpvh-members'); ?></a>

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="kolommen">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false"><?php esc_html_e('Kolommen', 'avpvh-members'); ?> ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <label><input type="checkbox" data-col="naam" checked> <?php esc_html_e('Naam', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="email" checked> <?php esc_html_e('E-mail', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="telefoon" checked> <?php esc_html_e('Telefoon', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="adres" checked> <?php esc_html_e('Adres', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="postcode"> <?php esc_html_e('Postcode', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="plaats"> <?php esc_html_e('Plaats', 'avpvh-members'); ?></label>
                        <label><input type="checkbox" data-col="groepen"> <?php esc_html_e('Groepen', 'avpvh-members'); ?></label>
                    </div>
                </div>
            </div>
        <?php
    }

    private static function render_table_head(): void {
        ?>
                <thead>
                    <tr>
                        <th class="col-naam is-sortable" data-sort-key="naam"><?php esc_html_e('Naam', 'avpvh-members'); ?></th>
                        <th class="col-email is-sortable" data-sort-key="email"><?php esc_html_e('E-mail', 'avpvh-members'); ?></th>
                        <th class="col-telefoon is-sortable" data-sort-key="telefoon"><?php esc_html_e('Telefoon', 'avpvh-members'); ?></th>
                        <th class="col-adres"><?php esc_html_e('Adres', 'avpvh-members'); ?></th>
                        <th class="col-postcode avpvh-col-hidden is-sortable" data-sort-key="postcode"><?php esc_html_e('Postcode', 'avpvh-members'); ?></th>
                        <th class="col-plaats avpvh-col-hidden is-sortable" data-sort-key="plaats"><?php esc_html_e('Plaats', 'avpvh-members'); ?></th>
                        <th class="col-groepen avpvh-col-hidden is-sortable" data-sort-key="groepen"><?php esc_html_e('Groepen', 'avpvh-members'); ?></th>
                    </tr>
                </thead>
        <?php
    }

    /**
     * A placeholder OpenLDAP address (members with no real e-mail, e.g.
     * under-16s — see handle_add_member()) isn't a real contact address, so
     * it's never shown here even when share_email is on.
     */
    private static function shows_email(object $lid): bool {
        return $lid->share_email && !str_ends_with(strtolower($lid->email ?? ''), '@avpvh.local');
    }

    /**
     * vCard download is generated client-side from these data attributes —
     * only ever the fields already shown in the row, so it can never leak more
     * than the visible row does. Every value is esc_attr()'d.
     */
    private static function vcard_attrs(object $lid, string $name): string {
        $attrs = ['name' => $name];
        if (self::shows_email($lid)) {
            $attrs['email'] = $lid->email;
        }
        if ($lid->share_phone) {
            $attrs += array_filter(['cell' => $lid->mobile, 'tel' => $lid->phone]);
        }
        if ($lid->share_address && $lid->street) {
            $attrs['adr'] = AVPVH_Address_Normalizer::format_street_line($lid) . ';' . $lid->city . ';' . $lid->postal_code . ';' . $lid->country;
        }
        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' data-vcard-' . $key . '="' . esc_attr($value) . '"';
        }
        return $html;
    }

    private static function render_row(object $lid, array $lid_groups, bool $can_edit): void {
        $naam_text = avpvh_format_name($lid);
        $naam = esc_html($naam_text);
        if ($can_edit) {
            $naam = '<a href="' . esc_url(add_query_arg('member_id', $lid->id, home_url('/member-profile/'))) . '">' . $naam . '</a>';
        }
        $address = $lid->share_address;
        ?>
                    <tr data-city="<?php echo esc_attr($address ? $lid->city : ''); ?>"
                        data-groups="<?php echo esc_attr(implode(',', $lid_groups)); ?>">
                        <td class="col-naam" data-label="<?php esc_attr_e('Naam', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr(strtolower(avpvh_format_name($lid, 'list'))); ?>">
                            <?php
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html()/esc_url() pieces
                            echo $naam;
                            ?>
                            <button type="button" class="avpvh-vcard-btn" title="<?php esc_attr_e('Downloaden als contact (vCard)', 'avpvh-members'); ?>"<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute value is esc_attr()'d in vcard_attrs()
                                echo self::vcard_attrs($lid, $naam_text);
                            ?>>📇</button>
                        </td>
                        <?php self::render_email_cell($lid); ?>
                        <?php self::render_phone_cell($lid); ?>
                        <?php self::render_address_cell($lid); ?>
                        <td class="col-postcode avpvh-col-hidden" data-label="<?php esc_attr_e('Postcode', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr($address ? strtolower($lid->postal_code) : ''); ?>">
                            <?php echo esc_html($address && $lid->postal_code ? $lid->postal_code : '—'); ?>
                        </td>
                        <td class="col-plaats avpvh-col-hidden" data-label="<?php esc_attr_e('Plaats', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr($address ? strtolower($lid->city) : ''); ?>">
                            <?php echo esc_html($address && $lid->city ? $lid->city : '—'); ?>
                        </td>
                        <td class="col-groepen avpvh-col-hidden" data-label="<?php esc_attr_e('Groepen', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr(strtolower(implode(', ', $lid_groups))); ?>">
                            <?php echo esc_html($lid_groups ? implode(', ', array_map('ucfirst', $lid_groups)) : '—'); ?>
                        </td>
                    </tr>
        <?php
    }

    private static function render_email_cell(object $lid): void {
        $show_email = self::shows_email($lid);
        ?>
                        <td class="col-email" data-label="<?php esc_attr_e('E-mail', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr($show_email ? strtolower($lid->email) : ''); ?>">
                            <?php if ($show_email) : ?>
                                <a href="mailto:<?php echo esc_attr($lid->email); ?>"><?php echo esc_html($lid->email); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
        <?php
    }

    private static function render_phone_cell(object $lid): void {
        $sort = $lid->mobile ?: $lid->phone;
        ?>
                        <td class="col-telefoon" data-label="<?php esc_attr_e('Telefoon', 'avpvh-members'); ?>" data-sort-value="<?php echo esc_attr($lid->share_phone ? $sort : ''); ?>">
                            <?php if ($lid->share_phone) : ?>
                                <?php if ($lid->mobile) : ?>
                                    <a href="<?php echo esc_attr(self::tel_href($lid->mobile)); ?>"><?php echo esc_html($lid->mobile); ?></a><br>
                                <?php endif; ?>
                                <?php if ($lid->phone) : ?>
                                    <a href="<?php echo esc_attr(self::tel_href($lid->phone)); ?>"><?php echo esc_html($lid->phone); ?></a>
                                <?php endif; ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
        <?php
    }

    private static function render_address_cell(object $lid): void {
        ?>
                        <td class="col-adres" data-label="<?php esc_attr_e('Adres', 'avpvh-members'); ?>">
                            <?php if ($lid->share_address && $lid->street) : ?>
                                <?php echo esc_html(AVPVH_Address_Normalizer::format_street_line($lid)); ?><br>
                                <?php echo esc_html($lid->postal_code . ' ' . $lid->city); ?>
                                <?php if ($lid->country && $lid->country !== 'Nederland') : ?>
                                    <br><?php echo esc_html($lid->country); ?>
                                <?php endif; ?>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
        <?php
    }

    // Shared by render() and handle_export() so the exported .xlsx can never
    // drift from (or exceed) what the on-screen table actually shows.
    private function get_leden_data(): ?array {
        if (!is_user_logged_in()) {
            return null;
        }
        $own_member = avpvh_get_member_by_wp_user(get_current_user_id());
        if (!$own_member || $own_member->status !== 'active') {
            return null;
        }

        $is_admin = current_user_can('manage_options');
        $is_bestuur = $is_admin || strtolower((string) get_user_meta(get_current_user_id(), 'avpvh_member_role', true)) === 'bestuur';
        $leden = AVPVH_DB::get_members_with_address((int) $own_member->id, $is_bestuur);

        $group_map = AVPVH_Directory::cached_all_group_memberships();

        return ['own_member' => $own_member, 'is_admin' => $is_admin, 'leden' => $leden, 'group_map' => $group_map];
    }

    public function handle_export(): void {
        check_admin_referer('avpvh_export_ledenlijst');

        $data = $this->get_leden_data();
        if (!$data) {
            wp_die(esc_html__('De ledenlijst is alleen beschikbaar voor actieve leden.', 'avpvh-members'), esc_html__('Geen toegang', 'avpvh-members'), ['response' => 403]);
        }

        require_once AVPVH_PLUGIN_DIR . 'includes/class-ledenlijst-export.php';
        $bytes = AVPVH_Ledenlijst_Export::build($data['leden'], $data['group_map']);

        $filename = sanitize_file_name('ledenlijst-' . current_time('Y-m-d') . '.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw .xlsx binary file download, not HTML
        echo $bytes;
        exit;
    }
}
