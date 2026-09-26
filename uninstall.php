<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes the plugin's settings. Uploaded .elpx attachments, their extracted
 * content and uploaded style packages are user content and are left in place.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    exelearning
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options on the current site.
 */
function exelearning_uninstall_site() {
	$options = array(
		'exelearning_db_version',
		'exelearning_disabled_styles',
		'exelearning_proxy_assets',
		'exelearning_styles_block_import',
		'exelearning_styles_registry',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $exelearning_site_id ) {
		switch_to_blog( $exelearning_site_id );
		exelearning_uninstall_site();
		restore_current_blog();
	}
} else {
	exelearning_uninstall_site();
}
