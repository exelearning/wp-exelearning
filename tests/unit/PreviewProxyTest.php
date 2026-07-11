<?php
/**
 * Tests for ExeLearning_Preview_Proxy (serving contract v2 route registrar).
 *
 * The proxy is now a thin registrar: it wires the serving + management routes
 * and the cleanup cron to two focused controllers. The controllers' own
 * behaviour is exercised by PreviewServingControllerTest,
 * PreviewManagementControllerTest and PreviewHttpHeadersTest; the conformance
 * vectors are replayed by PreviewContractVectorsTest.
 *
 * @package Exelearning
 */

/**
 * Class PreviewProxyTest.
 *
 * @covers ExeLearning_Preview_Proxy
 */
class PreviewProxyTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/exelearning/v1/preview-session', $routes );

		$has_serving   = false;
		$has_assets    = false;
		$has_revisions = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/exelearning/v1/preview/' ) ) {
				$has_serving = true;
			}
			if ( false !== strpos( $route, '/assets' ) && false !== strpos( $route, 'preview-session' ) ) {
				$has_assets = true;
			}
			if ( false !== strpos( $route, '/revisions' ) && false !== strpos( $route, 'preview-session' ) ) {
				$has_revisions = true;
			}
		}
		$this->assertTrue( $has_serving, 'serving route registered' );
		$this->assertTrue( $has_assets, 'assets route registered' );
		$this->assertTrue( $has_revisions, 'revisions route registered' );
	}

	public function test_register_cron_schedule_adds_interval() {
		$schedules = ExeLearning_Preview_Proxy::register_cron_schedule( array() );
		$this->assertArrayHasKey( ExeLearning_Preview_Proxy::CRON_SCHEDULE, $schedules );
		$this->assertSame( 900, $schedules[ ExeLearning_Preview_Proxy::CRON_SCHEDULE ]['interval'] );
	}

	public function test_register_cron_schedule_is_idempotent() {
		$existing  = array( ExeLearning_Preview_Proxy::CRON_SCHEDULE => array( 'interval' => 1, 'display' => 'kept' ) );
		$schedules = ExeLearning_Preview_Proxy::register_cron_schedule( $existing );
		$this->assertSame( 1, $schedules[ ExeLearning_Preview_Proxy::CRON_SCHEDULE ]['interval'] );
	}

	public function test_constructor_wires_the_cleanup_cron_hook() {
		new ExeLearning_Preview_Proxy();
		$this->assertNotFalse( has_action( ExeLearning_Preview_Proxy::CRON_HOOK ) );
	}

	public function test_getters_return_the_two_controllers() {
		$proxy = new ExeLearning_Preview_Proxy();
		$this->assertInstanceOf( ExeLearning_Preview_Management_Controller::class, $proxy->management() );
		$this->assertInstanceOf( ExeLearning_Preview_Serving_Controller::class, $proxy->serving() );
	}

	public function test_uninjected_proxy_builds_working_controllers() {
		$proxy = new ExeLearning_Preview_Proxy();
		// Serving: a bad capability id is a hardened 404 (exercises lazy deps).
		$this->assertSame( 404, $proxy->serving()->build_serve_response( 'not-a-valid-uuid', 'index.html' )['status'] );
		// Management: the capability gate is reachable.
		$this->assertTrue( $proxy->management()->check_manage_permission() );
	}

	public function test_injected_store_and_resolver_are_shared_by_both_controllers() {
		$base = trailingslashit( get_temp_dir() ) . 'exe-preview-proxy-' . wp_generate_password( 8, false );
		wp_mkdir_p( $base . '/store' );
		wp_mkdir_p( $base . '/dist' );
		$store = new ExeLearning_Preview_Session_Store( $base . '/store' );
		$fixed = new ExeLearning_Preview_Fixed_Resources( $base . '/dist' );
		$proxy = new ExeLearning_Preview_Proxy( $store, $fixed );

		// A session created through the management side is served by the serving
		// side — proving both controllers share the one injected store.
		$id = $proxy->management()->create_session( new WP_REST_Request( 'POST', '/x' ) )->get_data()['previewId'];
		$tmp = $base . '/index.tmp';
		file_put_contents( $tmp, '<html>shared</html>' );
		$store->apply_revision(
			$id,
			array(
				'baseRevision' => 0,
				'nextRevision' => 1,
				'writes'       => array( array( 'path' => 'index.html', 'tmp_path' => $tmp ) ),
				'deletes'      => array(),
				'assetRefs'    => array(),
				'fixedRefs'    => array(),
			),
			$fixed
		);
		$this->assertSame( 200, $proxy->serving()->build_serve_response( $id, 'index.html' )['status'] );

		$this->rrmdir( $base );
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}
}
