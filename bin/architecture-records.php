<?php
/**
 * Architecture record validation and index generation.
 *
 * Standalone CLI tool (no WordPress bootstrap required) that discovers the
 * Architecture Decision Records under `docs/architecture/adr/` and the change
 * directories under `docs/architecture/changes/`, validates their identifiers,
 * metadata and cross-references, and generates the two indexes.
 *
 * Usage:
 *   php bin/architecture-records.php list    # print the record index
 *   php bin/architecture-records.php check   # validate, non-zero on failure
 *
 * Run through `make architecture-records` / `make architecture-check`, or
 * `composer architecture-records` / `composer architecture-check`.
 *
 * The index is deliberately NOT a committed file. It is contributor-facing, it
 * is derived entirely from frontmatter, and a generated file checked into git
 * is a guaranteed merge conflict on every concurrent branch.
 *
 * The identification model is `<tracking-number>`-based and is documented in
 * `docs/architecture/adr/README.md` and `docs/architecture/changes/README.md`.
 * It follows the decision taken in the main repository,
 * `exelearning/exelearning#2232` (ADR-2232-01). Deviations from that reference
 * implementation, and why this repository needs them, are listed in
 * `docs/architecture/migration-map.md`.
 *
 * PHP rather than Bun/TypeScript on purpose: this repository already has PHP
 * and Composer in every developer environment and in CI, and adding a second
 * runtime just to lint documentation would be a new dependency for no gain.
 *
 * @package Exelearning
 */

declare( strict_types = 1 );

/**
 * Discovery, validation and index rendering for architecture records.
 *
 * Every method is static and side-effect free except `run()` and the private
 * filesystem/git helpers, so the whole surface is unit-testable from
 * `tests/unit/ArchitectureRecordsTest.php` without touching the repository.
 */
final class ExeLearning_Architecture_Records {

	/**
	 * Directory holding Architecture Decision Records, relative to the repo root.
	 */
	const ADR_DIR = 'docs/architecture/adr';

	/**
	 * Directory holding change directories, relative to the repo root.
	 */
	const CHANGES_DIR = 'docs/architecture/changes';

	/**
	 * Page mapping every retired identifier to its current location.
	 */
	const MIGRATION_MAP = 'docs/architecture/migration-map.md';

	/**
	 * GitHub repository these tracking numbers belong to.
	 *
	 * Issues are disabled on this repository, so every tracking number is a pull
	 * request number and `/pull/<n>` always resolves.
	 */
	const REPO_SLUG = 'exelearning/wp-exelearning';

	/**
	 * Allowed ADR lifecycle states.
	 */
	const ADR_STATUSES = array( 'Proposed', 'Accepted', 'Rejected', 'Superseded' );

	/**
	 * Allowed change-document lifecycle states.
	 */
	const CHANGE_STATUSES = array( 'draft', 'in-review', 'accepted', 'implemented', 'superseded', 'abandoned' );

	/**
	 * Recognised change documents, in canonical-metadata precedence order.
	 *
	 * The first of these that exists in a change directory owns the mutable
	 * change-level metadata (`title`, `status`, `implementation_prs`,
	 * `related_adrs`).
	 */
	const CHANGE_DOCUMENTS = array( 'proposal.md', 'spec.md', 'design.md', 'research.md', 'tasks.md' );

	/**
	 * Files inside the ADR directory that are not themselves records.
	 */
	const NON_RECORD_FILES = array( 'README.md', 'records.md', 'template.md' );

	/**
	 * Current ADR filename grammar: `ADR-<tracking-number>-<NN>-<decision-slug>.md`.
	 */
	const ADR_FILENAME_RE = '/^ADR-([1-9][0-9]*)-([0-9]{2})-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$/';

	/**
	 * Current change directory grammar: `<tracking-number>-<change-slug>`.
	 */
	const CHANGE_DIR_RE = '/^([1-9][0-9]*)-([a-z0-9]+(?:-[a-z0-9]+)*)$/';

	/**
	 * A retired identifier is `ADR-NNNN` / `SDD-NNNN` that is *not* followed by a
	 * two-digit local sequence. Without that lookahead a current identifier such
	 * as `ADR-1858-01` would match on its own four-digit prefix. Current
	 * identifiers in this repository are two-digit PR numbers, which the
	 * four-digit pattern cannot match at all.
	 */
	const LEGACY_ID_RE = '/\b(?:ADR|SDD)-[0-9]{4}(?!-[0-9]{2})\b/';

	/**
	 * A filename left over from the retired global numbering.
	 */
	const LEGACY_FILENAME_RE = '/^(?:ADR|SDD)-[0-9]{4}-/';

	/**
	 * Files allowed to mention retired identifiers, because documenting the
	 * migration requires naming what was migrated. Everything else in the
	 * repository must use current identifiers. Entries are matched as exact
	 * paths or as path prefixes.
	 */
	const LEGACY_REFERENCE_ALLOWLIST = array(
		self::MIGRATION_MAP,
		// This detector needs retired identifiers as literals and as fixtures.
		'bin/architecture-records.php',
		'tests/unit/ArchitectureRecordsTest.php',
	);

	/**
	 * Header printed on every generated index, so a pasted copy is recognisable.
	 */
	const GENERATED_BANNER = '<!-- Produced by `make architecture-records`. Not a committed file. -->';

	// -----------------------------------------------------------------------
	// Frontmatter parsing
	// -----------------------------------------------------------------------

	/**
	 * Parse the bounded YAML subset used by architecture frontmatter.
	 *
	 * Supports scalars, inline lists, top-level block lists, one level of nested
	 * mappings, and block lists nested inside those mappings. Deliberately not a
	 * general YAML parser: the schema is fixed and small, and requiring the PHP
	 * `yaml` extension (not bundled, not enabled in CI) for it would be a new
	 * dependency.
	 *
	 * @param string $raw Full file contents.
	 * @return array{data: array<string,mixed>, body: string}|null Null when the
	 *                                                            file has no
	 *                                                            frontmatter.
	 */
	public static function parse_frontmatter( string $raw ) {
		if ( ! preg_match( '/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $raw, $match ) ) {
			return null;
		}

		$data  = array();
		$lines = preg_split( '/\r?\n/', $match[1] );

		$current_key        = null;
		$current_list       = null;
		$current_map        = null;
		$pending_nested_key = null;

		$flush = static function () use ( &$data, &$current_key, &$current_list, &$current_map, &$pending_nested_key ) {
			if ( null === $current_key ) {
				return;
			}
			if ( null !== $current_list ) {
				$data[ $current_key ] = $current_list;
			} elseif ( null !== $current_map ) {
				$data[ $current_key ] = $current_map;
			}
			$current_list       = null;
			$current_map        = null;
			$current_key        = null;
			$pending_nested_key = null;
		};

		foreach ( (array) $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}

			if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $top ) ) {
				$flush();
				$rest = trim( $top[2] );
				if ( '' === $rest ) {
					$current_key = $top[1];
				} else {
					$data[ $top[1] ] = self::parse_scalar_or_inline_list( $rest );
				}
				continue;
			}

			if ( preg_match( '/^\s+-\s*(.*)$/', $line, $item ) && null !== $current_key ) {
				$value = self::strip_quotes( trim( $item[1] ) );
				if ( null !== $pending_nested_key ) {
					if ( null === $current_map ) {
						$current_map = array();
					}
					if ( ! isset( $current_map[ $pending_nested_key ] ) || ! is_array( $current_map[ $pending_nested_key ] ) ) {
						$current_map[ $pending_nested_key ] = array();
					}
					$current_map[ $pending_nested_key ][] = $value;
					continue;
				}
				$current_map = null;
				if ( null === $current_list ) {
					$current_list = array();
				}
				$current_list[] = $value;
				continue;
			}

			if ( preg_match( '/^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $nested ) && null !== $current_key ) {
				$current_list = null;
				if ( null === $current_map ) {
					$current_map = array();
				}
				$rest = trim( $nested[2] );
				if ( '' === $rest ) {
					$pending_nested_key                 = $nested[1];
					$current_map[ $pending_nested_key ] = array();
					continue;
				}
				$pending_nested_key          = null;
				$current_map[ $nested[1] ] = self::parse_scalar_or_inline_list( $rest );
			}
		}
		$flush();

		return array(
			'data' => $data,
			'body' => $match[2],
		);
	}

	/**
	 * Remove one layer of matching surrounding quotes.
	 *
	 * @param string $value Raw scalar.
	 * @return string Unquoted scalar.
	 */
	public static function strip_quotes( string $value ): string {
		return (string) preg_replace( '/^(["\'])(.*)\1$/', '$2', $value );
	}

	/**
	 * Parse a YAML scalar, or an inline `[a, b]` list.
	 *
	 * @param string $raw Raw right-hand side of a mapping entry.
	 * @return string|string[] Scalar or list.
	 */
	public static function parse_scalar_or_inline_list( string $raw ) {
		if ( 0 === strpos( $raw, '[' ) && substr( $raw, -1 ) === ']' ) {
			$inner = trim( substr( $raw, 1, -1 ) );
			if ( '' === $inner ) {
				return array();
			}
			return array_map(
				static function ( $part ) {
					return self::strip_quotes( trim( $part ) );
				},
				explode( ',', $inner )
			);
		}
		return self::strip_quotes( $raw );
	}

	/**
	 * Coerce a frontmatter value to a list of strings.
	 *
	 * @param mixed $value Parsed frontmatter value.
	 * @return string[] List of strings, empty when absent.
	 */
	public static function as_list( $value ): array {
		if ( null === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $entry ) {
				if ( ! is_array( $entry ) ) {
					$flat[] = (string) $entry;
				}
			}
			return $flat;
		}
		$single = trim( (string) $value );
		return '' === $single ? array() : array( $single );
	}

	/**
	 * Coerce a frontmatter value to a scalar string.
	 *
	 * @param mixed $value Parsed frontmatter value.
	 * @return string Scalar, empty when absent or non-scalar.
	 */
	public static function as_string( $value ): string {
		if ( null === $value || is_array( $value ) ) {
			return '';
		}
		return (string) $value;
	}

	/**
	 * Read a value from a nested frontmatter mapping.
	 *
	 * @param array<string,mixed> $data Frontmatter.
	 * @param string              $key  Top-level key.
	 * @param string              $sub  Nested key.
	 * @return mixed Nested value, or null.
	 */
	public static function nested( array $data, string $key, string $sub ) {
		if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) && isset( $data[ $key ][ $sub ] ) ) {
			return $data[ $key ][ $sub ];
		}
		return null;
	}

	/**
	 * Remove fenced code blocks so a `# heading` inside a shell snippet is not
	 * mistaken for the document H1.
	 *
	 * @param string $body Markdown body.
	 * @return string Body with fenced blocks removed.
	 */
	public static function strip_code_fences( string $body ): string {
		$lines = preg_split( '/\r?\n/', $body );
		$out   = array();
		$fence = null;

		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^\s*(`{3,}|~{3,})/', $line, $match ) ) {
				$marker = $match[1][0];
				if ( null === $fence ) {
					$fence = $marker;
				} elseif ( $marker === $fence ) {
					$fence = null;
				}
				$out[] = '';
				continue;
			}
			$out[] = null === $fence ? $line : '';
		}

		return implode( "\n", $out );
	}

	/**
	 * Extract the first Markdown H1 outside fenced code blocks.
	 *
	 * @param string $body Markdown body.
	 * @return string|null Heading text, or null when there is none.
	 */
	public static function first_h1( string $body ) {
		if ( preg_match( '/^# (.+)$/m', self::strip_code_fences( $body ), $match ) ) {
			return trim( $match[1] );
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// Discovery
	// -----------------------------------------------------------------------

	/**
	 * Discover every ADR under `docs/architecture/adr/`.
	 *
	 * @param string $root Repository root.
	 * @return array{adrs: array<int,array<string,mixed>>, errors: array<int,array{file:string,message:string}>}
	 */
	public static function discover_adrs( string $root ): array {
		$dir    = $root . '/' . self::ADR_DIR;
		$adrs   = array();
		$errors = array();
		if ( ! is_dir( $dir ) ) {
			return array(
				'adrs'   => $adrs,
				'errors' => $errors,
			);
		}

		$entries = (array) scandir( $dir );
		sort( $entries );

		foreach ( $entries as $file ) {
			if ( substr( $file, -3 ) !== '.md' ) {
				continue;
			}
			if ( in_array( $file, self::NON_RECORD_FILES, true ) ) {
				continue;
			}

			$rel = self::ADR_DIR . '/' . $file;

			// The current grammar is checked first: `ADR-1858-01-slug.md` also
			// starts with four digits, so the legacy pattern would shadow it.
			if ( ! preg_match( self::ADR_FILENAME_RE, $file, $match ) ) {
				$errors[] = array(
					'file'    => $rel,
					'message' => preg_match( self::LEGACY_FILENAME_RE, $file )
						? 'uses the retired global numbering. Rename to ADR-<tracking-number>-<NN>-<decision-slug>.md '
							. '(see ' . self::ADR_DIR . '/README.md).'
						: 'filename does not match ADR-<tracking-number>-<NN>-<decision-slug>.md',
				);
				continue;
			}

			$parsed = self::parse_frontmatter( (string) file_get_contents( $dir . '/' . $file ) );
			if ( null === $parsed ) {
				$errors[] = array(
					'file'    => $rel,
					'message' => 'missing YAML frontmatter',
				);
				continue;
			}

			$data = $parsed['data'];

			$adrs[] = array(
				'path'            => $rel,
				'file'            => $file,
				'id'              => self::as_string( $data['id'] ?? null ),
				'issue'           => (int) $match[1],
				'sequence'        => $match[2],
				'title'           => self::as_string( $data['title'] ?? null ),
				'status'          => self::as_string( $data['status'] ?? null ),
				'date'            => self::as_string( $data['date'] ?? null ),
				'tracking_issue'  => self::as_string( $data['tracking_issue'] ?? null ),
				'legacy_id'       => isset( $data['legacy_id'] ) ? self::as_string( $data['legacy_id'] ) : null,
				'supersedes'      => self::as_list( $data['supersedes'] ?? null ),
				'superseded_by'   => self::as_list( $data['superseded_by'] ?? null ),
				'related_adrs'    => self::as_list( self::nested( $data, 'related', 'adrs' ) ),
				'related_changes' => self::as_list( self::nested( $data, 'related', 'changes' ) ),
				'related_prs'     => self::as_list( self::nested( $data, 'related', 'prs' ) ),
				'external_refs'   => self::as_list( $data['external_refs'] ?? null ),
				'ai_tool'         => isset( $data['ai_assistance'] )
					? self::as_string( self::nested( $data, 'ai_assistance', 'tool' ) )
					: null,
				'ai_model'        => isset( $data['ai_assistance'] )
					? self::as_string( self::nested( $data, 'ai_assistance', 'model' ) )
					: null,
				'h1'              => self::first_h1( $parsed['body'] ),
			);
		}

		return array(
			'adrs'   => $adrs,
			'errors' => $errors,
		);
	}

	/**
	 * Discover every change directory under `docs/architecture/changes/`.
	 *
	 * @param string $root Repository root.
	 * @return array{changes: array<int,array<string,mixed>>, errors: array<int,array{file:string,message:string}>}
	 */
	public static function discover_changes( string $root ): array {
		$dir     = $root . '/' . self::CHANGES_DIR;
		$changes = array();
		$errors  = array();
		if ( ! is_dir( $dir ) ) {
			return array(
				'changes' => $changes,
				'errors'  => $errors,
			);
		}

		$entries = (array) scandir( $dir );
		sort( $entries );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $dir . '/' . $entry;
			if ( ! is_dir( $full ) ) {
				continue;
			}

			$rel = self::CHANGES_DIR . '/' . $entry;
			if ( ! preg_match( self::CHANGE_DIR_RE, $entry, $match ) ) {
				$errors[] = array(
					'file'    => $rel,
					'message' => 'directory name does not match <tracking-number>-<change-slug>',
				);
				continue;
			}

			$documents = array();
			foreach ( self::CHANGE_DOCUMENTS as $name ) {
				$doc_path = $full . '/' . $name;
				if ( ! is_file( $doc_path ) ) {
					continue;
				}
				$parsed = self::parse_frontmatter( (string) file_get_contents( $doc_path ) );
				if ( null === $parsed ) {
					$errors[] = array(
						'file'    => $rel . '/' . $name,
						'message' => 'missing YAML frontmatter',
					);
					continue;
				}
				$documents[] = array(
					'path' => $rel . '/' . $name,
					'name' => $name,
					'data' => $parsed['data'],
				);
			}

			if ( empty( $documents ) ) {
				$errors[] = array(
					'file'    => $rel,
					'message' => 'contains no recognised document (' . implode( ', ', self::CHANGE_DOCUMENTS ) . ')',
				);
				continue;
			}

			$canonical = $documents[0];
			$data      = $canonical['data'];

			$changes[] = array(
				'dir'                => $rel,
				'name'               => $entry,
				'issue'              => (int) $match[1],
				'slug'               => $match[2],
				'documents'          => $documents,
				'canonical'          => $canonical,
				'title'              => self::as_string( $data['title'] ?? null ),
				'status'             => self::as_string( $data['status'] ?? null ),
				'date'               => self::as_string( $data['date'] ?? null ),
				'implementation_prs' => self::as_list( $data['implementation_prs'] ?? null ),
				'related_adrs'       => self::as_list( $data['related_adrs'] ?? null ),
				'legacy_id'          => isset( $data['legacy_id'] ) ? self::as_string( $data['legacy_id'] ) : null,
			);
		}

		return array(
			'changes' => $changes,
			'errors'  => $errors,
		);
	}

	// -----------------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------------

	/**
	 * Whether a string is a real calendar date in `YYYY-MM-DD` form.
	 *
	 * @param string $value Candidate date.
	 * @return bool True when valid.
	 */
	public static function is_valid_date( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
			return false;
		}
		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Whether a string is a positive integer with no leading zeros.
	 *
	 * @param string $value Candidate number.
	 * @return bool True when valid.
	 */
	public static function is_positive_integer( string $value ): bool {
		return 1 === preg_match( '/^[1-9][0-9]*$/', $value );
	}

	/**
	 * Whether a string is an absolute http(s) URL.
	 *
	 * @param string $value Candidate reference.
	 * @return bool True when valid.
	 */
	public static function is_http_url( string $value ): bool {
		return 1 === preg_match( '#^https?://\S+$#', $value );
	}

	/**
	 * Validate identifiers, metadata and cross-references.
	 *
	 * @param array<int,array<string,mixed>> $adrs    Discovered ADRs.
	 * @param array<int,array<string,mixed>> $changes Discovered changes.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	public static function validate( array $adrs, array $changes ): array {
		$problems = array();
		$add      = static function ( string $file, string $message ) use ( &$problems ) {
			$problems[] = array(
				'file'    => $file,
				'message' => $message,
			);
		};

		$adr_ids      = array_column( $adrs, 'id' );
		$change_names = array_column( $changes, 'name' );
		$adrs_by_id   = array();
		foreach ( $adrs as $adr ) {
			if ( '' !== $adr['id'] && ! isset( $adrs_by_id[ $adr['id'] ] ) ) {
				$adrs_by_id[ $adr['id'] ] = $adr;
			}
		}

		$seen_ids       = array();
		$seen_sequences = array();

		foreach ( $adrs as $adr ) {
			$expected_id = 'ADR-' . $adr['issue'] . '-' . $adr['sequence'];

			if ( '' === $adr['id'] ) {
				$add( $adr['path'], 'missing required field `id`' );
			} elseif ( $adr['id'] !== $expected_id ) {
				$add(
					$adr['path'],
					'frontmatter id "' . $adr['id'] . '" does not match filename (expected "' . $expected_id . '")'
				);
			}

			if ( '' === $adr['title'] ) {
				$add( $adr['path'], 'missing required field `title`' );
			}

			if ( '' === $adr['date'] ) {
				$add( $adr['path'], 'missing required field `date`' );
			} elseif ( ! self::is_valid_date( $adr['date'] ) ) {
				$add( $adr['path'], 'date "' . $adr['date'] . '" is not a valid YYYY-MM-DD date' );
			}

			if ( '' === $adr['status'] ) {
				$add( $adr['path'], 'missing required field `status`' );
			} elseif ( ! in_array( $adr['status'], self::ADR_STATUSES, true ) ) {
				$add(
					$adr['path'],
					'status "' . $adr['status'] . '" is not one of ' . implode( ', ', self::ADR_STATUSES )
				);
			}

			if ( '' === $adr['tracking_issue'] ) {
				$add( $adr['path'], 'missing required field `tracking_issue`' );
			} elseif ( ! self::is_positive_integer( $adr['tracking_issue'] ) ) {
				$add( $adr['path'], 'tracking_issue "' . $adr['tracking_issue'] . '" is not a positive integer' );
			} elseif ( (int) $adr['tracking_issue'] !== $adr['issue'] ) {
				$add(
					$adr['path'],
					'tracking_issue ' . $adr['tracking_issue'] . ' does not match filename tracking number ' . $adr['issue']
				);
			}

			if ( null === $adr['ai_tool'] || '' === $adr['ai_tool'] ) {
				$add( $adr['path'], 'missing required field `ai_assistance.tool` (use `none` if no AI tool was used)' );
			}
			if ( null === $adr['ai_model'] || '' === $adr['ai_model'] ) {
				$add( $adr['path'], 'missing required field `ai_assistance.model` (use `none` if no AI tool was used)' );
			}

			$expected_h1 = $expected_id . ': ' . $adr['title'];
			if ( null === $adr['h1'] ) {
				$add( $adr['path'], 'missing H1 heading' );
			} elseif ( $adr['h1'] !== $expected_h1 ) {
				$add( $adr['path'], 'H1 is "' . $adr['h1'] . '" but should be "' . $expected_h1 . '"' );
			}

			if ( isset( $seen_ids[ $adr['id'] ] ) ) {
				$add( $adr['path'], 'duplicate ADR id "' . $adr['id'] . '" (also in ' . $seen_ids[ $adr['id'] ] . ')' );
			} elseif ( '' !== $adr['id'] ) {
				$seen_ids[ $adr['id'] ] = $adr['path'];
			}

			$sequence_key = $adr['issue'] . '-' . $adr['sequence'];
			if ( isset( $seen_sequences[ $sequence_key ] ) ) {
				$add(
					$adr['path'],
					'duplicate local sequence ' . $adr['sequence'] . ' for tracking number ' . $adr['issue']
						. ' (also in ' . $seen_sequences[ $sequence_key ] . ')'
				);
			} else {
				$seen_sequences[ $sequence_key ] = $adr['path'];
			}

			foreach ( $adr['related_adrs'] as $ref ) {
				if ( ! in_array( $ref, $adr_ids, true ) ) {
					$add( $adr['path'], 'related.adrs references unknown ADR "' . $ref . '"' );
				}
			}
			foreach ( $adr['related_changes'] as $ref ) {
				if ( ! in_array( $ref, $change_names, true ) ) {
					$add( $adr['path'], 'related.changes references unknown change "' . $ref . '"' );
				}
			}
			foreach ( $adr['related_prs'] as $pr ) {
				if ( ! self::is_positive_integer( $pr ) ) {
					$add( $adr['path'], 'related.prs value "' . $pr . '" is not a positive integer' );
				}
			}
			foreach ( $adr['external_refs'] as $ref ) {
				if ( ! self::is_http_url( $ref ) ) {
					$add( $adr['path'], 'external_refs value "' . $ref . '" is not an http(s) URL' );
				}
			}

			foreach ( $adr['supersedes'] as $ref ) {
				if ( $ref === $adr['id'] ) {
					$add( $adr['path'], 'ADR cannot supersede itself' );
					continue;
				}
				if ( ! isset( $adrs_by_id[ $ref ] ) ) {
					$add( $adr['path'], 'supersedes references unknown ADR "' . $ref . '"' );
					continue;
				}
				$target = $adrs_by_id[ $ref ];
				if ( ! in_array( $adr['id'], $target['superseded_by'], true ) ) {
					$add(
						$adr['path'],
						'supersedes "' . $ref . '" but ' . $target['path'] . ' does not list superseded_by: [' . $adr['id'] . ']'
					);
				}
				if ( 'Superseded' !== $target['status'] ) {
					$add(
						$target['path'],
						'is superseded by ' . $adr['id'] . ' but status is "' . $target['status'] . '", not "Superseded"'
					);
				}
			}

			foreach ( $adr['superseded_by'] as $ref ) {
				if ( $ref === $adr['id'] ) {
					$add( $adr['path'], 'ADR cannot be superseded by itself' );
					continue;
				}
				if ( ! isset( $adrs_by_id[ $ref ] ) ) {
					$add( $adr['path'], 'superseded_by references unknown ADR "' . $ref . '"' );
					continue;
				}
				$target = $adrs_by_id[ $ref ];
				if ( ! in_array( $adr['id'], $target['supersedes'], true ) ) {
					$add(
						$adr['path'],
						'superseded_by "' . $ref . '" but ' . $target['path'] . ' does not list supersedes: [' . $adr['id'] . ']'
					);
				}
			}
		}

		foreach ( $changes as $change ) {
			$canonical = $change['canonical'];

			if ( '' === $change['title'] ) {
				$add( $canonical['path'], 'missing required field `title`' );
			}

			if ( '' === $change['date'] ) {
				$add( $canonical['path'], 'missing required field `date`' );
			} elseif ( ! self::is_valid_date( $change['date'] ) ) {
				$add( $canonical['path'], 'date "' . $change['date'] . '" is not a valid YYYY-MM-DD date' );
			}

			if ( '' === $change['status'] ) {
				$add( $canonical['path'], 'missing required field `status`' );
			} elseif ( ! in_array( $change['status'], self::CHANGE_STATUSES, true ) ) {
				$add(
					$canonical['path'],
					'status "' . $change['status'] . '" is not one of ' . implode( ', ', self::CHANGE_STATUSES )
				);
			}

			foreach ( $change['implementation_prs'] as $pr ) {
				if ( ! self::is_positive_integer( $pr ) ) {
					$add( $canonical['path'], 'implementation_prs value "' . $pr . '" is not a positive integer' );
				}
			}
			foreach ( $change['related_adrs'] as $ref ) {
				if ( ! in_array( $ref, $adr_ids, true ) ) {
					$add( $canonical['path'], 'related_adrs references unknown ADR "' . $ref . '"' );
				}
			}

			foreach ( $change['documents'] as $doc ) {
				$issue = self::as_string( $doc['data']['tracking_issue'] ?? null );
				if ( '' === $issue ) {
					$add( $doc['path'], 'missing required field `tracking_issue`' );
				} elseif ( ! self::is_positive_integer( $issue ) ) {
					$add( $doc['path'], 'tracking_issue "' . $issue . '" is not a positive integer' );
				} elseif ( (int) $issue !== $change['issue'] ) {
					$add(
						$doc['path'],
						'tracking_issue ' . $issue . ' does not match change directory tracking number ' . $change['issue']
					);
				}

				if ( '' === self::as_string( $doc['data']['title'] ?? null ) ) {
					$add( $doc['path'], 'missing required field `title`' );
				}

				foreach ( self::as_list( $doc['data']['related_prs'] ?? null ) as $pr ) {
					if ( ! self::is_positive_integer( $pr ) ) {
						$add( $doc['path'], 'related_prs value "' . $pr . '" is not a positive integer' );
					}
				}
				foreach ( self::as_list( $doc['data']['external_refs'] ?? null ) as $ref ) {
					if ( ! self::is_http_url( $ref ) ) {
						$add( $doc['path'], 'external_refs value "' . $ref . '" is not an http(s) URL' );
					}
				}

				if ( $doc['name'] !== $canonical['name'] && isset( $doc['data']['implementation_prs'] ) ) {
					$add(
						$doc['path'],
						'declares implementation_prs, but ' . $canonical['name']
							. ' is the canonical metadata carrier for this change'
					);
				}
			}
		}

		return $problems;
	}

	/**
	 * Report a committed index, which reintroduces the merge-conflict class this
	 * convention removes.
	 *
	 * @param string[] $files Repository-relative file list.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	public static function find_committed_indexes( array $files ): array {
		$problems = array();
		foreach ( $files as $file ) {
			if ( self::ADR_DIR . '/records.md' !== $file && self::CHANGES_DIR . '/records.md' !== $file ) {
				continue;
			}
			$problems[] = array(
				'file'    => $file,
				'message' => 'the record index must not be committed — it is derived from frontmatter and '
					. 'conflicts on every concurrent branch. Delete it; `make architecture-records` prints it.',
			);
		}
		return $problems;
	}

	/**
	 * Scan files for retired identifiers outside the documented allowlist.
	 *
	 * @param string   $root  Repository root.
	 * @param string[] $files Repository-relative file list.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	public static function find_legacy_references( string $root, array $files ): array {
		$problems = array();

		foreach ( $files as $file ) {
			$allowed = false;
			foreach ( self::LEGACY_REFERENCE_ALLOWLIST as $prefix ) {
				if ( $file === $prefix || 0 === strpos( $file, $prefix ) ) {
					$allowed = true;
					break;
				}
			}
			if ( $allowed ) {
				continue;
			}

			$full = $root . '/' . $file;
			if ( ! is_file( $full ) || ! is_readable( $full ) ) {
				continue;
			}

			$content = file_get_contents( $full );
			if ( false === $content || false !== strpos( $content, "\0" ) ) {
				continue;
			}

			// A migrated document may name its own former identifier, so the
			// provenance note inside the document itself stays readable.
			$own_legacy_id = null;
			if ( substr( $file, -3 ) === '.md' ) {
				$parsed = self::parse_frontmatter( $content );
				if ( null !== $parsed && isset( $parsed['data']['legacy_id'] ) ) {
					$own_legacy_id = self::as_string( $parsed['data']['legacy_id'] );
				}
			}

			$lines = preg_split( '/\r?\n/', $content );
			foreach ( (array) $lines as $index => $line ) {
				if ( false !== strpos( $line, 'legacy_id:' ) ) {
					continue;
				}
				if ( ! preg_match( self::LEGACY_ID_RE, $line, $hit ) ) {
					continue;
				}
				if ( null !== $own_legacy_id && $hit[0] === $own_legacy_id ) {
					continue;
				}
				$problems[] = array(
					'file'    => $file . ':' . ( $index + 1 ),
					'message' => 'references retired identifier "' . $hit[0] . '". Use the current identifier (see '
						. self::MIGRATION_MAP . ').',
				);
			}
		}

		return $problems;
	}

	// -----------------------------------------------------------------------
	// Index generation
	// -----------------------------------------------------------------------

	/**
	 * Order ADRs by tracking number, then by local sequence.
	 *
	 * @param array<int,array<string,mixed>> $adrs Discovered ADRs.
	 * @return array<int,array<string,mixed>> Sorted copy.
	 */
	public static function sort_adrs( array $adrs ): array {
		usort(
			$adrs,
			static function ( $a, $b ) {
				return ( $a['issue'] <=> $b['issue'] ) ?: strcmp( $a['sequence'], $b['sequence'] );
			}
		);
		return $adrs;
	}

	/**
	 * Order changes by tracking number, then by slug.
	 *
	 * A tracking number may own more than one change directory, so the slug is a
	 * real tiebreaker here and not a defensive fallback.
	 *
	 * @param array<int,array<string,mixed>> $changes Discovered changes.
	 * @return array<int,array<string,mixed>> Sorted copy.
	 */
	public static function sort_changes( array $changes ): array {
		usort(
			$changes,
			static function ( $a, $b ) {
				return ( $a['issue'] <=> $b['issue'] ) ?: strcmp( $a['slug'], $b['slug'] );
			}
		);
		return $changes;
	}

	/**
	 * Markdown link to a tracking number on GitHub.
	 *
	 * Issues are disabled on this repository, so the number is always a pull
	 * request.
	 *
	 * @param int $number Tracking number.
	 * @return string Markdown link.
	 */
	public static function tracking_link( int $number ): string {
		return '[#' . $number . '](https://github.com/' . self::REPO_SLUG . '/pull/' . $number . ')';
	}

	/**
	 * Render the ADR index.
	 *
	 * @param array<int,array<string,mixed>> $adrs Discovered ADRs.
	 * @return string Markdown index.
	 */
	public static function render_adr_index( array $adrs ): string {
		$sorted = self::sort_adrs( $adrs );
		$lines  = array(
			self::GENERATED_BANNER,
			'',
			'# ADR Index',
			'',
			'Architecture Decision Records for the wp-exelearning WordPress plugin,',
			'ordered by tracking number and then by local sequence. See',
			self::ADR_DIR . '/README.md for the policy.',
			'',
			'| ID | Title | Status | Tracking | Date |',
			'|---|---|---|---|---|',
		);

		foreach ( $sorted as $adr ) {
			$lines[] = '| [' . $adr['id'] . '](' . $adr['file'] . ') | ' . $adr['title'] . ' | ' . $adr['status']
				. ' | ' . self::tracking_link( $adr['issue'] ) . ' | ' . $adr['date'] . ' |';
		}

		foreach ( self::ADR_STATUSES as $status ) {
			$lines[] = '';
			$lines[] = '## ' . $status;
			$lines[] = '';
			$group   = array_values(
				array_filter(
					$sorted,
					static function ( $adr ) use ( $status ) {
						return $adr['status'] === $status;
					}
				)
			);
			if ( empty( $group ) ) {
				$lines[] = '_No ' . strtolower( $status ) . ' ADRs._';
				continue;
			}
			foreach ( $group as $adr ) {
				$supersession = empty( $adr['superseded_by'] )
					? ''
					: ' — superseded by ' . implode( ', ', $adr['superseded_by'] );
				$lines[]      = '- [' . $adr['id'] . '](' . $adr['file'] . ') — ' . $adr['title'] . $supersession;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render the change index.
	 *
	 * @param array<int,array<string,mixed>> $changes Discovered changes.
	 * @return string Markdown index.
	 */
	public static function render_change_index( array $changes ): string {
		$sorted = self::sort_changes( $changes );
		$lines  = array(
			self::GENERATED_BANNER,
			'',
			'# Change Index',
			'',
			'Change proposals, specifications and designs for the wp-exelearning WordPress',
			'plugin, ordered by tracking number. Each change lives in its own directory',
			'named `<tracking-number>-<change-slug>`. See ' . self::CHANGES_DIR . '/README.md',
			'for the policy.',
			'',
			'| Change | Title | Status | Tracking | Date | Documents |',
			'|---|---|---|---|---|---|',
		);

		foreach ( $sorted as $change ) {
			$docs = array();
			foreach ( $change['documents'] as $doc ) {
				$docs[] = '[' . substr( $doc['name'], 0, -3 ) . '](' . $change['name'] . '/' . $doc['name'] . ')';
			}
			$lines[] = '| `' . $change['name'] . '` | ' . $change['title'] . ' | ' . $change['status'] . ' | '
				. self::tracking_link( $change['issue'] ) . ' | ' . $change['date'] . ' | '
				. implode( ', ', $docs ) . ' |';
		}

		foreach ( self::CHANGE_STATUSES as $status ) {
			$lines[] = '';
			$lines[] = '## ' . $status;
			$lines[] = '';
			$group   = array_values(
				array_filter(
					$sorted,
					static function ( $change ) use ( $status ) {
						return $change['status'] === $status;
					}
				)
			);
			if ( empty( $group ) ) {
				$lines[] = '_No ' . $status . ' changes._';
				continue;
			}
			foreach ( $group as $change ) {
				$adrs    = empty( $change['related_adrs'] ) ? '' : ' — ' . implode( ', ', $change['related_adrs'] );
				$lines[] = '- [`' . $change['name'] . '`](' . $change['name'] . '/' . $change['documents'][0]['name']
					. ') — ' . $change['title'] . $adrs;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	// -----------------------------------------------------------------------
	// CLI
	// -----------------------------------------------------------------------

	/**
	 * Tracked files plus not-yet-added ones, honouring .gitignore.
	 *
	 * Including untracked files matters: otherwise a brand-new file passes
	 * `check` locally and only fails in CI, once it has been committed.
	 *
	 * @param string $root Repository root.
	 * @return string[] Repository-relative paths.
	 */
	public static function tracked_files( string $root ): array {
		$command = 'git -C ' . escapeshellarg( $root ) . ' ls-files --cached --others --exclude-standard 2>/dev/null';
		$output  = array();
		$status  = 0;
		exec( $command, $output, $status );
		if ( 0 !== $status ) {
			return array();
		}
		return array_values( array_unique( array_filter( $output, 'strlen' ) ) );
	}

	/**
	 * Print a diagnostic group to STDERR.
	 *
	 * @param string                                      $title    Group heading.
	 * @param array<int,array{file:string,message:string}> $problems Diagnostics.
	 * @return void
	 */
	private static function report( string $title, array $problems ): void {
		if ( empty( $problems ) ) {
			return;
		}
		fwrite( STDERR, "\n" . $title . "\n" );
		foreach ( $problems as $problem ) {
			fwrite( STDERR, '  x ' . $problem['file'] . ': ' . $problem['message'] . "\n" );
		}
	}

	/**
	 * Run the tool.
	 *
	 * @param string $mode `list` or `check`.
	 * @param string $root Repository root.
	 * @return int Process exit code.
	 */
	public static function run( string $mode, string $root ): int {
		$discovered_adrs    = self::discover_adrs( $root );
		$discovered_changes = self::discover_changes( $root );
		$adrs               = $discovered_adrs['adrs'];
		$changes            = $discovered_changes['changes'];
		$structural         = array_merge( $discovered_adrs['errors'], $discovered_changes['errors'] );

		if ( 'list' === $mode ) {
			self::report( 'Structural problems:', $structural );
			if ( ! empty( $structural ) ) {
				fwrite( STDERR, "\nRefusing to list records while structural problems remain.\n" );
				return 1;
			}
			fwrite( STDOUT, self::render_adr_index( $adrs ) . "\n" );
			fwrite( STDOUT, self::render_change_index( $changes ) . "\n" );
			return 0;
		}

		$files             = self::tracked_files( $root );
		$metadata          = self::validate( $adrs, $changes );
		$legacy            = self::find_legacy_references( $root, $files );
		$committed_indexes = self::find_committed_indexes( $files );

		self::report( 'Structural problems:', $structural );
		self::report( 'Metadata problems:', $metadata );
		self::report( 'Retired identifier references:', $legacy );
		self::report( 'Committed index:', $committed_indexes );

		$total = count( $structural ) + count( $metadata ) + count( $legacy ) + count( $committed_indexes );
		if ( 0 === $total ) {
			fwrite(
				STDOUT,
				sprintf(
					"Architecture records OK — %d ADRs, %d changes.\n",
					count( $adrs ),
					count( $changes )
				)
			);
			return 0;
		}

		fwrite( STDERR, "\n" . $total . " problem(s) found.\n" );
		return 1;
	}
}

// CLI entry point. Guarded so PHPUnit can require this file for its unit tests
// without running the tool.
if ( PHP_SAPI === 'cli' && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$mode = $argv[1] ?? '';
	if ( 'list' !== $mode && 'check' !== $mode ) {
		fwrite( STDERR, "Usage: php bin/architecture-records.php <list|check>\n" );
		exit( 2 );
	}
	exit( ExeLearning_Architecture_Records::run( $mode, dirname( __DIR__ ) ) );
}
