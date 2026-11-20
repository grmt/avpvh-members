<?php
defined('ABSPATH') || exit;

/**
 * Read-only view of FluentSMTP's e-mail log for officers who may not reach
 * FluentSMTP itself (its only permission filter, fluent_mail/manage_capability,
 * opens its settings and SMTP credentials too). See admin/email-log.php.
 *
 * Logged mails can carry links that act as a key: order confirmations
 * (book_token, tshirt_token), photo shares, personal Google Drive folders,
 * WordPress login keys. Whoever opens one acts as the recipient, so:
 *
 * - such links are replaced by "[link verborgen]" in every body shown;
 * - the body of a mail about logging in or a password is not shown at all,
 *   so a code or link format added later can't slip through.
 */
class AVPVH_Email_Log {

    const PER_PAGE = 20;

    /** Query parameter names that carry a secret. */
    const SECRET_PARAM = '/token|key|confirm|share|nonce|code|hash|sig|secret|pass|auth|newuseremail|activate|reset|^rp$/i';

    /** Hosts whose URLs are themselves the key (anyone with the link). */
    const SECRET_HOSTS = ['drive.google.com', 'docs.google.com', 'photos.google.com', 'photos.app.goo.gl'];

    /** Subjects of mails whose body is never shown. */
    const AUTH_SUBJECT = '/wachtwoord|inloggegevens|inlog|login|password|verifi|beveiligingscode|bevestig|confirm|reset|activat|e-mailadres|email address|2fa|otp|herstel/i';

    const REDACTED = '[link verborgen]';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'fsmpt_email_logs';
    }

    public static function available(): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(self::table()))) === self::table();
    }

    /**
     * One page of the log, newest first, without bodies.
     *
     * @return array{rows: list<object>, total: int}
     */
    public static function query(string $search, string $status, int $page): array {
        global $wpdb;
        $table = self::table();
        // Always one placeholder, so both queries go through prepare().
        $where = ['id > %d'];
        $args = [0];
        if ($status === 'sent' || $status === 'failed') {
            $where[] = 'status = %s';
            $args[] = $status;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(subject LIKE %s OR `to` LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $where_sql = implode(' AND ', $where);
        $offset = max(0, $page - 1) * self::PER_PAGE;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the fixed prefixed table name, $where_sql only holds placeholders, all values are prepared
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $rows_sql = "SELECT id, `to`, subject, status, created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$args));
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...array_merge($args, [self::PER_PAGE, $offset])));
        // phpcs:enable

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    public static function get(int $id): ?object {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed prefixed table name
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, `to`, `from`, subject, body, headers, status, response, created_at FROM {$table} WHERE id = %d", $id));
        return $row ?: null;
    }

    /** The addresses in FluentSMTP's serialized `to` column, comma-separated. */
    public static function recipients(?string $to): string {
        $list = is_string($to) && is_serialized($to) ? unserialize($to, ['allowed_classes' => false]) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- FluentSMTP's own column format; no objects allowed
        if (!is_array($list)) {
            return (string) $to;
        }
        $emails = [];
        foreach ($list as $entry) {
            if (is_array($entry) && !empty($entry['email'])) {
                $emails[] = (string) $entry['email'];
            } elseif (is_string($entry)) {
                $emails[] = $entry;
            }
        }
        return implode(', ', $emails);
    }

    /** True for mails about logging in or a password: their body is never shown. */
    public static function body_hidden(object $row): bool {
        return (bool) preg_match(self::AUTH_SUBJECT, (string) ($row->subject ?? ''));
    }

    public static function is_secret_url(string $url): bool {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if (in_array($host, self::SECRET_HOSTS, true)) {
            return true;
        }
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        foreach (array_keys($query) as $name) {
            if (preg_match(self::SECRET_PARAM, (string) $name)) {
                return true;
            }
        }
        $fragment = (string) wp_parse_url($url, PHP_URL_FRAGMENT);
        if ($fragment !== '') {
            parse_str($fragment, $frag_params);
            foreach (array_keys($frag_params) as $name) {
                if (preg_match(self::SECRET_PARAM, (string) $name)) {
                    return true;
                }
            }
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('#/(?:s|share|token|confirm|reset|verify)/[A-Za-z0-9._~-]{12,}#i', $path)) {
            return true;
        }
        return false;
    }

    /** $html with every secret-bearing URL replaced, in links and in plain text alike. */
    public static function redact(string $html): string {
        return (string) preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            static fn(array $m): string => self::is_secret_url($m[0]) ? self::REDACTED : $m[0],
            $html
        );
    }
}
