=== MDA Media Player Fit ===
Contributors: montezfrench
Tags: video, iframe, responsive, md anderson, accessibility
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fits the MD Anderson media player and other embedded videos to 100% of their container width at a fixed aspect ratio.

== Description ==

Some video players do not resize well with CSS tricks. MDA Media Player Fit sets each matched iframe's `width` and `height` attributes to 100% of its container width at a fixed ratio (16:9 by default), and keeps them updated as the layout changes.

* Resizes on window resize, and when a hidden iframe is shown (tabs, accordions).
* Picks up iframes added after the page loads.
* MD Anderson media player fix: that player sets its height from the number at the end of its URL, so the plugin keeps that number in step with the frame.
* Optional fallback `title` for matched iframes that have none (WCAG 4.1.2). Existing titles are never changed.

Settings live under Settings > MDA Media Player Fit.

== Installation ==

1. Upload the `mda-media-player-fit` folder to `/wp-content/plugins/`, or upload the zip under Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Check Settings > MDA Media Player Fit and adjust the selector if needed.

== Updates ==

The plugin updates itself from GitHub Releases in github.com/mda-dsladmin/wp-plugins. WordPress shows the normal "update now" notice when a newer release exists, and auto-updates work too. Checks are cached for 12 hours; "Check for updates" under the plugin on the Plugins screen checks right away. To install updates without clicking, turn on "Enable auto-updates" for the plugin on the Plugins screen.

Sites running 1.0.x need 1.1.0 installed once by hand (Plugins > Add New > Upload Plugin, then "Replace current with uploaded"). From then on they update from GitHub.

The server must be able to reach api.github.com, github.com and release-assets.githubusercontent.com over HTTPS.

No token is needed (the repo is public). On busy shared hosting, GitHub's limit of 60 anonymous checks per hour per server IP can be reached; add a read-only token to wp-config.php to raise it:

`define( 'MDA_WP_PLUGINS_GITHUB_TOKEN', 'github_pat_...' );`

== Frequently Asked Questions ==

= Which iframes does it touch? =

By default: YouTube, YouTube no-cookie, Vimeo, and the MD Anderson media player. Change the CSS selector in settings. Avoid a bare "iframe" selector on sites with maps, forms, or reCAPTCHA, since every match is stretched to full width.

= The size does not change. =

Width and height attributes have the lowest priority. If a CSS rule sets width or height on the iframe, that rule wins. A theme's fixed max-width (for example 500px) is respected, and the height follows it. The only style the plugin sets is `max-width: 100%` (when no inline max-width is set), so a frame never holds a table cell or flex box open.

= Can I skip one iframe? =

Add `data-mdampf-skip` to that iframe.

= Can I turn it off on some pages? =

Yes, with the `mda_media_player_fit_enabled` filter:

`add_filter( 'mda_media_player_fit_enabled', function ( $on ) { return is_page( 'contact' ) ? false : $on; } );`

The `mda_media_player_fit_config` filter changes the settings passed to the script.

= Why is the MD Anderson video a little taller than 16:9 on phones? =

That player will not go below 220px tall. On screens narrower than about 391px, a true 16:9 frame would be shorter than that and cut off the controls, so the frame stays 220px tall and the video shows small bars above and below.

= Why does an MD Anderson video restart when I rotate my phone? =

That player only reads its size when it loads, so a width change has to reload it. Reloads wait until resizing stops and never happen in fullscreen.

== Changelog ==

= 1.1.0 =
* Updates from GitHub Releases, with a "Check for updates" link on the Plugins screen.
* Frames now fit exactly: the iframe's own border, padding and margins are taken into account, and a theme's fixed max-width is respected.
* Fix: in table cells and some grid or flex layouts the frame could keep growing, or never shrink. Both are fixed.
* The selector setting keeps ">" (child selectors work), and long titles are cut on a letter boundary.
* A failed update check (for example a GitHub rate limit) no longer hides an update that was already found.

= 1.0.1 =
* Fix: MD Anderson player cut off on phones. The frame now respects the player's 220px minimum height, and the URL number is capped at the frame width.

= 1.0.0 =
* First release.
