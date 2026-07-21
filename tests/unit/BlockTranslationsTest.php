<?php
/**
 * Tests for the Gutenberg block JavaScript translations.
 *
 * Guards the standard wp_set_script_translations() + `wp i18n make-json`
 * pipeline: the block's download strings (and the format labels, which used to
 * be referenced via a variable and were therefore never extracted) must end up
 * in the generated per-locale JS translation JSON.
 *
 * @package Exelearning
 */

/**
 * Class BlockTranslationsTest.
 */
class BlockTranslationsTest extends WP_UnitTestCase {

	/**
	 * Reset the scripts/styles registries so enqueues from these tests do not
	 * leak into other test classes (WP_UnitTestCase does not reset them).
	 */
	public function tear_down() {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		parent::tear_down();
	}

	/**
	 * Absolute path to the plugin's languages directory.
	 *
	 * @return string
	 */
	private function languages_dir() {
		return dirname( __DIR__, 2 ) . '/languages';
	}

	/**
	 * The Spanish JS translation JSON must exist and carry the download strings.
	 */
	public function test_es_es_js_json_contains_download_strings() {
		$files = glob( $this->languages_dir() . '/exelearning-es_ES-*.json' );
		$this->assertNotEmpty( $files, 'No es_ES JS translation JSON file was generated.' );

		$combined = '';
		foreach ( $files as $file ) {
			$combined .= file_get_contents( $file ); // phpcs:ignore
		}

		// Panel strings (already literal) and the format labels (previously a
		// variable, now literal) must all be present and translated.
		$this->assertStringContainsString( 'Opciones de descarga', $combined );
		$this->assertStringContainsString( 'Formatos disponibles', $combined );
		$this->assertStringContainsString( 'Descargar .elpx', $combined );
	}

	/**
	 * Re-enqueue the block editor scripts from a clean registry and return the
	 * registered script object for the block handle.
	 *
	 * @return _WP_Dependency
	 */
	private function register_block_script() {
		global $wp_scripts;
		wp_dequeue_script( 'exelearning-elp-block' );
		wp_deregister_script( 'exelearning-elp-block' );

		$block = new ExeLearning_Elp_Upload_Block();
		$block->enqueue_block_scripts();

		return $wp_scripts->registered['exelearning-elp-block'];
	}

	/**
	 * The block editor script must declare wp-i18n as a dependency so the
	 * wp.i18n runtime (and its translations) is available.
	 */
	public function test_block_script_depends_on_wp_i18n() {
		$script = $this->register_block_script();

		$this->assertContains( 'wp-i18n', $script->deps );
	}

	/**
	 * The script translations must be registered under the plugin text domain.
	 */
	public function test_block_script_translation_domain_is_exelearning() {
		$script = $this->register_block_script();

		$this->assertSame( 'exelearning', $script->textdomain );
	}

	/**
	 * The script translations must resolve to the plugin's own languages
	 * directory, where the generated JSON files live.
	 */
	public function test_block_script_translations_path_is_plugin_languages_dir() {
		$script = $this->register_block_script();

		$this->assertSame( EXELEARNING_PLUGIN_DIR . 'languages', $script->translations_path );
	}

	/**
	 * The enqueued script URL must resolve to the canonical relative path
	 * assets/js/elp-upload.js. WordPress derives the JSON filename from the md5
	 * of this relative path, so an unnormalized URL (e.g. "includes/../assets/…")
	 * would never match the generated exelearning-<locale>-<md5>.json files.
	 */
	public function test_block_script_src_is_canonical_relative_path() {
		$script = $this->register_block_script();

		$this->assertStringEndsWith( 'assets/js/elp-upload.js', $script->src );
		$this->assertStringNotContainsString( '../', $script->src );
	}

	/**
	 * The block must opt into standard script translations (no manual injection).
	 */
	public function test_block_uses_standard_script_translations() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-elp-upload-block.php' ); // phpcs:ignore

		$this->assertStringContainsString( 'wp_set_script_translations', $source );
		$this->assertStringNotContainsString( 'inject_block_translations', $source );
	}

	/**
	 * WordPress must actually resolve the block's script translations for es_ES.
	 *
	 * This guards the script `src`/JSON `md5` alignment: an unnormalized URL
	 * (e.g. "includes/../assets/...") would make `load_script_textdomain()`
	 * compute a md5 that does not match the make-json output, so no translation
	 * loads even though the JSON file exists.
	 */
	public function test_block_script_translations_resolve_for_es_es() {
		$block = new ExeLearning_Elp_Upload_Block();
		$block->enqueue_block_scripts();

		switch_to_locale( 'es_ES' );
		$json = load_script_textdomain(
			'exelearning-elp-block',
			'exelearning',
			EXELEARNING_PLUGIN_DIR . 'languages'
		);
		restore_previous_locale();

		$this->assertNotEmpty( $json, 'WordPress could not resolve the block script translations.' );
		$this->assertStringContainsString( 'Opciones de descarga', $json );
	}
}
