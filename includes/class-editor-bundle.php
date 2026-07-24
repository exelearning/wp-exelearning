<?php
/**
 * Bundled static editor helper.
 *
 * The embedded eXeLearning editor is a release artifact: official release
 * packages ship it pre-built under dist/static/, and that bundle is the only
 * editor source the plugin ever uses. There is no runtime install or update
 * path. See ADR-0002.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Editor_Bundle.
 *
 * Read-only view of the editor bundled with the plugin package. When the
 * bundle is missing (a source checkout without `make build-editor`) or
 * invalid, embedded editing is disabled.
 */
class ExeLearning_Editor_Bundle {

	/**
	 * Asset directories, at least one of which a valid bundle must contain.
	 *
	 * @var string[]
	 */
	const ASSET_DIRS = array( 'app', 'libs', 'files' );

	/**
	 * Get the bundled editor directory path.
	 *
	 * @return string Path to dist/static/ with a trailing slash.
	 */
	public static function get_path() {
		return trailingslashit( static::get_plugin_dir() ) . 'dist/static/';
	}

	/**
	 * Whether a valid editor bundle is available.
	 *
	 * Requires a readable index.html plus at least one of the expected asset
	 * directories, so a stray or truncated dist/static/ is rejected.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$path = static::get_path();
		if ( ! is_readable( $path . 'index.html' ) ) {
			return false;
		}
		foreach ( self::ASSET_DIRS as $dir ) {
			if ( is_dir( $path . $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Base plugin directory holding dist/static/.
	 *
	 * Resolved through late static binding so tests can point the helper at a
	 * fixture directory by subclassing.
	 *
	 * @return string
	 */
	protected static function get_plugin_dir() {
		return EXELEARNING_PLUGIN_DIR;
	}
}
