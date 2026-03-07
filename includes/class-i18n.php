<?php
/**
 * Internationalization class.
 *
 * Loads plugin text domain for translation.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_I18n.
 *
 * Manages loading of plugin text domain.
 */
class ExeLearning_I18n {

	/**
	 * Loads the plugin text domain for translation.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'exelearning',
			false,
			dirname( plugin_basename( EXELEARNING_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
