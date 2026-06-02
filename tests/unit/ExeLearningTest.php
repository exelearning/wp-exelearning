<?php
/**
 * Tests for ExeLearning main class.
 *
 * @package Exelearning
 */

/**
 * Class ExeLearningTest.
 *
 * @covers ExeLearning
 */
class ExeLearningTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning
	 */
	private $plugin;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin = new ExeLearning();
	}

	/**
	 * Test plugin instantiation.
	 */
	public function test_plugin_instantiation() {
		$this->assertInstanceOf( ExeLearning::class, $this->plugin );
	}

	/**
	 * Test run method exists.
	 */
	public function test_run_method_exists() {
		$this->assertTrue( method_exists( $this->plugin, 'run' ) );
	}

	/**
	 * Test run method can be called.
	 */
	public function test_run_method_callable() {
		$this->plugin->run();
		$this->assertTrue( true );
	}

	/**
	 * Read an instantiated component from the plugin's component registry.
	 *
	 * @param string $key Component key.
	 * @return object|null
	 */
	private function get_component( $key ) {
		$property = new ReflectionProperty( ExeLearning::class, 'components' );
		$property->setAccessible( true );
		$components = $property->getValue( $this->plugin );
		return isset( $components[ $key ] ) ? $components[ $key ] : null;
	}

	/**
	 * Test hooks component is initialized.
	 */
	public function test_hooks_initialized() {
		$this->assertInstanceOf( ExeLearning_Hooks::class, $this->get_component( 'hooks' ) );
	}

	/**
	 * Test filters component is initialized.
	 */
	public function test_filters_initialized() {
		$this->assertInstanceOf( ExeLearning_Filters::class, $this->get_component( 'filters' ) );
	}

	/**
	 * Test mime_types component is initialized.
	 */
	public function test_mime_types_initialized() {
		$this->assertInstanceOf( ExeLearning_Mime_Types::class, $this->get_component( 'mime_types' ) );
	}

	/**
	 * Test shortcodes component is initialized.
	 */
	public function test_shortcodes_initialized() {
		$this->assertInstanceOf( ExeLearning_Shortcodes::class, $this->get_component( 'shortcodes' ) );
	}

	/**
	 * Test i18n component is initialized.
	 */
	public function test_i18n_initialized() {
		$this->assertInstanceOf( ExeLearning_I18n::class, $this->get_component( 'i18n' ) );
	}

	/**
	 * Test rest_api component is initialized.
	 */
	public function test_rest_api_initialized() {
		$this->assertInstanceOf( ExeLearning_REST_API::class, $this->get_component( 'rest_api' ) );
	}

	/**
	 * Test version property is set.
	 */
	public function test_version_is_set() {
		$property = new ReflectionProperty( ExeLearning::class, 'version' );
		$property->setAccessible( true );

		$this->assertEquals( EXELEARNING_VERSION, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test init_components is private.
	 */
	public function test_init_components_is_private() {
		$method = new ReflectionMethod( ExeLearning::class, 'init_components' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test setup_hooks is private.
	 */
	public function test_setup_hooks_is_private() {
		$method = new ReflectionMethod( ExeLearning::class, 'setup_hooks' );
		$this->assertTrue( $method->isPrivate() );
	}

	/**
	 * Test load_i18n is private.
	 */
	public function test_load_i18n_is_private() {
		$method = new ReflectionMethod( ExeLearning::class, 'load_i18n' );
		$this->assertTrue( $method->isPrivate() );
	}
}
