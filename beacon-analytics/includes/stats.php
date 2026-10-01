<?php
declare(strict_types=1);

/**
 * One source of truth for dashboard numbers. Every query is prepared and
 * bounded; nothing here writes.
 */

if (!defined('ABSPATH')) {
    exit;
}

function beacon_range_to_days(string $range): int
{
    // Any "<days>d" between 1 and 365 works ('14d', '91d', ...). The
    // dashboard uses 1d/7d/30d/90d; emailed reports use the longer spans.
    if (preg_match('/^(\d{1,3})d$/', $range, $m)) {
        return max(1, min(365, (int) $m[1]));
    }
    return 7;
}

/**
 * Totals, daily series, top pages, referrers, devices, browsers, and custom
 * events for one date range.
 */
function beacon_get_summary(string $range, int $limit = 10): array
{
    global $wpdb;

    // Table depth. The dashboard shows the top 10; exports and emailed
    // reports pass a higher limit so quieter pages (like /?sl=... redirect
    // landings) are included instead of falling below the cut.
    $lim  = (int) max(1, min(500, $limit));
    $lim2 = (int) max(8, min(500, $limit));   // browsers/oses (dashboard: 8)
    $lim3 = (int) max(15, min(500, $limit));  // exit urls (dashboard: 15)

    $table = beacon_table();
    $days  = beacon_range_to_days($range);
    $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);

    // Rows are stored in UTC; group days by the site's timezone so the chart
    // matches the site's clock, not the server's.
    $offset = (int) wp_timezone()->getOffset(new DateTimeImmutable('now'));

    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS pageviews,
                COUNT(DISTINCT visitor_day_hash) AS visitors,
                COUNT(DISTINCT session_id) AS sessions
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s",
        $since
    ), ARRAY_A) ?: ['pageviews' => 0, 'visitors' => 0, 'sessions' => 0];

    $series = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
         GROUP BY day ORDER BY day",
        $offset,
        $since
    ), ARRAY_A);

    // Fill in zero-view days so the chart timeline has no silent gaps. The
    // window runs from the local date of the range start through today.
    $by_day = array_column($series, 'views', 'day');
    $series = [];
    $day    = (new DateTimeImmutable('@' . (time() - $days * DAY_IN_SECONDS)))
        ->setTimezone(wp_timezone());
    $today  = wp_date('Y-m-d');
    while (($d = $day->format('Y-m-d')) <= $today) {
        $series[] = ['day' => $d, 'views' => (int) ($by_day[$d] ?? 0)];
        $day = $day->modify('+1 day');
    }

    $top_pages = $wpdb->get_results($wpdb->prepare(
        "SELECT path, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
         GROUP BY path ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    $referrers = $wpdb->get_results($wpdb->prepare(
        "SELECT COALESCE(referrer_host, '(direct)') AS host, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
         GROUP BY host ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    $devices = $wpdb->get_results($wpdb->prepare(
        "SELECT device_type, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
         GROUP BY device_type ORDER BY views DESC",
        $since
    ), ARRAY_A);

    // Browser/OS shown with MAJOR version when the browser reported one
    // ("Chrome 143", "iOS 18") — majors are shared by millions, so this
    // stays fingerprint-safe.
    $browsers = $wpdb->get_results($wpdb->prepare(
        "SELECT CONCAT(browser, CASE WHEN browser_ver IS NOT NULL
                       THEN CONCAT(' ', browser_ver) ELSE '' END) AS browser,
                COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
         GROUP BY browser, browser_ver ORDER BY views DESC LIMIT {$lim2}",
        $since
    ), ARRAY_A);

    $oses = $wpdb->get_results($wpdb->prepare(
        "SELECT CONCAT(os, CASE WHEN os_ver IS NOT NULL
                       THEN CONCAT(' ', os_ver) ELSE '' END) AS os,
                COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s AND os IS NOT NULL
         GROUP BY os, os_ver ORDER BY views DESC LIMIT {$lim2}",
        $since
    ), ARRAY_A);

    // Screen sizes, bucketed at the browser (exact pixels are never sent).
    $screens = $wpdb->get_results($wpdb->prepare(
        "SELECT screen_bucket, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s AND screen_bucket IS NOT NULL
         GROUP BY screen_bucket ORDER BY views DESC",
        $since
    ), ARRAY_A);
    $bucket_labels = [
        'mini'   => __('Small (under 480px)', 'beacon-analytics'),
        'phone'  => __('Phone (480–767px)', 'beacon-analytics'),
        'tablet' => __('Tablet (768–1023px)', 'beacon-analytics'),
        'laptop' => __('Laptop (1024–1439px)', 'beacon-analytics'),
        'large'  => __('Large (1440px+)', 'beacon-analytics'),
    ];
    foreach ($screens as &$s_row) {
        $s_row['screen_bucket'] = $bucket_labels[$s_row['screen_bucket']] ?? $s_row['screen_bucket'];
    }
    unset($s_row);

    // Median-ish page speed: average load time of pageviews that carried one.
    $avg_load = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ROUND(AVG(load_ms)) FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
           AND load_ms IS NOT NULL AND load_ms > 0",
        $since
    ));

    // Exit URLs: outbound clicks only, grouped by destination host with the
    // clicked link's label. Custom tag events stay in journeys, where each
    // one has its session context.
    $exit_urls = $wpdb->get_results($wpdb->prepare(
        "SELECT CONCAT(event_name,
                       CASE WHEN event_label IS NOT NULL AND event_label <> ''
                            THEN CONCAT(' — ', event_label) ELSE '' END) AS destination,
                COUNT(*) AS fires
         FROM {$table}
         WHERE event_type = 'outbound' AND event_name IS NOT NULL
           AND created_at >= %s
         GROUP BY event_name, event_label ORDER BY fires DESC LIMIT {$lim3}",
        $since
    ), ARRAY_A);

    // Entry and exit pages: the first and last pageview of each session.
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT e.path, COUNT(*) AS views
         FROM {$table} e
         JOIN (SELECT session_id, MIN(id) AS mid FROM {$table}
               WHERE event_type = 'pageview' AND session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id) s ON e.id = s.mid
         GROUP BY e.path ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    $exits = $wpdb->get_results($wpdb->prepare(
        "SELECT e.path, COUNT(*) AS views
         FROM {$table} e
         JOIN (SELECT session_id, MAX(id) AS mid FROM {$table}
               WHERE event_type = 'pageview' AND session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id) s ON e.id = s.mid
         GROUP BY e.path ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    // Locations — country + state only, by design (HIPAA Safe Harbor).
    $countries = $wpdb->get_results($wpdb->prepare(
        "SELECT country, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s AND country IS NOT NULL
         GROUP BY country ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    $regions = $wpdb->get_results($wpdb->prepare(
        "SELECT region, COUNT(*) AS views
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s
           AND country = 'US' AND region IS NOT NULL
         GROUP BY region ORDER BY views DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    // Last 30 minutes, for the "right now" card.
    $realtime = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS pageviews, COUNT(DISTINCT visitor_day_hash) AS visitors
         FROM {$table}
         WHERE event_type = 'pageview' AND created_at >= %s",
        gmdate('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS)
    ), ARRAY_A) ?: ['pageviews' => 0, 'visitors' => 0];

    return compact(
        'totals', 'series', 'top_pages', 'referrers', 'devices', 'browsers',
        'oses', 'screens', 'avg_load', 'exit_urls', 'entries', 'exits',
        'countries', 'regions', 'realtime'
    );
}

/**
 * Sessions for the Journeys screen: start, entry page, steps, length.
 * Optional entry-path prefix filter ("/news" matches /news and /news/...)
 * and pagination so every visit in the retention window is reachable.
 */
function beacon_get_sessions(string $range, string $entry = '', int $page = 1, int $per_page = 50): array
{
    global $wpdb;
    $table  = beacon_table();
    $since  = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);
    $offset = max(0, ($page - 1)) * $per_page;

    $entry_sql = '';
    $params    = [$since, $since, $since];
    if ($entry !== '') {
        $entry_sql = ' AND fe.path LIKE %s';
        $params[]  = $wpdb->esc_like($entry) . '%';
    }
    $params[] = $per_page;
    $params[] = $offset;

    // Derived-table join (not a correlated subquery) so entry paths are
    // resolved once per session and stay inside the selected range.
    return $wpdb->get_results($wpdb->prepare(
        "SELECT e.session_id,
                MIN(e.created_at) AS started,
                MAX(e.created_at) AS ended,
                SUM(e.event_type = 'pageview') AS pageviews,
                SUM(e.event_type <> 'pageview') AS events,
                fe.path AS entry_path,
                sc.code AS study_code
         FROM {$table} e
         JOIN (SELECT session_id, MIN(id) AS mid FROM {$table}
               WHERE session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id) fm ON fm.session_id = e.session_id
         JOIN {$table} fe ON fe.id = fm.mid
         LEFT JOIN (SELECT session_id, MAX(event_label) AS code FROM {$table}
               WHERE event_name = 'study_code' AND created_at >= %s
               GROUP BY session_id) sc ON sc.session_id = e.session_id
         WHERE e.session_id IS NOT NULL AND e.created_at >= %s{$entry_sql}
         GROUP BY e.session_id, fe.path, sc.code
         ORDER BY started DESC
         LIMIT %d OFFSET %d",
        ...$params
    ), ARRAY_A);
}

/**
 * How many sessions match the range + entry filter (for the pager).
 */
function beacon_count_sessions(string $range, string $entry = ''): int
{
    global $wpdb;
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);

    if ($entry === '') {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$table}
             WHERE session_id IS NOT NULL AND created_at >= %s",
            $since
        ));
    }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM (
            SELECT fm.session_id
            FROM (SELECT session_id, MIN(id) AS mid FROM {$table}
                  WHERE session_id IS NOT NULL AND created_at >= %s
                  GROUP BY session_id) fm
            JOIN {$table} fe ON fe.id = fm.mid
            WHERE fe.path LIKE %s
         ) c",
        $since,
        $wpdb->esc_like($entry) . '%'
    ));
}

/**
 * Every step of one session, in order.
 */
function beacon_get_session_steps(string $session_id): array
{
    global $wpdb;
    $table = beacon_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT event_type, event_name, event_label, path, title, created_at
         FROM {$table} WHERE session_id = %s ORDER BY id LIMIT 500",
        $session_id
    ), ARRAY_A);
}

/**
 * Parse the funnels textarea. One per line:
 *   name | /step1 > /step2 > event:event_name
 * A step starting with "event:" matches a custom event by name; anything
 * else is a path prefix. Same guardrail as everywhere: no urls, no markup.
 */
function beacon_parse_funnels(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'http') !== false || str_contains($line, '<')) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) < 2) {
            continue;
        }
        [$name, $steps_raw] = $parts;
        $steps = [];
        foreach (explode('>', $steps_raw) as $step) {
            $step = trim($step);
            if ($step === '') {
                continue;
            }
            if (str_starts_with($step, 'event:')) {
                $ev = preg_replace('/[^a-zA-Z0-9_]/', '', substr($step, 6));
                if ($ev !== '') {
                    $steps[] = ['type' => 'event', 'value' => $ev];
                }
            } else {
                $steps[] = ['type' => 'path', 'value' => mb_substr($step, 0, 255)];
            }
        }
        if ($name !== '' && count($steps) >= 2) {
            $out[] = ['name' => mb_substr($name, 0, 80), 'steps' => array_slice($steps, 0, 8)];
        }
    }
    return array_slice($out, 0, 10);
}

/**
 * Evaluate funnels over the range: how many sessions reach each step, in
 * order. Runs in PHP over a bounded pull of session steps.
 */
function beacon_eval_funnels(array $funnels, string $range): array
{
    global $wpdb;
    if (!$funnels) {
        return [];
    }
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);

    // Bounded pull done session-first: take the most recent COMPLETE
    // sessions (never a session cut off mid-visit), then their steps.
    $cap  = 1500;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.session_id, e.event_type, e.event_name, e.path
         FROM {$table} e
         JOIN (SELECT session_id FROM {$table}
               WHERE session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id ORDER BY MAX(id) DESC LIMIT %d) s
           ON s.session_id = e.session_id
         WHERE e.created_at >= %s
         ORDER BY e.session_id, e.id",
        $since,
        $cap,
        $since
    ), ARRAY_A);

    $sessions = [];
    foreach ($rows as $r) {
        $sessions[$r['session_id']][] = $r;
    }
    $sampled = count($sessions) >= $cap;

    $results = [];
    foreach ($funnels as $f) {
        $counts = array_fill(0, count($f['steps']), 0);
        foreach ($sessions as $steps) {
            $i = 0;
            foreach ($steps as $s) {
                if ($i >= count($f['steps'])) {
                    break;
                }
                $want = $f['steps'][$i];
                $hit  = $want['type'] === 'event'
                    ? ($s['event_type'] !== 'pageview' && $s['event_name'] === $want['value'])
                    : ($s['event_type'] === 'pageview' && str_starts_with((string) $s['path'], $want['value']));
                if ($hit) {
                    $counts[$i]++;
                    $i++;
                }
            }
        }
        $results[] = ['name' => $f['name'], 'steps' => $f['steps'], 'counts' => $counts, 'sampled' => $sampled];
    }
    return $results;
}

/**
 * All steps for a set of sessions in one query (for exports/reports).
 * Bounded: at most 100 sessions, 5000 steps.
 *
 * @param string[] $session_ids
 */
function beacon_get_steps_for_sessions(array $session_ids): array
{
    global $wpdb;
    $ids = array_values(array_filter(array_slice($session_ids, 0, 100),
        static fn($s) => is_string($s) && preg_match('/^[a-f0-9]{32}$/', $s)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '%s'));
    return $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, event_type, event_name, event_label, path, created_at
         FROM " . beacon_table() . "
         WHERE session_id IN ({$ph})
         ORDER BY session_id, id LIMIT 5000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ...$ids
    ), ARRAY_A);
}
