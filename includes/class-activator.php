<?php
/**
 * Fired during plugin activation.
 *
 * This class defines all code to run during the plugin's activation.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Activator.
 *
 * Handles plugin activation tasks.
 */
class ExeLearning_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		// The opaque snapshot preview store reclaims expired capabilities lazily
		// on each management/serving request, so there is no activation-time
		// WP-Cron event to schedule.
	}
}
