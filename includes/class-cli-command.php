<?php
/**
 * WP-CLI commands for the eXeLearning plugin.
 *
 * @package Exelearning
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class ExeLearning_CLI_Command.
 *
 * Provides `wp exelearning <command>` for batch maintenance on large sites.
 * Registered only when WP-CLI is loaded (see exelearning.php).
 */
class ExeLearning_CLI_Command {

	/**
	 * (Re)process existing .elpx attachments so they become previewable.
	 *
	 * Useful when the plugin is installed after .elpx files were already
	 * uploaded, or when files were stored through a flow (e.g. Formidable Forms)
	 * that bypassed the upload handler.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Reprocess a single attachment by ID.
	 *
	 * [--all]
	 * : Reprocess every .elpx attachment that still needs it (no extraction yet,
	 *   or a missing extraction directory).
	 *
	 * [--force]
	 * : With --all, reprocess every .elpx attachment even if it already has a
	 *   valid extraction.
	 *
	 * ## EXAMPLES
	 *
	 *     wp exelearning reprocess --id=123
	 *     wp exelearning reprocess --all
	 *     wp exelearning reprocess --all --force
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function reprocess( $args, $assoc_args ) {
		unset( $args );

		$reprocessor = new ExeLearning_Reprocessor();

		$id    = isset( $assoc_args['id'] ) ? absint( $assoc_args['id'] ) : 0;
		$all   = isset( $assoc_args['all'] );
		$force = isset( $assoc_args['force'] );

		if ( ! $id && ! $all ) {
			WP_CLI::error( 'Specify --id=<id> or --all.' );
		}

		if ( $id ) {
			$ids = array( $id );
		} elseif ( $force ) {
			$ids = $reprocessor->get_elpx_attachment_ids();
		} else {
			$ids = $reprocessor->get_reprocessable_attachment_ids();
		}

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No eXeLearning files needed reprocessing.' );
			return;
		}

		$ok      = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( $ids as $one ) {
			if ( ! $reprocessor->is_elpx_attachment( $one ) ) {
				WP_CLI::warning( sprintf( 'Skipped #%d: not an eXeLearning (.elpx) attachment.', $one ) );
				++$skipped;
				continue;
			}

			$result = $reprocessor->reprocess( $one );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'Failed #%d: %s', $one, $result->get_error_message() ) );
				++$failed;
				continue;
			}

			WP_CLI::log(
				sprintf(
					'Reprocessed #%d (%s).',
					$one,
					$result['has_preview'] ? 'previewable' : 'no preview'
				)
			);
			++$ok;
		}

		WP_CLI::success( sprintf( '%d reprocessed, %d failed, %d skipped.', $ok, $failed, $skipped ) );
	}
}
