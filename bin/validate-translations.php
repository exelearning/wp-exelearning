<?php
/**
 * Translation contract validator.
 *
 * Standalone CLI check (no WordPress bootstrap required) that guards the
 * generated JavaScript translation JSON files against drift and corruption. It
 * is run by `composer validate-translations` / `make check-translations` and in
 * CI before the WordPress environment is started.
 *
 * It verifies, for the plugin's shipped locales and JavaScript sources, that:
 *   - every hashed JSON is valid JSON in the expected Jed structure;
 *   - each JSON's declared "source" exists on disk;
 *   - the filename hash equals md5(source) — the WordPress convention;
 *   - the locale in the filename matches the locale inside the JSON;
 *   - every JavaScript source containing translatable strings has one JSON per
 *     shipped locale (no missing files);
 *   - there are no unexpected or orphaned JSON files;
 *   - every shipped locale has an .l10n.php file (the format WordPress 6.5+
 *     loads in preference to the .mo), returning a non-empty message array for
 *     the plugin's own text domain and matching locale;
 *   - there are no orphaned .l10n.php files.
 *
 * Exits 0 when the contract holds, 1 otherwise (printing every problem).
 *
 * @package Exelearning
 */

$root      = dirname( __DIR__ );
$languages = $root . '/languages';
$errors    = array();

/**
 * Directories that are never scanned for translatable JavaScript sources.
 * Kept in sync with the make-pot/i18n-audit exclude list.
 */
$excluded_dirs = array( 'vendor', 'node_modules', 'tests', 'wp', 'wp-content', 'dist', 'exelearning', 'bin', 'node_modules', '.git' );

/**
 * Discover the shipped locales from the committed PO filenames.
 *
 * @param string $languages Languages directory.
 * @return string[] Sorted list of locale codes.
 */
function exe_discover_locales( $languages ) {
	$locales = array();
	foreach ( (array) glob( $languages . '/exelearning-*.po' ) as $po ) {
		$locales[] = substr( basename( $po, '.po' ), strlen( 'exelearning-' ) );
	}
	sort( $locales );
	return $locales;
}

/**
 * Recursively collect JavaScript sources that contain i18n calls.
 *
 * @param string   $root          Plugin root.
 * @param string[] $excluded_dirs Top-level directory names to skip.
 * @return string[] Sorted, plugin-root-relative JS paths with translations.
 */
function exe_discover_js_sources( $root, $excluded_dirs ) {
	$sources  = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			function ( $current ) use ( $root, $excluded_dirs ) {
				if ( $current->isDir() ) {
					$relative = ltrim( str_replace( $root, '', $current->getPathname() ), '/\\' );
					$top      = explode( DIRECTORY_SEPARATOR, $relative )[0];
					return ! in_array( $top, $excluded_dirs, true );
				}
				return 'js' === strtolower( $current->getExtension() );
			}
		)
	);

	foreach ( $iterator as $file ) {
		$contents = file_get_contents( $file->getPathname() );
		if ( false === $contents ) {
			continue;
		}
		// JavaScript i18n calls: __(), _x(), _n(), _nx().
		if ( preg_match( '/(?<![\w$])(__|_x|_n|_nx)\s*\(/', $contents ) ) {
			$sources[] = str_replace( '\\', '/', ltrim( str_replace( $root, '', $file->getPathname() ), '/\\' ) );
		}
	}
	sort( $sources );
	return $sources;
}

$locales    = exe_discover_locales( $languages );
$js_sources = exe_discover_js_sources( $root, $excluded_dirs );

if ( empty( $locales ) ) {
	$errors[] = 'No PO files found in languages/ — cannot determine shipped locales.';
}
if ( empty( $js_sources ) ) {
	$errors[] = 'No JavaScript sources with i18n calls were found.';
}

// Build the set of JSON files WordPress should have generated.
$expected = array();
foreach ( $js_sources as $source ) {
	$hash = md5( $source );
	foreach ( $locales as $locale ) {
		$expected[ 'exelearning-' . $locale . '-' . $hash . '.json' ] = array(
			'source' => $source,
			'locale' => $locale,
			'hash'   => $hash,
		);
	}
}

// Every expected file must exist.
foreach ( $expected as $name => $meta ) {
	if ( ! is_file( $languages . '/' . $name ) ) {
		$errors[] = sprintf(
			'Missing JSON for source "%s" and locale "%s" (expected %s).',
			$meta['source'],
			$meta['locale'],
			$name
		);
	}
}

// Every JSON present in languages/ must be an expected, well-formed file.
foreach ( (array) glob( $languages . '/*.json' ) as $file ) {
	$name = basename( $file );

	if ( ! preg_match( '/^exelearning-(.+)-([0-9a-f]{32})\.json$/', $name, $parts ) ) {
		$errors[] = sprintf( 'Unexpected JSON file does not match the generated-file convention: %s', $name );
		continue;
	}

	$filename_locale = $parts[1];
	$filename_hash   = $parts[2];

	if ( ! isset( $expected[ $name ] ) ) {
		$errors[] = sprintf( 'Orphaned JSON file (no matching JavaScript source/locale): %s', $name );
		// Still run the structural checks below to surface the underlying cause.
	}

	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( null === $data ) {
		$errors[] = sprintf( 'Invalid JSON: %s', $name );
		continue;
	}

	// Jed structure.
	if ( ! isset( $data['domain'] ) || 'messages' !== $data['domain'] ) {
		$errors[] = sprintf( 'JSON %s is missing the expected Jed "domain": "messages".', $name );
	}
	if ( ! isset( $data['locale_data']['messages'][''] ) ) {
		$errors[] = sprintf( 'JSON %s is missing the expected Jed locale_data.messages metadata.', $name );
	}
	if ( empty( $data['source'] ) ) {
		$errors[] = sprintf( 'JSON %s does not declare a "source".', $name );
		continue;
	}

	$source = str_replace( '\\', '/', $data['source'] );

	// The declared source file must exist.
	if ( ! is_file( $root . '/' . $source ) ) {
		$errors[] = sprintf( 'JSON %s declares source "%s" which does not exist.', $name, $source );
	}

	// The filename hash must be md5 of the declared source path.
	$computed = md5( $source );
	if ( $computed !== $filename_hash ) {
		$errors[] = sprintf(
			'JSON %s hash mismatch: filename hash %s but md5("%s") is %s.',
			$name,
			$filename_hash,
			$source,
			$computed
		);
	}

	// The locale in the filename must match the locale inside the JSON.
	$json_locale = isset( $data['locale_data']['messages']['']['lang'] )
		? $data['locale_data']['messages']['']['lang']
		: '';
	if ( $json_locale !== $filename_locale ) {
		$errors[] = sprintf(
			'JSON %s locale mismatch: filename locale "%s" but JSON lang "%s".',
			$name,
			$filename_locale,
			$json_locale
		);
	}
}

/**
 * Load a generated .l10n.php file without leaking anything into this scope.
 *
 * @param string $file Absolute path to the PHP translation file.
 * @return mixed Whatever the file returns.
 */
function exe_load_l10n_php( $file ) {
	return include $file;
}

// Every shipped locale must have a PHP translation file.
foreach ( $locales as $locale ) {
	$name = 'exelearning-' . $locale . '.l10n.php';
	if ( ! is_file( $languages . '/' . $name ) ) {
		$errors[] = sprintf( 'Missing PHP translation file for locale "%s" (expected %s).', $locale, $name );
	}
}

// Every PHP translation file present must be expected and well-formed.
foreach ( (array) glob( $languages . '/*.l10n.php' ) as $file ) {
	$name = basename( $file );

	if ( ! preg_match( '/^exelearning-(.+)\.l10n\.php$/', $name, $parts ) ) {
		$errors[] = sprintf( 'Unexpected PHP translation file does not match the generated-file convention: %s', $name );
		continue;
	}

	$filename_locale = $parts[1];

	if ( ! in_array( $filename_locale, $locales, true ) ) {
		$errors[] = sprintf( 'Orphaned PHP translation file (no matching PO source): %s', $name );
	}

	$data = exe_load_l10n_php( $file );
	if ( ! is_array( $data ) ) {
		$errors[] = sprintf( 'PHP translation file %s does not return an array.', $name );
		continue;
	}

	// WordPress keys the loaded data by the plugin's own text domain.
	if ( ! isset( $data['domain'] ) || 'exelearning' !== $data['domain'] ) {
		$errors[] = sprintf( 'PHP translation file %s does not declare the "exelearning" text domain.', $name );
	}

	// The locale in the filename must match the locale inside the file.
	$file_locale = isset( $data['language'] ) ? $data['language'] : '';
	if ( $file_locale !== $filename_locale ) {
		$errors[] = sprintf(
			'PHP translation file %s locale mismatch: filename locale "%s" but declared language "%s".',
			$name,
			$filename_locale,
			$file_locale
		);
	}

	if ( empty( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
		$errors[] = sprintf( 'PHP translation file %s contains no messages.', $name );
	}
}

if ( empty( $errors ) ) {
	fwrite(
		STDOUT,
		sprintf(
			"Translation contract OK: %d JavaScript source(s) x %d locale(s), %d PHP translation file(s).\n",
			count( $js_sources ),
			count( $locales ),
			count( (array) glob( $languages . '/*.l10n.php' ) )
		)
	);
	exit( 0 );
}

fwrite( STDERR, "Translation contract validation failed:\n" );
foreach ( $errors as $error ) {
	fwrite( STDERR, ' - ' . $error . "\n" );
}
exit( 1 );
