<?php
/**
 * Tests for the developer lifecycle hooks (actions and filters).
 *
 * @package Exelearning
 */

/**
 * Class DeveloperHooksTest.
 *
 * Exercises the public hook surface added for third-party integrations:
 * the ELPX metadata filter, the metadata-saved action, the shortcode
 * presentation filters, the style lifecycle actions/filter, and the static
 * editor install actions. Each test asserts both that a hook fires and that
 * the plugin's defensive validation cannot be subverted by a callback.
 */
class DeveloperHooksTest extends WP_UnitTestCase {

	/**
	 * Paths created during a test that must be removed on tear down.
	 *
	 * @var string[]
	 */
	private $cleanup_paths = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->cleanup_paths = array();
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		foreach ( $this->cleanup_paths as $path ) {
			$this->recursive_delete( $path );
		}
		delete_option( ExeLearning_Styles_Service::OPTION_REGISTRY );
		$storage = ExeLearning_Styles_Service::get_storage_dir();
		if ( is_dir( $storage ) ) {
			ExeLearning_Styles_Service::recursive_delete( $storage );
		}
		parent::tear_down();
	}

	/* -------------------------------------------------------------------- */
	/* ELPX metadata filter                                                 */
	/* -------------------------------------------------------------------- */

	/**
	 * The metadata filter is invoked and can enrich the array.
	 */
	public function test_elpx_metadata_filter_enriches() {
		add_filter(
			'exelearning_elpx_metadata',
			static function ( $metadata ) {
				$metadata['_exelearning_custom'] = 'enriched';
				return $metadata;
			}
		);

		$result = ExeLearning_Elp_File_Service::filter_metadata( $this->sample_metadata(), '/tmp/example.elpx', null );

		$this->assertSame( 'enriched', $result['_exelearning_custom'] );
		// Required keys are still present.
		$this->assertSame( 'Sample', $result['_exelearning_title'] );
		$this->assertArrayHasKey( '_exelearning_extracted', $result );
	}

	/**
	 * A non-array return value is discarded and required keys are restored.
	 */
	public function test_elpx_metadata_filter_non_array_is_safe() {
		add_filter(
			'exelearning_elpx_metadata',
			static function () {
				return 'not an array';
			}
		);

		$result = ExeLearning_Elp_File_Service::filter_metadata( $this->sample_metadata(), '', null );

		$this->assertIsArray( $result );
		foreach ( ExeLearning_Elp_File_Service::REQUIRED_META_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $result, "Required key {$key} must survive a bad filter." );
		}
		$this->assertSame( 'Sample', $result['_exelearning_title'] );
	}

	/**
	 * A callback cannot drop or overwrite required internal keys.
	 */
	public function test_elpx_metadata_filter_cannot_corrupt_required_keys() {
		add_filter(
			'exelearning_elpx_metadata',
			static function () {
				// Try to wipe everything and forge the extraction hash.
				return array( '_exelearning_extracted' => 'forged-hash' );
			}
		);

		$result = ExeLearning_Elp_File_Service::filter_metadata( $this->sample_metadata(), '', null );

		// The trusted pre-filter value wins.
		$this->assertSame( 'deadbeef', $result['_exelearning_extracted'] );
		$this->assertSame( 'Sample', $result['_exelearning_title'] );
	}

	/* -------------------------------------------------------------------- */
	/* Metadata saved action (real flow via the reprocessor)                */
	/* -------------------------------------------------------------------- */

	/**
	 * The saved action fires after metadata is persisted, with the right args.
	 */
	public function test_after_elpx_metadata_saved_fires() {
		$captured = array();
		add_action(
			'exelearning_after_elpx_metadata_saved',
			static function ( $attachment_id, $metadata ) use ( &$captured ) {
				$captured[] = array( $attachment_id, $metadata );
			},
			10,
			2
		);

		$fixture = $this->make_elpx_attachment();
		$id      = $fixture['id'];

		( new ExeLearning_Reprocessor() )->reprocess( $id );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $id, '_exelearning_extracted', true ) );

		$this->assertGreaterThan( 0, did_action( 'exelearning_after_elpx_metadata_saved' ) );
		$this->assertSame( $id, $captured[0][0] );
		$this->assertIsArray( $captured[0][1] );
		$this->assertArrayHasKey( '_exelearning_extracted', $captured[0][1] );
	}

	/**
	 * The metadata filter is wired into the real persistence flow.
	 */
	public function test_elpx_metadata_filter_persists_enrichment() {
		add_filter(
			'exelearning_elpx_metadata',
			static function ( $metadata ) {
				$metadata['_exelearning_custom'] = 'lms-42';
				return $metadata;
			}
		);

		$fixture = $this->make_elpx_attachment();
		$id      = $fixture['id'];

		( new ExeLearning_Reprocessor() )->reprocess( $id );
		$this->cleanup_paths[] = $this->extraction_dir( get_post_meta( $id, '_exelearning_extracted', true ) );

		$this->assertSame( 'lms-42', get_post_meta( $id, '_exelearning_custom', true ) );
	}

	/* -------------------------------------------------------------------- */
	/* Shortcode filters                                                    */
	/* -------------------------------------------------------------------- */

	/**
	 * The atts filter can change a presentation attribute.
	 */
	public function test_shortcode_atts_filter_changes_height() {
		add_filter(
			'exelearning_shortcode_atts',
			static function ( $atts ) {
				$atts['height'] = 900;
				return $atts;
			}
		);

		$result = ( new ExeLearning_Shortcodes() )->display_exelearning( array( 'id' => $this->previewable_attachment() ) );

		$this->assertStringContainsString( '900px', $result );
	}

	/**
	 * Unsafe values returned by the atts filter are re-sanitized.
	 */
	public function test_shortcode_atts_filter_values_are_resanitized() {
		add_filter(
			'exelearning_shortcode_atts',
			static function ( $atts ) {
				$atts['height'] = 'evil"><script>alert(1)</script>';
				return $atts;
			}
		);

		$result = ( new ExeLearning_Shortcodes() )->display_exelearning( array( 'id' => $this->previewable_attachment() ) );

		$this->assertStringNotContainsString( '<script>alert(1)', $result );
		// absint() of the unsafe value collapses to 0px.
		$this->assertStringContainsString( '0px', $result );
	}

	/**
	 * The output filter can wrap the final HTML.
	 */
	public function test_shortcode_output_filter_wraps_html() {
		add_filter(
			'exelearning_shortcode_output',
			static function ( $html ) {
				return '<div class="wrap-marker">' . $html . '</div>';
			}
		);

		$result = ( new ExeLearning_Shortcodes() )->display_exelearning( array( 'id' => $this->previewable_attachment() ) );

		$this->assertStringContainsString( 'wrap-marker', $result );
		$this->assertStringContainsString( 'exelearning-iframe', $result );
	}

	/* -------------------------------------------------------------------- */
	/* Style lifecycle                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * The installed action fires with the slug and entry.
	 */
	public function test_after_style_installed_fires() {
		$captured = array();
		add_action(
			'exelearning_after_style_installed',
			static function ( $slug, $entry ) use ( &$captured ) {
				$captured[] = array( $slug, $entry );
			},
			10,
			2
		);

		ExeLearning_Styles_Service::install_from_zip( $this->make_style_zip( 'acme' ), 'acme.zip' );

		$this->assertSame( 'acme', $captured[0][0] );
		$this->assertIsArray( $captured[0][1] );
		$this->assertArrayHasKey( 'css_files', $captured[0][1] );
	}

	/**
	 * The registry-entry filter can enrich the entry while required keys hold.
	 */
	public function test_style_registry_entry_filter_enriches_and_protects() {
		add_filter(
			'exelearning_style_registry_entry',
			static function ( $entry ) {
				$entry['category'] = 'institutional';
				// Attempt to strip a protected key and forge another.
				unset( $entry['css_files'] );
				$entry['enabled'] = 'tampered';
				return $entry;
			}
		);

		ExeLearning_Styles_Service::install_from_zip( $this->make_style_zip( 'lab' ), 'lab.zip' );

		$registry = ExeLearning_Styles_Service::get_registry();
		$entry    = $registry['uploaded']['lab'];

		$this->assertSame( 'institutional', $entry['category'] );
		// Protected keys are restored from the trusted entry.
		$this->assertArrayHasKey( 'css_files', $entry );
		$this->assertTrue( $entry['enabled'] );
	}

	/**
	 * The deleted action fires with the slug.
	 */
	public function test_after_style_deleted_fires() {
		$captured = array();
		add_action(
			'exelearning_after_style_deleted',
			static function ( $slug ) use ( &$captured ) {
				$captured[] = $slug;
			}
		);

		ExeLearning_Styles_Service::install_from_zip( $this->make_style_zip( 'bye' ), 'bye.zip' );
		ExeLearning_Styles_Service::delete_uploaded( 'bye' );

		$this->assertSame( array( 'bye' ), $captured );
	}

	/**
	 * The enabled-changed action fires with the slug and boolean state.
	 */
	public function test_after_style_enabled_changed_fires() {
		$captured = array();
		add_action(
			'exelearning_after_style_enabled_changed',
			static function ( $slug, $enabled ) use ( &$captured ) {
				$captured[] = array( $slug, $enabled );
			},
			10,
			2
		);

		ExeLearning_Styles_Service::install_from_zip( $this->make_style_zip( 'toggle' ), 'toggle.zip' );
		ExeLearning_Styles_Service::set_uploaded_enabled( 'toggle', false );

		$this->assertSame( 'toggle', $captured[0][0] );
		$this->assertFalse( $captured[0][1] );
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * A representative metadata array as built before persistence.
	 *
	 * @return array
	 */
	private function sample_metadata() {
		return array(
			'_exelearning_title'         => 'Sample',
			'_exelearning_description'   => 'Desc',
			'_exelearning_license'       => 'CC-BY',
			'_exelearning_language'      => 'en',
			'_exelearning_resource_type' => 'lesson',
			'_exelearning_extracted'     => 'deadbeef',
			'_exelearning_version'       => 3,
			'_exelearning_has_preview'   => '1',
		);
	}

	/**
	 * Create an attachment with extraction metadata pointing at a fake hash so
	 * the shortcode renders its preview iframe (no files required).
	 *
	 * @return int Attachment ID.
	 */
	private function previewable_attachment() {
		$attachment_id = $this->factory->attachment->create();
		update_post_meta( $attachment_id, '_exelearning_extracted', str_repeat( 'a', 40 ) );
		update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );
		return $attachment_id;
	}

	/**
	 * Create an attachment backed by a valid previewable .elpx file on disk.
	 *
	 * @return array { id: int, path: string }
	 */
	private function make_elpx_attachment() {
		$user_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_mime_type' => 'application/zip',
				'post_author'    => $user_id,
				'post_title'     => 'Hook ELP',
			)
		);
		wp_set_current_user( $user_id );

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/hooks-' . $attachment_id . '.elpx';

		$zip = new ZipArchive();
		$zip->open( $file_path, ZipArchive::CREATE );
		$zip->addFromString( 'content.xml', '<package></package>' );
		$zip->addFromString( 'index.html', '<html><body>Preview</body></html>' );
		$zip->close();

		$this->cleanup_paths[] = $file_path;
		update_attached_file( $attachment_id, $file_path );

		return array(
			'id'   => $attachment_id,
			'path' => $file_path,
		);
	}

	/**
	 * Build a minimal valid eXeLearning style ZIP on disk.
	 *
	 * @param string $name Theme/slug name.
	 * @return string Absolute path to the created ZIP.
	 */
	private function make_style_zip( $name ) {
		$path = wp_tempnam( 'hooks-style.zip' );
		wp_delete_file( $path );
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString(
			'config.xml',
			'<?xml version="1.0"?><theme>'
				. '<name>' . esc_html( $name ) . '</name>'
				. '<title>' . esc_html( ucfirst( $name ) ) . '</title>'
				. '<version>1.0.0</version>'
				. '<author>Test</author>'
				. '<license>CC-BY-SA</license>'
				. '<description>Test theme.</description>'
				. '</theme>'
		);
		$zip->addFromString( 'style.css', 'body { color: red; }' );
		$zip->close();
		$this->cleanup_paths[] = $path;
		return $path;
	}

	/**
	 * Absolute path to the extraction directory for a hash.
	 *
	 * @param string $hash Extraction hash.
	 * @return string
	 */
	private function extraction_dir( $hash ) {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'exelearning/' . $hash . '/';
	}

	/**
	 * Recursively delete a path.
	 *
	 * @param string $dir Path.
	 */
	private function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_file( $dir ) || is_link( $dir ) ) {
			unlink( $dir ); // phpcs:ignore
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$this->recursive_delete( $dir . DIRECTORY_SEPARATOR . $file );
		}
		rmdir( $dir ); // phpcs:ignore
	}
}
