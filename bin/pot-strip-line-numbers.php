<?php
/**
 * Reduce `#:` source references in the POT file to file names only.
 *
 * `wp i18n make-pot` writes `#: file:line` references, so any code change that
 * shifts a translatable string's line rewrote every catalog. `wp i18n make-json`
 * still needs the file names to map JavaScript strings to their scripts, so the
 * references are kept, without line numbers and without repeated files.
 *
 * @package Exelearning
 */

$pot   = dirname( __DIR__ ) . '/languages/exelearning.pot';
$lines = file( $pot );
if ( false === $lines ) {
	fwrite( STDERR, "pot-strip-line-numbers: cannot read {$pot}\n" );
	exit( 1 );
}

foreach ( $lines as $i => $line ) {
	if ( 0 === strncmp( $line, '#: ', 3 ) ) {
		$files       = preg_replace( '/:\d+$/', '', preg_split( '/\s+/', trim( substr( $line, 3 ) ) ) );
		$lines[ $i ] = '#: ' . implode( ' ', array_unique( $files ) ) . "\n";
	}
}

if ( false === file_put_contents( $pot, implode( '', $lines ) ) ) {
	fwrite( STDERR, "pot-strip-line-numbers: cannot write {$pot}\n" );
	exit( 1 );
}
exit( 0 );
