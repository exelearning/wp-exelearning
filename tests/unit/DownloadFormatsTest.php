<?php
/**
 * Tests for ExeLearning_Download_Formats helper.
 *
 * @package Exelearning
 */

/**
 * Class DownloadFormatsTest.
 *
 * @covers ExeLearning_Download_Formats
 */
class DownloadFormatsTest extends WP_UnitTestCase {

	/**
	 * Canonical list and ordering.
	 */
	public function test_all_returns_canonical_registry() {
		$all = ExeLearning_Download_Formats::all();
		$ids = array_column( $all, 'id' );

		$this->assertSame( array( 'elpx', 'html5', 'scorm12', 'ims', 'epub3' ), $ids );

		foreach ( $all as $fmt ) {
			$this->assertArrayHasKey( 'id', $fmt );
			$this->assertArrayHasKey( 'label', $fmt );
			$this->assertArrayHasKey( 'suffix', $fmt );
			$this->assertArrayHasKey( 'mime', $fmt );
			$this->assertArrayHasKey( 'client', $fmt );
		}
	}

	/**
	 * Filename suffix convention.
	 */
	public function test_suffixes_match_specification() {
		$by_suffix = array();
		foreach ( ExeLearning_Download_Formats::all() as $fmt ) {
			$by_suffix[ $fmt['id'] ] = $fmt['suffix'];
		}
		$this->assertSame( '.elpx', $by_suffix['elpx'] );
		$this->assertSame( '_web.zip', $by_suffix['html5'] );
		$this->assertSame( '_scorm.zip', $by_suffix['scorm12'] );
		$this->assertSame( '_ims.zip', $by_suffix['ims'] );
		$this->assertSame( '.epub', $by_suffix['epub3'] );
	}

	/**
	 * Default ids mirror the canonical list.
	 */
	public function test_default_ids_matches_all() {
		$this->assertSame(
			array( 'elpx', 'html5', 'scorm12', 'ims', 'epub3' ),
			ExeLearning_Download_Formats::default_ids()
		);
	}

	/**
	 * Sanitize accepts arrays and preserves canonical order.
	 */
	public function test_sanitize_accepts_array_and_preserves_canonical_order() {
		$this->assertSame(
			array( 'elpx', 'epub3' ),
			ExeLearning_Download_Formats::sanitize( array( 'epub3', 'elpx' ) )
		);
	}

	/**
	 * Sanitize drops unknown ids.
	 */
	public function test_sanitize_drops_unknown_ids() {
		$this->assertSame(
			array( 'html5', 'ims' ),
			ExeLearning_Download_Formats::sanitize( array( 'html5', 'unknown', 'ims', 'scorm2004' ) )
		);
	}

	/**
	 * Sanitize accepts comma-separated strings.
	 */
	public function test_sanitize_accepts_comma_separated_string() {
		$this->assertSame(
			array( 'html5', 'scorm12' ),
			ExeLearning_Download_Formats::sanitize( 'scorm12, html5 , unknown' )
		);
	}

	/**
	 * Sanitize falls back to defaults for invalid input.
	 */
	public function test_sanitize_falls_back_to_defaults_for_invalid_input() {
		$this->assertSame(
			ExeLearning_Download_Formats::default_ids(),
			ExeLearning_Download_Formats::sanitize( null )
		);
		$this->assertSame(
			ExeLearning_Download_Formats::default_ids(),
			ExeLearning_Download_Formats::sanitize( 42 )
		);
	}

	/**
	 * Lookup by id returns the entry.
	 */
	public function test_get_returns_format_by_id() {
		$fmt = ExeLearning_Download_Formats::get( 'epub3' );
		$this->assertNotNull( $fmt );
		$this->assertSame( 'epub3', $fmt['id'] );
		$this->assertSame( '.epub', $fmt['suffix'] );
		$this->assertSame( 'application/epub+zip', $fmt['mime'] );
	}

	/**
	 * Lookup returns null for unknown id.
	 */
	public function test_get_returns_null_for_unknown_id() {
		$this->assertNull( ExeLearning_Download_Formats::get( 'does-not-exist' ) );
	}
}
