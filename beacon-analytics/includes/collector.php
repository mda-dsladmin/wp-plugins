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
 * a request must carry this site's 32-char key, pass a per-IP rate limit, and
 * survive strict allow-list validation before a row is written. There is no
 * read path here — reading data requires manage_options in wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function (): void {
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
 * Constant-time check of the submitted site key against ours.
 */
function beacon_key_ok(string $key): bool
{
    $ours = beacon_settings()['site_key'];
    return preg_match('/^[a-f0-9]{32}$/', $key) === 1
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
 * Per-IP, per-minute rate limit using a transient. The IP is only hashed into
 * the transient key; it is not stored.
 */
function beacon_rate_limited(): bool
{
    $key  = 'beacon_rl_' . md5(beacon_client_ip() . gmdate('YmdHi'));
    $hits = (int) get_transient($key);
    if ($hits >= 120) {
        return true;
    }
    set_transient($key, $hits + 1, MINUTE_IN_SECONDS * 2);
    return false;
}

/**
 * Scrub one beacon and store it. Shared by the POST and pixel paths.
 *
 * @param array $in Raw beacon fields (already decoded).
 */
function beacon_store_event(array $in): void
{
    global $wpdb;

    if (beacon_settings()['dnt_mode'] === 'drop' && beacon_do_not_track()) {
        return; // honor the opt-out entirely: store nothing
    }

    $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $dnt = beacon_do_not_track();

    $type = in_array($in['type'] ?? '', ['pageview', 'event', 'outbound'], true)
        ? $in['type'] : 'pageview';

    // Visitor hash + session only when the visitor has not opted out. With DNT
    // in 'anon' mode the hit is counted but carries no visitor hash at all.
    $visitor_hash = null;
    $session_id   = null;
    $country      = null;
    $region       = null;
    if (!$dnt) {
        $ip           = beacon_client_ip();
        $visitor_hash = beacon_visitor_day_hash($ip, $ua);
        $session_id   = substr(hash('sha256', (string) ($in['sid'] ?? '') . $visitor_hash), 0, 32);
        // Local file lookup, country + state ONLY (Safe Harbor posture).
        [$country, $region] = beacon_geo_lookup($ip);
        unset($ip); // explicit: the IP is never written
    }

    // Labels normally pass the scrubber (digit masking, PII refusal). A
    // study_code label instead passes a STRICTER gate all of its own: it
    // must be a short plain code, or it is dropped entirely. The scrubber's
    // digit masking would eat IDs like C00123; this lane must not, but it
    // gives up nothing — no email, phone, or free text can fit the pattern.
    $label = null;
    if (isset($in['label'])) {
        $raw = sanitize_text_field((string) $in['label']);
        if (($in['name'] ?? '') === 'study_code') {
            $label = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $raw) ? $raw : null;
        } else {
            $label = beacon_scrub_label($raw);
        }
    }

    $wpdb->insert(beacon_table(), [
        'event_type'       => $type,
        'event_name'       => isset($in['name']) ? mb_substr(sanitize_text_field((string) $in['name']), 0, 120) : null,
        'event_label'      => $label,
        'path'             => beacon_clean_path((string) ($in['url'] ?? '/')),
        'title'            => isset($in['title']) ? mb_substr(sanitize_text_field((string) $in['title']), 0, 255) : null,
        'referrer_host'    => beacon_referrer_host(isset($in['ref']) ? (string) $in['ref'] : null),
        'visitor_day_hash' => $visitor_hash,
        'session_id'       => $session_id,
        'country'          => $country,
        'region'           => $region,
        'device_type'      => beacon_device_type($ua),
        'browser'          => beacon_browser_name($ua),
        // Coarse by design (fingerprint-resistant): MAJOR versions only,
        // and the screen arrives pre-bucketed — exact pixels never do.
        'browser_ver'      => isset($in['bv']) && (int) $in['bv'] > 0 ? min(999, (int) $in['bv']) : null,
        'os'               => beacon_os_name($ua),
        'os_ver'           => isset($in['ov']) && preg_match('/^\d{1,3}$/', (string) $in['ov']) ? (string) $in['ov'] : null,
        'screen_bucket'    => in_array($in['sb'] ?? '', ['mini', 'phone', 'tablet', 'laptop', 'large'], true) ? $in['sb'] : null,
        'load_ms'          => isset($in['load']) ? min(600000, max(0, (int) $in['load'])) : null,
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

    // The tracker posts JSON as text/plain (no CORS preflight), so WP won't
    // auto-parse it — decode the raw body first, then fall back.
    $in = json_decode($req->get_body(), true);
    if (!is_array($in)) {
        $in = $req->get_json_params();
    }
    if (!is_array($in)) {
        $in = $req->get_body_params(); // form-encoded fallback
    }

    // When collecting for this site itself, a cross-site Origin is spam:
    // reject it. Requests without an Origin header (curl, old UAs) pass.
    // Hosts are compared with/without www so dual-host setups don't 403.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $origin_host = parse_url($origin, PHP_URL_HOST);
        $home_host   = parse_url(home_url(), PHP_URL_HOST);
        if (is_string($origin_host) && is_string($home_host)) {
            $norm = static fn (string $h): string => preg_replace('/^www\./i', '', strtolower($h));
            if ($norm($origin_host) !== $norm($home_host)) {
                return new WP_REST_Response(null, 403);
            }
        }
    }

    if (!beacon_key_ok((string) ($in['site'] ?? ''))) {
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
    $key = (string) $req->get_param('site');

    if (!empty(beacon_settings()['enabled'])
        && beacon_key_ok($key)
        && !beacon_rate_limited()
    ) {
        beacon_store_event([
            'type' => 'pageview',
            'url'  => (string) ($_SERVER['HTTP_REFERER'] ?? '/'),
        ]);
    }

    // Always serve the pixel, even on a bad key — never leak which it was.
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}
