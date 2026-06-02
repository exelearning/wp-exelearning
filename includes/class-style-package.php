<?php
/**
 * Style package (ZIP) validation, extraction and parsing.
 *
 * Holds the archive-handling logic for {@see ExeLearning_Styles_Service} so the
 * service class stays focused on registry/state management. All methods are
 * static and side-effect free except extraction, which only writes inside the
 * destination directory it is given.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Style_Package.
 */
class ExeLearning_Style_Package {

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
	 * Validate an uploaded style ZIP without extracting anywhere.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @param int    $max_size Maximum allowed archive size in bytes.
	 * @return array{config: array<string,string>, prefix: string}|WP_Error
	 */
	public static function validate( $zip_path, $max_size ) {
		$precheck = self::precheck_archive( $zip_path, $max_size );
		if ( is_wp_error( $precheck ) ) {
			return $precheck;
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'zip_open_failed', __( 'The uploaded file is not a readable ZIP archive.', 'exelearning' ) );
		}

		$scan = self::scan_entries( $zip );
		if ( is_wp_error( $scan ) ) {
			$zip->close();
			return $scan;
		}

		$verify = self::verify_entries( $scan['entries'], $scan['prefix'] );
		if ( is_wp_error( $verify ) ) {
			$zip->close();
			return $verify;
		}

		$config_xml = $zip->getFromName( $scan['config_path'] );
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
			'prefix' => $scan['prefix'],
		);
	}

	/**
	 * Cheap pre-flight checks before opening the archive.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @param int    $max_size Maximum allowed size in bytes.
	 * @return true|WP_Error
	 */
	private static function precheck_archive( $zip_path, $max_size ) {
		if ( ! file_exists( $zip_path ) || ! is_readable( $zip_path ) ) {
			return new WP_Error( 'zip_missing', __( 'Uploaded file is missing or unreadable.', 'exelearning' ) );
		}
		$size = filesize( $zip_path );
		if ( false === $size || $size <= 0 ) {
			return new WP_Error( 'zip_empty', __( 'Uploaded file is empty.', 'exelearning' ) );
		}
		if ( $size > $max_size ) {
			return new WP_Error(
				'zip_too_large',
				sprintf(
					/* translators: %s: human-readable maximum size. */
					__( 'Uploaded style exceeds the maximum allowed size of %s.', 'exelearning' ),
					size_format( $max_size )
				)
			);
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_not_available', __( 'The ZipArchive PHP extension is not available.', 'exelearning' ) );
		}
		return true;
	}

	/**
	 * Walk the archive collecting entries and locating config.xml.
	 *
	 * @param ZipArchive $zip Opened archive.
	 * @return array{entries: array<int,array{name:string,size:int}>, config_path: string, prefix: string}|WP_Error
	 */
	private static function scan_entries( $zip ) {
		$config_path = null;
		$prefix      = '';
		$entries     = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive built-in property.
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				return new WP_Error( 'zip_bad_entry', __( 'The ZIP archive contains unreadable entries.', 'exelearning' ) );
			}
			$name = (string) $stat['name'];

			if ( self::is_unsafe_entry( $name ) ) {
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

			if ( 'config.xml' === basename( $name ) ) {
				if ( null !== $config_path ) {
					return new WP_Error( 'zip_multiple_configs', __( 'The archive contains more than one config.xml.', 'exelearning' ) );
				}
				$config_path = $name;
				$dirname     = trim( str_replace( '\\', '/', dirname( $name ) ), '/' );
				$prefix      = ( '' === $dirname || '.' === $dirname ) ? '' : $dirname . '/';
			}
		}

		if ( null === $config_path ) {
			return new WP_Error( 'zip_missing_config', __( 'The style package is missing config.xml.', 'exelearning' ) );
		}

		return array(
			'entries'     => $entries,
			'config_path' => $config_path,
			'prefix'      => $prefix,
		);
	}

	/**
	 * Ensure every archive entry lives under the package prefix and has an
	 * allow-listed extension, so a malicious archive cannot escape the package.
	 *
	 * @param array<int,array{name:string,size:int}> $entries Collected entries.
	 * @param string                                 $prefix  Shared prefix ('' for root).
	 * @return true|WP_Error
	 */
	private static function verify_entries( array $entries, $prefix ) {
		foreach ( $entries as $entry ) {
			if ( '' === $prefix ) {
				// Subdirectories at the root are allowed when config.xml is at the root.
				if ( false !== strpos( $entry['name'], '/' ) ) {
					continue;
				}
			} elseif ( 0 !== strpos( $entry['name'], $prefix ) ) {
				return new WP_Error(
					'zip_mixed_roots',
					__( 'The archive must contain a single root folder or place all files at the root.', 'exelearning' )
				);
			}
			if ( ! self::is_allowed_filename( $entry['name'] ) ) {
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
		return true;
	}

	/**
	 * Extract the archive's contents into $dest, optionally stripping $prefix.
	 *
	 * @param string $zip_path Source archive.
	 * @param string $dest     Destination directory (must exist and be writable).
	 * @param string $prefix   Shared archive prefix to strip; '' means none.
	 * @return true|WP_Error
	 */
	public static function extract_safely( $zip_path, $dest, $prefix ) {
		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			return new WP_Error( 'zip_open_failed', __( 'Failed to reopen ZIP archive.', 'exelearning' ) );
		}
		$dest_real = rtrim( wp_normalize_path( $dest ), '/' );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive built-in property.
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$name = (string) $stat['name'];
			if ( self::is_unsafe_entry( $name ) ) {
				$zip->close();
				return new WP_Error( 'zip_unsafe_entry', __( 'Refused unsafe archive entry during extraction.', 'exelearning' ) );
			}
			$relative = self::strip_prefix( $name, $prefix );
			if ( null === $relative ) {
				continue;
			}
			$result = self::write_entry( $zip, $i, $name, $relative, $dest_real );
			if ( is_wp_error( $result ) ) {
				$zip->close();
				return $result;
			}
		}
		$zip->close();
		return true;
	}

	/**
	 * Strip the shared prefix from an entry name.
	 *
	 * @param string $name   Raw entry name.
	 * @param string $prefix Shared prefix to strip ('' means none).
	 * @return string|null Relative path, or null if the entry is outside the prefix/empty.
	 */
	private static function strip_prefix( $name, $prefix ) {
		if ( '' === $prefix ) {
			return $name;
		}
		if ( 0 !== strpos( $name, $prefix ) ) {
			return null;
		}
		$relative = substr( $name, strlen( $prefix ) );
		return '' === $relative ? null : $relative;
	}

	/**
	 * Write a single archive entry to disk, guarding against traversal.
	 *
	 * @param ZipArchive $zip       Opened archive.
	 * @param int        $index     Entry index.
	 * @param string     $name      Raw entry name (trailing slash marks a directory).
	 * @param string     $relative  Prefix-stripped relative path.
	 * @param string     $dest_real Normalized destination root (no trailing slash).
	 * @return true|WP_Error
	 */
	private static function write_entry( $zip, $index, $name, $relative, $dest_real ) {
		$target = wp_normalize_path( $dest_real . '/' . ltrim( $relative, '/' ) );
		if ( 0 !== strpos( $target, $dest_real . '/' ) && $target !== $dest_real ) {
			return new WP_Error( 'zip_traversal', __( 'Refused path traversal during extraction.', 'exelearning' ) );
		}

		// Directory entry.
		if ( '/' === substr( $name, -1 ) ) {
			return wp_mkdir_p( $target )
				? true
				: new WP_Error( 'zip_mkdir_failed', __( 'Failed to create a directory from the archive.', 'exelearning' ) );
		}

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'zip_mkdir_failed', __( 'Failed to create a directory from the archive.', 'exelearning' ) );
		}

		$contents = $zip->getFromIndex( $index );
		if ( false === $contents ) {
			return new WP_Error( 'zip_read_failed', __( 'Failed to read a file from the archive.', 'exelearning' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $target, $contents ) ) {
			return new WP_Error( 'zip_write_failed', __( 'Failed to write an extracted file.', 'exelearning' ) );
		}
		return true;
	}

	/**
	 * Flag entries that must never be extracted: absolute paths, traversal,
	 * stream schemes, backslashes, empty names.
	 *
	 * @param string $name Raw archive entry name.
	 * @return bool
	 */
	public static function is_unsafe_entry( $name ) {
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
	public static function is_allowed_filename( $name ) {
		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			return false;
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
	public static function find_css_files( $dir ) {
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
	 * Parse config.xml into a sanitized associative array.
	 *
	 * @param string $xml_source Raw XML source.
	 * @return array<string,string>|WP_Error
	 */
	public static function parse_config_xml( $xml_source ) {
		$xml = self::load_xml( $xml_source );
		if ( false === $xml ) {
			return new WP_Error( 'style_bad_xml', __( 'config.xml is not valid XML.', 'exelearning' ) );
		}

		$name = isset( $xml->name ) ? trim( (string) $xml->name ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'style_missing_name', __( 'config.xml must declare a <name> element.', 'exelearning' ) );
		}

		// Sanitize free-text fields: they are persisted in the registry option and
		// echoed back into admin AJAX responses / the editor bootstrap, so they
		// must not carry active markup.
		return array(
			'name'        => sanitize_title( $name ),
			'title'       => isset( $xml->title ) ? sanitize_text_field( (string) $xml->title ) : $name,
			'version'     => isset( $xml->version ) ? sanitize_text_field( (string) $xml->version ) : '',
			'author'      => isset( $xml->author ) ? sanitize_text_field( (string) $xml->author ) : '',
			'license'     => isset( $xml->license ) ? sanitize_text_field( (string) $xml->license ) : '',
			'description' => isset( $xml->description ) ? sanitize_text_field( (string) $xml->description ) : '',
		);
	}

	/**
	 * Safely load an XML string into SimpleXML with entity loading disabled.
	 *
	 * @param string $xml_source Raw XML source.
	 * @return SimpleXMLElement|false
	 */
	private static function load_xml( $xml_source ) {
		// Reject any DOCTYPE outright: it is the entry point for XXE (external
		// entity / file:// disclosure) and entity-expansion (billion laughs).
		// config.xml is a tiny flat document that never legitimately needs one.
		if ( preg_match( '/<!DOCTYPE/i', (string) $xml_source ) ) {
			return false;
		}

		$prev_errors = libxml_use_internal_errors( true );
		$prev_entity = null;
		// On PHP < 8 also disable the external-entity loader as defense in depth.
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Only invoked on PHP < 8 where it is not deprecated.
			$prev_entity = libxml_disable_entity_loader( true );
		}
		// LIBXML_NONET blocks network access. LIBXML_NOENT is intentionally NOT
		// set: it would turn ON entity substitution, which (combined with a
		// SYSTEM entity) enables local file disclosure.
		$xml = simplexml_load_string( $xml_source, 'SimpleXMLElement', LIBXML_NONET );
		if ( null !== $prev_entity && function_exists( 'libxml_disable_entity_loader' ) ) {
			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Only invoked on PHP < 8 where it is not deprecated.
			libxml_disable_entity_loader( $prev_entity );
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );

		return $xml;
	}

	/**
	 * Walk a decoded bundle.json payload and return a normalized list of theme
	 * entries. Accepts both the double-nested shape the core build produces and
	 * the flat shape for forward/backward compatibility.
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
			$out[] = self::normalize_theme( $theme );
		}
		return $out;
	}

	/**
	 * Normalize a single theme entry from bundle.json.
	 *
	 * @param array $theme Raw theme entry (already known to have a name).
	 * @return array<string,mixed>
	 */
	private static function normalize_theme( array $theme ) {
		return array(
			'id'          => (string) $theme['name'],
			'name'        => (string) $theme['name'],
			'title'       => (string) ( $theme['title'] ?? $theme['name'] ),
			'version'     => (string) ( $theme['version'] ?? '' ),
			'description' => (string) ( $theme['description'] ?? '' ),
			'author'      => (string) ( $theme['author'] ?? '' ),
		);
	}

	/**
	 * Build the registry metadata entry for a newly installed style.
	 *
	 * @param array  $config    Parsed config.xml values.
	 * @param string $slug      Allocated slug.
	 * @param string $zip_path  Source archive (for checksum/size metadata).
	 * @param array  $css_files Discovered stylesheet filenames.
	 * @return array<string,mixed>
	 */
	public static function build_entry( array $config, $slug, $zip_path, array $css_files ) {
		// SHA-256 of the original archive so admins can spot identical reuploads.
		$checksum = @hash_file( 'sha256', $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort metadata.
		$size     = @filesize( $zip_path );            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'title'        => (string) ( $config['title'] ?? $slug ),
			'version'      => (string) ( $config['version'] ?? '' ),
			'author'       => (string) ( $config['author'] ?? '' ),
			'license'      => (string) ( $config['license'] ?? '' ),
			'description'  => (string) ( $config['description'] ?? '' ),
			'css_files'    => $css_files,
			'enabled'      => true,
			'installed_at' => gmdate( 'c' ),
			'checksum'     => is_string( $checksum ) ? 'sha256:' . $checksum : '',
			'size'         => is_int( $size ) ? $size : 0,
		);
	}
}
