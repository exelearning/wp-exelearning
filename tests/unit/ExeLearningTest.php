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
	 * Test hooks property is initialized.
	 */
	public function test_hooks_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'hooks' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_Hooks::class, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test filters property is initialized.
	 */
	public function test_filters_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'filters' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_Filters::class, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test mime_types property is initialized.
	 */
	public function test_mime_types_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'mime_types' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_Mime_Types::class, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test shortcodes property is initialized.
	 */
	public function test_shortcodes_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'shortcodes' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_Shortcodes::class, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test i18n property is initialized.
	 */
	public function test_i18n_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'i18n' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_I18n::class, $property->getValue( $this->plugin ) );
	}

	/**
	 * Test rest_api property is initialized.
	 */
	public function test_rest_api_initialized() {
		$property = new ReflectionProperty( ExeLearning::class, 'rest_api' );
		$property->setAccessible( true );

		$this->assertInstanceOf( ExeLearning_REST_API::class, $property->getValue( $this->plugin ) );
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
