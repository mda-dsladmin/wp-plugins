<?php
declare(strict_types=1);

/**
 * The scan engine. Replaces phase2's Node worker with WP-Cron batches, since
 * a WordPress host can't run a headless browser.
 *
 * How a scan runs:
 *  1. beacon_scan_start() creates a run and seeds the queue straight from
 *     WordPress content (published posts, pages, public CPTs + the homepage).
 *     No crawling needed to discover pages — WP already knows them all.
 *  2. beacon_scan_tick fires via WP-Cron, fetches pages in small batches
 *     (time-boxed), runs every check against each parse, and re-schedules
 *     itself until the queue is empty.
 *  3. Finalize: site-level checks (duplicate titles, stale pages, sitemap),
 *     then findings not seen this run flip from open to fixed.
 *
 * Finding identity: fingerprint = hash(path|category|check|selector|message).
 * Same problem on the next scan = same row (last_seen bumps). Gone = fixed.
 * Reappears = reopened. That drives new / still open / fixed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const BEACON_SCAN_TICK_SECONDS = 20;  // time budget per cron tick
const BEACON_SCAN_TICK_PAGES   = 15;  // page cap per cron tick
const BEACON_SCAN_LINK_CAP     = 400; // unique links checked per run

function beacon_scan_runs_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'beacon_scan_runs';
}
function beacon_scan_pages_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'beacon_scan_pages';
}
function beacon_scan_queue_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'beacon_scan_queue';
}
function beacon_findings_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'beacon_findings';
}

/** The run currently queued or running, if any. */
function beacon_scan_active_run(): ?array
{
    global $wpdb;
    $t = beacon_scan_runs_table();
    $row = $wpdb->get_row("SELECT * FROM {$t} WHERE status = 'running' ORDER BY id DESC LIMIT 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    return $row ?: null;
}

/** Is any beacon_scan_tick event (any args) still on the cron calendar? */
function beacon_scan_tick_scheduled(): bool
{
    foreach ((array) _get_cron_array() as $events) {
        if (!empty($events['beacon_scan_tick'])) {
            return true;
        }
    }
    return false;
}

/**
 * Watchdog: a tick event can be lost (fatal mid-tick, host killed the cron
 * request). Without this, the run would stay "running" forever and block
 * every future scan. If a running run has no tick scheduled, resume it; if
 * it has been going over 6 hours, call it failed.
 */
function beacon_scan_watchdog(): void
{
    global $wpdb;
    $run = beacon_scan_active_run();
    if (!$run) {
        return;
    }
    $age = time() - (int) strtotime($run['started_at'] . ' UTC');
    if ($age > 6 * HOUR_IN_SECONDS) {
        $wpdb->update(beacon_scan_runs_table(), [
            'status'      => 'failed',
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int) $run['id']]);
        delete_option('beacon_scan_links_' . (int) $run['id']);
        return;
    }
    if ($age > 2 * MINUTE_IN_SECONDS && !beacon_scan_tick_scheduled()) {
        beacon_scan_schedule_tick((int) $run['id'], (int) $run['pages_scanned'] + 100000);
    }
}
add_action('admin_init', 'beacon_scan_watchdog');

/** First-party hosts: this site plus any extra domains from settings. */
function beacon_scan_allowed_hosts(): array
{
    $hosts = [];
    $home  = parse_url(home_url(), PHP_URL_HOST);
    if (is_string($home)) {
        $hosts[] = strtolower($home);
        $hosts[] = strtolower(preg_replace('/^www\./', '', $home));
    }
    foreach (explode(',', (string) beacon_settings()['scan_allowed_domains']) as $d) {
        $d = strtolower(trim($d));
        if ($d !== '' && preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $d)) {
            $hosts[] = $d;
        }
    }
    return array_unique($hosts);
}

/**
 * Start a scan: create the run, seed the queue from WP content, kick cron.
 *
 * @return string 'started' | 'already_running'
 */
function beacon_scan_start(): string
{
    global $wpdb;

    if (beacon_scan_active_run()) {
        return 'already_running';
    }

    $max = max(10, min(2000, (int) beacon_settings()['scan_max_pages']));

    $wpdb->insert(beacon_scan_runs_table(), [
        'status'     => 'running',
        'started_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $run_id = (int) $wpdb->insert_id;

    // Seed straight from WordPress: homepage first, then all published
    // public content, newest first, up to the page cap.
    $urls = [home_url('/')];
    $ids  = get_posts([
        'post_type'      => get_post_types(['public' => true]),
        'post_status'    => 'publish',
        'posts_per_page' => $max - 1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'fields'         => 'ids',
    ]);
    foreach ($ids as $id) {
        $urls[] = get_permalink($id);
    }
    $urls = array_values(array_unique(array_filter($urls)));

    foreach ($urls as $u) {
        $wpdb->insert(beacon_scan_queue_table(), [
            'run_id' => $run_id,
            'url'    => mb_substr($u, 0, 512),
            'state'  => 'pending',
        ]);
    }
    $wpdb->update(beacon_scan_runs_table(), ['pages_total' => count($urls)], ['id' => $run_id]);

    // Per-run link-status cache lives in one option row, deleted at the end.
    update_option('beacon_scan_links_' . $run_id, [], false);

    beacon_scan_schedule_tick($run_id, 0);
    return 'started';
}

/**
 * Schedule the next tick. The batch number keeps each event's args unique so
 * WP-Cron's 10-minute duplicate-event protection never swallows one.
 */
function beacon_scan_schedule_tick(int $run_id, int $batch): void
{
    wp_schedule_single_event(time(), 'beacon_scan_tick', [$run_id, $batch]);
    if (function_exists('spawn_cron')) {
        spawn_cron();
    }
}

add_action('beacon_scan_tick', 'beacon_scan_do_tick', 10, 2);

/**
 * One cron tick: process queued pages until the time or page budget runs
 * out, then either re-schedule or finalize.
 */
function beacon_scan_do_tick(int $run_id, int $batch): void
{
    global $wpdb;

    $run = beacon_scan_active_run();
    if (!$run || (int) $run['id'] !== $run_id) {
        return; // stale event for a finished/failed run
    }

    $qt       = beacon_scan_queue_table();
    $deadline = time() + BEACON_SCAN_TICK_SECONDS;
    $done     = 0;
    $statuses = [];

    while ($done < BEACON_SCAN_TICK_PAGES && time() < $deadline) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, url FROM {$qt} WHERE run_id = %d AND state = 'pending' ORDER BY id LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $run_id
        ), ARRAY_A);
        if (!$row) {
            break;
        }
        // Atomic claim: only the process that flips pending->done works the
        // URL, so an overlapping cron run can't double-process it. Claiming
        // first also means a crashed tick can't loop on one URL forever.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$qt} SET state = 'done' WHERE id = %d AND state = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $row['id']
        ));
        if ($claimed !== 1) {
            continue; // another process got it
        }

        $statuses[] = beacon_scan_process_url($run_id, (string) $row['url'], $deadline);
        $done++;
    }

    // Loopback guard: if the very first batch can't fetch anything at all,
    // the server likely can't reach its own site. Fail loudly instead of
    // filing a wall of false "page could not be fetched" findings.
    if ($done >= 3 && (int) $run['pages_scanned'] === 0 && array_unique($statuses) === [0]) {
        $wpdb->update(beacon_scan_runs_table(), [
            'status'      => 'failed',
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $run_id]);
        delete_option('beacon_scan_links_' . $run_id);
        return;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE " . beacon_scan_runs_table() . " SET pages_scanned = pages_scanned + %d WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $done,
        $run_id
    ));

    $pending = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$qt} WHERE run_id = %d AND state = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $run_id
    ));

    if ($pending > 0) {
        beacon_scan_schedule_tick($run_id, $batch + 1);
    } else {
        beacon_scan_finalize($run_id);
    }
}

/**
 * Fetch one page and run every per-page check against the single parse.
 *
 * @return int The HTTP status the page returned (0 = unreachable).
 */
function beacon_scan_process_url(int $run_id, string $url, int $deadline): int
{
    global $wpdb;

    // Keep the query string here: with plain permalinks every page is
    // /?p=123, and dropping the query would collapse all findings onto "/".
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $q    = parse_url($url, PHP_URL_QUERY);
    if (is_string($q) && $q !== '') {
        $path .= '?' . $q;
    }

    $resp = wp_remote_get($url, [
        'timeout'     => 15,
        'redirection' => 3,
        'user-agent'  => 'BeaconScanner/1.0 (site self-check; ' . home_url('/') . ')',
    ]);

    $status = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
    $body   = is_wp_error($resp) ? '' : (string) wp_remote_retrieve_body($resp);
    $is_html = !is_wp_error($resp)
        && str_contains((string) wp_remote_retrieve_header($resp, 'content-type'), 'text/html');

    $title = '';
    if ($is_html && preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
        $title = mb_substr(trim(wp_strip_all_tags($m[1])), 0, 255);
    }

    $wpdb->insert(beacon_scan_pages_table(), [
        'run_id'      => $run_id,
        'path'        => mb_substr($path, 0, 512),
        'http_status' => $status,
        'title'       => $title !== '' ? $title : null,
        'fetched_at'  => gmdate('Y-m-d H:i:s'),
    ]);

    if ($status >= 400 || $status === 0) {
        beacon_record_finding($run_id, $path, beacon_finding(
            'links',
            'page_error',
            'critical',
            $status === 0 ? 'Page could not be fetched' : sprintf('Page returns HTTP %d', $status)
        ));
        return $status;
    }
    if (!$is_html || $body === '') {
        return $status;
    }

    // ---- one parse, every check ----
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) {
        return $status;
    }

    $allowed   = beacon_scan_allowed_hosts();
    $body_text = '';
    $body_el   = $doc->getElementsByTagName('body');
    if ($body_el->length) {
        $body_text = $body_el->item(0)->textContent;
    }

    $findings = array_merge(
        beacon_check_privacy($doc, $allowed),
        beacon_check_seo($doc, $body_text),
        beacon_check_a11y($doc),
        beacon_check_content($body_text),
        beacon_check_policy($body_text, beacon_parse_policy_rules((string) beacon_settings()['scan_policy_rules']))
    );
    foreach ($findings as $f) {
        beacon_record_finding($run_id, $path, $f);
    }

    beacon_scan_check_links($run_id, $path, beacon_extract_links($doc, $url), $allowed, $deadline);
    return $status;
}

/**
 * Broken-link pass. Each unique URL is checked once per run (cached in one
 * option row) with a HEAD request, GET fallback. Outbound checks carry no
 * visitor data — it is the server asking "does this link still work?" — and
 * can be turned off entirely in settings.
 */
function beacon_scan_check_links(int $run_id, string $path, array $links, array $allowed, int $deadline): void
{
    $opt   = 'beacon_scan_links_' . $run_id;
    $cache = get_option($opt, []);
    if (!is_array($cache)) {
        $cache = [];
    }
    $check_external = !empty(beacon_settings()['scan_check_external']);
    $dirty = false;

    foreach ($links as $link) {
        $host     = beacon_url_host($link);
        $internal = beacon_host_allowed($host, $allowed);

        if (!$internal && !$check_external) {
            continue;
        }

        if (!array_key_exists($link, $cache)) {
            // Respect the tick's time budget: uncached links wait for a later
            // page that also carries them. Never blow past max_execution_time.
            if (time() >= $deadline || count($cache) >= BEACON_SCAN_LINK_CAP) {
                break;
            }
            $resp = wp_remote_head($link, ['timeout' => 5, 'redirection' => 3, 'user-agent' => 'BeaconScanner/1.0']);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            // Some servers reject HEAD; confirm with a light GET before flagging.
            if (in_array($code, [0, 403, 405, 501], true)) {
                $resp = wp_remote_get($link, ['timeout' => 5, 'redirection' => 3, 'user-agent' => 'BeaconScanner/1.0']);
                $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            }
            $cache[$link] = $code;
            $dirty = true;
        }

        $code = (int) $cache[$link];
        if ($code >= 400 || $code === 0) {
            beacon_record_finding($run_id, $path, [
                'category' => 'links',
                'check_id' => 'broken_link',
                'severity' => $internal ? 'serious' : 'moderate',
                'message'  => sprintf(
                    '%s link is broken (%s): %s',
                    $internal ? 'Internal' : 'Outbound',
                    $code === 0 ? 'unreachable' : 'HTTP ' . $code,
                    mb_substr($link, 0, 180)
                ),
                'selector' => null,
                'snippet'  => null,
                'fkey'     => $link, // stable identity even when the code flaps
            ]);
        }
    }

    if ($dirty) {
        update_option($opt, $cache, false); // one write per page, not per link
    }
}

/**
 * Upsert one finding by fingerprint. Same issue next run = same row.
 *
 * The fingerprint deliberately excludes the message: messages embed numbers
 * that drift between scans (word counts, HTTP codes, reading grades), and
 * hashing them would churn identities — auto-"fixing" old rows, minting new
 * ones, and un-sticking an admin's "ignored". Identity is path + check +
 * selector (+ an optional 'fkey' like a link URL); the message just updates.
 */
function beacon_record_finding(int $run_id, string $path, array $f): void
{
    global $wpdb;
    $t = beacon_findings_table();

    $path        = mb_substr($path, 0, 512);
    $fingerprint = hash('sha256', implode('|', [
        $path, $f['category'], $f['check_id'], (string) ($f['selector'] ?? ''), (string) ($f['fkey'] ?? ''),
    ]));

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status FROM {$t} WHERE fingerprint = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fingerprint
    ), ARRAY_A);

    if ($existing) {
        $update = [
            'last_seen_run' => $run_id,
            'message'       => $f['message'], // refresh drifting numbers
            'severity'      => in_array($f['severity'], beacon_severities(), true) ? $f['severity'] : 'moderate',
        ];
        if ($existing['status'] === 'fixed') {
            $update['status'] = 'open'; // it came back; 'ignored' stays ignored
        }
        $wpdb->update($t, $update, ['id' => (int) $existing['id']]);
        return;
    }

    $wpdb->insert($t, [
        'fingerprint'    => $fingerprint,
        'category'       => $f['category'],
        'check_id'       => $f['check_id'],
        'severity'       => in_array($f['severity'], beacon_severities(), true) ? $f['severity'] : 'moderate',
        'path'           => $path,
        'message'        => $f['message'],
        'selector'       => $f['selector'],
        'snippet'        => $f['snippet'],
        'status'         => 'open',
        'first_seen_run' => $run_id,
        'last_seen_run'  => $run_id,
        'created_at'     => gmdate('Y-m-d H:i:s'),
    ]);
}

/**
 * End of run: site-level checks, then close out.
 */
function beacon_scan_finalize(int $run_id): void
{
    global $wpdb;
    $pt = beacon_scan_pages_table();

    // Duplicate titles across the scanned pages.
    $dupes = $wpdb->get_results($wpdb->prepare(
        "SELECT title, COUNT(*) AS n FROM {$pt}
         WHERE run_id = %d AND title IS NOT NULL
         GROUP BY title HAVING n > 1 LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $run_id
    ), ARRAY_A);
    foreach ($dupes as $d) {
        $paths = $wpdb->get_col($wpdb->prepare(
            "SELECT path FROM {$pt} WHERE run_id = %d AND title = %s LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $run_id,
            $d['title']
        ));
        foreach ($paths as $p) {
            beacon_record_finding($run_id, (string) $p, [
                'category' => 'content',
                'check_id' => 'duplicate_title',
                'severity' => 'moderate',
                'message'  => sprintf('Title "%s" is shared by %d pages', mb_substr((string) $d['title'], 0, 80), (int) $d['n']),
                'selector' => null,
                'snippet'  => null,
            ]);
        }
    }

    // Stale pages, straight from WordPress modified dates.
    $months = max(3, min(60, (int) beacon_settings()['scan_stale_months']));
    $stale  = get_posts([
        'post_type'      => get_post_types(['public' => true]),
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'date_query'     => [['column' => 'post_modified_gmt', 'before' => "-{$months} months"]],
        'orderby'        => 'modified',
        'order'          => 'ASC',
    ]);
    foreach ($stale as $post) {
        $p = parse_url((string) get_permalink($post), PHP_URL_PATH) ?: '/';
        beacon_record_finding($run_id, $p, [
            'category' => 'content',
            'check_id' => 'stale_page',
            'severity' => 'minor',
            'message'  => sprintf('Not updated since %s (over %d months)', get_the_modified_date('Y-m-d', $post), $months),
            'selector' => null,
            'snippet'  => null,
        ]);
    }

    // Sitemap present? WP native first, then the common plugin paths.
    $found = false;
    foreach (['wp-sitemap.xml', 'sitemap.xml', 'sitemap_index.xml'] as $s) {
        $resp = wp_remote_head(home_url('/' . $s), ['timeout' => 8, 'redirection' => 2]);
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        beacon_record_finding($run_id, '/', [
            'category' => 'seo',
            'check_id' => 'sitemap_missing',
            'severity' => 'moderate',
            'message'  => 'No sitemap found (wp-sitemap.xml / sitemap.xml)',
            'selector' => null,
            'snippet'  => null,
        ]);
    }

    // Anything open that this run re-checked but did not see again is fixed.
    // Scoped to pages actually fetched OK this run: a page the scan skipped
    // (page cap) or that errored must not get its findings auto-"fixed".
    $ft = beacon_findings_table();
    $wpdb->query($wpdb->prepare(
        "UPDATE {$ft} SET status = 'fixed'
         WHERE status = 'open' AND last_seen_run < %d
           AND path IN (SELECT path FROM {$pt} WHERE run_id = %d AND http_status BETWEEN 200 AND 399)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $run_id,
        $run_id
    ));

    $wpdb->update(beacon_scan_runs_table(), [
        'status'      => 'done',
        'finished_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => $run_id]);

    delete_option('beacon_scan_links_' . $run_id);

    // Keep only the last 20 runs of history.
    $rt   = beacon_scan_runs_table();
    $keep = $wpdb->get_col("SELECT id FROM {$rt} ORDER BY id DESC LIMIT 20"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    if (count($keep) === 20) {
        $min = (int) min($keep);
        $wpdb->query($wpdb->prepare("DELETE FROM {$rt} WHERE id < %d", $min)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM {$pt} WHERE run_id < %d", $min)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare("DELETE FROM " . beacon_scan_queue_table() . " WHERE run_id < %d", $min)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}

/* ---- weekly schedule, driven by the settings checkbox ---- */

add_action('init', function (): void {
    $weekly    = !empty(beacon_settings()['scan_weekly']);
    $scheduled = (bool) wp_next_scheduled('beacon_weekly_scan');
    if ($weekly && !$scheduled) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'beacon_weekly_scan');
    } elseif (!$weekly && $scheduled) {
        wp_clear_scheduled_hook('beacon_weekly_scan');
    }
});

add_action('beacon_weekly_scan', function (): void {
    beacon_scan_start();
});
