<?php
declare(strict_types=1);

/**
 * The settings screen. Stored as one array in wp_options via the Settings API,
 * so WordPress handles the nonce and capability gate; beacon_sanitize_settings()
 * is the clean-on-save gate nothing skips.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', function (): void {
    register_setting('beacon_group', BEACON_OPT, [
        'type'              => 'array',
        'sanitize_callback' => 'beacon_sanitize_settings',
    ]);
});

/**
 * Wipe all analytics data immediately (every row in wp_beacon_events).
 * Admin-only, nonce-checked. Scan results are separate — they have their own
 * clear button on the Site Scan screen.
 */
add_action('admin_post_beacon_clear_analytics', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_clear_analytics');

    global $wpdb;
    $wpdb->query('TRUNCATE TABLE ' . beacon_table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix

    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-settings', 'beacon_msg' => 'analytics_cleared'],
        admin_url('admin.php')
    ));
    exit;
});

/**
 * Clean every submitted value before it is stored. Never trust form input.
 * The site key is not part of the form — it always carries over from the
 * saved settings so it cannot be tampered with from the browser.
 */
function beacon_sanitize_settings($input): array
{
    $current = beacon_settings();
    $input   = is_array($input) ? $input : [];

    $endpoint = isset($input['endpoint']) ? esc_url_raw(trim((string) $input['endpoint'])) : '';
    if ($endpoint !== '' && !preg_match('#^https?://#i', $endpoint)) {
        $endpoint = ''; // only real http(s) URLs; anything else means "this site"
    }

    $dnt = ($input['dnt_mode'] ?? 'anon') === 'drop' ? 'drop' : 'anon';

    return [
        'enabled'             => !empty($input['enabled']) ? 1 : 0,
        'endpoint'            => $endpoint,
        'site_key'            => $current['site_key'],
        'tags'                => isset($input['tags']) ? sanitize_textarea_field((string) $input['tags']) : '',
        // Comma-separated query keys to keep. Only safe characters survive;
        // beacon_allowed_query_keys() re-validates each key on use.
        'query_keys'          => isset($input['query_keys'])
            ? substr(preg_replace('/[^a-z0-9_\-, ]/', '', strtolower((string) $input['query_keys'])), 0, 500)
            : '',
        'dnt_mode'            => $dnt,
        'retention_days'      => max(7, min(730, (int) ($input['retention_days'] ?? 90))),
        'exclude_logged_in'   => !empty($input['exclude_logged_in']) ? 1 : 0,
        'delete_on_uninstall' => !empty($input['delete_on_uninstall']) ? 1 : 0,
        // --- site scan ---
        'scan_weekly'          => !empty($input['scan_weekly']) ? 1 : 0,
        'scan_max_pages'       => max(10, min(2000, (int) ($input['scan_max_pages'] ?? 200))),
        'scan_check_external'  => !empty($input['scan_check_external']) ? 1 : 0,
        'scan_allowed_domains' => isset($input['scan_allowed_domains'])
            ? substr(preg_replace('/[^a-z0-9.\-, ]/', '', strtolower((string) $input['scan_allowed_domains'])), 0, 500)
            : '',
        'scan_stale_months'    => max(3, min(60, (int) ($input['scan_stale_months'] ?? 18))),
        'scan_policy_rules'    => isset($input['scan_policy_rules'])
            ? sanitize_textarea_field((string) $input['scan_policy_rules'])
            : '',
        'funnels'              => isset($input['funnels'])
            ? sanitize_textarea_field((string) $input['funnels'])
            : '',
        // A CSS selector; strip anything that could break out of the inline
        // JSON or smell like markup/urls, looped so stripped fragments can't
        // reassemble into a match. Blank = survey capture off.
        'survey_selector'      => isset($input['survey_selector'])
            ? beacon_strip_until_stable(sanitize_text_field((string) $input['survey_selector']))
            : '',
        'number_overrides'     => isset($input['number_overrides'])
            ? sanitize_textarea_field((string) $input['number_overrides'])
            : '',
        'fix_missing_alt'      => !empty($input['fix_missing_alt']) ? 1 : 0,
        'fix_missing_h1'       => !empty($input['fix_missing_h1']) ? 1 : 0,
        // Site key of a SEPARATE Beacon collector, only used when the
        // Collector URL points away from this site.
        'external_key'         => isset($input['external_key'])
            && preg_match('/^[a-f0-9]{32}$/', trim((string) $input['external_key']))
            ? trim((string) $input['external_key'])
            : '',
        // --- emailed reports ---
        // Keep only real email addresses (max 10). Blank = site admin email.
        'report_emails'        => implode(', ', array_slice(array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($input['report_emails'] ?? ''))),
            static fn($e) => $e !== '' && is_email($e)
        ))), 0, 10)),
        'report_content'       => implode(',', array_values(array_intersect(
            ['analytics', 'scan', 'funnels', 'journeys'],
            array_map('sanitize_key', (array) ($input['report_content'] ?? []))
        ))),
        'report_format'        => in_array($input['report_format'] ?? '', ['xlsx', 'pdf', 'csv'], true)
            ? $input['report_format'] : 'xlsx',
        // Study code field: ONE input id, letters/digits/dash/underscore.
        'study_field'          => substr(preg_replace('/[^a-zA-Z0-9_\-]/', '',
            ltrim(trim((string) ($input['study_field'] ?? '')), '#')), 0, 60),
        'report_intervals'     => implode(',', array_values(array_intersect(
            array_keys(beacon_report_intervals()),
            array_map('sanitize_key', (array) ($input['report_intervals'] ?? []))
        ))),
    ];
}

/** Repeat the unsafe-character strip until the value stops changing. */
function beacon_strip_until_stable(string $v): string
{
    do {
        $before = $v;
        $v = preg_replace('/[<>"\'`]|https?:/i', '', $v);
    } while ($v !== $before);
    return substr($v, 0, 120);
}

function beacon_render_settings(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'beacon-analytics'));
    }

    $o    = beacon_settings();
    $name = BEACON_OPT;
    ?>
    <div class="wrap beacon-wrap" id="beacon-app">

      <header class="beacon-head">
        <div>
          <h1 class="beacon-title">
            <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
            <?php esc_html_e('Beacon Settings', 'beacon-analytics'); ?>
          </h1>
          <p class="beacon-sub"><?php esc_html_e('Everything stays on this site unless you point the collector somewhere else.', 'beacon-analytics'); ?></p>
        </div>
        <button type="button" id="beacon-theme" class="beacon-btn" aria-pressed="false">
          <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
          <?php esc_html_e('Dark mode', 'beacon-analytics'); ?>
        </button>
      </header>

      <?php
      // Custom top-level pages don't get the automatic "saved" notice that
      // Settings-menu pages do, so announce it ourselves.
      if (isset($_GET['settings-updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
          add_settings_error(BEACON_OPT, 'beacon_saved', __('Settings saved.', 'beacon-analytics'), 'updated');
      }
      $beacon_msg = isset($_GET['beacon_msg']) ? sanitize_key(wp_unslash($_GET['beacon_msg'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
      if ($beacon_msg === 'analytics_cleared') {
          add_settings_error(BEACON_OPT, 'beacon_cleared', __('All analytics data deleted. Tracking continues from zero.', 'beacon-analytics'), 'updated');
      } elseif ($beacon_msg === 'geo_ok') {
          add_settings_error(BEACON_OPT, 'beacon_geo_ok', __('Location database downloaded. New visits now record country and state.', 'beacon-analytics'), 'updated');
      } elseif ($beacon_msg === 'geo_fail') {
          add_settings_error(BEACON_OPT, 'beacon_geo_fail', __('Could not download the location database. Try again in a minute.', 'beacon-analytics'), 'error');
      } elseif ($beacon_msg === 'report_sent') {
          add_settings_error(BEACON_OPT, 'beacon_report_sent', __('Test report sent. Check the inbox (and spam folder) of the recipients below.', 'beacon-analytics'), 'updated');
      } elseif ($beacon_msg === 'report_fail') {
          add_settings_error(BEACON_OPT, 'beacon_report_fail', __('The test report could not be sent. Check that this site can send email (a test of any contact form will tell you).', 'beacon-analytics'), 'error');
      }
      settings_errors(BEACON_OPT);
      ?>

      <form method="post" action="options.php" class="beacon-form">
        <?php settings_fields('beacon_group'); ?>

        <section class="beacon-card">
          <h2><?php esc_html_e('Tracking', 'beacon-analytics'); ?></h2>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($o['enabled'], 1); ?>>
              <?php esc_html_e('Enable tracking on the front end', 'beacon-analytics'); ?>
            </label>
          </p>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[exclude_logged_in]" value="1" <?php checked($o['exclude_logged_in'], 1); ?>>
              <?php esc_html_e('Do not count logged-in users (recommended)', 'beacon-analytics'); ?>
            </label>
          </p>

          <div class="beacon-field">
            <label for="beacon_endpoint"><?php esc_html_e('Collector URL (optional)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[endpoint]" id="beacon_endpoint" type="url"
                   class="beacon-input" value="<?php echo esc_attr($o['endpoint']); ?>"
                   placeholder="<?php echo esc_attr(rest_url('beacon/v1/collect')); ?>"
                   aria-describedby="beacon_endpoint_help">
            <p id="beacon_endpoint_help" class="beacon-help">
              <?php esc_html_e('Leave blank to collect on this site (the default shown above). Enter a URL only if a separate Beacon collector should receive the events instead.', 'beacon-analytics'); ?>
            </p>
          </div>

          <div class="beacon-field">
            <label for="beacon_external_key"><?php esc_html_e('External collector site key', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[external_key]" id="beacon_external_key" type="text"
                   class="beacon-input beacon-code" pattern="[a-f0-9]{32}"
                   value="<?php echo esc_attr($o['external_key']); ?>"
                   aria-describedby="beacon_external_key_help">
            <p id="beacon_external_key_help" class="beacon-help">
              <?php esc_html_e('Only needed with a Collector URL above: paste the site key from THAT Beacon install, or it will reject every event. Ignored when collecting locally.', 'beacon-analytics'); ?>
            </p>
          </div>

          <div class="beacon-field">
            <span class="beacon-label"><?php esc_html_e('Site key', 'beacon-analytics'); ?></span>
            <code class="beacon-key"><?php echo esc_html($o['site_key'] !== '' ? $o['site_key'] : __('Generated on activation', 'beacon-analytics')); ?></code>
            <p class="beacon-help"><?php esc_html_e('Created automatically for this site. The collector only accepts events carrying this key.', 'beacon-analytics'); ?></p>
          </div>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Tags (first-party only)', 'beacon-analytics'); ?></h2>
          <div class="beacon-field">
            <label for="beacon_tags"><?php esc_html_e('One rule per line', 'beacon-analytics'); ?></label>
            <textarea name="<?php echo esc_attr($name); ?>[tags]" id="beacon_tags" rows="6"
                      class="beacon-input beacon-code" aria-describedby="beacon_tags_help"
                      placeholder="click | .cta | cta_click"><?php echo esc_textarea($o['tags']); ?></textarea>
            <p id="beacon_tags_help" class="beacon-help">
              <?php esc_html_e('Format: trigger | value | event_name. Triggers: click/hover/submit/iframeclick (value = CSS selector), scroll (percent), timer (seconds), pageview (path). iframeclick fires when a visitor clicks into an embedded frame like a video player (labeled from the frame\'s title, once per frame per page). Click and hover events also record the element\'s visible label (page content only — never form input, and anything email- or phone-shaped is dropped). You cannot add a URL or script here by design — that is the HIPAA guardrail.', 'beacon-analytics'); ?>
            </p>
          </div>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Privacy & data', 'beacon-analytics'); ?></h2>

          <div class="beacon-field">
            <label for="beacon_query_keys"><?php esc_html_e('Query keys to keep (optional)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[query_keys]" id="beacon_query_keys" type="text"
                   class="beacon-input beacon-code" value="<?php echo esc_attr($o['query_keys']); ?>"
                   placeholder="sl, utm_source, utm_campaign"
                   aria-describedby="beacon_query_keys_help">
            <p id="beacon_query_keys_help" class="beacon-help">
              <?php esc_html_e('Query strings are stripped from URLs before storage. List keys here (comma-separated) to keep just those, so a redirect landing on /?sl=breast shows up as its own page. Never list keys that could carry personal info. Values are still digit-masked.', 'beacon-analytics'); ?>
            </p>
          </div>

          <div class="beacon-field">
            <label for="beacon_dnt"><?php esc_html_e('When a visitor sends Do Not Track / GPC', 'beacon-analytics'); ?></label>
            <select name="<?php echo esc_attr($name); ?>[dnt_mode]" id="beacon_dnt" class="beacon-input">
              <option value="anon" <?php selected($o['dnt_mode'], 'anon'); ?>><?php esc_html_e('Count the visit without any visitor hash', 'beacon-analytics'); ?></option>
              <option value="drop" <?php selected($o['dnt_mode'], 'drop'); ?>><?php esc_html_e('Store nothing at all', 'beacon-analytics'); ?></option>
            </select>
          </div>

          <div class="beacon-field">
            <label for="beacon_retention"><?php esc_html_e('Keep raw events for (days)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[retention_days]" id="beacon_retention" type="number"
                   min="7" max="730" step="1" class="beacon-input beacon-input-sm"
                   value="<?php echo esc_attr((string) $o['retention_days']); ?>">
            <p class="beacon-help"><?php esc_html_e('Older events are deleted automatically every day. 7 to 730.', 'beacon-analytics'); ?></p>
          </div>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[delete_on_uninstall]" value="1" <?php checked($o['delete_on_uninstall'], 1); ?>>
              <?php esc_html_e('Delete all analytics data when the plugin is uninstalled', 'beacon-analytics'); ?>
            </label>
          </p>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Survey capture (opt-in)', 'beacon-analytics'); ?></h2>
          <div class="beacon-field">
            <label for="beacon_survey"><?php esc_html_e('Question wrapper CSS selector(s)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[survey_selector]" id="beacon_survey" type="text"
                   class="beacon-input beacon-code" value="<?php echo esc_attr($o['survey_selector']); ?>"
                   placeholder=".question-wrapper, .quiz-item, #topic_selector" aria-describedby="beacon_survey_help">
            <p id="beacon_survey_help" class="beacon-help">
              <?php esc_html_e('Leave blank and survey answers are never recorded (the default). Add selectors (comma-separated) and answers inside them are stored as "Question → Answer" on the survey_response event: radio scales, compare checkboxes, and dropdowns. Free-text fields are never read. This is the only place Beacon stores visitor input. It stays on this server, tied only to an anonymous session — get compliance sign-off if questions are medical in nature.', 'beacon-analytics'); ?>
            </p>
          </div>

          <div class="beacon-field">
            <label for="beacon_num_overrides"><?php esc_html_e('Number field overrides (one per line)', 'beacon-analytics'); ?></label>
            <textarea name="<?php echo esc_attr($name); ?>[number_overrides]" id="beacon_num_overrides" rows="4"
                      class="beacon-input beacon-code" aria-describedby="beacon_num_help"
                      placeholder="#age | 10&#10;#weight | 5&#10;#cigs-per-day | none"><?php echo esc_textarea($o['number_overrides']); ?></textarea>
            <p id="beacon_num_help" class="beacon-help">
              <?php esc_html_e('Number inputs are NEVER captured unless their id is listed here. Format: #input-id | width. Width is the range size — 5 records "50–54", 10 records "50–59", "none" records the exact number. Bucketing happens in the visitor\'s browser. Fields that read as age always cap at "90+" no matter what, per the HIPAA Safe Harbor rule.', 'beacon-analytics'); ?>
            </p>
            <?php
            // Live readout: exactly what the parser recognized from the box
            // above, so a silently-dropped rule is visible immediately.
            $beacon_num_parsed = beacon_parse_number_overrides((string) $o['number_overrides']);
            ?>
            <p class="beacon-help">
              <strong>
                <?php
                echo esc_html(sprintf(
                    /* translators: %d: recognized rule count */
                    __('%d rule(s) currently recognized:', 'beacon-analytics'),
                    count($beacon_num_parsed)
                ));
                ?>
              </strong>
              <?php
              if ($beacon_num_parsed) {
                  $beacon_bits = [];
                  foreach ($beacon_num_parsed as $beacon_r) {
                      $beacon_bits[] = '#' . $beacon_r['match'] . ' (' . ($beacon_r['width'] > 0 ? $beacon_r['width'] : __('exact', 'beacon-analytics')) . ')';
                  }
                  echo esc_html(implode(', ', $beacon_bits));
              } else {
                  esc_html_e('none — if you have lines above, re-type them by hand (pasted text can carry invisible characters).', 'beacon-analytics');
              }
              ?>
            </p>
          </div>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Study code (identifiable — opt in)', 'beacon-analytics'); ?></h2>
          <div class="beacon-field">
            <label for="beacon_study_field"><?php esc_html_e('Study ID input field (one id)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[study_field]" id="beacon_study_field" type="text"
                   class="beacon-input beacon-code" value="<?php echo esc_attr($o['study_field']); ?>"
                   placeholder="#studyID" aria-describedby="beacon_study_help">
            <p id="beacon_study_help" class="beacon-help">
              <?php esc_html_e('Blank = off (the default). When set, the value a visitor submits in this ONE input is recorded as a study_code event, and journeys show which code each session belongs to. Codes may only contain letters, numbers, dashes, and underscores (max 32); anything else is refused.', 'beacon-analytics'); ?>
            </p>
            <p class="beacon-help">
              <strong><?php esc_html_e('Read before enabling:', 'beacon-analytics'); ?></strong>
              <?php esc_html_e('a study code points to an enrolled person via the study\'s own ID list, so with this on, stored sessions are identifiable health data, not anonymous analytics. Enable it only with documented participant authorization (consent covering ID-linked web tracking) confirmed by your IRB or privacy office, and only where hosting is permitted to hold identifiable health data (a BAA-covered server — point the Collector URL there if this site\'s host does not qualify). The rest of Beacon\'s privacy posture is unchanged, but exports, email reports, and journeys will contain the codes.', 'beacon-analytics'); ?>
            </p>
          </div>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Accessibility', 'beacon-analytics'); ?></h2>
          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[fix_missing_alt]" value="1" <?php checked($o['fix_missing_alt'], 1); ?>>
              <?php esc_html_e('Auto-fill missing image alt text from the title attribute', 'beacon-analytics'); ?>
            </label>
          </p>
          <p class="beacon-help">
            <?php esc_html_e('A stopgap, not a cure: when a content image has no alt attribute but has a title, the title is copied in as the alt at render time. Images with neither still get flagged by the Site Scan, and writing real alt text in the media library is still the proper fix. Only covers images WordPress renders through content, not theme-coded images.', 'beacon-analytics'); ?>
          </p>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[fix_missing_h1]" value="1" <?php checked($o['fix_missing_h1'], 1); ?>>
              <?php esc_html_e('Add a hidden h1 (the page title) when a page has no h1', 'beacon-analytics'); ?>
            </label>
          </p>
          <p class="beacon-help">
            <?php esc_html_e('If a page renders without any h1, a visually-hidden one carrying the page title is added so screen readers always find a top-level heading. Pages that already have an h1 are never touched. A real visible h1 is still the better fix, so the Site Scan keeps reporting these pages as a reminder.', 'beacon-analytics'); ?>
          </p>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Email reports', 'beacon-analytics'); ?></h2>

          <div class="beacon-field">
            <label for="beacon_report_emails"><?php esc_html_e('Send reports to', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[report_emails]" id="beacon_report_emails" type="text"
                   class="beacon-input" value="<?php echo esc_attr($o['report_emails']); ?>"
                   placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>"
                   aria-describedby="beacon_report_emails_help">
            <p id="beacon_report_emails_help" class="beacon-help">
              <?php
              echo esc_html(sprintf(
                  /* translators: %s: admin email address */
                  __('Comma-separated for several people. Leave blank to use the site admin email (%s). Reports carry the same de-identified totals as the dashboard — still, keep this to work addresses.', 'beacon-analytics'),
                  (string) get_option('admin_email')
              ));
              ?>
            </p>
          </div>

          <fieldset class="beacon-field">
            <legend class="beacon-label"><?php esc_html_e('What to include', 'beacon-analytics'); ?></legend>
            <?php
            $beacon_rc = explode(',', (string) $o['report_content']);
            $beacon_content_opts = [
                'analytics' => __('Analytics summary (visits, pages, devices, locations, surveys)', 'beacon-analytics'),
                'scan'      => __('Site scan results (open findings)', 'beacon-analytics'),
                'funnels'   => __('Funnels', 'beacon-analytics'),
                'journeys'  => __('Journeys (recent sessions and their steps)', 'beacon-analytics'),
            ];
            foreach ($beacon_content_opts as $beacon_k => $beacon_label) :
            ?>
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[report_content][]"
                     value="<?php echo esc_attr($beacon_k); ?>" <?php checked(in_array($beacon_k, $beacon_rc, true)); ?>>
              <?php echo esc_html($beacon_label); ?>
            </label><br>
            <?php endforeach; ?>
          </fieldset>

          <div class="beacon-field">
            <label for="beacon_report_format"><?php esc_html_e('Attachment format', 'beacon-analytics'); ?></label>
            <select name="<?php echo esc_attr($name); ?>[report_format]" id="beacon_report_format" class="beacon-input beacon-input-sm">
              <option value="xlsx" <?php selected($o['report_format'], 'xlsx'); ?>><?php esc_html_e('Excel (.xlsx)', 'beacon-analytics'); ?></option>
              <option value="pdf" <?php selected($o['report_format'], 'pdf'); ?>><?php esc_html_e('PDF', 'beacon-analytics'); ?></option>
              <option value="csv" <?php selected($o['report_format'], 'csv'); ?>><?php esc_html_e('CSV', 'beacon-analytics'); ?></option>
            </select>
            <p class="beacon-help"><?php esc_html_e('The email body shows the key totals; the attachment has every table.', 'beacon-analytics'); ?></p>
          </div>

          <fieldset class="beacon-field">
            <legend class="beacon-label"><?php esc_html_e('How often (pick any)', 'beacon-analytics'); ?></legend>
            <?php
            $beacon_ri = explode(',', (string) $o['report_intervals']);
            foreach (beacon_report_intervals() as $beacon_k => $beacon_iv) :
            ?>
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[report_intervals][]"
                     value="<?php echo esc_attr($beacon_k); ?>" <?php checked(in_array($beacon_k, $beacon_ri, true)); ?>>
              <?php echo esc_html($beacon_iv[1] . ' — ' . sprintf(/* translators: %d: days */ __('covers the last %d days', 'beacon-analytics'), $beacon_iv[0])); ?>
            </label><br>
            <?php endforeach; ?>
            <p class="beacon-help">
              <?php esc_html_e('Several at once is fine — every 2 weeks AND every 3 months, for example. Each schedule starts counting when you save it, so the first email arrives one full period later and always covers a complete window. Nothing selected = no emails.', 'beacon-analytics'); ?>
            </p>
          </fieldset>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Funnels', 'beacon-analytics'); ?></h2>
          <div class="beacon-field">
            <label for="beacon_funnels"><?php esc_html_e('One funnel per line', 'beacon-analytics'); ?></label>
            <textarea name="<?php echo esc_attr($name); ?>[funnels]" id="beacon_funnels" rows="3"
                      class="beacon-input beacon-code" aria-describedby="beacon_funnels_help"
                      placeholder="Breast pathway | /?sl=breast > event:survey_response"><?php echo esc_textarea($o['funnels']); ?></textarea>
            <p id="beacon_funnels_help" class="beacon-help">
              <?php esc_html_e('Format: name | step > step > step. A step is a path prefix (like /pricing) or event:event_name. Results show on the Journeys screen: how many sessions complete each step in order.', 'beacon-analytics'); ?>
            </p>
          </div>
        </section>

        <section class="beacon-card">
          <h2><?php esc_html_e('Site scan', 'beacon-analytics'); ?></h2>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[scan_weekly]" value="1" <?php checked($o['scan_weekly'], 1); ?>>
              <?php esc_html_e('Run a scan automatically every week', 'beacon-analytics'); ?>
            </label>
          </p>

          <p class="beacon-field">
            <label class="beacon-check">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[scan_check_external]" value="1" <?php checked($o['scan_check_external'], 1); ?>>
              <?php esc_html_e('Also check outbound links (sends HEAD requests to other sites; no visitor data involved)', 'beacon-analytics'); ?>
            </label>
          </p>

          <div class="beacon-field">
            <label for="beacon_scan_max"><?php esc_html_e('Max pages per scan', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[scan_max_pages]" id="beacon_scan_max" type="number"
                   min="10" max="2000" step="1" class="beacon-input beacon-input-sm"
                   value="<?php echo esc_attr((string) $o['scan_max_pages']); ?>">
          </div>

          <div class="beacon-field">
            <label for="beacon_scan_domains"><?php esc_html_e('Extra first-party domains (optional)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[scan_allowed_domains]" id="beacon_scan_domains" type="text"
                   class="beacon-input beacon-code" value="<?php echo esc_attr($o['scan_allowed_domains']); ?>"
                   placeholder="cdn.your-org.org, assets.your-org.org"
                   aria-describedby="beacon_scan_domains_help">
            <p id="beacon_scan_domains_help" class="beacon-help">
              <?php esc_html_e('Domains the privacy check should treat as your own (comma-separated). Anything else a page loads gets flagged as a third-party leak.', 'beacon-analytics'); ?>
            </p>
          </div>

          <div class="beacon-field">
            <label for="beacon_scan_stale"><?php esc_html_e('Flag pages not updated in (months)', 'beacon-analytics'); ?></label>
            <input name="<?php echo esc_attr($name); ?>[scan_stale_months]" id="beacon_scan_stale" type="number"
                   min="3" max="60" step="1" class="beacon-input beacon-input-sm"
                   value="<?php echo esc_attr((string) $o['scan_stale_months']); ?>">
          </div>

          <div class="beacon-field">
            <label for="beacon_policy_rules"><?php esc_html_e('Policy rules (one per line)', 'beacon-analytics'); ?></label>
            <textarea name="<?php echo esc_attr($name); ?>[scan_policy_rules]" id="beacon_policy_rules" rows="4"
                      class="beacon-input beacon-code" aria-describedby="beacon_policy_help"
                      placeholder="cutting-edge cure | serious | Banned marketing claim"><?php echo esc_textarea($o['scan_policy_rules']); ?></textarea>
            <p id="beacon_policy_help" class="beacon-help">
              <?php esc_html_e('Format: pattern | severity | message. Severity: critical, serious, moderate, or minor. Wrap the pattern in /slashes/ for regex. A rule is a pattern and a message only — no URLs, no scripts, same guardrail as tags.', 'beacon-analytics'); ?>
            </p>
          </div>
        </section>

        <?php submit_button(__('Save settings', 'beacon-analytics'), 'beacon-btn beacon-btn-primary', 'submit', true); ?>
      </form>

      <!-- Separate forms below on purpose: each action has its own nonce, and
           HTML forbids nesting them inside the settings form above. -->

      <section class="beacon-card">
        <h2><?php esc_html_e('Visitor locations (country + state)', 'beacon-analytics'); ?></h2>
        <?php
        $geo_size  = beacon_geo_available() ? @filesize(beacon_geo_db_path()) : false;
        $geo_mtime = beacon_geo_available() ? @filemtime(beacon_geo_db_path()) : false;
        ?>
        <?php if ($geo_size !== false && $geo_mtime !== false) : ?>
          <p class="beacon-help">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: file size in MB, 2: date */
                __('Location database installed (%1$s MB, updated %2$s). Lookups run on this server; no visitor data leaves it. Only country and state are stored — never city, per the HIPAA Safe Harbor standard.', 'beacon-analytics'),
                number_format_i18n($geo_size / 1048576, 1),
                wp_date((string) (get_option('date_format') ?: 'Y-m-d'), (int) $geo_mtime)
            ));
            ?>
          </p>
        <?php else : ?>
          <p class="beacon-help">
            <?php esc_html_e('No location database installed yet. Download the free DB-IP Lite file (about 60 MB) and new visits will record country and state. The download is a one-time server fetch, like a plugin update — no visitor data is involved, ever.', 'beacon-analytics'); ?>
          </p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('beacon_geo_download'); ?>
          <input type="hidden" name="action" value="beacon_geo_download">
          <button type="submit" class="beacon-btn">
            <span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
            <?php echo beacon_geo_available()
                ? esc_html__('Update location database', 'beacon-analytics')
                : esc_html__('Download location database', 'beacon-analytics'); ?>
          </button>
        </form>
        <p class="beacon-help"><?php esc_html_e('Refresh it every few months for accuracy. Attribution ("IP Geolocation by DB-IP") shows in the dashboard footer as the free license requires.', 'beacon-analytics'); ?></p>
      </section>
      <section class="beacon-card">
        <h2><?php esc_html_e('Send a test report', 'beacon-analytics'); ?></h2>
        <p class="beacon-help">
          <?php esc_html_e('Emails one report right now to the recipients set above, using the saved content and format choices (save settings first). Covers the shortest schedule you selected, or 7 days if none.', 'beacon-analytics'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('beacon_send_test_report'); ?>
          <input type="hidden" name="action" value="beacon_send_test_report">
          <button type="submit" class="beacon-btn">
            <span class="dashicons dashicons-email" aria-hidden="true"></span>
            <?php esc_html_e('Send test report now', 'beacon-analytics'); ?>
          </button>
        </form>
      </section>
      <section class="beacon-card">
        <h2><?php esc_html_e('Delete analytics data', 'beacon-analytics'); ?></h2>
        <p class="beacon-help">
          <?php esc_html_e('Erases every stored pageview, visitor count, and event right now. Settings, the site key, and Site Scan results are kept. This cannot be undone.', 'beacon-analytics'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="beacon-clear-analytics-form">
          <?php wp_nonce_field('beacon_clear_analytics'); ?>
          <input type="hidden" name="action" value="beacon_clear_analytics">
          <button type="submit" class="beacon-btn">
            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
            <?php esc_html_e('Delete all analytics data', 'beacon-analytics'); ?>
          </button>
        </form>
      </section>
    </div>
    <?php
}
