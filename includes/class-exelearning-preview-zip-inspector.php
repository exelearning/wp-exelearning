<?php
/**
 * Safety checks for an uploaded preview ZIP.
 *
 * @package ExeLearning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a ZIP is safe to extract, and what a safe path inside it looks like.
 *
 * Kept apart from the snapshot store on purpose: the store's job is to write a
 * tree atomically, this one's is to refuse an archive before a single byte
 * reaches the disk. The rules here are the trust boundary for untrusted author
 * content, so they are worth reading in one place.
 */
class ExeLearning_Preview_Zip_Inspector {

	/**
	 * Vet every entry of an open ZIP before extraction.
	 *
	 * The whole archive is checked up front on purpose: extractTo() is all or
	 * nothing, so a limit only noticed halfway through would leave a partially
	 * written tree behind. Sizes come from the declared *uncompressed* size,
	 * which is what a zip bomb inflates to.
	 *
	 * @param ZipArchive $zip       Open archive.
	 * @param int        $max_files Maximum number of entries.
	 * @param int        $max_bytes Maximum total uncompressed size.
	 * @return true|WP_Error True when the archive is safe to extract.
	 */
	public static function inspect( ZipArchive $zip, $max_files, $max_bytes ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive API.
		$num_files = $zip->numFiles;
		if ( $num_files > $max_files ) {
			return new WP_Error( 'preview_too_large', 'Preview ZIP contains too many files.' );
		}
		$total     = 0;
		$has_index = false;
		for ( $index = 0; $index < $num_files; ++$index ) {
			$entry = self::inspect_entry( $zip, $index );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			if ( null === $entry ) {
				continue;
			}
			$total += $entry['size'];
			if ( $total > $max_bytes ) {
				return new WP_Error( 'preview_too_large', 'Preview ZIP is too large.' );
			}
			$has_index = $has_index || 'index.html' === $entry['name'];
		}
		if ( ! $has_index ) {
			return new WP_Error( 'invalid_preview_zip', 'Preview ZIP must contain index.html.' );
		}
		return true;
	}

	/**
	 * Vet a single ZIP entry.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param int        $index Entry index.
	 * @return array{name:string,size:int}|null|WP_Error Entry data, null for a
	 *                                                   directory, error when unsafe.
	 */
	private static function inspect_entry( ZipArchive $zip, $index ) {
		$name      = $zip->getNameIndex( $index );
		$stat      = $zip->statIndex( $index );
		$directory = is_string( $name ) && '/' === substr( $name, -1 );
		$validated = $directory ? rtrim( $name, '/' ) : $name;
		if ( ! is_string( $name ) || ! is_array( $stat ) || ! self::safe_path( $validated ) ) {
			return new WP_Error( 'invalid_preview_path', 'Unsafe preview ZIP path.' );
		}
		if ( $directory ) {
			return null;
		}
		if ( self::reserved_path( $name ) ) {
			return new WP_Error( 'invalid_preview_path', 'Reserved preview ZIP path.' );
		}
		if ( self::is_symlink_entry( $zip, $index ) ) {
			return new WP_Error( 'invalid_preview_path', 'Preview ZIP contains a symbolic link.' );
		}
		return array(
			'name' => $name,
			'size' => (int) ( $stat['size'] ?? 0 ),
		);
	}

	/**
	 * Whether an entry is a Unix symbolic link.
	 *
	 * A link is stored as a tiny entry whose contents are the target path, so it
	 * passes the size and path checks while pointing anywhere on the filesystem.
	 *
	 * @param ZipArchive $zip   Open archive.
	 * @param int        $index Entry index.
	 * @return bool
	 */
	private static function is_symlink_entry( ZipArchive $zip, $index ) {
		$operationsystem = 0;
		$attributes      = 0;
		return $zip->getExternalAttributesIndex( $index, $operationsystem, $attributes )
			&& ZipArchive::OPSYS_UNIX === $operationsystem
			&& 0xa000 === ( ( $attributes >> 16 ) & 0xf000 );
	}

	/**
	 * Check that a relative path is canonical and safe.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function safe_path( $path ) {
		if ( '' === $path || false !== strpos( $path, "\0" ) || '/' === $path[0] || '\\' === $path[0]
			|| false !== strpos( $path, '\\' ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check whether a path is private metadata.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function reserved_path( $path ) {
		return '.metadata.json' === $path || '.accessed' === $path;
	}
}
