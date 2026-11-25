<?php
defined('ABSPATH') || exit;

/**
 * Admin screens for the contribution: the "Contributie aanmaken" panel on
 * the members list and the Contributie tab of a member. Without a
 * bookkeeping plugin they edit avm_fees; with one (AVPVH_Fees::manager())
 * they only show its status and link to it.
 */
class AVPVH_Fees_Admin {

    private const FORM_ACTION = 'admin-post.php';
    private const DETAIL_PAGE = 'avpvh-member-detail';
    private const LIST_PAGE   = 'avpvh-members';

    public function __construct() {
        add_action('admin_post_avpvh_generate_fees', [$this, 'handle_generate']);
        add_action('admin_post_avpvh_save_fee', [$this, 'handle_save']);
        add_action('admin_post_avpvh_delete_fee', [$this, 'handle_delete']);
    }

    public function handle_generate(): void {
        check_admin_referer('avpvh_generate_fees');
        self::require_local_admin();
        $year   = absint(wp_unslash($_POST['year'] ?? 0));
        $amount = self::posted_amount('amount');
        if ($year < 1900 || $amount === null) {
            error_log("AVPVH_Fees_Admin: generate refused, year {$year}"); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure log
            self::redirect_list(['fees_error' => 'input']);
        }
        $result = AVPVH_DB::create_fees_for_year($year, $amount);
        self::redirect_list([
            'fees_year'     => $year,
            'fees_created'  => $result['created'],
            'fees_waived'   => $result['waived'],
            'fees_existing' => $result['existing'],
        ]);
    }

    public function handle_save(): void {
        check_admin_referer('avpvh_save_fee');
        self::require_local_admin();
        $member_id = absint(wp_unslash($_POST['member_id'] ?? 0));
        $year      = absint(wp_unslash($_POST['year'] ?? 0));
        $status    = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        $paid_date = sanitize_text_field(wp_unslash($_POST['paid_date'] ?? ''));
        $ok = AVPVH_DB::save_fee(
            $member_id,
            $year,
            $status,
            self::posted_amount('amount_due'),
            self::posted_amount('amount_paid'),
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $paid_date) ? $paid_date : null
        );
        if (!$ok) {
            error_log("AVPVH_Fees_Admin: save failed for member {$member_id}, year {$year}"); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure log
        }
        self::redirect_detail($member_id, $ok ? ['updated' => '1'] : ['fee_error' => '1']);
    }

    public function handle_delete(): void {
        check_admin_referer('avpvh_delete_fee');
        self::require_local_admin();
        $fee_id    = absint(wp_unslash($_POST['fee_id'] ?? 0));
        $member_id = AVPVH_DB::delete_fee($fee_id);
        if (!$member_id) {
            error_log("AVPVH_Fees_Admin: delete found no fee {$fee_id}"); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational failure log
        }
        self::redirect_detail($member_id ?: absint(wp_unslash($_POST['member_id'] ?? 0)), $member_id ? ['updated' => '1'] : ['fee_error' => '1']);
    }

    /** The members list's panel: create a year's records, or say who keeps the contribution. */
    public static function render_year_panel(int $current_year): void {
        self::render_generate_notice();
        $manager = AVPVH_Fees::manager();
        if ($manager) {
            echo '<p class="description">';
            echo esc_html(sprintf(/* translators: %s: name of the plugin that keeps the contribution, e.g. Boekhouding */ __('De contributie wordt bijgehouden in de %s; de kolom hieronder toont de stand daaruit.', 'avpvh-members'), $manager['label']));
            if ($manager['url'] !== '') {
                echo ' <a href="' . esc_url($manager['url']) . '">' . esc_html__('Naar de tarieven', 'avpvh-members') . '</a>';
            }
            echo '</p>';
            return;
        }
        ?>
        <details class="avpvh-fee-year-panel" style="margin:.5em 0 1em">
            <summary><?php esc_html_e('Contributie aanmaken voor een jaar', 'avpvh-members'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url(self::FORM_ACTION)); ?>" style="display:flex;gap:.75em;align-items:end;flex-wrap:wrap;margin-top:.5em">
                <?php wp_nonce_field('avpvh_generate_fees'); ?>
                <input type="hidden" name="action" value="avpvh_generate_fees">
                <label><?php esc_html_e('Jaar', 'avpvh-members'); ?><br>
                    <input type="number" name="year" min="1900" max="2999" value="<?php echo esc_attr((string) $current_year); ?>" style="width:7em" required></label>
                <label><?php esc_html_e('Bedrag (€)', 'avpvh-members'); ?><br>
                    <input type="text" name="amount" inputmode="decimal" style="width:7em" required></label>
                <?php submit_button(__('Aanmaken voor alle actieve leden', 'avpvh-members'), 'secondary', 'submit', false); ?>
            </form>
            <p class="description"><?php esc_html_e('Leden die al een regel voor dat jaar hebben, blijven zoals ze zijn. Leden met een kenmerk "Vrijgesteld van contributie" krijgen Vrijgesteld. Betalingen zet je daarna per lid op het tabblad Contributie.', 'avpvh-members'); ?></p>
        </details>
        <?php
    }

    /** The Contributie tab of one member. */
    public static function render_member_tab(int $member_id): void {
        $manager = AVPVH_Fees::manager($member_id);
        $rows    = AVPVH_Fees::for_member($member_id);
        ?>
        <h2><?php esc_html_e('Contributieoverzicht', 'avpvh-members'); ?></h2>
        <?php if (!empty($_GET['fee_error'])) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Kon de contributie niet opslaan.', 'avpvh-members'); ?></p></div>
        <?php endif; ?>
        <?php if ($manager) : ?>
            <p class="description">
                <?php echo esc_html(sprintf(/* translators: %s: name of the plugin that keeps the contribution, e.g. Boekhouding */ __('Bijgehouden in de %s.', 'avpvh-members'), $manager['label'])); ?>
                <?php if ($manager['url'] !== '') : ?>
                    <a href="<?php echo esc_url($manager['url']); ?>"><?php esc_html_e('Bekijken of wijzigen', 'avpvh-members'); ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <div style="overflow-x:auto">
        <table class="wp-list-table widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Jaar', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Verschuldigd', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Betaald', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Betaaldatum', 'avpvh-members'); ?></th>
                <th><?php esc_html_e('Status', 'avpvh-members'); ?></th>
                <?php if (!$manager) : ?><th><?php esc_html_e('Actie', 'avpvh-members'); ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="<?php echo $manager ? 5 : 6; ?>"><?php esc_html_e('Geen contributie vastgelegd.', 'avpvh-members'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $row['year']); ?></td>
                    <td><?php echo esc_html(AVPVH_Fees::format_amount($row['amount_due'])); ?></td>
                    <td><?php echo esc_html(AVPVH_Fees::format_amount($row['amount_paid'])); ?></td>
                    <td><?php echo esc_html($row['paid_date'] ? wp_date('d-m-Y', strtotime($row['paid_date'])) : '—'); ?></td>
                    <td><?php echo esc_html(AVPVH_Fees::label($row['status'])); ?></td>
                    <?php if (!$manager) : ?>
                        <td><?php self::render_row_actions($member_id, $row); ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        if (!$manager) {
            self::render_add_form($member_id);
        }
    }

    private static function render_row_actions(int $member_id, array $row): void {
        ?>
        <details>
            <summary><?php esc_html_e('Wijzigen', 'avpvh-members'); ?></summary>
            <?php self::render_fee_form($member_id, $row); ?>
            <form method="post" action="<?php echo esc_url(admin_url(self::FORM_ACTION)); ?>" style="margin-top:.5em"
                data-avpvh-confirm="<?php echo esc_attr(__('Deze contributieregel verwijderen?', 'avpvh-members')); ?>">
                <?php wp_nonce_field('avpvh_delete_fee'); ?>
                <input type="hidden" name="action" value="avpvh_delete_fee">
                <input type="hidden" name="fee_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                <input type="hidden" name="member_id" value="<?php echo esc_attr((string) $member_id); ?>">
                <button type="submit" class="button-link button-link-delete"><?php esc_html_e('Verwijderen', 'avpvh-members'); ?></button>
            </form>
        </details>
        <?php
    }

    private static function render_add_form(int $member_id): void {
        ?>
        <h3 style="margin-top:1rem"><?php esc_html_e('Jaar toevoegen', 'avpvh-members'); ?></h3>
        <?php
        self::render_fee_form($member_id, [
            'year'        => (int) current_time('Y'),
            'status'      => AVPVH_Fees::PENDING,
            'amount_due'  => null,
            'amount_paid' => null,
            'paid_date'   => null,
        ], true);
    }

    /** One record's fields; the year is fixed for an existing record. */
    private static function render_fee_form(int $member_id, array $row, bool $new = false): void {
        $stored = $row['status'] === AVPVH_Fees::PARTIAL ? AVPVH_Fees::PENDING : $row['status'];
        $amount = static fn(?float $value): string => $value === null ? '' : number_format($value, 2, ',', '');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url(self::FORM_ACTION)); ?>" style="display:flex;gap:.5em;align-items:end;flex-wrap:wrap;margin-top:.5em">
            <?php wp_nonce_field('avpvh_save_fee'); ?>
            <input type="hidden" name="action" value="avpvh_save_fee">
            <input type="hidden" name="member_id" value="<?php echo esc_attr((string) $member_id); ?>">
            <?php if ($new) : ?>
                <label><?php esc_html_e('Jaar', 'avpvh-members'); ?><br>
                    <input type="number" name="year" min="1900" max="2999" value="<?php echo esc_attr((string) $row['year']); ?>" style="width:6em" required></label>
            <?php else : ?>
                <input type="hidden" name="year" value="<?php echo esc_attr((string) $row['year']); ?>">
            <?php endif; ?>
            <label><?php esc_html_e('Status', 'avpvh-members'); ?><br>
                <select name="status">
                    <?php foreach (AVPVH_Fees::STORED as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($stored, $status); ?>><?php echo esc_html(AVPVH_Fees::label($status)); ?></option>
                    <?php endforeach; ?>
                </select></label>
            <label><?php esc_html_e('Verschuldigd (€)', 'avpvh-members'); ?><br>
                <input type="text" name="amount_due" inputmode="decimal" value="<?php echo esc_attr($amount($row['amount_due'])); ?>" style="width:6em"></label>
            <label><?php esc_html_e('Betaald (€)', 'avpvh-members'); ?><br>
                <input type="text" name="amount_paid" inputmode="decimal" value="<?php echo esc_attr($amount($row['amount_paid'])); ?>" style="width:6em"></label>
            <label><?php esc_html_e('Betaaldatum', 'avpvh-members'); ?><br>
                <input type="date" name="paid_date" value="<?php echo esc_attr((string) ($row['paid_date'] ?? '')); ?>"></label>
            <button type="submit" class="button button-small"><?php esc_html_e('Opslaan', 'avpvh-members'); ?></button>
        </form>
        <?php
    }

    private static function render_generate_notice(): void {
        if (!empty($_GET['fees_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Vul een jaar en een bedrag in.', 'avpvh-members') . '</p></div>';
            return;
        }
        if (empty($_GET['fees_year'])) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
            /* translators: 1: year, 2: records created, 3: records waived, 4: members that already had one */
            __('Contributie %1$d: %2$d aangemaakt, %3$d vrijgesteld, %4$d hadden er al een.', 'avpvh-members'),
            absint(wp_unslash($_GET['fees_year'])),
            absint(wp_unslash($_GET['fees_created'] ?? 0)),
            absint(wp_unslash($_GET['fees_waived'] ?? 0)),
            absint(wp_unslash($_GET['fees_existing'] ?? 0))
        )) . '</p></div>';
    }

    /** "12,50", "12.50" or "€ 1.012,50" as a float; null when empty or not a number. */
    public static function parse_amount(string $raw): ?float {
        $raw = trim(str_replace(['€', ' '], '', $raw));
        if ($raw === '') {
            return null;
        }
        if (str_contains($raw, ',')) {
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        }
        return is_numeric($raw) && (float) $raw >= 0 ? round((float) $raw, 2) : null;
    }

    private static function posted_amount(string $field): ?float {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller checked the nonce first
        return self::parse_amount(sanitize_text_field(wp_unslash($_POST[$field] ?? '')));
    }

    /** Only the members admin may edit, and only while no bookkeeping plugin keeps the contribution. */
    private static function require_local_admin(): void {
        if (!AVPVH_Roles::can_manage_members()) {
            wp_die(esc_html__('Geen toegang.', 'avpvh-members'), 403);
        }
        if (AVPVH_Fees::is_external()) {
            wp_die(esc_html__('De contributie wordt in de boekhouding bijgehouden.', 'avpvh-members'), 409);
        }
    }

    private static function redirect_list(array $args): never {
        self::redirect(['page' => self::LIST_PAGE] + $args);
    }

    private static function redirect_detail(int $member_id, array $args): never {
        self::redirect(['page' => self::DETAIL_PAGE, 'id' => $member_id, 'tab' => 'fees'] + $args);
    }

    private static function redirect(array $args): never {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
