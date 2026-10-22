<?php
declare(strict_types=1);

/**
 * Exports for people who don't log into wp-admin.
 *
 *  - Analytics CSV: every dashboard table for a range, one file.
 *  - Findings CSV: the Site Scan fix queue.
 *  - PDF: the dashboard's Print report button opens the browser print
 *    dialog; "Save as PDF" there produces the manager-ready PDF. A print
 *    stylesheet in admin.css strips wp-admin chrome and buttons.
 *
 * Exports carry the same privacy posture as the dashboard: aggregates only,
 * no hashes, no session IDs.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Open the CSV stream or die trying. */
function beacon_csv_start(string $filename)
{
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel reads accents correctly.
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}

/**
 * Neutralize spreadsheet formula injection: a visitor can plant a path or
 * label starting with = + - @ via the public collector, and Excel would run
 * it as a formula when the manager opens the export. Prefix those with '.
 */
function beacon_csv_row($out, array $row): void
{
    $safe = [];
    foreach (array_values($row) as $v) {
        $v = (string) $v;
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            $v = "'" . $v;
        }
        $safe[] = $v;
    }
    fputcsv($out, $safe, ',', '"', ''); // explicit args: PHP 8.4 deprecation + no \ escapes
}

/**
 * The ranges any export accepts (dashboard chips + all time).
 */
function beacon_export_range(): string
{
    $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7d'; // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked by caller
    return in_array($range, ['1d', '7d', '30d', '90d', 'all'], true) ? $range : '7d';
}

/**
 * Dashboard exports use the SAME sections as the emailed reports, so the
 * CSV, the Excel file, and the email attachment always match. Site scan is
 * left out on purpose (it has its own export on the Site Scan screen).
 */
function beacon_export_sections(string $range): array
{
    require_once BEACON_DIR . 'includes/reports.php';
    return beacon_report_sections($range, ['analytics', 'funnels', 'journeys']);
}

add_action('admin_post_beacon_export_csv', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_export_csv');
    $range    = beacon_export_range();
    $sections = beacon_export_sections($range);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="beacon-analytics-' . $range . '-' . wp_date('Y-m-d') . '.csv"');
    beacon_report_write_csv($sections, 'php://output'); // BOM + formula guard inside
    exit;
});

// Excel: one tab per table, like the emailed reports. A CSV file cannot
// hold tabs (the format is a single sheet), which is why this exists.
add_action('admin_post_beacon_export_xlsx', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_export_xlsx');
    $range    = beacon_export_range();
    $sections = beacon_export_sections($range);

    $tmp = trailingslashit(get_temp_dir()) . 'beacon-export-' . wp_generate_password(10, false) . '.xlsx';
    if (!beacon_report_write_xlsx($sections, $tmp)) {
        @unlink($tmp);
        wp_die(esc_html__('This server cannot build Excel files (the PHP zip extension is missing). Use Export CSV instead.', 'beacon-analytics'));
    }
    nocache_headers();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="beacon-analytics-' . $range . '-' . wp_date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . (string) filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
});

add_action('admin_post_beacon_export_findings', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_export_findings');

    global $wpdb;
    $t    = beacon_findings_table();
    $rows = $wpdb->get_results(
        "SELECT status, severity, category, check_id, path, message, selector, created_at
         FROM {$t} ORDER BY FIELD(status,'open','ignored','fixed'),
         FIELD(severity,'critical','serious','moderate','minor'), id DESC LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ARRAY_A
    );

    $out = beacon_csv_start('beacon-scan-findings-' . wp_date('Y-m-d') . '.csv');
    beacon_csv_row($out, ['Beacon Site Scan findings', home_url('/'), 'Generated', wp_date('Y-m-d H:i')]);
    beacon_csv_row($out, ['Status', 'Severity', 'Category', 'Check', 'Page', 'Issue', 'Selector', 'First seen (UTC)']);
    foreach ($rows as $r) {
        beacon_csv_row($out, $r);
    }
    fclose($out);
    exit;
});
