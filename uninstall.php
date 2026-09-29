<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * By default the analytics data is LEFT IN PLACE, because deleting a plugin
 * by accident should not destroy months of data. The table and options are
 * removed only if "Delete all analytics data when the plugin is uninstalled"
 * was checked in Beacon Settings.
 *
 * Either way:
 *  - every scheduled task is removed;
 *  - the hash salts are deleted, so kept hashes can never be linked or checked
 *    against an address again;
 *  - the rate-limit table (short-lived counters) is dropped;
 *  - kept events are trimmed to the retention window one last time. Nothing
 *    prunes them after that, until Beacon is installed again.
 *
 * On multisite this runs for every site in the network.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up the current site.
 */
function beacon_uninstall_site()
{
    global $wpdb;
    $beacon_opt = get_option('beacon_analytics_settings', []);

    wp_clear_scheduled_hook('beacon_daily_prune');
    wp_clear_scheduled_hook('beacon_weekly_scan');
    wp_clear_scheduled_hook('beacon_email_tick');
    wp_unschedule_hook('beacon_scan_tick');

    delete_option('beacon_analytics_salt');     // legacy permanent salt
    delete_option('beacon_analytics_day_salt'); // today's salt
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}beacon_rate"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

    if (is_array($beacon_opt) && empty($beacon_opt['delete_on_uninstall'])) {
        $beacon_days = isset($beacon_opt['retention_days']) ? max(1, (int) $beacon_opt['retention_days']) : 90;
        $beacon_table = $wpdb->prefix . 'beacon_events';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $beacon_table)) === $beacon_table) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$beacon_table} WHERE created_at < %s", gmdate('Y-m-d H:i:s', time() - $beacon_days * DAY_IN_SECONDS))); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    if (is_array($beacon_opt) && !empty($beacon_opt['delete_on_uninstall'])) {
        foreach (['beacon_events', 'beacon_scan_runs', 'beacon_scan_pages', 'beacon_scan_queue', 'beacon_findings'] as $beacon_t) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$beacon_t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        }
        delete_option('beacon_analytics_settings');
        delete_option('beacon_db_version');
        delete_option('beacon_report_last_sent');

        // Stray per-run link caches from any scan that was mid-flight.
        $beacon_like = $wpdb->esc_like('beacon_scan_links_') . '%';
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $beacon_like)) as $beacon_o) {
            delete_option((string) $beacon_o);
        }

        // The downloaded location database (~130 MB) in uploads/beacon-geo/.
        $beacon_up  = wp_get_upload_dir();
        $beacon_dir = trailingslashit($beacon_up['basedir']) . 'beacon-geo';
        if (is_dir($beacon_dir)) {
            foreach ((array) glob($beacon_dir . '/*') as $beacon_f) {
                if (is_file($beacon_f)) {
                    @unlink($beacon_f);
                }
            }
            @rmdir($beacon_dir);
        }
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $beacon_site_id) {
        switch_to_blog((int) $beacon_site_id);
        beacon_uninstall_site();
        restore_current_blog();
    }
} else {
    beacon_uninstall_site();
}
