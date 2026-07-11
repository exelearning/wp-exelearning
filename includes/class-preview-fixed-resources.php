<?php
/**
 * Fixed-resource resolver for the editor preview (serving contract v2, layer 1).
 *
 * MIRRORS eXe core: src/services/preview-fixed-resources.ts.
 *
 * The eXe build emits `bundles/preview-fixed-resources.json` inside the static
 * editor distribution, enumerating every installation-immutable file the preview
 * serving route may resolve OUTSIDE a session (official libraries, base iDevice
 * runtimes, base themes, PDF.js, content CSS, logo, global fonts). This class is
 * the ONLY path from a `fixedResourceId` to the filesystem:
 *
 * - ids resolve by EXACT map lookup — client input never becomes a path;
 * - only `resources[id].path` (server-controlled build output) reaches the
 *   filesystem, resolved under the distribution root with containment checks
 *   that also reject symlink escapes;
 * - unknown ids are a lookup miss, never a filesystem probe.
 *
 * A missing or invalid manifest disables the fixed layer (every lookup misses):
 * revisions that carry `fixedRefs` then get a 422 and the client demotes those
 * paths to document writes. It is never fatal.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Preview_Fixed_Resources.
 *
 * Manifest-gated resolver for installation-immutable preview resources.
 */
class ExeLearning_Preview_Fixed_Resources {

	/**
	 * Manifest filename inside the static editor distribution `bundles/` dir.
	 *
	 * @var string
	 */
	const MANIFEST_FILE = 'bundles/preview-fixed-resources.json';

	/**
	 * Distribution root every manifest path must stay under (no trailing slash).
	 *
	 * @var string
	 */
	private $dist_root;

	/**
	 * Absolute path to the manifest JSON.
	 *
	 * @var string
	 */
	private $manifest_path;

	/**
	 * Lazily loaded manifest cache: fixedResourceId => array( 'path' => string ).
	 * Null until the first lookup loads (and caches) it.
	 *
	 * @var array<string,array>|null
	 */
	private $resources = null;

	/**
	 * Constructor.
	 *
	 * @param string|null $dist_root     Distribution root; defaults to the
	 *                                    installed static editor directory. Tests
	 *                                    pass a throwaway root (see the contract
	 *                                    conformance harness).
	 * @param string|null $manifest_path Manifest path; defaults to
	 *                                    `{dist_root}/bundles/preview-fixed-resources.json`.
	 */
	public function __construct( $dist_root = null, $manifest_path = null ) {
		if ( null === $dist_root ) {
			$dist_root = ExeLearning_Static_Editor_Installer::get_editor_path();
		}
		$this->dist_root     = untrailingslashit( $dist_root );
		$this->manifest_path = null !== $manifest_path
			? $manifest_path
			: trailingslashit( $this->dist_root ) . self::MANIFEST_FILE;
	}

	/**
	 * Exact-id membership test (the applyRevision validation hook).
	 *
	 * @param string $id Candidate fixedResourceId.
	 * @return bool True when the manifest declares the id.
	 */
	public function has_resource( $id ) {
		$resources = $this->load_resources();
		return isset( $resources[ $id ] );
	}

	/**
	 * All ids the manifest declares (diagnostics / conformance tooling).
	 *
	 * @return string[]
	 */
	public function get_manifest_ids() {
		return array_keys( $this->load_resources() );
	}

	/**
	 * Resolve a fixed resource id to an absolute, containment-checked file path.
	 *
	 * Returns null for unknown ids, manifest paths escaping the distribution root
	 * (path arithmetic or symlinks), and files absent on disk. Client-supplied
	 * data can only ever be the exact-match `id`.
	 *
	 * @param string $id fixedResourceId.
	 * @return string|null Absolute file path, or null.
	 */
	public function get_resource_path( $id ) {
		$resources = $this->load_resources();
		if ( ! isset( $resources[ $id ] ) ) {
			return null;
		}
		$entry = $resources[ $id ];
		if ( ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) || '' === $entry['path'] ) {
			return null;
		}

		$root_real = realpath( $this->dist_root );
		if ( false === $root_real ) {
			return null;
		}

		// Containment on the resolved (lexical) path first: rejects absolute
		// manifest paths and `..` escapes.
		$candidate = $this->lexical_resolve( $root_real, $entry['path'] );
		if ( ! $this->is_inside_root( $candidate, $root_real ) ) {
			return null;
		}

		// Then on the realpath: a symlink inside the root pointing outside it
		// must not be followed out of the distribution.
		$real = realpath( $candidate );
		if ( false === $real || ! $this->is_inside_root( $real, $root_real ) ) {
			return null;
		}
		if ( ! is_file( $real ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Load and validate the manifest once, caching the id => entry map.
	 *
	 * A missing or invalid manifest yields an empty map (fixed layer disabled).
	 *
	 * @return array<string,array>
	 */
	private function load_resources() {
		if ( null !== $this->resources ) {
			return $this->resources;
		}

		$map = array();
		if ( is_readable( $this->manifest_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a server-controlled build manifest.
			$raw    = file_get_contents( $this->manifest_path );
			$parsed = ( false !== $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $parsed )
				&& isset( $parsed['schemaVersion'] ) && 1 === (int) $parsed['schemaVersion']
				&& isset( $parsed['resources'] ) && is_array( $parsed['resources'] ) ) {
				foreach ( $parsed['resources'] as $id => $entry ) {
					if ( is_array( $entry ) && isset( $entry['path'] ) && is_string( $entry['path'] ) ) {
						$map[ (string) $id ] = array( 'path' => $entry['path'] );
					}
				}
			}
		}

		$this->resources = $map;
		return $this->resources;
	}

	/**
	 * Lexically resolve a manifest-relative path against the distribution root,
	 * collapsing `.`/`..` without touching the filesystem. An absolute input or
	 * an escape is returned as-is so the containment check below rejects it.
	 *
	 * @param string $root Real distribution root (no trailing slash).
	 * @param string $rel  Manifest path value.
	 * @return string Absolute lexical path.
	 */
	private function lexical_resolve( $root, $rel ) {
		$rel = str_replace( '\\', '/', $rel );
		// An absolute manifest path is illegal: anchor it so containment fails.
		$combined = ( '/' === substr( $rel, 0, 1 ) ) ? $rel : $root . '/' . $rel;
		$parts    = explode( '/', $combined );
		$stack    = array();
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $part;
		}
		return '/' . implode( '/', $stack );
	}

	/**
	 * True when `$child` equals `$parent` or lies strictly inside it.
	 *
	 * @param string $child  Candidate absolute path.
	 * @param string $parent Root absolute path.
	 * @return bool
	 */
	private function is_inside_root( $child, $parent ) {
		return $child === $parent || 0 === strpos( $child, $parent . '/' );
	}
}
