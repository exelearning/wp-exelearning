<?php
/**
 * Tests for ExeLearning_Architecture_Records (bin/architecture-records.php).
 *
 * The validator guards the tracking-number identification model described in
 * docs/architecture/adr/README.md and docs/architecture/changes/README.md.
 * Everything it checks is exercised here against throwaway fixture trees, plus
 * one guard that the real repository passes its own check.
 *
 * This file deliberately contains retired `ADR-NNNN` / `SDD-NNNN` identifiers
 * as fixtures — it is on the detector's allowlist for exactly that reason.
 *
 * @package Exelearning
 */

require_once dirname( __DIR__, 2 ) . '/bin/architecture-records.php';

/**
 * Class ArchitectureRecordsTest.
 *
 * @covers ExeLearning_Architecture_Records
 */
class ArchitectureRecordsTest extends WP_UnitTestCase {

	/**
	 * Throwaway fixture roots created by this test, removed on teardown.
	 *
	 * @var string[]
	 */
	private $roots = array();

	/**
	 * Remove every fixture tree.
	 */
	public function tear_down() {
		foreach ( $this->roots as $root ) {
			$this->rrmdir( $root );
		}
		$this->roots = array();
		parent::tear_down();
	}

	/* -------------------------------------------------------------------- */
	/* Helpers                                                              */
	/* -------------------------------------------------------------------- */

	/**
	 * Create an empty fixture repository root.
	 *
	 * @return string Absolute path.
	 */
	private function make_root() {
		$root = rtrim( get_temp_dir(), '/' ) . '/exe-arch-' . wp_generate_password( 12, false );
		mkdir( $root, 0777, true );
		$this->roots[] = $root;
		return $root;
	}

	/**
	 * Write a file inside a fixture root, creating parent directories.
	 *
	 * @param string $root     Fixture root.
	 * @param string $relative Repository-relative path.
	 * @param string $contents File contents.
	 * @return void
	 */
	private function write( $root, $relative, $contents ) {
		$full = $root . '/' . $relative;
		$dir  = dirname( $full );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $full, $contents );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * A valid ADR document.
	 *
	 * @param array $overrides Frontmatter overrides (id, title, status, date,
	 *                         tracking_issue, extra, h1).
	 * @return string File contents.
	 */
	private function adr_document( array $overrides = array() ) {
		$id     = $overrides['id'] ?? 'ADR-68-01';
		$title  = $overrides['title'] ?? 'A durable decision';
		$status = $overrides['status'] ?? 'Accepted';
		$date   = $overrides['date'] ?? '2026-07-10';
		$issue  = $overrides['tracking_issue'] ?? '68';
		$extra  = $overrides['extra'] ?? '';
		$h1     = $overrides['h1'] ?? $id . ': ' . $title;

		return "---\n"
			. "id: {$id}\n"
			. "title: \"{$title}\"\n"
			. "status: {$status}\n"
			. "date: {$date}\n"
			. "tracking_issue: {$issue}\n"
			. $extra
			. "ai_assistance:\n"
			. "  tool: \"Claude Code\"\n"
			. "  model: \"claude-opus-5\"\n"
			. "---\n\n"
			. "# {$h1}\n\n## Context\n\nSomething happened.\n";
	}

	/**
	 * A valid change document.
	 *
	 * @param array $overrides Frontmatter overrides.
	 * @return string File contents.
	 */
	private function change_document( array $overrides = array() ) {
		$issue  = $overrides['tracking_issue'] ?? '68';
		$title  = $overrides['title'] ?? 'A significant change';
		$status = $overrides['status'] ?? 'implemented';
		$date   = $overrides['date'] ?? '2026-07-10';
		$extra  = $overrides['extra'] ?? '';

		return "---\n"
			. "tracking_issue: {$issue}\n"
			. "title: \"{$title}\"\n"
			. "status: {$status}\n"
			. "date: {$date}\n"
			. $extra
			. "ai_assistance:\n"
			. "  tool: \"none\"\n"
			. "  model: \"none\"\n"
			. "---\n\n"
			. "# {$title} — design\n\n## Summary\n\nA design.\n";
	}

	/**
	 * Collect diagnostic messages as a single searchable string.
	 *
	 * @param array $problems Diagnostics.
	 * @return string Joined "file: message" lines.
	 */
	private function messages( array $problems ) {
		$lines = array();
		foreach ( $problems as $problem ) {
			$lines[] = $problem['file'] . ': ' . $problem['message'];
		}
		return implode( "\n", $lines );
	}

	/* -------------------------------------------------------------------- */
	/* Frontmatter parsing                                                  */
	/* -------------------------------------------------------------------- */

	/**
	 * A document without frontmatter parses to null.
	 */
	public function test_parse_frontmatter_returns_null_without_frontmatter() {
		$this->assertNull( ExeLearning_Architecture_Records::parse_frontmatter( "# Just a heading\n" ) );
	}

	/**
	 * Scalars, inline lists and quoting round-trip.
	 */
	public function test_parse_frontmatter_reads_scalars_and_inline_lists() {
		$parsed = ExeLearning_Architecture_Records::parse_frontmatter(
			"---\nid: ADR-68-01\ntitle: \"Quoted title\"\nempty: []\nprs: [68, 72]\n---\n\nbody\n"
		);

		$this->assertSame( 'ADR-68-01', $parsed['data']['id'] );
		$this->assertSame( 'Quoted title', $parsed['data']['title'] );
		$this->assertSame( array(), $parsed['data']['empty'] );
		$this->assertSame( array( '68', '72' ), $parsed['data']['prs'] );
		$this->assertSame( "\nbody\n", $parsed['body'] );
	}

	/**
	 * Top-level block lists and nested mappings are both understood.
	 */
	public function test_parse_frontmatter_reads_block_lists_and_nested_mappings() {
		$parsed = ExeLearning_Architecture_Records::parse_frontmatter(
			"---\n"
			. "external_refs:\n  - https://example.test/a\n  - https://example.test/b\n"
			. "related:\n  prs: [68]\n  changes: [\"68-slug\"]\n  adrs: []\n"
			. "ai_assistance:\n  tool: \"Claude Code\"\n  model: \"claude-opus-5\"\n"
			. "---\n\nbody\n"
		);

		$this->assertSame(
			array( 'https://example.test/a', 'https://example.test/b' ),
			$parsed['data']['external_refs']
		);
		$this->assertSame( array( '68' ), $parsed['data']['related']['prs'] );
		$this->assertSame( array( '68-slug' ), $parsed['data']['related']['changes'] );
		$this->assertSame( 'Claude Code', $parsed['data']['ai_assistance']['tool'] );
	}

	/**
	 * The retired frontmatter shape nested a block list inside a mapping. The
	 * parser must still read those files, or a migration could not be checked.
	 */
	public function test_parse_frontmatter_reads_block_lists_nested_in_a_mapping() {
		$parsed = ExeLearning_Architecture_Records::parse_frontmatter(
			"---\nrelated:\n  prs:\n    - https://example.test/pull/68\n  adrs:\n    - ADR-0001\n  sdds: []\n---\n\nbody\n"
		);

		$this->assertSame( array( 'https://example.test/pull/68' ), $parsed['data']['related']['prs'] );
		$this->assertSame( array( 'ADR-0001' ), $parsed['data']['related']['adrs'] );
		$this->assertSame( array(), $parsed['data']['related']['sdds'] );
	}

	/**
	 * Blank lines and YAML comments are ignored.
	 */
	public function test_parse_frontmatter_ignores_comments_and_blank_lines() {
		$parsed = ExeLearning_Architecture_Records::parse_frontmatter(
			"---\n# a comment\n\nid: ADR-68-01\n\n---\n\nbody\n"
		);

		$this->assertSame( array( 'id' => 'ADR-68-01' ), $parsed['data'] );
	}

	/* -------------------------------------------------------------------- */
	/* Headings and code fences                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * A `# comment` inside a fenced shell block is not the document H1.
	 *
	 * ADR-86-01 really contains one, so a naive scanner reports the wrong H1.
	 */
	public function test_first_h1_ignores_headings_inside_code_fences() {
		$body = "```bash\n# apply the change\nmake package\n```\n\n# ADR-86-01: The real title\n";

		$this->assertSame( 'ADR-86-01: The real title', ExeLearning_Architecture_Records::first_h1( $body ) );
	}

	/**
	 * Tilde fences are stripped too, and a body with no H1 yields null.
	 */
	public function test_first_h1_handles_tilde_fences_and_missing_headings() {
		$this->assertNull( ExeLearning_Architecture_Records::first_h1( "~~~\n# not a heading\n~~~\n" ) );
		$this->assertNull( ExeLearning_Architecture_Records::first_h1( "no heading at all\n" ) );
	}

	/* -------------------------------------------------------------------- */
	/* Scalar validators                                                    */
	/* -------------------------------------------------------------------- */

	/**
	 * Only real calendar dates in YYYY-MM-DD form are accepted.
	 */
	public function test_is_valid_date() {
		$this->assertTrue( ExeLearning_Architecture_Records::is_valid_date( '2026-07-10' ) );
		$this->assertTrue( ExeLearning_Architecture_Records::is_valid_date( '2024-02-29' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_valid_date( '2026-02-30' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_valid_date( '2026-13-01' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_valid_date( '2026-7-10' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_valid_date( 'yesterday' ) );
	}

	/**
	 * Tracking numbers have no leading zeros and no sign.
	 */
	public function test_is_positive_integer() {
		$this->assertTrue( ExeLearning_Architecture_Records::is_positive_integer( '68' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_positive_integer( '068' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_positive_integer( '0' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_positive_integer( '-1' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_positive_integer( 'https://example.test/pull/68' ) );
	}

	/**
	 * Cross-repository references must be absolute http(s) URLs.
	 */
	public function test_is_http_url() {
		$this->assertTrue( ExeLearning_Architecture_Records::is_http_url( 'https://example.test/x' ) );
		$this->assertTrue( ExeLearning_Architecture_Records::is_http_url( 'http://example.test/x' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_http_url( '2150' ) );
		$this->assertFalse( ExeLearning_Architecture_Records::is_http_url( 'exelearning/exelearning#2150' ) );
	}

	/* -------------------------------------------------------------------- */
	/* Discovery                                                            */
	/* -------------------------------------------------------------------- */

	/**
	 * A well-formed ADR is discovered with its identifier decomposed.
	 */
	public function test_discover_adrs_reads_a_valid_record() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-68-01-a-decision-slug.md', $this->adr_document() );

		$result = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertSame( array(), $result['errors'] );
		$this->assertCount( 1, $result['adrs'] );
		$this->assertSame( 'ADR-68-01', $result['adrs'][0]['id'] );
		$this->assertSame( 68, $result['adrs'][0]['issue'] );
		$this->assertSame( '01', $result['adrs'][0]['sequence'] );
		$this->assertSame( 'Claude Code', $result['adrs'][0]['ai_tool'] );
	}

	/**
	 * README.md, records.md and template.md are not records.
	 */
	public function test_discover_adrs_skips_non_record_files() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/README.md', "# Policy\n" );
		$this->write( $root, 'docs/architecture/adr/records.md', "# Index\n" );
		$this->write( $root, 'docs/architecture/adr/template.md', "---\nid: ADR-NNN-01\n---\n\n# x\n" );

		$result = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertSame( array(), $result['adrs'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * A retired filename gets a message that names the migration, not a generic
	 * "does not match" — that is the whole point of keeping the legacy pattern.
	 */
	public function test_discover_adrs_reports_retired_filenames_distinctly() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-0001-old-style.md', $this->adr_document() );
		$this->write( $root, 'docs/architecture/adr/nonsense.md', $this->adr_document() );

		$result = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertSame( array(), $result['adrs'] );
		$this->assertStringContainsString( 'retired global numbering', $this->messages( $result['errors'] ) );
		$this->assertStringContainsString( 'filename does not match', $this->messages( $result['errors'] ) );
	}

	/**
	 * A four-digit tracking number is a current identifier, not a legacy one.
	 */
	public function test_discover_adrs_accepts_four_digit_tracking_numbers() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-1858-01-a-decision-slug.md',
			$this->adr_document(
				array(
					'id'             => 'ADR-1858-01',
					'tracking_issue' => '1858',
				)
			)
		);

		$result = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 1858, $result['adrs'][0]['issue'] );
	}

	/**
	 * A record with no frontmatter is a structural error, not a crash.
	 */
	public function test_discover_adrs_reports_missing_frontmatter() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-68-01-a-decision-slug.md', "# No frontmatter\n" );

		$result = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertStringContainsString( 'missing YAML frontmatter', $this->messages( $result['errors'] ) );
	}

	/**
	 * A change directory is discovered with its documents in canonical order.
	 */
	public function test_discover_changes_orders_documents_canonically() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/changes/68-a-slug/design.md', $this->change_document() );
		$this->write( $root, 'docs/architecture/changes/68-a-slug/proposal.md', $this->change_document() );

		$result = ExeLearning_Architecture_Records::discover_changes( $root );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'proposal.md', $result['changes'][0]['canonical']['name'] );
		$this->assertSame(
			array( 'proposal.md', 'design.md' ),
			array_column( $result['changes'][0]['documents'], 'name' )
		);
	}

	/**
	 * A malformed directory name and an empty directory are both reported.
	 */
	public function test_discover_changes_reports_bad_directories() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/changes/Not_A_Slug/design.md', $this->change_document() );
		mkdir( $root . '/docs/architecture/changes/68-empty', 0777, true );

		$result = ExeLearning_Architecture_Records::discover_changes( $root );

		$messages = $this->messages( $result['errors'] );
		$this->assertStringContainsString( 'directory name does not match', $messages );
		$this->assertStringContainsString( 'contains no recognised document', $messages );
	}

	/* -------------------------------------------------------------------- */
	/* Validation                                                           */
	/* -------------------------------------------------------------------- */

	/**
	 * A consistent pair of records produces no diagnostics.
	 */
	public function test_validate_accepts_consistent_records() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-a-decision-slug.md',
			$this->adr_document(
				array(
					'extra' => "related:\n  prs: [68]\n  changes: [\"68-a-slug\"]\n  adrs: []\n"
						. "external_refs:\n  - https://example.test/issues/2150\n",
				)
			)
		);
		$this->write(
			$root,
			'docs/architecture/changes/68-a-slug/design.md',
			$this->change_document( array( 'extra' => "implementation_prs: [68]\nrelated_adrs: [ADR-68-01]\n" ) )
		);

		$adrs    = ExeLearning_Architecture_Records::discover_adrs( $root );
		$changes = ExeLearning_Architecture_Records::discover_changes( $root );

		$this->assertSame(
			array(),
			ExeLearning_Architecture_Records::validate( $adrs['adrs'], $changes['changes'] )
		);
	}

	/**
	 * The frontmatter id, the tracking number and the H1 must all agree with
	 * the filename. Any of them drifting is what silently breaks inbound links.
	 */
	public function test_validate_rejects_identifier_drift() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-a-decision-slug.md',
			$this->adr_document(
				array(
					'id'             => 'ADR-72-01',
					'tracking_issue' => '99',
					'h1'             => 'Something else entirely',
				)
			)
		);

		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'does not match filename (expected "ADR-68-01")', $messages );
		$this->assertStringContainsString( 'tracking_issue 99 does not match filename tracking number 68', $messages );
		$this->assertStringContainsString( 'H1 is "Something else entirely"', $messages );
	}

	/**
	 * Required metadata and the closed status vocabulary are both enforced.
	 */
	public function test_validate_reports_missing_and_invalid_metadata() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-a-decision-slug.md',
			"---\nid: ADR-68-01\ntitle: \"\"\nstatus: Maybe\ndate: 2026-02-30\ntracking_issue: 68\n---\n\n# ADR-68-01: \n"
		);

		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'missing required field `title`', $messages );
		$this->assertStringContainsString( 'status "Maybe" is not one of', $messages );
		$this->assertStringContainsString( 'is not a valid YYYY-MM-DD date', $messages );
		$this->assertStringContainsString( 'missing required field `ai_assistance.tool`', $messages );
		$this->assertStringContainsString( 'missing required field `ai_assistance.model`', $messages );
	}

	/**
	 * `deciders` is required by the main repository's validator but not here:
	 * AGENTS.md forbids people's names in frontmatter, so requiring the field
	 * would force contributors to fabricate provenance.
	 */
	public function test_validate_does_not_require_deciders() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-68-01-a-decision-slug.md', $this->adr_document() );

		$adrs = ExeLearning_Architecture_Records::discover_adrs( $root );

		$this->assertStringNotContainsString(
			'deciders',
			$this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) )
		);
	}

	/**
	 * Two records may share a tracking number, but not a local sequence.
	 */
	public function test_validate_rejects_a_reused_local_sequence() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-68-01-first.md', $this->adr_document() );
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-02-second.md',
			$this->adr_document(
				array(
					'id'    => 'ADR-68-02',
					'title' => 'Another decision',
				)
			)
		);

		$adrs = ExeLearning_Architecture_Records::discover_adrs( $root );
		$this->assertSame(
			array(),
			ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ),
			'Two ADRs under one tracking number are legitimate.'
		);

		// Now make the second one claim the first one's sequence.
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-02-second.md',
			$this->adr_document(
				array(
					'id'    => 'ADR-68-01',
					'title' => 'Another decision',
					'h1'    => 'ADR-68-02: Another decision',
				)
			)
		);
		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'does not match filename (expected "ADR-68-02")', $messages );
	}

	/**
	 * Cross-references must resolve, PR numbers must be numbers, and
	 * cross-repository links must be URLs.
	 */
	public function test_validate_checks_cross_references_and_reference_shapes() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-a-decision-slug.md',
			$this->adr_document(
				array(
					'extra' => "related:\n"
						. "  prs: [\"https://example.test/pull/68\"]\n"
						. "  changes: [\"99-missing\"]\n"
						. "  adrs: [ADR-99-01]\n"
						. "external_refs: [2150]\n",
				)
			)
		);

		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'related.adrs references unknown ADR "ADR-99-01"', $messages );
		$this->assertStringContainsString( 'related.changes references unknown change "99-missing"', $messages );
		$this->assertStringContainsString( 'is not a positive integer', $messages );
		$this->assertStringContainsString( 'external_refs value "2150" is not an http(s) URL', $messages );
	}

	/**
	 * Supersession must be declared from both sides, and the superseded record
	 * must say so in its status.
	 */
	public function test_validate_requires_symmetric_supersession() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-old.md',
			$this->adr_document()
		);
		$this->write(
			$root,
			'docs/architecture/adr/ADR-86-01-new.md',
			$this->adr_document(
				array(
					'id'             => 'ADR-86-01',
					'tracking_issue' => '86',
					'title'          => 'A replacement decision',
					'extra'          => "supersedes: [ADR-68-01]\n",
				)
			)
		);

		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'does not list superseded_by: [ADR-86-01]', $messages );
		$this->assertStringContainsString( 'status is "Accepted", not "Superseded"', $messages );
	}

	/**
	 * A record cannot supersede or be superseded by itself.
	 */
	public function test_validate_rejects_self_supersession() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/adr/ADR-68-01-a-decision-slug.md',
			$this->adr_document( array( 'extra' => "supersedes: [ADR-68-01]\nsuperseded_by: [ADR-68-01]\n" ) )
		);

		$adrs     = ExeLearning_Architecture_Records::discover_adrs( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], array() ) );

		$this->assertStringContainsString( 'ADR cannot supersede itself', $messages );
		$this->assertStringContainsString( 'ADR cannot be superseded by itself', $messages );
	}

	/**
	 * Every document's tracking number must match its directory, and only the
	 * canonical document may declare implementation_prs.
	 */
	public function test_validate_checks_change_documents() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/changes/68-a-slug/proposal.md',
			$this->change_document( array( 'extra' => "implementation_prs: [68]\nrelated_adrs: [ADR-99-01]\n" ) )
		);
		$this->write(
			$root,
			'docs/architecture/changes/68-a-slug/design.md',
			$this->change_document(
				array(
					'tracking_issue' => '99',
					'extra'          => "implementation_prs: [99]\n",
				)
			)
		);

		$changes  = ExeLearning_Architecture_Records::discover_changes( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( array(), $changes['changes'] ) );

		$this->assertStringContainsString( 'tracking_issue 99 does not match change directory tracking number 68', $messages );
		$this->assertStringContainsString( 'proposal.md is the canonical metadata carrier', $messages );
		$this->assertStringContainsString( 'related_adrs references unknown ADR "ADR-99-01"', $messages );
	}

	/**
	 * Change statuses use the lowercase vocabulary, not the ADR one.
	 */
	public function test_validate_rejects_capitalised_change_statuses() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/changes/68-a-slug/design.md',
			$this->change_document( array( 'status' => 'Implemented' ) )
		);

		$changes  = ExeLearning_Architecture_Records::discover_changes( $root );
		$messages = $this->messages( ExeLearning_Architecture_Records::validate( array(), $changes['changes'] ) );

		$this->assertStringContainsString( 'status "Implemented" is not one of', $messages );
	}

	/* -------------------------------------------------------------------- */
	/* Committed indexes and retired identifiers                            */
	/* -------------------------------------------------------------------- */

	/**
	 * A committed index is an error; it is generated, not stored.
	 */
	public function test_find_committed_indexes() {
		$problems = ExeLearning_Architecture_Records::find_committed_indexes(
			array(
				'docs/architecture/adr/records.md',
				'docs/architecture/changes/records.md',
				'docs/architecture/adr/README.md',
			)
		);

		$this->assertCount( 2, $problems );
		$this->assertStringContainsString( 'must not be committed', $problems[0]['message'] );
	}

	/**
	 * Retired identifiers are reported with a file:line location.
	 */
	public function test_find_legacy_references_reports_retired_identifiers() {
		$root = $this->make_root();
		$this->write( $root, 'includes/class-thing.php', "<?php\n// See ADR-0002 for why.\n" );

		$problems = ExeLearning_Architecture_Records::find_legacy_references(
			$root,
			array( 'includes/class-thing.php' )
		);

		$this->assertCount( 1, $problems );
		$this->assertSame( 'includes/class-thing.php:2', $problems[0]['file'] );
		$this->assertStringContainsString( 'ADR-0002', $problems[0]['message'] );
	}

	/**
	 * A current identifier must not be mistaken for a retired one just because
	 * its tracking number happens to have four digits.
	 */
	public function test_find_legacy_references_ignores_current_identifiers() {
		$root = $this->make_root();
		$this->write( $root, 'includes/class-thing.php', "<?php\n// See ADR-72-01 and ADR-1858-04.\n" );

		$this->assertSame(
			array(),
			ExeLearning_Architecture_Records::find_legacy_references( $root, array( 'includes/class-thing.php' ) )
		);
	}

	/**
	 * A migrated document may name its own former identifier: `legacy_id` in the
	 * frontmatter, and the provenance note in the body that repeats it.
	 */
	public function test_find_legacy_references_exempts_a_documents_own_legacy_id() {
		$root = $this->make_root();
		$this->write(
			$root,
			'docs/architecture/changes/68-a-slug/design.md',
			"---\ntracking_issue: 68\nlegacy_id: SDD-0001\n---\n\n"
			. "> This document was written as `SDD-0001`.\n\nBut ADR-0002 is somebody else's.\n"
		);

		$problems = ExeLearning_Architecture_Records::find_legacy_references(
			$root,
			array( 'docs/architecture/changes/68-a-slug/design.md' )
		);

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'ADR-0002', $problems[0]['message'] );
	}

	/**
	 * The migration map is allowed to name what it migrated; without the
	 * allowlist the one page that answers "where did it go?" could not exist.
	 */
	public function test_find_legacy_references_honours_the_allowlist() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/migration-map.md', "| `ADR-0001` | `ADR-68-01` |\n" );

		$this->assertSame(
			array(),
			ExeLearning_Architecture_Records::find_legacy_references(
				$root,
				array( 'docs/architecture/migration-map.md' )
			)
		);
	}

	/**
	 * Binary files are skipped rather than scanned as text.
	 */
	public function test_find_legacy_references_skips_binary_files() {
		$root = $this->make_root();
		$this->write( $root, 'assets/thing.bin', "ADR-0001\x00binary" );

		$this->assertSame(
			array(),
			ExeLearning_Architecture_Records::find_legacy_references( $root, array( 'assets/thing.bin' ) )
		);
	}

	/* -------------------------------------------------------------------- */
	/* Index generation                                                     */
	/* -------------------------------------------------------------------- */

	/**
	 * ADRs sort by tracking number, then by local sequence — not lexically,
	 * which would put ADR-1858-01 before ADR-68-01.
	 */
	public function test_sort_adrs_orders_numerically() {
		$adrs = array(
			array(
				'issue'    => 1858,
				'sequence' => '01',
			),
			array(
				'issue'    => 68,
				'sequence' => '02',
			),
			array(
				'issue'    => 68,
				'sequence' => '01',
			),
		);

		$sorted = ExeLearning_Architecture_Records::sort_adrs( $adrs );

		$this->assertSame(
			array( '68-01', '68-02', '1858-01' ),
			array_map(
				static function ( $adr ) {
					return $adr['issue'] . '-' . $adr['sequence'];
				},
				$sorted
			)
		);
	}

	/**
	 * Two change directories may share a tracking number, so the slug is a real
	 * tiebreaker — #88 shipped two independent designs.
	 */
	public function test_sort_changes_breaks_ties_on_the_slug() {
		$changes = array(
			array(
				'issue' => 88,
				'slug'  => 'testable-editor-bundle-paths',
			),
			array(
				'issue' => 86,
				'slug'  => 'unify-release-packaging',
			),
			array(
				'issue' => 88,
				'slug'  => 'editor-bootstrap-view-returns-html',
			),
		);

		$sorted = ExeLearning_Architecture_Records::sort_changes( $changes );

		$this->assertSame(
			array(
				'86-unify-release-packaging',
				'88-editor-bootstrap-view-returns-html',
				'88-testable-editor-bundle-paths',
			),
			array_map(
				static function ( $change ) {
					return $change['issue'] . '-' . $change['slug'];
				},
				$sorted
			)
		);
	}

	/**
	 * Tracking numbers link to a pull request, because issues are disabled here.
	 */
	public function test_tracking_link_points_at_a_pull_request() {
		$this->assertSame(
			'[#68](https://github.com/exelearning/wp-exelearning/pull/68)',
			ExeLearning_Architecture_Records::tracking_link( 68 )
		);
	}

	/**
	 * The ADR index carries the generated banner, a row per record, and a
	 * section per status including the empty ones.
	 */
	public function test_render_adr_index() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/adr/ADR-68-01-a-decision-slug.md', $this->adr_document() );

		$adrs  = ExeLearning_Architecture_Records::discover_adrs( $root );
		$index = ExeLearning_Architecture_Records::render_adr_index( $adrs['adrs'] );

		$this->assertStringContainsString( 'Not a committed file.', $index );
		$this->assertStringContainsString( '[ADR-68-01](ADR-68-01-a-decision-slug.md)', $index );
		$this->assertStringContainsString( 'https://github.com/exelearning/wp-exelearning/pull/68', $index );
		$this->assertStringContainsString( '_No rejected ADRs._', $index );
	}

	/**
	 * The change index lists each document in the directory and groups by the
	 * lowercase change statuses.
	 */
	public function test_render_change_index() {
		$root = $this->make_root();
		$this->write( $root, 'docs/architecture/changes/68-a-slug/design.md', $this->change_document() );
		$this->write( $root, 'docs/architecture/changes/68-a-slug/tasks.md', $this->change_document() );

		$changes = ExeLearning_Architecture_Records::discover_changes( $root );
		$index   = ExeLearning_Architecture_Records::render_change_index( $changes['changes'] );

		$this->assertStringContainsString( '`68-a-slug`', $index );
		$this->assertStringContainsString( '[design](68-a-slug/design.md)', $index );
		$this->assertStringContainsString( '[tasks](68-a-slug/tasks.md)', $index );
		$this->assertStringContainsString( '_No draft changes._', $index );
	}

	/* -------------------------------------------------------------------- */
	/* The repository's own records                                         */
	/* -------------------------------------------------------------------- */

	/**
	 * The records actually committed to this repository are valid.
	 *
	 * This is the guard that matters: the fixtures above prove the rules work,
	 * this proves the repository obeys them.
	 */
	public function test_this_repository_passes_its_own_check() {
		$root = dirname( __DIR__, 2 );

		$adrs    = ExeLearning_Architecture_Records::discover_adrs( $root );
		$changes = ExeLearning_Architecture_Records::discover_changes( $root );

		$this->assertSame( array(), $this->structural_messages( $adrs, $changes ) );
		$this->assertSame(
			'',
			$this->messages( ExeLearning_Architecture_Records::validate( $adrs['adrs'], $changes['changes'] ) )
		);
		$this->assertNotEmpty( $adrs['adrs'] );
		$this->assertNotEmpty( $changes['changes'] );
	}

	/**
	 * Structural diagnostics from both discovery passes, as a string.
	 *
	 * @param array $adrs    ADR discovery result.
	 * @param array $changes Change discovery result.
	 * @return array Empty array when clean, otherwise the messages.
	 */
	private function structural_messages( array $adrs, array $changes ) {
		$errors = array_merge( $adrs['errors'], $changes['errors'] );
		return empty( $errors ) ? array() : array( $this->messages( $errors ) );
	}
}
