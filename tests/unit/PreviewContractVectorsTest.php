<?php
/**
 * Replays the shared preview serving contract v2 conformance vectors
 * (tests/fixtures/preview-contract/vectors.json, vendored verbatim from eXe
 * core) against the WordPress implementation. See the fixture README for the
 * harness semantics.
 *
 * @package Exelearning
 */

/**
 * Class PreviewContractVectorsTest.
 *
 * @covers ExeLearning_Preview_Proxy
 * @covers ExeLearning_Preview_Session_Store
 * @covers ExeLearning_Preview_Fixed_Resources
 */
class PreviewContractVectorsTest extends WP_UnitTestCase {

	/**
	 * @var string
	 */
	private $base;

	/**
	 * @var ExeLearning_Preview_Proxy
	 */
	private $proxy;

	/**
	 * @var int
	 */
	private $author;

	public function set_up() {
		parent::set_up();
		$this->base = trailingslashit( get_temp_dir() ) . 'exe-preview-vectors-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->base . '/store' );
		wp_mkdir_p( $this->base . '/tmp' );
		wp_mkdir_p( $this->base . '/dist' );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author );
	}

	public function tear_down() {
		$this->rrmdir( $this->base );
		parent::tear_down();
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

	private function tmp_with( $bytes ) {
		$path = $this->base . '/tmp/' . wp_generate_password( 16, false );
		file_put_contents( $path, $bytes );
		return $path;
	}

	/**
	 * Replay every vector step in order against one session.
	 */
	public function test_replay_conformance_vectors() {
		$vectors = json_decode(
			file_get_contents( dirname( __DIR__ ) . '/fixtures/preview-contract/vectors.json' ),
			true
		);
		$this->assertSame( 2, $vectors['protocolVersion'] );

		$fixed = $this->materialize_fixed( $vectors['fixedResources'] );
		$store = new ExeLearning_Preview_Session_Store( $this->base . '/store' );
		$this->proxy = new ExeLearning_Preview_Proxy( $store, $fixed );

		$preview_id = null;
		foreach ( $vectors['steps'] as $step ) {
			$this->replay_step( $step, $preview_id );
		}
	}

	/**
	 * Materialize the fixed resources under a throwaway distribution root and
	 * build the manifest the resolver reads.
	 *
	 * @param array $fixed_resources id => { path, content }.
	 * @return ExeLearning_Preview_Fixed_Resources
	 */
	private function materialize_fixed( $fixed_resources ) {
		$root      = $this->base . '/dist';
		$resources = array();
		foreach ( $fixed_resources as $id => $entry ) {
			$full = $root . '/' . $entry['path'];
			wp_mkdir_p( dirname( $full ) );
			file_put_contents( $full, $entry['content'] );
			$resources[ $id ] = array(
				'path' => $entry['path'],
				'size' => strlen( $entry['content'] ),
			);
		}
		wp_mkdir_p( $root . '/bundles' );
		file_put_contents(
			$root . '/bundles/preview-fixed-resources.json',
			wp_json_encode(
				array(
					'schemaVersion' => 1,
					'buildVersion'  => 'test',
					'resources'     => $resources,
				)
			)
		);
		return new ExeLearning_Preview_Fixed_Resources( $root );
	}

	/**
	 * @param array       $step        Vector step.
	 * @param string|null $preview_id  Captured session id (by reference).
	 */
	private function replay_step( $step, &$preview_id ) {
		$id     = $step['id'];
		$method = $step['request']['method'];
		$path   = str_replace( '{previewId}', (string) $preview_id, $step['request']['path'] );

		if ( 0 === strpos( $path, '/preview/' ) && 'GET' === $method ) {
			$this->replay_serving( $step, $path, $preview_id );
			return;
		}

		// Management API.
		if ( '/api/preview-session' === $path && 'POST' === $method ) {
			$resp       = $this->proxy->management()->create_session( new WP_REST_Request( 'POST', $path ) );
			$preview_id = $resp->get_data()['previewId'];
			$this->assert_management( $step, $resp );
			return;
		}
		if ( preg_match( '#/assets$#', $path ) ) {
			$resp = $this->proxy->management()->upload_assets( $this->build_assets_request( $preview_id, $step['request']['body'] ) );
			$this->assert_management( $step, $resp );
			return;
		}
		if ( preg_match( '#/revisions$#', $path ) ) {
			$resp = $this->proxy->management()->publish_revision( $this->build_revision_request( $preview_id, $step['request']['body'] ) );
			$this->assert_management( $step, $resp );
			return;
		}
		if ( 'DELETE' === $method ) {
			$req = new WP_REST_Request( 'DELETE', $path );
			$req->set_url_params( array( 'previewId' => $preview_id ) );
			$this->assert_management( $step, $this->proxy->management()->delete_session( $req ) );
			return;
		}

		$this->fail( "Unroutable vector step {$id}: {$method} {$path}" );
	}

	/**
	 * Build the `assets` multipart request from a vector body.
	 *
	 * @param string $preview_id Session id.
	 * @param array  $body       Step body (kind assets).
	 * @return WP_REST_Request
	 */
	private function build_assets_request( $preview_id, $body ) {
		$entries  = array();
		$contents = array();
		foreach ( $body['entries'] as $entry ) {
			$entries[]  = array(
				'key'  => $entry['key'],
				'size' => strlen( $entry['content'] ),
			);
			$contents[] = $entry['content'];
		}
		return $this->multipart( $preview_id, 'assets', wp_json_encode( $entries ), $contents );
	}

	/**
	 * Build the `revision` multipart request from a vector body.
	 *
	 * @param string $preview_id Session id.
	 * @param array  $body       Step body (kind revision).
	 * @return WP_REST_Request
	 */
	private function build_revision_request( $preview_id, $body ) {
		$writes   = array();
		$contents = array();
		foreach ( $body['writes'] as $write ) {
			$writes[]   = $write['path'];
			$contents[] = $write['content'];
		}
		$revision = array_merge( $body['meta'], array( 'writes' => $writes ) );
		return $this->multipart( $preview_id, 'revision', wp_json_encode( $revision ), $contents );
	}

	/**
	 * Assemble a multipart request: one JSON field + index-aligned files[].
	 *
	 * @param string   $preview_id Session id.
	 * @param string   $field      Field name (assets|revision).
	 * @param string   $json       JSON value.
	 * @param string[] $contents   File contents.
	 * @return WP_REST_Request
	 */
	private function multipart( $preview_id, $field, $json, $contents ) {
		$req = new WP_REST_Request( 'POST', '/x' );
		$req->set_header( 'Content-Type', 'multipart/form-data; boundary=b' );
		$req->set_url_params( array( 'previewId' => $preview_id ) );
		$req->set_body_params( array( $field => $json ) );

		if ( ! empty( $contents ) ) {
			$names     = array();
			$tmp_names = array();
			$sizes     = array();
			$errors    = array();
			foreach ( $contents as $i => $bytes ) {
				$names[]     = 'f' . $i;
				$tmp_names[] = $this->tmp_with( $bytes );
				$sizes[]     = strlen( $bytes );
				$errors[]    = UPLOAD_ERR_OK;
			}
			$req->set_file_params(
				array(
					'files' => array(
						'name'     => $names,
						'tmp_name' => $tmp_names,
						'size'     => $sizes,
						'error'    => $errors,
					),
				)
			);
		}
		return $req;
	}

	/**
	 * Assert a serving step (status, headers, bodyText).
	 *
	 * @param array       $step       Vector step.
	 * @param string      $path       Substituted request path.
	 * @param string|null $preview_id Session id.
	 */
	private function replay_serving( $step, $path, $preview_id ) {
		$prefix    = '/preview/' . $preview_id . '/';
		$rel       = ( 0 === strpos( $path, $prefix ) ) ? substr( $path, strlen( $prefix ) ) : '';
		$req_head  = isset( $step['request']['headers'] ) ? $step['request']['headers'] : array();
		$headers_in = array(
			'if_none_match' => $this->ci_get( $req_head, 'If-None-Match' ),
			'range'         => $this->ci_get( $req_head, 'Range' ),
		);

		$resp   = $this->proxy->serving()->build_serve_response( $preview_id, $rel, $headers_in );
		$expect = $step['expect'];

		$this->assertSame( $expect['status'], $resp['status'], "status for {$step['id']}" );

		if ( isset( $expect['bodyText'] ) ) {
			$this->assertSame( $expect['bodyText'], $this->body_text( $resp ), "bodyText for {$step['id']}" );
		}
		if ( isset( $expect['headers'] ) ) {
			foreach ( $expect['headers'] as $name => $rule ) {
				$rule = $this->substitute_preview_id( $rule, $preview_id );
				$this->assert_header( $resp['headers'], $name, $rule, $step['id'] );
			}
		}
	}

	/**
	 * Substitute `{previewId}` in an expected header rule (e.g. the bare-root
	 * redirect's relative `Location: {previewId}/index.html`). The harness
	 * substitutes it in request paths; expected header values need it too.
	 *
	 * @param string|array $rule       Expected header rule.
	 * @param string|null  $preview_id Captured session id.
	 * @return string|array
	 */
	private function substitute_preview_id( $rule, $preview_id ) {
		if ( is_string( $rule ) ) {
			return str_replace( '{previewId}', (string) $preview_id, $rule );
		}
		if ( is_array( $rule ) ) {
			foreach ( $rule as $key => $value ) {
				if ( is_string( $value ) ) {
					$rule[ $key ] = str_replace( '{previewId}', (string) $preview_id, $value );
				}
			}
		}
		return $rule;
	}

	/**
	 * Assert a management step (status + subset body).
	 *
	 * @param array           $step Vector step.
	 * @param WP_REST_Response $resp Response.
	 */
	private function assert_management( $step, $resp ) {
		$this->assertSame( $step['expect']['status'], $resp->get_status(), "status for {$step['id']}" );
		if ( isset( $step['expect']['body'] ) ) {
			$this->assert_subset( $step['expect']['body'], $resp->get_data(), $step['id'] );
		}
	}

	private function body_text( $resp ) {
		$body = $resp['body'];
		if ( 'file' === $body['kind'] ) {
			return file_get_contents( $body['path'] );
		}
		if ( 'range' === $body['kind'] ) {
			$handle = fopen( $body['path'], 'rb' );
			fseek( $handle, $body['offset'] );
			$data = fread( $handle, $body['length'] );
			fclose( $handle );
			return $data;
		}
		return '';
	}

	private function ci_get( $headers, $name ) {
		foreach ( $headers as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return $value;
			}
		}
		return null;
	}

	private function header_lookup( $headers, $name ) {
		foreach ( $headers as $key => $value ) {
			if ( 0 === strcasecmp( $key, $name ) ) {
				return $value;
			}
		}
		return null;
	}

	private function assert_header( $headers, $name, $rule, $step_id ) {
		$value = $this->header_lookup( $headers, $name );
		if ( is_array( $rule ) ) {
			if ( isset( $rule['absent'] ) && $rule['absent'] ) {
				$this->assertNull( $value, "header {$name} should be absent ({$step_id})" );
				return;
			}
			if ( isset( $rule['startsWith'] ) ) {
				$this->assertNotNull( $value, "header {$name} missing ({$step_id})" );
				$this->assertStringStartsWith( $rule['startsWith'], $value, "header {$name} ({$step_id})" );
				return;
			}
			if ( isset( $rule['contains'] ) ) {
				$this->assertNotNull( $value, "header {$name} missing ({$step_id})" );
				$this->assertStringContainsString( $rule['contains'], $value, "header {$name} ({$step_id})" );
				return;
			}
		}
		$this->assertSame( $rule, $value, "header {$name} ({$step_id})" );
	}

	private function assert_subset( $expected, $actual, $where ) {
		if ( is_array( $expected ) ) {
			$this->assertIsArray( $actual, "expected array at {$where}" );
			if ( $this->is_list( $expected ) ) {
				$this->assertSame( $expected, $actual, "list mismatch at {$where}" );
				return;
			}
			foreach ( $expected as $key => $value ) {
				$this->assertArrayHasKey( $key, $actual, "missing key {$key} at {$where}" );
				$this->assert_subset( $value, $actual[ $key ], "{$where}/{$key}" );
			}
			return;
		}
		$this->assertSame( $expected, $actual, "value mismatch at {$where}" );
	}

	private function is_list( $array ) {
		if ( empty( $array ) ) {
			return true;
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
