<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * By default the analytics data is LEFT IN PLACE, because deleting a plugin
 * by accident should not destroy months of data. The table and options are
 * removed only if "Delete all analytics data when the plugin is uninstalled"
 * was checked in Beacon Settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$beacon_opt = get_option('beacon_analytics_settings', []);

wp_clear_scheduled_hook('beacon_daily_prune');
wp_clear_scheduled_hook('beacon_weekly_scan');
wp_unschedule_hook('beacon_scan_tick');

if (is_array($beacon_opt) && !empty($beacon_opt['delete_on_uninstall'])) {
    global $wpdb;
    foreach (['beacon_events', 'beacon_scan_runs', 'beacon_scan_pages', 'beacon_scan_queue', 'beacon_findings'] as $beacon_t) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$beacon_t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    }
    delete_option('beacon_analytics_settings');
    delete_option('beacon_analytics_salt');
    delete_option('beacon_db_version');

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
