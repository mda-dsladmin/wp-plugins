<?php
declare(strict_types=1);

/**
 * The first-party Tag Manager parser.
 *
 * Turns the plain-text Tags box into a clean list of rules for tracker.js.
 * Format is one rule per line:  trigger | value | event_name
 *
 *   click    | .cta      | cta_click        fire when .cta is clicked
 *   hover    | .cta      | cta_hover        fire after 300ms hovering .cta
 *   submit   | form#appt | appt_submit      fire when that form submits
 *   scroll   | 50        | scroll_50        fire at 50% scroll depth
 *   timer    | 30        | engaged_30s      fire after 30 seconds
 *   pageview | /research | viewed_research  fire on paths under /research
 *
 * THE GUARDRAIL: a rule can express exactly one action — "fire this named
 * event to our own collector." There is deliberately no field for a URL, an
 * endpoint, or a script, and any line containing "http" or "<" is thrown
 * away. An admin physically cannot use this box to load a Google/Meta pixel
 * or pasted JavaScript. That is the one thing that makes GTM leak data, and
 * it is impossible here. Do NOT add a "custom HTML" rule type.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<int,array> Clean rules, ready for the tracker as JSON.
 */
/**
 * Parse the number-field overrides textarea. One rule per line:
 *   #input-id | width
 * The field is the number input's id, written with the leading # (like a
 * CSS id selector); width is a bucket size (1-100) or "none" for the exact
 * value. Same guardrail as tags: lines with "http" or "<" are discarded.
 *
 * @return array<int,array{match:string,width:int}> width 0 = exact value.
 */
function beacon_parse_number_overrides(string $text): array
{
    // Normalize invisible characters first: non-breaking spaces, zero-width
    // characters, and curly quotes ride along with copy-paste and would
    // otherwise silently kill every rule.
    $text = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{FEFF}\x{2018}\x{2019}\x{201C}\x{201D}]/u', ' ', $text);

    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'http') !== false || str_contains($line, '<')) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 2) {
            continue;
        }
        [$field, $width_raw] = $parts;
        // The leading # is the documented format, but be forgiving: accept
        // the bare id too rather than silently dropping the rule.
        if (str_starts_with($field, '#')) {
            $field = substr($field, 1);
        }
        $field = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $field));
        if ($field === '' || mb_strlen($field) > 60) {
            continue;
        }
        $width_raw = strtolower($width_raw);
        if ($width_raw === 'none' || $width_raw === 'exact' || $width_raw === '0') {
            $width = 0;
        } else {
            $width = (int) $width_raw;
            if ($width < 1 || $width > 100) {
                continue;
            }
        }
        $out[] = ['match' => $field, 'width' => $width];
    }
    return array_slice($out, 0, 30);
}

function beacon_parse_tags(string $text): array
{
    $allowed = ['click', 'hover', 'submit', 'scroll', 'timer', 'pageview', 'iframeclick'];
    $out = [];

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);

        // Guardrail: skip blanks, reject anything with a URL or markup.
        if ($line === '' || stripos($line, 'http') !== false || str_contains($line, '<')) {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            continue; // not a complete rule
        }
        [$trigger, $val, $event] = $parts;

        $trigger = strtolower($trigger);
        // Event name is used as a key: letters, numbers, underscore only.
        $event = preg_replace('/[^a-zA-Z0-9_]/', '', $event);

        if (!in_array($trigger, $allowed, true) || $event === '') {
            continue;
        }

        $rule = ['trigger' => $trigger, 'event' => substr($event, 0, 120)];
        if ($trigger === 'click' || $trigger === 'hover' || $trigger === 'submit' || $trigger === 'iframeclick') {
            $rule['selector'] = substr($val, 0, 255);        // CSS selector
        } elseif ($trigger === 'pageview') {
            $rule['path'] = substr($val, 0, 255);            // path prefix
        } elseif ($trigger === 'scroll') {
            $rule['depth'] = max(1, min(100, (int) $val));   // 1..100 percent
        } else { // timer
            $rule['seconds'] = max(1, min(3600, (int) $val)); // 1..3600 s
        }
        $out[] = $rule;
    }

    return $out;
}
