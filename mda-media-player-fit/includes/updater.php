<?php
/**
 * Updates from GitHub Releases (github.com/mda-dsladmin/wp-plugins).
 *
 * How it works:
 *   - The plugin header's "Update URI" points at github.com, so WordPress
 *     never looks for this plugin on wordpress.org, and it asks the
 *     "update_plugins_github.com" filter below instead.
 *   - We read the repo's Releases (public repo, no login needed), find the
 *     newest release tagged "mda-media-player-fit-v<version>" that has a
 *     "mda-media-player-fit.zip" asset, and offer it as a normal update.
 *   - Results are cached for 12 hours (1 hour after a failed check). The
 *     cache is shared with the other plugins from this repo, so a site with
 *     several of them still makes one request. A failed check (rate limit,
 *     outage) keeps the last good list, so a known update never vanishes.
 *   - Up to 300 releases are read (3 pages), so an older plugin in a busy
 *     repo is still found.
 *   - "Check for updates" under the plugin on the Plugins screen clears the
 *     cache and checks right away.
 *
 * Guardrails (a tampered or odd answer is ignored, never followed):
 *   - the download must be exactly
 *     https://github.com/mda-dsladmin/wp-plugins/releases/download/<tag>/<slug>.zip
 *   - versions must be plain numbers (1.2.3 or 1.2.3.4); pre-releases and
 *     drafts are skipped
 *   - the API request never follows redirects, so the optional token only
 *     ever goes to api.github.com
 *   - cached entries are checked again when used, so an older copy of this
 *     code in another plugin can't loosen these rules through the shared cache
 *
 * Optional: GitHub allows 60 anonymous API requests per hour per server IP.
 * On busy shared hosting, add a read-only token to wp-config.php to raise the
 * limit:  define( 'MDA_WP_PLUGINS_GITHUB_TOKEN', 'github_pat_...' );
 *
 * Must stay PHP 7.0 compatible.
 *
 * @package MDAMediaPlayerFit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDAMPF_UPD_SLUG', 'mda-media-player-fit' );
define( 'MDAMPF_UPD_REPO', 'mda-dsladmin/wp-plugins' );
define( 'MDAMPF_UPD_CACHE', 'mda_wp_plugins_releases_v1' ); // Shared by every plugin in the repo. Keep the same format.
define( 'MDAMPF_UPD_GOOD', 'mda_wp_plugins_releases_good_v1' ); // Last successful answer (30 days), used when a check fails.

/**
 * Latest release of every plugin in the repo, from cache or GitHub.
 *
 * @param bool $force Skip the cache.
 * @return array { 'plugins' => [ slug => info ], 'error' => string }
 */
function mdampf_upd_releases( $force = false ) {
	if ( ! $force ) {
		$cached = get_site_transient( MDAMPF_UPD_CACHE );
		if ( is_array( $cached ) && isset( $cached['plugins'] ) ) {
			return $cached;
		}
	}

	$headers = array(
		'Accept'               => 'application/vnd.github+json',
		'X-GitHub-Api-Version' => '2022-11-28',
	);
	if ( defined( 'MDA_WP_PLUGINS_GITHUB_TOKEN' ) && is_string( MDA_WP_PLUGINS_GITHUB_TOKEN ) && '' !== MDA_WP_PLUGINS_GITHUB_TOKEN ) {
		$headers['Authorization'] = 'Bearer ' . MDA_WP_PLUGINS_GITHUB_TOKEN;
	}

	$list  = array();
	$error = '';
	for ( $page = 1; $page <= 3; $page++ ) {
		$res = wp_remote_get(
			'https://api.github.com/repos/' . MDAMPF_UPD_REPO . '/releases?per_page=100&page=' . $page,
			array(
				'timeout'     => 10,
				'redirection' => 0, // never follow: the token must only ever go to api.github.com
				'headers'     => $headers,
			)
		);
		$code  = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$batch = ( 200 === $code ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		$err   = '';
		if ( is_wp_error( $res ) ) {
			$err = $res->get_error_message();
		} elseif ( 200 !== $code ) {
			$err = ( 403 === $code || 429 === $code )
				? 'GitHub rate limit reached. Try again later, or add MDA_WP_PLUGINS_GITHUB_TOKEN to wp-config.php.'
				: 'GitHub answered HTTP ' . $code . '.';
		} elseif ( ! is_array( $batch ) ) {
			$err = 'GitHub sent an unreadable answer.';
		}
		if ( '' !== $err ) {
			$error = $err; // Any page failing counts as a failed check: a partial list could hide an update.
			break;
		}
		$list = array_merge( $list, array_values( $batch ) );
		if ( count( $batch ) < 100 ) {
			break;
		}
	}

	if ( '' !== $error ) {
		// Keep the last good list, so a failed check doesn't hide an update
		// WordPress already knows about.
		$good = get_site_transient( MDAMPF_UPD_GOOD );
		$data = array(
			'plugins' => ( is_array( $good ) && isset( $good['plugins'] ) && is_array( $good['plugins'] ) ) ? $good['plugins'] : array(),
			'error'   => $error,
		);
		set_site_transient( MDAMPF_UPD_CACHE, $data, HOUR_IN_SECONDS );
		return $data;
	}

	$prefix  = 'https://github.com/' . MDAMPF_UPD_REPO . '/releases/download/';
	$plugins = array();

	foreach ( $list as $rel ) {
		if ( ! is_array( $rel ) || ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) || empty( $rel['tag_name'] ) || ! is_string( $rel['tag_name'] ) ) {
			continue;
		}
		$tag = $rel['tag_name'];
		$cut = strrpos( $tag, '-v' );
		if ( false === $cut ) {
			continue;
		}
		$slug    = substr( $tag, 0, $cut );
		$version = substr( $tag, $cut + 2 );
		if ( ! preg_match( '/^[a-z0-9-]+\z/', $slug ) || ! preg_match( '/^\d+\.\d+\.\d+(\.\d+)?\z/', $version ) ) {
			continue;
		}

		$package = '';
		if ( isset( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
			foreach ( $rel['assets'] as $asset ) {
				if ( is_array( $asset ) && isset( $asset['name'], $asset['browser_download_url'] )
					&& $slug . '.zip' === $asset['name']
					&& is_string( $asset['browser_download_url'] )
					&& $asset['browser_download_url'] === $prefix . rawurlencode( $tag ) . '/' . rawurlencode( $slug . '.zip' ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( '' === $package ) {
			continue; // No usable zip on this release.
		}

		if ( ! isset( $plugins[ $slug ] ) || version_compare( $version, $plugins[ $slug ]['version'], '>' ) ) {
			$plugins[ $slug ] = array(
				'version'   => $version,
				'package'   => $package,
				'url'       => isset( $rel['html_url'] ) && is_string( $rel['html_url'] ) ? $rel['html_url'] : 'https://github.com/' . MDAMPF_UPD_REPO,
				'notes'     => isset( $rel['body'] ) && is_string( $rel['body'] ) ? mb_substr( $rel['body'], 0, 20000 ) : '',
				'published' => isset( $rel['published_at'] ) && is_string( $rel['published_at'] ) ? $rel['published_at'] : '',
			);
		}
	}

	$data = array(
		'plugins' => $plugins,
		'error'   => '',
	);
	set_site_transient( MDAMPF_UPD_CACHE, $data, 12 * HOUR_IN_SECONDS );
	set_site_transient( MDAMPF_UPD_GOOD, $data, 30 * DAY_IN_SECONDS );
	return $data;
}

/**
 * This plugin's latest release info, or null.
 *
 * @param bool $force Skip the cache.
 * @return array|null
 */
function mdampf_upd_info( $force = false ) {
	$all  = mdampf_upd_releases( $force );
	$info = isset( $all['plugins'][ MDAMPF_UPD_SLUG ] ) ? $all['plugins'][ MDAMPF_UPD_SLUG ] : null;
	// Check again on use: the cache is shared with other plugins' copies of this code.
	if ( ! is_array( $info ) || ! isset( $info['version'], $info['package'] )
		|| ! is_string( $info['version'] ) || ! preg_match( '/^\d+\.\d+\.\d+(\.\d+)?\z/', $info['version'] )
		|| 'https://github.com/' . MDAMPF_UPD_REPO . '/releases/download/' . rawurlencode( MDAMPF_UPD_SLUG . '-v' . $info['version'] ) . '/' . rawurlencode( MDAMPF_UPD_SLUG . '.zip' ) !== $info['package'] ) {
		return null;
	}
	// Fill in or reset anything missing or odd, so no caller trips on it.
	$repo_url          = 'https://github.com/' . MDAMPF_UPD_REPO;
	$info['url']       = ( isset( $info['url'] ) && is_string( $info['url'] ) && 0 === strpos( $info['url'], $repo_url . '/' ) ) ? $info['url'] : $repo_url;
	$info['notes']     = ( isset( $info['notes'] ) && is_string( $info['notes'] ) ) ? $info['notes'] : '';
	$info['published'] = ( isset( $info['published'] ) && is_string( $info['published'] ) ) ? $info['published'] : '';
	return $info;
}

/**
 * WordPress asks this during its update checks (because of the Update URI header).
 */
function mdampf_upd_filter( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( MDAMPF_FILE ) !== $plugin_file ) {
		return $update; // Another plugin that also updates from github.com.
	}
	$info = mdampf_upd_info();
	if ( null === $info ) {
		return $update;
	}
	// Returned even when not newer: WordPress files it under "no update",
	// which keeps the auto-updates toggle working.
	return array(
		'id'           => 'https://github.com/' . MDAMPF_UPD_REPO . '/tree/main/' . MDAMPF_UPD_SLUG,
		'slug'         => MDAMPF_UPD_SLUG,
		'plugin'       => $plugin_file,
		'version'      => $info['version'],
		'new_version'  => $info['version'],
		'url'          => $info['url'],
		'package'      => $info['package'],
		'requires'     => '6.0',
		'requires_php' => '7.0',
		'tested'       => '',
	);
}
add_filter( 'update_plugins_github.com', 'mdampf_upd_filter', 10, 3 );

/**
 * "View version details" card on the Plugins screen.
 */
function mdampf_upd_plugins_api( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || MDAMPF_UPD_SLUG !== $args->slug ) {
		return $result;
	}
	$info  = mdampf_upd_info();
	$notes = ( null !== $info && '' !== $info['notes'] ) ? '<pre style="white-space:pre-wrap">' . esc_html( $info['notes'] ) . '</pre>' : '<p>See the release notes on GitHub.</p>';
	return (object) array(
		'name'          => 'MDA Media Player Fit',
		'slug'          => MDAMPF_UPD_SLUG,
		'version'       => null !== $info ? $info['version'] : MDAMPF_VERSION,
		'author'        => 'Montez French | Senior Web Developer at MD Anderson',
		'homepage'      => 'https://github.com/' . MDAMPF_UPD_REPO,
		'requires'      => '6.0',
		'requires_php'  => '7.0',
		'last_updated'  => null !== $info ? $info['published'] : '',
		'download_link' => null !== $info ? $info['package'] : '',
		'sections'      => array(
			'description' => '<p>Fits the MD Anderson media player and other embedded videos to their container at a fixed aspect ratio. Updates come from GitHub Releases.</p>',
			'changelog'   => $notes,
		),
	);
}
add_filter( 'plugins_api', 'mdampf_upd_plugins_api', 10, 3 );

/**
 * "Check for updates" link under the plugin on the Plugins screen.
 */
function mdampf_upd_row_meta( $links, $plugin_file ) {
	if ( plugin_basename( MDAMPF_FILE ) === $plugin_file && current_user_can( 'update_plugins' ) ) {
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=mdampf_check_updates' ), 'mdampf_check_updates' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'mda-media-player-fit' ) . '</a>';
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'mdampf_upd_row_meta', 10, 2 );

/**
 * Handle "Check for updates": clear caches, ask GitHub now, report back.
 */
function mdampf_upd_check_now() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'You are not allowed to update plugins.', 'mda-media-player-fit' ), 403 );
	}
	check_admin_referer( 'mdampf_check_updates' );

	$all = mdampf_upd_releases( true );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();

	$info = mdampf_upd_info();
	if ( '' !== $all['error'] ) {
		$result = 'error';
	} elseif ( null === $info ) {
		$result = 'none';
	} elseif ( version_compare( $info['version'], MDAMPF_VERSION, '>' ) ) {
		$result = 'available';
	} else {
		$result = 'current';
	}

	// Plugins are managed in Network Admin on multisite.
	$back = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
	wp_safe_redirect( add_query_arg( 'mdampf_update_check', $result, $back ) );
	exit;
}
add_action( 'admin_post_mdampf_check_updates', 'mdampf_upd_check_now' );

/**
 * Result notice after "Check for updates".
 */
function mdampf_upd_notice() {
	if ( ! isset( $_GET['mdampf_update_check'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$result = sanitize_key( wp_unslash( $_GET['mdampf_update_check'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$info   = mdampf_upd_info();
	$all    = get_site_transient( MDAMPF_UPD_CACHE );

	switch ( $result ) {
		case 'available':
			$type = 'warning';
			/* translators: %s: version number. */
			$msg = sprintf( __( 'MDA Media Player Fit %s is available. Use "update now" below.', 'mda-media-player-fit' ), null !== $info ? $info['version'] : '' );
			break;
		case 'current':
			$type = 'success';
			/* translators: %s: version number. */
			$msg = sprintf( __( 'MDA Media Player Fit is up to date (%s).', 'mda-media-player-fit' ), MDAMPF_VERSION );
			break;
		case 'none':
			$type = 'info';
			$msg  = __( 'MDA Media Player Fit: no releases found on GitHub yet.', 'mda-media-player-fit' );
			break;
		default:
			$type = 'error';
			$msg  = __( 'MDA Media Player Fit could not check GitHub for updates.', 'mda-media-player-fit' );
			if ( is_array( $all ) && ! empty( $all['error'] ) ) {
				$msg .= ' ' . $all['error'];
			}
	}
	printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $msg ) );
}
add_action( 'admin_notices', 'mdampf_upd_notice' );
add_action( 'network_admin_notices', 'mdampf_upd_notice' );
