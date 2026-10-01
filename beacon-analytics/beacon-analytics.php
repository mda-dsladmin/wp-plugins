<?php
/**
 * Plugin Name:       Beacon Analytics
 * Description:       Self-hosted, privacy-first analytics that live entirely inside this WordPress site. Collector, tracker, tag manager, and dashboard — no third-party calls, no cookies, no IPs stored.
 * Version:           1.17.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Montez French
 * License:           GPL-2.0-or-later
 * Text Domain:       beacon-analytics
 * Update URI:        https://envsnstudios.com/plugins/beacon-analytics
 *
 * HOW THE PIECES FIT
 * ------------------
 * Everything runs on this site. The data path is:
 *
 *   visitor's browser -> /wp-json/beacon/v1/collect (this site) -> wp_beacon_events
 *
 * No third party is ever in that path. If the "Collector URL" setting is left
 * blank, the tracker posts to this site's own REST endpoint. If a URL is
 * entered, the tracker posts there instead (for a separately hosted Beacon
 * collector) and this site stores nothing.
 *
 * Files:
 *   includes/privacy.php        scrubbing rules (the privacy contract)
 *   includes/collector.php      REST endpoint that receives and stores events
 *   includes/stats.php          dashboard queries
 *   includes/tags.php           first-party tag rules parser
 *   includes/tracker-output.php prints the snippet on the front end
 *   includes/cron.php           daily retention pruning
 *   includes/admin/             dashboard + settings screens
 *   assets/tracker.js           the browser tracker (small, no cookies)
 */

if (!defined('ABSPATH')) {
    exit;
}

const BEACON_OPT     = 'beacon_analytics_settings';
const BEACON_SALT    = 'beacon_analytics_salt';
const BEACON_VERSION = '1.17.1';

define('BEACON_FILE', __FILE__);
define('BEACON_DIR', plugin_dir_path(__FILE__));
define('BEACON_URL', plugin_dir_url(__FILE__));

require_once BEACON_DIR . 'includes/privacy.php';
require_once BEACON_DIR . 'includes/geo.php';
require_once BEACON_DIR . 'includes/tags.php';
require_once BEACON_DIR . 'includes/collector.php';
require_once BEACON_DIR . 'includes/tracker-output.php';
require_once BEACON_DIR . 'includes/cron.php';
require_once BEACON_DIR . 'includes/updates.php';
require_once BEACON_DIR . 'includes/reports.php';
require_once BEACON_DIR . 'includes/scan/checks.php';
require_once BEACON_DIR . 'includes/scan/engine.php';

if (is_admin()) {
    require_once BEACON_DIR . 'includes/stats.php';
    require_once BEACON_DIR . 'includes/admin/menu.php';
    require_once BEACON_DIR . 'includes/admin/dashboard.php';
    require_once BEACON_DIR . 'includes/admin/settings.php';
    require_once BEACON_DIR . 'includes/admin/scan-page.php';
    require_once BEACON_DIR . 'includes/admin/charts.php';
    require_once BEACON_DIR . 'includes/admin/journeys-page.php';
    require_once BEACON_DIR . 'includes/admin/export.php';
}

/**
 * The events table name, prefixed for this install.
 */
function beacon_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'beacon_events';
}

/**
 * Current settings merged over safe defaults, so every key always exists.
 */
function beacon_settings(): array
{
    $defaults = [
        'enabled'           => 0,
        'endpoint'          => '',      // blank = this site's own REST collector
        'site_key'          => '',
        'tags'              => '',
        'query_keys'        => '',      // query params to keep, e.g. 'sl, utm_source'
        'dnt_mode'          => 'anon',  // 'anon' or 'drop'
        'retention_days'    => 90,
        'exclude_logged_in' => 1,
        'delete_on_uninstall' => 0,
        // --- site scan (Phase 2) ---
        'scan_weekly'          => 0,    // run a scan automatically every week
        'scan_max_pages'       => 200,  // page cap per scan
        'scan_check_external'  => 1,    // HEAD-check outbound links too
        'scan_allowed_domains' => '',   // extra first-party domains, comma-sep
        'scan_stale_months'    => 18,   // flag pages not updated in N months
        'scan_policy_rules'    => '',   // one rule per line: pattern | severity | message
        // --- journeys / funnels ---
        'funnels'              => '',   // one per line: name | /step1 > /step2 > event:name
        // Survey capture: a CSS selector for question wrappers. Blank = OFF.
        // When set, the chosen answer + question text are stored as the
        // survey_response label. Deliberately opt-in: this is the one place
        // Beacon stores visitor input. See docs/SECURITY-AND-PRIVACY.md §5.
        'survey_selector'      => '',
        // Site key for an EXTERNAL Beacon collector (used only when the
        // Collector URL is filled in; blank otherwise).
        'external_key'         => '',
        // Number-field overrides for survey capture. STRICT by default: a
        // number field is captured ONLY if named here. One per line:
        //   field | width     (field = input id/name or label text;
        //                      width = bucket size like 5 or 10, or "none")
        'number_overrides'     => '',
        // Copy an image's title attribute into a missing alt at render time.
        'fix_missing_alt'      => 0,
        // Add a visually-hidden h1 (the page title) when a page has none.
        'fix_missing_h1'       => 0,
        // --- emailed reports ---
        'report_emails'        => '',   // comma-separated; blank = admin email
        'report_content'       => 'analytics,scan,funnels,journeys',
        'report_format'        => 'xlsx', // xlsx | pdf | csv
        'report_intervals'     => '',   // comma-separated keys, e.g. '2w,3m'
        // Study code capture (IDENTIFIABLE — opt in). The id of ONE input
        // whose submitted value is recorded as this session's study code.
        // Blank = off. Turning this on makes stored data identifiable to
        // whoever holds the study's ID list: it requires participant
        // authorization (consent) and hosting allowed to hold identifiable
        // health data. See docs/SECURITY-AND-PRIVACY.md.
        'study_field'          => '',
    ];
    $saved = get_option(BEACON_OPT, []);
    return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
}

/**
 * Where the tracker should POST. The install site's own collector unless the
 * admin filled in an external URL.
 */
function beacon_collect_url(): string
{
    $o = beacon_settings();
    return $o['endpoint'] !== '' ? $o['endpoint'] : rest_url('beacon/v1/collect');
}

/**
 * Create/upgrade all tables and secrets. Runs on activation, and again after
 * a plugin update (see the version check below). Safe to run repeatedly —
 * dbDelta only applies differences.
 */
function beacon_install(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table   = beacon_table();
    $charset = $wpdb->get_charset_collate();

    // No PHI columns by design: no raw IP, no query strings, no name/email
    // fields anywhere. The visitor hash rotates daily, so it is not a durable
    // identifier.
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_type varchar(20) NOT NULL DEFAULT 'pageview',
        event_name varchar(120) DEFAULT NULL,
        event_label varchar(160) DEFAULT NULL,
        path varchar(512) NOT NULL,
        title varchar(255) DEFAULT NULL,
        referrer_host varchar(190) DEFAULT NULL,
        visitor_day_hash char(64) DEFAULT NULL,
        session_id char(32) DEFAULT NULL,
        country char(2) DEFAULT NULL,
        region varchar(64) DEFAULT NULL,
        device_type varchar(10) DEFAULT 'other',
        browser varchar(40) DEFAULT NULL,
        browser_ver smallint(5) unsigned DEFAULT NULL,
        os varchar(40) DEFAULT NULL,
        os_ver varchar(10) DEFAULT NULL,
        screen_bucket varchar(10) DEFAULT NULL,
        screen_w smallint(5) unsigned DEFAULT NULL,
        load_ms mediumint(8) unsigned DEFAULT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_time (created_at),
        KEY idx_type_time (event_type, created_at),
        KEY idx_session (session_id, id)
    ) {$charset};");

    // ---- Phase 2: site-quality scan tables ----

    // One crawl of the site.
    dbDelta("CREATE TABLE {$wpdb->prefix}beacon_scan_runs (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        status varchar(10) NOT NULL DEFAULT 'running',
        pages_total int(10) unsigned NOT NULL DEFAULT 0,
        pages_scanned int(10) unsigned NOT NULL DEFAULT 0,
        started_at datetime NOT NULL,
        finished_at datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_status (status)
    ) {$charset};");

    // One page seen in a run.
    dbDelta("CREATE TABLE {$wpdb->prefix}beacon_scan_pages (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        path varchar(512) NOT NULL,
        http_status smallint(5) unsigned DEFAULT NULL,
        title varchar(255) DEFAULT NULL,
        fetched_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_run (run_id)
    ) {$charset};");

    // URLs waiting to be scanned in a run.
    dbDelta("CREATE TABLE {$wpdb->prefix}beacon_scan_queue (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id bigint(20) unsigned NOT NULL,
        url varchar(512) NOT NULL,
        state varchar(10) NOT NULL DEFAULT 'pending',
        PRIMARY KEY  (id),
        KEY idx_run_state (run_id, state)
    ) {$charset};");

    // One problem, tracked across runs by fingerprint. The fix queue.
    // 'snippet' is the only place page content lands, and it is scrubbed
    // (digit runs masked, clamped) before storage — see beacon_scrub_snippet().
    dbDelta("CREATE TABLE {$wpdb->prefix}beacon_findings (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        fingerprint char(64) NOT NULL,
        category varchar(16) NOT NULL,
        check_id varchar(40) NOT NULL,
        severity varchar(10) NOT NULL DEFAULT 'moderate',
        path varchar(512) NOT NULL,
        message varchar(255) NOT NULL,
        selector varchar(255) DEFAULT NULL,
        snippet varchar(255) DEFAULT NULL,
        status varchar(10) NOT NULL DEFAULT 'open',
        first_seen_run bigint(20) unsigned NOT NULL,
        last_seen_run bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_fingerprint (fingerprint),
        KEY idx_status_cat (status, category, severity),
        KEY idx_last_seen (last_seen_run)
    ) {$charset};");

    $o = beacon_settings();
    if (!preg_match('/^[a-f0-9]{32}$/', $o['site_key'])) {
        $o['site_key'] = bin2hex(random_bytes(16));
        update_option(BEACON_OPT, $o);
    }
    if (!get_option(BEACON_SALT)) {
        // Long random secret for the daily-rotating visitor hash. Never shown
        // in any UI, never sent to the browser.
        add_option(BEACON_SALT, bin2hex(random_bytes(32)), '', false);
    }

    if (!wp_next_scheduled('beacon_daily_prune')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'beacon_daily_prune');
    }
    // Twice a day, check whether any emailed report has come due. Fires
    // often so a report lands near its due time even on quiet sites.
    if (!wp_next_scheduled('beacon_email_tick')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'beacon_email_tick');
    }

    update_option('beacon_db_version', BEACON_VERSION);
}

register_activation_hook(__FILE__, 'beacon_install');

// After a plugin update the activation hook does NOT fire — catch the version
// change here so new tables (like the scan tables) get created.
add_action('plugins_loaded', function (): void {
    if (get_option('beacon_db_version') !== BEACON_VERSION) {
        beacon_install();
    }
});

register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook('beacon_daily_prune');
    wp_clear_scheduled_hook('beacon_weekly_scan');
    wp_clear_scheduled_hook('beacon_email_tick');
    // Tick events carry args, which clear_scheduled_hook (default args)
    // misses — unschedule_hook removes every event for the hook.
    wp_unschedule_hook('beacon_scan_tick');
    delete_site_transient('beacon_update_info');
});
