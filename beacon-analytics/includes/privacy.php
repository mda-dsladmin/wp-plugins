<?php
declare(strict_types=1);

/**
 * Privacy helpers. Everything that touches an identifier lives in this file so
 * the rules are auditable in one place.
 *
 * The contract:
 *  - The visitor's IP is used in memory to build a daily hash, then discarded.
 *    It is never written to the database.
 *  - Query strings are stripped from every URL before storage (they are where
 *    tokens, emails, and record numbers leak).
 *  - Any run of 5+ digits left in a path is masked (an MRN-shaped backstop).
 *  - Do Not Track and Global Privacy Control are honored.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The query keys the admin has allow-listed in settings (e.g. "sl, utm_source").
 *
 * @return string[] Lowercase key names, safe charset only.
 */
function beacon_allowed_query_keys(): array
{
    $raw = (string) beacon_settings()['query_keys'];
    $out = [];
    foreach (explode(',', strtolower($raw)) as $k) {
        $k = trim($k);
        if ($k !== '' && preg_match('/^[a-z0-9_\-]{1,40}$/', $k)) {
            $out[] = $k;
        }
    }
    return array_slice(array_unique($out), 0, 20);
}

/**
 * Path with the query string scrubbed. By default the whole query is removed
 * (that is where tokens, emails, and record numbers leak). Keys the admin has
 * explicitly allow-listed survive — e.g. keep "sl" so /?sl=breast stays a
 * distinct page — and their values are still digit-masked and clamped.
 */
function beacon_clean_path(string $raw_url): string
{
    $path = parse_url($raw_url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    $allowed = beacon_allowed_query_keys();
    if ($allowed) {
        $query = parse_url($raw_url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $keep = [];
            foreach ($allowed as $k) {
                if (isset($params[$k]) && is_string($params[$k]) && $params[$k] !== '') {
                    // Same backstop as paths: mask MRN-shaped digit runs.
                    $v = preg_replace('/\d{5,}/', '[redacted]', $params[$k]);
                    $keep[$k] = mb_substr($v, 0, 60);
                }
            }
            if ($keep) {
                ksort($keep);
                $path .= '?' . http_build_query($keep);
            }
        }
    }

    // Mask any run of 5 or more digits, e.g. a medical record number.
    $path = preg_replace('/\d{5,}/', '[redacted]', $path);
    return mb_substr($path, 0, 512);
}

/**
 * Host only from a referrer, never the full URL (query strings leak there too).
 */
function beacon_referrer_host(?string $referrer): ?string
{
    if (!$referrer) {
        return null;
    }
    $host = parse_url($referrer, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? mb_substr($host, 0, 190) : null;
}

/**
 * Daily-rotating visitor hash. The salt changes every day, so the same visitor
 * gets a different hash tomorrow. That counts unique visitors per day without
 * a durable identifier and without ever storing the IP.
 */
function beacon_visitor_day_hash(string $ip, string $ua): string
{
    $salt = (string) get_option(BEACON_SALT, 'beacon-fallback-salt');
    // wp_date() uses the site's timezone, so the hash rotates at local
    // midnight and "unique visitors today" matches the site's day.
    return hash('sha256', $salt . '|' . wp_date('Y-m-d') . '|' . $ip . '|' . $ua);
}

/**
 * Coarse device bucket from the user-agent. Intentionally crude — this is
 * bucketing, not fingerprinting.
 */
function beacon_device_type(string $ua): string
{
    $ua = strtolower($ua);
    if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
        return 'tablet';
    }
    if (str_contains($ua, 'mobi') || str_contains($ua, 'android')) {
        return 'mobile';
    }
    return $ua === '' ? 'other' : 'desktop';
}

function beacon_browser_name(string $ua): string
{
    $ua = strtolower($ua);
    return match (true) {
        str_contains($ua, 'edg')     => 'Edge',
        str_contains($ua, 'chrome')  => 'Chrome',
        str_contains($ua, 'firefox') => 'Firefox',
        str_contains($ua, 'safari')  => 'Safari',
        default                      => 'Other',
    };
}

function beacon_os_name(string $ua): string
{
    $ua = strtolower($ua);
    return match (true) {
        str_contains($ua, 'windows') => 'Windows',
        str_contains($ua, 'iphone'), str_contains($ua, 'ipad') => 'iOS',
        str_contains($ua, 'mac')     => 'macOS',
        str_contains($ua, 'android') => 'Android',
        str_contains($ua, 'linux')   => 'Linux',
        default                      => 'Other',
    };
}

/**
 * Scrub an event label before storage. Labels are element text from the page
 * (button captions, blurb titles) — page content, not visitor data. Even so:
 * drop anything email- or phone-shaped outright, mask long digit runs, and
 * clamp. The tracker already refuses to read labels from form fields.
 */
function beacon_scrub_label(string $label): ?string
{
    $label = trim(preg_replace('/\s+/', ' ', $label));
    if ($label === '') {
        return null;
    }
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $label)
        || preg_match('/\+?\d[\d\s().\-]{7,}\d/', $label)) {
        return null;
    }
    $label = preg_replace('/\d{5,}/', '[redacted]', $label);
    return mb_substr($label, 0, 160);
}

/**
 * Did the visitor ask not to be tracked? Honors DNT and GPC headers.
 */
function beacon_do_not_track(): bool
{
    return ($_SERVER['HTTP_DNT'] ?? '') === '1'
        || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
}
