<?php
/**
 * List table for eXeLearning files.
 *
 * This class defines the list table for displaying eXeLearning files in the backend.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

// Include WP_List_Table if not already included.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ExeLearning_Elp_List_Table.
 *
 * Displays a list table of eXeLearning files.
 */
class ExeLearning_Elp_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param array $args Arguments for the list table.
	 */
	public function __construct( $args = array() ) {
		parent::__construct(
			array(
				'singular' => 'exelearning_file',
				'plural'   => 'exelearning_files',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns the list table columns.
	 *
	 * @return array List of columns.
	 */
	public function get_columns() {
		$columns = array(
			'cb'     => '<input type="checkbox" />',
			'title'  => __( 'Title', 'exelearning' ),
			'date'   => __( 'Date', 'exelearning' ),
			'status' => __( 'Status', 'exelearning' ),
		);
		return $columns;
	}

	/**
	 * Prepares the list of items.
	 */
	public function prepare_items() {
		// Static data for demonstration. Replace with data from the database.
		$data = array(
			array(
				'ID'     => 1,
				'title'  => 'Example File 1',
				'date'   => '2025-01-01',
				'status' => 'Active',
			),
			array(
				'ID'     => 2,
				'title'  => 'Example File 2',
				'date'   => '2025-01-02',
				'status' => 'Blocked',
			),
		);

		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = array();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$this->items = $data;
	}

	/**
	 * Renders the checkbox column.
	 *
	 * @param array $item Current item.
	 * @return string HTML for the checkbox.
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="file[]" value="%s" />',
			esc_attr( $item['ID'] )
		);
	}

	/**
	 * Renders the title column.
	 *
	 * @param array $item Current item.
	 * @return string HTML for the title with row actions.
	 */
	protected function column_title( $item ) {
		$edit_url = '#'; // Define edit URL as required.

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Edit', 'exelearning' ) ),
			'delete' => sprintf( '<a href="%s">%s</a>', esc_url( '#' ), __( 'Delete', 'exelearning' ) ),
		);

		return sprintf(
			'<strong>%1$s</strong> %2$s',
			esc_html( $item['title'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Default column rendering.
	 *
	 * @param array  $item Current item.
	 * @param string $column_name Column name.
	 * @return string Column content.
	 */
	protected function column_default( $item, $column_name ) {
		if ( isset( $item[ $column_name ] ) ) {
			return esc_html( $item[ $column_name ] );
		}
		return '';
	}
}
