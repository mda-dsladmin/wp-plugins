<?php
/**
 * Remove the plugin's settings when it is deleted from the Plugins screen.
 *
 * @package MDAMediaPlayerFit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mdampf_site_id ) {
		switch_to_blog( $mdampf_site_id );
		delete_option( 'mdampf_settings' );
		restore_current_blog();
	}
} else {
	delete_option( 'mdampf_settings' );
}
