<?php
declare(strict_types=1);

/**
 * The collector — the only public write endpoint.
 *
 *   POST /wp-json/beacon/v1/collect   JSON beacon from tracker.js
 *   GET  /wp-json/beacon/v1/collect   the <noscript> 1x1 pixel fallback
 *
 * Takes one small beacon per event, scrubs it through includes/privacy.php,
 * and stores a privacy-safe row in wp_beacon_events. Always answers fast and
 * never echoes input back.
 *
 * It is public on purpose (visitors are anonymous), but write-only and gated:
 * a request must carry this site's 32-char key (printed in the page, so it
 * stops blind bots, not a determined attacker), come from this site's own
 * origin, pass per-IP, per-session and site-wide rate limits, and survive
 * allow-list validation and privacy scrubbing before a row is written.
 * Non-text values (arrays, objects) are ignored. There is no read path
 * here — reading data requires manage_options in wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('beacon/v1', '/collect', [
        [
            'methods'             => 'POST',
            'callback'            => 'beacon_collect_post',
            'permission_callback' => '__return_true', // public by design; key-checked inside
        ],
        [
            'methods'             => 'GET',
            'callback'            => 'beacon_collect_pixel',
            'permission_callback' => '__return_true',
        ],
    ]);
});

/**
 * A text field from the beacon, or '' when missing or not plain text
 * (arrays and objects are ignored outright).
 */
function beacon_in_str(array $in, string $key): string
{
    if (!isset($in[$key]) || !is_scalar($in[$key])) {
        return '';
    }
    $v = (string) $in[$key];
    // Cut oversized fields before any scrubbing runs on them, at a character
    // boundary so a multi-byte letter is never split.
    $max = 2048;
    if (strlen($v) > $max) {
        while ($max > 0 && (ord($v[$max]) & 0xC0) === 0x80) {
            $max--;
        }
        $v = substr($v, 0, $max);
    }
    return $v;
}

/**
 * Constant-time check of the submitted site key against ours.
 */
function beacon_key_ok(string $key): bool
{
    $ours = beacon_settings()['site_key'];
    return preg_match('/^[a-f0-9]{32}\z/', $key) === 1
        && $ours !== ''
        && hash_equals($ours, $key);
}

/**
 * The client IP for rate limiting. Filterable so proxied hosts (Cloudflare,
 * nginx) can map their real-IP header — otherwise every visitor would share
 * the proxy's IP and one shared rate bucket.
 */
function beacon_client_ip(): string
{
    return (string) apply_filters('beacon_client_ip', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * The address used for rate limiting: IPv4 as is, IPv6 cut to its /64
 * network (one household or server gets a whole /64, so counting single
 * IPv6 addresses would let one attacker look like millions of visitors).
 * IPv4 written in IPv6 form (::ffff:1.2.3.4) counts as the IPv4 address.
 */
function beacon_rate_ip(): string
{
    return beacon_ip_network(beacon_client_ip(), 32, 64);
}

/**
 * Count one hit in a bucket and say whether it is over the limit. Uses the
 * object cache when the site has a persistent one, otherwise one small row
 * per bucket in wp_beacon_rate (atomic increment; pruned daily). The IP is
 * only hashed into the bucket name; it is not stored.
 */
function beacon_rate_hit(string $bucket, int $limit, int $window)
{
    $key = substr(hash('sha256', $bucket), 0, 40);
    if (wp_using_ext_object_cache()) {
        wp_cache_add($key, 0, 'beacon_rate', $window);
        $hits = wp_cache_incr($key, 1, 'beacon_rate');
        return $hits !== false && (int) $hits > $limit;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'beacon_rate';
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (bucket, hits, expires) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $key,
        time() + $window
    ));
    $hits = (int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM {$table} WHERE bucket = %s", $key)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // Clear out expired rows now and then, so the table stays small even
    // when WP-Cron never runs the daily prune.
    if (mt_rand(1, 200) === 1) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires < %d LIMIT 2000", time())); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    return $hits > $limit;
}

/**
 * Per-IPv6-/48 (600/minute), per-IP (120/minute; an IPv6 /64 counts as one
 * IP) and site-wide (filterable, 3000/minute) limits, in that order. Only a
 * request that passes its own address limits counts toward the site-wide
 * one, so a single flooding address cannot use up everyone's allowance.
 */
function beacon_rate_limited(): bool
{
    $minute = gmdate('YmdHi');
    $net    = beacon_ip_network(beacon_client_ip(), 32, 48);
    if (substr($net, -3) === '/48' // one home or office usually gets a whole IPv6 /48
        && beacon_rate_hit('net|' . $net . '|' . $minute, 600, 2 * MINUTE_IN_SECONDS)) {
        return true;
    }
    if (beacon_rate_hit('ip|' . beacon_rate_ip() . '|' . $minute, 120, 2 * MINUTE_IN_SECONDS)) {
        return true;
    }
    $site_cap = (int) apply_filters('beacon_site_rate_limit', 3000);
    return $site_cap > 0 && beacon_rate_hit('site|' . $minute, $site_cap, 2 * MINUTE_IN_SECONDS);
}

/**
 * Drop expired rate-limit rows (runs with the daily prune).
 */
function beacon_rate_prune()
{
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}beacon_rate WHERE expires < %d", time())); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
add_action('beacon_daily_prune', 'beacon_rate_prune');

/**
 * Is this request's Origin allowed to post events here? No Origin header
 * (curl, some older browsers) is allowed. A present Origin must parse to a
 * host that is this site's (with or without www) or one the admin listed.
 * "null" and malformed origins are refused.
 */
function beacon_origin_allowed(): bool
{
    if (!isset($_SERVER['HTTP_ORIGIN'])) {
        return true;
    }
    $origin_host = parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    if (!is_string($origin_host) || $origin_host === '') {
        return false; // "null", data:, sandboxed frames, garbage
    }
    return beacon_collector_host_allowed($origin_host);
}

/**
 * Is this host one that may send events here: this site's own host (with or
 * without www) or one the admin listed under "Sites allowed to send"?
 */
function beacon_collector_host_allowed(string $host): bool
{
    $norm = static function (string $h): string { return (string) preg_replace('/^www\./i', '', strtolower(trim($h))); };
    $allowed = [(string) parse_url(home_url(), PHP_URL_HOST)];
    foreach (explode(',', (string) beacon_settings()['collector_origins']) as $h) {
        $allowed[] = $h;
    }
    $want = $norm($host);
    foreach ($allowed as $h) {
        if ($want !== '' && $norm($h) === $want) {
            return true;
        }
    }
    return false;
}

/**
 * Scrub one beacon and store it. Shared by the POST and pixel paths.
 *
 * @param array $in Raw beacon fields (already decoded).
 */
function beacon_store_event(array $in)
{
    global $wpdb;

    if (beacon_settings()['dnt_mode'] === 'drop' && beacon_do_not_track()) {
        return; // honor the opt-out entirely: store nothing
    }

    $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $dnt = beacon_do_not_track();

    $type = in_array(beacon_in_str($in, 'type'), ['pageview', 'event', 'outbound'], true)
        ? beacon_in_str($in, 'type') : 'pageview';

    // Event names are keys (tag-rule names, or a host for outbound links):
    // letters, digits, and _ . : - only, and nothing that looks personal
    // (any script on the page can call window.beacon() with any name).
    $name = beacon_in_str($in, 'name');
    $name = (preg_match('/^[A-Za-z0-9_.:-]{1,120}\z/', $name)
        && !beacon_looks_personal($name)
        && !preg_match('/\d{5,}/', $name)) ? $name : null;

    // Visitor hash + session only when the visitor has not opted out. With DNT
    // in 'anon' mode the hit is counted but carries no visitor hash at all.
    $visitor_hash = null;
    $session_id   = null;
    $country      = null;
    $region       = null;
    if (!$dnt) {
        $ip           = beacon_client_ip();
        $visitor_hash = beacon_visitor_day_hash($ip, $ua);
        $session_id   = substr(hash('sha256', beacon_in_str($in, 'sid') . $visitor_hash), 0, 32);
        // One session can't flood the tables (keeps Journeys and reports fast).
        if (beacon_rate_hit('sess|' . $session_id . '|' . gmdate('YmdH'), 500, HOUR_IN_SECONDS)) {
            return;
        }
        // Local file lookup, country + state ONLY (Safe Harbor posture).
        list($country, $region) = beacon_geo_lookup($ip);
        unset($ip); // explicit: the IP is never written
    }

    // Labels normally pass the scrubber (digit masking, PII refusal). A
    // study_code label instead passes a STRICTER gate all of its own: it
    // must be a short plain code, or it is dropped entirely. The scrubber's
    // digit masking would eat IDs like C00123; this lane must not, but it
    // gives up nothing — no email, phone, or free text can fit the pattern.
    // Study codes: only when the admin turned study code capture on, only a
    // short plain code, never a long all-digit number (SSN, phone, MRN
    // shaped) or anything else that looks personal.
    $label = null;
    $raw   = sanitize_text_field(beacon_in_str($in, 'label'));
    if ($raw !== '') {
        if ($name === 'study_code') {
            $label = (beacon_settings()['study_field'] !== ''
                && preg_match('/^[A-Za-z0-9_-]{1,32}\z/', $raw)
                && !preg_match('/^\d{7,}\z/', $raw)
                && !beacon_looks_personal($raw)) ? $raw : null;
            if ($label === null) {
                return; // not a valid study code (or capture is off): store nothing
            }
        } else {
            if ($name === 'survey_response') {
                $raw = beacon_cap_survey_age($raw);
                if ($raw === null) {
                    return; // a birth month or day: store nothing
                }
            }
            $label = beacon_scrub_label($raw);
        }
    }

    $title = sanitize_text_field(beacon_in_str($in, 'title'));

    $wpdb->insert(beacon_table(), [
        'event_type'       => $type,
        'event_name'       => $name,
        'event_label'      => $label,
        'path'             => beacon_clean_path(beacon_in_str($in, 'url') !== '' ? beacon_in_str($in, 'url') : '/'),
        'title'            => $title !== '' ? beacon_scrub_title($title) : null,
        'referrer_host'    => beacon_referrer_host(beacon_in_str($in, 'ref')),
        'visitor_day_hash' => $visitor_hash,
        'session_id'       => $session_id,
        'country'          => $country,
        'region'           => $region,
        'device_type'      => beacon_device_type($ua),
        'browser'          => beacon_browser_name($ua),
        // Coarse by design (fingerprint-resistant): MAJOR versions only,
        // and the screen arrives pre-bucketed — exact pixels never do.
        'browser_ver'      => (int) beacon_in_str($in, 'bv') > 0 ? min(999, (int) beacon_in_str($in, 'bv')) : null,
        'os'               => beacon_os_name($ua),
        'os_ver'           => preg_match('/^\d{1,3}\z/', beacon_in_str($in, 'ov')) ? beacon_in_str($in, 'ov') : null,
        'screen_bucket'    => in_array(beacon_in_str($in, 'sb'), ['mini', 'phone', 'tablet', 'laptop', 'large'], true) ? beacon_in_str($in, 'sb') : null,
        'load_ms'          => beacon_in_str($in, 'load') !== '' ? min(600000, max(0, (int) beacon_in_str($in, 'load'))) : null,
        'created_at'       => gmdate('Y-m-d H:i:s'),
    ]);
}

/**
 * POST handler: the JSON beacon.
 */
function beacon_collect_post(WP_REST_Request $req): WP_REST_Response
{
    if (empty(beacon_settings()['enabled'])) {
        return new WP_REST_Response(null, 403);
    }

    // A real beacon is well under 4 KB. Refuse big bodies before decoding.
    $body = (string) $req->get_body();
    if (strlen($body) > 8192) {
        return new WP_REST_Response(null, 413);
    }

    // The tracker posts JSON as text/plain (no CORS preflight), so WP won't
    // auto-parse it — decode the raw body first, then fall back.
    $in = json_decode($body, true, 4);
    if (!is_array($in)) {
        $in = $req->get_json_params();
    }
    if (!is_array($in)) {
        $in = $req->get_body_params(); // form-encoded fallback
    }

    // Only this site (or origins the admin listed) may post events. Requests
    // without an Origin header (curl, old UAs) pass; "null" origins do not.
    if (!beacon_origin_allowed()) {
        return new WP_REST_Response(null, 403);
    }

    if (!beacon_key_ok(beacon_in_str($in, 'site'))) {
        return new WP_REST_Response(null, 400);
    }
    if (beacon_rate_limited()) {
        return new WP_REST_Response(null, 429);
    }

    beacon_store_event($in);
    return new WP_REST_Response(null, 204);
}

/**
 * GET handler: the no-JS pixel. Returns a 1x1 transparent GIF so the
 * <noscript><img> resolves cleanly. It records a bare pageview only.
 */
function beacon_collect_pixel(WP_REST_Request $req)
{
    $key = $req->get_param('site');
    $key = is_scalar($key) ? (string) $key : '';

    // The page path comes from the Referer. The pixel only counts when the
    // Referer is this site (or a site allowed to send here); with no Referer,
    // or one from anywhere else, nothing is stored. Another site could
    // otherwise embed the pixel and pad the counts.
    $ref = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 2048);
    $rh  = parse_url($ref, PHP_URL_HOST);

    if (!empty(beacon_settings()['enabled'])
        && beacon_key_ok($key)
        && is_string($rh) && beacon_collector_host_allowed($rh)
        && !beacon_rate_limited()
    ) {
        beacon_store_event([
            'type' => 'pageview',
            'url'  => $ref,
        ]);
    }

    // Always serve the pixel, even on a bad key — never leak which it was.
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
