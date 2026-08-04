<?php
/**
 * Service class for eXeLearning style packages.
 *
 * Manages the installation, validation, listing and enable/disable state
 * of eXeLearning style ZIP packages uploaded by administrators.
 *
 * Uploaded styles live at:
 *   {uploads}/exelearning-styles/{slug}/
 *
 * The registry (built-in disable list + uploaded metadata) is persisted as
 * a WordPress option named {@see ExeLearning_Styles_Service::OPTION_REGISTRY}.
 *
 * Built-in styles are discovered by reading the bundled editor's
 * `dist/static/data/bundle.json`. No writes ever happen inside `dist/static`,
 * so reinstalling the embedded editor never destroys uploaded styles.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Styles_Service.
 */
class ExeLearning_Styles_Service {

	const OPTION_REGISTRY        = 'exelearning_styles_registry';
	const OPTION_BLOCK_IMPORT    = 'exelearning_styles_block_import';
	const OPTION_DISABLED_STYLES = 'exelearning_disabled_styles';
	const UPLOAD_SUBDIR          = 'exelearning-styles';
	const DEFAULT_MAX_ZIP_SIZE   = 20971520; // 20 MB.

	/**
	 * Absolute path to the directory that stores uploaded style bundles.
	 *
	 * @return string
	 */
	public static function get_storage_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Public URL that maps to {@see self::get_storage_dir()}.
	 *
	 * @return string
	 */
	public static function get_storage_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Maximum allowed size for an uploaded style ZIP, in bytes.
	 *
	 * Filterable via `exelearning_styles_max_zip_size` for sites with tight
	 * upload limits or large theme bundles.
	 *
	 * @return int
	 */
	public static function get_max_zip_size() {
		/**
		 * Filter the maximum uploaded style ZIP size.
		 *
		 * @param int $size Size in bytes.
		 */
		$size = (int) apply_filters( 'exelearning_styles_max_zip_size', self::DEFAULT_MAX_ZIP_SIZE );
		return $size > 0 ? $size : self::DEFAULT_MAX_ZIP_SIZE;
	}

	/**
	 * Load the persisted registry as an associative array.
	 *
	 * @return array{uploaded: array<string,array>, disabled_builtins: string[]}
	 */
	public static function get_registry() {
		$raw = get_option( self::OPTION_REGISTRY, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'uploaded'          => isset( $raw['uploaded'] ) && is_array( $raw['uploaded'] ) ? $raw['uploaded'] : array(),
			'disabled_builtins' => isset( $raw['disabled_builtins'] ) && is_array( $raw['disabled_builtins'] )
				? array_values( array_map( 'strval', $raw['disabled_builtins'] ) )
				: array(),
		);
	}

	/**
	 * Persist the registry.
	 *
	 * @param array $registry Full registry array.
	 * @return void
	 */
	public static function save_registry( array $registry ) {
		update_option( self::OPTION_REGISTRY, $registry, false );
	}

	/**
	 * Read the list of disabled style ids (built-in and uploaded).
	 *
	 * This flat list, stored in {@see self::OPTION_DISABLED_STYLES}, is the
	 * single source of truth for enable/disable state. It is registered with
	 * the Settings API and saved through the settings form on the admin
	 * screen. Legacy state (the registry's `disabled_builtins` key and the
	 * per-upload `enabled` flags) is converted once by
	 * {@see ExeLearning_Upgrader::maybe_upgrade()} and no longer read.
	 *
	 * @return string[] Normalized list of disabled style ids.
	 */
	public static function get_disabled_styles() {
		$raw = get_option( self::OPTION_DISABLED_STYLES, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id ) {
			$id = sanitize_title( (string) $id );
			if ( '' !== $id && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Whether a style (built-in or uploaded) is disabled.
	 *
	 * @param string $id Style id/slug.
	 * @return bool
	 */
	public static function is_style_disabled( $id ) {
		return in_array( self::normalize_slug( $id ), self::get_disabled_styles(), true );
	}

	/**
	 * Add or remove one style id from the disabled list.
	 *
	 * @param string $id       Style id/slug.
	 * @param bool   $disabled True to disable, false to enable.
	 * @return void
	 */
	public static function set_style_disabled( $id, $disabled ) {
		$id   = self::normalize_slug( $id );
		$list = self::get_disabled_styles();
		if ( $disabled && ! in_array( $id, $list, true ) ) {
			$list[] = $id;
		} elseif ( ! $disabled ) {
			$list = array_values( array_filter( $list, static fn( $d ) => $d !== $id ) );
		}
		sort( $list );
		update_option( self::OPTION_DISABLED_STYLES, $list, false );
	}

	/**
	 * Read the bundled editor's themes list.
	 *
	 * Returns an empty array if the editor is not installed, if the bundle
	 * file is unreadable, or if the JSON is malformed. Failure here is
	 * non-fatal: the admin UI simply shows no built-ins to disable.
	 *
	 * The bundle stores the themes array double-nested as
	 * `{ themes: { themes: [ ... ] } }` because the build script serializes
	 * the raw API response shape. Older/alternative builds used a flat
	 * array — we accept both.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_builtin_themes() {
		$bundle_path = ExeLearning_Editor_Bundle::get_path() . 'data/bundle.json';
		if ( ! file_exists( $bundle_path ) || ! is_readable( $bundle_path ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $bundle_path );
		if ( false === $json || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return self::extract_themes_from_bundle( is_array( $data ) ? $data : array() );
	}

	/**
	 * Walk a decoded bundle.json payload and return a normalized list of
	 * theme entries. Accepts both the double-nested shape the core build
	 * produces and the flat shape for forward/backward compatibility.
	 *
	 * @param array $data Decoded bundle.
	 * @return array<int, array<string,mixed>>
	 */
	public static function extract_themes_from_bundle( array $data ) {
		return ExeLearning_Style_Package::extract_themes_from_bundle( $data );
	}

	/**
	 * List uploaded styles enriched with computed URL/path info.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function list_uploaded_styles() {
		$registry = self::get_registry();
		$disabled = self::get_disabled_styles();
		$out      = array();
		foreach ( $registry['uploaded'] as $slug => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$meta['id']      = (string) $slug;
			$meta['name']    = (string) $slug;
			$meta['url']     = trailingslashit( self::get_storage_url() ) . rawurlencode( $slug );
			$meta['path']    = trailingslashit( self::get_storage_dir() ) . $slug;
			$meta['enabled'] = ! in_array( (string) $slug, $disabled, true );
			$out[]           = $meta;
		}
		return $out;
	}

	/**
	 * Build the payload consumed by the editor's themeRegistryOverride hook.
	 *
	 * @return array{disabledBuiltins: string[], uploaded: array<int, array<string,mixed>>, blockImportInstall: true, fallbackTheme: string}
	 */
	public static function build_theme_registry_override() {
		$registry = self::get_registry();
		$disabled = self::get_disabled_styles();
		$uploaded = array();
		foreach ( $registry['uploaded'] as $slug => $meta ) {
			if ( ! is_array( $meta ) || in_array( (string) $slug, $disabled, true ) ) {
				continue;
			}
			$css_files  = isset( $meta['css_files'] ) && is_array( $meta['css_files'] ) ? array_values( $meta['css_files'] ) : array( 'style.css' );
			$files      = self::list_uploaded_files( $slug );
			$style_url  = trailingslashit( self::get_storage_url() ) . rawurlencode( $slug );
			$uploaded[] = array(
				'id'           => (string) $slug,
				'name'         => (string) $slug,
				'dirName'      => (string) $slug,
				'title'        => isset( $meta['title'] ) ? (string) $meta['title'] : (string) $slug,
				'description'  => isset( $meta['description'] ) ? (string) $meta['description'] : '',
				'version'      => isset( $meta['version'] ) ? (string) $meta['version'] : '',
				'author'       => isset( $meta['author'] ) ? (string) $meta['author'] : '',
				'license'      => isset( $meta['license'] ) ? (string) $meta['license'] : '',
				'type'         => 'admin',
				'url'          => $style_url,
				'cssFiles'     => array_values( array_map( 'strval', $css_files ) ),
				'files'        => $files,
				'icons'        => self::scan_uploaded_icons( $slug, $style_url ),
				'downloadable' => '0',
				'valid'        => true,
			);
		}
		return array(
			// The full disabled list is passed as-is: the editor matches these
			// ids against built-in theme names, so disabled *uploaded* slugs in
			// the list are simply ignored on that side.
			'disabledBuiltins'   => $disabled,
			'uploaded'           => $uploaded,
			'blockImportInstall' => self::is_import_blocked(),
			'fallbackTheme'      => 'base',
		);
	}

	/**
	 * Whether the administrator has blocked user-imported styles.
	 *
	 * When true the editor hides its "User styles" tab and silently refuses
	 * to install a style bundled inside an imported .elpx project — the
	 * WordPress equivalent of eXeLearning's `ONLINE_THEMES_INSTALL=false`.
	 *
	 * Defaults to true on first install so the editor stays locked down
	 * until the admin opts in.
	 *
	 * @return bool
	 */
	public static function is_import_blocked() {
		$value = get_option( self::OPTION_BLOCK_IMPORT, null );
		if ( null === $value ) {
			return false;
		}
		return (bool) $value;
	}

	/**
	 * Persist the import-blocked toggle.
	 *
	 * @param bool $blocked New state.
	 * @return void
	 */
	public static function set_import_blocked( $blocked ) {
		update_option( self::OPTION_BLOCK_IMPORT, (bool) $blocked ? 1 : 0, false );
	}

	/**
	 * Toggle the enabled flag on an uploaded style.
	 *
	 * @param string $slug    Uploaded style slug.
	 * @param bool   $enabled New enabled state.
	 * @return true|WP_Error
	 */
	public static function set_uploaded_enabled( $slug, $enabled ) {
		$slug     = self::normalize_slug( $slug );
		$registry = self::get_registry();
		if ( ! isset( $registry['uploaded'][ $slug ] ) ) {
			return new WP_Error( 'style_not_found', __( 'Style not found.', 'exelearning' ) );
		}
		$enabled = (bool) $enabled;
		self::set_style_disabled( $slug, ! $enabled );

		/**
		 * Fires after an uploaded style has been enabled or disabled.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug    Style slug.
		 * @param bool   $enabled New enabled state.
		 */
		do_action( 'exelearning_after_style_enabled_changed', $slug, $enabled );
		return true;
	}

	/**
	 * Toggle the enabled flag on a built-in style.
	 *
	 * @param string $id      Built-in style id/name.
	 * @param bool   $enabled New enabled state (true = enabled, false = hidden).
	 * @return true
	 */
	public static function set_builtin_enabled( $id, $enabled ) {
		self::set_style_disabled( $id, ! (bool) $enabled );
		return true;
	}

	/**
	 * Delete an uploaded style (registry entry + files on disk).
	 *
	 * @param string $slug Uploaded style slug.
	 * @return true|WP_Error
	 */
	public static function delete_uploaded( $slug ) {
		$slug     = self::normalize_slug( $slug );
		$registry = self::get_registry();
		if ( ! isset( $registry['uploaded'][ $slug ] ) ) {
			return new WP_Error( 'style_not_found', __( 'Style not found.', 'exelearning' ) );
		}
		$dir = trailingslashit( self::get_storage_dir() ) . $slug;
		if ( is_dir( $dir ) ) {
			self::recursive_delete( $dir );
		}
		unset( $registry['uploaded'][ $slug ] );
		self::save_registry( $registry );
		// Drop the slug from the disabled list so a future upload reusing the
		// same slug starts out enabled.
		self::set_style_disabled( $slug, false );

		/**
		 * Fires after an uploaded style has been deleted.
		 *
		 * Runs after both the files on disk and the registry entry are removed.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug Style slug that was deleted.
		 */
		do_action( 'exelearning_after_style_deleted', $slug );
		return true;
	}

	/**
	 * Install an uploaded style ZIP.
	 *
	 * Validates the archive, extracts it into a fresh per-slug directory,
	 * records metadata in the registry, and returns the new entry.
	 *
	 * @param string $zip_path   Absolute path to the uploaded ZIP on disk.
	 * @param string $orig_name  Original filename (used as fallback slug source).
	 * @return array|WP_Error    Registry entry on success, WP_Error on failure.
	 */
	public static function install_from_zip( $zip_path, $orig_name = '' ) {
		$validation = ExeLearning_Style_Package::validate( $zip_path, self::get_max_zip_size() );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$config = $validation['config'];

		// Derive a stable slug. Prefer the theme's declared name; fall back to
		// the uploaded file's basename. Suffix on collision so we never
		// clobber an existing entry.
		$requested_slug = ! empty( $config['name'] )
			? $config['name']
			: pathinfo( $orig_name, PATHINFO_FILENAME );
		$slug           = self::allocate_unique_slug( $requested_slug );

		$dest = trailingslashit( self::get_storage_dir() ) . $slug;
		if ( ! wp_mkdir_p( $dest ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Failed to create style directory.', 'exelearning' ) );
		}

		$extract_result = ExeLearning_Style_Package::extract_safely( $zip_path, $dest, $validation['prefix'] );
		if ( is_wp_error( $extract_result ) ) {
			self::recursive_delete( $dest );
			return $extract_result;
		}

		$css_files = ExeLearning_Style_Package::find_css_files( $dest );
		if ( empty( $css_files ) ) {
			self::recursive_delete( $dest );
			return new WP_Error(
				'style_no_css',
				__( 'The uploaded style does not contain any stylesheet.', 'exelearning' )
			);
		}

		$entry = ExeLearning_Style_Package::build_entry( $config, $slug, $zip_path, $css_files );

		/**
		 * Filters the style registry entry before it is persisted.
		 *
		 * Allows integrations to enrich the stored style metadata (for example,
		 * add a category or display label). Must return an array; a non-array
		 * return is discarded. Required internal keys (css_files, enabled,
		 * installed_at, checksum, size) are always restored from the trusted
		 * built entry so this filter cannot inject unsafe paths, strip integrity
		 * fields, or bypass ZIP validation.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $entry  Style registry entry built from the validated ZIP.
		 * @param string $slug   Allocated style slug.
		 * @param array  $config Parsed style configuration (theme.json / config).
		 * @return array Registry entry. Must be an array.
		 */
		$filtered = apply_filters( 'exelearning_style_registry_entry', $entry, $slug, $config );
		if ( is_array( $filtered ) ) {
			// Restore trusted internal keys regardless of what the filter did.
			foreach ( array( 'css_files', 'enabled', 'installed_at', 'checksum', 'size' ) as $required_key ) {
				if ( array_key_exists( $required_key, $entry ) ) {
					$filtered[ $required_key ] = $entry[ $required_key ];
				}
			}
			$entry = $filtered;
		}

		$registry                      = self::get_registry();
		$registry['uploaded'][ $slug ] = $entry;
		self::save_registry( $registry );

		/**
		 * Fires after a style ZIP has been installed and registered.
		 *
		 * @since 1.0.0
		 *
		 * @param string $slug  Style slug.
		 * @param array  $entry Final style registry entry that was persisted.
		 */
		do_action( 'exelearning_after_style_installed', $slug, $entry );

		$entry['id']   = $slug;
		$entry['name'] = $slug;
		return $entry;
	}

	/**
	 * Validate an uploaded style ZIP without extracting anywhere.
	 *
	 * Returns the parsed config and the shared path prefix (either '' or a
	 * single root directory) so the caller can strip that prefix during
	 * extraction — style authors commonly wrap the package in a folder.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @return array{config: array<string,string>, prefix: string}|WP_Error
	 */
	public static function validate_zip( $zip_path ) {
		return ExeLearning_Style_Package::validate( $zip_path, self::get_max_zip_size() );
	}

	/**
	 * Parse config.xml into a sanitized associative array.
	 *
	 * @param string $xml_source Raw XML source.
	 * @return array<string,string>|WP_Error
	 */
	public static function parse_config_xml( $xml_source ) {
		return ExeLearning_Style_Package::parse_config_xml( $xml_source );
	}

	/**
	 * Flag entries that must never be extracted: absolute paths, traversal,
	 * stream schemes, backslashes, empty names.
	 *
	 * @param string $name Raw archive entry name.
	 * @return bool
	 */
	public static function is_unsafe_zip_entry( $name ) {
		return ExeLearning_Style_Package::is_unsafe_entry( $name );
	}

	/**
	 * Walk an uploaded style's extracted directory and return every file
	 * inside it as a list of forward-slash relative paths. Used to publish
	 * a per-file manifest to the embedded editor so its preview/export
	 * pipeline can fetch admin-uploaded styles without a zip bundle.
	 *
	 * @param string $slug Uploaded style slug (already validated).
	 * @return string[] Sorted relative paths; empty array if the directory is missing.
	 */
	public static function list_uploaded_files( $slug ) {
		$slug = self::normalize_slug( $slug );
		$dir  = trailingslashit( self::get_storage_dir() ) . $slug;
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$base_len = strlen( trailingslashit( $dir ) );
		$out      = array();
		try {
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iter as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}
				$absolute = (string) $file_info->getPathname();
				$relative = substr( $absolute, $base_len );
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', $relative );
				$out[]    = $relative;
			}
		} catch ( Exception $e ) {
			return array();
		}
		sort( $out );
		return $out;
	}

	/**
	 * Scan an uploaded style's `icons/` subfolder and return the editor-shape
	 * icon map. Mirrors the upstream theme-parser.ts::scanThemeIcons logic so
	 * the iDevice icon picker shows icons shipped with admin-uploaded styles
	 * (not just built-in themes, where the editor scans the folder itself).
	 *
	 * @param string $slug      Uploaded style slug (already validated upstream).
	 * @param string $style_url Absolute URL prefix at which the style is served.
	 * @return array<string, array{id:string,title:string,type:string,value:string}>
	 */
	public static function scan_uploaded_icons( $slug, $style_url ) {
		$slug = self::normalize_slug( $slug );
		$dir  = trailingslashit( self::get_storage_dir() ) . $slug . '/icons';
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return array();
		}
		$out = array();
		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'png', 'svg', 'gif', 'jpg', 'jpeg' ), true ) ) {
				continue;
			}
			$icon_id         = pathinfo( $name, PATHINFO_FILENAME );
			$out[ $icon_id ] = array(
				'id'    => $icon_id,
				'title' => $icon_id,
				'type'  => 'img',
				'value' => trailingslashit( $style_url ) . 'icons/' . rawurlencode( $name ),
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Normalize a user-supplied id so it is safe to embed in paths and URLs.
	 *
	 * @param string $slug Raw slug.
	 * @return string
	 */
	public static function normalize_slug( $slug ) {
		$slug = sanitize_title( (string) $slug );
		return '' === $slug ? 'style' : $slug;
	}

	/**
	 * Allocate a slug that does not collide with built-ins or existing uploads.
	 *
	 * @param string $requested Desired slug before disambiguation.
	 * @return string
	 */
	public static function allocate_unique_slug( $requested ) {
		$base     = self::normalize_slug( $requested );
		$builtin  = array_map(
			static fn( $t ) => strtolower( (string) ( $t['name'] ?? '' ) ),
			self::list_builtin_themes()
		);
		$registry = self::get_registry();
		$existing = array_map( 'strtolower', array_keys( $registry['uploaded'] ) );
		$taken    = array_merge( $builtin, $existing );
		$slug     = $base;
		$i        = 2;
		while ( in_array( strtolower( $slug ), $taken, true ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}

	/**
	 * Recursively delete a directory. Safe to call on a missing path.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	public static function recursive_delete( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || is_file( $dir ) ) {
			wp_delete_file( $dir );
			return;
		}
		$items = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $items as $item ) {
			self::recursive_delete( $dir . DIRECTORY_SEPARATOR . $item );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
