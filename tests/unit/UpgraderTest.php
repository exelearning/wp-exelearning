<?php
/**
 * Tests for ExeLearning_Upgrader.
 *
 * @package Exelearning
 */

/**
 * Class UpgraderTest.
 *
 * @covers ExeLearning_Upgrader
 */
class UpgraderTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->reset_options();
	}

	public function tear_down() {
		$this->reset_options();
		parent::tear_down();
	}

	/**
	 * Remove every option the upgrader reads or writes.
	 */
	private function reset_options() {
		delete_option( ExeLearning_Upgrader::OPTION_DB_VERSION );
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		delete_option( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES );
	}

	/**
	 * A fresh install migrates to an empty disabled list and stamps the
	 * DB version.
	 */
	public function test_fresh_install_stamps_version() {
		ExeLearning_Upgrader::maybe_upgrade();

		$this->assertSame( ExeLearning_Upgrader::DB_VERSION, (int) get_option( ExeLearning_Upgrader::OPTION_DB_VERSION ) );
		$this->assertSame( array(), ExeLearning_Styles_Service::get_disabled_styles() );
	}

	/**
	 * Legacy state — disabled_builtins in the registry plus per-upload
	 * enabled flags — is folded into the flat disabled-styles option.
	 */
	public function test_migrates_legacy_registry_state() {
		update_option(
			ExeLearning_Styles_Service::OPTION_REGISTRY,
			array(
				'uploaded'          => array(
					'acme' => array(
						'title'   => 'Acme',
						'enabled' => false,
					),
					'keep' => array(
						'title'   => 'Keep',
						'enabled' => true,
					),
				),
				'disabled_builtins' => array( 'zen', 'flux' ),
			),
			false
		);

		ExeLearning_Upgrader::maybe_upgrade();

		$this->assertSame( array( 'acme', 'flux', 'zen' ), ExeLearning_Styles_Service::get_disabled_styles() );
		$this->assertTrue( ExeLearning_Styles_Service::is_style_disabled( 'acme' ) );
		$this->assertFalse( ExeLearning_Styles_Service::is_style_disabled( 'keep' ) );
	}

	/**
	 * The migration runs once: later registry edits in the legacy shape are
	 * not re-imported after the version stamp is in place.
	 */
	public function test_upgrade_is_idempotent() {
		ExeLearning_Upgrader::maybe_upgrade();

		update_option(
			ExeLearning_Styles_Service::OPTION_REGISTRY,
			array(
				'uploaded'          => array(),
				'disabled_builtins' => array( 'zen' ),
			),
			false
		);

		ExeLearning_Upgrader::maybe_upgrade();

		$this->assertSame( array(), ExeLearning_Styles_Service::get_disabled_styles() );
	}

	/**
	 * DB version 2 drops the removed runtime editor-installer state
	 * (ADR-0002): the metadata option and the concurrency transient.
	 */
	public function test_removes_editor_installer_state() {
		update_option( 'exelearning_static_editor', array( 'version' => '4.0.0' ) );
		set_transient( 'exelearning_installing_editor', true, 300 );

		ExeLearning_Upgrader::maybe_upgrade();

		$this->assertFalse( get_option( 'exelearning_static_editor' ) );
		$this->assertFalse( get_transient( 'exelearning_installing_editor' ) );
	}

	/**
	 * The upgrader is wired into admin_init by the plugin bootstrap.
	 */
	public function test_upgrader_hooked_on_admin_init() {
		$this->assertNotFalse( has_action( 'admin_init', array( 'ExeLearning_Upgrader', 'maybe_upgrade' ) ) );
	}
}
