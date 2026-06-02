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
	 *
	 * No-op: the distributed plugin folder is `exelearning` (matching the
	 * text domain), so WordPress 4.6+ just-in-time loading picks up the
	 * bundled translations on its own. In this development repo the folder
	 * is `wp-exelearning`, which means strings appear untranslated locally;
	 * that does not affect the packaged release. The method is kept to
	 * preserve the hook contract registered in ExeLearning::load_i18n().
	 */
	public function load_textdomain() {
	}
}
