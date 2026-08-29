<?php
/**
 * Merge duplicate AVP-PvH member rows using an ignored, local JSON plan.
 *
 * Dry-run is the default. Run inside WordPress with:
 *   AVPVH_MERGE_DRY_RUN=1 wp eval-file scripts/merge-duplicate-members.php
 *   AVPVH_MERGE_DRY_RUN=0 wp eval-file scripts/merge-duplicate-members.php
 *
 * Override the default config path with AVPVH_MEMBER_MERGE_CONFIG. The
 * config contains member-specific IDs and hashes and must match
 * scripts/*.local.* so Git never tracks it.
 */

defined('ABSPATH') || exit;

global $wpdb;

function avpvh_merge_fail(string $message): never
{
    throw new RuntimeException($message);
}

function avpvh_merge_uid_hash(string $uid): string
{
    return hash('sha256', $uid);
}

function avpvh_merge_name_key(object $member): string
{
    $last = strtolower(trim((string) $member->last_name));
    if (str_contains($last, ',')) {
        $last = trim(explode(',', $last, 2)[0]);
    }
    $last = preg_replace(
        '/^(van der|van den|van de|v\/d|vd|ten|ter|de|van|te|von|la|le|du)\s+/iu',
        '',
        $last
    );
    return strtolower(trim((string) $member->first_name)) . '|' . trim((string) $last);
}

function avpvh_merge_is_empty(mixed $value): bool
{
    return $value === null || $value === '' || $value === '0000-00-00';
}

function avpvh_merge_exact_ids(array $actual, array $expected, string $label): void
{
    $actual = array_map('intval', $actual);
    $expected = array_map('intval', $expected);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        avpvh_merge_fail("$label changed; expected IDs do not match live data");
    }
}

function avpvh_merge_source_dependencies(int $member_id): array
{
    global $wpdb;

    $simple_tables = [
        'activity_participation',
        'fees',
        'member_identities',
        'member_flag_assignments',
        'member_audit_log',
    ];
    $counts = [];
    foreach ($simple_tables as $table) {
        $counts[$table] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}avm_{$table} WHERE member_id = %d",
            $member_id
        ));
    }
    $counts['relationships'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}avm_relationships
         WHERE member_id = %d OR related_member_id = %d",
        $member_id,
        $member_id
    ));
    $counts['role_delegations'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}avm_role_delegations
         WHERE delegated_to_member_id = %d OR delegated_by_member_id = %d",
        $member_id,
        $member_id
    ));
    return $counts;
}

$dry_run = getenv('AVPVH_MERGE_DRY_RUN') !== '0';
$config_path = getenv('AVPVH_MEMBER_MERGE_CONFIG')
    ?: __DIR__ . '/merge-duplicate-members.local.json';

if (!is_readable($config_path)) {
    avpvh_merge_fail('Local merge config is missing or unreadable');
}

try {
    $config = json_decode((string) file_get_contents($config_path), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    avpvh_merge_fail('Local merge config is invalid JSON: ' . $exception->getMessage());
}

if (empty($config['merges']) || !is_array($config['merges'])) {
    avpvh_merge_fail('Local merge config has no merges');
}

$member_fields = [
    'birth_date', 'birth_year', 'phone', 'mobile', 'emergency_contact',
    'diet', 'joined_year', 'left_year', 'passport_name', 'initials',
];
$plans = [];

foreach ($config['merges'] as $index => $merge) {
    $source_id = (int) ($merge['source_member_id'] ?? 0);
    $target_id = (int) ($merge['target_member_id'] ?? 0);
    $label = "merge #" . ($index + 1) . " ($source_id->$target_id)";
    if ($source_id < 1 || $target_id < 1 || $source_id === $target_id) {
        avpvh_merge_fail("$label has invalid member IDs");
    }

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}avm_members WHERE id = %d",
        $source_id
    ));
    $target = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}avm_members WHERE id = %d",
        $target_id
    ));
    if (!$source || !$target) {
        avpvh_merge_fail("$label source or target no longer exists");
    }
    if (
        avpvh_merge_name_key($source) !== avpvh_merge_name_key($target)
        && empty($merge['allow_normalized_name_mismatch'])
    ) {
        avpvh_merge_fail("$label normalized names do not match");
    }
    if (!hash_equals((string) ($merge['source_lldap_uid_sha256'] ?? ''), avpvh_merge_uid_hash($source->lldap_user_id))) {
        avpvh_merge_fail("$label source LLDAP hash mismatch");
    }
    if (!hash_equals((string) ($merge['target_lldap_uid_sha256'] ?? ''), avpvh_merge_uid_hash($target->lldap_user_id))) {
        avpvh_merge_fail("$label target LLDAP hash mismatch");
    }
    if ((string) $source->status !== (string) ($merge['expected_source_status'] ?? '')) {
        avpvh_merge_fail("$label source status changed");
    }
    if ((string) $target->status !== (string) ($merge['expected_target_status'] ?? '')) {
        avpvh_merge_fail("$label target status changed");
    }
    if (substr((string) $source->created_at, 0, 10) !== (string) ($merge['expected_source_created_date'] ?? '')) {
        avpvh_merge_fail("$label source creation date changed");
    }
    if ($source->wp_user_id !== null) {
        avpvh_merge_fail("$label source unexpectedly has a WordPress user");
    }

    $source_groups = AVPVH_LLDAP::get_user_groups((string) $source->lldap_user_id);
    if (is_wp_error($source_groups)) {
        avpvh_merge_fail("$label could not verify source LLDAP groups");
    }
    if ($source_groups) {
        avpvh_merge_fail("$label source LLDAP account still has group memberships");
    }

    $dependencies = avpvh_merge_source_dependencies($source_id);
    foreach ($dependencies as $dependency => $count) {
        if ($count !== 0) {
            avpvh_merge_fail("$label source has unsupported $dependency rows: $count");
        }
    }

    $source_address_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d ORDER BY id",
        $source_id
    ));
    $target_address_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}avm_addresses WHERE member_id = %d ORDER BY id",
        $target_id
    ));
    avpvh_merge_exact_ids(
        $source_address_ids,
        $merge['expected_source_address_ids'] ?? [],
        "$label source addresses"
    );
    avpvh_merge_exact_ids(
        $target_address_ids,
        $merge['expected_target_address_ids'] ?? [],
        "$label target addresses"
    );

    $updates = [];
    foreach ($member_fields as $field) {
        $source_value = $source->$field;
        $target_value = $target->$field;
        if (avpvh_merge_is_empty($source_value)) {
            continue;
        }
        if (avpvh_merge_is_empty($target_value)) {
            $updates[$field] = $source_value;
            continue;
        }
        if ((string) $source_value !== (string) $target_value) {
            avpvh_merge_fail("$label conflicting non-empty member field: $field");
        }
    }

    $address_operations = $merge['address_operations'] ?? [];
    $handled_source_ids = [];
    foreach ($address_operations as $operation) {
        $address_id = (int) ($operation['address_id'] ?? 0);
        $expected_owner = (int) ($operation['expected_member_id'] ?? 0);
        $address = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}avm_addresses WHERE id = %d",
            $address_id
        ));
        if (!$address || (int) $address->member_id !== $expected_owner) {
            avpvh_merge_fail("$label address $address_id owner changed");
        }
        if (!in_array($expected_owner, [$source_id, $target_id], true)) {
            avpvh_merge_fail("$label address $address_id belongs outside the pair");
        }
        $action = (string) ($operation['action'] ?? '');
        if (!in_array($action, ['move', 'delete'], true)) {
            avpvh_merge_fail("$label address $address_id has invalid action");
        }
        if ($expected_owner === $source_id) {
            $handled_source_ids[] = $address_id;
        }
    }
    avpvh_merge_exact_ids(
        $handled_source_ids,
        $source_address_ids,
        "$label handled source addresses"
    );

    if (empty($merge['delete_source_lldap'])) {
        avpvh_merge_fail("$label must explicitly enable source LLDAP deletion");
    }

    $plans[] = [
        'label' => $label,
        'source_id' => $source_id,
        'target_id' => $target_id,
        'source_uid' => (string) $source->lldap_user_id,
        'member_updates' => $updates,
        'address_operations' => $address_operations,
    ];
    echo "$label preflight OK; " . count($source_address_ids) . " source addresses; "
        . count($updates) . " member fields to fill\n";
}

if ($dry_run) {
    echo "DRY RUN complete; no database or LLDAP changes made\n";
    return;
}

$wpdb->query('START TRANSACTION');
try {
    foreach ($plans as $plan) {
        if ($plan['member_updates']) {
            $result = $wpdb->update(
                "{$wpdb->prefix}avm_members",
                $plan['member_updates'],
                ['id' => $plan['target_id']]
            );
            if ($result === false) {
                avpvh_merge_fail($plan['label'] . ' target member update failed');
            }
        }

        foreach ($plan['address_operations'] as $operation) {
            $address_id = (int) $operation['address_id'];
            $owner_id = (int) $operation['expected_member_id'];
            if ($operation['action'] === 'delete') {
                $result = $wpdb->delete(
                    "{$wpdb->prefix}avm_addresses",
                    ['id' => $address_id, 'member_id' => $owner_id],
                    ['%d', '%d']
                );
            } else {
                $data = ['member_id' => $plan['target_id']];
                if (array_key_exists('valid_from', $operation)) {
                    $data['valid_from'] = $operation['valid_from'];
                }
                if (array_key_exists('valid_until', $operation)) {
                    $data['valid_until'] = $operation['valid_until'];
                }
                $result = $wpdb->update(
                    "{$wpdb->prefix}avm_addresses",
                    $data,
                    ['id' => $address_id, 'member_id' => $owner_id]
                );
            }
            if ($result !== 1) {
                avpvh_merge_fail($plan['label'] . " address $address_id mutation failed");
            }
        }

        $deleted = $wpdb->delete(
            "{$wpdb->prefix}avm_members",
            ['id' => $plan['source_id']],
            ['%d']
        );
        if ($deleted !== 1) {
            avpvh_merge_fail($plan['label'] . ' source member deletion failed');
        }
    }
    $wpdb->query('COMMIT');
} catch (Throwable $exception) {
    $wpdb->query('ROLLBACK');
    throw $exception;
}

$lldap_failures = [];
foreach ($plans as $plan) {
    $result = AVPVH_LLDAP::delete_user($plan['source_uid']);
    if (is_wp_error($result)) {
        $lldap_failures[] = $plan['source_id'];
        continue;
    }
    echo $plan['label'] . " merged; duplicate LLDAP account deleted\n";
}

if ($lldap_failures) {
    avpvh_merge_fail(
        'Database merge committed, but LLDAP cleanup failed for source member IDs: '
        . implode(',', $lldap_failures)
    );
}

echo "LIVE merge complete\n";
