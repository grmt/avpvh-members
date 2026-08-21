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
                ? '<p>De ledenlijst is alleen beschikbaar voor actieve leden.</p>'
                : '<p>Je moet ingelogd zijn om de ledenlijst te zien.</p>';
        }
        ['own_member' => $own_member, 'is_admin' => $is_admin, 'leden' => $leden, 'group_map' => $group_map] = $data;
        if (!$leden) {
            return '<p>Geen leden gevonden.</p>';
        }

        // A name is only a link to the (edit) profile page for members you're
        // actually allowed to edit there — own profile, household, or admin —
        // otherwise it's a dead end (member-profile silently falls back to
        // your own profile for an unauthorized member_id).
        $editable_ids = $is_admin
            ? null // null = everyone
            // wpdb returns column values as strings, so these must be cast to
            // int explicitly — the strict in_array() check below would
            // otherwise never match (97 !== "97").
            : array_map('intval', array_column(AVPVH_DB::get_manageable_members((int) $own_member->id), 'id'));

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

        ob_start();
        ?>
        <div class="avpvh-ledenlijst">
            <?php if (!empty($_GET['consent_saved'])) : ?>
                <p class="avpvh-ledenlijst-melding">Je voorkeuren zijn opgeslagen.</p>
            <?php endif; ?>
            <p class="avpvh-ledenlijst-uitleg">
                Deze lijst wordt gedeeld met alle ingelogde actieve leden, zoals beschreven
                in de <a href="https://www.avphilipsvanhorne.nl/wp-content/uploads/public/2024/04/Privacy-Verklaring.pdf" target="_blank" rel="noopener">privacyverklaring</a>.
                Je kunt via <a href="<?php echo esc_url(home_url('/member-profile/')); ?>">je profiel</a>
                losse gegevens afschermen of je gegevens volledig verbergen.
            </p>

            <div class="avpvh-ledenlijst-controls">
                <input type="search" id="avpvh-ledenlijst-zoek" placeholder="Zoeken…" class="avpvh-ledenlijst-zoek">

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="plaats">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false">Plaats ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <?php foreach (array_keys($cities) as $city) : ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($city); ?>"> <?php echo esc_html($city); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="groep">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false">Groep ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <?php foreach (array_keys($groups) as $group) : ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($group); ?>"> <?php echo esc_html(ucfirst($group)); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a class="avpvh-ledenlijst-export"
                   href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'avpvh_export_ledenlijst', admin_url('admin-post.php')), 'avpvh_export_ledenlijst')); ?>">Exporteer naar Excel</a>

                <div class="avpvh-ledenlijst-dropdown" data-dropdown="kolommen">
                    <button type="button" class="avpvh-ledenlijst-dropdown-toggle" aria-expanded="false">Kolommen ▾</button>
                    <div class="avpvh-ledenlijst-dropdown-menu" hidden>
                        <label><input type="checkbox" data-col="naam" checked> Naam</label>
                        <label><input type="checkbox" data-col="email" checked> E-mail</label>
                        <label><input type="checkbox" data-col="telefoon" checked> Telefoon</label>
                        <label><input type="checkbox" data-col="adres" checked> Adres</label>
                        <label><input type="checkbox" data-col="postcode"> Postcode</label>
                        <label><input type="checkbox" data-col="plaats"> Plaats</label>
                        <label><input type="checkbox" data-col="groepen"> Groepen</label>
                    </div>
                </div>
            </div>

            <div class="avpvh-ledenlijst-tabel-scroll">
            <table class="avpvh-ledenlijst-tabel" id="avpvh-ledenlijst-tabel">
                <thead>
                    <tr>
                        <th class="col-naam is-sortable" data-sort-key="naam">Naam</th>
                        <th class="col-email is-sortable" data-sort-key="email">E-mail</th>
                        <th class="col-telefoon is-sortable" data-sort-key="telefoon">Telefoon</th>
                        <th class="col-adres">Adres</th>
                        <th class="col-postcode avpvh-col-hidden is-sortable" data-sort-key="postcode">Postcode</th>
                        <th class="col-plaats avpvh-col-hidden is-sortable" data-sort-key="plaats">Plaats</th>
                        <th class="col-groepen avpvh-col-hidden is-sortable" data-sort-key="groepen">Groepen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($leden as $lid) :
                    $lid_groups = $group_map[$lid->lldap_user_id] ?? [];
                    $can_edit = $is_admin || ($editable_ids !== null && in_array((int) $lid->id, $editable_ids, true));
                    $naam_text = avpvh_format_name($lid);
                    $naam = esc_html($naam_text);
                    if ($can_edit) {
                        $naam = '<a href="' . esc_url(add_query_arg('member_id', $lid->id, home_url('/member-profile/'))) . '">' . $naam . '</a>';
                    }

                    // A placeholder LLDAP address (members with no real
                    // e-mail, e.g. under-16s — see handle_add_member()) isn't
                    // a real contact address, so it's never shown here even
                    // when share_email is on.
                    $show_email = $lid->share_email && !str_ends_with(strtolower($lid->email ?? ''), '@avpvh.local');

                    // vCard download is generated client-side from these data
                    // attributes — only ever the fields already shown here,
                    // so it can never leak more than the visible row does.
                    $vcard_attrs = ' data-vcard-name="' . esc_attr($naam_text) . '"';
                    if ($show_email) {
                        $vcard_attrs .= ' data-vcard-email="' . esc_attr($lid->email) . '"';
                    }
                    if ($lid->share_phone) {
                        if ($lid->mobile) {
                            $vcard_attrs .= ' data-vcard-cell="' . esc_attr($lid->mobile) . '"';
                        }
                        if ($lid->phone) {
                            $vcard_attrs .= ' data-vcard-tel="' . esc_attr($lid->phone) . '"';
                        }
                    }
                    if ($lid->share_address && $lid->street) {
                        $vcard_attrs .= ' data-vcard-adr="' . esc_attr($lid->street . ' ' . $lid->house_number . ';' . $lid->city . ';' . $lid->postal_code . ';' . $lid->country) . '"';
                    }
                    ?>
                    <tr data-city="<?php echo esc_attr($lid->share_address ? $lid->city : ''); ?>"
                        data-groups="<?php echo esc_attr(implode(',', $lid_groups)); ?>">
                        <td class="col-naam" data-label="Naam" data-sort-value="<?php echo esc_attr(strtolower(avpvh_format_name($lid, 'list'))); ?>">
                            <?php echo $naam; ?>
                            <button type="button" class="avpvh-vcard-btn" title="Downloaden als contact (vCard)"<?php echo $vcard_attrs; ?>>📇</button>
                        </td>
                        <td class="col-email" data-label="E-mail" data-sort-value="<?php echo esc_attr($show_email ? strtolower($lid->email) : ''); ?>">
                            <?php if ($show_email) : ?>
                                <a href="mailto:<?php echo esc_attr($lid->email); ?>"><?php echo esc_html($lid->email); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="col-telefoon" data-label="Telefoon" data-sort-value="<?php echo esc_attr($lid->share_phone ? ($lid->mobile ?: $lid->phone) : ''); ?>">
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
                        <td class="col-adres" data-label="Adres">
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
                        <td class="col-postcode avpvh-col-hidden" data-label="Postcode" data-sort-value="<?php echo esc_attr($lid->share_address ? strtolower($lid->postal_code) : ''); ?>">
                            <?php echo esc_html($lid->share_address ? ($lid->postal_code ?: '—') : '—'); ?>
                        </td>
                        <td class="col-plaats avpvh-col-hidden" data-label="Plaats" data-sort-value="<?php echo esc_attr($lid->share_address ? strtolower($lid->city) : ''); ?>">
                            <?php echo esc_html($lid->share_address ? ($lid->city ?: '—') : '—'); ?>
                        </td>
                        <td class="col-groepen avpvh-col-hidden" data-label="Groepen" data-sort-value="<?php echo esc_attr(strtolower(implode(', ', $lid_groups))); ?>">
                            <?php echo esc_html($lid_groups ? implode(', ', array_map('ucfirst', $lid_groups)) : '—'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
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

        $group_map = get_transient('avpvh_all_group_memberships');
        if ($group_map === false) {
            $result = AVPVH_LLDAP::get_all_group_memberships();
            $group_map = is_wp_error($result) ? [] : $result;
            set_transient('avpvh_all_group_memberships', $group_map, is_wp_error($result) ? MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        }

        return ['own_member' => $own_member, 'is_admin' => $is_admin, 'leden' => $leden, 'group_map' => $group_map];
    }

    public function handle_export(): void {
        check_admin_referer('avpvh_export_ledenlijst');

        $data = $this->get_leden_data();
        if (!$data) {
            wp_die('De ledenlijst is alleen beschikbaar voor actieve leden.', 'Geen toegang', ['response' => 403]);
        }

        require_once AVPVH_PLUGIN_DIR . 'includes/class-ledenlijst-export.php';
        $bytes = AVPVH_Ledenlijst_Export::build($data['leden'], $data['group_map']);

        $filename = sanitize_file_name('ledenlijst-' . date('Y-m-d') . '.xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
}
