<?php
/**
 * Tests for the standalone editor page built by admin/views/editor-bootstrap.php.
 *
 * The view patches the bundled static editor into something that boots against
 * this WordPress install: it injects window.__WP_EXE_CONFIG__ with the REST
 * endpoint and nonce the editor saves through, a <base> tag so the editor's
 * relative asset paths resolve inside dist/static/, and the approved style
 * registry. Everything the embedded editor knows about WordPress comes from
 * here, and until it returned its HTML instead of printing it, none of it could
 * be checked.
 *
 * The bundle is a fixture rather than whatever dist/static/ happens to hold, so
 * these assertions mean the same thing in CI and on a machine with a built
 * editor. See ExeLearning_Bundle_Fixture and
 * docs/architecture/changes/88-testable-editor-bundle-paths/.
 *
 * @package Exelearning
 */

/**
 * Editor whose request-ending steps are recorded instead of taken.
 *
 * Mirrors the ExeLearning_Admin_Styles::finish_request() pattern: exit() would
 * end the PHPUnit process, so the subclass records what would have been sent.
 */
class ExeLearning_Editor_Recording extends ExeLearning_Editor {

	/**
	 * Redirect target, if one was issued.
	 *
	 * @var string|null
	 */
	public $redirected_to = null;

	/**
	 * Document that would have been printed.
	 *
	 * @var string|null
	 */
	public $sent_html = null;

	/**
	 * Record the redirect rather than performing it.
	 *
	 * @param string $location Redirect target URL.
	 * @return void
	 */
	protected function redirect_and_exit( $location ) {
		$this->redirected_to = $location;
	}

	/**
	 * Record the document rather than printing it.
	 *
	 * @param string $html Assembled HTML document.
	 * @return void
	 */
	protected function send_and_exit( $html ) {
		$this->sent_html = $html;
	}

	/**
	 * Expose the protected entry point to the test.
	 *
	 * @param int $attachment_id Attachment being edited.
	 * @return void
	 */
	public function serve( $attachment_id ) {
		$this->serve_bootstrap_page( $attachment_id );
	}
}

/**
 * Class EditorBootstrapPageTest.
 *
 * Deliberately without a @covers annotation. What is under test here is the
 * view file, not a class, and @covers would restrict attribution to the named
 * class and discard everything the view itself executed — which is the whole
 * point of this file.
 */
class EditorBootstrapPageTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Editor
	 */
	private $editor;

	/**
	 * Attachment files to remove on tear down.
	 *
	 * @var string[]
	 */
	private $cleanup_paths = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->editor        = new ExeLearning_Editor();
		$this->cleanup_paths = array();
		$_GET                = array();
		ExeLearning_Bundle_Fixture::create();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		ExeLearning_Bundle_Fixture::destroy();
		$_GET = array();
		foreach ( $this->cleanup_paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * Create an .elpx attachment owned by the current user.
	 *
	 * @param string $title Attachment title.
	 * @return int Attachment ID.
	 */
	private function make_elpx( $title = 'Mi curso' ) {
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title'  => $title,
				'post_author' => get_current_user_id(),
			)
		);

		$upload = wp_upload_dir();
		$path   = trailingslashit( $upload['basedir'] ) . 'bootstrap-' . $attachment_id . '.elpx';
		file_put_contents( $path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_attached_file( $attachment_id, $path );
		$this->cleanup_paths[] = $path;

		return $attachment_id;
	}

	/**
	 * Read one field out of the injected window.__WP_EXE_CONFIG__ literal.
	 *
	 * The block is a JavaScript object literal with bare keys, so it is not
	 * JSON as a whole; each value, however, is written by wp_json_encode (or is
	 * a bare integer), so it decodes on its own.
	 *
	 * @param string $html Built page.
	 * @param string $key  Configuration key.
	 * @return mixed Decoded value.
	 */
	private function config_value( $html, $key ) {
		$pattern = '/^\s*' . preg_quote( $key, '/' ) . ': (.*)$/m';
		$this->assertMatchesRegularExpression( $pattern, $html, "No {$key} in the injected config." );
		preg_match( $pattern, $html, $matches );

		return json_decode( rtrim( trim( $matches[1] ), ',' ), true );
	}

	/**
	 * Without a bundled editor the page cannot be built, and the view says so
	 * rather than rendering something broken.
	 */
	public function test_no_page_is_built_without_a_bundled_editor() {
		ExeLearning_Bundle_Fixture::create_empty();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->editor->build_bootstrap_page( $this->make_elpx() ) );
	}

	/**
	 * That refusal points at the settings screen, which is where the missing
	 * bundle is explained.
	 */
	public function test_the_missing_editor_url_targets_the_settings_notice() {
		$url = ExeLearning_Editor::editor_missing_url();

		$this->assertStringContainsString( 'page=exelearning-settings', $url );
		$this->assertStringContainsString( 'editor-missing=1', $url );
		$this->assertStringStartsWith( admin_url( 'options-general.php' ), $url );
	}

	/**
	 * The editor is told which attachment it is editing and where to save it.
	 */
	public function test_the_page_tells_the_editor_how_to_reach_wordpress() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$attachment_id = $this->make_elpx();

		$html = $this->editor->build_bootstrap_page( $attachment_id );

		$this->assertSame( 'WordPress', $this->config_value( $html, 'mode' ) );
		$this->assertSame( $attachment_id, $this->config_value( $html, 'attachmentId' ) );
		$this->assertSame( rest_url( 'exelearning/v1' ), $this->config_value( $html, 'restUrl' ) );
		$this->assertNotEmpty( $this->config_value( $html, 'nonce' ) );
		$this->assertSame(
			wp_get_attachment_url( $attachment_id ),
			$this->config_value( $html, 'elpUrl' )
		);
	}

	/**
	 * The nonce is a real wp_rest nonce, not a placeholder: the REST routes
	 * reject the save without it.
	 */
	public function test_the_page_carries_a_usable_rest_nonce() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertSame( 1, wp_verify_nonce( $this->config_value( $html, 'nonce' ), 'wp_rest' ) );
	}

	/**
	 * The signed-in user is passed through so the editor can attribute changes.
	 */
	public function test_the_page_identifies_the_signed_in_user() {
		$user_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Ada Lovelace',
			)
		);
		wp_set_current_user( $user_id );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertSame( 'Ada Lovelace', $this->config_value( $html, 'userName' ) );
		$this->assertSame( $user_id, $this->config_value( $html, 'userId' ) );
	}

	/**
	 * A <base> tag pointing into dist/static/ is what makes the editor's own
	 * relative asset paths resolve; without it the page loads nothing.
	 */
	public function test_the_page_bases_relative_paths_on_the_bundled_editor() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertStringContainsString(
			'<base href="' . esc_url( EXELEARNING_PLUGIN_URL . 'dist/static' ) . '/">',
			$html
		);
	}

	/**
	 * Exactly one <base>, and it goes in the head.
	 *
	 * The editor's own markup contains `<header id="head">`, which the pattern
	 * that finds the head element used to match as well, dropping a second
	 * stray <base> into the middle of the body.
	 */
	public function test_only_one_base_tag_is_injected() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		ExeLearning_Bundle_Fixture::write(
			'index.html',
			'<html><head></head><body><header id="head">Barra</header></body></html>'
		);

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertSame( 1, substr_count( $html, '<base href=' ) );
		$this->assertStringContainsString( '</head>', $html );
		$this->assertLessThan(
			strpos( $html, '<body' ),
			strpos( $html, '<base href=' ),
			'The <base> tag must sit in the head, not in the body.'
		);
	}

	/**
	 * Explicit "./" paths in the bundle's own markup are rewritten too, because
	 * the editor page is served from wp-admin rather than from dist/static/.
	 */
	public function test_relative_asset_paths_in_the_bundle_are_rewritten() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		ExeLearning_Bundle_Fixture::write(
			'index.html',
			'<html><head></head><body><script src="./app/main.js"></script></body></html>'
		);

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertStringContainsString(
			'src="' . esc_url( EXELEARNING_PLUGIN_URL . 'dist/static' ) . '/app/main.js"',
			$html
		);
		$this->assertStringNotContainsString( 'src="./app/main.js"', $html );
	}

	/**
	 * The bridge script is loaded from the plugin's own assets, not from the
	 * editor bundle: it is the WordPress half of the protocol.
	 */
	public function test_the_page_loads_the_wordpress_bridge() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertStringContainsString(
			esc_url( EXELEARNING_PLUGIN_URL . 'assets' ) . '/js/wp-exe-bridge.js',
			$html
		);
	}

	/**
	 * Everything is injected inside <head>, before the editor boots.
	 */
	public function test_the_configuration_is_injected_into_the_document_head() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$head = substr( $html, 0, strpos( $html, '</head>' ) );
		$this->assertStringContainsString( 'window.__WP_EXE_CONFIG__', $head );
		$this->assertStringContainsString( '<base href=', $head );
		$this->assertStringContainsString( 'wp-exe-notification', $head );
	}

	/**
	 * The approved style registry travels with the page, so the editor offers
	 * exactly the styles this site allows.
	 */
	public function test_the_page_carries_the_style_registry() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		ExeLearning_Styles_Service::set_builtin_enabled( 'pukao', false );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		// The registry is handed to the boot trap as `var OVERRIDE = {...};`,
		// which wp_json_encode wrote, so it decodes as JSON on its own.
		$this->assertMatchesRegularExpression( '/var OVERRIDE = (\{.*?\});/s', $html );
		preg_match( '/var OVERRIDE = (\{.*?\});/s', $html, $matches );
		$registry = json_decode( $matches[1], true );

		$this->assertContains( 'pukao', $registry['disabledBuiltins'] );

		delete_option( ExeLearning_Styles_Service::OPTION_DISABLED_STYLES );
	}

	/**
	 * The page is localized: the editor boots in the site's language.
	 */
	public function test_the_page_passes_the_site_language() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->editor->build_bootstrap_page( $this->make_elpx() );

		$this->assertSame( substr( get_locale(), 0, 2 ), $this->config_value( $html, 'locale' ) );
	}

	/**
	 * An attachment with no title still produces a usable page rather than an
	 * empty heading.
	 */
	public function test_an_untitled_attachment_falls_back_to_its_filename() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$attachment_id = $this->make_elpx( '' );

		$html = $this->editor->build_bootstrap_page( $attachment_id );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'bootstrap-' . $attachment_id . '.elpx', $html );
	}

	/**
	 * With an editor bundled, the request ends by sending the built document.
	 */
	public function test_serving_the_page_sends_the_built_document() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$editor = new ExeLearning_Editor_Recording();

		$editor->serve( $this->make_elpx() );

		$this->assertNull( $editor->redirected_to );
		$this->assertStringContainsString( 'window.__WP_EXE_CONFIG__', (string) $editor->sent_html );
	}

	/**
	 * Without one, the administrator is sent to the settings screen instead of
	 * being shown a broken editor.
	 */
	public function test_serving_the_page_redirects_when_no_editor_is_bundled() {
		ExeLearning_Bundle_Fixture::create_empty();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$editor = new ExeLearning_Editor_Recording();

		$editor->serve( $this->make_elpx() );

		$this->assertNull( $editor->sent_html );
		$this->assertSame( ExeLearning_Editor::editor_missing_url(), $editor->redirected_to );
	}

	/**
	 * The whole front controller, from a nonced request to a served document.
	 *
	 * EditorPageTest covers each guard that refuses a request; this is the path
	 * where every guard passes, which used to end in exit() before the first
	 * assertion could run.
	 */
	public function test_a_valid_request_is_served_end_to_end() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$attachment_id = $this->make_elpx();
		$editor        = new ExeLearning_Editor_Recording();

		$_GET = array(
			'_wpnonce'      => wp_create_nonce( 'exelearning_editor' ),
			'attachment_id' => (string) $attachment_id,
		);

		// render_editor_page() closes one output buffer on entry, so give it one
		// of its own to consume rather than PHPUnit's.
		ob_start();
		$editor->render_editor_page();

		$this->assertStringContainsString( 'window.__WP_EXE_CONFIG__', (string) $editor->sent_html );
		$this->assertSame( $attachment_id, $this->config_value( (string) $editor->sent_html, 'attachmentId' ) );
	}

	/**
	 * A bundle whose index.html cannot be read stops the request instead of
	 * serving a half-built document.
	 */
	public function test_an_unreadable_bundle_template_stops_the_request() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$attachment_id = $this->make_elpx();
		ExeLearning_Bundle_Fixture::write( 'index.html', '' );

		$this->expectException( WPDieException::class );
		$this->editor->build_bootstrap_page( $attachment_id );
	}
}
