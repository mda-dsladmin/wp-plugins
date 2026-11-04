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
    // 'all' = everything still stored (bounded by the retention setting):
    // days since the oldest stored event, at least 1.
    if ($range === 'all') {
        static $all = null;
        if ($all === null) {
            global $wpdb;
            $oldest = $wpdb->get_var('SELECT MIN(created_at) FROM ' . beacon_table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $all = $oldest ? (int) ceil((time() - strtotime($oldest . ' UTC')) / DAY_IN_SECONDS) + 1 : 1;
            $all = max(1, min(3650, $all));
        }
        return $all;
    }
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
/**
 * WHERE fragment + params for the Journeys filters on the entry hit (fe):
 *  - $entry: entry page starts with (path, query included)
 *  - $query: entry query contains; only visits that ARRIVED with a query
 *    string match (e.g. "sl=brst", "brst", or "?sl=" all work)
 *
 * @return array{0:string,1:array}
 */
function beacon_entry_filter_sql(string $entry, string $query, string $vh = ''): array
{
    global $wpdb;
    $sql    = '';
    $params = [];
    if ($vh !== '') {
        $sql     .= ' AND fe.visitor_day_hash = %s';
        $params[] = $vh;
    }
    if ($entry !== '') {
        $sql     .= ' AND fe.path LIKE %s';
        $params[] = $wpdb->esc_like($entry) . '%';
    }
    $query = ltrim($query, '?');
    if ($query !== '' && str_contains($query, '=')) {
        // "sl=breast" matches that exact pair, so it does not also catch
        // "sl=undiagnosed-breast" or "sl=breast-recon".
        $q        = $wpdb->esc_like($query);
        $sql     .= ' AND (fe.path LIKE %s OR fe.path LIKE %s OR fe.path LIKE %s OR fe.path LIKE %s)';
        array_push($params, '%?' . $q, '%?' . $q . '&%', '%&' . $q, '%&' . $q . '&%');
    } elseif ($query !== '') {
        $sql     .= ' AND fe.path LIKE %s';
        $params[] = '%' . $wpdb->esc_like('?') . '%' . $wpdb->esc_like($query) . '%';
    }
    return [$sql, $params];
}

function beacon_get_sessions(string $range, string $entry = '', int $page = 1, int $per_page = 50, string $query = '', string $vh = ''): array
{
    global $wpdb;
    $table  = beacon_table();
    $since  = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);
    $offset = max(0, ($page - 1)) * $per_page;

    [$entry_sql, $fparams] = beacon_entry_filter_sql($entry, $query, $vh);
    $params   = array_merge([$since, $since, $since], $fparams);
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
                fe.visitor_day_hash AS vh,
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
         GROUP BY e.session_id, fe.path, fe.visitor_day_hash, sc.code
         ORDER BY started DESC
         LIMIT %d OFFSET %d",
        ...$params
    ), ARRAY_A);
}

/**
 * How many sessions match the range + entry filter (for the pager).
 */
function beacon_count_sessions(string $range, string $entry = '', string $query = '', string $vh = ''): int
{
    global $wpdb;
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);

    if ($entry === '' && ltrim($query, '?') === '' && $vh === '') {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$table}
             WHERE session_id IS NOT NULL AND created_at >= %s",
            $since
        ));
    }
    [$filter_sql, $fparams] = beacon_entry_filter_sql($entry, $query, $vh);
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM (
            SELECT fm.session_id
            FROM (SELECT session_id, MIN(id) AS mid FROM {$table}
                  WHERE session_id IS NOT NULL AND created_at >= %s
                  GROUP BY session_id) fm
            JOIN {$table} fe ON fe.id = fm.mid
            WHERE 1=1{$filter_sql}
         ) c",
        ...array_merge([$since], $fparams)
    ));
}

/**
 * "Visitor 1, Visitor 2..." labels for the range, so same-day visits from
 * the same browser can be grouped without exposing the stored visitor code.
 *
 * Why labels instead of the code: the daily code is built from the IP and
 * browser, and HIPAA's rule for codes in de-identified data says a code must
 * not be derived from information about the person. These labels are just
 * order of first appearance in the range; they are built fresh for each
 * view or report, never stored, and mean nothing outside it. The code
 * itself resets at local midnight, so a label never spans two days.
 *
 * @return array<string,int> visitor code => label number (oldest first)
 */
function beacon_visitor_labels(string $range): array
{
    static $cache = [];
    if (isset($cache[$range])) {
        return $cache[$range];
    }
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);
    $hashes = $wpdb->get_col($wpdb->prepare(
        'SELECT visitor_day_hash FROM ' . beacon_table() . '
         WHERE visitor_day_hash IS NOT NULL AND session_id IS NOT NULL AND created_at >= %s
         GROUP BY visitor_day_hash ORDER BY MIN(id) ASC',
        $since
    ));
    $map = [];
    foreach ((array) $hashes as $i => $h) {
        $map[(string) $h] = $i + 1;
    }
    return $cache[$range] = $map;
}

/** "Visitor 12" for a session row's visitor code ('' when there is none). */
function beacon_visitor_label(string $range, ?string $vh): string
{
    if ($vh === null || $vh === '') {
        return '';
    }
    $n = beacon_visitor_labels($range)[$vh] ?? 0;
    return $n > 0 ? sprintf(/* translators: %d: number */ __('Visitor %d', 'beacon-analytics'), $n) : '';
}

/**
 * Split a stored path into [page, query]: "/?sl=brst" -> ["/", "?sl=brst"].
 */
function beacon_split_path(string $path): array
{
    $i = strpos($path, '?');
    return $i === false ? [$path, ''] : [substr($path, 0, $i), substr($path, $i)];
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
function beacon_get_steps_for_sessions(array $session_ids, int $max_sessions = 100, int $max_steps = 5000): array
{
    global $wpdb;
    $ids = array_values(array_filter(array_slice($session_ids, 0, max(1, min(5000, $max_sessions))),
        static fn($s) => is_string($s) && preg_match('/^[a-f0-9]{32}$/', $s)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '%s'));
    return $wpdb->get_results($wpdb->prepare(
        "SELECT session_id, event_type, event_name, event_label, path, created_at
         FROM " . beacon_table() . "
         WHERE session_id IN ({$ph})
         ORDER BY session_id, id LIMIT " . (int) max(1, min(100000, $max_steps)), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ...$ids
    ), ARRAY_A);
}

/**
 * Pageviews per day, split by the visit's ENTRY link — for entry links that
 * carry an allow-listed query key (like /?sl=gyn). Each pageview counts
 * toward the link its visit started on. "Other" = every remaining pageview
 * (visits that entered elsewhere, plus Do-Not-Track hits with no visit).
 * Returns [] when no entry link carries a query string.
 *
 * @return array{headers: string[], rows: array}
 */
function beacon_get_entry_link_series(string $range, int $max_cols = 25): array
{
    global $wpdb;
    $table  = beacon_table();
    $days   = beacon_range_to_days($range);
    $since  = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    $offset = (int) wp_timezone()->getOffset(new DateTimeImmutable('now'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(DATE_ADD(e.created_at, INTERVAL %d SECOND)) AS day, fe.path AS entry, COUNT(*) AS views
         FROM {$table} e
         JOIN (SELECT session_id, MIN(id) AS mid FROM {$table}
               WHERE event_type = 'pageview' AND session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id) fm ON fm.session_id = e.session_id
         JOIN {$table} fe ON fe.id = fm.mid
         WHERE e.event_type = 'pageview' AND e.created_at >= %s AND fe.path LIKE %s
         GROUP BY day, fe.path",
        $offset, $since, $since, '%' . $wpdb->esc_like('?') . '%'
    ), ARRAY_A);
    if (!$rows) {
        return [];
    }

    // Columns: busiest entry links first.
    $tot = [];
    foreach ($rows as $r) {
        $tot[$r['entry']] = ($tot[$r['entry']] ?? 0) + (int) $r['views'];
    }
    arsort($tot);
    $cols = array_slice(array_keys($tot), 0, $max_cols);

    $grid = [];
    foreach ($rows as $r) {
        if (in_array($r['entry'], $cols, true)) {
            $grid[$r['day']][$r['entry']] = (int) $r['views'];
        }
    }

    $headers = [__('Day', 'beacon-analytics'), __('Total views', 'beacon-analytics')];
    foreach ($cols as $c) {
        $q = (string) parse_url($c, PHP_URL_QUERY);           // "sl=gyn"
        $headers[] = $q !== '' ? $q : $c;
    }
    $headers[] = __('Other', 'beacon-analytics');

    $out = [];
    foreach (beacon_get_summary($range, 1)['series'] as $d) {   // zero-filled days
        $row = [$d['day'], (int) $d['views']];
        $sum = 0;
        foreach ($cols as $c) {
            $v = $grid[$d['day']][$c] ?? 0;
            $row[] = $v;
            $sum += $v;
        }
        $row[] = max(0, (int) $d['views'] - $sum);
        $out[] = $row;
    }
    return ['headers' => $headers, 'rows' => $out];
}

/**
 * Custom event totals (tag rules, iframeclick, window.beacon calls) for the
 * range: how many times each fired and in how many visits. Outbound clicks
 * (Exit URLs), survey answers, and study codes have their own tables.
 *
 * @return array{totals: array, details: array}
 */
function beacon_get_event_totals(string $range, int $limit = 200): array
{
    global $wpdb;
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);
    $lim   = (int) max(1, min(500, $limit));

    $totals = $wpdb->get_results($wpdb->prepare(
        "SELECT event_name, COUNT(*) AS fires, COUNT(DISTINCT session_id) AS visits
         FROM {$table}
         WHERE event_type = 'event' AND created_at >= %s
           AND event_name NOT IN ('survey_response', 'study_code')
         GROUP BY event_name ORDER BY fires DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    $details = $wpdb->get_results($wpdb->prepare(
        "SELECT event_name, COALESCE(event_label, '') AS label, COUNT(*) AS fires,
                COUNT(DISTINCT session_id) AS visits
         FROM {$table}
         WHERE event_type = 'event' AND created_at >= %s
           AND event_name NOT IN ('survey_response', 'study_code')
         GROUP BY event_name, label ORDER BY fires DESC LIMIT {$lim}",
        $since
    ), ARRAY_A);

    return ['totals' => $totals, 'details' => $details];
}

/**
 * Survey completion per page. For every page where answers were recorded:
 *  - questions = distinct questions anyone answered on that page in the
 *    range (the text before " → " in each survey_response label)
 *  - visits to the page, and visits that answered at least one, at least
 *    half, and all of those questions
 * Changing an answer does not count twice: a visit's progress is the number
 * of DISTINCT questions it answered on that page. A question nobody
 * answered in the range is not counted, so a page's question total can be
 * lower than its real item count early on.
 */
function beacon_get_survey_completion(string $range): array
{
    global $wpdb;
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT path, session_id, event_label
         FROM {$table}
         WHERE event_name = 'survey_response' AND event_label IS NOT NULL
           AND session_id IS NOT NULL AND created_at >= %s
         ORDER BY id DESC LIMIT 50000",
        $since
    ), ARRAY_A);
    if (!$rows) {
        return [];
    }

    $questions = [];  // path => [question => true]
    $answered  = [];  // path => [session => [question => true]]
    foreach ($rows as $r) {
        $parts = explode(' → ', (string) $r['event_label'], 2);
        $q     = trim($parts[0]);
        if ($q === '') {
            continue;
        }
        $questions[$r['path']][$q] = true;
        $answered[$r['path']][$r['session_id']][$q] = true;
    }

    $out = [];
    foreach ($questions as $path => $qs) {
        $n    = count($qs);
        $half = (int) ceil($n / 2);
        $one = $halfplus = $all = 0;
        foreach ($answered[$path] as $sess_qs) {
            $c = count($sess_qs);
            $one++;
            if ($c >= $half) {
                $halfplus++;
            }
            if ($c >= $n) {
                $all++;
            }
        }
        $visits = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$table}
             WHERE event_type = 'pageview' AND path = %s AND session_id IS NOT NULL AND created_at >= %s",
            $path,
            $since
        ));
        $visits = max($visits, $one); // the page view can fall just before the range start
        $out[] = [
            'path'      => (string) $path,
            'questions' => $n,
            'visits'    => $visits,
            'one'       => $one,
            'half'      => $halfplus,
            'all'       => $all,
            'rate'      => $visits > 0 ? round(100 * $all / $visits, 1) : 0.0,
        ];
    }
    usort($out, static fn($a, $b) => $b['visits'] <=> $a['visits']);
    return $out;
}

/** Label for visits that did not arrive on a service line link. */
function beacon_organic_label(): string
{
    return __('organic', 'beacon-analytics');
}

/**
 * A visit's service line, from its entry page: "/?sl=brst" -> "brst".
 * A single key=value query shows just the value; anything else shows the
 * whole query; no query -> "organic".
 */
function beacon_service_line_label(string $entry): string
{
    $q = (string) parse_url($entry, PHP_URL_QUERY);
    if ($q === '') {
        return beacon_organic_label();
    }
    parse_str($q, $parts);
    if (count($parts) === 1) {
        $v = (string) reset($parts);
        return $v !== '' ? $v : $q;
    }
    return $q;
}

/**
 * Service-line breakdowns. A "service line" is the entry link a visit
 * arrived on (an entry page with an allow-listed query, like /?sl=brst).
 * Visits with no entry query are grouped as "organic" for
 * comparison. Everything is computed from one bounded pull of the range's
 * events, so all four tables agree with each other.
 *
 * Group names: a single key=value query shows just the value ("brst");
 * anything else shows the whole query.
 *
 * @return array{groups:string[], summary:array, pages:array, time:array,
 *               clicks:array, answers:array, sampled:bool}
 */
function beacon_get_service_line_data(string $range, int $max_groups = 20, int $max_rows = 200): array
{
    global $wpdb;
    $table = beacon_table();
    $since = gmdate('Y-m-d H:i:s', time() - beacon_range_to_days($range) * DAY_IN_SECONDS);
    $cap   = 100000;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.session_id, e.event_type, e.event_name, e.event_label, e.path,
                e.created_at, fe.path AS entry, fe.device_type AS device
         FROM {$table} e
         JOIN (SELECT session_id, MIN(id) AS mid FROM {$table}
               WHERE session_id IS NOT NULL AND created_at >= %s
               GROUP BY session_id) fm ON fm.session_id = e.session_id
         JOIN {$table} fe ON fe.id = fm.mid
         WHERE e.created_at >= %s
         ORDER BY e.session_id, e.id
         LIMIT %d",
        $since,
        $since,
        $cap
    ), ARRAY_A);

    $none      = beacon_organic_label();
    $label_for = 'beacon_service_line_label';
    // "Clicks" means clicks: scroll-depth and time-on-page milestones (tags
    // with the scroll or timer trigger, like scroll_50 or engaged_30s) are
    // left out here. They still count in Event totals and in journeys.
    $not_clicks = [];
    if (function_exists('beacon_parse_tags')) {
        foreach (beacon_parse_tags((string) beacon_settings()['tags']) as $rule) {
            if (in_array($rule['trigger'], ['scroll', 'timer'], true)) {
                $not_clicks[$rule['event']] = true;
            }
        }
    }
    $queries   = []; // group => the entry query it came from (for Journeys links)

    // Walk visits in order.
    $visits = []; // sid => [group, device, start, end, pages, answered, pageset, clickset, answerset, times]
    $last_pv = []; // sid => [path, ts]
    foreach ($rows as $r) {
        $sid = (string) $r['session_id'];
        $ts  = strtotime((string) $r['created_at'] . ' UTC');
        if (!isset($visits[$sid])) {
            $grp = $label_for((string) $r['entry']);
            if ($grp !== $none && !isset($queries[$grp])) {
                $queries[$grp] = (string) parse_url((string) $r['entry'], PHP_URL_QUERY);
            }
            $visits[$sid] = [
                'group' => $grp, 'device' => (string) $r['device'],
                'start' => $ts, 'end' => $ts, 'pages' => 0, 'answered' => false,
                'pageset' => [], 'clickset' => [], 'answerset' => [], 'times' => [],
            ];
        }
        $v = &$visits[$sid];
        $v['end'] = max($v['end'], $ts);
        if ($r['event_type'] === 'pageview') {
            $v['pages']++;
            $v['pageset'][(string) $r['path']] = true;
            if (isset($last_pv[$sid])) {
                [$p, $t0] = $last_pv[$sid];
                $v['times'][$p][] = max(0, $ts - $t0);
            }
            $last_pv[$sid] = [(string) $r['path'], $ts];
        } elseif ($r['event_name'] === 'survey_response') {
            $v['answered'] = true;
            if ((string) $r['event_label'] !== '') {
                $v['answerset'][(string) $r['event_label']] = true;
            }
        } elseif ($r['event_name'] !== 'study_code' && !isset($not_clicks[(string) $r['event_name']])) {
            $key = (string) $r['event_name'];
            if ((string) $r['event_label'] !== '') {
                $key .= ' — ' . $r['event_label'];
            }
            $v['clickset'][$key] = true;
        }
        unset($v);
    }

    // Groups: busiest first, named groups capped, overflow merged.
    $size = [];
    foreach ($visits as $v) {
        $size[$v['group']] = ($size[$v['group']] ?? 0) + 1;
    }
    $named = array_filter($size, static fn($g) => $g !== $none, ARRAY_FILTER_USE_KEY);
    arsort($named);
    $keep   = array_slice(array_keys($named), 0, $max_groups);
    $other  = __('(other links)', 'beacon-analytics');
    $groups = $keep;
    if (count($named) > $max_groups) {
        $groups[] = $other;
    }
    if (isset($size[$none])) {
        $groups[] = $none;
    }
    foreach ($visits as &$v) {
        if ($v['group'] !== $none && !in_array($v['group'], $keep, true)) {
            $v['group'] = $other;
        }
    }
    unset($v);

    // Summary.
    $acc = [];
    foreach (array_merge(['__all'], $groups) as $g) {
        $acc[$g] = ['visits' => 0, 'len' => 0, 'pages' => 0, 'mobile' => 0, 'answered' => 0];
    }
    $pages = $time = $clicks = $answers = [];
    foreach ($visits as $v) {
        foreach (['__all', $v['group']] as $g) {
            $acc[$g]['visits']++;
            $acc[$g]['len'] += $v['end'] - $v['start'];
            $acc[$g]['pages'] += $v['pages'];
            $acc[$g]['mobile'] += $v['device'] === 'mobile' ? 1 : 0;
            $acc[$g]['answered'] += $v['answered'] ? 1 : 0;
            foreach ($v['pageset'] as $p => $_) {
                $pages[$p][$g] = ($pages[$p][$g] ?? 0) + 1;
            }
            foreach ($v['times'] as $p => $list) {
                foreach ($list as $sec) {
                    $time[$p][$g][] = $sec;
                }
            }
            foreach ($v['clickset'] as $c => $_) {
                $clicks[$c][$g] = ($clicks[$c][$g] ?? 0) + 1;
            }
            foreach ($v['answerset'] as $a => $_) {
                $answers[$a][$g] = ($answers[$a][$g] ?? 0) + 1;
            }
        }
    }

    $summary = [];
    foreach (array_merge(['__all'], $groups) as $g) {
        $a = $acc[$g];
        $n = max(1, $a['visits']);
        $summary[] = [
            'group'    => $g === '__all' ? __('All visits', 'beacon-analytics') : $g,
            'visits'   => $a['visits'],
            'avg_len'  => (int) round($a['len'] / $n),
            'avg_pages'=> round($a['pages'] / $n, 1),
            'mobile'   => round(100 * $a['mobile'] / $n, 1),
            'answered' => round(100 * $a['answered'] / $n, 1),
        ];
    }

    $pivot = static function (array $m) use ($groups, $max_rows): array {
        uasort($m, static fn($x, $y) => ($y['__all'] ?? 0) <=> ($x['__all'] ?? 0));
        $out = [];
        foreach (array_slice($m, 0, $max_rows, true) as $label => $cells) {
            $row = ['label' => (string) $label, 'all' => (int) ($cells['__all'] ?? 0), 'by' => []];
            foreach ($groups as $g) {
                $row['by'][$g] = (int) ($cells[$g] ?? 0);
            }
            $out[] = $row;
        }
        return $out;
    };
    $avg = [];
    foreach ($time as $p => $by) {
        foreach ($by as $g => $list) {
            $avg[$p][$g] = (int) round(array_sum($list) / max(1, count($list)));
        }
    }

    return [
        'groups'  => $groups,
        'summary' => $summary,
        'pages'   => $pivot($pages),
        'time'    => $avg,           // [path][group|'__all'] => avg seconds
        'clicks'  => $pivot($clicks),
        'answers' => $pivot($answers),
        'queries' => $queries,
        'sampled' => count($rows) >= $cap,
    ];
}
