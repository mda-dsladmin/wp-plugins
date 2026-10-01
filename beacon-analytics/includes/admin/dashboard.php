<?php
declare(strict_types=1);

/**
 * The analytics dashboard screen (Beacon design, scoped to .beacon-wrap).
 * Server-rendered: the range switcher is plain links, so the screen works
 * with JavaScript off. The only JS is the theme toggle.
 */

if (!defined('ABSPATH')) {
    exit;
}

function beacon_render_dashboard(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'beacon-analytics'));
    }

    $o = beacon_settings();

    $range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7d';
    if (!in_array($range, ['1d', '7d', '30d', '90d'], true)) {
        $range = '7d';
    }

    $style = isset($_GET['chart']) ? sanitize_key(wp_unslash($_GET['chart'])) : 'bars';
    if (!in_array($style, ['bars', 'lines'], true)) {
        $style = 'bars';
    }

    $data    = beacon_get_summary($range);
    $t       = $data['totals'];
    $max_day = 1;
    foreach ($data['series'] as $row) {
        $max_day = max($max_day, (int) $row['views']);
    }
    $ranges = ['1d' => __('24h', 'beacon-analytics'), '7d' => __('7 days', 'beacon-analytics'), '30d' => __('30 days', 'beacon-analytics'), '90d' => __('90 days', 'beacon-analytics')];
    $base   = admin_url('admin.php?page=beacon-analytics');
    ?>
    <div class="wrap beacon-wrap" id="beacon-app">

      <header class="beacon-head">
        <div>
          <h1 class="beacon-title">
            <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
            <?php esc_html_e('Beacon Analytics', 'beacon-analytics'); ?>
          </h1>
          <p class="beacon-sub">
            <?php esc_html_e('Self-hosted on this site. No IPs stored, no query strings, no third-party calls.', 'beacon-analytics'); ?>
          </p>
        </div>
        <div class="beacon-head-actions">
          <a class="beacon-btn" href="<?php echo esc_url(wp_nonce_url(
              admin_url('admin-post.php?action=beacon_export_csv&range=' . $range),
              'beacon_export_csv'
          )); ?>">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <?php esc_html_e('Export CSV', 'beacon-analytics'); ?>
          </a>
          <button type="button" id="beacon-print" class="beacon-btn">
            <span class="dashicons dashicons-printer" aria-hidden="true"></span>
            <?php esc_html_e('Print report (PDF)', 'beacon-analytics'); ?>
          </button>
          <button type="button" id="beacon-theme" class="beacon-btn" aria-pressed="false">
            <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
            <?php esc_html_e('Dark mode', 'beacon-analytics'); ?>
          </button>
        </div>
      </header>

      <?php if (empty($o['enabled'])) : ?>
        <p class="beacon-notice" role="status">
          <?php
          printf(
              /* translators: %s: link to the settings page */
              wp_kses(__('Tracking is off. Turn it on in %s.', 'beacon-analytics'), []),
              '<a href="' . esc_url(admin_url('admin.php?page=beacon-settings')) . '">'
              . esc_html__('Beacon Settings', 'beacon-analytics') . '</a>'
          );
          ?>
        </p>
      <?php elseif ($o['endpoint'] !== '') : ?>
        <p class="beacon-notice" role="status">
          <?php esc_html_e('Events are being sent to an external collector, so this dashboard only shows data collected on this site:', 'beacon-analytics'); ?>
          <code><?php echo esc_html($o['endpoint']); ?></code>
        </p>
      <?php endif; ?>

      <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Date range', 'beacon-analytics'); ?>">
        <?php foreach ($ranges as $r => $label) : $on = ($r === $range); ?>
          <a class="beacon-chip<?php echo $on ? ' is-on' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(['range' => $r, 'chart' => $style], $base)); ?>"
             <?php echo $on ? 'aria-current="page"' : ''; ?>>
            <?php echo esc_html($label); ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <nav class="beacon-ranges" aria-label="<?php esc_attr_e('Chart style', 'beacon-analytics'); ?>">
        <?php
        $styles = ['bars' => __('Bars', 'beacon-analytics'), 'lines' => __('Line', 'beacon-analytics')];
        foreach ($styles as $s => $label) : $on = ($s === $style); ?>
          <a class="beacon-chip beacon-chip-sm<?php echo $on ? ' is-on' : ''; ?>"
             href="<?php echo esc_url(add_query_arg(['range' => $range, 'chart' => $s], $base)); ?>"
             <?php echo $on ? 'aria-current="page"' : ''; ?>>
            <?php echo esc_html($label); ?>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="beacon-cards">
        <?php
        $cards = [
            [__('Pageviews', 'beacon-analytics'), (int) $t['pageviews'], 'visibility'],
            [__('Unique visitors', 'beacon-analytics'), (int) $t['visitors'], 'admin-users'],
            [__('Sessions', 'beacon-analytics'), (int) $t['sessions'], 'clock'],
            [__('Pageviews, last 30 min', 'beacon-analytics'), (int) $data['realtime']['pageviews'], 'marker'],
        ];
        if ($data['avg_load'] > 0) {
            $cards[] = [__('Avg page load (ms)', 'beacon-analytics'), (int) $data['avg_load'], 'performance'];
        }
        foreach ($cards as [$label, $val, $icon]) : ?>
          <div class="beacon-card">
            <p class="beacon-card-label">
              <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
              <?php echo esc_html($label); ?>
            </p>
            <p class="beacon-card-num"><?php echo esc_html(number_format_i18n($val)); ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <section class="beacon-card beacon-chart-card" aria-label="<?php esc_attr_e('Pageviews by day', 'beacon-analytics'); ?>">
        <h2><?php esc_html_e('Pageviews by day', 'beacon-analytics'); ?></h2>
        <?php if ((int) $t['pageviews'] === 0) : ?>
          <p class="beacon-muted"><?php esc_html_e('No data yet. Once tracking is on, visits show up here.', 'beacon-analytics'); ?></p>
        <?php elseif ($style === 'lines') : ?>
          <?php echo beacon_svg_line($data['series']); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG built from escaped values ?>
          <span class="screen-reader-text">
            <?php
            foreach ($data['series'] as $row) {
                echo esc_html($row['day'] . ': ' . number_format_i18n((int) $row['views']) . ' views. ');
            }
            ?>
          </span>
        <?php else : ?>
          <ul class="beacon-bars">
            <?php foreach ($data['series'] as $row) :
                $views = (int) $row['views'];
                $h     = max(4, (int) round(($views / $max_day) * 128));
                ?>
              <li>
                <span class="beacon-bar" style="height:<?php echo (int) $h; ?>px" aria-hidden="true"></span>
                <span class="beacon-bar-day" aria-hidden="true"><?php echo esc_html(substr((string) $row['day'], 5)); ?></span>
                <span class="screen-reader-text">
                  <?php
                  echo esc_html(sprintf(
                      /* translators: 1: date, 2: view count */
                      __('%1$s: %2$s views', 'beacon-analytics'),
                      $row['day'],
                      number_format_i18n($views)
                  ));
                  ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <div class="beacon-grid-2">
        <?php
        beacon_dash_table(
            __('Top pages', 'beacon-analytics'),
            __('Path', 'beacon-analytics'),
            $data['top_pages'],
            'path',
            'views'
        );
        beacon_dash_table(
            __('Top referrers', 'beacon-analytics'),
            __('Source', 'beacon-analytics'),
            $data['referrers'],
            'host',
            'views'
        );
        beacon_dash_table(
            __('Entry pages', 'beacon-analytics'),
            __('Path', 'beacon-analytics'),
            $data['entries'],
            'path',
            'views',
            __('Sessions', 'beacon-analytics')
        );
        beacon_dash_table(
            __('Exit pages', 'beacon-analytics'),
            __('Path', 'beacon-analytics'),
            $data['exits'],
            'path',
            'views',
            __('Sessions', 'beacon-analytics')
        );
        if (beacon_geo_available()) {
            beacon_dash_table(
                __('Countries', 'beacon-analytics'),
                __('Country', 'beacon-analytics'),
                $data['countries'],
                'country',
                'views',
                '',
                true
            );
            beacon_dash_table(
                __('US states', 'beacon-analytics'),
                __('State', 'beacon-analytics'),
                $data['regions'],
                'region',
                'views'
            );
        }
        beacon_dash_table(
            __('Devices', 'beacon-analytics'),
            __('Device', 'beacon-analytics'),
            $data['devices'],
            'device_type',
            'views',
            '',
            true
        );
        beacon_dash_table(
            __('Browsers', 'beacon-analytics'),
            __('Browser', 'beacon-analytics'),
            $data['browsers'],
            'browser',
            'views',
            '',
            true
        );
        beacon_dash_table(
            __('Operating systems', 'beacon-analytics'),
            __('OS', 'beacon-analytics'),
            $data['oses'],
            'os',
            'views',
            '',
            true
        );
        beacon_dash_table(
            __('Screen sizes', 'beacon-analytics'),
            __('Size', 'beacon-analytics'),
            $data['screens'],
            'screen_bucket',
            'views',
            '',
            true
        );
        ?>
      </div>

      <?php if (beacon_geo_available() && $data['regions']) : ?>
        <section class="beacon-card beacon-chart-card" aria-label="<?php esc_attr_e('US states map', 'beacon-analytics'); ?>">
          <h2><?php esc_html_e('Where US visitors are (state level)', 'beacon-analytics'); ?></h2>
          <?php echo beacon_svg_us_map($data['regions']); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG built from escaped values ?>
          <p class="beacon-help"><?php esc_html_e('Darker red means more pageviews. State is the finest location Beacon stores; the table above is the exact record.', 'beacon-analytics'); ?></p>
        </section>
      <?php endif; ?>

      <?php
      // Outbound clicks only — custom tag events live in Journeys, where
      // each one has its full session context.
      beacon_dash_table(
          __('Exit URLs (outbound clicks)', 'beacon-analytics'),
          __('Destination', 'beacon-analytics'),
          $data['exit_urls'],
          'destination',
          'fires',
          __('Clicks', 'beacon-analytics')
      );
      ?>

      <p class="beacon-foot">
        <?php
        echo esc_html(sprintf(
            /* translators: %d: retention days */
            __('Raw events are kept for %d days, then deleted automatically.', 'beacon-analytics'),
            (int) $o['retention_days']
        ));
        if (beacon_geo_available()) {
            // Required CC BY 4.0 attribution for the local location database.
            echo ' ';
            printf(
                /* translators: %s: DB-IP link */
                esc_html__('Location data (country and state only) resolved locally using %s.', 'beacon-analytics'),
                '<a href="https://db-ip.com" rel="noopener">IP Geolocation by DB-IP</a>'
            );
        }
        ?>
      </p>
    </div>
    <?php
}

/**
 * One label + count table in the Beacon card style.
 *
 * @param array<int,array> $rows
 */
function beacon_dash_table(string $title, string $col, array $rows, string $key, string $count_key, string $count_label = '', bool $pie = false): void
{
    $count_label = $count_label !== '' ? $count_label : __('Views', 'beacon-analytics');
    ?>
    <section class="beacon-card">
      <h2><?php echo esc_html($title); ?></h2>
      <?php if (!$rows) : ?>
        <p class="beacon-muted"><?php esc_html_e('No data yet.', 'beacon-analytics'); ?></p>
      <?php else : ?>
        <?php if ($pie) : ?>
          <?php echo beacon_svg_pie($rows, $key, $count_key); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG built from escaped values ?>
        <?php endif; ?>
        <table class="beacon-table">
          <thead>
            <tr>
              <th scope="col"><?php echo esc_html($col); ?></th>
              <th scope="col" class="beacon-num"><?php echo esc_html($count_label); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r) : ?>
              <tr>
                <td><?php echo esc_html((string) ($r[$key] ?? '')); ?></td>
                <td class="beacon-num"><?php echo esc_html(number_format_i18n((int) ($r[$count_key] ?? 0))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
    <?php
}
