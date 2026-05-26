<?php
/**
 * Download format registry for eXeLearning embeds.
 *
 * Single source of truth for the formats offered by the embed download
 * split-button. The actual format generation is produced client-side by the
 * eXeLearning `SharedExporters` bundle; this class only describes the
 * options exposed in the UI and the filename suffix convention.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Download_Formats.
 */
class ExeLearning_Download_Formats {

	/**
	 * Return the canonical list of formats, in display order.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function all() {
		return array(
			array(
				'id'     => 'elpx',
				'label'  => __( 'Download .elpx', 'exelearning' ),
				'suffix' => '.elpx',
				'mime'   => 'application/zip',
				'client' => false,
			),
			array(
				'id'     => 'html5',
				'label'  => __( 'Web', 'exelearning' ),
				'suffix' => '_web.zip',
				'mime'   => 'application/zip',
				'client' => true,
			),
			array(
				'id'     => 'scorm12',
				'label'  => __( 'SCORM 1.2', 'exelearning' ),
				'suffix' => '_scorm.zip',
				'mime'   => 'application/zip',
				'client' => true,
			),
			array(
				'id'     => 'ims',
				'label'  => __( 'IMS Package', 'exelearning' ),
				'suffix' => '_ims.zip',
				'mime'   => 'application/zip',
				'client' => true,
			),
			array(
				'id'     => 'epub3',
				'label'  => __( 'EPUB3', 'exelearning' ),
				'suffix' => '.epub',
				'mime'   => 'application/epub+zip',
				'client' => true,
			),
		);
	}

	/**
	 * Default enabled format IDs.
	 *
	 * @return string[]
	 */
	public static function default_ids() {
		return array_map(
			static function ( $fmt ) {
				return $fmt['id'];
			},
			self::all()
		);
	}

	/**
	 * Sanitize a raw list of format IDs against the registry. Order is
	 * preserved relative to {@see self::all()}.
	 *
	 * @param mixed $input Raw user-supplied list (array or comma-separated string).
	 * @return string[]
	 */
	public static function sanitize( $input ) {
		if ( is_string( $input ) ) {
			$input = array_filter( array_map( 'trim', explode( ',', $input ) ) );
		}
		if ( ! is_array( $input ) ) {
			return self::default_ids();
		}
		$valid = self::default_ids();
		$set   = array_map( 'strval', $input );
		$out   = array();
		foreach ( $valid as $id ) {
			if ( in_array( $id, $set, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Look up a single format definition by id.
	 *
	 * @param string $id Format id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $fmt ) {
			if ( $fmt['id'] === $id ) {
				return $fmt;
			}
		}
		return null;
	}
}
