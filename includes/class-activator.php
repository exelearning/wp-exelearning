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
		// Activation code goes here.
		// For instance, flush rewrite rules if needed.
		// Example: flush_rewrite_rules().
	}
}
