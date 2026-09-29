<?php
/**
 * Tests for uninstall.php.
 *
 * @package Exelearning
 */

/**
 * Class UninstallTest.
 *
 * @coversNothing
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Uninstalling removes every plugin option and leaves other options alone.
	 */
	public function test_uninstall_deletes_plugin_options() {
		$options = array(
			ExeLearning_Upgrader::OPTION_DB_VERSION,
			ExeLearning_Styles_Service::OPTION_DISABLED_STYLES,
			ExeLearning_Content_Proxy::OPTION_PROXY_ASSETS,
			ExeLearning_Styles_Service::OPTION_BLOCK_IMPORT,
			ExeLearning_Styles_Service::OPTION_REGISTRY,
			ExeLearning_Iframe_Sandbox::OPTION,
			ExeLearning_Iframe_Sandbox::EMBED_OPTION,
		);
		foreach ( $options as $option ) {
			update_option( $option, 'x' );
		}
		update_option( 'blogname', 'Kept' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'exelearning/exelearning.php' );
		}
		include EXELEARNING_PLUGIN_DIR . 'uninstall.php';

		foreach ( $options as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' survived uninstall.' );
		}
		$this->assertSame( 'Kept', get_option( 'blogname' ) );
	}
}
