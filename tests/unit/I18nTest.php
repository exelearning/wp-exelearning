<?php
/**
 * Tests for ExeLearning_I18n class.
 *
 * @package Exelearning
 */

/**
 * Class I18nTest.
 *
 * @covers ExeLearning_I18n
 */
class I18nTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_I18n
	 */
	private $i18n;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->i18n = new ExeLearning_I18n();
	}

	/**
	 * Test load_textdomain method exists.
	 */
	public function test_load_textdomain_exists() {
		$this->assertTrue( method_exists( $this->i18n, 'load_textdomain' ) );
	}

	/**
	 * Test load_textdomain can be called without error.
	 */
	public function test_load_textdomain_callable() {
		// Should not throw any errors.
		$this->i18n->load_textdomain();
		$this->assertTrue( true );
	}
}
