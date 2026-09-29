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
function beacon_csv_row($out, array $row)
{
    $safe = [];
    foreach (array_values($row) as $v) {
        $v = (string) $v;
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            $v = "'" . $v;
        }
        $safe[] = $v;
    }
    beacon_fputcsv($out, $safe); // RFC 4180 quoting, no backslash escapes, same on PHP 7 and 8
}

/** One titled section of rows into the stream. */
function beacon_csv_section($out, string $title, array $header, array $rows)
{
    beacon_csv_row($out, [$title]);
    beacon_csv_row($out, $header);
    foreach ($rows as $r) {
        beacon_csv_row($out, $r);
    }
    beacon_csv_row($out, []); // blank spacer line
}

add_action('admin_post_beacon_export_csv', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_export_csv');

    $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7d';
    if (!in_array($range, ['1d', '7d', '30d', '90d'], true)) {
        $range = '7d';
    }
    $d = beacon_get_summary($range);

    $out = beacon_csv_start('beacon-analytics-' . $range . '-' . wp_date('Y-m-d') . '.csv');

    beacon_csv_row($out, ['Beacon Analytics export']);
    beacon_csv_row($out, ['Site', home_url('/')]);
    beacon_csv_row($out, ['Range', $range, 'Generated', wp_date('Y-m-d H:i')]);
    beacon_csv_row($out, []);

    beacon_csv_section($out, 'Totals', ['Pageviews', 'Unique visitors', 'Sessions'], [[
        (int) $d['totals']['pageviews'],
        (int) $d['totals']['visitors'],
        (int) $d['totals']['sessions'],
    ]]);
    beacon_csv_section($out, 'Pageviews by day', ['Day', 'Views'], $d['series']);
    beacon_csv_section($out, 'Top pages', ['Path', 'Views'], $d['top_pages']);
    beacon_csv_section($out, 'Entry pages', ['Path', 'Sessions'], $d['entries']);
    beacon_csv_section($out, 'Exit pages', ['Path', 'Sessions'], $d['exits']);
    beacon_csv_section($out, 'Referrers', ['Source', 'Views'], $d['referrers']);
    beacon_csv_section($out, 'Devices', ['Device', 'Views'], $d['devices']);
    beacon_csv_section($out, 'Browsers', ['Browser', 'Views'], $d['browsers']);
    beacon_csv_section($out, 'Operating systems', ['OS', 'Views'], $d['oses']);
    beacon_csv_section($out, 'Screen sizes', ['Size', 'Views'], $d['screens']);
    if ($d['avg_load'] > 0) {
        beacon_csv_section($out, 'Performance', ['Average page load (ms)'], [[(int) $d['avg_load']]]);
    }
    beacon_csv_section($out, 'Exit URLs (outbound clicks)', ['Destination', 'Clicks'], $d['exit_urls']);
    if ($d['countries']) {
        beacon_csv_section($out, 'Countries', ['Country', 'Views'], $d['countries']);
        beacon_csv_section($out, 'US states', ['State', 'Views'], $d['regions']);
    }

    // Survey responses (only exists if survey capture was enabled).
    global $wpdb;
    $t = beacon_table();
    $survey = $wpdb->get_results($wpdb->prepare(
        "SELECT event_label, COUNT(*) AS responses
         FROM {$t}
         WHERE event_name = 'survey_response' AND event_label IS NOT NULL AND created_at >= %s
         GROUP BY event_label ORDER BY event_label", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS)
    ), ARRAY_A);
    if ($survey) {
        beacon_csv_section($out, 'Survey responses (Question -> Answer)', ['Question -> Answer', 'Responses'], $survey);
    }

    // Funnel results for the range.
    $funnels = beacon_eval_funnels(beacon_parse_funnels((string) beacon_settings()['funnels']), $range);
    foreach ($funnels as $f) {
        $rows = [];
        foreach ($f['steps'] as $i => $step) {
            $rows[] = [
                ($step['type'] === 'event' ? 'event: ' : '') . $step['value'],
                (int) $f['counts'][$i],
            ];
        }
        beacon_csv_section($out, 'Funnel: ' . $f['name'], ['Step', 'Sessions reaching it'], $rows);
    }

    fclose($out);
    exit;
});

add_action('admin_post_beacon_export_findings', function () {
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
