<?php
declare(strict_types=1);

/**
 * Service Lines: how visits that arrived on each entry link (like /?sl=brst)
 * behaved, side by side. A summary table compares every service line; pick
 * one to see which pages its visits reached, how long they stayed on each,
 * what they clicked, and how they answered surveys.
 *
 * Same numbers as the "Service lines" tabs in the reports. Counts are
 * visits: a visit that clicks something twice counts once.
 */

if (!defined('ABSPATH')) {
    exit;
}

function beacon_render_service_lines(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'beacon-analytics'));
    }

    $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '30d';
    if (!in_array($range, ['1d', '7d', '30d', '90d', 'all'], true)) {
        $range = '30d';
    }
    $ranges = ['1d' => __('24h', 'beacon-analytics'), '7d' => __('7 days', 'beacon-analytics'),
        '30d' => __('30 days', 'beacon-analytics'), '90d' => __('90 days', 'beacon-analytics'),
        'all' => __('All time', 'beacon-analytics')];
    $base = admin_url('admin.php?page=beacon-service-lines');

    $d      = beacon_get_service_line_data($range);
    $groups = $d['groups'];
    $pick   = isset($_GET['sl']) ? sanitize_text_field(wp_unslash($_GET['sl'])) : '';
    if (!in_array($pick, $groups, true)) {
        $pick = $groups[0] ?? '';
    }
    $sum = [];
    foreach ($d['summary'] as $s) {
        $sum[$s['group']] = $s;
    }
    $gvisits = max(1, (int) ($sum[$pick]['visits'] ?? 0));
    $dur = static fn(int $s): string => $s >= 60 ? sprintf('%dm %02ds', intdiv($s, 60), $s % 60) : $s . 's';
    ?>
    <div class="wrap beacon-wrap" id="beacon-app">
      <header class="beacon-head">
        <div>
          <h1 class="beacon-title">
            <span class="dashicons dashicons-networking" aria-hidden="true"></span>
            <?php esc_html_e('Service Lines', 'beacon-analytics'); ?>
          </h1>
          <p class="beacon-sub"><?php esc_html_e('How visits behaved, grouped by the entry link they arrived on (like /?sl=brst).', 'beacon-analytics'); ?></p>
        </div>
        <button type="button" id="beacon-theme" class="beacon-btn" aria-pressed="false">
          <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
          <?php esc_html_e('Dark mode', 'beacon-analytics'); ?>
        </button>
      </header>

      <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Date range', 'beacon-analytics'); ?>">
        <?php foreach ($ranges as $r => $label) : $on = ($r === $range); ?>
          <a class="beacon-chip<?php echo $on ? ' is-on' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(['range' => $r, 'sl' => $pick], $base)); ?>"
             <?php echo $on ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if (count($groups) === 0 || (count($groups) === 1 && $groups[0] === beacon_organic_label())) : ?>
        <section class="beacon-card">
          <p class="beacon-muted"><?php esc_html_e('No visits in this range arrived on an entry link. Entry links are pages with an allowed query key (Settings > Privacy & data > Query keys to keep), like /?sl=brst.', 'beacon-analytics'); ?></p>
        </section>
      <?php else : ?>

      <section class="beacon-card">
        <h2><?php esc_html_e('Compare service lines', 'beacon-analytics'); ?></h2>
        <?php if ($d['sampled']) : ?>
          <p class="beacon-help"><?php esc_html_e('Very busy range: based on the most recent 100,000 events.', 'beacon-analytics'); ?></p>
        <?php endif; ?>
        <div class="beacon-scroll-x">
          <table class="beacon-table">
            <thead><tr>
              <th scope="col"><?php esc_html_e('Service line', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Visits', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Avg visit', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Avg pages', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('On a phone', 'beacon-analytics'); ?></th>
              <th scope="col" class="beacon-num"><?php esc_html_e('Answered a survey', 'beacon-analytics'); ?></th>
            </tr></thead>
            <tbody>
              <?php foreach ($d['summary'] as $s) : ?>
                <tr>
                  <th scope="row"><?php echo esc_html($s['group']); ?></th>
                  <td class="beacon-num"><?php echo esc_html(number_format_i18n($s['visits'])); ?></td>
                  <td class="beacon-num"><?php echo esc_html($dur((int) $s['avg_len'])); ?></td>
                  <td class="beacon-num"><?php echo esc_html((string) $s['avg_pages']); ?></td>
                  <td class="beacon-num"><?php echo esc_html($s['mobile'] . '%'); ?></td>
                  <td class="beacon-num"><?php echo esc_html($s['answered'] . '%'); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Service line', 'beacon-analytics'); ?>">
        <?php foreach ($groups as $g) : $on = ($g === $pick); ?>
          <a class="beacon-chip<?php echo $on ? ' is-on' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(['range' => $range, 'sl' => $g], $base)); ?>"
             <?php echo $on ? 'aria-current="page"' : ''; ?>><?php echo esc_html($g); ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if (isset($d['queries'][$pick])) : ?>
        <p>
          <a class="beacon-btn" href="<?php echo esc_url(add_query_arg(['page' => 'beacon-journeys', 'range' => in_array($range, ['1d', '7d', '30d', 'all'], true) ? $range : 'all', 'eq' => $d['queries'][$pick]], admin_url('admin.php'))); ?>">
            <span class="dashicons dashicons-randomize" aria-hidden="true"></span>
            <?php echo esc_html(sprintf(/* translators: %s: service line */ __('View the %s journeys', 'beacon-analytics'), $pick)); ?>
          </a>
        </p>
      <?php endif; ?>

      <?php
      $tables = [
          ['title' => __('Pages these visits reached', 'beacon-analytics'), 'col' => __('Page', 'beacon-analytics'), 'items' => $d['pages'], 'time' => true],
          ['title' => __('What these visits clicked', 'beacon-analytics'), 'col' => __('Event — label', 'beacon-analytics'), 'items' => $d['clicks'], 'time' => false],
          ['title' => __('How these visits answered', 'beacon-analytics'), 'col' => __('Question → Answer', 'beacon-analytics'), 'items' => $d['answers'], 'time' => false],
      ];
      foreach ($tables as $tb) :
          $items = array_values(array_filter($tb['items'], static fn($it) => ($it['by'][$pick] ?? 0) > 0));
          usort($items, static fn($a, $b) => ($b['by'][$pick] ?? 0) <=> ($a['by'][$pick] ?? 0));
          if (!$items) {
              continue;
          }
          ?>
        <section class="beacon-card">
          <h2><?php echo esc_html($tb['title'] . ' (' . $pick . ')'); ?></h2>
          <div class="beacon-scroll-x">
            <table class="beacon-table">
              <thead><tr>
                <th scope="col"><?php echo esc_html($tb['col']); ?></th>
                <th scope="col" class="beacon-num"><?php esc_html_e('Visits', 'beacon-analytics'); ?></th>
                <th scope="col" class="beacon-num"><?php esc_html_e('% of these visits', 'beacon-analytics'); ?></th>
                <?php if ($tb['time']) : ?>
                  <th scope="col" class="beacon-num"><?php esc_html_e('Avg time on page', 'beacon-analytics'); ?></th>
                <?php endif; ?>
              </tr></thead>
              <tbody>
                <?php foreach (array_slice($items, 0, 50) as $it) : $n = (int) $it['by'][$pick]; ?>
                  <tr>
                    <td><?php echo esc_html(mb_substr($it['label'], 0, 90)); ?></td>
                    <td class="beacon-num"><?php echo esc_html(number_format_i18n($n)); ?></td>
                    <td class="beacon-num"><?php echo esc_html(round(100 * $n / $gvisits) . '%'); ?></td>
                    <?php if ($tb['time']) :
                        $sec = $d['time'][$it['label']][$pick] ?? null; ?>
                      <td class="beacon-num"><?php echo $sec === null ? '<span class="beacon-muted">—</span>' : esc_html($dur((int) $sec)); ?></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>

      <p class="beacon-help">
        <?php esc_html_e('Counts are visits, so a visit that clicks something twice counts once. Average time on page leaves out the last page of each visit, because nobody can know how long someone looked at it before closing the tab. The same tables, for every service line at once, are in the Excel report.', 'beacon-analytics'); ?>
      </p>
      <?php endif; ?>
    </div>
    <?php
}
