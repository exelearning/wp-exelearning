<?php
/**
 * Tests for ExeLearning_Activator and ExeLearning_Deactivator classes.
 *
 * @package Exelearning
 */

/**
 * Class ActivatorDeactivatorTest.
 *
 * @covers ExeLearning_Activator
 * @covers ExeLearning_Deactivator
 */
class ActivatorDeactivatorTest extends WP_UnitTestCase {

	/**
	 * Test Activator activate method exists.
	 */
	public function test_activator_activate_exists() {
		$this->assertTrue( method_exists( ExeLearning_Activator::class, 'activate' ) );
	}

	/**
	 * Test Activator activate is static.
	 */
	public function test_activator_activate_is_static() {
		$method = new ReflectionMethod( ExeLearning_Activator::class, 'activate' );
		$this->assertTrue( $method->isStatic() );
	}

	/**
	 * Test Activator activate can be called.
	 */
	public function test_activator_activate_callable() {
		ExeLearning_Activator::activate();
		$this->assertTrue( true );
	}

	/**
	 * Test Deactivator deactivate method exists.
	 */
	public function test_deactivator_deactivate_exists() {
		$this->assertTrue( method_exists( ExeLearning_Deactivator::class, 'deactivate' ) );
	}

	/**
	 * Test Deactivator deactivate is static.
	 */
	public function test_deactivator_deactivate_is_static() {
		$method = new ReflectionMethod( ExeLearning_Deactivator::class, 'deactivate' );
		$this->assertTrue( $method->isStatic() );
	}

	/**
	 * Test Deactivator deactivate can be called.
	 */
	public function test_deactivator_deactivate_callable() {
		ExeLearning_Deactivator::deactivate();
		$this->assertTrue( true );
	}
}
