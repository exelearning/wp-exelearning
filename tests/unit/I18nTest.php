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
		$this->reset_textdomain_state();
	}

	/**
	 * Clean up any textdomain state so tests do not leak the loaded catalog
	 * into unrelated test classes.
	 */
	public function tear_down() {
		$this->reset_textdomain_state();
		parent::tear_down();
	}

	/**
	 * Clear the loaded catalog for the domain so each test triggers a fresh
	 * just-in-time load. unload_textdomain() clears the translation controller,
	 * and clearing the "unloaded" flag afterwards keeps JIT enabled (otherwise
	 * the flag would short-circuit JIT for every following test).
	 */
	private function reset_textdomain_state() {
		unload_textdomain( 'exelearning' );
		unset( $GLOBALS['l10n_unloaded']['exelearning'] );
	}

	/**
	 * Test load_textdomain method exists.
	 */
	public function test_load_textdomain_exists() {
		$this->assertTrue( method_exists( $this->i18n, 'load_textdomain' ) );
	}

	/**
	 * The bundled Spanish PHP translations must resolve after the loader runs,
	 * even though the checkout/plugin directory name never matches the text
	 * domain. This is the behavior WordPress just-in-time loading cannot provide
	 * on its own for a plugin whose folder differs from its text domain: the
	 * loader must register the plugin's own languages directory as a custom path.
	 */
	public function test_load_textdomain_resolves_bundled_spanish_translation() {
		switch_to_locale( 'es_ES' );

		$this->i18n->load_textdomain();

		$translated = __( 'Settings', 'exelearning' );

		restore_previous_locale();

		$this->assertSame( 'Ajustes', $translated );
	}

	/**
	 * The loader must derive the translation directory from the plugin file, so
	 * it points at the plugin's own languages directory regardless of the folder
	 * name. Capturing the .mo path WordPress attempts proves the path is plugin
	 * relative and correctly named.
	 */
	public function test_load_textdomain_uses_plugin_languages_directory() {
		$attempts = array();
		$capture  = static function ( $mofile, $domain ) use ( &$attempts ) {
			if ( 'exelearning' === $domain ) {
				$attempts[] = $mofile;
			}
			return $mofile;
		};

		add_filter( 'load_textdomain_mofile', $capture, 10, 2 );
		switch_to_locale( 'es_ES' );

		$this->i18n->load_textdomain();
		// Force just-in-time loading to run so the .mo path is resolved.
		__( 'Settings', 'exelearning' );

		restore_previous_locale();
		remove_filter( 'load_textdomain_mofile', $capture, 10 );

		$this->assertNotEmpty( $attempts, 'WordPress never attempted to load a .mo file for the domain.' );

		$plugin_mofile = EXELEARNING_PLUGIN_DIR . 'languages/exelearning-es_ES.mo';
		$this->assertContains(
			$plugin_mofile,
			$attempts,
			'The loader did not resolve the plugin languages directory.'
		);
	}

	/**
	 * Global language packs installed under WP_LANG_DIR/plugins must keep
	 * priority over the plugin's bundled translations. WordPress resolves the
	 * WP_LANG_DIR location before the custom plugin path, so a language pack
	 * translation must win over the bundled one.
	 */
	public function test_global_language_packs_override_bundled_translations() {
		$locale        = 'es_ES';
		$lang_plugins  = WP_LANG_DIR . '/plugins';
		$language_pack = $lang_plugins . '/exelearning-' . $locale . '.mo';
		$override      = 'Ajustes (paquete de idioma)';

		if ( ! is_dir( $lang_plugins ) ) {
			wp_mkdir_p( $lang_plugins );
		}
		$this->write_language_pack( $language_pack, $locale, 'Settings', $override );

		// A fresh registry avoids cached directory scans so the language pack
		// file just written is discovered.
		$original_registry                    = $GLOBALS['wp_textdomain_registry'];
		$GLOBALS['wp_textdomain_registry']    = new WP_Textdomain_Registry();

		$this->reset_textdomain_state();
		switch_to_locale( $locale );

		$this->i18n->load_textdomain();
		$translated = __( 'Settings', 'exelearning' );

		restore_previous_locale();
		$this->reset_textdomain_state();

		$GLOBALS['wp_textdomain_registry'] = $original_registry;
		wp_delete_file( $language_pack );

		$this->assertSame(
			$override,
			$translated,
			'A language pack under WP_LANG_DIR/plugins must override the bundled translation.'
		);
	}

	/**
	 * Write a minimal binary .mo language pack with a single translated string.
	 *
	 * @param string $file        Destination .mo path.
	 * @param string $locale      Locale code.
	 * @param string $singular    Source string.
	 * @param string $translation Translated string.
	 */
	private function write_language_pack( $file, $locale, $singular, $translation ) {
		require_once ABSPATH . WPINC . '/pomo/mo.php';

		$mo = new MO();
		$mo->set_header( 'Project-Id-Version', 'exelearning' );
		$mo->set_header( 'MIME-Version', '1.0' );
		$mo->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$mo->set_header( 'Content-Transfer-Encoding', '8bit' );
		$mo->set_header( 'Language', $locale );
		$mo->set_header( 'Plural-Forms', 'nplurals=2; plural=(n != 1);' );
		$mo->add_entry(
			array(
				'singular'     => $singular,
				'translations' => array( $translation ),
			)
		);
		$mo->export_to_file( $file );
	}

	/**
	 * Loading translations must not trigger the WordPress "translation loading
	 * triggered too early" notice (_doing_it_wrong, introduced in 6.7).
	 */
	public function test_load_textdomain_does_not_trigger_doing_it_wrong() {
		$notices = array();
		$capture = static function ( $function_name, $message ) use ( &$notices ) {
			$notices[] = $function_name . ': ' . $message;
		};

		add_action( 'doing_it_wrong_run', $capture, 10, 2 );
		switch_to_locale( 'es_ES' );

		$this->i18n->load_textdomain();
		__( 'Settings', 'exelearning' );

		restore_previous_locale();
		remove_action( 'doing_it_wrong_run', $capture, 10 );

		$this->assertSame( array(), $notices, 'Translation loading emitted a _doing_it_wrong() notice.' );
	}
}
