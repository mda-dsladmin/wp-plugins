<?php
declare(strict_types=1);

/**
 * The Site Scan screen: run a scan, watch progress, and work the fix queue.
 * Server-rendered; filters are GET links/selects and every state change is a
 * nonce-checked POST. Findings sort by severity, then by how much traffic
 * the page gets (from Beacon's own analytics), so busy problem pages surface
 * first.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---- actions ---- */

// Lightweight progress endpoint so the page can update its status line in
// place instead of fully reloading (a full reload every few seconds resets
// screen-reader position and scroll — an accessibility problem).
add_action('wp_ajax_beacon_scan_progress', function (): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }
    check_ajax_referer('beacon_scan_progress');
    $run = beacon_scan_active_run();
    wp_send_json_success([
        'running' => (bool) $run,
        'scanned' => $run ? (int) $run['pages_scanned'] : 0,
        'total'   => $run ? (int) $run['pages_total'] : 0,
    ]);
});

add_action('admin_post_beacon_start_scan', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_start_scan');
    $result = beacon_scan_start();
    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-scan', 'beacon_msg' => $result],
        admin_url('admin.php')
    ));
    exit;
});

add_action('admin_post_beacon_clear_scans', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_clear_scans');

    global $wpdb;
    // Stop any running scan first so a stray tick can't write into the void.
    wp_unschedule_hook('beacon_scan_tick');
    foreach ([beacon_findings_table(), beacon_scan_runs_table(), beacon_scan_pages_table(), beacon_scan_queue_table()] as $beacon_t) {
        $wpdb->query("TRUNCATE TABLE {$beacon_t}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix
    }
    // Drop any per-run link caches left behind.
    $like = $wpdb->esc_like('beacon_scan_links_') . '%';
    $opts = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));
    foreach ($opts as $opt) {
        delete_option((string) $opt);
    }

    wp_safe_redirect(add_query_arg(
        ['page' => 'beacon-scan', 'beacon_msg' => 'cleared'],
        admin_url('admin.php')
    ));
    exit;
});

add_action('admin_post_beacon_finding_status', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_finding_status');

    global $wpdb;
    $id     = isset($_POST['finding']) ? (int) $_POST['finding'] : 0;
    $status = isset($_POST['new_status']) ? sanitize_key(wp_unslash($_POST['new_status'])) : '';
    if ($id > 0 && in_array($status, ['open', 'fixed', 'ignored'], true)) {
        $wpdb->update(beacon_findings_table(), ['status' => $status], ['id' => $id]);
    }

    $back = wp_get_referer() ?: admin_url('admin.php?page=beacon-scan');
    wp_safe_redirect($back);
    exit;
});

/* ---- screen ---- */

function beacon_render_scan(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'beacon-analytics'));
    }

    global $wpdb;
    $ft = beacon_findings_table();
    $rt = beacon_scan_runs_table();

    $active     = beacon_scan_active_run();
    $latest     = $wpdb->get_row("SELECT * FROM {$rt} WHERE status = 'done' ORDER BY id DESC LIMIT 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $latest_id  = $latest ? (int) $latest['id'] : 0;
    $last_any   = $wpdb->get_row("SELECT * FROM {$rt} ORDER BY id DESC LIMIT 1", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Filters (GET, so the URL is shareable).
    $cats = ['privacy', 'links', 'seo', 'accessibility', 'content', 'policy'];
    $f_cat    = isset($_GET['cat']) ? sanitize_key(wp_unslash($_GET['cat'])) : '';
    $f_sev    = isset($_GET['sev']) ? sanitize_key(wp_unslash($_GET['sev'])) : '';
    $f_status = isset($_GET['fstatus']) ? sanitize_key(wp_unslash($_GET['fstatus'])) : 'open';
    $f_cat    = in_array($f_cat, $cats, true) ? $f_cat : '';
    $f_sev    = in_array($f_sev, beacon_severities(), true) ? $f_sev : '';
    $f_status = in_array($f_status, ['open', 'fixed', 'ignored'], true) ? $f_status : 'open';

    // Build the queue query with prepared fragments only.
    $where  = ['status = %s'];
    $params = [$f_status];
    if ($f_cat !== '') {
        $where[]  = 'category = %s';
        $params[] = $f_cat;
    }
    if ($f_sev !== '') {
        $where[]  = 'severity = %s';
        $params[] = $f_sev;
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$ft} WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 300", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ...$params
    ), ARRAY_A);

    // Pageviews per path (last 30 days) to rank busy pages first.
    $views = [];
    $paths = array_values(array_unique(array_column($rows, 'path')));
    if ($paths) {
        $ph = implode(',', array_fill(0, count($paths), '%s'));
        $vt = beacon_table();
        $vr = $wpdb->get_results($wpdb->prepare(
            "SELECT path, COUNT(*) AS views FROM {$vt}
             WHERE event_type = 'pageview' AND created_at >= %s AND path IN ({$ph})
             GROUP BY path", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS),
            ...$paths
        ), ARRAY_A);
        $views = array_column($vr, 'views', 'path');
    }
    $rank = array_flip(beacon_severities());
    usort($rows, static function ($a, $b) use ($rank, $views) {
        $r = ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
        if ($r !== 0) {
            return $r;
        }
        return (int) ($views[$b['path']] ?? 0) <=> (int) ($views[$a['path']] ?? 0);
    });
    $total_rows = count($rows);
    $rows = array_slice($rows, 0, 100);

    // Headline chips.
    $open_by_sev = array_column($wpdb->get_results(
        "SELECT severity, COUNT(*) AS n FROM {$ft} WHERE status = 'open' GROUP BY severity", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ARRAY_A
    ), 'n', 'severity');
    $new_latest  = $latest_id ? (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$ft} WHERE first_seen_run = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $latest_id
    )) : 0;
    $fixed_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$ft} WHERE status = 'fixed'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Trend: findings present per finished run (last 8).
    $trend_runs = array_reverse($wpdb->get_results(
        "SELECT id, started_at FROM {$rt} WHERE status = 'done' ORDER BY id DESC LIMIT 8", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ARRAY_A
    ));
    $trend = [];
    foreach ($trend_runs as $r) {
        $trend[] = [
            'label' => mb_substr((string) $r['started_at'], 5, 5),
            'n'     => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ft} WHERE first_seen_run <= %d AND last_seen_run >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $r['id'],
                (int) $r['id']
            )),
        ];
    }
    $trend_max = max(1, ...array_merge([0], array_column($trend, 'n')));

    $base = admin_url('admin.php?page=beacon-scan');
    $msg  = isset($_GET['beacon_msg']) ? sanitize_key(wp_unslash($_GET['beacon_msg'])) : '';
    ?>
    <div class="wrap beacon-wrap" id="beacon-app"
         <?php if ($active) : ?>
           data-scan-running="1"
           data-progress-url="<?php echo esc_url(admin_url('admin-ajax.php?action=beacon_scan_progress&_wpnonce=' . wp_create_nonce('beacon_scan_progress'))); ?>"
         <?php endif; ?>>

      <header class="beacon-head">
        <div>
          <h1 class="beacon-title">
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <?php esc_html_e('Site Scan', 'beacon-analytics'); ?>
          </h1>
          <p class="beacon-sub">
            <?php esc_html_e('Privacy leaks, broken links, SEO, static accessibility, content, and your policy rules. Static checks are a subset, not a full audit — zero issues here does not mean fully accessible.', 'beacon-analytics'); ?>
          </p>
        </div>
        <div class="beacon-head-actions">
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('beacon_start_scan'); ?>
            <input type="hidden" name="action" value="beacon_start_scan">
            <button type="submit" class="beacon-btn beacon-btn-primary" <?php disabled((bool) $active); ?>>
              <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
              <?php esc_html_e('Scan now', 'beacon-analytics'); ?>
            </button>
          </form>
          <a class="beacon-btn" href="<?php echo esc_url(wp_nonce_url(
              admin_url('admin-post.php?action=beacon_export_findings'),
              'beacon_export_findings'
          )); ?>">
            <span class="dashicons dashicons-download" aria-hidden="true"></span>
            <?php esc_html_e('Export CSV', 'beacon-analytics'); ?>
          </a>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="beacon-clear-form">
            <?php wp_nonce_field('beacon_clear_scans'); ?>
            <input type="hidden" name="action" value="beacon_clear_scans">
            <button type="submit" class="beacon-btn">
              <span class="dashicons dashicons-trash" aria-hidden="true"></span>
              <?php esc_html_e('Clear scan results', 'beacon-analytics'); ?>
            </button>
          </form>
          <button type="button" id="beacon-theme" class="beacon-btn" aria-pressed="false">
            <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
            <?php esc_html_e('Dark mode', 'beacon-analytics'); ?>
          </button>
        </div>
      </header>

      <?php if ($msg === 'cleared') : ?>
        <p class="beacon-notice" role="status"><?php esc_html_e('All scan results cleared. Findings, run history, and any in-progress scan are gone. Analytics data was not touched.', 'beacon-analytics'); ?></p>
      <?php elseif ($msg === 'already_running') : ?>
        <p class="beacon-notice" role="status"><?php esc_html_e('A scan is already running.', 'beacon-analytics'); ?></p>
      <?php elseif ($msg === 'started') : ?>
        <p class="beacon-notice" role="status"><?php esc_html_e('Scan started. This page refreshes itself while it runs.', 'beacon-analytics'); ?></p>
      <?php endif; ?>

      <?php if ($active) : ?>
        <p class="beacon-notice" role="status" aria-live="polite" id="beacon-scan-progress">
          <?php
          echo esc_html(sprintf(
              /* translators: 1: pages scanned, 2: total pages */
              __('Scanning… %1$d of %2$d pages done.', 'beacon-analytics'),
              (int) $active['pages_scanned'],
              (int) $active['pages_total']
          ));
          ?>
        </p>
      <?php elseif ($last_any && $last_any['status'] === 'failed') : ?>
        <p class="beacon-notice" role="status">
          <?php esc_html_e('The last scan failed. If this keeps happening, the server may not be able to fetch its own pages (loopback blocked) or the cron job was interrupted. Try Scan now again.', 'beacon-analytics'); ?>
        </p>
      <?php elseif ($latest) : ?>
        <p class="beacon-muted">
          <?php
          echo esc_html(sprintf(
              /* translators: 1: date, 2: pages */
              __('Last scan: %1$s UTC, %2$d pages.', 'beacon-analytics'),
              (string) $latest['started_at'],
              (int) $latest['pages_scanned']
          ));
          ?>
        </p>
      <?php endif; ?>

      <div class="beacon-cards">
        <?php
        $chips = [
            [__('Critical open', 'beacon-analytics'), (int) ($open_by_sev['critical'] ?? 0), 'warning'],
            [__('Serious open', 'beacon-analytics'), (int) ($open_by_sev['serious'] ?? 0), 'flag'],
            [__('New this scan', 'beacon-analytics'), $new_latest, 'plus-alt2'],
            [__('Fixed so far', 'beacon-analytics'), $fixed_total, 'yes-alt'],
        ];
        foreach ($chips as [$label, $val, $icon]) : ?>
          <div class="beacon-card">
            <p class="beacon-card-label">
              <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
              <?php echo esc_html($label); ?>
            </p>
            <p class="beacon-card-num"><?php echo esc_html(number_format_i18n($val)); ?></p>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($trend) > 1) : ?>
        <section class="beacon-card beacon-chart-card" aria-label="<?php esc_attr_e('Findings per scan', 'beacon-analytics'); ?>">
          <h2><?php esc_html_e('Findings per scan', 'beacon-analytics'); ?></h2>
          <ul class="beacon-bars">
            <?php foreach ($trend as $tr) : ?>
              <li>
                <span class="beacon-bar" style="height:<?php echo (int) max(4, round($tr['n'] / $trend_max * 128)); ?>px" aria-hidden="true"></span>
                <span class="beacon-bar-day" aria-hidden="true"><?php echo esc_html($tr['label']); ?></span>
                <span class="screen-reader-text">
                  <?php
                  echo esc_html(sprintf(
                      /* translators: 1: date, 2: finding count */
                      __('%1$s: %2$s findings', 'beacon-analytics'),
                      $tr['label'],
                      number_format_i18n($tr['n'])
                  ));
                  ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <section class="beacon-card">
        <h2><?php esc_html_e('Fix queue', 'beacon-analytics'); ?></h2>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="beacon-filters">
          <input type="hidden" name="page" value="beacon-scan">
          <label for="beacon_f_cat"><?php esc_html_e('Category', 'beacon-analytics'); ?></label>
          <select id="beacon_f_cat" name="cat" class="beacon-input beacon-input-sm">
            <option value=""><?php esc_html_e('All', 'beacon-analytics'); ?></option>
            <?php foreach ($cats as $c) : ?>
              <option value="<?php echo esc_attr($c); ?>" <?php selected($f_cat, $c); ?>><?php echo esc_html(ucfirst($c)); ?></option>
            <?php endforeach; ?>
          </select>
          <label for="beacon_f_sev"><?php esc_html_e('Severity', 'beacon-analytics'); ?></label>
          <select id="beacon_f_sev" name="sev" class="beacon-input beacon-input-sm">
            <option value=""><?php esc_html_e('All', 'beacon-analytics'); ?></option>
            <?php foreach (beacon_severities() as $s) : ?>
              <option value="<?php echo esc_attr($s); ?>" <?php selected($f_sev, $s); ?>><?php echo esc_html(ucfirst($s)); ?></option>
            <?php endforeach; ?>
          </select>
          <label for="beacon_f_status"><?php esc_html_e('Status', 'beacon-analytics'); ?></label>
          <select id="beacon_f_status" name="fstatus" class="beacon-input beacon-input-sm">
            <?php foreach (['open' => __('Open', 'beacon-analytics'), 'fixed' => __('Fixed', 'beacon-analytics'), 'ignored' => __('Ignored', 'beacon-analytics')] as $k => $label) : ?>
              <option value="<?php echo esc_attr($k); ?>" <?php selected($f_status, $k); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="beacon-btn"><?php esc_html_e('Filter', 'beacon-analytics'); ?></button>
        </form>

        <?php if (!$rows) : ?>
          <p class="beacon-muted"><?php esc_html_e('Nothing here. Run a scan, or loosen the filters.', 'beacon-analytics'); ?></p>
        <?php else : ?>
          <?php if ($total_rows > 100) : ?>
            <p class="beacon-muted">
              <?php
              echo esc_html(sprintf(
                  /* translators: 1: shown, 2: total */
                  __('Showing the top 100 of %d findings (worst severity, busiest pages first). Use the filters to narrow down.', 'beacon-analytics'),
                  $total_rows
              ));
              ?>
            </p>
          <?php endif; ?>
          <table class="beacon-table beacon-queue">
            <thead>
              <tr>
                <th scope="col"><?php esc_html_e('Severity', 'beacon-analytics'); ?></th>
                <th scope="col"><?php esc_html_e('Category', 'beacon-analytics'); ?></th>
                <th scope="col"><?php esc_html_e('Page', 'beacon-analytics'); ?></th>
                <th scope="col"><?php esc_html_e('Issue', 'beacon-analytics'); ?></th>
                <th scope="col" class="beacon-num"><?php esc_html_e('Views (30d)', 'beacon-analytics'); ?></th>
                <th scope="col"><?php esc_html_e('Action', 'beacon-analytics'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r) : ?>
                <tr>
                  <td><span class="beacon-sev beacon-sev-<?php echo esc_attr($r['severity']); ?>"><?php echo esc_html(ucfirst($r['severity'])); ?></span></td>
                  <td><?php echo esc_html(ucfirst($r['category'])); ?></td>
                  <td>
                    <a href="<?php echo esc_url(home_url($r['path'])); ?>" target="_blank" rel="noopener">
                      <?php echo esc_html(mb_substr($r['path'], 0, 60)); ?>
                      <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'beacon-analytics'); ?></span>
                    </a>
                  </td>
                  <td>
                    <?php echo esc_html($r['message']); ?>
                    <?php if ($r['selector']) : ?>
                      <br><code class="beacon-code-sm"><?php echo esc_html($r['selector']); ?></code>
                    <?php endif; ?>
                    <?php if ($r['snippet']) : ?>
                      <br><code class="beacon-code-sm"><?php echo esc_html(mb_substr($r['snippet'], 0, 100)); ?></code>
                    <?php endif; ?>
                  </td>
                  <td class="beacon-num"><?php echo esc_html(number_format_i18n((int) ($views[$r['path']] ?? 0))); ?></td>
                  <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="beacon-inline-form">
                      <?php wp_nonce_field('beacon_finding_status'); ?>
                      <input type="hidden" name="action" value="beacon_finding_status">
                      <input type="hidden" name="finding" value="<?php echo (int) $r['id']; ?>">
                      <?php if ($r['status'] !== 'fixed') : ?>
                        <button type="submit" name="new_status" value="fixed" class="beacon-btn beacon-btn-xs"><?php esc_html_e('Mark fixed', 'beacon-analytics'); ?></button>
                      <?php endif; ?>
                      <?php if ($r['status'] !== 'ignored') : ?>
                        <button type="submit" name="new_status" value="ignored" class="beacon-btn beacon-btn-xs"><?php esc_html_e('Ignore', 'beacon-analytics'); ?></button>
                      <?php endif; ?>
                      <?php if ($r['status'] !== 'open') : ?>
                        <button type="submit" name="new_status" value="open" class="beacon-btn beacon-btn-xs"><?php esc_html_e('Reopen', 'beacon-analytics'); ?></button>
                      <?php endif; ?>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <p class="beacon-foot">
        <?php esc_html_e('The scanner fetches this site\'s own public pages from this server. It never logs in, never stores full pages, and masks digit runs in stored snippets.', 'beacon-analytics'); ?>
      </p>
    </div>
    <?php
}
