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
	 * Registers the bundled translations directory for the plugin text domain.
	 *
	 * This must be hooked on `init` (see ExeLearning::load_i18n()), never
	 * earlier: WordPress 6.7+ emits a `_doing_it_wrong()` notice when a text
	 * domain is loaded before `init`.
	 *
	 * WordPress just-in-time translation loading only consults the plugin's own
	 * `languages` directory when that directory has been registered as a custom
	 * path for the domain. For plugins distributed outside WordPress.org this was
	 * generalized in WordPress 6.8; on WordPress 6.1–6.7 (and whenever the
	 * checkout directory does not match the text domain, e.g. a `wp-exelearning`
	 * development clone) the bundled `.mo`/`.l10n.php` files are otherwise never
	 * found. Calling `load_plugin_textdomain()` registers that custom path in the
	 * text domain registry, so the fix is required on 6.1–6.7 and harmless (a
	 * redundant, idempotent registration) on 6.8+.
	 *
	 * The path is derived from the plugin file so no directory name is
	 * hard-coded, and language packs installed under `WP_LANG_DIR/plugins`
	 * keep priority because `load_plugin_textdomain()` looks there first.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'exelearning',
			false,
			$this->languages_rel_path()
		);
	}

	/**
	 * Builds the plugin-relative path to the bundled translations directory.
	 *
	 * Derived from EXELEARNING_PLUGIN_FILE via plugin_basename() so it stays
	 * correct regardless of the folder the plugin is checked out or installed
	 * into.
	 *
	 * @return string Path to the languages directory, relative to WP_PLUGIN_DIR.
	 */
	public function languages_rel_path() {
		return dirname( plugin_basename( EXELEARNING_PLUGIN_FILE ) ) . '/languages';
	}
}
