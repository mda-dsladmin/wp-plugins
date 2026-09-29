<?php
declare(strict_types=1);

/**
 * Privacy helpers. Everything that touches an identifier lives in this file so
 * the rules are auditable in one place.
 *
 * The contract:
 *  - The visitor's IP is used in memory to build a daily hash, then discarded.
 *    It is never written to the database. Before hashing, the IP is cut to
 *    its network (IPv4 /24, IPv6 /48), so even a reversed hash could never
 *    point to one person's address. The hash key (salt) is replaced every day
 *    and the old one is thrown away, so hashes cannot be linked across days.
 *    (A same-day copy of the database holds that day's key; the network cut
 *    is what protects that case.)
 *  - Query strings are stripped from every URL before storage (they are where
 *    tokens, emails, and record numbers leak). Search-result paths lose the
 *    search terms.
 *  - Paths, titles, and labels are checked for personal data (emails, phone
 *    and SSN-shaped numbers, dates); a path segment that matches is replaced,
 *    a title or label that matches is dropped. Any run of 5+ digits left is
 *    masked (an MRN-shaped backstop). Percent-encoding, full-width digits,
 *    Unicode dashes and spaces, and invisible characters are undone before
 *    checking so they cannot hide anything.
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
        if ($k !== '' && preg_match('/^[a-z0-9_\-]{1,40}\z/', $k) && !in_array($k, beacon_reserved_query_keys(), true)) {
            $out[] = $k;
        }
    }
    return array_slice(array_unique($out), 0, 20);
}

/**
 * Query keys that can never be allow-listed, because their values are the
 * visitor's own words or identifiers (search terms, emails, names, record
 * numbers, tokens). Settings silently drops them.
 *
 * @return string[]
 */
function beacon_reserved_query_keys(): array
{
    return ['s', 'q', 'search', 'query', 'term', 'keyword', 'keywords', 'k',
        'email', 'e', 'mail', 'name', 'first_name', 'last_name', 'fname', 'lname',
        'phone', 'tel', 'mobile', 'dob', 'birth', 'birthdate', 'mrn', 'ssn', 'patient', 'patient_id',
        'token', 'key', 'auth', 'code', 'password', 'pass', 'session', 'sid',
        'utm_term', 'search_term', 'searchterm', 'search_query', 'gclid', 'fbclid', 'msclkid'];
}

/**
 * Remove invisible formatting characters (Unicode "Cf": zero-width spaces
 * and joiners, left-to-right and other direction marks, soft hyphen, BOM,
 * tag characters, invisible math operators) and variation selectors. They
 * could otherwise be slipped between digits to hide a phone number.
 */
function beacon_strip_invisible(string $s): string
{
    $out = @preg_replace('/[\p{Cf}\x{034F}\x{180B}-\x{180F}\x{FE00}-\x{FE0F}]/u', '', $s);
    return is_string($out) ? $out : $s;
}

/**
 * Fold look-alike characters to plain ASCII for the personal-data checks:
 * full-width and "math" letters and digits (NFKC, when PHP's intl extension
 * is present), digits from other scripts (Arabic-Indic, Devanagari, ...),
 * Unicode dashes and spaces, and invisible characters.
 */
function beacon_fold_text(string $s): string
{
    static $map = null;
    if ($map === null) {
        $chr = function ($c) {
            return html_entity_decode('&#' . $c . ';', ENT_QUOTES, 'UTF-8');
        };
        $map = [];
        // Dashes and minus signs (U+2010-2015, U+2212, U+FE58, U+FE63, U+FF0D) -> "-".
        foreach (array_merge(range(0x2010, 0x2015), [0x2212, 0xFE58, 0xFE63, 0xFF0D]) as $c) {
            $map[$chr($c)] = '-';
        }
        // Unusual spaces (no-break, en/em/thin, narrow no-break, ideographic) -> " ".
        foreach (array_merge([0x00A0, 0x202F, 0x205F, 0x3000], range(0x2000, 0x200A)) as $c) {
            $map[$chr($c)] = ' ';
        }
        // "@" and "." look-alikes.
        $map[$chr(0xFE6B)] = '@';
        $map[$chr(0xFF20)] = '@';
        $map[$chr(0x2024)] = '.';
        // Full-width ASCII block (for hosts without the intl extension).
        for ($c = 0xFF01; $c <= 0xFF5E; $c++) {
            if (!isset($map[$chr($c)])) {
                $map[$chr($c)] = chr($c - 0xFEE0);
            }
        }
    }
    $s = beacon_strip_invisible($s);
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_KC);
        if (is_string($n)) {
            $s = $n;
        }
    }
    $s = strtr($s, $map);
    // Digits from any other script become ASCII digits (their real value
    // when intl is present, otherwise "0": the shape is what the checks need).
    $d = @preg_replace_callback('/(?![0-9])\p{Nd}/u', function ($m) {
        if (class_exists('IntlChar')) {
            $v = IntlChar::charDigitValue($m[0]);
            if (is_int($v) && $v >= 0 && $v <= 9) {
                return (string) $v;
            }
        }
        return '0';
    }, $s);
    return is_string($d) ? $d : $s;
}

/**
 * Does this text look like personal data?
 *
 * Always: emails (also written out: "jane at example dot com", "jane @
 * example . com", "jane[at]example[.]com"), phone- and SSN-shaped numbers.
 * Strict mode (the default) adds calendar dates in any common form and
 * 7-digit local phone numbers. Strict is used for anything a visitor can
 * type or choose (paths, survey answers, event names); page titles and
 * button text, which are the site's own content, use the looser mode so
 * ordinary headlines like "Walk: October 12, 2025" are kept.
 *
 * Year ranges ("2024-2025") and numbers with thousands separators
 * ("1.500.000") are not mistaken for phone numbers. If a check cannot run
 * (a regex error), the text is treated as personal. Errs toward dropping.
 */
function beacon_looks_personal(string $s, bool $strict = true): bool
{
    $t = str_replace(['"', "'"], '', beacon_fold_text($s));
    // Things that look numeric but are not personal: year ranges and grouped thousands.
    $n = (string) preg_replace(['/(?<!\d)(?:19|20)\d{2}\s*[-\/]\s*(?:19|20)?\d{2}(?!\d)/', '/(?<![\d.,])\d{1,3}(?:([.,])\d{3})(?:\1\d{3})+(?![\d.,])/'], ' ', $t);
    if (!$strict) {
        // Titles may carry dates ("Updated 3/14/2025"); don't read them as phone numbers.
        $n = (string) preg_replace(['/(?<!\d)\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2})(?!\d)/', '/(?<!\d)(?:19|20)\d{2}[\/.\-]\d{1,2}[\/.\-]\d{1,2}(?!\d)/'], ' ', $n);
    }

    $tld    = 'com|org|net|edu|gov|mil|us|info|biz|io|co|me|mx|uk|ca|es';
    $months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?'
        . '|enero|febrero|marzo|abril|mayo|junio|julio|agosto|sep?tiembre|octubre|noviembre|diciembre';
    $dot    = '(?:\s*(?:\.|\(dot\)|\[dot\]|\(\.\)|\[\.\]|\bdot\b|\bpunto\b)\s*)';

    $checks = [
        [$t, '/[^\s@<>()\[\]\\\\,;:]+@[^\s@<>()\[\]\\\\,;:]+\.[^\s@<>()\[\]\\\\,;:\d]{2,}/u'],                         // email (any script)
        [$t, '/[\p{L}\p{N}._%+-]+ ?@ ?[\p{L}\p{N}-]+(?: ?\. ?[\p{L}\p{N}-]+)* ?\. ?(?:' . $tld . ')(?![\p{L}\p{N}-])/u'],   // "jane @ example . com" (lowercase TLD)
        [$t, '/[^\s@]+@[\p{L}\p{N}-]+' . $dot . '(?:[\p{L}\p{N}-]+' . $dot . ')*(?-i:' . $tld . ')(?![\p{L}\p{N}-])/iu'],     // "jane@gmail dot com"
        [$t, '/[\p{L}\p{N}._%+-]+\s*(?:\(at\)|\[at\]|\bat\b|\barroba\b)\s*[\p{L}\p{N}-]+(?:' . $dot . '[\p{L}\p{N}-]+)*' . $dot . '(?-i:' . $tld . ')(?![\p{L}\p{N}-])/iu'], // "jane at example dot com"
        [$n, '/\+?\d[\d\s().\-\/_]{7,}\d/'],                                            // phone, long ID numbers
        [$n, '/(?<!\d)\d{3}[\s.\-\/_]\d{2}[\s.\-\/_]\d{4}(?!\d)/'],                     // SSN
    ];
    if ($strict) {
        $checks = array_merge($checks, [
            [$n, '/(?<!\d)\d{3}[\s.\-]\d{4}(?!\d)/'],                                    // 7-digit local phone (555-1234)
            [$t, '/(?<!\d)\d{1,2}[\/\-_]\d{1,2}[\/\-_](?:\d{4}|\d{2})(?!\d)/'],           // 3/14/1952, 3-14-52
            [$t, '/(?<![\d.])\d{1,2}\.\d{1,2}\.\d{4}(?![\d.])/'],                          // 14.03.1952
            [$t, '/(?<!\d)(?:1[89]|20)\d{2}[\/.\-_]\d{1,2}[\/.\-_]\d{1,2}(?!\d)/'],       // 1952-03-14
            [$t, '/\b(?:' . $months . ')\.?[\s\-_]+\d{1,2}(?:st|nd|rd|th)?,?[\s\-_]+(?:1[89]|20)\d{2}(?!\d)/iu'], // March 14, 1952 / march-14-1952
            [$t, '/(?<!\d)\d{1,2}(?:st|nd|rd|th)?[\s\-]*(?:of\s+|de\s+)?(?:' . $months . ')\.?,?[\s\-]*(?:de\s+|del\s+)?(?:1[89]|20)\d{2}(?!\d)/iu'], // 14 Mar 1952, 14-Mar-1952, 14MAR1952, 14 de marzo de 1952
        ]);
    }
    foreach ($checks as $c) {
        $hit = @preg_match($c[1], $c[0]);
        if ($hit !== 0) {
            return true; // a match, or the check could not run (fail closed)
        }
    }
    return false;
}

/**
 * Mask any run of 5 or more digits (ASCII or any other script), e.g. a
 * medical record number.
 */
function beacon_mask_digits(string $s): string
{
    $s = preg_replace('/\d{5,}/', '[redacted]', $s);
    $u = @preg_replace('/\p{Nd}{5,}/u', '[redacted]', $s);
    return is_string($u) ? $u : $s;
}

/**
 * The site's search base ("search" unless changed), used to strip search
 * terms from pretty-permalink search URLs like /search/jane+doe/.
 */
function beacon_search_base(): string
{
    global $wp_rewrite;
    $base = (is_object($wp_rewrite) && !empty($wp_rewrite->search_base)) ? (string) $wp_rewrite->search_base : 'search';
    return trim($base, '/');
}

/**
 * Path with the query string scrubbed. By default the whole query is removed
 * (that is where tokens, emails, and record numbers leak). Keys the admin has
 * explicitly allow-listed survive — e.g. keep "sl" so /?sl=breast stays a
 * distinct page — and their values are still digit-masked and clamped.
 */
function beacon_clean_path(string $raw_url): string
{
    // parse_url() gives up on some real paths (e.g. /job:12/), so fall back
    // to a plain split rather than recording them all as "/".
    $parts = beacon_split_url($raw_url);
    $path  = $parts['path'] !== '' ? $parts['path'] : '/';

    // Decode first so percent-encoded digits or text cannot slip past the
    // checks, drop invalid UTF-8, and fold look-alike characters.
    $path = wp_check_invalid_utf8(rawurldecode($path), true);
    $path = '/' . ltrim(beacon_fold_text(is_string($path) ? $path : ''), '/');

    // Search-result pages carry the visitor's search terms in the path, also
    // under a language prefix (/es/search/...). Keep everything up to the
    // search segment and drop the rest.
    $base = beacon_search_base();
    if ($base !== '' && preg_match('#^(.*?/)' . preg_quote($base, '#') . '(?:/|$)#i', $path, $m)) {
        $path = $m[1] . $base . '/';
    }

    // Numbers split across segments: /123/45/6789/ (SSN) or /713/555/1234/.
    // Also dates split the same way: /03/14/1952/ (WordPress archives put
    // the year first, /2025/03/14/, and are not touched).
    $path = (string) preg_replace(['#(?<![\d])\d{3}/\d{2,3}/\d{4}(?![\d])#', '#(?<![\d])\d{1,2}/\d{1,2}/(?:1[89]|20)\d{2}(?![\d])#'], '[redacted]', $path);

    // Replace any path segment that looks like personal data. Each segment
    // is checked fully decoded (a double-encoded "%2540" is an "@" too).
    $segments = explode('/', $path);
    foreach ($segments as $i => $seg) {
        if ($seg === '') {
            continue;
        }
        $check = $seg;
        for ($round = 0; $round < 3; $round++) {
            $dec = rawurldecode($check);
            if ($dec === $check) {
                break;
            }
            $check = $dec;
        }
        if (beacon_looks_personal($check)) {
            $segments[$i] = '[redacted]';
        }
    }
    $path = beacon_mask_digits(implode('/', $segments));

    // Re-encode everything outside a plain URL-path character set, so a
    // stored path never holds markup, quotes, or spaces.
    $path = preg_replace_callback('~[^A-Za-z0-9\-._\~/\[\]]~', function ($m) {
        return rawurlencode($m[0]);
    }, $path);

    $allowed = beacon_allowed_query_keys();
    if ($allowed) {
        $query = $parts['query'];
        if ($query !== '') {
            parse_str($query, $params);
            $keep = [];
            foreach ($allowed as $k) {
                if (!isset($params[$k]) || !is_string($params[$k]) || $params[$k] === '') {
                    continue;
                }
                // A value that is still percent-encoded after parsing (double
                // encoding) is refused outright: cleaning would delete the
                // "%40" and hide an "@".
                if (preg_match('/%[0-9A-Fa-f]{2}/', $params[$k])) {
                    continue;
                }
                // Clean first, then check the value exactly as it would be
                // stored (cleaning can join pieces into an email or SSN).
                $v = beacon_fold_text(sanitize_text_field($params[$k]));
                if ($v === '' || beacon_looks_personal($v) || beacon_looks_personal(beacon_fold_text($params[$k]))) {
                    continue;
                }
                // Same backstop as paths: mask MRN-shaped digit runs.
                $keep[$k] = mb_substr(beacon_mask_digits($v), 0, 60);
            }
            if ($keep) {
                ksort($keep);
                $path .= '?' . http_build_query($keep);
            }
        }
    }

    return substr($path, 0, 512);
}

/**
 * Split a URL (or a bare path) into path and query without trusting
 * parse_url(), which returns false for some valid paths.
 *
 * @return array{path:string,query:string}
 */
function beacon_split_url(string $url): array
{
    $url = (string) strtok($url, '#');
    $q   = '';
    $qpos = strpos($url, '?');
    if ($qpos !== false) {
        $q   = (string) substr($url, $qpos + 1);
        $url = (string) substr($url, 0, $qpos);
    }
    // Drop "scheme://host[:port]" or "//host" if present.
    $url = (string) preg_replace('#^(?:[a-z][a-z0-9+.\-]*:)?//[^/]*#i', '', $url);
    return ['path' => $url, 'query' => $q];
}

/**
 * Host only from a referrer, never the full URL (query strings leak there too).
 */
function beacon_referrer_host($referrer)
{
    if (!$referrer) {
        return null;
    }
    $host = parse_url($referrer, PHP_URL_HOST);
    if (!is_string($host)) {
        return null;
    }
    $host = strtolower($host);
    // A real hostname only: letters, digits, dots, hyphens.
    return preg_match('/^[a-z0-9][a-z0-9.-]{0,189}\z/', $host) ? $host : null;
}

/**
 * Today's secret hash key. A new random key is made each day (site time) and
 * yesterday's is overwritten, so it is gone for good. Never shown in any UI,
 * never sent to the browser.
 *
 * Two requests landing in the same instant at midnight may each create a
 * key; the last one written wins. At worst a visitor is counted twice for
 * that moment. That is the price of never keeping old keys.
 */
function beacon_day_salt(): string
{
    $today = wp_date('Y-m-d');
    $cur   = get_option(BEACON_DAY_SALT);
    if (is_array($cur) && isset($cur['d'], $cur['s']) && $cur['d'] === $today
        && is_string($cur['s']) && strlen($cur['s']) === 64) {
        return $cur['s'];
    }
    $new = ['d' => $today, 's' => bin2hex(random_bytes(32))];
    update_option(BEACON_DAY_SALT, $new, false);
    return $new['s'];
}

/**
 * Daily visitor hash. Keyed with today's salt, which is discarded tomorrow:
 * the same visitor gets an unrelated hash each day, and once the day's salt
 * is gone nobody (including us, or anyone with a database backup) can turn a
 * stored hash back into an IP or link two days. Counts unique visitors per
 * day without a durable identifier and without ever storing the IP.
 */
function beacon_visitor_day_hash(string $ip, string $ua): string
{
    return hash_hmac('sha256', beacon_ip_network($ip, 24, 48) . '|' . $ua, beacon_day_salt());
}

/**
 * An IP cut to its network: the first $v4_bits of an IPv4 address or the
 * first $v6_bits of an IPv6 one, as "address/bits". IPv4 addresses written
 * in IPv6 form (::ffff:1.2.3.4) are treated as IPv4. Anything that is not
 * an IP comes back unchanged.
 */
function beacon_ip_network(string $ip, int $v4_bits, int $v6_bits): string
{
    $bin = @inet_pton($ip);
    if (!is_string($bin)) {
        return $ip;
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        $bin = substr($bin, 12); // IPv4-mapped IPv6
    }
    $bits = strlen($bin) === 4 ? $v4_bits : $v6_bits;
    $out  = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        $keep = max(0, min(8, $bits - 8 * $i));
        $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
        $out .= chr(ord($bin[$i]) & $mask);
    }
    return (string) inet_ntop($out) . '/' . $bits;
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
    // First match wins, so order matters (Edge and Chrome UAs also say "safari").
    if (str_contains($ua, 'edg')) {
        return 'Edge';
    }
    if (str_contains($ua, 'chrome')) {
        return 'Chrome';
    }
    if (str_contains($ua, 'firefox')) {
        return 'Firefox';
    }
    if (str_contains($ua, 'safari')) {
        return 'Safari';
    }
    return 'Other';
}

function beacon_os_name(string $ua): string
{
    $ua = strtolower($ua);
    // First match wins, so order matters (iPhone UAs also say "mac os").
    if (str_contains($ua, 'windows')) {
        return 'Windows';
    }
    if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) {
        return 'iOS';
    }
    if (str_contains($ua, 'mac')) {
        return 'macOS';
    }
    if (str_contains($ua, 'android')) {
        return 'Android';
    }
    if (str_contains($ua, 'linux')) {
        return 'Linux';
    }
    return 'Other';
}

/**
 * Scrub an event label before storage. Labels are element text from the page
 * (button captions, blurb titles) or a survey answer. Even so: drop anything
 * email-, phone- or date-shaped outright (strict checks), mask long digit
 * runs, and clamp. The tracker already refuses to read labels from form
 * fields. Page titles use the looser checks (see beacon_scrub_title()).
 */
function beacon_scrub_label(string $label, int $max = 160, bool $strict = true)
{
    $label = beacon_strip_invisible($label);
    $ws    = @preg_replace('/\s+/u', ' ', $label);
    $label = trim(is_string($ws) ? $ws : (string) preg_replace('/\s+/', ' ', $label));
    if ($label === '' || beacon_looks_personal($label, $strict)) {
        return null;
    }
    $label = mb_substr(beacon_mask_digits($label), 0, $max);
    return $label === '' ? null : $label;
}

/**
 * Page title before storage. The tracker sends a server-chosen title (search
 * and 404 pages get a generic one), so titles are the site's own headlines:
 * emails, phone and SSN shapes are still dropped, but dates are allowed
 * ("Walk: October 12, 2025").
 */
function beacon_scrub_title(string $title)
{
    return beacon_scrub_label($title, 255, false);
}

/**
 * HIPAA Safe Harbor rules for survey answers, enforced on the server so they
 * hold no matter how the answer was captured (number field, dropdown, radio).
 * Labels arrive as "Question → Answer". Only questions about age, birth, or
 * dates are touched (English or Spanish, and field names like patient_age,
 * birthYear, dob_month, "Month *", "MM"):
 *
 *  - A year that implies age 90+ (a birth year, or any year 90+ years ago)
 *    becomes "90+".
 *  - An age of 90 or more, or a range starting at 90+, becomes "90+".
 *  - A month or day of a date (a month name, or a day number 1-31 that is
 *    not part of a year) is dropped entirely: returns null, store nothing.
 *    Only the year of a date is kept.
 *
 * @return string|null The label to store, or null to drop the answer.
 */
function beacon_cap_survey_age(string $label)
{
    $parts = explode(' → ', $label, 2);
    if (count($parts) !== 2) {
        return $label;
    }
    list($q, $a) = $parts;
    $qn = beacon_survey_question_words($q);
    $af = trim(beacon_fold_text($a));

    $months   = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?'
        . '|enero|febrero|marzo|abril|mayo|junio|julio|agosto|sep?tiembre|octubre|noviembre|diciembre';
    // A question about a birth date (not "births" or "birth weight").
    $birthish = (bool) preg_match('/\b(?:birth ?(?:day|date|month|year)|date of birth|day of birth|month of birth|year of birth|dob|born|cumplea[nñ]os|nacimiento|naci[oó]?|nacid[oa])\b/iu', $qn);
    // A question that is only a date part: "Month", "Day *", "MM", "Month / Mes", "Select year".
    $datewords = ['month', 'day', 'year', 'mes', 'dia', 'día', 'año', 'ano', 'mm', 'dd', 'yy', 'yyyy', 'aaaa'];
    $filler    = ['select', 'choose', 'enter', 'pick', 'your', 'the', 'a', 'an', 'patient', 'patients', 's', 'of', 'de', 'el', 'la', 'please', 'seleccione', 'elija', 'su', 'birth'];
    $w = (string) preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $qn);
    $w = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $w);
    $words = array_values(array_diff(preg_split('/\s+/u', trim($w), -1, PREG_SPLIT_NO_EMPTY) ?: [], $filler));
    $datepart = $words !== [] && array_diff($words, $datewords) === [];
    // Any other question about a date (date of surgery, month of diagnosis,
    // fecha, ...). Rates like "steps per day" or "times a month" are not dates.
    $qd       = (string) preg_replace('/\b(?:per|a|an|each|every|por|al|cada)\s+(?:day|d[ií]a|month|mes|year|a[nñ]o|week|semana)\b/iu', ' ', $qn);
    $dateish  = $birthish || $datepart || (bool) preg_match('/\b(?:date|dates|fecha|fechas|month|mes|day|d[ií]a|year|a[nñ]o|when|cu[aá]ndo)\b/iu', $qd);
    $ageish   = (bool) preg_match('/\b(?:age|ages|how old|years? old|edad|cu[aá]ntos a[nñ]os|a[nñ]os)\b/iu', $qn);

    if (!$dateish && !$ageish) {
        return $label; // not about age, birth or dates: leave it alone
    }

    // A year 90+ years ago becomes "90+"; a later year is kept.
    $has_year = (bool) preg_match('/(?<!\d)(1[89]\d{2}|20\d{2})(?!\d)/', $af, $m);
    if ($has_year && (int) wp_date('Y') - (int) $m[1] >= 90) {
        return $q . ' → 90+';
    }

    // The month or day of a date is dropped. Only a year or an age is kept.
    if ($dateish) {
        $no_year = (string) preg_replace('/(?<!\d)(?:1[89]|20)\d{2}(?!\d)/', ' ', $af);
        if (preg_match('/\b(?:' . $months . ')\b/iu', $af)
            || preg_match('/(?<!\d)(?:0?[1-9]|[12]\d|3[01])(?!\d)/', $no_year)) {
            return null;
        }
    }

    // An age of 90 or more, or a range starting at 90+.
    if (($ageish || $birthish) && !$has_year && preg_match('/\d+/', $af, $m) && (int) $m[0] >= 90) {
        return $q . ' → 90+';
    }
    return $label;
}

/**
 * A survey question as plain lowercase words: "patient_age", "birthYear" and
 * "dob-year" become "patient age", "birth year" and "dob year", so word
 * matching works on field names as well as on written questions.
 */
function beacon_survey_question_words(string $q): string
{
    $q = beacon_fold_text($q);
    $q = (string) preg_replace('/(\p{Ll})(\p{Lu})/u', '$1 $2', $q);
    $q = (string) preg_replace('/[_\-.\/]+/', ' ', $q);
    $q = (string) preg_replace('/\s+/u', ' ', $q);
    return function_exists('mb_strtolower') ? mb_strtolower(trim($q), 'UTF-8') : strtolower(trim($q));
}

/**
 * Did the visitor ask not to be tracked? Honors DNT and GPC headers.
 */
function beacon_do_not_track(): bool
{
    return ($_SERVER['HTTP_DNT'] ?? '') === '1'
        || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
}
