<?php
/**
 * Obsolete extraction hash aliases for eXeLearning content.
 *
 * Saving an edited .elpx generates a new extraction hash and removes the
 * previous extraction directory, which would turn every published content
 * URL for the old hash into a dead link. This repository persists each
 * retired hash as attachment post meta so the content proxy can redirect
 * stale URLs to the attachment's current extraction (SDD-0001).
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_Content_Hash_Aliases.
 *
 * Persists the relationship between retired extraction hashes and the
 * attachment that retired them, and resolves stale content-proxy requests
 * back to that attachment. Aliases are stored as multi-value attachment post
 * meta, so they are removed automatically when the attachment is permanently
 * deleted, and they resolve through the attachment identity to its single
 * current hash — redirect chains are impossible by construction.
 */
class ExeLearning_Content_Hash_Aliases {

	/**
	 * Post meta key storing retired extraction hashes (multi-value).
	 *
	 * @var string
	 */
	const META_KEY = '_exelearning_obsolete_hash';

	/**
	 * Post meta key storing the current extraction hash.
	 *
	 * @var string
	 */
	const CURRENT_META_KEY = '_exelearning_extracted';

	/**
	 * Whether a value is a well-formed extraction hash.
	 *
	 * @param mixed $hash Candidate hash.
	 * @return bool True for a 40-character hexadecimal string.
	 */
	public static function is_valid_hash( $hash ) {
		return is_string( $hash ) && 1 === preg_match( '/^[a-f0-9]{40}$/i', $hash );
	}

	/**
	 * Register an obsolete hash as an alias of an attachment.
	 *
	 * Refuses malformed hashes, missing or non-attachment posts, hashes that
	 * are still the current extraction of any attachment (self-aliases and
	 * legacy shared hashes), and hashes already owned by a different
	 * attachment (ambiguity guard). Re-registering an alias the attachment
	 * already owns is an idempotent success. The stored row is read back
	 * before success is reported, so callers may safely delete the old
	 * extraction directory only after a true return.
	 *
	 * @param int    $attachment_id Attachment retiring the hash.
	 * @param string $old_hash      Retired extraction hash.
	 * @return bool True when the alias is persisted (or already present).
	 */
	public function register( $attachment_id, $old_hash ) {
		$attachment_id = (int) $attachment_id;
		if ( ! self::is_valid_hash( $old_hash ) ) {
			return false;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}

		// Never alias a hash that still serves live content for any
		// attachment: neither this one (self-reference) nor another one
		// (legacy shared extraction that must keep working).
		if ( $this->is_current_hash( $old_hash ) ) {
			return false;
		}

		$existing = get_post_meta( $attachment_id, self::META_KEY, false );
		if ( in_array( $old_hash, (array) $existing, true ) ) {
			return true;
		}

		// A hash already owned by a different attachment would become
		// ambiguous; the resolver refuses those, so never create them.
		if ( array() !== $this->find_attachments_by_meta( self::META_KEY, $old_hash ) ) {
			return false;
		}

		add_post_meta( $attachment_id, self::META_KEY, $old_hash );

		// Verify persistence: the caller deletes content based on this.
		return in_array( $old_hash, (array) get_post_meta( $attachment_id, self::META_KEY, false ), true );
	}

	/**
	 * Resolve an obsolete hash to the attachment that retired it.
	 *
	 * Returns 0 for malformed hashes, hashes that are the current extraction
	 * of any attachment (current content always wins over alias data),
	 * unknown hashes, and hashes registered on more than one attachment
	 * (ambiguous — never silently pick one).
	 *
	 * @param string $hash Requested extraction hash.
	 * @return int Owning attachment ID, or 0 when the hash must not redirect.
	 */
	public function resolve( $hash ) {
		if ( ! self::is_valid_hash( $hash ) ) {
			return 0;
		}

		if ( $this->is_current_hash( $hash ) ) {
			return 0;
		}

		$owners = $this->find_attachments_by_meta( self::META_KEY, $hash );
		if ( 1 !== count( $owners ) ) {
			return 0;
		}

		return (int) $owners[0];
	}

	/**
	 * Whether a hash is the current extraction of any attachment.
	 *
	 * @param string $hash Extraction hash.
	 * @return bool
	 */
	public function is_current_hash( $hash ) {
		if ( ! self::is_valid_hash( $hash ) ) {
			return false;
		}

		return array() !== $this->find_attachments_by_meta( self::CURRENT_META_KEY, $hash );
	}

	/**
	 * Find attachments holding a hash under a meta key.
	 *
	 * Bounded lookup (two rows are enough to detect ambiguity) that runs only
	 * on the save path and on the proxy's file-not-found fallback — never on
	 * successful content requests.
	 *
	 * @param string $meta_key Meta key to match.
	 * @param string $hash     Hash value to match.
	 * @return int[] Attachment IDs.
	 */
	private function find_attachments_by_meta( $meta_key, $hash ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 2,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded lookup used only on saves and on already-404 proxy requests.
					array(
						'key'   => $meta_key,
						'value' => $hash,
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
