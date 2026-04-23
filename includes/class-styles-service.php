<?php
/**
 * eXeLearning style packages service.
 *
 * Manages the installation, validation, listing and enable/disable state
 * of eXeLearning style ZIP packages uploaded by administrators.
 *
 * Uploaded styles live at:
 *   {uploads}/exelearning-styles/{slug}/
 *
 * The registry (built-in disable list + uploaded metadata) is persisted as
 * a WordPress option named {@see ExeLearning_Styles_Service::OPTION_REGISTRY}.
 *
 * Built-in styles are discovered by reading the bundled editor's
 * `dist/static/data/bundle.json`. No writes ever happen inside `dist/static`,
 * so reinstalling the embedded editor never destroys uploaded styles.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Styles_Service.
 */
class ExeLearning_Styles_Service {

	const OPTION_REGISTRY      = 'exelearning_styles_registry';
	const UPLOAD_SUBDIR        = 'exelearning-styles';
	const DEFAULT_MAX_ZIP_SIZE = 20971520; // 20 MB.

	/**
	 * File extensions allowed inside a style ZIP.
	 *
	 * @var string[]
	 */
	const ALLOWED_EXTENSIONS = array(
		'css',
		'js',
		'map',
		'svg',
		'png',
		'jpg',
		'jpeg',
		'gif',
		'webp',
		'ico',
		'xml',
		'json',
		'md',
		'txt',
		'html',
		'htm',
		'woff',
		'woff2',
		'ttf',
		'otf',
		'eot',
	);

	/**
	 * Absolute path to the directory that stores uploaded style bundles.
	 *
	 * @return string
	 */
	public static function get_storage_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Public URL that maps to {@see self::get_storage_dir()}.
	 *
	 * @return string
	 */
	public static function get_storage_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Maximum allowed size for an uploaded style ZIP, in bytes.
	 *
	 * Filterable via `exelearning_styles_max_zip_size` for sites with tight
	 * upload limits or large theme bundles.
	 *
	 * @return int
	 */
	public static function get_max_zip_size() {
		/**
		 * Filter the maximum uploaded style ZIP size.
		 *
		 * @param int $size Size in bytes.
		 */
		$size = (int) apply_filters( 'exelearning_styles_max_zip_size', self::DEFAULT_MAX_ZIP_SIZE );
		return $size > 0 ? $size : self::DEFAULT_MAX_ZIP_SIZE;
	}

	/**
	 * Load the persisted registry as an associative array.
	 *
	 * @return array{uploaded: array<string,array>, disabled_builtins: string[]}
	 */
	public static function get_registry() {
		$raw = get_option( self::OPTION_REGISTRY, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'uploaded'          => isset( $raw['uploaded'] ) && is_array( $raw['uploaded'] ) ? $raw['uploaded'] : array(),
			'disabled_builtins' => isset( $raw['disabled_builtins'] ) && is_array( $raw['disabled_builtins'] )
				? array_values( array_map( 'strval', $raw['disabled_builtins'] ) )
				: array(),
		);
	}

	/**
	 * Persist the registry.
	 *
	 * @param array $registry Full registry array.
	 * @return void
	 */
	public static function save_registry( array $registry ) {
		update_option( self::OPTION_REGISTRY, $registry, false );
	}

	/**
	 * Read the bundled editor's themes list.
	 *
	 * Returns an empty array if the editor is not installed, if the bundle
	 * file is unreadable, or if the JSON is malformed. Failure here is
	 * non-fatal: the admin UI simply shows no built-ins to disable.
	 *
	 * The bundle stores the themes array double-nested as
	 * `{ themes: { themes: [ ... ] } }` because the build script serializes
	 * the raw API response shape. Older/alternative builds used a flat
	 * array — we accept both.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_builtin_themes() {
		$bundle_path = EXELEARNING_PLUGIN_DIR . 'dist/static/data/bundle.json';
		if ( ! file_exists( $bundle_path ) || ! is_readable( $bundle_path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $bundle_path );
		if ( false === $json || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return self::extract_themes_from_bundle( is_array( $data ) ? $data : array() );
	}

	/**
	 * Walk a decoded bundle.json payload and return a normalized list of
	 * theme entries. Accepts both the double-nested shape the core build
	 * produces and the flat shape for forward/backward compatibility.
	 *
	 * @param array $data Decoded bundle.
	 * @return array<int, array<string,mixed>>
	 */
	public static function extract_themes_from_bundle( array $data ) {
		if ( empty( $data['themes'] ) ) {
			return array();
		}
		$themes = $data['themes'];
		if ( is_array( $themes ) && isset( $themes['themes'] ) && is_array( $themes['themes'] ) ) {
			$themes = $themes['themes'];
		}
		if ( ! is_array( $themes ) ) {
			return array();
		}
		$out = array();
		foreach ( $themes as $theme ) {
			if ( ! is_array( $theme ) || empty( $theme['name'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (string) $theme['name'],
				'name'        => (string) $theme['name'],
				'title'       => isset( $theme['title'] ) ? (string) $theme['title'] : (string) $theme['name'],
				'version'     => isset( $theme['version'] ) ? (string) $theme['version'] : '',
				'description' => isset( $theme['description'] ) ? (string) $theme['description'] : '',
				'author'      => isset( $theme['author'] ) ? (string) $theme['author'] : '',
			);
		}
		return $out;
	}

	/**
	 * List uploaded styles enriched with computed URL/path info.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_uploaded_styles() {
		$registry = self::get_registry();
		$out      = array();
		foreach ( $registry['uploaded'] as $slug => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$meta['id']   = (string) $slug;
			$meta['name'] = (string) $slug;
			$meta['url']  = trailingslashit( self::get_storage_url() ) . rawurlencode( $slug );
			$meta['path'] = trailingslashit( self::get_storage_dir() ) . $slug;
			$out[]        = $meta;
		}
		return $out;
	}

	/**
	 * Build the payload consumed by the editor's themeRegistryOverride hook.
	 *
	 * @return array{disabledBuiltins: string[], uploaded: array<int, array<string,mixed>>, blockImportInstall: true, fallbackTheme: string}
	 */
	public static function build_theme_registry_override() {
		$registry = self::get_registry();
		$uploaded = array();
		foreach ( $registry['uploaded'] as $slug => $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['enabled'] ) ) {
				continue;
			}
			$css_files  = isset( $meta['css_files'] ) && is_array( $meta['css_files'] ) ? array_values( $meta['css_files'] ) : array( 'style.css' );
			$uploaded[] = array(
				'id'           => (string) $slug,
				'name'         => (string) $slug,
				'dirName'      => (string) $slug,
				'title'        => isset( $meta['title'] ) ? (string) $meta['title'] : (string) $slug,
				'description'  => isset( $meta['description'] ) ? (string) $meta['description'] : '',
				'version'      => isset( $meta['version'] ) ? (string) $meta['version'] : '',
				'author'       => isset( $meta['author'] ) ? (string) $meta['author'] : '',
				'license'      => isset( $meta['license'] ) ? (string) $meta['license'] : '',
				'type'         => 'uploaded',
				'url'          => trailingslashit( self::get_storage_url() ) . rawurlencode( $slug ),
				'cssFiles'     => array_values( array_map( 'strval', $css_files ) ),
				'downloadable' => '0',
				'valid'        => true,
			);
		}
		return array(
			'disabledBuiltins'   => $registry['disabled_builtins'],
			'uploaded'           => $uploaded,
			'blockImportInstall' => true,
			'fallbackTheme'      => 'base',
		);
	}

	/**
	 * Toggle the enabled flag on an uploaded style.
	 *
	 * @param string $slug    Uploaded style slug.
	 * @param bool   $enabled New enabled state.
	 * @return true|WP_Error
	 */
	public static function set_uploaded_enabled( $slug, $enabled ) {
		$slug     = self::normalize_slug( $slug );
		$registry = self::get_registry();
		if ( ! isset( $registry['uploaded'][ $slug ] ) ) {
			return new WP_Error( 'style_not_found', __( 'Style not found.', 'exelearning' ) );
		}
		$registry['uploaded'][ $slug ]['enabled'] = (bool) $enabled;
		self::save_registry( $registry );
		return true;
	}

	/**
	 * Toggle the enabled flag on a built-in style.
	 *
	 * @param string $id      Built-in style id/name.
	 * @param bool   $enabled New enabled state (true = enabled, false = hidden).
	 * @return true
	 */
	public static function set_builtin_enabled( $id, $enabled ) {
		$id       = self::normalize_slug( $id );
		$registry = self::get_registry();
		$disabled = $registry['disabled_builtins'];
		if ( $enabled ) {
			$disabled = array_values( array_filter( $disabled, static fn( $d ) => $d !== $id ) );
		} elseif ( ! in_array( $id, $disabled, true ) ) {
			$disabled[] = $id;
		}
		$registry['disabled_builtins'] = $disabled;
		self::save_registry( $registry );
		return true;
	}

	/**
	 * Delete an uploaded style (registry entry + files on disk).
	 *
	 * @param string $slug Uploaded style slug.
	 * @return true|WP_Error
	 */
	public static function delete_uploaded( $slug ) {
		$slug     = self::normalize_slug( $slug );
		$registry = self::get_registry();
		if ( ! isset( $registry['uploaded'][ $slug ] ) ) {
			return new WP_Error( 'style_not_found', __( 'Style not found.', 'exelearning' ) );
		}
		$dir = trailingslashit( self::get_storage_dir() ) . $slug;
		if ( is_dir( $dir ) ) {
			self::recursive_delete( $dir );
		}
		unset( $registry['uploaded'][ $slug ] );
		self::save_registry( $registry );
		return true;
	}

	/**
	 * Install an uploaded style ZIP.
	 *
	 * Validates the archive, extracts it into a fresh per-slug directory,
	 * records metadata in the registry, and returns the new entry.
	 *
	 * @param string $zip_path   Absolute path to the uploaded ZIP on disk.
	 * @param string $orig_name  Original filename (used as fallback slug source).
	 * @return array|WP_Error    Registry entry on success, WP_Error on failure.
	 */
	public static function install_from_zip( $zip_path, $orig_name = '' ) {
		$validation = self::validate_zip( $zip_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = $validation['config'];
		$prefix = $validation['prefix'];

		// Derive a stable slug. Prefer the theme's declared name; fall back to
		// the uploaded file's basename. Suffix on collision so we never
		// clobber an existing entry.
		$requested_slug = ! empty( $config['name'] )
			? $config['name']
			: pathinfo( $orig_name, PATHINFO_FILENAME );
		$slug           = self::allocate_unique_slug( $requested_slug );

		$dest = trailingslashit( self::get_storage_dir() ) . $slug;
		if ( ! wp_mkdir_p( $dest ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Failed to create style directory.', 'exelearning' ) );
		}

		$extract_result = self::extract_zip_safely( $zip_path, $dest, $prefix );
		if ( is_wp_error( $extract_result ) ) {
			self::recursive_delete( $dest );
			return $extract_result;
		}

		$css_files = self::find_css_files( $dest );
		if ( empty( $css_files ) ) {
			self::recursive_delete( $dest );
			return new WP_Error(
				'style_no_css',
				__( 'The uploaded style does not contain any stylesheet.', 'exelearning' )
			);
		}

		// SHA-256 of the original archive so admins can spot identical reuploads.
		$checksum = @hash_file( 'sha256', $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort metadata.
		$size     = @filesize( $zip_path );            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$entry = array(
			'title'        => isset( $config['title'] ) ? (string) $config['title'] : $slug,
			'version'      => isset( $config['version'] ) ? (string) $config['version'] : '',
			'author'       => isset( $config['author'] ) ? (string) $config['author'] : '',
			'license'      => isset( $config['license'] ) ? (string) $config['license'] : '',
			'description'  => isset( $config['description'] ) ? (string) $config['description'] : '',
			'css_files'    => $css_files,
			'enabled'      => true,
			'installed_at' => gmdate( 'c' ),
			'checksum'     => is_string( $checksum ) ? 'sha256:' . $checksum : '',
			'size'         => is_int( $size ) ? $size : 0,
		);

		$registry                         = self::get_registry();
		$registry['uploaded'][ $slug ]    = $entry;
		self::save_registry( $registry );

		$entry['id']   = $slug;
		$entry['name'] = $slug;
		return $entry;
	}

	/**
	 * Validate an uploaded style ZIP without extracting anywhere.
	 *
	 * Returns the parsed config and the shared path prefix (either '' or a
	 * single root directory) so the caller can strip that prefix during
	 * extraction — style authors commonly wrap the package in a folder.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @return array{config: array<string,string>, prefix: string}|WP_Error
	 */
	public static function validate_zip( $zip_path ) {
		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new WP_Error( 'zip_missing', __( 'Uploaded file is missing or unreadable.', 'exelearning' ) );
		}
		$size = filesize( $zip_path );
		if ( false === $size || $size <= 0 ) {
			return new WP_Error( 'zip_empty', __( 'Uploaded file is empty.', 'exelearning' ) );
		}
		if ( $size > self::get_max_zip_size() ) {
			return new WP_Error(
				'zip_too_large',
				sprintf(
					/* translators: %s: human-readable maximum size. */
					__( 'Uploaded style exceeds the maximum allowed size of %s.', 'exelearning' ),
					size_format( self::get_max_zip_size() )
				)
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_not_available', __( 'The ZipArchive PHP extension is not available.', 'exelearning' ) );
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'zip_open_failed', __( 'The uploaded file is not a readable ZIP archive.', 'exelearning' ) );
		}

		$config_path = null;
		$prefix      = null;
		$entries     = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				$zip->close();
				return new WP_Error( 'zip_bad_entry', __( 'The ZIP archive contains unreadable entries.', 'exelearning' ) );
			}
			$name = (string) $stat['name'];

			if ( self::is_unsafe_zip_entry( $name ) ) {
				$zip->close();
				return new WP_Error(
					'zip_unsafe_entry',
					sprintf(
						/* translators: %s: offending entry name. */
						__( 'Rejected unsafe archive entry: %s', 'exelearning' ),
						$name
					)
				);
			}

			$entries[] = array(
				'name' => $name,
				'size' => isset( $stat['size'] ) ? (int) $stat['size'] : 0,
			);

			$basename = basename( $name );
			if ( 'config.xml' === $basename ) {
				if ( null !== $config_path ) {
					$zip->close();
					return new WP_Error( 'zip_multiple_configs', __( 'The archive contains more than one config.xml.', 'exelearning' ) );
				}
				$config_path = $name;
				$dirname     = trim( str_replace( '\\', '/', dirname( $name ) ), '/' );
				$prefix      = ( '' === $dirname || '.' === $dirname ) ? '' : $dirname . '/';
			}
		}

		if ( null === $config_path ) {
			$zip->close();
			return new WP_Error( 'zip_missing_config', __( 'The style package is missing config.xml.', 'exelearning' ) );
		}

		// Every entry must live under the same prefix as config.xml so a
		// malicious archive cannot sneak in files that escape the package.
		foreach ( $entries as $entry ) {
			if ( '' === $prefix ) {
				if ( false !== strpos( $entry['name'], '/' ) ) {
					// Subdirectories at the root are allowed when config.xml is at the root.
					continue;
				}
			} elseif ( 0 !== strpos( $entry['name'], $prefix ) ) {
				$zip->close();
				return new WP_Error(
					'zip_mixed_roots',
					__( 'The archive must contain a single root folder or place all files at the root.', 'exelearning' )
				);
			}
			if ( ! self::is_allowed_filename( $entry['name'] ) ) {
				$zip->close();
				return new WP_Error(
					'zip_bad_extension',
					sprintf(
						/* translators: %s: offending filename. */
						__( 'File type not allowed in style package: %s', 'exelearning' ),
						$entry['name']
					)
				);
			}
		}

		$config_xml = $zip->getFromName( $config_path );
		$zip->close();
		if ( false === $config_xml ) {
			return new WP_Error( 'zip_config_unreadable', __( 'config.xml could not be read from the archive.', 'exelearning' ) );
		}

		$parsed = self::parse_config_xml( $config_xml );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return array(
			'config' => $parsed,
			'prefix' => $prefix,
		);
	}

	/**
	 * Parse config.xml into a sanitized associative array.
	 *
	 * @param string $xml_source Raw XML source.
	 * @return array<string,string>|WP_Error
	 */
	public static function parse_config_xml( $xml_source ) {
		$prev_errors = libxml_use_internal_errors( true );
		$prev_entity = null;
		// libxml_disable_entity_loader is removed in PHP 8; by default no
		// external entities are loaded, so we simply use SimpleXML safely.
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$prev_entity = libxml_disable_entity_loader( true );
		}
		$xml = simplexml_load_string(
			$xml_source,
			'SimpleXMLElement',
			LIBXML_NONET | LIBXML_NOENT
		);
		if ( null !== $prev_entity && function_exists( 'libxml_disable_entity_loader' ) ) {
			libxml_disable_entity_loader( $prev_entity );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );

		if ( false === $xml ) {
			return new WP_Error( 'style_bad_xml', __( 'config.xml is not valid XML.', 'exelearning' ) );
		}

		$name = isset( $xml->name ) ? trim( (string) $xml->name ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'style_missing_name', __( 'config.xml must declare a <name> element.', 'exelearning' ) );
		}

		return array(
			'name'        => sanitize_title( $name ),
			'title'       => isset( $xml->title ) ? (string) $xml->title : $name,
			'version'     => isset( $xml->version ) ? (string) $xml->version : '',
			'author'      => isset( $xml->author ) ? (string) $xml->author : '',
			'license'     => isset( $xml->license ) ? (string) $xml->license : '',
			'description' => isset( $xml->description ) ? (string) $xml->description : '',
		);
	}

	/**
	 * Extract the archive's contents into $dest, optionally stripping $prefix.
	 *
	 * @param string $zip_path Source archive.
	 * @param string $dest     Destination directory (must exist and be writable).
	 * @param string $prefix   Shared archive prefix to strip; '' means none.
	 * @return true|WP_Error
	 */
	private static function extract_zip_safely( $zip_path, $dest, $prefix ) {
		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'zip_open_failed', __( 'Failed to reopen ZIP archive.', 'exelearning' ) );
		}
		$dest_real = rtrim( wp_normalize_path( $dest ), '/' );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$name = (string) $stat['name'];
			if ( self::is_unsafe_zip_entry( $name ) ) {
				$zip->close();
				return new WP_Error( 'zip_unsafe_entry', __( 'Refused unsafe archive entry during extraction.', 'exelearning' ) );
			}
			$relative = $name;
			if ( '' !== $prefix ) {
				if ( 0 !== strpos( $name, $prefix ) ) {
					continue;
				}
				$relative = substr( $name, strlen( $prefix ) );
				if ( '' === $relative ) {
					continue;
				}
			}
			$target      = $dest_real . '/' . ltrim( $relative, '/' );
			$target      = wp_normalize_path( $target );
			$target_real = wp_normalize_path( $target );
			if ( 0 !== strpos( $target_real, $dest_real . '/' ) && $target_real !== $dest_real ) {
				$zip->close();
				return new WP_Error( 'zip_traversal', __( 'Refused path traversal during extraction.', 'exelearning' ) );
			}
			if ( '/' === substr( $name, -1 ) ) {
				if ( ! wp_mkdir_p( $target ) ) {
					$zip->close();
					return new WP_Error( 'zip_mkdir_failed', __( 'Failed to create a directory from the archive.', 'exelearning' ) );
				}
				continue;
			}
			$parent = dirname( $target );
			if ( ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				return new WP_Error( 'zip_mkdir_failed', __( 'Failed to create a directory from the archive.', 'exelearning' ) );
			}
			$contents = $zip->getFromIndex( $i );
			if ( false === $contents ) {
				$zip->close();
				return new WP_Error( 'zip_read_failed', __( 'Failed to read a file from the archive.', 'exelearning' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $target, $contents );
			if ( false === $written ) {
				$zip->close();
				return new WP_Error( 'zip_write_failed', __( 'Failed to write an extracted file.', 'exelearning' ) );
			}
		}
		$zip->close();
		return true;
	}

	/**
	 * Flag entries that must never be extracted: absolute paths, traversal,
	 * stream schemes, backslashes, empty names.
	 *
	 * @param string $name Raw archive entry name.
	 * @return bool
	 */
	private static function is_unsafe_zip_entry( $name ) {
		if ( '' === $name ) {
			return true;
		}
		if ( false !== strpos( $name, '\\' ) ) {
			return true;
		}
		if ( 0 === strpos( $name, '/' ) ) {
			return true;
		}
		if ( preg_match( '#^[a-zA-Z]+://#', $name ) ) {
			return true;
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $name ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a file inside the archive has an allow-listed extension.
	 *
	 * Directory entries (trailing slash) are always allowed.
	 *
	 * @param string $name Entry name.
	 * @return bool
	 */
	private static function is_allowed_filename( $name ) {
		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			// Disallow extensionless files; style packages only need typed assets.
			return false;
		}
		return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
	}

	/**
	 * Scan the extracted directory for available stylesheets.
	 *
	 * The editor prioritizes `style.css`; if that's present it's listed first.
	 *
	 * @param string $dir Directory to scan.
	 * @return string[] File names relative to $dir.
	 */
	private static function find_css_files( $dir ) {
		$dir = trailingslashit( $dir );
		$out = array();
		if ( file_exists( $dir . 'style.css' ) ) {
			$out[] = 'style.css';
		}
		$glob = glob( $dir . '*.css' );
		if ( is_array( $glob ) ) {
			foreach ( $glob as $file ) {
				$base = basename( $file );
				if ( ! in_array( $base, $out, true ) ) {
					$out[] = $base;
				}
			}
		}
		return $out;
	}

	/**
	 * Normalize a user-supplied id so it is safe to embed in paths and URLs.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function normalize_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );
		return '' === $slug ? 'style' : $slug;
	}

	/**
	 * Allocate a slug that does not collide with built-ins or existing uploads.
	 *
	 * @param string $requested Desired slug before disambiguation.
	 * @return string
	 */
	public static function allocate_unique_slug( $requested ) {
		$base    = self::normalize_slug( $requested );
		$builtin = array_map(
			static fn( $t ) => strtolower( (string) ( $t['name'] ?? '' ) ),
			self::list_builtin_themes()
		);
		$registry = self::get_registry();
		$existing = array_map( 'strtolower', array_keys( $registry['uploaded'] ) );
		$taken    = array_merge( $builtin, $existing );
		$slug     = $base;
		$i        = 2;
		while ( in_array( strtolower( $slug ), $taken, true ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}

	/**
	 * Recursively delete a directory. Safe to call on a missing path.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		$items = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $items as $item ) {
			self::recursive_delete( $dir . DIRECTORY_SEPARATOR . $item );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
