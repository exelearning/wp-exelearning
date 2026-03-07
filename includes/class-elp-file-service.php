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
			$key   = (string) $property->key;
			$value = (string) $property->value;

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

		if ( ! file_exists( $destination ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem access needed for extraction.
			mkdir( $destination, 0755, true );
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property name.
		$expected_count = $zip->numFiles;
		$result         = $zip->extractTo( $destination );
		$zip->close();

		if ( false === $result ) {
			return new WP_Error( 'elp_extract_failed', 'Failed to extract ELP file contents.' );
		}

		// Verify extraction actually produced files (e.g. PHP-WASM disk issues).
		if ( $expected_count > 0 ) {
			$items = glob( $destination . '*' );
			if ( empty( $items ) ) {
				return new WP_Error( 'elp_extract_empty', 'ZIP extraction produced no files.' );
			}
		}

		return true;
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
}
