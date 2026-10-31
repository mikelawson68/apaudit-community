<?php
/**
 * Plugin Name: apAudit
 * Description: ArtistPro-owned WordPress activity history, accountability, retention, and CSV export.
 * Version: 0.1.5
 * Author: artistpro LLC
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: apaudit
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

define('APAUDIT_VERSION', '0.1.5');


function apaudit_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'apaudit_events';
}

function apaudit_install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = apaudit_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(80) NOT NULL,
        object_type varchar(80) DEFAULT NULL,
        object_id bigint(20) unsigned DEFAULT NULL,
        actor_id bigint(20) unsigned DEFAULT NULL,
        actor_ip varchar(45) DEFAULT NULL,
        message text NOT NULL,
        context_json longtext DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY event_type (event_type),
        KEY object_lookup (object_type,object_id),
        KEY actor_id (actor_id),
        KEY created_at (created_at)
    ) {$charset};");
    update_option('apaudit_db_version', APAUDIT_VERSION);
    if (get_option('apaudit_retention_days', null) === null) update_option('apaudit_retention_days', '180');
}
register_activation_hook(__FILE__, 'apaudit_install');
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('apaudit_daily_cleanup');
});


function apaudit_record(string $type, string $message, string $object_type = '', int $object_id = 0, array $context = [], ?int $actor_id = null): void {
    global $wpdb;
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Activity history uses its own append-only event table.
    $wpdb->insert(apaudit_table(), [
        'event_type' => sanitize_key($type),
        'object_type' => sanitize_key($object_type),
        'object_id' => $object_id ?: null,
        'actor_id' => ($actor_id ?? get_current_user_id()) ?: null,
        'actor_ip' => $ip,
        'message' => sanitize_text_field($message),
        'context_json' => $context ? wp_json_encode($context) : null,
        'created_at' => current_time('mysql', true),
    ]);
}

add_action('wp_login', static fn(string $login, WP_User $user) => apaudit_record('user_login', "User signed in: {$login}", 'user', (int) $user->ID, [], (int) $user->ID), 10, 2);
// Failed login input is untrusted and may contain a password entered in the wrong field.
add_action('wp_login_failed', static fn() => apaudit_record('login_failed', 'Failed sign-in'));
add_action('user_register', static fn(int $id) => apaudit_record('user_created', 'User account created', 'user', $id));
add_action('profile_update', static fn(int $id) => apaudit_record('user_updated', 'User profile updated', 'user', $id));
add_action('delete_user', static fn(int $id) => apaudit_record('user_deleted', 'User account deleted', 'user', $id));
add_action('save_post', static function (int $id, WP_Post $post, bool $update): void {
    if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
    apaudit_record($update ? 'content_updated' : 'content_created', ($update ? 'Updated: ' : 'Created: ') . $post->post_title, $post->post_type, $id);
}, 10, 3);
add_action('before_delete_post', static function (int $id): void { $post = get_post($id); if ($post) apaudit_record('content_deleted', 'Deleted: ' . $post->post_title, $post->post_type, $id); });
add_action('activated_plugin', static fn(string $plugin) => apaudit_record('plugin_activated', "Plugin activated: {$plugin}", 'plugin'));
add_action('deactivated_plugin', static fn(string $plugin) => apaudit_record('plugin_deactivated', "Plugin deactivated: {$plugin}", 'plugin'));
add_action('switch_theme', static fn(string $name) => apaudit_record('theme_switched', "Theme activated: {$name}", 'theme'));
add_action('upgrader_process_complete', static fn($upgrader, array $options) => apaudit_record('software_updated', 'WordPress software update completed', 'system'), 10, 2);

add_action('apaudit_daily_cleanup', static function (): void {
    global $wpdb;
    $days = max(30, min(3650, (int) get_option('apaudit_retention_days', '180')));
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention deletes expired rows from the custom event table.
    $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', apaudit_table(), $cutoff));
});
add_action('init', static function (): void { if (!wp_next_scheduled('apaudit_daily_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'apaudit_daily_cleanup'); });

add_action('admin_init', static function (): void {
    if (!function_exists('wp_add_privacy_policy_content')) return;
    $content = '<p>' . esc_html__('apAudit stores site activity, usernames, event details, and the remote IP address seen by WordPress. Data stays on this site. Records are retained for 180 days by default and older records are removed by scheduled cleanup. Site administrators can view and export the history.', 'apaudit') . '</p>';
    wp_add_privacy_policy_content('apAudit', wp_kses_post($content));
});

// Integrate with WordPress personal-data requests without deleting the audit history.
add_filter('wp_privacy_personal_data_exporters', static function (array $exporters): array {
    $exporters['apaudit'] = ['exporter_friendly_name' => __('apAudit activity history', 'apaudit'), 'callback' => 'apaudit_export_personal_data'];
    return $exporters;
});
add_filter('wp_privacy_personal_data_erasers', static function (array $erasers): array {
    $erasers['apaudit'] = ['eraser_friendly_name' => __('apAudit activity history', 'apaudit'), 'callback' => 'apaudit_erase_personal_data'];
    return $erasers;
});

function apaudit_export_personal_data(string $email, int $page = 1): array|WP_Error {
    global $wpdb;
    $user = get_user_by('email', $email);
    if (!$user) {
        if ($wpdb->last_error !== '') return new WP_Error('apaudit_user_lookup_failed', __('apAudit could not look up this account. Please retry the export.', 'apaudit'));
        return ['data' => [], 'done' => true];
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy exports must read current records from the custom event table.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, event_type, object_type, object_id, actor_id, actor_ip, message, created_at FROM %i WHERE actor_id = %d OR (object_type = 'user' AND object_id = %d) ORDER BY id ASC LIMIT %d OFFSET %d",
        apaudit_table(), (int) $user->ID, (int) $user->ID, 50, (max(1, $page) - 1) * 50
    ), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) return new WP_Error('apaudit_export_failed', __('apAudit could not read activity history. Please retry the export.', 'apaudit'));
    $data = [];
    foreach ($rows as $row) {
        $fields = [
            ['name' => __('Time (UTC)', 'apaudit'), 'value' => (string) $row['created_at']],
            ['name' => __('Event', 'apaudit'), 'value' => (string) $row['event_type']],
            ['name' => __('Activity', 'apaudit'), 'value' => (string) $row['message']],
        ];
        // The IP belongs to the actor, not necessarily the account being edited.
        if (((int) $row['actor_id'] === (int) $user->ID || ((string) $row['event_type'] === 'user_login' && (string) $row['object_type'] === 'user' && (int) $row['object_id'] === (int) $user->ID)) && (string) $row['actor_ip'] !== '') {
            $fields[] = ['name' => __('IP address', 'apaudit'), 'value' => (string) $row['actor_ip']];
        }
        $data[] = ['group_id' => 'apaudit', 'group_label' => __('apAudit activity history', 'apaudit'), 'item_id' => 'apaudit-' . (int) $row['id'], 'data' => $fields];
    }
    return ['data' => $data, 'done' => count($rows) < 50];
}

function apaudit_erase_personal_data(string $email, int $page = 1): array {
    global $wpdb;
    $user = get_user_by('email', $email);
    if (!$user) {
        if ($wpdb->last_error !== '') return ['items_removed' => false, 'items_retained' => true, 'messages' => [__('apAudit could not look up this account. Please retry the erasure.', 'apaudit')], 'done' => true];
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }
    $id = (int) $user->ID;
    // Always use the first page: processed rows no longer match this query.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure must select current unredacted records.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, event_type, actor_id, object_type, object_id FROM %i WHERE actor_id = %d OR (object_type = 'user' AND object_id = %d) ORDER BY id ASC LIMIT 50",
        apaudit_table(), $id, $id
    ), ARRAY_A);
    if ($wpdb->last_error !== '' || !is_array($rows)) return ['items_removed' => false, 'items_retained' => true, 'messages' => [__('apAudit could not read activity history.', 'apaudit')], 'done' => true];
    $removed = false;
    foreach ($rows as $row) {
        $changes = ['context_json' => null];
        if ((int) $row['actor_id'] === $id) {
            $changes['actor_id'] = null;
            $changes['actor_ip'] = null;
            // Activity text can include a private title or other personal information.
            $changes['message'] = 'Activity details removed for a personal data request';
        }
        if ((string) $row['object_type'] === 'user' && (int) $row['object_id'] === $id) {
            $changes['object_id'] = null;
            // Login runs before WordPress necessarily sets the current actor.
            if ((string) $row['event_type'] === 'user_login') {
                $changes['actor_ip'] = null;
                $changes['message'] = 'User signed in (personal data erased)';
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Redact the selected personal data in the custom event table.
        if ($wpdb->update(apaudit_table(), $changes, ['id' => (int) $row['id']]) === false) {
            return ['items_removed' => $removed, 'items_retained' => true, 'messages' => [__('apAudit could not erase all matching activity data.', 'apaudit')], 'done' => true];
        }
        $removed = true;
    }
    return ['items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => count($rows) < 50];
}

add_action('admin_post_apaudit_retention', static function (): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You cannot change these settings.', 'apaudit'), '', ['response' => 403]);
    check_admin_referer('apaudit_retention');
    $input = isset($_POST['retention_days']) && is_scalar($_POST['retention_days']) ? sanitize_text_field(wp_unslash((string) $_POST['retention_days'])) : '';
    if (!is_string($input) || !preg_match('/^[0-9]+$/', $input) || (int) $input < 30 || (int) $input > 3650) {
        wp_die(esc_html__('Enter a retention period between 30 and 3650 days.', 'apaudit'), '', ['response' => 400]);
    }
    update_option('apaudit_retention_days', (string) (int) $input);
    wp_safe_redirect(add_query_arg(['page' => 'apaudit', 'retention_saved' => '1'], admin_url('tools.php')));
    exit;
});

add_action('admin_menu', static function (): void { add_management_page('apAudit', 'apAudit', 'manage_options', 'apaudit', 'apaudit_render_page'); });
function apaudit_render_page(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination changes no data.
    $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
    $per_page = 250;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin history must reflect current events and completed redactions.
    $events = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', apaudit_table(), $per_page + 1, ($page - 1) * $per_page));
    $has_next = count($events) > $per_page;
    if ($has_next) array_pop($events);
    $export_url = wp_nonce_url(admin_url('admin-post.php?action=apaudit_export'), 'apaudit_export');
    $retention = max(30, min(3650, (int) get_option('apaudit_retention_days', '180')));
    echo '<div class="wrap"><h1>apAudit</h1>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="apaudit_retention">';
    wp_nonce_field('apaudit_retention');
    echo '<label for="apaudit-retention">' . esc_html__('Keep activity history for (days)', 'apaudit') . '</label> ';
    echo '<input id="apaudit-retention" type="number" name="retention_days" min="30" max="3650" value="' . esc_attr((string) $retention) . '" required> ';
    echo '<button class="button" type="submit">' . esc_html__('Save retention', 'apaudit') . '</button>';
    echo '<p>' . esc_html__('Older records are removed by scheduled cleanup. Reducing this period can remove existing history.', 'apaudit') . '</p></form>';

    echo '<p><a class="button" href="' . esc_url($export_url) . '">Export CSV</a></p><p>Accountability history for content, accounts, plugins, themes, and system changes.</p><table class="widefat striped"><thead><tr><th>Time (UTC)</th><th>Event</th><th>Actor</th><th>Activity</th><th>Object</th><th>IP</th></tr></thead><tbody>';
    foreach ($events as $event) {
        $user = $event->actor_id ? get_userdata((int) $event->actor_id) : null;
        printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s #%s</td><td>%s</td></tr>', esc_html($event->created_at), esc_html($event->event_type), esc_html($user ? $user->user_login : 'system'), esc_html($event->message), esc_html($event->object_type), esc_html((string) $event->object_id), esc_html($event->actor_ip));
    }
    echo '</tbody></table><p>';
    if ($page > 1) {
        $previous = add_query_arg(['page' => 'apaudit', 'paged' => $page - 1], admin_url('tools.php'));
        echo '<a class="button" href="' . esc_url($previous) . '">' . esc_html__('Previous', 'apaudit') . '</a> ';
    }
    if ($has_next) {
        $next = add_query_arg(['page' => 'apaudit', 'paged' => $page + 1], admin_url('tools.php'));
        echo '<a class="button" href="' . esc_url($next) . '">' . esc_html__('Next', 'apaudit') . '</a>';
    }
    echo '</p></div>';
}

/** Spreadsheet-safe export of the existing activity history. */
function apaudit_csv_cell($value): string {
    $value = (string) $value;
    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) || preg_match('/^[\t\r\n]/', $value) ? "'" . $value : $value;
}
function apaudit_export(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You cannot export this activity history.', 'apaudit'), '', ['response' => 403]);
    check_admin_referer('apaudit_export');
    global $wpdb;
    $table = apaudit_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bound this export to the current custom-table history.
    $last_id = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', $table));
    if ($wpdb->last_error !== '') wp_die(esc_html__('apAudit could not read activity history. Please retry the export.', 'apaudit'), '', ['response' => 500]);
    // Prepare the complete file before sending headers, so a later database
    // failure cannot make an incomplete history look like a successful export.
    $out = fopen('php://temp/maxmemory:2097152', 'w+');
    if ($out === false) wp_die(esc_html__('apAudit could not prepare the export. Please retry.', 'apaudit'), '', ['response' => 500]);
    try {
        if (fputcsv($out, ['id','created_at','event_type','actor_id','message','object_type','object_id','actor_ip'], ',', '"', '') === false) throw new RuntimeException('CSV write failed');
        $cursor = 0;
        while ($cursor < $last_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export current custom-table rows without retaining a stale personal-data cache.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,created_at,event_type,actor_id,message,object_type,object_id,actor_ip FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 500', $table, $cursor, $last_id), ARRAY_A);
            if ($wpdb->last_error !== '' || !is_array($rows)) throw new RuntimeException('History read failed');
            if (!$rows) break;
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                if (fputcsv($out, array_map('apaudit_csv_cell', array_values($row)), ',', '"', '') === false) throw new RuntimeException('CSV write failed');
            }
        }
        if (!rewind($out)) throw new RuntimeException('CSV rewind failed');
    } catch (Throwable $e) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the temporary CSV stream on failure.
        fclose($out);
        wp_die(esc_html__('apAudit could not prepare the activity export. Please retry.', 'apaudit'), '', ['response' => 500]);
    }
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="apaudit-events.csv"');
    fpassthru($out);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the temporary CSV response stream.
    fclose($out);
    exit;
}
add_action('admin_post_apaudit_export', 'apaudit_export');
