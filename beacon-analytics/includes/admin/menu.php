<?php
declare(strict_types=1);

/**
 * Admin menu + asset loading. Two screens, admins only:
 *   Beacon            the analytics dashboard
 *   Beacon > Settings the plugin settings
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The real page hooks, captured from the add_*_page() return values so the
 * asset check below can't drift if the menu title is ever translated.
 */
function beacon_page_hooks(array $add = []): array
{
    static $hooks = [];
    if ($add) {
        $hooks = array_merge($hooks, $add);
    }
    return $hooks;
}

add_action('admin_menu', function (): void {
    $hooks = [];
    $hooks[] = add_menu_page(
        __('Beacon Analytics', 'beacon-analytics'),
        __('Beacon', 'beacon-analytics'),
        'manage_options',
        'beacon-analytics',
        'beacon_render_dashboard',
        'dashicons-chart-bar',
        58
    );
    $hooks[] = add_submenu_page(
        'beacon-analytics',
        __('Beacon Analytics', 'beacon-analytics'),
        __('Dashboard', 'beacon-analytics'),
        'manage_options',
        'beacon-analytics',
        'beacon_render_dashboard'
    );
    $hooks[] = add_submenu_page(
        'beacon-analytics',
        __('Journeys', 'beacon-analytics'),
        __('Journeys', 'beacon-analytics'),
        'manage_options',
        'beacon-journeys',
        'beacon_render_journeys'
    );
    $hooks[] = add_submenu_page(
        'beacon-analytics',
        __('Site Scan', 'beacon-analytics'),
        __('Site Scan', 'beacon-analytics'),
        'manage_options',
        'beacon-scan',
        'beacon_render_scan'
    );
    $hooks[] = add_submenu_page(
        'beacon-analytics',
        __('Beacon Settings', 'beacon-analytics'),
        __('Settings', 'beacon-analytics'),
        'manage_options',
        'beacon-settings',
        'beacon_render_settings'
    );
    beacon_page_hooks(array_filter($hooks));
});

// Load the scoped Beacon styles only on our own screens.
add_action('admin_enqueue_scripts', function (string $hook): void {
    if (!in_array($hook, beacon_page_hooks(), true)) {
        return;
    }
    wp_enqueue_style('beacon-admin', BEACON_URL . 'assets/admin.css', [], BEACON_VERSION);
    wp_enqueue_script('beacon-admin', BEACON_URL . 'assets/admin.js', [], BEACON_VERSION, true);
});
