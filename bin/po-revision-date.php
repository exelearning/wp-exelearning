<?php
/**
 * Preserve PO-Revision-Date headers across `wp i18n update-po`.
 *
 * `wp i18n update-po` unconditionally rewrites the `PO-Revision-Date` header to
 * the current time, which makes the translation generation workflow
 * non-deterministic (running it twice would produce a different working tree,
 * and the generated .mo files embed that timestamp). This helper snapshots the
 * existing revision dates before update-po runs and restores them afterwards,
 * so the header stays under human control and the workflow is reproducible.
 *
 * Usage:
 *   php bin/po-revision-date.php snapshot
 *   wp i18n update-po ...
 *   php bin/po-revision-date.php restore
 *
 * @package Exelearning
 */

$mode = isset( $argv[1] ) ? $argv[1] : '';
if ( 'snapshot' !== $mode && 'restore' !== $mode ) {
	fwrite( STDERR, "usage: po-revision-date.php snapshot|restore\n" );
	exit( 1 );
}

$root      = dirname( __DIR__ );
$languages = $root . '/languages';
$snapshot  = $languages . '/.po-revision-dates.json';
$header    = '"PO-Revision-Date:';

$po_files = glob( $languages . '/exelearning-*.po' );
if ( false === $po_files ) {
	$po_files = array();
}

/**
 * Return the first line of a PO file that carries the given header, or null.
 *
 * @param string $file   PO file path.
 * @param string $needle Header prefix to look for.
 * @return string|null The full header line (without EOL), or null.
 */
function exe_po_header_line( $file, $needle ) {
	$lines = file( $file );
	if ( false === $lines ) {
		return null;
	}
	foreach ( $lines as $line ) {
		if ( 0 === strpos( $line, $needle ) ) {
			return rtrim( $line, "\r\n" );
		}
	}
	return null;
}

if ( 'snapshot' === $mode ) {
	$map = array();
	foreach ( $po_files as $po ) {
		$line = exe_po_header_line( $po, $header );
		if ( null !== $line ) {
			$map[ basename( $po ) ] = $line;
		}
	}
	file_put_contents( $snapshot, json_encode( $map ) );
	exit( 0 );
}

// restore.
if ( ! is_file( $snapshot ) ) {
	exit( 0 );
}

$map = json_decode( file_get_contents( $snapshot ), true );
if ( ! is_array( $map ) ) {
	$map = array();
}

foreach ( $map as $name => $original_line ) {
	$po = $languages . '/' . $name;
	if ( ! is_file( $po ) ) {
		continue;
	}
	$lines = file( $po );
	if ( false === $lines ) {
		continue;
	}
	foreach ( $lines as $index => $line ) {
		if ( 0 === strpos( $line, $header ) ) {
			$eol            = ( "\r\n" === substr( $line, -2 ) ) ? "\r\n" : "\n";
			$lines[ $index ] = $original_line . $eol;
			break;
		}
	}
	file_put_contents( $po, implode( '', $lines ) );
}

unlink( $snapshot );
exit( 0 );
