<?php
declare(strict_types=1);

/**
 * Journeys: anonymous session paths and funnels.
 *
 * The privacy line, stated once and enforced here: a journey is ONE visit by
 * an anonymous session that cannot be tied to a person or followed across
 * days. There is deliberately no way to look up "a user" — only sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

function beacon_render_journeys(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'beacon-analytics'));
    }

    $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '1d';
    if (!in_array($range, ['1d', '7d', '30d', 'all'], true)) {
        $range = '1d';
    }
    $session = isset($_GET['session']) ? sanitize_key(wp_unslash($_GET['session'])) : '';
    $session = preg_match('/^[a-f0-9]{32}$/', $session) ? $session : '';

    // Entry-path filter + pager (both survive in the URL, so views are shareable).
    $entry = isset($_GET['entry']) ? sanitize_text_field(wp_unslash($_GET['entry'])) : '';
    $entry = mb_substr(preg_replace('/[<>"\']/', '', $entry), 0, 100);
    // Entry-query filter ("sl=brst"): only visits that arrived with a query.
    $eq    = isset($_GET['eq']) ? sanitize_text_field(wp_unslash($_GET['eq'])) : '';
    $eq    = mb_substr(preg_replace('/[<>"\']/', '', $eq), 0, 100);
    $pg    = isset($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
    // Visitor filter: a label NUMBER from this range (never the stored code).
    $vis   = isset($_GET['vis']) ? max(0, (int) $_GET['vis']) : 0;

    $base = admin_url('admin.php?page=beacon-journeys');
    ?>
    <div class="wrap beacon-wrap" id="beacon-app">

      <header class="beacon-head">
        <div>
          <h1 class="beacon-title">
            <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
            <?php esc_html_e('Journeys', 'beacon-analytics'); ?>
          </h1>
          <p class="beacon-sub">
            <?php esc_html_e('One anonymous visit at a time. Visitor numbers group visits from the same browser on the same day, but no visit can be tied to a person or followed across days. That is the HIPAA line, and the data model enforces it.', 'beacon-analytics'); ?>
          </p>
        </div>
        <button type="button" id="beacon-theme" class="beacon-btn" aria-pressed="false">
          <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
          <?php esc_html_e('Dark mode', 'beacon-analytics'); ?>
        </button>
      </header>

      <?php if ($session !== '') : ?>
        <?php beacon_journeys_detail($session, $base, $range, $entry, $pg, $eq, $vis); ?>
      <?php else : ?>
        <?php beacon_journeys_list($base, $range, $entry, $pg, $eq, $vis); ?>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * The session list plus funnels, with entry filter and pager.
 */
function beacon_journeys_list(string $base, string $range, string $entry, int $pg, string $eq = '', int $vis = 0): void
{
    $vh = $vis > 0 ? (string) (array_search($vis, beacon_visitor_labels($range), true) ?: '') : '';
    if ($vh === '') {
        $vis = 0; // unknown label for this range: show everyone
    }
    $per_page = 50;
    $ranges   = ['1d' => __('24h', 'beacon-analytics'), '7d' => __('7 days', 'beacon-analytics'), '30d' => __('30 days', 'beacon-analytics'), 'all' => __('All time', 'beacon-analytics')];
    $total    = beacon_count_sessions($range, $entry, $eq, $vh);
    $pages    = max(1, (int) ceil($total / $per_page));
    $pg       = min($pg, $pages);
    $sessions = beacon_get_sessions($range, $entry, $pg, $per_page, $eq, $vh);
    $funnels  = beacon_eval_funnels(
        beacon_parse_funnels((string) beacon_settings()['funnels']),
        $range
    );
    ?>
    <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Date range', 'beacon-analytics'); ?>">
      <?php foreach ($ranges as $r => $label) : $on = ($r === $range); ?>
        <a class="beacon-chip<?php echo $on ? ' is-on' : ''; ?>"
           href="<?php echo esc_url(add_query_arg(['range' => $r, 'entry' => $entry, 'eq' => $eq], $base)); ?>"
           <?php echo $on ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($funnels) : ?>
      <section class="beacon-card">
        <h2><?php esc_html_e('Funnels', 'beacon-analytics'); ?></h2>
        <?php if (!empty($funnels[0]['sampled'])) : ?>
          <p class="beacon-help"><?php esc_html_e('High traffic range: funnel numbers are based on the 1,500 most recent sessions.', 'beacon-analytics'); ?></p>
        <?php endif; ?>
        <?php foreach ($funnels as $f) : $first = max(1, (int) ($f['counts'][0] ?? 0)); ?>
          <div class="beacon-funnel">
            <h3><?php echo esc_html($f['name']); ?></h3>
            <ol class="beacon-funnel-steps">
              <?php foreach ($f['steps'] as $i => $step) :
                  $n   = (int) $f['counts'][$i];
                  $pct = (int) round($n / $first * 100);
                  ?>
                <li>
                  <span class="beacon-funnel-label">
                    <?php echo esc_html(($step['type'] === 'event' ? 'event: ' : '') . $step['value']); ?>
                  </span>
                  <span class="beacon-funnel-bar" aria-hidden="true" style="width:<?php echo max(2, $pct); ?>%"></span>
                  <span class="beacon-funnel-num">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: session count, 2: percent of first step */
                        __('%1$s sessions (%2$d%%)', 'beacon-analytics'),
                        number_format_i18n($n),
                        $pct
                    ));
                    ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endforeach; ?>
      </section>
    <?php else : ?>
      <p class="beacon-muted">
        <?php esc_html_e('No funnels defined yet. Add them in Beacon Settings, e.g.: Signup path | /landing > /pricing > event:signup_click', 'beacon-analytics'); ?>
      </p>
    <?php endif; ?>

    <section class="beacon-card">
      <h2><?php esc_html_e('Sessions', 'beacon-analytics'); ?></h2>

      <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="beacon-filters">
        <input type="hidden" name="page" value="beacon-journeys">
        <input type="hidden" name="range" value="<?php echo esc_attr($range); ?>">
        <label for="beacon_entry"><?php esc_html_e('Entry page starts with', 'beacon-analytics'); ?></label>
        <input type="text" id="beacon_entry" name="entry" class="beacon-input beacon-code"
               style="max-width:16rem" value="<?php echo esc_attr($entry); ?>" placeholder="/landing">
        <label for="beacon_eq"><?php esc_html_e('Entry query contains', 'beacon-analytics'); ?></label>
        <input type="text" id="beacon_eq" name="eq" class="beacon-input beacon-code"
               style="max-width:12rem" value="<?php echo esc_attr($eq); ?>" placeholder="sl=brst">
        <button type="submit" class="beacon-btn"><?php esc_html_e('Filter', 'beacon-analytics'); ?></button>
        <?php if ($entry !== '' || $eq !== '' || $vis > 0) : ?>
          <a class="beacon-btn" href="<?php echo esc_url(add_query_arg('range', $range, $base)); ?>"><?php esc_html_e('Clear', 'beacon-analytics'); ?></a>
        <?php endif; ?>
      </form>

      <?php if ($vis > 0) : ?>
        <p class="beacon-help">
          <?php echo esc_html(sprintf(
              /* translators: %d: visitor number */
              __('Showing only Visitor %d: visits from the same browser on the same day. Visitor numbers are made fresh for each view, and a visitor on another day gets a new number.', 'beacon-analytics'),
              $vis
          )); ?>
        </p>
      <?php endif; ?>

      <p class="beacon-muted">
        <?php
        echo esc_html(sprintf(
            /* translators: 1: session count, 2: page, 3: total pages */
            __('%1$s sessions match. Page %2$d of %3$d.', 'beacon-analytics'),
            number_format_i18n($total),
            $pg,
            $pages
        ));
        ?>
      </p>

      <?php if (!$sessions) : ?>
        <p class="beacon-muted"><?php esc_html_e('No sessions match this range and filter.', 'beacon-analytics'); ?></p>
      <?php else : ?>
        <table class="beacon-table">
          <thead>
            <tr>
              <th scope="col"><?php esc_html_e('Started', 'beacon-analytics'); ?></th>
              <th scope="col"><?php esc_html_e('Visitor', 'beacon-analytics'); ?></th>
              <?php if (beacon_settings()['study_field'] !== '') : ?>
                <th scope="col"><?php esc_html_e('Study code', 'beacon-analytics'); ?></th>
              <?php endif; ?>
              <th scope="col"><?php esc_html_e('Entry page', 'beacon-analytics'); ?></th>
              <th scope="col"><?php esc_html_e('Entry query', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Pages', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Events', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Length', 'beacon-analytics'); ?></th>
              <th scope="col"><?php esc_html_e('Journey', 'beacon-analytics'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sessions as $s) :
                $secs = max(0, strtotime((string) $s['ended']) - strtotime((string) $s['started']));
                $len  = $secs >= 60 ? sprintf('%dm %02ds', intdiv($secs, 60), $secs % 60) : $secs . 's';
                ?>
              <tr>
                <td><?php echo esc_html(get_date_from_gmt((string) $s['started'], 'M j, g:i a')); ?></td>
                <?php $beacon_vl = beacon_visitor_label($range, $s['vh'] ?? null); ?>
                <td>
                  <?php if ($beacon_vl !== '') : ?>
                    <a href="<?php echo esc_url(add_query_arg(['range' => $range, 'vis' => beacon_visitor_labels($range)[(string) $s['vh']]], $base)); ?>"
                       title="<?php esc_attr_e('Show every visit by this visitor on this day', 'beacon-analytics'); ?>"><?php echo esc_html($beacon_vl); ?></a>
                  <?php else : ?>
                    <span class="beacon-muted">—</span>
                  <?php endif; ?>
                </td>
                <?php if (beacon_settings()['study_field'] !== '') : ?>
                  <td><?php echo $s['study_code'] !== null && $s['study_code'] !== ''
                      ? '<code>' . esc_html((string) $s['study_code']) . '</code>'
                      : '<span class="beacon-muted">—</span>'; ?></td>
                <?php endif; ?>
                <?php [$beacon_ep, $beacon_eqs] = beacon_split_path((string) $s['entry_path']); ?>
                <td><?php echo esc_html(mb_substr($beacon_ep, 0, 50)); ?></td>
                <td><?php echo $beacon_eqs !== ''
                    ? '<code>' . esc_html(mb_substr($beacon_eqs, 0, 50)) . '</code>'
                    : '<span class="beacon-muted">—</span>'; ?></td>
                <td class="beacon-num"><?php echo (int) $s['pageviews']; ?></td>
                <td class="beacon-num"><?php echo (int) $s['events']; ?></td>
                <td class="beacon-num"><?php echo esc_html($len); ?></td>
                <td>
                  <a href="<?php echo esc_url(add_query_arg(['session' => $s['session_id'], 'range' => $range, 'entry' => $entry, 'eq' => $eq, 'vis' => $vis, 'pg' => $pg], $base)); ?>">
                    <?php esc_html_e('View steps', 'beacon-analytics'); ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($pages > 1) : ?>
          <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Session pages', 'beacon-analytics'); ?>" style="margin-top:1rem">
            <?php if ($pg > 1) : ?>
              <a class="beacon-chip beacon-chip-sm"
                 href="<?php echo esc_url(add_query_arg(['range' => $range, 'entry' => $entry, 'eq' => $eq, 'vis' => $vis, 'pg' => $pg - 1], $base)); ?>">
                &larr; <?php esc_html_e('Newer', 'beacon-analytics'); ?>
              </a>
            <?php endif; ?>
            <?php if ($pg < $pages) : ?>
              <a class="beacon-chip beacon-chip-sm"
                 href="<?php echo esc_url(add_query_arg(['range' => $range, 'entry' => $entry, 'eq' => $eq, 'vis' => $vis, 'pg' => $pg + 1], $base)); ?>">
                <?php esc_html_e('Older', 'beacon-analytics'); ?> &rarr;
              </a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
        <p class="beacon-help">
          <?php esc_html_e('Every visit in the retention window is reachable here. Visits with Do Not Track have no session and are counted only in totals.', 'beacon-analytics'); ?>
        </p>
      <?php endif; ?>
    </section>
    <?php
}

/**
 * One session's ordered steps.
 */
function beacon_journeys_detail(string $session_id, string $base, string $range, string $entry = '', int $pg = 1, string $eq = '', int $vis = 0): void
{
    $steps = beacon_get_session_steps($session_id);

    // Time on page: from one pageview to the NEXT pageview (events in
    // between belong to the same page). The final page has no "next", so
    // its time is unknowable — the visitor closed the tab unseen. Every
    // analytics tool shares this blind spot; Beacon shows a dash instead
    // of inventing a number.
    $durations = [];
    foreach ($steps as $i => $st) {
        if ($st['event_type'] !== 'pageview') {
            continue;
        }
        for ($j = $i + 1, $n = count($steps); $j < $n; $j++) {
            if ($steps[$j]['event_type'] === 'pageview') {
                $durations[$i] = max(0, strtotime((string) $steps[$j]['created_at'])
                    - strtotime((string) $st['created_at']));
                break;
            }
        }
    }
    $beacon_fmt_dur = static function (int $s): string {
        return $s >= 60 ? sprintf('%dm %02ds', intdiv($s, 60), $s % 60) : $s . 's';
    };
    ?>
    <p>
      <a class="beacon-btn" href="<?php echo esc_url(add_query_arg(['range' => $range, 'entry' => $entry, 'eq' => $eq, 'vis' => $vis, 'pg' => $pg], $base)); ?>">
        <span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
        <?php esc_html_e('All sessions', 'beacon-analytics'); ?>
      </a>
    </p>

    <section class="beacon-card">
      <h2><?php esc_html_e('Session journey', 'beacon-analytics'); ?></h2>
      <?php if (!$steps) : ?>
        <p class="beacon-muted"><?php esc_html_e('Session not found (it may have been pruned).', 'beacon-analytics'); ?></p>
      <?php else : ?>
        <ol class="beacon-steps">
          <?php foreach ($steps as $i => $st) : ?>
            <li>
              <span class="beacon-step-time"><?php echo esc_html(get_date_from_gmt((string) $st['created_at'], 'g:i:s a')); ?></span>
              <?php if ($st['event_type'] === 'pageview') : ?>
                <span class="beacon-step-type beacon-step-page"><?php esc_html_e('Page', 'beacon-analytics'); ?></span>
                <span class="beacon-step-what">
                  <?php echo esc_html((string) $st['path']); ?>
                  <?php if ($st['title']) : ?>
                    <span class="beacon-muted"> — <?php echo esc_html((string) $st['title']); ?></span>
                  <?php endif; ?>
                  <span class="beacon-muted">
                    <?php
                    echo isset($durations[$i])
                        ? esc_html(sprintf(/* translators: %s: duration */ __('(on page %s)', 'beacon-analytics'), $beacon_fmt_dur($durations[$i])))
                        : esc_html__('(last page — time unknown)', 'beacon-analytics');
                    ?>
                  </span>
                </span>
              <?php else : ?>
                <span class="beacon-step-type beacon-step-event">
                  <?php echo esc_html($st['event_type'] === 'outbound' ? __('Outbound', 'beacon-analytics') : __('Event', 'beacon-analytics')); ?>
                </span>
                <span class="beacon-step-what">
                  <?php echo esc_html((string) $st['event_name']); ?>
                  <?php if ($st['event_label']) : ?>
                    <span class="beacon-muted"> — <?php echo esc_html((string) $st['event_label']); ?></span>
                  <?php endif; ?>
                  <span class="beacon-muted"> (<?php echo esc_html((string) $st['path']); ?>)</span>
                </span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>
    <?php
}
