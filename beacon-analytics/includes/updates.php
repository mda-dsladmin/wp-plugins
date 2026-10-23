<?php
declare(strict_types=1);

/**
 * Self-hosted plugin updates.
 *
 * The plugin header carries "Update URI: https://envsnstudios.com/..." which
 * tells WordPress two things: (1) never look for this plugin on wordpress.org
 * (so a same-named directory plugin can't hijack an update), and (2) offer
 * the "update_plugins_envsnstudios.com" filter during update checks. We hook
 * that filter, ask our own update server what the newest version is, and if
 * it is newer than what's installed WordPress shows the normal one-click
 * update in the Plugins screen. Auto-updates work too.
 *
 * The server side is one small file, update.php, sitting in the same folder
 * as the release zips (see update-server/ in the project). Releasing a new
 * version = dropping the new zip in that folder. Nothing else to edit.
 *
 * Guardrails:
 *   - The package URL must be HTTPS on the pinned update host, or the
 *     response is ignored. A tampered or misconfigured server cannot point
 *     installs at a zip somewhere else.
 *   - Responses are cached for 6 hours (misses for 1 hour), so the update
 *     host is not hit on every wp-admin page load. The "Check again" button
 *     on Dashboard > Updates bypasses the cache.
 */

if (!defined('ABSPATH')) {
    exit;
}

// The one place the update location is defined. If the hosting folder ever
// moves, change these two lines and the Update URI line in the plugin header.
const BEACON_UPDATE_HOST = 'envsnstudios.com';
const BEACON_UPDATE_BASE = 'https://envsnstudios.com/plugins/';

/**
 * Latest release info, from GitHub Releases (default) or the legacy
 * self-hosted folder (when the repo setting is blank).
 *
 * @return array{version:string,package:string}|null Extra keys pass through.
 */
function beacon_update_fetch_info(): ?array
{
    $cached = get_site_transient('beacon_update_info');
    if (is_array($cached)) {
        return isset($cached['version']) ? $cached : null; // ['none'] = miss
    }
    delete_site_transient('beacon_update_error');

    $repo = (string) beacon_settings()['update_repo'];
    $data = $repo !== '' ? beacon_update_from_github($repo) : beacon_update_from_folder();

    if ($data !== null) {
        set_site_transient('beacon_update_info', $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }
    // Unreachable or nothing usable: remember the miss for an hour so admin
    // pages stay fast. The "Check for updates" link clears it.
    set_site_transient('beacon_update_info', ['none' => 1], HOUR_IN_SECONDS);
    return null;
}

/** Remember why the last check failed, for the Plugins screen notice. */
function beacon_update_fail(string $why): void
{
    set_site_transient('beacon_update_error', $why, HOUR_IN_SECONDS);
}

/**
 * GitHub Releases. Works with a repo that holds several plugins: it scans
 * recent published releases (no drafts or pre-releases) for an attached
 * .zip whose name contains "beacon-analytics", and takes the highest
 * version. The version comes from the tag (v1.19.0, beacon-analytics-v1.19.0,
 * ...), else the asset name, else the release title.
 */
function beacon_update_from_github(string $repo): ?array
{
    $headers = [
        'Accept'               => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2022-11-28',
        'User-Agent'           => 'Beacon-Analytics/' . BEACON_VERSION, // GitHub requires one
    ];
    $token = (string) beacon_settings()['update_token'];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token; // optional: lifts the 60/hour limit
    }
    $res = wp_remote_get('https://api.github.com/repos/' . $repo . '/releases?per_page=30', [
        'timeout' => 10,
        'headers' => $headers,
    ]);
    if (is_wp_error($res)) {
        beacon_update_fail(sprintf('GitHub could not be reached (%s).', $res->get_error_message()));
        return null;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 404) {
        beacon_update_fail(sprintf('GitHub says the repo "%s" does not exist or is private.', $repo));
        return null;
    }
    if ($code === 403 || $code === 429) {
        beacon_update_fail('GitHub rate limit reached (60 checks per hour per server, shared with other sites on the same host). Wait an hour, or add a GitHub token in Beacon settings.');
        return null;
    }
    if ($code !== 200) {
        beacon_update_fail(sprintf('GitHub answered with HTTP %d.', $code));
        return null;
    }
    $releases = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($releases)) {
        beacon_update_fail('GitHub sent an unreadable answer.');
        return null;
    }

    $best = null;
    $repo_path = '/' . strtolower($repo) . '/releases/download/';
    foreach ($releases as $rel) {
        if (!is_array($rel) || !empty($rel['draft']) || !empty($rel['prerelease'])) {
            continue;
        }
        $asset = null;
        foreach ((array) ($rel['assets'] ?? []) as $a) {
            $name = strtolower((string) ($a['name'] ?? ''));
            $url  = (string) ($a['browser_download_url'] ?? '');
            if (str_ends_with($name, '.zip') && str_contains($name, 'beacon-analytics')
                && str_starts_with($url, 'https://github.com/')
                && str_starts_with(strtolower((string) wp_parse_url($url, PHP_URL_PATH)), $repo_path)) {
                $asset = ['name' => $name, 'url' => $url];
                break;
            }
        }
        if ($asset === null) {
            continue; // another plugin's release, or no zip attached
        }
        $version = '';
        foreach ([(string) ($rel['tag_name'] ?? ''), $asset['name'], (string) ($rel['name'] ?? '')] as $src) {
            if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $src, $m)) {
                $version = $m[1];
                break;
            }
        }
        if ($version === '' || ($best !== null && !version_compare($version, $best['version'], '>'))) {
            continue;
        }
        $best = [
            'version'      => $version,
            'package'      => $asset['url'],
            'url'          => (string) ($rel['html_url'] ?? 'https://github.com/' . $repo . '/releases'),
            'last_updated' => (string) ($rel['published_at'] ?? ''),
            'changelog'    => (string) ($rel['body'] ?? ''),
        ];
    }
    if ($best === null) {
        beacon_update_fail(sprintf('No published release in %s has a .zip attached with "beacon-analytics" in its name.', $repo));
    }
    return $best;
}

/** Legacy source: update.php, then manifest.json, in the self-hosted folder. */
function beacon_update_from_folder(): ?array
{
    foreach ([BEACON_UPDATE_BASE . 'update.php', BEACON_UPDATE_BASE . 'manifest.json'] as $url) {
        $res = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            continue;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['version']) || empty($data['package'])
            || !is_string($data['version']) || !is_string($data['package'])) {
            continue;
        }
        // Only install code served over HTTPS from the pinned host.
        $host = wp_parse_url($data['package'], PHP_URL_HOST);
        if (!is_string($host) || strtolower($host) !== BEACON_UPDATE_HOST
            || !str_starts_with($data['package'], 'https://')) {
            continue;
        }
        return $data;
    }
    beacon_update_fail('The self-hosted update folder could not be reached.');
    return null;
}

/**
 * Keep the plugin in its own folder when updating. WordPress installs the
 * zip's top folder as-is, so a release zip whose folder is named anything
 * else (beacon-analytics-plugin, wp-plugins-main/...) would land in a new
 * folder and deactivate the plugin. Rename it back before install.
 */
add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader, $hook_extra = []) {
    if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? '') !== plugin_basename(BEACON_FILE)) {
        return $source;
    }
    $want = trailingslashit($remote_source) . dirname(plugin_basename(BEACON_FILE));
    if (untrailingslashit($source) === $want) {
        return $source;
    }
    // Monorepo-style zip: the plugin can sit one folder deeper.
    $main = basename(BEACON_FILE);
    if (!file_exists(trailingslashit($source) . $main) && file_exists(trailingslashit($source) . 'beacon-analytics/' . $main)) {
        $source = trailingslashit($source) . 'beacon-analytics';
    }
    if (!file_exists(trailingslashit($source) . $main)) {
        return new WP_Error('beacon_bad_zip', 'The release zip does not contain beacon-analytics.php.');
    }
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->move(untrailingslashit($source), $want, true)) {
        return trailingslashit($want);
    }
    return new WP_Error('beacon_rename_failed', 'Could not move the update into the beacon-analytics folder.');
}, 10, 4);

// The update check itself. Core extracts the hostname from the Update URI
// header and fires this filter; whatever we return (with a newer version)
// becomes the update offer.
add_filter('update_plugins_' . BEACON_UPDATE_HOST, function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== plugin_basename(BEACON_FILE)) {
        return $update; // some other plugin also updating from this host
    }
    $info = beacon_update_fetch_info();
    if ($info === null || !version_compare($info['version'], BEACON_VERSION, '>')) {
        return $update; // nothing newer (core would also no_update this,
                        // but being explicit keeps older cores safe)
    }
    return [
        'id'           => BEACON_UPDATE_BASE . 'beacon-analytics',
        'slug'         => 'beacon-analytics',
        'plugin'       => $plugin_file,
        'version'      => $info['version'],
        'url'          => is_string($info['url'] ?? null) ? $info['url'] : BEACON_UPDATE_BASE,
        'package'      => $info['package'],
        'requires'     => is_string($info['requires'] ?? null) ? $info['requires'] : '6.0',
        'requires_php' => is_string($info['requires_php'] ?? null) ? $info['requires_php'] : '8.0',
        'tested'       => is_string($info['tested'] ?? null) ? $info['tested'] : '',
    ];
}, 10, 3);

// "Check again" on Dashboard > Updates should really check again.
add_action('load-update-core.php', function (): void {
    if (isset($_GET['force-check'])) { // phpcs:ignore WordPress.Security.NonceVerification
        delete_site_transient('beacon_update_info');
    }
});

// The "View version x.y.z details" link on the Plugins screen asks
// wordpress.org unless we answer here. Serve a minimal info card so the
// link works instead of erroring.
add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'beacon-analytics') {
        return $result;
    }
    $info = beacon_update_fetch_info();
    $changelog = is_string($info['changelog'] ?? null) ? $info['changelog'] : '';
    return (object) [
        'name'          => 'Beacon Analytics',
        'slug'          => 'beacon-analytics',
        'version'       => $info['version'] ?? BEACON_VERSION,
        'author'        => 'Montez French',
        'homepage'      => BEACON_UPDATE_BASE,
        'requires'      => $info['requires'] ?? '6.0',
        'requires_php'  => $info['requires_php'] ?? '8.0',
        'last_updated'  => $info['last_updated'] ?? '',
        'download_link' => $info['package'] ?? '',
        'sections'      => [
            'description' => '<p>Self-hosted, privacy-first analytics that live entirely inside this WordPress site. Updates are served from ' . esc_html(BEACON_UPDATE_HOST) . '.</p>',
            'changelog'   => $changelog !== '' ? wpautop(esc_html($changelog)) : '<p>See the release notes shipped with each version.</p>',
        ],
    ];
}, 10, 3);

// ---- "Check for updates" link on the Plugins screen ----
// A one-click force check: clears Beacon's cached answer AND WordPress's
// own plugin-update cache, asks the update server right away, then comes
// back to the Plugins screen with a notice saying what it found.
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file !== plugin_basename(BEACON_FILE) || !current_user_can('update_plugins')) {
        return $links;
    }
    $url = wp_nonce_url(admin_url('admin-post.php?action=beacon_check_updates'), 'beacon_check_updates');
    $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Check for updates', 'beacon-analytics') . '</a>';
    return $links;
}, 10, 2);

add_action('admin_post_beacon_check_updates', function (): void {
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('Not allowed.', 'beacon-analytics'));
    }
    check_admin_referer('beacon_check_updates');

    delete_site_transient('beacon_update_info'); // Beacon's 6h cache
    delete_site_transient('update_plugins');     // WordPress's cache
    wp_update_plugins();                         // ask now, not on the next cron

    $info = beacon_update_fetch_info();          // fresh, cached by the call above
    if ($info === null) {
        $msg = 'unreachable';
    } elseif (version_compare($info['version'], BEACON_VERSION, '>')) {
        $msg = 'available';
    } else {
        $msg = 'current';
    }
    wp_safe_redirect(add_query_arg(['beacon_update' => $msg], admin_url('plugins.php')));
    exit;
});

add_action('admin_notices', function (): void {
    global $pagenow;
    if ($pagenow !== 'plugins.php' || !isset($_GET['beacon_update'])) { // phpcs:ignore WordPress.Security.NonceVerification -- display only
        return;
    }
    $state = sanitize_key(wp_unslash($_GET['beacon_update'])); // phpcs:ignore WordPress.Security.NonceVerification -- display only
    $info  = get_site_transient('beacon_update_info');
    if ($state === 'available' && is_array($info) && isset($info['version'])) {
        $cls  = 'notice-warning';
        $text = sprintf(
            /* translators: 1: new version, 2: installed version */
            __('Beacon %1$s is available (you have %2$s). Use "update now" in the Beacon row below.', 'beacon-analytics'),
            $info['version'],
            BEACON_VERSION
        );
    } elseif ($state === 'current') {
        $cls  = 'notice-success';
        $text = sprintf(/* translators: %s: installed version */ __('Beacon is up to date (%s).', 'beacon-analytics'), BEACON_VERSION);
    } elseif ($state === 'unreachable') {
        $cls  = 'notice-error';
        $why  = get_site_transient('beacon_update_error');
        $text = __('Beacon could not check for updates.', 'beacon-analytics') . ' ' . (is_string($why) ? $why : '');
    } else {
        return;
    }
    printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($cls), esc_html($text));
});
