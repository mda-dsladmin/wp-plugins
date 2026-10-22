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
 * Ask the update server for the latest release info.
 * Tries update.php first (reads the zip, always current), then the static
 * manifest.json fallback for hosts that cannot run PHP in that folder.
 *
 * @return array{version:string,package:string}|null Extra keys pass through.
 */
function beacon_update_fetch_info(): ?array
{
    $cached = get_site_transient('beacon_update_info');
    if (is_array($cached)) {
        return isset($cached['version']) ? $cached : null; // ['none'] = miss
    }

    foreach ([BEACON_UPDATE_BASE . 'update.php', BEACON_UPDATE_BASE . 'manifest.json'] as $url) {
        $res = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
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
        set_site_transient('beacon_update_info', $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    // Server unreachable or serving junk: remember the miss for an hour so
    // admin pages stay fast, then try again.
    set_site_transient('beacon_update_info', ['none' => 1], HOUR_IN_SECONDS);
    return null;
}

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
            'changelog'   => $changelog !== '' ? wp_kses_post($changelog) : '<p>See the release notes shipped with each version.</p>',
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
        $text = __('Could not reach the Beacon update server. Check that update.php or manifest.json is online, then try again.', 'beacon-analytics');
    } else {
        return;
    }
    printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($cls), esc_html($text));
});
