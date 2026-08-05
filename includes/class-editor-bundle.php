<?php
/**
 * Bundled static editor helper.
 *
 * The embedded eXeLearning editor is a release artifact: official release
 * packages ship it pre-built under dist/static/, and that bundle is the only
 * editor source the plugin ever uses. There is no runtime install or update
 * path. See ADR-72-01.
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
	 * Directory standing in for the plugin directory under PHPUnit.
	 *
	 * @internal Test seam. Not part of the plugin's public API.
	 * @var string|null
	 */
	private static $path_override = null;

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
	 * Point the helper at a fixture bundle for the duration of a test.
	 *
	 * Every path the plugin resolves for the embedded editor goes through
	 * {@see self::get_path()}, and in a source checkout dist/static/ is absent
	 * while on a developer machine it holds a full editor build. Tests that
	 * exercise the bundled-editor paths would therefore assert against
	 * whatever the machine happens to have, so they need to supply their own.
	 *
	 * This is not a way to relocate the editor: ADR-72-01 makes dist/static/
	 * inside the plugin directory the only runtime editor source, and the
	 * guard below makes the setter a no-op outside the PHPUnit suite.
	 *
	 * @internal Test seam. Not part of the plugin's public API.
	 *
	 * @param string|null $dir Fixture directory holding dist/static/, or null to reset.
	 * @return void
	 */
	public static function set_path_override( $dir ) {
		if ( ! defined( 'WP_TESTS_DOMAIN' ) ) {
			return;
		}
		self::$path_override = null === $dir ? null : (string) $dir;
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
		if ( null !== self::$path_override ) {
			return self::$path_override;
		}
		return EXELEARNING_PLUGIN_DIR;
	}
}
