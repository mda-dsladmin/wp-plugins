<?php
declare(strict_types=1);

/**
 * The per-page checks: privacy, SEO, accessibility (static subset), content,
 * and policy rules. Pure functions — they take a parsed DOM and return
 * finding arrays; the engine (engine.php) handles HTTP, storage, and the
 * site-level checks.
 *
 * Guardrails (do not regress):
 *  - Store findings, never full pages. Snippets are scrubbed and clamped.
 *  - The static accessibility checks are a subset, not a full audit. The UI
 *    says so — "0 issues" here must never read as "fully accessible."
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Severity levels, worst first. Used for ordering everywhere. */
function beacon_severities(): array
{
    return ['critical', 'serious', 'moderate', 'minor'];
}

/**
 * Scrub a snippet of element HTML before storage. The one place page content
 * is kept, so: mask MRN-shaped digit runs, blank out value attributes (form
 * echoes), collapse whitespace, clamp hard.
 */
function beacon_scrub_snippet(string $html): string
{
    $html = preg_replace('/value\s*=\s*("[^"]*"|\'[^\']*\')/i', 'value="[scrubbed]"', $html);
    $html = preg_replace('/\d{5,}/', '[redacted]', $html);
    $html = preg_replace('/\s+/', ' ', $html);
    return mb_substr(trim($html), 0, 200);
}

/** A short human selector for one element: tag#id or tag.first-class. */
function beacon_node_selector(DOMElement $el): string
{
    $sel = strtolower($el->tagName);
    if ($el->hasAttribute('id')) {
        $sel .= '#' . $el->getAttribute('id');
    } elseif ($el->hasAttribute('class')) {
        $classes = preg_split('/\s+/', trim($el->getAttribute('class')));
        if (!empty($classes[0])) {
            $sel .= '.' . $classes[0];
        }
    }
    return mb_substr($sel, 0, 255);
}

/** Outer HTML of a node, for scrubbed snippets. */
function beacon_outer_html(DOMElement $el): string
{
    return (string) $el->ownerDocument->saveHTML($el);
}

/** One finding row (category/check/severity/message/selector/snippet). */
function beacon_finding(string $cat, string $check, string $sev, string $msg, $el = null): array
{
    return [
        'category' => $cat,
        'check_id' => $check,
        'severity' => $sev,
        'message'  => mb_substr($msg, 0, 255),
        'selector' => $el ? beacon_node_selector($el) : null,
        'snippet'  => $el ? beacon_scrub_snippet(beacon_outer_html($el)) : null,
    ];
}

/** Host of a URL, lowercase, or null for relative/non-http URLs. */
function beacon_url_host(string $url)
{
    if (preg_match('#^//#', $url)) {
        $url = 'https:' . $url; // protocol-relative
    }
    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : null;
}

/**
 * Is this host first-party? The site's own host(s) match exactly; a domain
 * the admin listed also covers its subdomains (listed as ".example.org" by
 * beacon_scan_allowed_hosts()). A missing host is NOT first-party: links are
 * made absolute before this is called, so no host means a malformed URL.
 */
function beacon_host_allowed($host, array $allowed): bool
{
    if (!is_string($host) || $host === '') {
        return false;
    }
    foreach ($allowed as $a) {
        if ($a !== '' && $a[0] === '.') {
            if ($host === substr($a, 1) || str_ends_with($host, $a)) {
                return true;
            }
        } elseif ($host === $a) {
            return true;
        }
    }
    return false;
}

/** The port a URL talks to (explicit, else the scheme's default). */
function beacon_url_port(string $url): int
{
    $port = parse_url($url, PHP_URL_PORT);
    if ($port) {
        return (int) $port;
    }
    return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443;
}

/**
 * Is this a public internet address? Works on the raw bytes, so every way
 * of writing an address (dotted, hex, IPv4 inside IPv6) is judged the same.
 *
 * IPv4: refuses 0/8, 10/8, 100.64/10 (carrier NAT), 127/8, 169.254/16
 * (link-local, cloud metadata), 172.16/12, 192.0.0/24, 192.0.2/24,
 * 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4 and 240/4.
 * IPv6: only global unicast (2000::/3) is allowed, minus the ranges that
 * tunnel to IPv4 or are reserved (64:ff9b::/96 and 64:ff9b:1::/48 NAT64,
 * 2001::/32 Teredo, 2001:db8::/32 docs, 2002::/16 6to4). IPv4-mapped and
 * IPv4-compatible IPv6 addresses are judged as the IPv4 address inside.
 */
function beacon_scan_ip_public(string $ip): bool
{
    $b = @inet_pton(trim($ip, '[]'));
    if (!is_string($b)) {
        return false;
    }
    if (strlen($b) === 16) {
        $zero10 = str_repeat("\0", 10);
        if (substr($b, 0, 12) === $zero10 . "\xff\xff" || substr($b, 0, 12) === $zero10 . "\0\0") {
            $b = substr($b, 12); // IPv4 written as IPv6
        } else {
            $first = ord($b[0]);
            if (($first & 0xE0) !== 0x20) {
                return false; // not global unicast 2000::/3
            }
            $w0 = (ord($b[0]) << 8) | ord($b[1]);
            $w1 = (ord($b[2]) << 8) | ord($b[3]);
            if ($w0 === 0x2002                               // 6to4
                || ($w0 === 0x2001 && $w1 === 0x0000)        // Teredo
                || ($w0 === 0x2001 && $w1 === 0x0db8)) {     // documentation
                return false;
            }
            return true;
        }
    }
    if (strlen($b) !== 4) {
        return false;
    }
    $n = (ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3]);
    $blocked = [
        [0x00000000, 8], [0x0A000000, 8], [0x64400000, 10], [0x7F000000, 8],
        [0xA9FE0000, 16], [0xAC100000, 12], [0xC0000000, 24], [0xC0000200, 24],
        [0xC0A80000, 16], [0xC6120000, 15], [0xC6336400, 24], [0xCB007100, 24],
        [0xE0000000, 4], [0xF0000000, 4],
    ];
    foreach ($blocked as $net) {
        $mask = (0xFFFFFFFF << (32 - $net[1])) & 0xFFFFFFFF;
        if (($n & $mask) === $net[0]) {
            return false;
        }
    }
    return true;
}

/**
 * Every address a host resolves to (IPv4 and IPv6), or the host itself when
 * it is already an IP. Empty when it does not resolve.
 *
 * @return string[]
 */
function beacon_scan_resolve(string $host): array
{
    if (@inet_pton($host) !== false) {
        return [$host];
    }
    $ips = [];
    $v4  = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    if (function_exists('dns_get_record')) {
        $v6 = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($v6) ? $v6 : [] as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
    }
    return array_values(array_unique($ips));
}

/**
 * The ports this site itself answers on: 80, 443 and the home URL's own
 * port (sites often redirect http to https, or link the other scheme).
 *
 * @return int[]
 */
function beacon_scan_home_ports(): array
{
    return array_values(array_unique([80, 443, beacon_url_port(home_url('/'))]));
}

/** Is this URL this site's own host (with or without www) on one of its ports? */
function beacon_scan_is_home(string $url): bool
{
    $host = rtrim(strtolower((string) parse_url($url, PHP_URL_HOST)), '.');
    $home = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    $bare = (string) preg_replace('/^www\./', '', $home);
    return $host !== ''
        && in_array($host, [$home, $bare, 'www.' . $bare], true)
        && in_array(beacon_url_port($url), beacon_scan_home_ports(), true);
}

/**
 * Is this URL first-party for the scan? This site's own host on one of its
 * ports (80, 443, or the home URL's port), or an admin-listed domain (and
 * its subdomains) on port 80 or 443. The site's host on some other port
 * (e.g. :6379) is NOT first-party.
 */
function beacon_scan_is_first_party(string $url, array $allowed): bool
{
    $host = beacon_url_host($url);
    if ($host === null || !beacon_host_allowed($host, $allowed)) {
        return false;
    }
    $site_hosts = array_filter($allowed, function ($a) {
        return $a !== '' && $a[0] !== '.';
    });
    $port = beacon_url_port($url);
    return in_array($host, $site_hosts, true)
        ? in_array($port, beacon_scan_home_ports(), true)
        : in_array($port, [80, 443], true);
}

/**
 * Check a URL before the scanner requests it. Only http(s); it must pass
 * WordPress's own wp_http_validate_url() (ports, credentials, obvious
 * private ranges) AND every address the host resolves to must be public.
 * Older WordPress versions do not block link-local/cloud-metadata addresses
 * themselves, so this does not rely on the WordPress version. The site's
 * own host on its own port is always allowed (Local/dev sites resolve to
 * 127.0.0.1 and must still be able to scan themselves).
 *
 * @return array{ok:bool,home:bool,ips:string[]} ips = the checked addresses
 *         the request must be pinned to.
 */
function beacon_scan_check_url(string $url): array
{
    $no = ['ok' => false, 'home' => false, 'ips' => []];
    if (!preg_match('#^https?://#i', $url) || !wp_http_validate_url($url)) {
        return $no;
    }
    $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
    if ($host === '' || substr($host, -1) === '.') {
        return $no; // "example.com." is refused: old curl versions ignore the pin for it
    }
    if (beacon_scan_is_home($url)) {
        return ['ok' => true, 'home' => true, 'ips' => []];
    }
    $ips = beacon_scan_resolve($host);
    if (!$ips) {
        return $no; // unresolvable: nothing to check, so don't request it
    }
    foreach ($ips as $ip) {
        if (!beacon_scan_ip_public($ip)) {
            return $no;
        }
    }
    return ['ok' => true, 'home' => false, 'ips' => $ips];
}

/** May the scanner request this URL? See beacon_scan_check_url(). */
function beacon_scan_url_safe(string $url): bool
{
    return beacon_scan_check_url($url)['ok'];
}

/**
 * Can requests be pinned to the address that was checked? That needs the
 * curl transport (CURLOPT_RESOLVE). Without it, the scanner does not
 * request outside hosts at all.
 */
function beacon_scan_can_pin(): bool
{
    return function_exists('curl_init') && function_exists('curl_exec') && defined('CURLOPT_RESOLVE')
        && apply_filters('use_curl_transport', true, []);
}

/**
 * The scanner's only way to make a request.
 *
 *  - Redirects are followed by hand (up to 3) and every hop is checked with
 *    beacon_scan_check_url() before it is requested, so a redirect cannot
 *    lead to an internal address.
 *  - For any host other than this site, the connection is pinned to the
 *    address that was just checked (curl CURLOPT_RESOLVE), so a DNS answer
 *    that changes between the check and the connection ("DNS rebinding")
 *    cannot send it somewhere else.
 *  - When $first_party is given (the scan's allowed hosts), every hop must
 *    stay first-party; used for page fetches and when outside-link checks
 *    are off.
 *  - Responses are capped at 5 MB unless the caller sets a smaller cap.
 *
 * @param array|null $first_party Allowed hosts, or null for any public host.
 * @return array|WP_Error Same shape as wp_remote_request().
 */
function beacon_scan_request(string $method, string $url, array $args, $first_party = null)
{
    $args['redirection'] = 0;
    if (empty($args['limit_response_size'])) {
        $args['limit_response_size'] = 5 * 1024 * 1024;
    }
    for ($hop = 0; $hop <= 3; $hop++) {
        if (is_array($first_party) && !beacon_scan_is_first_party($url, $first_party)) {
            return new WP_Error('beacon_offsite', 'Blocked: leaves this site');
        }
        $check = beacon_scan_check_url($url);
        if (!$check['ok']) {
            return new WP_Error('beacon_unsafe_url', 'Blocked: not a public address');
        }
        $pin = null;
        if (!$check['home']) {
            if (!beacon_scan_can_pin()) {
                return new WP_Error('beacon_no_pin', 'Blocked: outside hosts need the curl transport');
            }
            $ip = $check['ips'][0];
            foreach ($check['ips'] as $cand) {
                if (strpos($cand, ':') === false) {
                    $ip = $cand; // prefer IPv4
                    break;
                }
            }
            $pin_host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            $pin_line = $pin_host . ':' . beacon_url_port($url) . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
            // Attached for this one request only (redirects are off, so there
            // is exactly one curl handle), so it applies to every call it sees.
            // No URL comparison: WordPress may rewrite the URL (e.g. lowercase
            // the scheme) before curl runs.
            $pin = function ($handle) use ($pin_line) {
                curl_setopt($handle, CURLOPT_RESOLVE, [$pin_line]);
            };
            add_action('http_api_curl', $pin, 10, 1);
        }
        try {
            $resp = $method === 'HEAD' ? wp_safe_remote_head($url, $args) : wp_safe_remote_get($url, $args);
        } finally {
            if ($pin) {
                remove_action('http_api_curl', $pin, 10);
            }
        }
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $loc  = wp_remote_retrieve_header($resp, 'location');
        if (is_array($loc)) {
            $loc = (string) reset($loc);
        }
        if ($code >= 300 && $code < 400 && is_string($loc) && $loc !== '') {
            $url = WP_Http::make_absolute_url($loc, $url);
            continue;
        }
        return $resp;
    }
    return new WP_Error('beacon_too_many_redirects', 'Too many redirects');
}

/* =========================================================================
 * PRIVACY — the leak detector. Flags every element that makes the browser
 * call a domain outside the allow-list, and known trackers in inline JS.
 * This is the check that proves no page leaks to a third party.
 * ========================================================================= */

function beacon_check_privacy(DOMDocument $doc, array $allowed): array
{
    $out = [];

    // Element/attribute pairs that trigger a network request, with severity.
    // Scripts, iframes, and external form posts can carry data out = critical.
    $targets = [
        ['script', 'src', 'critical'],
        ['iframe', 'src', 'critical'],
        ['form', 'action', 'critical'],
        ['img', 'src', 'serious'],
        ['link', 'href', 'serious'],
        ['source', 'src', 'serious'],
        ['video', 'src', 'serious'],
        ['audio', 'src', 'serious'],
        ['embed', 'src', 'critical'],
        ['object', 'data', 'critical'],
    ];

    foreach ($targets as list($tag, $attr, $sev)) {
        foreach ($doc->getElementsByTagName($tag) as $el) {
            /** @var DOMElement $el */
            $url  = trim($el->getAttribute($attr));
            $host = beacon_url_host($url);
            if ($host !== null && !beacon_host_allowed($host, $allowed)) {
                // Only request-making <link> rels matter (stylesheet, fonts...).
                if ($tag === 'link') {
                    $rel = strtolower($el->getAttribute('rel'));
                    if (!preg_match('/stylesheet|preload|prefetch|preconnect|dns-prefetch|icon/', $rel)) {
                        continue;
                    }
                }
                $msg = $tag === 'form'
                    ? sprintf('Form posts to third-party host %s', $host)
                    : sprintf('Third-party %s loads from %s', $tag, $host);
                $out[] = beacon_finding('privacy', 'third_party_' . $tag, $sev, $msg, $el);
            }
        }
    }

    // Known tracker signatures inside inline scripts (a tag manager snippet,
    // a pasted pixel). A static scan can't see JS-injected requests, but the
    // loader snippet itself is almost always right here in the HTML.
    $trackers = [
        'googletagmanager.com', 'google-analytics.com', 'connect.facebook.net',
        'doubleclick.net', 'hotjar.com', 'clarity.ms', 'tiktok.com/i18n',
        'snap.licdn.com', 'ads.linkedin.com', 'pinimg.com', 'segment.com',
        'mixpanel.com', 'fullstory.com', 'quantserve.com', 'scorecardresearch.com',
    ];
    foreach ($doc->getElementsByTagName('script') as $el) {
        /** @var DOMElement $el */
        if ($el->hasAttribute('src')) {
            continue; // already covered above
        }
        $js = $el->textContent;
        foreach ($trackers as $t) {
            if (stripos($js, $t) !== false) {
                $out[] = beacon_finding(
                    'privacy',
                    'inline_tracker',
                    'critical',
                    sprintf('Inline script references tracker domain %s', $t),
                    $el
                );
                break; // one finding per script block
            }
        }
    }

    return $out;
}

/* =========================================================================
 * SEO — static checklist off the same parse.
 * ========================================================================= */

function beacon_check_seo(DOMDocument $doc, string $body_text): array
{
    $out = [];
    $xp  = new DOMXPath($doc);

    $title = '';
    $t = $doc->getElementsByTagName('title');
    if ($t->length) {
        $title = trim($t->item(0)->textContent);
    }
    if ($title === '') {
        $out[] = beacon_finding('seo', 'title_missing', 'serious', 'Page has no <title>');
    } elseif (mb_strlen($title) > 60) {
        $out[] = beacon_finding('seo', 'title_long', 'minor', sprintf('Title is %d characters (aim for 60 or fewer)', mb_strlen($title)));
    } elseif (mb_strlen($title) < 10) {
        $out[] = beacon_finding('seo', 'title_short', 'minor', 'Title is under 10 characters');
    }

    $desc = $xp->query('//meta[@name="description"]/@content');
    if (!$desc->length || trim($desc->item(0)->nodeValue) === '') {
        $out[] = beacon_finding('seo', 'meta_desc_missing', 'moderate', 'Page has no meta description');
    } else {
        $len = mb_strlen(trim($desc->item(0)->nodeValue));
        if ($len > 160 || $len < 50) {
            $out[] = beacon_finding('seo', 'meta_desc_length', 'minor', sprintf('Meta description is %d characters (aim for 50–160)', $len));
        }
    }

    $h1s = $doc->getElementsByTagName('h1')->length;
    if ($h1s === 0) {
        $out[] = beacon_finding('seo', 'h1_missing', 'serious', 'Page has no <h1>');
    } elseif ($h1s > 1) {
        $out[] = beacon_finding('seo', 'h1_multiple', 'moderate', sprintf('Page has %d <h1> headings (should be one)', $h1s));
    }

    if (!$xp->query('//link[@rel="canonical"]')->length) {
        $out[] = beacon_finding('seo', 'canonical_missing', 'minor', 'Page has no canonical link tag');
    }

    $words = str_word_count(strip_tags($body_text));
    if ($words > 0 && $words < 150) {
        $out[] = beacon_finding('seo', 'thin_content', 'moderate', sprintf('Thin content: only %d words on the page', $words));
    }

    return $out;
}

/* =========================================================================
 * ACCESSIBILITY — the static subset. Real failures, honestly labeled as a
 * subset (no rendered-page checks like contrast or focus behavior).
 * ========================================================================= */

function beacon_check_a11y(DOMDocument $doc): array
{
    $out = [];
    $xp  = new DOMXPath($doc);
    $cap = static function (array $a): array { return array_slice($a, 0, 10); }; // per-check page cap

    $html = $doc->getElementsByTagName('html');
    if ($html->length && trim($html->item(0)->getAttribute('lang')) === '') {
        $out[] = beacon_finding('accessibility', 'html_lang', 'serious', 'The <html> element has no lang attribute');
    }

    // Images with no alt attribute at all (alt="" is a valid decorative mark).
    $found = [];
    foreach ($doc->getElementsByTagName('img') as $el) {
        if (!$el->hasAttribute('alt')) {
            $found[] = beacon_finding('accessibility', 'img_no_alt', 'serious', 'Image has no alt attribute', $el);
        }
    }
    $out = array_merge($out, $cap($found));

    // Form fields without an accessible name. Label targets are collected in
    // PHP and compared with === (XPath string literals can't safely embed
    // arbitrary ids — quotes in an id would break the query).
    $label_for = [];
    foreach ($doc->getElementsByTagName('label') as $l) {
        $for = $l->getAttribute('for');
        if ($for !== '') {
            $label_for[$for] = true;
        }
    }
    $found = [];
    foreach ($xp->query('//input|//select|//textarea') as $el) {
        /** @var DOMElement $el */
        $type = strtolower($el->getAttribute('type'));
        if (in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
            continue;
        }
        $id      = $el->getAttribute('id');
        $labeled = ($id !== '' && isset($label_for[$id]))
            || trim($el->getAttribute('aria-label')) !== ''
            || trim($el->getAttribute('aria-labelledby')) !== ''
            || trim($el->getAttribute('title')) !== ''
            || $xp->query('ancestor::label', $el)->length;
        if (!$labeled) {
            $found[] = beacon_finding('accessibility', 'field_no_label', 'serious', 'Form field has no label', $el);
        }
    }
    $out = array_merge($out, $cap($found));

    // Links and buttons with no accessible name.
    foreach ([['a', 'link_no_name', 'Link has no accessible text'], ['button', 'button_no_name', 'Button has no accessible text']] as list($tag, $check, $msg)) {
        $found = [];
        foreach ($doc->getElementsByTagName($tag) as $el) {
            /** @var DOMElement $el */
            if ($tag === 'a' && !$el->hasAttribute('href')) {
                continue;
            }
            $named = trim($el->textContent) !== ''
                || trim($el->getAttribute('aria-label')) !== ''
                || trim($el->getAttribute('aria-labelledby')) !== ''
                || trim($el->getAttribute('title')) !== '';
            if (!$named) {
                // an image with alt inside also names it
                foreach ($el->getElementsByTagName('img') as $img) {
                    if (trim($img->getAttribute('alt')) !== '') {
                        $named = true;
                        break;
                    }
                }
            }
            if (!$named) {
                $found[] = beacon_finding('accessibility', $check, 'serious', $msg, $el);
            }
        }
        $out = array_merge($out, $cap($found));
    }

    // Iframes without a title.
    $found = [];
    foreach ($doc->getElementsByTagName('iframe') as $el) {
        if (trim($el->getAttribute('title')) === '') {
            $found[] = beacon_finding('accessibility', 'iframe_no_title', 'moderate', 'Iframe has no title attribute', $el);
        }
    }
    $out = array_merge($out, $cap($found));

    // Duplicate ids (breaks label/aria references).
    $ids = [];
    foreach ($xp->query('//*[@id]') as $el) {
        $ids[] = $el->getAttribute('id');
    }
    $dupes = array_filter(array_count_values($ids), static function ($n) { return $n > 1; });
    if ($dupes) {
        $out[] = beacon_finding(
            'accessibility',
            'duplicate_ids',
            'moderate',
            sprintf('%d duplicated id value(s): %s', count($dupes), mb_substr(implode(', ', array_keys($dupes)), 0, 120))
        );
    }

    // Heading order: a level must not jump by more than one (h2 -> h4).
    $last = 0;
    foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $el) {
        $level = (int) substr($el->tagName, 1);
        if ($last > 0 && $level > $last + 1) {
            $out[] = beacon_finding(
                'accessibility',
                'heading_skip',
                'moderate',
                sprintf('Heading level skips from h%d to h%d', $last, $level),
                $el instanceof DOMElement ? $el : null
            );
            break; // one per page is enough to act on
        }
        $last = $level;
    }

    return $out;
}

/* =========================================================================
 * CONTENT QUALITY — reading level off the same text. Stale pages and
 * duplicate titles are site-level and live in the engine.
 * ========================================================================= */

function beacon_check_content(string $body_text): array
{
    $out  = [];
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($body_text)));
    $words = max(1, str_word_count($text));
    if ($words < 100) {
        return $out; // too little text to grade fairly
    }

    $sentences = max(1, preg_match_all('/[.!?]+(\s|$)/', $text));
    $syllables = 0;
    foreach (preg_split('/\s+/', strtolower($text)) as $w) {
        $w = preg_replace('/[^a-z]/', '', $w);
        if ($w === '') {
            continue;
        }
        // Rough syllable estimate: vowel groups, silent trailing e.
        $s = preg_match_all('/[aeiouy]+/', $w);
        if (str_ends_with($w, 'e') && $s > 1) {
            $s--;
        }
        $syllables += max(1, $s);
    }

    // Flesch-Kincaid grade level.
    $grade = 0.39 * ($words / $sentences) + 11.8 * ($syllables / $words) - 15.59;
    if ($grade >= 14) {
        $out[] = beacon_finding(
            'content',
            'hard_to_read',
            'minor',
            sprintf('Hard to read: Flesch-Kincaid grade %.0f (aim under 12 for public pages)', $grade)
        );
    }

    return $out;
}

/* =========================================================================
 * POLICY — the admin's own word/pattern rules. First-party by design: a rule
 * is a pattern, a severity, and a message. Never a URL, never a script.
 * ========================================================================= */

/**
 * Parse the policy rules textarea. One rule per line:
 *   pattern | severity | message
 * A /wrapped/ pattern is a regex; anything else is a case-insensitive word
 * match. Lines containing "http" or "<" are rejected (same guardrail as tags).
 */
function beacon_parse_policy_rules(string $text): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'http') !== false || str_contains($line, '<')) {
            continue;
        }
        // Split from the RIGHT: the last two fields are severity and message,
        // so a regex pattern may itself contain | (alternation).
        if (!preg_match('/^(.+)\|([^|]+)\|([^|]+)$/', $line, $m)) {
            continue;
        }
        $pattern = trim($m[1]);
        $sev     = trim($m[2]);
        $msg     = trim($m[3]);
        if ($pattern === '' || $msg === '' || !in_array($sev, beacon_severities(), true)) {
            continue;
        }
        if (preg_match('#^/.+/[a-z]*$#', $pattern)) {
            if (@preg_match($pattern, '') === false) {
                continue; // invalid regex, skip the rule
            }
            $regex = $pattern;
        } else {
            $regex = '/\b' . preg_quote($pattern, '/') . '\b/i';
        }
        $out[] = ['regex' => $regex, 'severity' => $sev, 'message' => mb_substr($msg, 0, 255)];
    }
    return array_slice($out, 0, 100);
}

function beacon_check_policy(string $body_text, array $rules): array
{
    $out  = [];
    $text = strip_tags($body_text);
    foreach ($rules as $i => $r) {
        $hit = @preg_match($r['regex'], $text);
        if ($hit === false) {
            // The rule could not run (backtracking limit, bad UTF-8, ...).
            // Say so instead of quietly reporting "no violation".
            $out[] = beacon_finding('policy', 'policy_rule_error_' . $i, 'minor',
                mb_substr('Policy rule could not be checked on this page (rule ' . ($i + 1) . '): ' . $r['message'], 0, 255));
        } elseif ($hit) {
            $out[] = beacon_finding('policy', 'policy_rule_' . $i, $r['severity'], $r['message']);
        }
    }
    return $out;
}

/* =========================================================================
 * Link extraction for the engine's broken-link pass.
 * ========================================================================= */

/**
 * Every unique http(s) link target on the page, absolutized against the PAGE
 * URL (not the site root) so relative and ../ links resolve correctly.
 *
 * @return string[] absolute URLs
 */
function beacon_extract_links(DOMDocument $doc, string $page_url): array
{
    $out = [];
    foreach ($doc->getElementsByTagName('a') as $el) {
        /** @var DOMElement $el */
        $href = trim($el->getAttribute('href'));
        if ($href === '' || $href[0] === '#'
            || preg_match('/^(mailto|tel|javascript|data):/i', $href)) {
            continue;
        }
        if (preg_match('#^//#', $href)) {
            $href = 'https:' . $href;
        } elseif (!preg_match('#^https?://#i', $href)) {
            if (class_exists('WP_Http')) {
                $href = WP_Http::make_absolute_url($href, $page_url);
            } else {
                $href = rtrim($page_url, '/') . '/' . ltrim($href, '/'); // test fallback
            }
        }
        $href = preg_replace('/#.*$/', '', $href);
        if ($href !== '' && !isset($out[$href])) {
            $out[$href] = true;
        }
    }
    return array_slice(array_keys($out), 0, 150); // per-page cap
}
