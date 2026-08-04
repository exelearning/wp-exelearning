<?php
/**
 * Tests for the standalone editor screen served by ExeLearning_Editor.
 *
 * render_editor_page() is a front controller: it authenticates the request and
 * then include()s the bootstrap template and exits. The template itself cannot
 * run under PHPUnit (it tears down every output buffer and exits), so these
 * tests cover the guards that decide whether the request ever gets that far.
 * wp_die() is routed to WPDieException by the WordPress test suite.
 *
 * @package Exelearning
 */

/**
 * Class EditorPageTest.
 *
 * @covers ExeLearning_Editor
 */
class EditorPageTest extends WP_UnitTestCase {

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Editor
	 */
	private $editor;

	/**
	 * Attachment file paths to remove on tear down.
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
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		$_GET = array();
		foreach ( $this->cleanup_paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		parent::tear_down();
	}

	/**
	 * A request without a valid nonce is refused before anything else is read.
	 */
	public function test_a_missing_nonce_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertDiesWith( esc_html__( 'Security check failed.', 'exelearning' ) );
	}

	/**
	 * A forged nonce is refused just like a missing one.
	 */
	public function test_a_forged_nonce_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['_wpnonce'] = 'not-a-nonce';

		$this->assertDiesWith( esc_html__( 'Security check failed.', 'exelearning' ) );
	}

	/**
	 * A nonce alone is not enough: the user still needs upload_files.
	 */
	public function test_a_user_without_upload_files_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['_wpnonce'] = wp_create_nonce( 'exelearning_editor' );

		$this->assertDiesWith( esc_html__( 'You do not have permission to access this page.', 'exelearning' ) );
	}

	/**
	 * The editor cannot open without an attachment to edit.
	 */
	public function test_a_request_without_an_attachment_is_refused() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['_wpnonce'] = wp_create_nonce( 'exelearning_editor' );

		$this->assertDiesWith( esc_html__( 'No attachment specified.', 'exelearning' ) );
	}

	/**
	 * Only .elpx attachments can be opened in the editor.
	 */
	public function test_a_non_elpx_attachment_is_refused() {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_GET['_wpnonce']      = wp_create_nonce( 'exelearning_editor' );
		$_GET['attachment_id'] = $this->make_attachment( $user_id, 'jpg' );

		$this->assertDiesWith( esc_html__( 'This file is not an eXeLearning file (.elpx).', 'exelearning' ) );
	}

	/**
	 * A user who may upload files still cannot open somebody else's private
	 * attachment for editing.
	 */
	public function test_a_user_without_edit_rights_on_the_attachment_is_refused() {
		$owner  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$intern = $this->factory->user->create( array( 'role' => 'author' ) );

		$attachment_id = $this->make_attachment( $owner, 'elpx' );

		wp_set_current_user( $intern );
		$_GET['_wpnonce']      = wp_create_nonce( 'exelearning_editor' );
		$_GET['attachment_id'] = $attachment_id;

		$this->assertDiesWith( esc_html__( 'You do not have permission to edit this file.', 'exelearning' ) );
	}

	/**
	 * Loading any other admin screen leaves the request untouched: no buffer is
	 * opened and no early renderer is scheduled.
	 */
	public function test_no_early_renderer_is_scheduled_outside_the_editor_page() {
		$_GET = array( 'page' => 'some-other-page' );

		$level  = ob_get_level();
		$editor = new ExeLearning_Editor();

		$this->assertSame( $level, ob_get_level() );
		$this->assertFalse( has_action( 'admin_init', array( $editor, 'render_editor_page_and_exit' ) ) );
	}

	/**
	 * On the editor page the constructor takes over the request: it buffers
	 * everything WordPress prints and schedules the renderer ahead of the rest
	 * of admin_init, so stray notices cannot corrupt the standalone HTML.
	 */
	public function test_the_editor_page_is_rendered_ahead_of_admin_init() {
		$_GET           = array( 'page' => 'exelearning-editor' );
		$reporting      = error_reporting(); // phpcs:ignore
		$display_errors = ini_get( 'display_errors' );
		$level          = ob_get_level();

		$editor           = new ExeLearning_Editor();
		$level_after_boot = ob_get_level();
		$scheduled_at     = has_action( 'admin_init', array( $editor, 'render_editor_page_and_exit' ) );

		ob_end_clean();
		error_reporting( $reporting ); // phpcs:ignore
		ini_set( 'display_errors', $display_errors ); // phpcs:ignore

		$this->assertSame( $level + 1, $level_after_boot, 'Output must be captured from the very start.' );
		$this->assertSame( -999, $scheduled_at );
		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * render_editor_page_and_exit() throws away every buffered byte before
	 * handing over to the page renderer, so no stray notice can survive into
	 * the standalone HTML document.
	 *
	 * The method unwinds output buffering completely — including the runner's
	 * own buffer — so the test restores it afterwards.
	 */
	public function test_buffered_output_is_discarded_before_rendering() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		echo 'Notice: something noisy';

		$message = null;
		try {
			$this->editor->render_editor_page_and_exit();
		} catch ( WPDieException $e ) {
			$message = $e->getMessage();
		}

		$remaining = ob_get_level();
		ob_start();

		$this->assertSame( 0, $remaining, 'Every output buffer must be discarded.' );
		// The nonce guard is what stops the request; the point is that it was
		// reached with a clean slate.
		$this->assertSame( esc_html__( 'Security check failed.', 'exelearning' ), $message );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Create an attachment backed by a file with the given extension.
	 *
	 * @param int    $author_id Attachment author.
	 * @param string $extension File extension without the dot.
	 * @return int Attachment ID.
	 */
	private function make_attachment( $author_id, $extension ) {
		$attachment_id = $this->factory->attachment->create( array( 'post_author' => $author_id ) );

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'editor-page-' . $attachment_id . '.' . $extension;
		file_put_contents( $path, 'fixture' ); // phpcs:ignore
		update_attached_file( $attachment_id, $path );
		$this->cleanup_paths[] = $path;

		return $attachment_id;
	}

	/**
	 * Assert that rendering the editor page stops the request with a message.
	 *
	 * render_editor_page() closes one output buffer on entry, so the assertion
	 * opens one of its own for it to consume.
	 *
	 * @param string $expected Expected wp_die() message.
	 */
	private function assertDiesWith( $expected ) {
		$level = ob_get_level();
		ob_start();

		try {
			$this->editor->render_editor_page();
			$this->fail( 'Expected the editor page to refuse the request.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( $expected, $e->getMessage() );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}
}
