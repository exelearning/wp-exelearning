<?php
/**
 * Filesystem helpers shared by the plugin.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Filesystem.
 */
class ExeLearning_Filesystem {

	/**
	 * Recursively delete a directory. Safe to call on a missing path.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( array_diff( $items, array( '.', '..' ) ) as $item ) {
			self::recursive_delete( $dir . DIRECTORY_SEPARATOR . $item );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
