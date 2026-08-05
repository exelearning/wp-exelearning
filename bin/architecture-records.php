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
 * Frontmatter parsing for architecture records.
 *
 * Handles the bounded YAML subset the schema uses: scalars, inline lists, block
 * lists and one level of nested mappings. Deliberately not a general YAML
 * parser, and deliberately dependency-free.
 */
final class ExeLearning_Architecture_Frontmatter {

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

		$state = array(
			'data'       => array(),
			'key'        => null,
			'list'       => null,
			'map'        => null,
			'nested_key' => null,
		);

		$lines = preg_split( '/\r?\n/', $match[1] );
		foreach ( (array) $lines as $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}
			self::consume_frontmatter_line( $line, $state );
		}
		self::flush_frontmatter_key( $state );

		return array(
			'data' => $state['data'],
			'body' => $match[2],
		);
	}
	/**
	 * Commit the pending key of a frontmatter parse into the result.
	 *
	 * @param array<string,mixed> $state Parser state, updated in place.
	 * @return void
	 */
	private static function flush_frontmatter_key( array &$state ): void {
		if ( null === $state['key'] ) {
			return;
		}
		if ( null !== $state['list'] ) {
			$state['data'][ $state['key'] ] = $state['list'];
		} elseif ( null !== $state['map'] ) {
			$state['data'][ $state['key'] ] = $state['map'];
		}
		$state['list']       = null;
		$state['map']        = null;
		$state['key']        = null;
		$state['nested_key'] = null;
	}
	/**
	 * Apply one frontmatter line to the parser state.
	 *
	 * @param string              $line  Raw line.
	 * @param array<string,mixed> $state Parser state, updated in place.
	 * @return void
	 */
	private static function consume_frontmatter_line( string $line, array &$state ): void {
		if ( preg_match( '/^([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $top ) ) {
			self::flush_frontmatter_key( $state );
			$rest = trim( $top[2] );
			if ( '' === $rest ) {
				$state['key'] = $top[1];
			} else {
				$state['data'][ $top[1] ] = self::parse_scalar_or_inline_list( $rest );
			}
			return;
		}

		if ( null === $state['key'] ) {
			return;
		}

		if ( preg_match( '/^\s+-\s*(.*)$/', $line, $item ) ) {
			self::append_frontmatter_item( self::strip_quotes( trim( $item[1] ) ), $state );
			return;
		}

		if ( preg_match( '/^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$/', $line, $nested ) ) {
			self::open_frontmatter_nested_key( $nested[1], trim( $nested[2] ), $state );
		}
	}
	/**
	 * Append a list item, either to a block list or to a pending nested key.
	 *
	 * @param string              $value Item value.
	 * @param array<string,mixed> $state Parser state, updated in place.
	 * @return void
	 */
	private static function append_frontmatter_item( string $value, array &$state ): void {
		if ( null !== $state['nested_key'] ) {
			if ( null === $state['map'] ) {
				$state['map'] = array();
			}
			if ( ! isset( $state['map'][ $state['nested_key'] ] ) || ! is_array( $state['map'][ $state['nested_key'] ] ) ) {
				$state['map'][ $state['nested_key'] ] = array();
			}
			$state['map'][ $state['nested_key'] ][] = $value;
			return;
		}

		$state['map'] = null;
		if ( null === $state['list'] ) {
			$state['list'] = array();
		}
		$state['list'][] = $value;
	}
	/**
	 * Record a nested mapping entry, or open one that takes a block list.
	 *
	 * @param string              $key   Nested key.
	 * @param string              $rest  Remainder of the line.
	 * @param array<string,mixed> $state Parser state, updated in place.
	 * @return void
	 */
	private static function open_frontmatter_nested_key( string $key, string $rest, array &$state ): void {
		$state['list'] = null;
		if ( null === $state['map'] ) {
			$state['map'] = array();
		}
		if ( '' === $rest ) {
			$state['nested_key']         = $key;
			$state['map'][ $key ]        = array();
			return;
		}
		$state['nested_key']  = null;
		$state['map'][ $key ] = self::parse_scalar_or_inline_list( $rest );
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
}

/**
 * Metadata and cross-reference rules for architecture records.
 *
 * Split out of the entry-point class so each rule group stays independently
 * readable and testable.
 */
final class ExeLearning_Architecture_Validator {

	/**
	 * Validate identifiers, metadata and cross-references.
	 *
	 * @param array<int,array<string,mixed>> $adrs    Discovered ADRs.
	 * @param array<int,array<string,mixed>> $changes Discovered changes.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	public static function validate( array $adrs, array $changes ): array {
		$adr_ids      = array_column( $adrs, 'id' );
		$change_names = array_column( $changes, 'name' );

		$adrs_by_id = array();
		foreach ( $adrs as $adr ) {
			if ( '' !== $adr['id'] && ! isset( $adrs_by_id[ $adr['id'] ] ) ) {
				$adrs_by_id[ $adr['id'] ] = $adr;
			}
		}

		$problems       = array();
		$seen_ids       = array();
		$seen_sequences = array();

		foreach ( $adrs as $adr ) {
			$problems = array_merge(
				$problems,
				self::validate_adr_fields( $adr ),
				self::validate_adr_uniqueness( $adr, $seen_ids, $seen_sequences ),
				self::validate_adr_references( $adr, $adr_ids, $change_names ),
				self::validate_adr_supersession( $adr, $adrs_by_id )
			);
		}

		foreach ( $changes as $change ) {
			$problems = array_merge(
				$problems,
				self::validate_change_metadata( $change, $adr_ids, $change_names ),
				self::validate_change_documents( $change )
			);
		}

		return $problems;
	}
	/**
	 * Build one diagnostic.
	 *
	 * @param string $file    Repository-relative path.
	 * @param string $message Human-readable problem.
	 * @return array{file:string,message:string} Diagnostic.
	 */
	public static function problem( string $file, string $message ): array {
		return array(
			'file'    => $file,
			'message' => $message,
		);
	}
	/**
	 * Validate one ADR's own required fields and their shapes.
	 *
	 * @param array<string,mixed> $adr Discovered ADR.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_adr_fields( array $adr ): array {
		$problems    = array();
		$path        = $adr['path'];
		$expected_id = 'ADR-' . $adr['issue'] . '-' . $adr['sequence'];

		if ( '' === $adr['id'] ) {
			$problems[] = self::problem( $path, 'missing required field `id`' );
		} elseif ( $adr['id'] !== $expected_id ) {
			$problems[] = self::problem(
				$path,
				'frontmatter id "' . $adr['id'] . '" does not match filename (expected "' . $expected_id . '")'
			);
		}

		if ( '' === $adr['title'] ) {
			$problems[] = self::problem( $path, 'missing required field `title`' );
		}

		$problems = array_merge(
			$problems,
			self::validate_date_field( $path, $adr['date'] ),
			self::validate_status_field( $path, $adr['status'], ExeLearning_Architecture_Records::ADR_STATUSES ),
			self::validate_tracking_number( $path, $adr['tracking_issue'], $adr['issue'], 'filename' )
		);

		if ( null === $adr['ai_tool'] || '' === $adr['ai_tool'] ) {
			$problems[] = self::problem(
				$path,
				'missing required field `ai_assistance.tool` (use `none` if no AI tool was used)'
			);
		}
		if ( null === $adr['ai_model'] || '' === $adr['ai_model'] ) {
			$problems[] = self::problem(
				$path,
				'missing required field `ai_assistance.model` (use `none` if no AI tool was used)'
			);
		}

		$expected_h1 = $expected_id . ': ' . $adr['title'];
		if ( null === $adr['h1'] ) {
			$problems[] = self::problem( $path, 'missing H1 heading' );
		} elseif ( $adr['h1'] !== $expected_h1 ) {
			$problems[] = self::problem(
				$path,
				'H1 is "' . $adr['h1'] . '" but should be "' . $expected_h1 . '"'
			);
		}

		return $problems;
	}
	/**
	 * Validate a `date` field, when present.
	 *
	 * @param string $path  Repository-relative path.
	 * @param string $value Raw date value.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_date_field( string $path, string $value ): array {
		if ( '' === $value ) {
			return array( self::problem( $path, 'missing required field `date`' ) );
		}
		if ( ! ExeLearning_Architecture_Records::is_valid_date( $value ) ) {
			return array( self::problem( $path, 'date "' . $value . '" is not a valid YYYY-MM-DD date' ) );
		}
		return array();
	}
	/**
	 * Validate a `status` field against its vocabulary.
	 *
	 * @param string   $path     Repository-relative path.
	 * @param string   $value    Raw status value.
	 * @param string[] $allowed  Permitted values.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_status_field( string $path, string $value, array $allowed ): array {
		if ( '' === $value ) {
			return array( self::problem( $path, 'missing required field `status`' ) );
		}
		if ( ! in_array( $value, $allowed, true ) ) {
			return array(
				self::problem( $path, 'status "' . $value . '" is not one of ' . implode( ', ', $allowed ) ),
			);
		}
		return array();
	}
	/**
	 * Validate `tracking_issue` against the number carried by the path.
	 *
	 * @param string $path     Repository-relative path.
	 * @param string $value    Raw tracking_issue value.
	 * @param int    $expected Number derived from the filename or directory.
	 * @param string $source   Where $expected came from, for the message.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_tracking_number(
		string $path,
		string $value,
		int $expected,
		string $source
	): array {
		if ( '' === $value ) {
			return array( self::problem( $path, 'missing required field `tracking_issue`' ) );
		}
		if ( ! ExeLearning_Architecture_Records::is_positive_integer( $value ) ) {
			return array(
				self::problem( $path, 'tracking_issue "' . $value . '" is not a positive integer' ),
			);
		}
		if ( (int) $value !== $expected ) {
			return array(
				self::problem(
					$path,
					'tracking_issue ' . $value . ' does not match ' . $source . ' tracking number ' . $expected
				),
			);
		}
		return array();
	}
	/**
	 * Reject duplicate ids and duplicate issue-local sequences.
	 *
	 * @param array<string,mixed>   $adr             Discovered ADR.
	 * @param array<string,string>  $seen_ids        Id to path, updated in place.
	 * @param array<string,string>  $seen_sequences  Sequence key to path, updated in place.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_adr_uniqueness(
		array $adr,
		array &$seen_ids,
		array &$seen_sequences
	): array {
		$problems = array();
		$path     = $adr['path'];

		if ( isset( $seen_ids[ $adr['id'] ] ) ) {
			$problems[] = self::problem(
				$path,
				'duplicate ADR id "' . $adr['id'] . '" (also in ' . $seen_ids[ $adr['id'] ] . ')'
			);
		} elseif ( '' !== $adr['id'] ) {
			$seen_ids[ $adr['id'] ] = $path;
		}

		$sequence_key = $adr['issue'] . '-' . $adr['sequence'];
		if ( isset( $seen_sequences[ $sequence_key ] ) ) {
			$problems[] = self::problem(
				$path,
				'duplicate local sequence ' . $adr['sequence'] . ' for tracking number ' . $adr['issue']
					. ' (also in ' . $seen_sequences[ $sequence_key ] . ')'
			);
		} else {
			$seen_sequences[ $sequence_key ] = $path;
		}

		return $problems;
	}
	/**
	 * Check that every cross-reference on an ADR resolves.
	 *
	 * @param array<string,mixed> $adr          Discovered ADR.
	 * @param string[]            $adr_ids      Known ADR ids.
	 * @param string[]            $change_names Known change directory names.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_adr_references( array $adr, array $adr_ids, array $change_names ): array {
		$problems = array();
		$path     = $adr['path'];

		foreach ( $adr['related_adrs'] as $ref ) {
			if ( ! in_array( $ref, $adr_ids, true ) ) {
				$problems[] = self::problem( $path, 'related.adrs references unknown ADR "' . $ref . '"' );
			}
		}
		foreach ( $adr['related_changes'] as $ref ) {
			if ( ! in_array( $ref, $change_names, true ) ) {
				$problems[] = self::problem( $path, 'related.changes references unknown change "' . $ref . '"' );
			}
		}
		foreach ( $adr['related_prs'] as $pr ) {
			if ( ! ExeLearning_Architecture_Records::is_positive_integer( $pr ) ) {
				$problems[] = self::problem( $path, 'related.prs value "' . $pr . '" is not a positive integer' );
			}
		}
		foreach ( $adr['external_refs'] as $ref ) {
			if ( ! ExeLearning_Architecture_Records::is_http_url( $ref ) ) {
				$problems[] = self::problem( $path, 'external_refs value "' . $ref . '" is not an http(s) URL' );
			}
		}

		return $problems;
	}
	/**
	 * Check that supersession is declared from both sides and the superseded
	 * record carries the matching status.
	 *
	 * @param array<string,mixed>              $adr        Discovered ADR.
	 * @param array<string,array<string,mixed>> $adrs_by_id Index of ADRs by id.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_adr_supersession( array $adr, array $adrs_by_id ): array {
		$problems = array();
		$path     = $adr['path'];

		foreach ( $adr['supersedes'] as $ref ) {
			if ( $ref === $adr['id'] ) {
				$problems[] = self::problem( $path, 'ADR cannot supersede itself' );
				continue;
			}
			if ( ! isset( $adrs_by_id[ $ref ] ) ) {
				$problems[] = self::problem( $path, 'supersedes references unknown ADR "' . $ref . '"' );
				continue;
			}
			$target = $adrs_by_id[ $ref ];
			if ( ! in_array( $adr['id'], $target['superseded_by'], true ) ) {
				$problems[] = self::problem(
					$path,
					'supersedes "' . $ref . '" but ' . $target['path']
						. ' does not list superseded_by: [' . $adr['id'] . ']'
				);
			}
			if ( 'Superseded' !== $target['status'] ) {
				$problems[] = self::problem(
					$target['path'],
					'is superseded by ' . $adr['id'] . ' but status is "' . $target['status'] . '", not "Superseded"'
				);
			}
		}

		foreach ( $adr['superseded_by'] as $ref ) {
			if ( $ref === $adr['id'] ) {
				$problems[] = self::problem( $path, 'ADR cannot be superseded by itself' );
				continue;
			}
			if ( ! isset( $adrs_by_id[ $ref ] ) ) {
				$problems[] = self::problem( $path, 'superseded_by references unknown ADR "' . $ref . '"' );
				continue;
			}
			$target = $adrs_by_id[ $ref ];
			if ( ! in_array( $adr['id'], $target['supersedes'], true ) ) {
				$problems[] = self::problem(
					$path,
					'superseded_by "' . $ref . '" but ' . $target['path']
						. ' does not list supersedes: [' . $adr['id'] . ']'
				);
			}
		}

		return $problems;
	}
	/**
	 * Validate a change's canonical metadata.
	 *
	 * @param array<string,mixed> $change       Discovered change.
	 * @param string[]            $adr_ids      Known ADR ids.
	 * @param string[]            $change_names Known change directory names.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_change_metadata( array $change, array $adr_ids, array $change_names ): array {
		$canonical = $change['canonical'];
		$path      = $canonical['path'];
		$problems  = array();

		if ( '' === $change['title'] ) {
			$problems[] = self::problem( $path, 'missing required field `title`' );
		}

		$problems = array_merge(
			$problems,
			self::validate_date_field( $path, $change['date'] ),
			self::validate_status_field( $path, $change['status'], ExeLearning_Architecture_Records::CHANGE_STATUSES )
		);

		foreach ( $change['implementation_prs'] as $pr ) {
			if ( ! ExeLearning_Architecture_Records::is_positive_integer( $pr ) ) {
				$problems[] = self::problem(
					$path,
					'implementation_prs value "' . $pr . '" is not a positive integer'
				);
			}
		}
		foreach ( $change['related_adrs'] as $ref ) {
			if ( ! in_array( $ref, $adr_ids, true ) ) {
				$problems[] = self::problem( $path, 'related_adrs references unknown ADR "' . $ref . '"' );
			}
		}
		// Change-to-change links are the one cross-reference a tracking number
		// cannot make self-evident: one number can own two directories, so a
		// sibling reference is a plain directory name with nothing in the
		// filename to catch a typo or a later rename.
		foreach ( $change['related_changes'] as $ref ) {
			if ( $ref === $change['name'] ) {
				$problems[] = self::problem( $path, 'related_changes references the change itself' );
			} elseif ( ! in_array( $ref, $change_names, true ) ) {
				$problems[] = self::problem( $path, 'related_changes references unknown change "' . $ref . '"' );
			}
		}

		return $problems;
	}
	/**
	 * Validate each document inside a change directory.
	 *
	 * @param array<string,mixed> $change Discovered change.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function validate_change_documents( array $change ): array {
		$canonical = $change['canonical'];
		$problems  = array();

		foreach ( $change['documents'] as $doc ) {
			$path = $doc['path'];

			$problems = array_merge(
				$problems,
				self::validate_tracking_number(
					$path,
					ExeLearning_Architecture_Records::as_string( $doc['data']['tracking_issue'] ?? null ),
					$change['issue'],
					'change directory'
				)
			);

			if ( '' === ExeLearning_Architecture_Records::as_string( $doc['data']['title'] ?? null ) ) {
				$problems[] = self::problem( $path, 'missing required field `title`' );
			}

			foreach ( ExeLearning_Architecture_Records::as_list( $doc['data']['related_prs'] ?? null ) as $pr ) {
				if ( ! ExeLearning_Architecture_Records::is_positive_integer( $pr ) ) {
					$problems[] = self::problem( $path, 'related_prs value "' . $pr . '" is not a positive integer' );
				}
			}
			foreach ( ExeLearning_Architecture_Records::as_list( $doc['data']['external_refs'] ?? null ) as $ref ) {
				if ( ! ExeLearning_Architecture_Records::is_http_url( $ref ) ) {
					$problems[] = self::problem( $path, 'external_refs value "' . $ref . '" is not an http(s) URL' );
				}
			}

			if ( $doc['name'] !== $canonical['name'] && isset( $doc['data']['implementation_prs'] ) ) {
				$problems[] = self::problem(
					$path,
					'declares implementation_prs, but ' . $canonical['name']
						. ' is the canonical metadata carrier for this change'
				);
			}
		}

		return $problems;
	}
}

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
	 * `related_adrs`, `related_changes`).
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
				'related_changes'    => self::as_list( $data['related_changes'] ?? null ),
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
			if ( self::is_legacy_reference_allowed( $file ) ) {
				continue;
			}

			$content = self::read_text_file( $root . '/' . $file );
			if ( null === $content ) {
				continue;
			}

			$problems = array_merge( $problems, self::scan_legacy_references( $file, $content ) );
		}

		return $problems;
	}

	/**
	 * Whether a path may name a retired identifier.
	 *
	 * @param string $file Repository-relative path.
	 * @return bool True when the path is on the documented allowlist.
	 */
	private static function is_legacy_reference_allowed( string $file ): bool {
		foreach ( self::LEGACY_REFERENCE_ALLOWLIST as $prefix ) {
			if ( $file === $prefix || 0 === strpos( $file, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read a readable, non-binary file.
	 *
	 * @param string $full Absolute path.
	 * @return string|null Contents, or null when unreadable or binary.
	 */
	private static function read_text_file( string $full ) {
		if ( ! is_file( $full ) || ! is_readable( $full ) ) {
			return null;
		}
		$content = file_get_contents( $full );
		if ( false === $content || false !== strpos( $content, "\0" ) ) {
			return null;
		}
		return $content;
	}

	/**
	 * Report retired identifiers inside one file.
	 *
	 * A migrated document may name its own former identifier, so the provenance
	 * note inside the document itself stays readable.
	 *
	 * @param string $file    Repository-relative path.
	 * @param string $content File contents.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	private static function scan_legacy_references( string $file, string $content ): array {
		$own_legacy_id = null;
		if ( substr( $file, -3 ) === '.md' ) {
			$parsed = self::parse_frontmatter( $content );
			if ( null !== $parsed && isset( $parsed['data']['legacy_id'] ) ) {
				$own_legacy_id = self::as_string( $parsed['data']['legacy_id'] );
			}
		}

		$problems = array();
		$lines    = preg_split( '/\r?\n/', $content );
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
			$problems[] = ExeLearning_Architecture_Validator::problem(
				$file . ':' . ( $index + 1 ),
				'references retired identifier "' . $hit[0] . '". Use the current identifier (see '
					. self::MIGRATION_MAP . ').'
			);
		}

		return $problems;
	}

	// -----------------------------------------------------------------------
	// Facade
	//
	// Frontmatter parsing and validation live in their own classes; these
	// delegations keep the documented public surface (and its tests) stable.
	// -----------------------------------------------------------------------

	/**
	 * @param string $raw Document contents.
	 * @return array{data:array<string,mixed>,body:string}|null Parsed document.
	 */
	public static function parse_frontmatter( string $raw ) {
		return ExeLearning_Architecture_Frontmatter::parse_frontmatter( $raw );
	}

	/**
	 * @param string $value Raw scalar.
	 * @return string Unquoted scalar.
	 */
	public static function strip_quotes( string $value ): string {
		return ExeLearning_Architecture_Frontmatter::strip_quotes( $value );
	}

	/**
	 * @param string $raw Raw value.
	 * @return string|array<int,string> Scalar or list.
	 */
	public static function parse_scalar_or_inline_list( string $raw ) {
		return ExeLearning_Architecture_Frontmatter::parse_scalar_or_inline_list( $raw );
	}

	/**
	 * @param mixed $value Frontmatter value.
	 * @return array<int,string> Normalised list.
	 */
	public static function as_list( $value ): array {
		return ExeLearning_Architecture_Frontmatter::as_list( $value );
	}

	/**
	 * @param mixed $value Frontmatter value.
	 * @return string Normalised scalar.
	 */
	public static function as_string( $value ): string {
		return ExeLearning_Architecture_Frontmatter::as_string( $value );
	}

	/**
	 * @param array<string,mixed> $data Frontmatter.
	 * @param string              $key  Outer key.
	 * @param string              $sub  Nested key.
	 * @return mixed Nested value or null.
	 */
	public static function nested( array $data, string $key, string $sub ) {
		return ExeLearning_Architecture_Frontmatter::nested( $data, $key, $sub );
	}

	/**
	 * @param array<int,array<string,mixed>> $adrs    Discovered ADRs.
	 * @param array<int,array<string,mixed>> $changes Discovered changes.
	 * @return array<int,array{file:string,message:string}> Diagnostics.
	 */
	public static function validate( array $adrs, array $changes ): array {
		return ExeLearning_Architecture_Validator::validate( $adrs, $changes );
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
