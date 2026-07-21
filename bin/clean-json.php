<?php
/**
 * Remove generated JavaScript translation JSON files before regeneration.
 *
 * `wp i18n make-json` (re)writes the JSON for the sources it currently finds but
 * never deletes stale files (for example when a JavaScript source is renamed or
 * removed, or a locale is dropped). This helper deletes only files that match
 * the exact WordPress generated-file convention:
 *
 *   languages/exelearning-<locale>-<32 lowercase hex chars>.json
 *
 * so that `make-json` can regenerate a clean set. It never touches any other
 * JSON. The translation contract validator (bin/validate-translations.php)
 * guarantees that every file matching this pattern is in fact generated, which
 * is what makes deleting by convention safe.
 *
 * @package Exelearning
 */

$languages = dirname( __DIR__ ) . '/languages';
$pattern   = '#/exelearning-[A-Za-z][A-Za-z_]*-[0-9a-f]{32}\.json$#';

$candidates = glob( $languages . '/exelearning-*.json' );
if ( false === $candidates ) {
	$candidates = array();
}

$removed = 0;
foreach ( $candidates as $file ) {
	if ( preg_match( $pattern, $file ) ) {
		if ( unlink( $file ) ) {
			++$removed;
		}
	}
}

fwrite( STDOUT, sprintf( "Removed %d generated JSON file(s).\n", $removed ) );
exit( 0 );
