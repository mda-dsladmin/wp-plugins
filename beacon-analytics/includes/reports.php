<?php
declare(strict_types=1);

/**
 * Emailed reports.
 *
 * On the schedule chosen in settings (weekly up to yearly, several at once),
 * Beacon emails a report to the site admin — or to the addresses entered in
 * settings — with the full data attached as a spreadsheet or PDF. The email
 * body carries a short summary; the attachment carries every table.
 *
 * Everything is generated on this server with no outside service and no
 * libraries: the .xlsx and .pdf writers below build the files byte by byte.
 * The report holds the same de-identified aggregates as the dashboard —
 * no visitor hashes, no session IDs, no IPs — so the email adds no PHI
 * exposure beyond what the dashboard already shows. Email is still plain
 * transport: keep recipients to work addresses.
 *
 * Timing model: a 'beacon_email_tick' cron fires twice a day. For each
 * interval the admin selected (1w, 2w, 3w, 1m, 3m, 6m, 1y) we remember when
 * that interval's report last went out; once a full period has passed, the
 * next one is sent covering exactly that period. The first report arrives
 * one full period after the interval is switched on, so it always covers a
 * complete window.
 */

if (!defined('ABSPATH')) {
    exit;
}

const BEACON_REPORT_LAST = 'beacon_report_last_sent';

/** The selectable schedules: key => [days, label]. */
function beacon_report_intervals(): array
{
    return [
        '1w' => [7,   __('Every week', 'beacon-analytics')],
        '2w' => [14,  __('Every 2 weeks', 'beacon-analytics')],
        '3w' => [21,  __('Every 3 weeks', 'beacon-analytics')],
        '1m' => [30,  __('Every month', 'beacon-analytics')],
        '3m' => [91,  __('Every 3 months', 'beacon-analytics')],
        '6m' => [182, __('Every 6 months', 'beacon-analytics')],
        '1y' => [365, __('Every year', 'beacon-analytics')],
    ];
}

/**
 * Who gets the report. The addresses from settings, or the site admin email
 * when the field is blank. Every address is validated again here — settings
 * sanitizing already did, but this list goes straight into wp_mail().
 */
function beacon_report_recipients(): array
{
    $raw = (string) beacon_settings()['report_emails'];
    $out = [];
    foreach (explode(',', $raw) as $e) {
        $e = trim($e);
        if ($e !== '' && is_email($e)) {
            $out[] = $e;
        }
    }
    $out = array_values(array_unique($out));
    if (!$out) {
        $admin = (string) get_option('admin_email');
        if (is_email($admin)) {
            $out[] = $admin;
        }
    }
    return array_slice($out, 0, 10);
}

/**
 * Assemble every table for the report as plain arrays, one structure the
 * CSV, XLSX, and PDF writers all consume:
 *   [ ['title' => ..., 'headers' => [...], 'rows' => [[...], ...]], ... ]
 */
function beacon_report_sections(string $range, array $content, bool $deep = false): array
{
    global $wpdb;
    // stats.php normally loads only in wp-admin; cron runs outside it.
    require_once BEACON_DIR . 'includes/stats.php';

    $sections = [];
    $days     = beacon_range_to_days($range);

    $sections[] = [
        'key' => 'about', 'title' => __('About this report', 'beacon-analytics'),
        'headers' => [],
        'rows'    => [
            [__('Site', 'beacon-analytics'), home_url('/')],
            [__('Period', 'beacon-analytics'), $range === 'all'
                ? __('All time (everything still stored)', 'beacon-analytics')
                : sprintf(/* translators: %d: days */ __('Last %d days', 'beacon-analytics'), $days)],
            [__('Generated', 'beacon-analytics'), wp_date('Y-m-d H:i')],
        ],
    ];

    if (in_array('analytics', $content, true)) {
        $d = beacon_get_summary($range, 200); // deep tables: nothing falls below a top-10 cut
        $sections[] = ['key' => 'totals', 'title' => __('Totals', 'beacon-analytics'),
            'headers' => [__('Pageviews', 'beacon-analytics'), __('Unique visitors', 'beacon-analytics'), __('Sessions', 'beacon-analytics')],
            'rows' => [[(int) $d['totals']['pageviews'], (int) $d['totals']['visitors'], (int) $d['totals']['sessions']]]];
        $sections[] = ['key' => 'series', 'title' => __('Pageviews by day', 'beacon-analytics'),
            'headers' => [__('Day', 'beacon-analytics'), __('Views', 'beacon-analytics')],
            'rows' => array_map(fn($r) => [$r['day'], (int) $r['views']], $d['series'])];
        $pivot = beacon_get_entry_link_series($range);
        if ($pivot) {
            $sections[] = ['key' => 'entry_links', 'title' => __('Pageviews by entry link', 'beacon-analytics'),
                'headers' => $pivot['headers'], 'rows' => $pivot['rows']];
        }
        $simple = [
            ['top_pages', __('Top pages', 'beacon-analytics'),    __('Path', 'beacon-analytics'),    __('Views', 'beacon-analytics'),    $d['top_pages']],
            ['entries', __('Entry pages', 'beacon-analytics'),  __('Path', 'beacon-analytics'),    __('Sessions', 'beacon-analytics'), $d['entries']],
            ['exits', __('Exit pages', 'beacon-analytics'),   __('Path', 'beacon-analytics'),    __('Sessions', 'beacon-analytics'), $d['exits']],
            ['referrers', __('Referrers', 'beacon-analytics'),    __('Source', 'beacon-analytics'),  __('Views', 'beacon-analytics'),    $d['referrers']],
            ['devices', __('Devices', 'beacon-analytics'),      __('Device', 'beacon-analytics'),  __('Views', 'beacon-analytics'),    $d['devices']],
            ['browsers', __('Browsers', 'beacon-analytics'),     __('Browser', 'beacon-analytics'), __('Views', 'beacon-analytics'),    $d['browsers']],
            ['oses', __('Operating systems', 'beacon-analytics'), __('OS', 'beacon-analytics'), __('Views', 'beacon-analytics'),    $d['oses']],
            ['screens', __('Screen sizes', 'beacon-analytics'), __('Size', 'beacon-analytics'),    __('Views', 'beacon-analytics'),    $d['screens']],
            ['exit_urls', __('Exit URLs (outbound clicks)', 'beacon-analytics'), __('Destination', 'beacon-analytics'), __('Clicks', 'beacon-analytics'), $d['exit_urls']],
        ];
        foreach ($simple as [$skey, $title, $h1, $h2, $rows]) {
            $sections[] = ['key' => $skey, 'title' => $title, 'headers' => [$h1, $h2],
                'rows' => array_map('array_values', array_map(fn($r) => array_slice($r, 0, 2), $rows))];
        }
        if ($d['countries']) {
            $sections[] = ['key' => 'countries', 'title' => __('Countries', 'beacon-analytics'),
                'headers' => [__('Country', 'beacon-analytics'), __('Views', 'beacon-analytics')],
                'rows' => array_map('array_values', $d['countries'])];
            $sections[] = ['key' => 'regions', 'title' => __('US states', 'beacon-analytics'),
                'headers' => [__('State', 'beacon-analytics'), __('Views', 'beacon-analytics')],
                'rows' => array_map('array_values', $d['regions'])];
        }
        if ($d['avg_load'] > 0) {
            $sections[] = ['key' => 'performance', 'title' => __('Performance', 'beacon-analytics'),
                'headers' => [__('Average page load (ms)', 'beacon-analytics')],
                'rows' => [[(int) $d['avg_load']]]];
        }
        $t = beacon_table();
        $survey = $wpdb->get_results($wpdb->prepare(
            "SELECT event_label, COUNT(*) AS responses
             FROM {$t}
             WHERE event_name = 'survey_response' AND event_label IS NOT NULL AND created_at >= %s
             GROUP BY event_label ORDER BY event_label", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        ), ARRAY_A);
        if ($survey) {
            $sections[] = ['key' => 'survey', 'title' => __('Survey responses', 'beacon-analytics'),
                'headers' => [__('Question → Answer', 'beacon-analytics'), __('Responses', 'beacon-analytics')],
                'rows' => array_map('array_values', $survey)];
        }

        // How far visitors got through each page's questions.
        $comp = beacon_get_survey_completion($range);
        if ($comp) {
            $sections[] = ['key' => 'survey_completion', 'title' => __('Survey completion', 'beacon-analytics'),
                // Short headers so the PDF columns fit: visits to the page,
                // visits that answered 1+ / half+ / all questions, % all.
                'headers' => [__('Questions', 'beacon-analytics'), __('Visits', 'beacon-analytics'),
                    __('1+', 'beacon-analytics'), __('Half+', 'beacon-analytics'),
                    __('All', 'beacon-analytics'), __('Done %', 'beacon-analytics'),
                    __('Page', 'beacon-analytics')],
                'rows' => array_map(static fn($c) => [$c['questions'], $c['visits'], $c['one'], $c['half'],
                    $c['all'], $c['rate'], $c['path']], $comp)];
        }

        // Milestones and tagged clicks: how often each fired, in how many visits.
        $ev = beacon_get_event_totals($range);
        if ($ev['totals']) {
            $sections[] = ['key' => 'event_totals', 'title' => __('Event totals', 'beacon-analytics'),
                'headers' => [__('Event', 'beacon-analytics'), __('Times', 'beacon-analytics'), __('Visits', 'beacon-analytics')],
                'rows' => array_map(static fn($e) => [(string) $e['event_name'], (int) $e['fires'], (int) $e['visits']], $ev['totals'])];
            $sections[] = ['key' => 'event_details', 'title' => __('Event details', 'beacon-analytics'),
                'headers' => [__('Event', 'beacon-analytics'), __('Times', 'beacon-analytics'),
                    __('Visits', 'beacon-analytics'), __('Label', 'beacon-analytics')],
                'rows' => array_map(static fn($e) => [(string) $e['event_name'], (int) $e['fires'],
                    (int) $e['visits'], (string) $e['label']], $ev['details'])];
        }

        foreach (beacon_report_service_line_sections($range) as $sl) {
            $sections[] = $sl;
        }
    }

    if (in_array('funnels', $content, true)) {
        $funnels = beacon_eval_funnels(beacon_parse_funnels((string) beacon_settings()['funnels']), $range);
        foreach ($funnels as $f) {
            $rows = [];
            foreach ($f['steps'] as $i => $step) {
                $rows[] = [($step['type'] === 'event' ? 'event: ' : '') . $step['value'], (int) $f['counts'][$i]];
            }
            $sections[] = ['key' => 'funnels', 'title' => sprintf(/* translators: %s: funnel name */ __('Funnel: %s', 'beacon-analytics'), $f['name']),
                'headers' => [__('Step', 'beacon-analytics'), __('Sessions reaching it', 'beacon-analytics')],
                'rows' => $rows];
        }
    }

    if (in_array('scan', $content, true) && function_exists('beacon_findings_table')) {
        $ft = beacon_findings_table();
        $counts = $wpdb->get_results(
            "SELECT severity, COUNT(*) AS n FROM {$ft} WHERE status = 'open'
             GROUP BY severity ORDER BY FIELD(severity,'critical','serious','moderate','minor')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        $sections[] = ['key' => 'scan_counts', 'title' => __('Site scan: open findings by severity', 'beacon-analytics'),
            'headers' => [__('Severity', 'beacon-analytics'), __('Open findings', 'beacon-analytics')],
            'rows' => $counts ? array_map('array_values', $counts) : [[__('none', 'beacon-analytics'), 0]]];
        $rows = $wpdb->get_results(
            "SELECT severity, category, path, message FROM {$ft} WHERE status = 'open'
             ORDER BY FIELD(severity,'critical','serious','moderate','minor'), id DESC LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );
        if ($rows) {
            $sections[] = ['key' => 'scan_findings', 'title' => __('Site scan: open findings (up to 300)', 'beacon-analytics'),
                'headers' => [__('Severity', 'beacon-analytics'), __('Category', 'beacon-analytics'), __('Page', 'beacon-analytics'), __('Issue', 'beacon-analytics')],
                'rows' => array_map('array_values', $rows)];
        }
    }

    $journeys = [];
    if (in_array('journeys', $content, true)) {
        $journeys = beacon_report_journey_sections($range, $deep);
        foreach ($journeys as $js) {
            if (!empty($js['note'])) {
                $sections[0]['rows'][] = [__('Journeys tabs', 'beacon-analytics'), (string) $js['note']];
            }
            $sections[] = $js;
        }
    }

    // One Excel tab per service line (Excel only: CSV and PDF skip these).
    if ($deep && in_array('analytics', $content, true)) {
        foreach (beacon_report_service_line_sheets($range, $journeys) as $sheet) {
            $sections[] = $sheet;
        }
    }

    // Tabs switched off in Settings > Report tabs are dropped everywhere
    // (emailed reports and dashboard exports). "About" always stays. Runs
    // before AND after combining, so older saved choices still apply.
    $off  = array_filter(explode(',', (string) beacon_settings()['report_tabs_off']));
    $keep = static fn($s) => ($s['key'] ?? '') === 'about' || !in_array($s['key'] ?? '', $off, true);
    $sections = array_values(array_filter($sections, $keep));
    $sections = beacon_report_consolidate($sections);
    return array_values(array_filter($sections, $keep));
}

/**
 * Fewer, fuller tabs. Small related tables are stacked on one tab, and
 * tables that repeat another tab are dropped:
 *  - Overview = About + Totals + Performance
 *  - "Pageviews by day" is dropped when "Pageviews by entry link" exists
 *    (its Total views column is the same numbers)
 *  - Technology = Devices + Browsers + Operating systems + Screen sizes
 *  - Locations = Countries + US states
 *  - Events = Event totals + Event details
 *  - Service lines compare = the five side-by-side service line tables;
 *    site-wide "Survey responses" is dropped when it exists (its All
 *    column has the same answers, counted by visit)
 *  - Funnels = every funnel on one tab
 */
function beacon_report_consolidate(array $sections): array
{
    $has = static fn(string $k) => (bool) array_filter($sections, static fn($s) => ($s['key'] ?? '') === $k);
    if ($has('entry_links')) {
        $sections = array_values(array_filter($sections, static fn($s) => ($s['key'] ?? '') !== 'series'));
    }
    if ($has('sl_summary')) {
        $sections = array_values(array_filter($sections, static fn($s) => ($s['key'] ?? '') !== 'survey'));
    }
    $groups = [
        'about'      => [['about', 'totals', 'performance'], __('Overview', 'beacon-analytics')],
        'technology' => [['devices', 'browsers', 'oses', 'screens'], __('Technology', 'beacon-analytics')],
        'locations'  => [['countries', 'regions'], __('Locations', 'beacon-analytics')],
        'events'     => [['event_totals', 'event_details'], __('Events', 'beacon-analytics')],
        'sl_compare' => [['sl_summary', 'sl_pages', 'sl_time', 'sl_clicks', 'sl_answers'], __('Service lines compare', 'beacon-analytics')],
        'funnels'    => [['funnels'], __('Funnels', 'beacon-analytics')],
    ];
    foreach ($groups as $newkey => [$members, $title]) {
        $out = [];
        $at  = null;
        $blocks = [];
        foreach ($sections as $s) {
            if (in_array($s['key'] ?? '', $members, true) && empty($s['blocks'])) {
                if ($at === null) {
                    $at = count($out);
                    $out[] = null; // placeholder keeps the tab where the first member was
                }
                $blocks[] = ['title' => (string) $s['title'], 'headers' => $s['headers'], 'rows' => $s['rows']];
                continue;
            }
            $out[] = $s;
        }
        if ($at !== null) {
            $out[$at] = ['key' => $newkey, 'title' => $title, 'headers' => [], 'rows' => [], 'blocks' => $blocks];
        }
        $sections = $out;
    }
    return $sections;
}

/**
 * For CSV and PDF: turn stacked-block tabs back into plain tables, and
 * leave out the Excel-only per-service-line tabs.
 */
function beacon_report_flatten(array $sections): array
{
    $flat = [];
    foreach ($sections as $s) {
        if (!empty($s['xlsx_only'])) {
            continue;
        }
        if (!empty($s['blocks'])) {
            foreach ($s['blocks'] as $b) {
                $flat[] = ['key' => $s['key'] ?? '', 'title' => (string) $b['title'], 'headers' => $b['headers'], 'rows' => $b['rows']];
            }
            continue;
        }
        $flat[] = $s;
    }
    return $flat;
}

/** Every report tab that can be switched off: key => label. */
function beacon_report_tab_choices(): array
{
    return [
        'series'            => __('Pageviews by day (only when there are no entry links)', 'beacon-analytics'),
        'entry_links'       => __('Pageviews by entry link', 'beacon-analytics'),
        'top_pages'         => __('Top pages', 'beacon-analytics'),
        'entries'           => __('Entry pages', 'beacon-analytics'),
        'exits'             => __('Exit pages', 'beacon-analytics'),
        'referrers'         => __('Referrers', 'beacon-analytics'),
        'technology'        => __('Technology (devices, browsers, OS, screens)', 'beacon-analytics'),
        'exit_urls'         => __('Exit URLs (outbound clicks)', 'beacon-analytics'),
        'locations'         => __('Locations (countries, US states)', 'beacon-analytics'),
        'survey'            => __('Survey responses (only when there are no service lines)', 'beacon-analytics'),
        'survey_completion' => __('Survey completion', 'beacon-analytics'),
        'events'            => __('Events (totals and details)', 'beacon-analytics'),
        'sl_compare'        => __('Service lines compare', 'beacon-analytics'),
        'sl_sheets'         => __('One tab per service line (Excel only)', 'beacon-analytics'),
        'funnels'           => __('Funnels', 'beacon-analytics'),
        'scan_counts'       => __('Site scan: findings by severity', 'beacon-analytics'),
        'scan_findings'     => __('Site scan: open findings', 'beacon-analytics'),
        'journey_sessions'  => __('Journeys: sessions', 'beacon-analytics'),
        'journey_steps'     => __('Journeys: steps', 'beacon-analytics'),
    ];
}

/**
 * One Excel tab per service line ("SL brst", "SL gyn", ..., "SL organic"),
 * each holding that line's numbers in stacked blocks: summary, pages
 * reached (with % of the line's visits and avg time on page), clicks,
 * survey answers, then the line's journey steps in the same pivot-ready
 * columns as the main Journeys tab. Excel only ('xlsx_only').
 *
 * @param array $journeys Already-built journey sections, reused for steps.
 */
function beacon_report_service_line_sheets(string $range, array $journeys = []): array
{
    $d = beacon_get_service_line_data($range);
    if (!array_filter($d['groups'], static fn($g) => $g !== beacon_organic_label())) {
        return []; // no service line visits: nothing to split
    }
    $sum = [];
    foreach ($d['summary'] as $s) {
        $sum[$s['group']] = $s;
    }
    $steps = null;
    foreach ($journeys as $js) {
        if (($js['key'] ?? '') === 'journey_steps') {
            $steps = $js;
        }
    }

    $out = [];
    foreach ($d['groups'] as $g) {
        $s      = $sum[$g] ?? null;
        $visits = max(1, (int) ($s['visits'] ?? 0));
        $pct    = static fn(int $n): float => round(100 * $n / $visits, 1);
        $blocks = [];

        $blocks[] = ['title' => sprintf(/* translators: %s: service line */ __('Service line: %s', 'beacon-analytics'), $g),
            'headers' => [__('Visits', 'beacon-analytics'), __('Avg visit (s)', 'beacon-analytics'),
                __('Avg pages', 'beacon-analytics'), __('% phone', 'beacon-analytics'), __('% answered survey', 'beacon-analytics')],
            'rows' => $s ? [[$s['visits'], $s['avg_len'], $s['avg_pages'], $s['mobile'], $s['answered']]] : []];

        $pick = static function (array $items) use ($g): array {
            $items = array_values(array_filter($items, static fn($it) => ($it['by'][$g] ?? 0) > 0));
            usort($items, static fn($a, $b) => $b['by'][$g] <=> $a['by'][$g]);
            return $items;
        };

        $rows = [];
        foreach ($pick($d['pages']) as $it) {
            $rows[] = [$it['label'], $it['by'][$g], $pct($it['by'][$g]), $d['time'][$it['label']][$g] ?? ''];
        }
        if ($rows) {
            $blocks[] = ['title' => __('Pages these visits reached', 'beacon-analytics'),
                'headers' => [__('Page', 'beacon-analytics'), __('Visits', 'beacon-analytics'),
                    __('% of visits', 'beacon-analytics'), __('Avg time on page (s)', 'beacon-analytics')],
                'rows' => $rows];
        }
        $rows = [];
        foreach ($pick($d['clicks']) as $it) {
            $parts  = explode(' — ', $it['label'], 2);
            $rows[] = [$parts[0], $parts[1] ?? '', $it['by'][$g], $pct($it['by'][$g])];
        }
        if ($rows) {
            $blocks[] = ['title' => __('What these visits clicked', 'beacon-analytics'),
                'headers' => [__('Event', 'beacon-analytics'), __('Label', 'beacon-analytics'),
                    __('Visits', 'beacon-analytics'), __('% of visits', 'beacon-analytics')],
                'rows' => $rows];
        }
        $rows = [];
        foreach ($pick($d['answers']) as $it) {
            $qa     = explode(' → ', $it['label'], 2);
            $rows[] = [trim($qa[0]), trim($qa[1] ?? ''), $it['by'][$g], $pct($it['by'][$g])];
        }
        if ($rows) {
            $blocks[] = ['title' => __('How these visits answered', 'beacon-analytics'),
                'headers' => [__('Question', 'beacon-analytics'), __('Answer', 'beacon-analytics'),
                    __('Visits', 'beacon-analytics'), __('% of visits', 'beacon-analytics')],
                'rows' => $rows];
        }
        if ($steps) {
            $rows = array_values(array_filter($steps['rows'], static fn($row) => ($row[1] ?? '') === $g));
            if ($rows) {
                $blocks[] = ['title' => __('Journey steps', 'beacon-analytics'),
                    'headers' => $steps['headers'], 'rows' => $rows];
            }
        }

        $out[] = ['key' => 'sl_sheets', 'title' => 'SL ' . $g, 'headers' => [], 'rows' => [],
            'blocks' => $blocks, 'xlsx_only' => true];
    }
    return $out;
}

/**
 * Service-line tabs: summary, pages, time on page, clicks, survey answers.
 * Rows are items; columns are "All" plus one column per service line.
 * Counts are VISITS (a visit that clicks twice counts once). Empty when
 * no visit in the range arrived on an entry link.
 */
function beacon_report_service_line_sections(string $range): array
{
    $d = beacon_get_service_line_data($range);
    $named = array_filter($d['groups'], static fn($g) => $g !== beacon_organic_label());
    if (!$named) {
        return [];
    }
    $out  = [];
    $note = $d['sampled'] ? ' ' . __('(sampled: very busy range)', 'beacon-analytics') : '';

    $out[] = ['key' => 'sl_summary', 'title' => __('Service lines: summary', 'beacon-analytics') . $note,
        'headers' => [__('Service line', 'beacon-analytics'), __('Visits', 'beacon-analytics'),
            __('Avg visit (s)', 'beacon-analytics'), __('Avg pages', 'beacon-analytics'),
            __('% phone', 'beacon-analytics'), __('% answered survey', 'beacon-analytics')],
        'rows' => array_map(static fn($s) => [$s['group'], $s['visits'], $s['avg_len'], $s['avg_pages'],
            $s['mobile'], $s['answered']], $d['summary'])];

    $head = static fn(string $first) => array_merge([$first, __('All', 'beacon-analytics')], $d['groups']);
    $grid = static function (array $items) use ($d): array {
        $rows = [];
        foreach ($items as $it) {
            $row = [$it['label'], $it['all']];
            foreach ($d['groups'] as $g) {
                $row[] = $it['by'][$g];
            }
            $rows[] = $row;
        }
        return $rows;
    };

    if ($d['pages']) {
        $out[] = ['key' => 'sl_pages', 'title' => __('Service lines: pages (visits)', 'beacon-analytics'),
            'headers' => $head(__('Page', 'beacon-analytics')), 'rows' => $grid($d['pages'])];
        $trows = [];
        foreach ($d['pages'] as $it) {
            $row = [$it['label'], $d['time'][$it['label']]['__all'] ?? ''];
            foreach ($d['groups'] as $g) {
                $row[] = $d['time'][$it['label']][$g] ?? '';
            }
            $trows[] = $row;
        }
        $out[] = ['key' => 'sl_time', 'title' => __('Service lines: avg time on page (s)', 'beacon-analytics'),
            'headers' => $head(__('Page', 'beacon-analytics')), 'rows' => $trows];
    }
    if ($d['clicks']) {
        $out[] = ['key' => 'sl_clicks', 'title' => __('Service lines: clicks (visits)', 'beacon-analytics'),
            'headers' => $head(__('Event — label', 'beacon-analytics')), 'rows' => $grid($d['clicks'])];
    }
    if ($d['answers']) {
        $out[] = ['key' => 'sl_answers', 'title' => __('Service lines: survey answers (visits)', 'beacon-analytics'),
            'headers' => $head(__('Question → Answer', 'beacon-analytics')), 'rows' => $grid($d['answers'])];
    }
    return $out;
}

/**
 * Journeys for exports: a sessions table, and the ordered steps of the
 * most recent sessions. Sessions are identified by a short prefix of the
 * anonymous session code so the two tables can be matched up. Bounded so
 * a busy site cannot produce an unreadable file.
 */
function beacon_report_journey_sections(string $range, bool $deep = false): array
{
    require_once BEACON_DIR . 'includes/stats.php';

    // Deep = spreadsheets (Excel/CSV): every visit in the range up to 5,000,
    // with all their steps, so the team can filter and aggregate journeys
    // by service line. Shallow = PDF: recent visits only, to stay readable.
    $max_sessions = $deep ? 5000 : 200;
    $max_detail   = $deep ? 5000 : 50;
    $study_on = beacon_settings()['study_field'] !== '';
    $sessions = beacon_get_sessions($range, '', 1, $max_sessions);
    $out      = [];

    $head = [__('Started', 'beacon-analytics'), __('Session', 'beacon-analytics'), __('Service line', 'beacon-analytics'), __('Visitor', 'beacon-analytics')];
    if ($study_on) {
        $head[] = __('Study code', 'beacon-analytics');
    }
    array_push($head, __('Pages', 'beacon-analytics'), __('Events', 'beacon-analytics'),
        __('Length (s)', 'beacon-analytics'), __('Entry query', 'beacon-analytics'), __('Entry page', 'beacon-analytics'));

    $rows = [];
    foreach ($sessions as $s) {
        $row = [
            get_date_from_gmt((string) $s['started'], 'Y-m-d H:i'),
            substr((string) $s['session_id'], 0, 8),
            beacon_service_line_label((string) $s['entry_path']),
            beacon_visitor_label($range, $s['vh'] ?? null),
        ];
        if ($study_on) {
            $row[] = (string) ($s['study_code'] ?? '');
        }
        array_push($row, (int) $s['pageviews'], (int) $s['events'],
            max(0, strtotime((string) $s['ended']) - strtotime((string) $s['started'])),
            beacon_split_path((string) $s['entry_path'])[1],
            beacon_split_path((string) $s['entry_path'])[0]);
        $rows[] = $row;
    }
    $out[] = ['key' => 'journey_sessions',
        // Fixed tab name, so Excel formulas and pivots that point at this
        // tab keep working from one export to the next. The visit count is
        // on the Overview tab ('note' below).
        'title' => __('Journeys: sessions', 'beacon-analytics'),
        'note'  => count($rows) >= $max_sessions
            ? sprintf(/* translators: %d: count */ __('Most recent %s visits (the limit)', 'beacon-analytics'), number_format_i18n(count($rows)))
            : sprintf(/* translators: %d: count */ __('All %s visits in this period', 'beacon-analytics'), number_format_i18n(count($rows))),
        'headers' => $head, 'rows' => $rows];

    // Step-by-step detail, tagged with each visit's service line.
    $ids   = array_column(array_slice($sessions, 0, $max_detail), 'session_id');
    $steps = beacon_get_steps_for_sessions($ids, $max_detail, $deep ? 100000 : 5000);
    $line  = [];
    $who   = [];
    foreach ($sessions as $s) {
        $line[(string) $s['session_id']] = beacon_service_line_label((string) $s['entry_path']);
        $who[(string) $s['session_id']]  = beacon_visitor_label($range, $s['vh'] ?? null);
    }

    // Time on page per pageview: gap to the next pageview IN THE SAME
    // session. The last page of a session has no next, so it stays blank —
    // that time is unknowable, not zero.
    $durs = [];
    foreach ($steps as $i => $st) {
        if ($st['event_type'] !== 'pageview') {
            continue;
        }
        for ($j = $i + 1, $cnt = count($steps); $j < $cnt; $j++) {
            if ($steps[$j]['session_id'] !== $st['session_id']) {
                break; // steps are ordered by session, then time
            }
            if ($steps[$j]['event_type'] === 'pageview') {
                $durs[$i] = max(0, strtotime((string) $steps[$j]['created_at'])
                    - strtotime((string) $st['created_at']));
                break;
            }
        }
    }

    $rows = [];
    $n    = [];
    foreach ($steps as $i => $st) {
        $sid = (string) $st['session_id'];
        $n[$sid] = ($n[$sid] ?? 0) + 1;
        if ($st['event_type'] === 'pageview') {
            $what = (string) $st['path'];
        } else {
            $what = (string) ($st['event_name'] ?? $st['event_type']);
            if (!empty($st['event_label'])) {
                $what .= ' — ' . $st['event_label'];
            }
        }
        if ($deep) {
            // Spreadsheet layout: one fact per column so it can be filtered
            // and pivoted (e.g. Service line x Label = Embryo freezing).
            $is_pv   = $st['event_type'] === 'pageview';
            $is_ans  = (string) $st['event_name'] === 'survey_response';
            $qa      = $is_ans ? explode(' → ', (string) $st['event_label'], 2) : ['', ''];
            $rows[] = [substr($sid, 0, 8), $line[$sid] ?? '', $who[$sid] ?? '', $n[$sid],
                get_date_from_gmt((string) $st['created_at'], 'Y-m-d H:i:s'),
                $is_pv ? __('page', 'beacon-analytics') : (string) $st['event_type'],
                (string) $st['path'],
                $is_pv ? '' : (string) $st['event_name'],
                ($is_pv || $is_ans) ? '' : (string) ($st['event_label'] ?? ''),
                trim($qa[0]), trim($qa[1] ?? ''),
                isset($durs[$i]) ? $durs[$i] : ''];
            continue;
        }
        $rows[] = [substr($sid, 0, 8), $line[$sid] ?? '', $who[$sid] ?? '', $n[$sid],
            get_date_from_gmt((string) $st['created_at'], 'Y-m-d H:i:s'),
            $st['event_type'] === 'pageview' ? __('page', 'beacon-analytics') : (string) $st['event_type'],
            isset($durs[$i]) ? $durs[$i] : '',
            $what];
    }
    $out[] = ['key' => 'journey_steps',
        'title' => __('Journeys: steps', 'beacon-analytics'),
        'headers' => $deep
            ? [__('Session', 'beacon-analytics'), __('Service line', 'beacon-analytics'), __('Visitor', 'beacon-analytics'), __('#', 'beacon-analytics'),
                __('Time', 'beacon-analytics'), __('Type', 'beacon-analytics'), __('Page', 'beacon-analytics'),
                __('Event', 'beacon-analytics'), __('Label', 'beacon-analytics'),
                __('Question', 'beacon-analytics'), __('Answer', 'beacon-analytics'),
                __('On page (s)', 'beacon-analytics')]
            : [__('Session', 'beacon-analytics'), __('Service line', 'beacon-analytics'), __('Visitor', 'beacon-analytics'), __('#', 'beacon-analytics'),
                __('Time', 'beacon-analytics'), __('Type', 'beacon-analytics'),
                __('On page (s)', 'beacon-analytics'), __('Step', 'beacon-analytics')],
        'rows' => $rows];

    return $out;
}

// ---------------------------------------------------------------- CSV ----

/**
 * Spreadsheet formula injection guard (same rule as the dashboard export):
 * a visitor can plant a path or label starting with = + - @ via the public
 * collector; prefix with ' so Excel shows it as text instead of running it.
 */
function beacon_report_csv_cell(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

function beacon_report_write_csv(array $sections, string $path): bool
{
    $out = fopen($path, 'w');
    if (!$out) {
        return false;
    }
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    foreach (beacon_report_flatten($sections) as $s) {
        fputcsv($out, [beacon_report_csv_cell((string) $s['title'])], ',', '"', '');
        if ($s['headers']) {
            fputcsv($out, array_map('beacon_report_csv_cell', array_map('strval', $s['headers'])), ',', '"', '');
        }
        foreach ($s['rows'] as $r) {
            fputcsv($out, array_map('beacon_report_csv_cell', array_map('strval', array_values($r))), ',', '"', '');
        }
        fputcsv($out, [], ',', '"', '');
    }
    return fclose($out);
}

// --------------------------------------------------------------- XLSX ----

/** 0-based column index -> spreadsheet letters (0=A, 25=Z, 26=AA). */
function beacon_xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1 - $m, 26);
    }
    return $s;
}

function beacon_xlsx_esc(string $v): string
{
    // Strip control characters XML forbids, then escape.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v);
    return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** One worksheet's XML from a section. */
function beacon_xlsx_sheet(array $section): string
{
    $rows = [];
    if (!empty($section['blocks'])) {
        // Several small tables stacked on one sheet: title, header, rows,
        // blank line. (No frozen header or filter: there are many headers.)
        foreach ($section['blocks'] as $b) {
            $rows[] = ['cells' => [(string) $b['title']], 'style' => 1];
            if ($b['headers']) {
                $rows[] = ['cells' => $b['headers'], 'style' => 1];
            }
            foreach ($b['rows'] as $br) {
                $rows[] = ['cells' => array_values($br), 'style' => 0];
            }
            $rows[] = ['cells' => [], 'style' => 0];
        }
        $section['headers'] = [];
    } elseif ($section['headers']) {
        $rows[] = ['cells' => $section['headers'], 'style' => 1];
    }
    foreach ($section['rows'] as $r) {
        $rows[] = ['cells' => array_values($r), 'style' => 0];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         // Header row stays put while scrolling (View > Freeze top row).
         // Opens at 130% (the default looked tiny on a projector); header
         // row frozen while scrolling.
         . '<sheetViews><sheetView workbookViewId="0" zoomScale="130" zoomScaleNormal="130">'
         . ($section['headers'] ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
         . '</sheetView></sheetViews>'
         . '<cols><col min="1" max="1" width="52" customWidth="1"/><col min="2" max="12" width="20" customWidth="1"/></cols>'
         . '<sheetData>';
    foreach ($rows as $ri => $row) {
        $xml .= '<row r="' . ($ri + 1) . '">';
        foreach ($row['cells'] as $ci => $cell) {
            $ref = beacon_xlsx_col($ci) . ($ri + 1);
            // Real numbers become numeric cells; anything stringy (paths,
            // "007", dates) stays text. Inline strings can never run as
            // formulas, so no injection guard is needed here.
            if (is_int($cell) || is_float($cell)) {
                $xml .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
            } else {
                $v = (string) $cell;
                if (preg_match('/^-?(?!0\d)\d{1,12}(\.\d+)?$/', $v)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr" s="' . $row['style'] . '"><is><t xml:space="preserve">'
                          . beacon_xlsx_esc($v) . '</t></is></c>';
                }
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    // Filter buttons on the header row (Data > Filter), so a tab can be
    // narrowed to one service line, event, or page in one click.
    if ($section['headers'] && count($rows) > 1) {
        $xml .= '<autoFilter ref="A1:' . beacon_xlsx_col(count($section['headers']) - 1) . count($rows) . '"/>';
    }
    return $xml . '</worksheet>';
}

function beacon_report_write_xlsx(array $sections, string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return false; // caller falls back to CSV
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    // Sheet names: Excel limit 31 chars, no []:*?/\ and must be unique.
    $names = [];
    foreach ($sections as $i => $s) {
        $n = preg_replace('/[\[\]:*?\/\\\\]/', '', (string) $s['title']);
        $n = trim(mb_substr($n !== '' ? $n : 'Sheet', 0, 28));
        $base = $n;
        $k = 2;
        while (in_array($n, $names, true)) {
            $n = $base . ' ' . $k++;
        }
        $names[$i] = $n;
    }

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $wbr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sections as $i => $s) {
        $n = $i + 1;
        $ct  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb  .= '<sheet name="' . beacon_xlsx_esc($names[$i]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wbr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', beacon_xlsx_sheet($s));
    }
    $styleId = count($sections) + 1;
    $ct  .= '</Types>';
    $wb  .= '</sheets></workbook>';
    $wbr .= '<Relationship Id="rId' . $styleId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbr);
    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFDA291C"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="2"><xf xfId="0"/><xf xfId="0" fontId="1" applyFont="1"/></cellXfs>'
        . '</styleSheet>');
    return $zip->close();
}

// ---------------------------------------------------------------- PDF ----

/** UTF-8 -> WinAnsi bytes for the PDF core fonts, with escaping. */
function beacon_pdf_text(string $v): string
{
    $v = (string) @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $v);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $v);
}

/**
 * A deliberately small PDF writer: US Letter pages of text lines in
 * Helvetica, with the MD Anderson red on headings. No images, no library.
 */
function beacon_report_write_pdf(array $sections, string $path, string $title): bool
{
    $W = 612.0; $H = 792.0; $MARGIN = 54.0; $CW = $W - 2 * $MARGIN;

    // A Letter page fits about 8 columns. Wider tables (one column per
    // service line) are split into parts that repeat the first column.
    $split = [];
    foreach (beacon_report_flatten($sections) as $s) {
        $n = count($s['headers']);
        if ($n <= 8) {
            $split[] = $s;
            continue;
        }
        $chunks = array_chunk(range(1, $n - 1), 6);
        foreach ($chunks as $ci => $cols) {
            $pick = static fn(array $row) => array_merge([$row[0] ?? ''], array_map(static fn($c) => $row[$c] ?? '', $cols));
            $split[] = [
                'title'   => $s['title'] . sprintf(' (part %d of %d)', $ci + 1, count($chunks)),
                'headers' => $pick(array_values($s['headers'])),
                'rows'    => array_map(static fn($row) => $pick(array_values($row)), $s['rows']),
            ];
        }
    }
    $sections = $split;

    // ---- Layout pass: turn sections into per-page draw commands. ----
    $pages = [];
    $ops   = '';
    $y     = $H - $MARGIN;

    $newpage = function () use (&$pages, &$ops, &$y, $H, $MARGIN): void {
        if ($ops !== '') {
            $pages[] = $ops;
        }
        $ops = '';
        $y   = $H - $MARGIN;
    };
    // font: 1 = regular, 2 = bold; color: k = black, r = brand red
    $line = function (array $cells, array $xw, float $size, int $font, string $color) use (&$ops, &$y, $MARGIN, $newpage): void {
        if ($y < $MARGIN + $size) {
            $newpage();
        }
        $rgb = $color === 'r' ? '0.855 0.161 0.110' : '0.133 0.133 0.133';
        foreach ($cells as $i => $cell) {
            [$x, $w] = $xw[$i];
            $max  = max(3, (int) floor($w / ($size * 0.52))); // approx Helvetica fit
            $text = (string) $cell;
            if (mb_strlen($text) > $max) {
                $text = mb_substr($text, 0, $max - 1) . '…';
            }
            $ops .= sprintf(
                "BT /F%d %.1f Tf %s rg 1 0 0 1 %.1f %.1f Tm (%s) Tj ET\n",
                $font, $size, $rgb, $MARGIN + $x, $y - $size, beacon_pdf_text($text)
            );
        }
        $y -= $size * 1.45;
    };
    $columns = function (int $n) use ($CW): array {
        if ($n <= 1) {
            return [[0.0, $CW]];
        }
        if ($n === 2) {
            // Paths/questions left, counts right.
            return [[0.0, $CW * 0.62 - 8], [$CW * 0.62, $CW * 0.38 - 8]];
        }
        // 3+ columns: generous first and last (labels and messages), short
        // middles (severity, category, counts).
        $first = $CW * 0.28;
        $last  = $CW * 0.34;
        $mid   = ($CW - $first - $last) / ($n - 2);
        $xw    = [[0.0, $first - 8]];
        for ($i = 1; $i < $n - 1; $i++) {
            $xw[] = [$first + ($i - 1) * $mid, $mid - 8];
        }
        $xw[] = [$CW - $last, $last - 8];
        return $xw;
    };

    $line([$title], $columns(1), 16.0, 2, 'r');
    $y -= 4;
    foreach ($sections as $s) {
        if ($y < $MARGIN + 60) {
            $newpage(); // keep a heading with at least a couple of rows
        }
        $y -= 6;
        $line([(string) $s['title']], $columns(1), 11.5, 2, 'r');
        $n = max(count($s['headers']), max(array_map('count', $s['rows'] ?: [[0]])));
        $xw = $columns(max(1, $n));
        if ($s['headers']) {
            $line(array_map('strval', $s['headers']), $xw, 8.5, 2, 'k');
        }
        foreach ($s['rows'] as $r) {
            $line(array_map('strval', array_values($r)), $xw, 8.5, 1, 'k');
        }
    }
    $newpage(); // flush the last page

    // ---- Object pass: catalog, pages, fonts, then page+stream pairs. ----
    $objs = [];
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $next = 5; // 3, 4 are the fonts
    $pageObjs = [];
    foreach ($pages as $stream) {
        $pageId    = $next++;
        $contentId = $next++;
        $kids[]    = $pageId . ' 0 R';
        $pageObjs[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] '
            . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $pageObjs[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $objs   += $pageObjs;
    ksort($objs);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objs as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $max  = (int) max(array_keys($objs));
    $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    return file_put_contents($path, $pdf) !== false;
}

// --------------------------------------------------------------- Send ----

/**
 * Build and email one report covering the last $days days.
 * Returns true when wp_mail accepted it.
 */
function beacon_send_report(int $days, string $reason): bool
{
    $o       = beacon_settings();
    $content = array_values(array_intersect(explode(',', (string) $o['report_content']), ['analytics', 'scan', 'funnels', 'journeys']));
    if (!$content) {
        $content = ['analytics'];
    }
    $to = beacon_report_recipients();
    if (!$to) {
        return false;
    }

    $range    = min(365, max(1, $days)) . 'd';
    $sections = beacon_report_sections($range, $content, (string) $o['report_format'] !== 'pdf');

    // Attachment in the chosen format. XLSX needs ZipArchive; fall back to
    // CSV (which Excel opens fine) if the host lacks it.
    $format = (string) $o['report_format'];
    $stamp  = wp_date('Y-m-d');
    $base   = trailingslashit(get_temp_dir()) . 'beacon-report-' . $stamp . '-' . wp_generate_password(8, false);
    $ok     = false;
    $file   = '';
    if ($format === 'pdf') {
        $file = $base . '.pdf';
        $ok   = beacon_report_write_pdf($sections, $file, sprintf('Beacon Analytics — %s', wp_parse_url(home_url(), PHP_URL_HOST) ?: 'report'));
    } elseif ($format === 'xlsx') {
        $file = $base . '.xlsx';
        $ok   = beacon_report_write_xlsx($sections, $file);
    }
    if (!$ok) { // csv chosen, or the fancier format failed
        $file = $base . '.csv';
        $ok   = beacon_report_write_csv($sections, $file);
    }
    if (!$ok) {
        return false;
    }

    // Short summary for the email body; the attachment has everything.
    $host   = wp_parse_url(home_url(), PHP_URL_HOST) ?: home_url();
    $tot    = ['pageviews' => null, 'visitors' => null, 'sessions' => null];
    foreach (beacon_report_flatten($sections) as $s) {
        if (count($s['headers']) === 3 && count($s['rows']) === 1 && is_int($s['rows'][0][0] ?? null)) {
            [$tot['pageviews'], $tot['visitors'], $tot['sessions']] = $s['rows'][0];
            break;
        }
    }
    $subject = sprintf('[Beacon] %s — %s report (%d days)', $host, $reason, $days);
    $body  = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;color:#222">';
    $body .= '<h2 style="color:#DA291C;border-bottom:3px solid #DA291C;padding-bottom:8px">Beacon Analytics</h2>';
    $body .= '<p style="margin:4px 0">' . esc_html($host) . ' &middot; ' . esc_html(sprintf('last %d days', $days)) . '</p>';
    if ($tot['pageviews'] !== null) {
        $body .= '<p style="font-size:15px;margin:14px 0"><strong>' . number_format_i18n($tot['pageviews']) . '</strong> pageviews &middot; <strong>'
               . number_format_i18n($tot['visitors']) . '</strong> visitors &middot; <strong>'
               . number_format_i18n($tot['sessions']) . '</strong> sessions</p>';
    }
    $body .= '<p>The full report is attached (' . esc_html(strtoupper(pathinfo($file, PATHINFO_EXTENSION))) . '). '
           . 'It holds the same de-identified totals as the dashboard &mdash; no personal data.</p>';
    $body .= '<p style="color:#767676;font-size:12px">Sent automatically by the Beacon Analytics plugin on '
           . esc_html($host) . '. Change schedule, content, or recipients in Beacon &rarr; Settings.</p></div>';

    $sent = wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], [$file]);
    @unlink($file);
    return (bool) $sent;
}

// The scheduler: twice a day, send whichever selected intervals are due.
add_action('beacon_email_tick', function (): void {
    $o        = beacon_settings();
    $selected = array_intersect(explode(',', (string) $o['report_intervals']), array_keys(beacon_report_intervals()));
    if (!$selected) {
        return;
    }
    $last  = get_option(BEACON_REPORT_LAST, []);
    $last  = is_array($last) ? $last : [];
    $now   = time();
    $dirty = false;
    foreach ($selected as $key) {
        [$days, $label] = beacon_report_intervals()[$key];
        if (empty($last[$key])) {
            // Just switched on: start the clock so the first report covers
            // one full period instead of a partial one.
            $last[$key] = $now;
            $dirty = true;
            continue;
        }
        // 6h slack so a tick landing slightly early doesn't push the report
        // a whole extra half-day late every cycle.
        if ($now - (int) $last[$key] >= $days * DAY_IN_SECONDS - 6 * HOUR_IN_SECONDS) {
            if (beacon_send_report($days, $label)) {
                $last[$key] = $now;
                $dirty = true;
            }
        }
    }
    if ($dirty) {
        update_option(BEACON_REPORT_LAST, $last, false);
    }
});

// "Send a test report now" button on the settings screen.
add_action('admin_post_beacon_send_test_report', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_send_test_report');

    $o        = beacon_settings();
    $selected = array_intersect(explode(',', (string) $o['report_intervals']), array_keys(beacon_report_intervals()));
    $days     = 7;
    foreach ($selected as $key) { // shortest selected interval, else 7 days
        $days = beacon_report_intervals()[$key][0];
        break;
    }
    $ok = beacon_send_report($days, __('test', 'beacon-analytics'));

    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-settings', 'beacon_msg' => $ok ? 'report_sent' : 'report_fail'],
        admin_url('admin.php')
    ));
    exit;
});
