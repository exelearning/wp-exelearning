<?php
/**
 * Tests for ExeLearning_Elp_List_Table class.
 *
 * @package Exelearning
 */

/**
 * Class ElpListTableTest.
 *
 * @covers ExeLearning_Elp_List_Table
 */
class ElpListTableTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Elp_List_Table
	 */
	private $list_table;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// Set up screen to avoid errors.
		set_current_screen( 'upload' );

		$this->list_table = new ExeLearning_Elp_List_Table();
	}

	/**
	 * Test get_columns returns expected columns.
	 */
	public function test_get_columns() {
		$columns = $this->list_table->get_columns();

		$this->assertIsArray( $columns );
		$this->assertArrayHasKey( 'cb', $columns );
		$this->assertArrayHasKey( 'title', $columns );
		$this->assertArrayHasKey( 'date', $columns );
		$this->assertArrayHasKey( 'status', $columns );
	}

	/**
	 * Test prepare_items sets items.
	 */
	public function test_prepare_items() {
		$this->list_table->prepare_items();

		$this->assertNotEmpty( $this->list_table->items );
		$this->assertCount( 2, $this->list_table->items );
	}

	/**
	 * Test prepare_items sets column headers.
	 */
	public function test_prepare_items_sets_headers() {
		$this->list_table->prepare_items();

		$headers = $this->list_table->get_column_info();

		$this->assertIsArray( $headers );
		$this->assertCount( 4, $headers );
	}

	/**
	 * Test column_cb renders checkbox.
	 */
	public function test_column_cb() {
		$method = new ReflectionMethod( ExeLearning_Elp_List_Table::class, 'column_cb' );
		$method->setAccessible( true );

		$item   = array( 'ID' => 123 );
		$result = $method->invoke( $this->list_table, $item );

		$this->assertStringContainsString( '<input', $result );
		$this->assertStringContainsString( 'type="checkbox"', $result );
		$this->assertStringContainsString( 'value="123"', $result );
	}

	/**
	 * Test column_title renders title with actions.
	 */
	public function test_column_title() {
		$method = new ReflectionMethod( ExeLearning_Elp_List_Table::class, 'column_title' );
		$method->setAccessible( true );

		$item   = array(
			'ID'    => 1,
			'title' => 'Test File',
		);
		$result = $method->invoke( $this->list_table, $item );

		$this->assertStringContainsString( 'Test File', $result );
		$this->assertStringContainsString( '<strong>', $result );
		$this->assertStringContainsString( 'row-actions', $result );
		$this->assertStringContainsString( 'edit', $result );
		$this->assertStringContainsString( 'delete', $result );
	}

	/**
	 * Test column_default returns column value.
	 */
	public function test_column_default() {
		$method = new ReflectionMethod( ExeLearning_Elp_List_Table::class, 'column_default' );
		$method->setAccessible( true );

		$item   = array(
			'date'   => '2025-01-01',
			'status' => 'Active',
		);
		$result = $method->invoke( $this->list_table, $item, 'date' );

		$this->assertEquals( '2025-01-01', $result );
	}

	/**
	 * Test column_default returns empty for unknown column.
	 */
	public function test_column_default_unknown() {
		$method = new ReflectionMethod( ExeLearning_Elp_List_Table::class, 'column_default' );
		$method->setAccessible( true );

		$item   = array( 'date' => '2025-01-01' );
		$result = $method->invoke( $this->list_table, $item, 'unknown' );

		$this->assertEquals( '', $result );
	}

	/**
	 * Test class extends WP_List_Table.
	 */
	public function test_extends_wp_list_table() {
		$this->assertInstanceOf( WP_List_Table::class, $this->list_table );
	}
}
