<?php
/**
 * Tests for ExeLearning_Content_Hash_Aliases class.
 *
 * Covers the obsolete-extraction-hash alias repository designed in SDD-0001:
 * registration (with verification), resolution, duplicate registration,
 * multiple aliases, invalid values, shared-hash refusals, cleanup on
 * attachment deletion, and the no-autoloaded-global-map guarantee.
 *
 * @package Exelearning
 */

/**
 * Class ContentHashAliasesTest.
 *
 * @covers ExeLearning_Content_Hash_Aliases
 */
class ContentHashAliasesTest extends WP_UnitTestCase {

	/**
	 * Meta key holding the current extraction hash.
	 *
	 * @var string
	 */
	const CURRENT_META = '_exelearning_extracted';

	/**
	 * Meta key holding retired (obsolete) extraction hashes.
	 *
	 * @var string
	 */
	const ALIAS_META = '_exelearning_obsolete_hash';

	/**
	 * Test instance.
	 *
	 * @var ExeLearning_Content_Hash_Aliases
	 */
	private $aliases;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->aliases = new ExeLearning_Content_Hash_Aliases();
	}

	/**
	 * Generate a unique, format-valid extraction hash.
	 *
	 * @return string 40-char lowercase hex hash.
	 */
	private function make_hash() {
		return sha1( uniqid( 'exe-test-', true ) );
	}

	/**
	 * Create an attachment, optionally with a current extraction hash.
	 *
	 * @param string|null $current_hash Current extraction hash to store.
	 * @return int Attachment ID.
	 */
	private function make_attachment( $current_hash = null ) {
		$attachment_id = $this->factory->attachment->create();
		if ( null !== $current_hash ) {
			update_post_meta( $attachment_id, self::CURRENT_META, $current_hash );
		}
		return $attachment_id;
	}

	/**
	 * A registered alias is persisted as attachment post meta and resolves
	 * back to the owning attachment.
	 */
	public function test_register_and_resolve_round_trip() {
		$attachment_id = $this->make_attachment( $this->make_hash() );
		$old_hash      = $this->make_hash();

		$this->assertTrue( $this->aliases->register( $attachment_id, $old_hash ) );
		$this->assertContains( $old_hash, get_post_meta( $attachment_id, self::ALIAS_META ) );
		$this->assertSame( $attachment_id, $this->aliases->resolve( $old_hash ) );
	}

	/**
	 * Multiple aliases can be registered for one attachment and all resolve.
	 */
	public function test_multiple_aliases_per_attachment() {
		$attachment_id = $this->make_attachment( $this->make_hash() );
		$hash_a        = $this->make_hash();
		$hash_b        = $this->make_hash();

		$this->assertTrue( $this->aliases->register( $attachment_id, $hash_a ) );
		$this->assertTrue( $this->aliases->register( $attachment_id, $hash_b ) );

		$this->assertSame( $attachment_id, $this->aliases->resolve( $hash_a ) );
		$this->assertSame( $attachment_id, $this->aliases->resolve( $hash_b ) );
	}

	/**
	 * Re-registering the same alias on the same attachment is idempotent:
	 * it succeeds and does not duplicate the meta row.
	 */
	public function test_duplicate_registration_same_attachment_is_idempotent() {
		$attachment_id = $this->make_attachment( $this->make_hash() );
		$old_hash      = $this->make_hash();

		$this->assertTrue( $this->aliases->register( $attachment_id, $old_hash ) );
		$this->assertTrue( $this->aliases->register( $attachment_id, $old_hash ) );

		$rows = get_post_meta( $attachment_id, self::ALIAS_META );
		$this->assertCount( 1, array_keys( $rows, $old_hash, true ) );
	}

	/**
	 * A hash already registered as an alias of a different attachment is
	 * refused (ambiguity guard).
	 */
	public function test_register_refuses_alias_owned_by_other_attachment() {
		$owner_id = $this->make_attachment( $this->make_hash() );
		$other_id = $this->make_attachment( $this->make_hash() );
		$old_hash = $this->make_hash();

		$this->assertTrue( $this->aliases->register( $owner_id, $old_hash ) );
		$this->assertFalse( $this->aliases->register( $other_id, $old_hash ) );
		$this->assertSame( array(), get_post_meta( $other_id, self::ALIAS_META ) );
	}

	/**
	 * Malformed hashes are rejected.
	 */
	public function test_register_rejects_invalid_hash() {
		$attachment_id = $this->make_attachment( $this->make_hash() );

		$this->assertFalse( $this->aliases->register( $attachment_id, 'not-a-hash' ) );
		$this->assertFalse( $this->aliases->register( $attachment_id, 'abc123' ) );
		$this->assertFalse( $this->aliases->register( $attachment_id, str_repeat( 'g', 40 ) ) );
		$this->assertFalse( $this->aliases->register( $attachment_id, '' ) );
		$this->assertSame( array(), get_post_meta( $attachment_id, self::ALIAS_META ) );
	}

	/**
	 * Invalid attachments (missing post, wrong post type) are rejected.
	 */
	public function test_register_rejects_invalid_attachment() {
		$this->assertFalse( $this->aliases->register( 0, $this->make_hash() ) );
		$this->assertFalse( $this->aliases->register( 999999, $this->make_hash() ) );

		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$this->assertFalse( $this->aliases->register( $post_id, $this->make_hash() ) );
	}

	/**
	 * The attachment's own current hash cannot become its alias
	 * (self-reference / loop guard).
	 */
	public function test_register_rejects_self_alias() {
		$current       = $this->make_hash();
		$attachment_id = $this->make_attachment( $current );

		$this->assertFalse( $this->aliases->register( $attachment_id, $current ) );
		$this->assertSame( array(), get_post_meta( $attachment_id, self::ALIAS_META ) );
	}

	/**
	 * A hash that is the CURRENT extraction of another attachment cannot be
	 * registered as an alias (legacy shared hash protection).
	 */
	public function test_register_rejects_hash_current_for_other_attachment() {
		$shared_hash = $this->make_hash();
		$this->make_attachment( $shared_hash );
		$editing_id = $this->make_attachment( $this->make_hash() );

		$this->assertFalse( $this->aliases->register( $editing_id, $shared_hash ) );
	}

	/**
	 * Unknown hashes do not resolve.
	 */
	public function test_resolve_unknown_hash_returns_zero() {
		$this->assertSame( 0, $this->aliases->resolve( $this->make_hash() ) );
	}

	/**
	 * Malformed hashes do not resolve.
	 */
	public function test_resolve_invalid_hash_returns_zero() {
		$this->assertSame( 0, $this->aliases->resolve( 'not-a-hash' ) );
		$this->assertSame( 0, $this->aliases->resolve( '' ) );
	}

	/**
	 * A hash that is the current extraction of any attachment never resolves
	 * as an alias, even when stale alias meta exists for it (current content
	 * always takes precedence over alias data).
	 */
	public function test_resolve_refuses_hash_current_for_an_attachment() {
		$shared_hash = $this->make_hash();
		$this->make_attachment( $shared_hash );

		$alias_owner = $this->make_attachment( $this->make_hash() );
		// Inject stale alias data directly, bypassing register() guards.
		add_post_meta( $alias_owner, self::ALIAS_META, $shared_hash );

		$this->assertSame( 0, $this->aliases->resolve( $shared_hash ) );
	}

	/**
	 * A hash registered (via direct meta manipulation) on two attachments is
	 * ambiguous and never resolves.
	 */
	public function test_resolve_refuses_ambiguous_alias() {
		$hash = $this->make_hash();
		$a    = $this->make_attachment( $this->make_hash() );
		$b    = $this->make_attachment( $this->make_hash() );
		add_post_meta( $a, self::ALIAS_META, $hash );
		add_post_meta( $b, self::ALIAS_META, $hash );

		$this->assertSame( 0, $this->aliases->resolve( $hash ) );
	}

	/**
	 * Alias meta injected on a non-attachment post never resolves.
	 */
	public function test_resolve_ignores_non_attachment_posts() {
		$hash    = $this->make_hash();
		$post_id = $this->factory->post->create( array( 'post_type' => 'page' ) );
		add_post_meta( $post_id, self::ALIAS_META, $hash );

		$this->assertSame( 0, $this->aliases->resolve( $hash ) );
	}

	/**
	 * Permanently deleting the attachment removes its aliases: resolution
	 * stops and no meta rows survive.
	 */
	public function test_aliases_removed_on_attachment_deletion() {
		$attachment_id = $this->make_attachment( $this->make_hash() );
		$old_hash      = $this->make_hash();
		$this->assertTrue( $this->aliases->register( $attachment_id, $old_hash ) );

		wp_delete_attachment( $attachment_id, true );

		$this->assertSame( 0, $this->aliases->resolve( $old_hash ) );
		$this->assertSame( array(), get_post_meta( $attachment_id, self::ALIAS_META ) );
	}

	/**
	 * The repository stores aliases as post meta only: no autoloaded global
	 * option map is created.
	 */
	public function test_no_autoloaded_global_map() {
		$attachment_id = $this->make_attachment( $this->make_hash() );
		$old_hash      = $this->make_hash();
		$this->assertTrue( $this->aliases->register( $attachment_id, $old_hash ) );

		wp_cache_delete( 'alloptions', 'options' );
		$autoloaded = wp_load_alloptions();

		$this->assertStringNotContainsString( $old_hash, wp_json_encode( $autoloaded ) );
	}

	/**
	 * is_current_hash() reports whether any attachment currently uses a hash.
	 */
	public function test_is_current_hash() {
		$current = $this->make_hash();
		$this->make_attachment( $current );

		$this->assertTrue( $this->aliases->is_current_hash( $current ) );
		$this->assertFalse( $this->aliases->is_current_hash( $this->make_hash() ) );
		$this->assertFalse( $this->aliases->is_current_hash( 'not-a-hash' ) );
	}
}
