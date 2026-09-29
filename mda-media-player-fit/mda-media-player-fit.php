<?php
/**
 * Plugin Name:       MDA Media Player Fit
 * Description:       Fits the MD Anderson media player and other embedded videos to 100% of their container width at a fixed aspect ratio (16:9 by default) by updating the iframe width and height attributes. Also adds a fallback iframe title for accessibility.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.0
 * Author:            Montez French | Senior Web Developer at MD Anderson
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mda-media-player-fit
 * Update URI:        https://github.com/mda-dsladmin/wp-plugins/tree/main/mda-media-player-fit
 *
 * @package MDAMediaPlayerFit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDAMPF_VERSION', '1.1.0' );
define( 'MDAMPF_FILE', __FILE__ );
define( 'MDAMPF_OPTION', 'mdampf_settings' );

require_once __DIR__ . '/includes/updater.php';

/**
 * Default settings.
 *
 * @return array
 */
function mdampf_defaults() {
	return array(
		'selector'       => 'iframe[src*="youtube.com"], iframe[src*="youtube-nocookie.com"], iframe[src*="vimeo.com"], iframe[src*="mediaplayer.mdanderson.org"]',
		'ratio_w'        => 16,
		'ratio_h'        => 9,
		'fallback_title' => 'Embedded video',
		'mda_player'     => 1,
	);
}

/**
 * Saved settings merged over the defaults.
 *
 * @return array
 */
function mdampf_get_settings() {
	$saved = get_option( MDAMPF_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, mdampf_defaults() );
}

/*
 * ------------------------------------------------------------------
 * Front end
 * ------------------------------------------------------------------
 */

/**
 * Load the script and pass it the settings.
 */
function mdampf_enqueue_script() {
	/**
	 * Filter: return false to turn the script off (for example on certain pages).
	 */
	if ( ! apply_filters( 'mda_media_player_fit_enabled', true ) ) {
		return;
	}

	$settings = mdampf_get_settings();

	$config = array(
		'selector'      => (string) $settings['selector'],
		'ratioW'        => (int) $settings['ratio_w'],
		'ratioH'        => (int) $settings['ratio_h'],
		'fallbackTitle' => (string) $settings['fallback_title'],
		'mdaPlayer'     => ! empty( $settings['mda_player'] ),
	);

	/**
	 * Filter: change the settings passed to the script.
	 */
	$config = apply_filters( 'mda_media_player_fit_config', $config );

	wp_enqueue_script(
		'mda-media-player-fit',
		plugins_url( 'assets/js/mda-media-player-fit.js', __FILE__ ),
		array(),
		MDAMPF_VERSION,
		true
	);

	wp_add_inline_script(
		'mda-media-player-fit',
		'window.mdaMediaPlayerFit = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'mdampf_enqueue_script' );

/*
 * ------------------------------------------------------------------
 * Settings page: Settings > MDA Media Player Fit
 * ------------------------------------------------------------------
 */

/**
 * Add the settings page.
 */
function mdampf_add_settings_page() {
	add_options_page(
		__( 'MDA Media Player Fit', 'mda-media-player-fit' ),
		__( 'MDA Media Player Fit', 'mda-media-player-fit' ),
		'manage_options',
		'mda-media-player-fit',
		'mdampf_render_settings_page'
	);
}
add_action( 'admin_menu', 'mdampf_add_settings_page' );

/**
 * Register the setting and its fields.
 */
function mdampf_register_settings() {
	register_setting(
		'mdampf_settings_group',
		MDAMPF_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'mdampf_sanitize_settings',
			'default'           => mdampf_defaults(),
		)
	);

	add_settings_section( 'mdampf_main', '', '__return_false', 'mda-media-player-fit' );

	add_settings_field( 'mdampf_selector', __( 'Which iframes', 'mda-media-player-fit' ), 'mdampf_field_selector', 'mda-media-player-fit', 'mdampf_main', array( 'label_for' => 'mdampf_selector' ) );
	add_settings_field( 'mdampf_ratio', __( 'Aspect ratio', 'mda-media-player-fit' ), 'mdampf_field_ratio', 'mda-media-player-fit', 'mdampf_main' );
	add_settings_field( 'mdampf_fallback_title', __( 'Fallback iframe title', 'mda-media-player-fit' ), 'mdampf_field_fallback_title', 'mda-media-player-fit', 'mdampf_main', array( 'label_for' => 'mdampf_fallback_title' ) );
	add_settings_field( 'mdampf_mda_player', __( 'MD Anderson player', 'mda-media-player-fit' ), 'mdampf_field_mda_player', 'mda-media-player-fit', 'mdampf_main' );
}
add_action( 'admin_init', 'mdampf_register_settings' );

/**
 * Clean the submitted settings.
 *
 * @param mixed $input Raw form input.
 * @return array
 */
function mdampf_sanitize_settings( $input ) {
	$defaults = mdampf_defaults();
	$input    = is_array( $input ) ? $input : array();
	$clean    = array();

	// options.php has already unslashed these values, so no wp_unslash() here.

	// Selector: plain text, no tags and no "<". A ">" is kept because it is
	// real CSS ("child of"); removing it would change what gets matched.
	// Empty falls back to the default.
	$selector          = isset( $input['selector'] ) && is_string( $input['selector'] ) ? sanitize_text_field( $input['selector'] ) : '';
	$selector          = mb_substr( str_replace( '<', '', $selector ), 0, 500 );
	$clean['selector'] = ( '' !== trim( $selector ) ) ? $selector : $defaults['selector'];

	// Ratio: whole numbers from 1 to 100. Anything else (including negatives) falls back to the default.
	foreach ( array( 'ratio_w', 'ratio_h' ) as $key ) {
		$num           = isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? (int) $input[ $key ] : 0;
		$clean[ $key ] = ( $num >= 1 && $num <= 100 ) ? $num : $defaults[ $key ];
	}

	// Fallback title: plain text. Empty turns the feature off. Angle brackets
	// are removed (not encoded), because the title is set as a plain
	// attribute and screen readers would otherwise read "&lt;" aloud.
	$title                   = isset( $input['fallback_title'] ) && is_string( $input['fallback_title'] ) ? str_replace( array( '<', '>' ), '', $input['fallback_title'] ) : '';
	$clean['fallback_title'] = mb_substr( sanitize_text_field( $title ), 0, 200 ); // Characters, not bytes: never cuts a letter in half.

	// MD Anderson player fix: on or off.
	$clean['mda_player'] = empty( $input['mda_player'] ) ? 0 : 1;

	return $clean;
}

/**
 * Field: selector.
 */
function mdampf_field_selector() {
	$s = mdampf_get_settings();
	printf(
		'<textarea id="mdampf_selector" name="%1$s[selector]" rows="3" class="large-text code">%2$s</textarea>',
		esc_attr( MDAMPF_OPTION ),
		esc_textarea( $s['selector'] )
	);
	echo '<p class="description">' . esc_html__( 'CSS selector for the iframes to resize. The default covers YouTube, Vimeo, and the MD Anderson media player. Use "iframe" only if the site has no other iframes (maps, forms, reCAPTCHA), since every match is stretched to full width.', 'mda-media-player-fit' ) . '</p>';
}

/**
 * Field: aspect ratio.
 */
function mdampf_field_ratio() {
	$s = mdampf_get_settings();
	printf(
		'<label><span class="screen-reader-text">%1$s</span><input type="number" min="1" max="100" step="1" name="%2$s[ratio_w]" value="%3$s" class="small-text"></label> : <label><span class="screen-reader-text">%4$s</span><input type="number" min="1" max="100" step="1" name="%2$s[ratio_h]" value="%5$s" class="small-text"></label>',
		esc_html__( 'Ratio width', 'mda-media-player-fit' ),
		esc_attr( MDAMPF_OPTION ),
		esc_attr( $s['ratio_w'] ),
		esc_html__( 'Ratio height', 'mda-media-player-fit' ),
		esc_attr( $s['ratio_h'] )
	);
	echo '<p class="description">' . esc_html__( 'Width : height. 16 : 9 is standard widescreen video.', 'mda-media-player-fit' ) . '</p>';
}

/**
 * Field: fallback title.
 */
function mdampf_field_fallback_title() {
	$s = mdampf_get_settings();
	printf(
		'<input type="text" id="mdampf_fallback_title" name="%1$s[fallback_title]" value="%2$s" class="regular-text">',
		esc_attr( MDAMPF_OPTION ),
		esc_attr( $s['fallback_title'] )
	);
	echo '<p class="description">' . esc_html__( 'Added only to matched iframes that have no title, so screen readers can name them (WCAG 4.1.2). Titles set in the content are never changed. A specific title per video is still better. Leave empty to turn this off.', 'mda-media-player-fit' ) . '</p>';
}

/**
 * Field: MD Anderson player fix.
 */
function mdampf_field_mda_player() {
	$s = mdampf_get_settings();
	printf(
		'<label><input type="checkbox" name="%1$s[mda_player]" value="1"%2$s> %3$s</label>',
		esc_attr( MDAMPF_OPTION ),
		checked( ! empty( $s['mda_player'] ), true, false ),
		esc_html__( 'Fit the MD Anderson media player (mediaplayer.mdanderson.org)', 'mda-media-player-fit' )
	);
	echo '<p class="description">' . esc_html__( 'That player sets its height from the number at the end of its URL and will not go below 220px tall. This keeps the number in step with the frame, and on narrow screens (under about 391px) lets the frame grow to 220px tall instead of cutting off the controls. Changing the URL reloads the player, so it only happens on page load and after a resize stops.', 'mda-media-player-fit' ) . '</p>';
}

/**
 * Render the settings page.
 */
function mdampf_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
	echo '<form action="options.php" method="post">';
	settings_fields( 'mdampf_settings_group' );
	do_settings_sections( 'mda-media-player-fit' );
	submit_button();
	echo '</form></div>';
}

/**
 * "Settings" link on the Plugins screen.
 *
 * @param array $links Existing links.
 * @return array
 */
function mdampf_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=mda-media-player-fit' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mda-media-player-fit' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mdampf_action_links' );
