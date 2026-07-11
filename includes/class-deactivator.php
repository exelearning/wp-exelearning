<?php
/**
 * Fired during plugin deactivation.
 *
 * This class defines all code to run during the plugin's deactivation.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Deactivator.
 *
 * Handles plugin deactivation tasks.
 */
class ExeLearning_Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ExeLearning_Preview_Proxy::CRON_HOOK );
	}
}
