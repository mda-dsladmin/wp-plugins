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
function beacon_finding(string $cat, string $check, string $sev, string $msg, ?DOMElement $el = null): array
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
function beacon_url_host(string $url): ?string
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

/** Is this host first-party — the site itself or an allow-listed domain? */
function beacon_host_allowed(?string $host, array $allowed): bool
{
    if ($host === null) {
        return true; // relative URL = same site
    }
    foreach ($allowed as $a) {
        if ($host === $a || str_ends_with($host, '.' . $a)) {
            return true;
        }
    }
    return false;
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

    foreach ($targets as [$tag, $attr, $sev]) {
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
    $cap = static fn (array $a): array => array_slice($a, 0, 10); // per-check page cap

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
    foreach ([['a', 'link_no_name', 'Link has no accessible text'], ['button', 'button_no_name', 'Button has no accessible text']] as [$tag, $check, $msg]) {
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
    $dupes = array_filter(array_count_values($ids), static fn ($n) => $n > 1);
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
        if (@preg_match($r['regex'], $text)) {
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
