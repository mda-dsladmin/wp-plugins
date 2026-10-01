<?php
declare(strict_types=1);

/**
 * Retention: a daily WP-Cron job deletes events older than the configured
 * window (default 90 days). Keeping raw analytics forever is a liability, not
 * an asset — a short window is part of the privacy posture.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('beacon_daily_prune', function (): void {
    global $wpdb;

    $days = (int) beacon_settings()['retention_days'];
    $days = max(7, min(730, $days));
    $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    $table = beacon_table();
    // Delete in bounded batches so a big backlog can't lock the table.
    do {
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
            $cutoff
        ));
    } while ($deleted === 5000);
});
