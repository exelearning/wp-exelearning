<?php
/**
 * Run `wp i18n update-po` while preserving each .po file's PO-Revision-Date.
 *
 * `wp i18n update-po` rewrites the PO-Revision-Date header to the current time
 * on every run, which would make translation generation non-deterministic (and
 * the generated .mo files embed that timestamp). This wrapper records the
 * existing headers in memory, runs the update, and ALWAYS restores them — on
 * success, on a non-zero exit, on an uncaught error, and on SIGINT/SIGTERM — so
 * a failed or interrupted run can never leave a rewritten date behind to become
 * the baseline of the next run.
 *
 * Nothing is persisted to disk between steps: the snapshot lives only in memory,
 * so there is no temporary state file that could survive a partial failure and
 * be mistaken for the originals. Every read and write is checked, and the script
 * exits with the update command's exit code (or 1 if a .po file cannot be read
 * or restored).
 *
 * Usage:
 *   php bin/po-update.php                 # runs the default wp i18n update-po
 *   php bin/po-update.php <cmd> [args...] # runs a custom command (used by tests)
 *
 * @package Exelearning
 */

$root      = dirname( __DIR__ );
$languages = $root . '/languages';
$header    = '"PO-Revision-Date:';

$po_files = glob( $languages . '/exelearning-*.po' );
if ( false === $po_files ) {
	fwrite( STDERR, "po-update: cannot list PO files in {$languages}\n" );
	exit( 1 );
}

// 1. Snapshot the current PO-Revision-Date headers in memory (checked reads).
$original = array();
foreach ( $po_files as $po ) {
	$lines = file( $po );
	if ( false === $lines ) {
		fwrite( STDERR, "po-update: cannot read {$po}\n" );
		exit( 1 );
	}
	foreach ( $lines as $line ) {
		if ( 0 === strncmp( $line, $header, strlen( $header ) ) ) {
			$original[ $po ] = rtrim( $line, "\r\n" );
			break;
		}
	}
}

// 2. Restore routine (checked writes); runs its body at most once.
$restored = false;
$restore  = function () use ( &$restored, $header, $original ) {
	if ( $restored ) {
		return true;
	}
	$restored = true;

	$ok = true;
	foreach ( $original as $po => $revision_line ) {
		if ( ! is_file( $po ) ) {
			continue;
		}
		$lines = file( $po );
		if ( false === $lines ) {
			fwrite( STDERR, "po-update: cannot re-read {$po} to restore its date\n" );
			$ok = false;
			continue;
		}
		foreach ( $lines as $index => $line ) {
			if ( 0 === strncmp( $line, $header, strlen( $header ) ) ) {
				$eol             = ( "\r\n" === substr( $line, -2 ) ) ? "\r\n" : "\n";
				$lines[ $index ] = $revision_line . $eol;
				break;
			}
		}
		if ( false === file_put_contents( $po, implode( '', $lines ) ) ) {
			fwrite( STDERR, "po-update: cannot restore {$po}\n" );
			$ok = false;
		}
	}
	return $ok;
};

// 3. Guarantee restoration on fatal shutdown and on interruption.
register_shutdown_function( $restore );
if ( function_exists( 'pcntl_async_signals' ) ) {
	pcntl_async_signals( true );
	$on_signal = function () use ( $restore ) {
		$restore();
		exit( 130 );
	};
	pcntl_signal( SIGINT, $on_signal );
	pcntl_signal( SIGTERM, $on_signal );
}

// 4. Build the update command (overridable via argv so the restore-on-failure
// behavior can be exercised without a real wp i18n update-po run).
if ( $argc > 1 ) {
	$command = implode( ' ', array_map( 'escapeshellarg', array_slice( $argv, 1 ) ) );
} else {
	$command = 'wp i18n update-po '
		. escapeshellarg( $languages . '/exelearning.pot' ) . ' '
		. escapeshellarg( $languages . '/' );
}

// 5. Run it, always restore, and propagate the exit code (a restore failure,
// being the more damaging outcome, takes precedence over the command's code).
$exit_code = 1;
try {
	passthru( $command, $exit_code );
} finally {
	$restore_ok = $restore();
}

exit( $restore_ok ? $exit_code : 1 );
