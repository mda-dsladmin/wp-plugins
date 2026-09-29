<?php
declare(strict_types=1);

/**
 * Front-end output: print the tracking snippet just before </body>.
 * This is the only thing the plugin adds to a visitor-facing page.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optional accessibility patch: images missing an alt attribute get one
 * copied from their title attribute at render time. A stopgap, not a cure —
 * the real fix is writing alt text in the media library, and the Site Scan
 * keeps flagging images that have neither. Runs on every <img> WordPress
 * renders through content (WP 6.0+ wp_content_img_tag filter).
 */
add_filter('wp_content_img_tag', function ($img) {
    if (empty(beacon_settings()['fix_missing_alt']) || !is_string($img)) {
        return $img;
    }
    if (preg_match('/\salt\s*=/i', $img)) {
        return $img; // already has an alt (even empty = intentional decorative)
    }
    if (preg_match('/\stitle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $img, $m)) {
        $title = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        if ($title !== '') {
            return preg_replace('/^<img/i', '<img alt="' . esc_attr($title) . '"', $img, 1);
        }
    }
    return $img; // no title to borrow: leave it, so the scan still flags it
});

/**
 * Optional accessibility patch: pages with no <h1> get a visually-hidden
 * one carrying the page title, so screen-reader users always land on a
 * top-level heading. Runs whether or not tracking is enabled — it is an
 * accessibility fix, not analytics. A real visible h1 is still the proper
 * fix; the Site Scan keeps reporting the finding either way as a reminder.
 */
add_action('wp_footer', function () {
    if (empty(beacon_settings()['fix_missing_h1'])) {
        return;
    }
    $title = is_singular() ? get_the_title() : wp_get_document_title();
    // Both come back HTML-escaped ("Mom&#8217;s &amp; Dad&#8217;s"); the
    // heading is set as plain text, so decode first or screen readers would
    // read the entity codes out loud.
    $title = is_string($title) ? html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES, 'UTF-8') : '';
    if (trim($title) === '') {
        return;
    }
    ?>
    <script>
    (function () {
      if (document.querySelector('h1')) return;
      var h = document.createElement('h1');
      h.textContent = <?php echo wp_json_encode(trim($title), JSON_HEX_TAG | JSON_HEX_AMP); ?>;
      // The standard visually-hidden clip pattern: announced, not displayed.
      h.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;';
      var main = document.querySelector('main') || document.body;
      main.insertBefore(h, main.firstChild);
    })();
    </script>
    <?php
}, 5);

add_action('wp_footer', function () {
    $o = beacon_settings();

    if (empty($o['enabled']) || $o['site_key'] === '') {
        return;
    }
    // Don't count the site's own team while they work (on by default).
    if (!empty($o['exclude_logged_in']) && is_user_logged_in()) {
        return;
    }

    $endpoint = beacon_collect_url();

    // Sending to a separate collector? That install has its own site key —
    // use it, or the remote end would reject every event.
    $site_key = $o['site_key'];
    if ($o['endpoint'] !== '' && preg_match('/^[a-f0-9]{32}$/', (string) $o['external_key'])) {
        $site_key = $o['external_key'];
    }

    // (a) Inline first-party tag rules, printed before the tracker so it finds
    // them as window.BeaconTags on load. wp_json_encode() safely embeds them.
    $rules = beacon_parse_tags($o['tags']);
    if ($rules) {
        printf("<script>window.BeaconTags=%s;</script>\n", wp_json_encode($rules, JSON_HEX_TAG | JSON_HEX_AMP));
    }

    // Survey capture selector (opt-in). When present, tracker.js records
    // question + chosen answer as the survey_response label.
    if ($o['survey_selector'] !== '') {
        printf("<script>window.BeaconSurvey=%s;</script>\n", wp_json_encode($o['survey_selector'], JSON_HEX_TAG | JSON_HEX_AMP));

        // Number-field overrides: STRICT by default. A number input is only
        // captured if the admin listed it here, at the bucket width chosen.
        $num_rules = beacon_parse_number_overrides((string) $o['number_overrides']);
        if ($num_rules) {
            printf("<script>window.BeaconNumRules=%s;</script>\n", wp_json_encode($num_rules, JSON_HEX_TAG | JSON_HEX_AMP));
        }
    }

    // Study code capture (opt-in, identifiable). Prints the ONE input id
    // whose submitted value the tracker may read. See settings for the
    // compliance requirements. Off when blank.
    if ($o['study_field'] !== '') {
        printf("<script>window.BeaconStudyField=%s;</script>\n", wp_json_encode($o['study_field'], JSON_HEX_TAG | JSON_HEX_AMP));
    }

    // (b) The tracker itself, served from this plugin's own folder. The page
    // title is chosen here, not read from the browser: WordPress puts the
    // visitor's search terms in the title of search pages.
    $page_path = '';
    if (is_search()) {
        $page_title = __('Search results', 'beacon-analytics');
        $page_path  = '/' . beacon_search_base() . '/'; // never the search words
    } elseif (is_404()) {
        $page_title = __('Page not found', 'beacon-analytics');
    } else {
        $page_title = html_entity_decode(wp_strip_all_tags(wp_get_document_title()), ENT_QUOTES, 'UTF-8');
    }
    printf(
        "<script defer src=\"%s\" data-site=\"%s\" data-endpoint=\"%s\" data-title=\"%s\"%s></script>\n",
        esc_url(BEACON_URL . 'assets/tracker.js?v=' . BEACON_VERSION),
        esc_attr($site_key),
        esc_url($endpoint),
        esc_attr($page_title),
        $page_path !== '' ? ' data-path="' . esc_attr($page_path) . '"' : ''
    );

    // (c) No-JavaScript fallback: a 1x1 pixel that records the pageview via
    // GET. Carries only the site key and the event type.
    printf(
        "<noscript><img src=\"%s\" alt=\"\" width=\"1\" height=\"1\" style=\"position:absolute\"></noscript>\n",
        esc_url(add_query_arg('site', $site_key, $endpoint))
    );
});
