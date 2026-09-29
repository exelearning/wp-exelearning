<?php
/**
 * E2E seed helper: create a throwaway .elpx attachment owned by the running
 * user and print its id (`attachment_id=<id>`), so a Playwright test can look up
 * the editor URL from the browser. The `exelearning_editor` nonce is NOT minted
 * here on purpose — WordPress binds nonces to the browser session token, so a
 * wp-cli nonce would never validate in the browser. The test reads a
 * session-valid edit URL via `wp.media` instead.
 *
 * Run inside wp-env against the TESTS CLI container, as the admin user:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning \
 *     wp eval-file tests/e2e/helpers/seed-elpx-attachment.php --user=admin
 *
 * @package Exelearning
 */

$exe_e2e_upload = wp_upload_dir();
$exe_e2e_path   = trailingslashit( $exe_e2e_upload['basedir'] ) . 'e2e-preview-' . time() . '.elpx';

// The editor page only checks the .elpx extension; the bytes are never parsed.
file_put_contents( $exe_e2e_path, "PK\x03\x04" ); // phpcs:ignore

$exe_e2e_attachment_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'application/zip',
		'post_title'     => 'e2e-preview',
		'post_status'    => 'inherit',
	),
	$exe_e2e_path
);

update_attached_file( $exe_e2e_attachment_id, $exe_e2e_path );

echo 'attachment_id=' . (int) $exe_e2e_attachment_id;
