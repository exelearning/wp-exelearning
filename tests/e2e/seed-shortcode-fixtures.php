<?php
/**
 * Seed fixtures for the shortcode-viewer E2E spec.
 *
 * Creates one previewable attachment — backed by a real extracted index.html
 * that the content proxy serves — plus one published page per shortcode
 * scenario, then prints a JSON manifest of the created IDs for Playwright to
 * consume. It is idempotent: re-running reuses the same attachment and pages.
 *
 * Run inside the tests environment:
 *   wp eval-file tests/e2e/seed-shortcode-fixtures.php
 *
 * @package Exelearning
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// Deterministic 40-hex extraction hash (matches the content-proxy contract).
$hash = str_repeat( 'e', 40 );

/*
 * 1. Write a real extracted package so the proxied iframe loads a valid page
 *    (a 404 there would be a genuine plugin-generated failure). The document is
 *    fully self-contained: no external requests, so the console stays clean.
 */
$upload  = wp_upload_dir();
$exe_dir = trailingslashit( $upload['basedir'] ) . 'exelearning/' . $hash;
wp_mkdir_p( $exe_dir );
file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$exe_dir . '/index.html',
	"<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
		. "<title>eXeLearning E2E preview</title>\n</head>\n<body>\n"
		. "<main><h1>eXeLearning E2E preview</h1>"
		. "<p>Seeded interactive content.</p></main>\n</body>\n</html>\n"
);

// 2. Find or create the fixture attachment.
$existing = get_posts(
	array(
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'meta_key'    => '_exe_e2e_fixture', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);

if ( ! empty( $existing ) ) {
	$attachment_id = (int) $existing[0];
} else {
	$src  = dirname( __DIR__ ) . '/fixtures/test-content.elpx';
	$dest = trailingslashit( $upload['path'] ) . 'exe-e2e-fixture.elpx';
	if ( file_exists( $src ) ) {
		copy( $src, $dest );
	} else {
		file_put_contents( $dest, 'PK' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'application/zip',
			'post_title'     => 'eXe E2E Fixture',
			'post_status'    => 'inherit',
		),
		$dest
	);
	update_post_meta( $attachment_id, '_exe_e2e_fixture', '1' );
}

update_post_meta( $attachment_id, '_exelearning_extracted', $hash );
update_post_meta( $attachment_id, '_exelearning_has_preview', '1' );

/*
 * 3. Create/refresh one page per scenario. sprintf: %1$d = attachment ID, %%
 *    escapes the literal percent in the height value.
 */
$scenarios = array(
	'default'    => '[exelearning id="%1$d"]',
	'nofs'       => '[exelearning id="%1$d" fullscreen="0"]',
	'fs'         => '[exelearning id="%1$d" fullscreen="1"]',
	'height'     => '[exelearning id="%1$d" height="75%%"]',
	'downloadfs' => '[exelearning id="%1$d" show_download="1" fullscreen="1"]',
);

$pages = array();
foreach ( $scenarios as $key => $template ) {
	$slug    = 'exe-e2e-' . $key;
	$content = sprintf( $template, $attachment_id );
	$page    = get_page_by_path( $slug );

	if ( $page ) {
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
		$pages[ $key ] = (int) $page->ID;
	} else {
		$pages[ $key ] = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'eXe E2E ' . $key,
				'post_name'    => $slug,
				'post_content' => $content,
			)
		);
	}
}

// Gutenberg block scenario (fullscreen on) to exercise the block frontend path.
$block_slug    = 'exe-e2e-block';
$block_content = sprintf(
	'<!-- wp:exelearning/elp-upload {"attachmentId":%d,"fullscreen":true} /-->',
	$attachment_id
);
$block_page    = get_page_by_path( $block_slug );
if ( $block_page ) {
	wp_update_post(
		array(
			'ID'           => $block_page->ID,
			'post_content' => $block_content,
		)
	);
	$pages['block'] = (int) $block_page->ID;
} else {
	$pages['block'] = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'eXe E2E block',
			'post_name'    => $block_slug,
			'post_content' => $block_content,
		)
	);
}

echo 'EXE_E2E_JSON:' . wp_json_encode(
	array(
		'attachmentId' => (int) $attachment_id,
		'hash'         => $hash,
		'pages'        => $pages,
	)
) . "\n";
