<?php
/**
 * ELP File Service for eXeLearning.
 *
 * Handles validation, parsing, and extraction of .elp/.elpx files.
 * Replaces the external exelearning/elp-parser library with inline logic
 * using native PHP ZipArchive and SimpleXML.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Elp_File_Service.
 *
 * Provides methods to validate, parse, and extract .elp files.
 */
class ExeLearning_Elp_File_Service {

	/**
	 * ELP file version (always 3, v2 is not supported).
	 *
	 * @var int
	 */
	private $version;

	/**
	 * Parsed metadata fields.
	 *
	 * @var array
	 */
	private $metadata = array(
		'title'                  => '',
		'description'            => '',
		'author'                 => '',
		'license'                => '',
		'language'               => '',
		'learning_resource_type' => '',
	);

	/**
	 * Path to the current file being parsed.
	 *
	 * @var string
	 */
	private $file_path;

	/**
	 * Parses an ELP file and populates version and metadata.
	 *
	 * @param string $file_path Path to the .elp file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function parse( string $file_path ) {
		$this->file_path = $file_path;

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'elp_not_found', 'File does not exist.' );
		}

		$mime_type = mime_content_type( $file_path );
		if ( 'application/zip' !== $mime_type ) {
			return new WP_Error( 'elp_not_zip', 'The file is not a valid ZIP file.' );
		}

		$zip = $this->get_zip_instance();
		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'elp_open_failed', 'Unable to open the ZIP file.' );
		}

		// Validate structure: must contain content.xml.
		$has_content_xml = ( false !== $zip->locateName( 'content.xml' ) );

		if ( ! $has_content_xml ) {
			$has_contentv3 = ( false !== $zip->locateName( 'contentv3.xml' ) );
			$zip->close();

			if ( $has_contentv3 ) {
				return new WP_Error(
					'elp_v2_not_supported',
					__( 'This file was created with an older version of eXeLearning and is not compatible. Please open it with eXeLearning 3.x and save it again.', 'exelearning' )
				);
			}

			return new WP_Error(
				'elp_invalid',
				__( 'The file is not a valid eXeLearning file (.elpx).', 'exelearning' )
			);
		}

		$this->version = 3;

		$xml_content = $zip->getFromName( 'content.xml' );
		$zip->close();

		if ( false === $xml_content ) {
			return new WP_Error( 'elp_read_failed', 'Failed to read XML content.' );
		}

		return $this->parse_xml( $xml_content );
	}

	/**
	 * Parses XML content and extracts metadata.
	 *
	 * @param string $xml_content Raw XML string.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private function parse_xml( string $xml_content ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_content );

		if ( false === $xml ) {
			$errors = libxml_get_errors();
			libxml_clear_errors();
			$message = ! empty( $errors ) ? $errors[0]->message : 'Unknown error';
			return new WP_Error( 'elp_xml_error', 'XML Parsing error: ' . $message );
		}

		$this->extract_v3_metadata( $xml );

		return true;
	}

	/**
	 * Extracts metadata from version 3 XML format.
	 *
	 * @param SimpleXMLElement $xml XML document.
	 */
	private function extract_v3_metadata( SimpleXMLElement $xml ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML property name from ELP format.
		if ( ! isset( $xml->odeProperties ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- XML property name from ELP format.
		foreach ( $xml->odeProperties->odeProperty as $property ) {
			$key = (string) $property->key;
			// Strip any markup: these values come from an untrusted uploaded
			// .elpx and are later surfaced in admin JS (media modal) and post
			// meta. Sanitizing at parse time keeps every downstream consumer safe.
			$value = sanitize_text_field( (string) $property->value );

			switch ( $key ) {
				case 'pp_title':
					$this->metadata['title'] = $value;
					break;
				case 'pp_description':
					$this->metadata['description'] = $value;
					break;
				case 'pp_author':
					$this->metadata['author'] = $value;
					break;
				case 'license':
					$this->metadata['license'] = $value;
					break;
				case 'lom_general_language':
					$this->metadata['language'] = $value;
					break;
				case 'pp_learningResourceType':
					$this->metadata['learning_resource_type'] = $value;
					break;
			}
		}
	}

	/**
	 * Extracts the ELP file contents to a directory.
	 *
	 * @param string $file_path   Path to the .elp file.
	 * @param string $destination Directory to extract to.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function extract( string $file_path, string $destination ) {
		$zip = $this->get_zip_instance();

		if ( true !== $zip->open( $file_path ) ) {
			return new WP_Error( 'elp_open_failed', 'Unable to open ELP file for extraction.' );
		}

		if ( ! wp_mkdir_p( $destination ) ) {
			$zip->close();
			return new WP_Error( 'elp_mkdir_failed', 'Failed to create directory for extracted files.' );
		}

		// Zip-bomb guard: bound the file count (filterable).
		$max_files = (int) apply_filters( 'exelearning_max_extract_files', 10000 );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property name.
		$expected_count = $zip->numFiles;
		if ( $expected_count > $max_files ) {
			$zip->close();
			return new WP_Error( 'elp_too_many_files', 'ELP archive contains too many files.' );
		}

		/**
		 * Fires right before an .elpx archive is extracted.
		 *
		 * Intended for logging, audit trails, metrics, or external integrations.
		 * This is an observation point only: it runs after the archive has already
		 * passed open/validation and the zip-bomb count guard, and it must NOT be
		 * used to bypass validation, archive safety checks, path-traversal
		 * protection, or alter extraction behavior.
		 *
		 * @since 1.0.0
		 *
		 * @param string $file_path   Source .elpx file path.
		 * @param string $destination Destination directory the archive extracts into.
		 */
		do_action( 'exelearning_before_elpx_extract', $file_path, $destination );

		// Extract entry by entry instead of ZipArchive::extractTo(): this lets us
		// reject path traversal / absolute paths / stream wrappers, neutralize
		// symlink entries (we always write regular files), and cap the total
		// uncompressed size to prevent zip bombs.
		$result = $this->extract_entries( $zip, rtrim( wp_normalize_path( $destination ), '/' ), $expected_count );
		$zip->close();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Verify extraction actually produced files (e.g. PHP-WASM disk issues).
		if ( $expected_count > 0 ) {
			$items = glob( trailingslashit( $destination ) . '*' );
			if ( empty( $items ) ) {
				return new WP_Error( 'elp_extract_empty', 'ZIP extraction produced no files.' );
			}
		}

		/**
		 * Fires after an .elpx archive has been successfully extracted.
		 *
		 * Only runs when extraction completed without error; it never fires on a
		 * failed or partial extraction. Intended for logging, audit trails,
		 * metrics, or external integrations. It is an observation point only and
		 * must NOT be used to bypass any validation or safety check.
		 *
		 * @since 1.0.0
		 *
		 * @param string $file_path   Source .elpx file path.
		 * @param string $destination Final extraction directory.
		 * @param array  $metadata    Metadata parsed from the archive.
		 */
		do_action( 'exelearning_after_elpx_extract', $file_path, $destination, $this->to_array() );

		return true;
	}

	/**
	 * Iterate archive entries, validating and writing each into $dest_real.
	 *
	 * @param ZipArchive $zip        Opened archive.
	 * @param string     $dest_real  Normalized destination root (no trailing slash).
	 * @param int        $count      Number of entries in the archive.
	 * @return true|WP_Error
	 */
	private function extract_entries( $zip, $dest_real, $count ) {
		$max_bytes   = (int) apply_filters( 'exelearning_max_extract_bytes', 1073741824 ); // 1 GB uncompressed.
		$total_bytes = 0;

		// First pass: validate every entry before writing anything, so a forbidden
		// or unsafe entry rejects the whole archive atomically (no partial extraction).
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$name = (string) $stat['name'];
			if ( self::is_unsafe_zip_entry( $name ) ) {
				return new WP_Error( 'elp_unsafe_entry', 'Refused unsafe archive entry during extraction.' );
			}
			if ( self::is_forbidden_archive_entry( $name ) ) {
				return new WP_Error(
					'elp_forbidden_entry',
					'ELP archive contains a forbidden server-executable or server-configuration file.'
				);
			}
			$total_bytes += isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( $total_bytes > $max_bytes ) {
				return new WP_Error( 'elp_too_large', 'ELP archive is too large to extract.' );
			}
		}

		// Second pass: every entry has been validated, now write them to disk.
		for ( $i = 0; $i < $count; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				continue;
			}
			$name   = (string) $stat['name'];
			$result = $this->extract_entry( $zip, $i, $name, $dest_real );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Write a single archive entry to disk, guarding against traversal.
	 *
	 * @param ZipArchive $zip       Opened archive.
	 * @param int        $index     Entry index.
	 * @param string     $name      Raw entry name (trailing slash marks a directory).
	 * @param string     $dest_real Normalized destination root (no trailing slash).
	 * @return true|WP_Error
	 */
	private function extract_entry( $zip, $index, $name, $dest_real ) {
		$target = wp_normalize_path( $dest_real . '/' . ltrim( $name, '/' ) );

		// Defense in depth: the resolved target must stay inside the destination.
		if ( 0 !== strpos( $target, $dest_real . '/' ) && $target !== $dest_real ) {
			return new WP_Error( 'elp_traversal', 'Refused path traversal during extraction.' );
		}

		// Directory entry.
		if ( '/' === substr( $name, -1 ) ) {
			return wp_mkdir_p( $target )
				? true
				: new WP_Error( 'elp_mkdir_failed', 'Failed to create a directory from the archive.' );
		}

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'elp_mkdir_failed', 'Failed to create a directory from the archive.' );
		}

		$contents = $zip->getFromIndex( $index );
		if ( false === $contents ) {
			return new WP_Error( 'elp_read_failed', 'Failed to read a file from the archive.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write of validated, in-bounds extraction target.
		if ( false === file_put_contents( $target, $contents ) ) {
			return new WP_Error( 'elp_write_failed', 'Failed to write an extracted file.' );
		}

		return true;
	}

	/**
	 * Flag archive entries that must never be extracted.
	 *
	 * Rejects empty names, backslashes, absolute paths, stream wrappers
	 * (e.g. phar://), and any parent-directory traversal.
	 *
	 * @param string $name Raw archive entry name.
	 * @return bool True if the entry is unsafe and must be skipped.
	 */
	public static function is_unsafe_zip_entry( $name ) {
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
	 * Whether an archive entry name is forbidden even if its path is safe.
	 *
	 * These entries can turn a web-accessible extraction directory into an
	 * executable surface on some Apache/PHP configurations, especially when
	 * .htaccess is honored. The check is defense in depth on top of the
	 * path-traversal guards in is_unsafe_zip_entry(); the whole archive is
	 * rejected if any forbidden entry is present.
	 *
	 * Trailing dots and whitespace are stripped before comparison because some
	 * Windows/IIS stacks ignore them (so "shell.php." or "shell.php " can still
	 * execute). PHP-capable extensions are rejected in any position of the name
	 * (e.g. "shell.php.txt"), since Apache mod_mime with AddHandler executes a
	 * file whenever ".php" appears among its extensions; the remaining
	 * server-executable extensions are only matched as the final extension to
	 * avoid false positives on legitimate assets such as "pl.png" or "py.svg".
	 *
	 * @param string $name Archive entry name.
	 * @return bool True when the entry must not be extracted.
	 */
	public static function is_forbidden_archive_entry( $name ) {
		// Normalize separators and reduce to the basename for comparison.
		$normalized = str_replace( '\\', '/', (string) $name );
		$basename   = (string) substr( strrchr( '/' . $normalized, '/' ), 1 );
		$lower      = strtolower( $basename );
		// Strip trailing dots/whitespace that some servers ignore.
		$stripped = rtrim( $lower, " \t\n\r\0\x0B." );

		// Server-configuration files that must never be extracted.
		$forbidden_basenames = array(
			'.htaccess',
			'.htpasswd',
			'.user.ini',
			'php.ini',
			'web.config',
		);
		if ( in_array( $stripped, $forbidden_basenames, true ) ) {
			return true;
		}

		// PHP-capable extensions are dangerous in any position of the name.
		$php_family = array(
			'php',
			'php3',
			'php4',
			'php5',
			'php7',
			'php8',
			'phtml',
			'phar',
			'shtml',
		);
		foreach ( explode( '.', $stripped ) as $part ) {
			if ( in_array( trim( $part ), $php_family, true ) ) {
				return true;
			}
		}

		// Other server-executable extensions are matched as the final extension only.
		$final_extensions = array_merge(
			$php_family,
			array( 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'jspx' )
		);

		$dot = strrpos( $stripped, '.' );
		if ( false !== $dot ) {
			$extension = substr( $stripped, $dot + 1 );
			if ( in_array( $extension, $final_extensions, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Gets a ZipArchive instance.
	 *
	 * @return ZipArchive
	 */
	protected function get_zip_instance(): ZipArchive {
		return new ZipArchive();
	}

	/**
	 * Validates an .elp file and returns parsed data.
	 *
	 * @param string $file_path Path to the .elp file.
	 * @return array|WP_Error Array with parsed data or WP_Error on failure.
	 */
	public function validate_elp_file( string $file_path ) {
		$result = $this->parse( $file_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'status'  => 'valid',
			'version' => 3,
			'data'    => $this->to_array(),
		);
	}

	/**
	 * Gets the detected ELP file version.
	 *
	 * @return int ELP file version (2 or 3).
	 */
	public function get_version(): int {
		return $this->version;
	}

	/**
	 * Gets the title.
	 *
	 * @return string
	 */
	public function get_title(): string {
		return $this->metadata['title'];
	}

	/**
	 * Gets the description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->metadata['description'];
	}

	/**
	 * Gets the author.
	 *
	 * @return string
	 */
	public function get_author(): string {
		return $this->metadata['author'];
	}

	/**
	 * Gets the license.
	 *
	 * @return string
	 */
	public function get_license(): string {
		return $this->metadata['license'];
	}

	/**
	 * Gets the language.
	 *
	 * @return string
	 */
	public function get_language(): string {
		return $this->metadata['language'];
	}

	/**
	 * Gets the learning resource type.
	 *
	 * @return string
	 */
	public function get_learning_resource_type(): string {
		return $this->metadata['learning_resource_type'];
	}

	/**
	 * Converts parsed data to an array.
	 *
	 * @return array Parsed ELP file data.
	 */
	public function to_array(): array {
		return array(
			'version'              => $this->version,
			'title'                => $this->metadata['title'],
			'description'          => $this->metadata['description'],
			'author'               => $this->metadata['author'],
			'license'              => $this->metadata['license'],
			'language'             => $this->metadata['language'],
			'learningResourceType' => $this->metadata['learning_resource_type'],
		);
	}

	/**
	 * Attachment meta keys that the plugin relies on internally.
	 *
	 * These are always preserved from the trusted, pre-filter values so a
	 * third-party callback on {@see 'exelearning_elpx_metadata'} cannot drop or
	 * corrupt the data the plugin needs to locate and render extracted content.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const REQUIRED_META_KEYS = array(
		'_exelearning_title',
		'_exelearning_description',
		'_exelearning_license',
		'_exelearning_language',
		'_exelearning_resource_type',
		'_exelearning_extracted',
		'_exelearning_version',
		'_exelearning_has_preview',
	);

	/**
	 * Applies the metadata filter before ELPX metadata is persisted.
	 *
	 * Lets integrations enrich the attachment metadata array (for example, add
	 * custom keys for an LMS or catalogue) right before it is written to post
	 * meta. The return value is validated defensively: a non-array return is
	 * discarded, and every required internal key is restored from the trusted
	 * pre-filter values so a misbehaving callback can add keys but can never drop
	 * or overwrite the data the plugin depends on.
	 *
	 * @since 1.0.0
	 *
	 * @param array                        $metadata    Metadata array keyed by post meta key.
	 * @param string                       $file        Source .elpx file path, or '' when unavailable.
	 * @param ExeLearning_Elp_File_Service $elp_service The service that parsed the archive.
	 * @return array Sanitized metadata array with all required internal keys preserved.
	 */
	public static function filter_metadata( array $metadata, string $file, $elp_service ): array {
		// Snapshot trusted internal values before handing data to third parties.
		$trusted = array();
		foreach ( self::REQUIRED_META_KEYS as $key ) {
			if ( array_key_exists( $key, $metadata ) ) {
				$trusted[ $key ] = $metadata[ $key ];
			}
		}

		/**
		 * Filters the ELPX metadata array before it is saved to attachment meta.
		 *
		 * Callbacks may enrich the array with additional keys but MUST return an
		 * array. Required internal keys (see REQUIRED_META_KEYS) are always
		 * restored afterwards, so this filter cannot be used to drop or tamper
		 * with the plugin's own metadata, the extraction hash, or any security
		 * relevant value.
		 *
		 * @since 1.0.0
		 *
		 * @param array                        $metadata    Metadata array keyed by post meta key.
		 * @param string                       $file        Source .elpx file path, or '' when unavailable.
		 * @param ExeLearning_Elp_File_Service $elp_service The service that parsed the archive.
		 * @return array Enriched metadata array. Must be an array.
		 */
		$filtered = apply_filters( 'exelearning_elpx_metadata', $metadata, $file, $elp_service );

		if ( ! is_array( $filtered ) ) {
			$filtered = array();
		}

		// Restore the trusted internal keys regardless of what the filter did.
		foreach ( $trusted as $key => $value ) {
			$filtered[ $key ] = $value;
		}

		return $filtered;
	}
}
