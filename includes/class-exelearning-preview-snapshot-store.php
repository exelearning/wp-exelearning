<?php
/**
 * Complete, expiring editor-preview snapshot store.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Stores preview snapshots outside the public uploads tree.
 */
class ExeLearning_Preview_Snapshot_Store {

	/** Idle lifetime in seconds. */
	const TTL_SECONDS = 1800;

	/**
	 * Default maximum archive entries.
	 *
	 * Matches the upload extractor (`exelearning_max_extract_files`): a package
	 * that WordPress accepted on upload must also be previewable.
	 */
	const DEFAULT_MAX_FILES = 10000;

	/**
	 * Default maximum uncompressed bytes.
	 *
	 * 1 GB, matching the upload extractor (`exelearning_max_extract_bytes`).
	 * Real course packages routinely reach 200-500 MB once their media is
	 * counted, and the previous 100 MB cap rejected them even though the same
	 * package had already been extracted on upload.
	 */
	const DEFAULT_MAX_BYTES = 1073741824;

	/**
	 * Maximum archive entries accepted for a preview snapshot.
	 *
	 * Override with the `EXELEARNING_PREVIEW_MAX_FILES` constant (wp-config.php)
	 * or the `exelearning_preview_max_files` filter. A non-positive result falls
	 * back to the default so the zip-bomb guard can never be switched off.
	 *
	 * @return int
	 */
	public static function max_files() {
		$value = defined( 'EXELEARNING_PREVIEW_MAX_FILES' )
			? (int) EXELEARNING_PREVIEW_MAX_FILES
			: self::DEFAULT_MAX_FILES;
		$value = (int) apply_filters( 'exelearning_preview_max_files', $value );
		return $value > 0 ? $value : self::DEFAULT_MAX_FILES;
	}

	/**
	 * Maximum uncompressed bytes accepted for a preview snapshot.
	 *
	 * Override with the `EXELEARNING_PREVIEW_MAX_BYTES` constant (wp-config.php)
	 * or the `exelearning_preview_max_bytes` filter. A non-positive result falls
	 * back to the default so the zip-bomb guard can never be switched off.
	 *
	 * Raising this raises the worst-case temporary disk use: roughly the limit
	 * times the number of concurrent editing sessions, reclaimed after
	 * `TTL_SECONDS`. PHP's own `post_max_size`/`upload_max_filesize` still cap
	 * the upload independently.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$value = defined( 'EXELEARNING_PREVIEW_MAX_BYTES' )
			? (int) EXELEARNING_PREVIEW_MAX_BYTES
			: self::DEFAULT_MAX_BYTES;
		$value = (int) apply_filters( 'exelearning_preview_max_bytes', $value );
		return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Private root directory.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Clock returning a Unix timestamp.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Create a snapshot store.
	 *
	 * @param string|null   $root  Storage root override for tests.
	 * @param callable|null $clock Clock override for tests.
	 */
	public function __construct( $root = null, $clock = null ) {
		$this->root  = $root ? $root : trailingslashit( get_temp_dir() ) . 'exelearning-preview';
		$this->clock = $clock ? $clock : 'time';
	}

	/**
	 * Create or atomically replace a complete snapshot.
	 *
	 * @param int         $owner_id     WordPress user id.
	 * @param int         $attachment_id Attachment id.
	 * @param string      $zip_path      Uploaded ZIP pathname.
	 * @param string|null $preview_id    Existing capability when replacing.
	 * @return string|WP_Error Capability id or validation error.
	 */
	public function replace( $owner_id, $attachment_id, $zip_path, $preview_id = null ) {
		$this->sweep_expired();
		$id         = $preview_id ? $preview_id : wp_generate_uuid4();
		$authorized = $this->authorize_replace( $id, $owner_id, $attachment_id, $preview_id );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
		// Build the whole snapshot beside the live one, then swap it in: a reader
		// either sees the previous snapshot or the new one, never a half-written
		// tree, and a failure anywhere above leaves the live one untouched.
		$staging = $this->stage_snapshot( $owner_id, $attachment_id, $zip_path );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}
		$published = $this->publish_snapshot( $staging, trailingslashit( $this->root ) . $id );
		if ( is_wp_error( $published ) ) {
			return $published;
		}
		return $id;
	}

	/**
	 * Decide whether this caller may write to a capability.
	 *
	 * Replacing demands an existing snapshot that belongs to the same user AND
	 * the same attachment; a fresh capability has no metadata to match yet.
	 *
	 * @param string      $id            Capability id.
	 * @param int         $owner_id      WordPress user id.
	 * @param int         $attachment_id Attachment id.
	 * @param string|null $preview_id    Existing capability when replacing.
	 * @return true|WP_Error
	 */
	private function authorize_replace( $id, $owner_id, $attachment_id, $preview_id ) {
		if ( ! $this->valid_id( $id ) ) {
			return new WP_Error( 'invalid_preview_id', 'Invalid preview capability.' );
		}
		$metadata = $this->metadata( $id );
		if ( $preview_id && null === $metadata ) {
			return new WP_Error( 'missing_preview', 'Preview snapshot not found.' );
		}
		if ( $metadata && ( (int) $metadata['owner_id'] !== (int) $owner_id
			|| (int) $metadata['attachment_id'] !== (int) $attachment_id ) ) {
			return new WP_Error( 'preview_forbidden', 'Preview snapshot belongs to another attachment.' );
		}
		return true;
	}

	/**
	 * Extract a snapshot and its metadata into a private staging directory.
	 *
	 * The staging directory is removed on any failure, so a rejected upload
	 * leaves nothing behind.
	 *
	 * @param int    $owner_id      WordPress user id.
	 * @param int    $attachment_id Attachment id.
	 * @param string $zip_path      Uploaded ZIP pathname.
	 * @return string|WP_Error Staging directory pathname.
	 */
	private function stage_snapshot( $owner_id, $attachment_id, $zip_path ) {
		if ( ! wp_mkdir_p( $this->root ) ) {
			return new WP_Error( 'preview_storage', 'Cannot create preview directory.' );
		}
		$staging = trailingslashit( $this->root ) . '.staging-' . bin2hex( random_bytes( 12 ) );
		if ( ! wp_mkdir_p( $staging ) ) {
			return new WP_Error( 'preview_storage', 'Cannot create preview staging directory.' );
		}
		$extracted = $this->extract( $zip_path, $staging );
		if ( is_wp_error( $extracted ) ) {
			$this->remove_tree( $staging );
			return $extracted;
		}
		$written = $this->write_metadata( $staging, $owner_id, $attachment_id );
		if ( is_wp_error( $written ) ) {
			$this->remove_tree( $staging );
			return $written;
		}
		return $staging;
	}

	/**
	 * Write the ownership record and the idle-TTL marker into a staging directory.
	 *
	 * @param string $staging       Staging directory pathname.
	 * @param int    $owner_id      WordPress user id.
	 * @param int    $attachment_id Attachment id.
	 * @return true|WP_Error
	 */
	private function write_metadata( $staging, $owner_id, $attachment_id ) {
		$metadata_json = wp_json_encode(
			array(
				'owner_id'      => (int) $owner_id,
				'attachment_id' => (int) $attachment_id,
			)
		);
		// Private atomic snapshot store on the local filesystem: WP_Filesystem
		// cannot guarantee the atomic write / rename / swap this trust boundary
		// needs, so direct PHP filesystem calls are used deliberately here.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_touch
		if ( false === file_put_contents( trailingslashit( $staging ) . '.metadata.json', $metadata_json )
			|| ! touch( trailingslashit( $staging ) . '.accessed', call_user_func( $this->clock ) ) ) {
			return new WP_Error( 'preview_storage', 'Cannot write preview metadata.' );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.file_system_operations_touch
		return true;
	}

	/**
	 * Swap a staged snapshot into place, keeping the previous one until it lands.
	 *
	 * The live tree is renamed aside rather than deleted, so if the swap fails
	 * the old snapshot is put back instead of leaving the capability empty.
	 *
	 * @param string $staging Staging directory pathname.
	 * @param string $target  Final snapshot directory pathname.
	 * @return true|WP_Error
	 */
	private function publish_snapshot( $staging, $target ) {
		$backup = $target . '.old-' . bin2hex( random_bytes( 6 ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
		if ( is_dir( $target ) && ! rename( $target, $backup ) ) {
			$this->remove_tree( $staging );
			return new WP_Error( 'preview_storage', 'Cannot replace preview snapshot.' );
		}
		if ( ! rename( $staging, $target ) ) {
			if ( is_dir( $backup ) ) {
				rename( $backup, $target );
			}
			$this->remove_tree( $staging );
			return new WP_Error( 'preview_storage', 'Cannot publish preview snapshot.' );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
		$this->remove_tree( $backup );
		return true;
	}

	/**
	 * Resolve a capability file and refresh its idle lifetime.
	 *
	 * @param string $preview_id Capability UUID.
	 * @param string $path       Relative file path.
	 * @return array{path:string,mime:string}|null
	 */
	public function get( $preview_id, $path ) {
		$this->sweep_expired();
		if ( ! $this->valid_id( $preview_id ) || null === $this->metadata( $preview_id ) ) {
			return null;
		}
		$decoded = rawurldecode( (string) $path );
		if ( ! ExeLearning_Preview_Zip_Inspector::safe_path( $decoded )
			|| ExeLearning_Preview_Zip_Inspector::reserved_path( $decoded ) ) {
			return null;
		}
		$root = realpath( trailingslashit( $this->root ) . $preview_id );
		$file = realpath( trailingslashit( $this->root ) . $preview_id . '/' . $decoded );
		if ( false === $root || false === $file || ! is_file( $file )
			|| 0 !== strpos( $file, trailingslashit( $root ) ) ) {
			return null;
		}
		touch( trailingslashit( $root ) . '.accessed', call_user_func( $this->clock ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Cheap idle-lifetime refresh on the private store.
		return array(
			'path' => $file,
			'mime' => self::mime_for( $decoded ),
		);
	}

	/**
	 * Delete a snapshot after owner and attachment validation.
	 *
	 * @param int    $owner_id      WordPress user id.
	 * @param int    $attachment_id Attachment id.
	 * @param string $preview_id    Capability UUID.
	 * @return bool|WP_Error Whether the snapshot existed.
	 */
	public function delete( $owner_id, $attachment_id, $preview_id ) {
		$metadata = $this->metadata( $preview_id );
		if ( null === $metadata ) {
			return false;
		}
		if ( (int) $metadata['owner_id'] !== (int) $owner_id
			|| (int) $metadata['attachment_id'] !== (int) $attachment_id ) {
			return new WP_Error( 'preview_forbidden', 'Preview snapshot belongs to another attachment.' );
		}
		$this->remove_tree( trailingslashit( $this->root ) . $preview_id );
		return true;
	}

	/** Remove expired capabilities. */
	public function sweep_expired() {
		if ( ! is_dir( $this->root ) ) {
			return 0;
		}
		$count   = 0;
		$entries = scandir( $this->root );
		foreach ( $entries ? $entries : array() as $id ) {
			if ( ! $this->valid_id( $id ) ) {
				continue;
			}
			$accessed = @filemtime( trailingslashit( $this->root ) . $id . '/.accessed' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing marker is expected and treated as expired below.
			if ( false === $accessed || call_user_func( $this->clock ) - $accessed > self::TTL_SECONDS ) {
				$this->remove_tree( trailingslashit( $this->root ) . $id );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Validate and extract a ZIP snapshot.
	 *
	 * @param string $zip_path ZIP pathname.
	 * @param string $target   Staging directory.
	 * @return true|WP_Error
	 */
	private function extract( $zip_path, $target ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'invalid_preview_zip', 'Invalid preview ZIP.' );
		}
		$inspection = ExeLearning_Preview_Zip_Inspector::inspect( $zip, self::max_files(), self::max_bytes() );
		if ( is_wp_error( $inspection ) ) {
			$zip->close();
			return $inspection;
		}
		if ( ! $zip->extractTo( $target ) ) {
			$zip->close();
			return new WP_Error( 'invalid_preview_zip', 'Preview ZIP could not be extracted.' );
		}
		$zip->close();
		return true;
	}






	/**
	 * Validate a UUIDv4 capability.
	 *
	 * @param string $id Capability id.
	 * @return bool
	 */
	private function valid_id( $id ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $id );
	}

	/**
	 * Read private ownership metadata.
	 *
	 * @param string $id Capability id.
	 * @return array<string,int>|null
	 */
	private function metadata( $id ) {
		if ( ! $this->valid_id( $id ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Private metadata file; missing or partial content handled below.
		$json = @file_get_contents( trailingslashit( $this->root ) . $id . '/.metadata.json' );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) && isset( $data['owner_id'], $data['attachment_id'] ) ? $data : null;
	}

	/**
	 * Select a safe MIME type from a relative path.
	 *
	 * @param string $path Relative file path.
	 * @return string MIME type.
	 */
	public static function mime_for( $path ) {
		$map       = array(
			'html'  => 'text/html; charset=utf-8',
			'htm'   => 'text/html; charset=utf-8',
			'xhtml' => 'application/xhtml+xml',
			'xml'   => 'application/xml',
			'svg'   => 'image/svg+xml',
			'css'   => 'text/css; charset=utf-8',
			'js'    => 'application/javascript; charset=utf-8',
			'mjs'   => 'application/javascript; charset=utf-8',
			'json'  => 'application/json; charset=utf-8',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'mp3'   => 'audio/mpeg',
			'ogg'   => 'audio/ogg',
			'pdf'   => 'application/pdf',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
		);
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return isset( $map[ $extension ] ) ? $map[ $extension ] : 'application/octet-stream';
	}

	/**
	 * Recursively remove a private snapshot directory.
	 *
	 * @param string $path Directory path.
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( new FilesystemIterator( $path ) as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				$this->remove_tree( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Recursive teardown of the private store tree.
			}
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive teardown of the private store tree.
	}
}
