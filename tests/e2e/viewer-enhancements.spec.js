/**
 * E2E coverage for responsive shortcode height and editor fullscreen behavior.
 *
 * @package Exelearning
 */

const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

let fixtures;

/**
 * Authenticate as the default wp-env administrator.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function loginAsAdmin( page ) {
    await page.goto( '/wp-login.php' );
    await page.fill( '#user_login', 'admin' );
    await page.fill( '#user_pass', 'password' );
    await page.click( '#wp-submit' );
    await page.waitForURL( '**/wp-admin/**' );
}

test.beforeAll( function() {
    execSync(
        'npx wp-env run tests-cli bash -c '
            + '"wp theme activate twentytwentyone && wp plugin activate exelearning"',
        { encoding: 'utf8' }
    );

    const raw = execSync(
        'npx wp-env run tests-cli --env-cwd=wp-content/plugins/exelearning '
            + 'wp eval-file tests/e2e/seed-shortcode-fixtures.php',
        { encoding: 'utf8' }
    );
    const marker = 'EXE_E2E_JSON:';
    const index = raw.indexOf( marker );

    if ( -1 === index ) {
        throw new Error( `Seed script did not emit a manifest. Output:\n${ raw }` );
    }

    fixtures = JSON.parse( raw.slice( index + marker.length ).split( '\n' )[ 0 ].trim() );
} );

test( 'percentage height tracks the rendered embed width', async function( { page } ) {
    await page.setViewportSize( { width: 1200, height: 900 } );
    await page.goto( `/?page_id=${ fixtures.pages.height }` );

    const iframe = page.locator( 'iframe.exelearning-iframe' );
    await expect( iframe ).toBeVisible();

    async function expectRatio() {
        await expect.poll( async function() {
            const box = await iframe.boundingBox();
            return box ? Math.abs( box.height - ( box.width * 0.75 ) ) : Number.MAX_VALUE;
        } ).toBeLessThanOrEqual( 2 );
    }

    await expectRatio();
    await page.setViewportSize( { width: 700, height: 900 } );
    await expectRatio();
} );

// The two tests below exercise elp-upload-fullscreen.js in isolation against a
// hand-built DOM. They verify the script's logic (enable/disable, fullscreen
// request) without loading Gutenberg; the real editor integration is covered by
// the "block editor" test at the end of this file.
test( 'fullscreen script (unit): requests fullscreen on its preview iframe', async function( { page } ) {
    await page.setContent(
        '<div data-type="exelearning/elp-upload">'
            + '<button type="button" class="exelearning-fullscreen-btn">Fullscreen</button>'
            + '<div class="exelearning-block-preview"><iframe></iframe></div>'
            + '</div>'
    );

    await page.locator( 'iframe' ).evaluate( function( iframe ) {
        iframe.requestFullscreen = function() {
            iframe.dataset.fullscreenRequested = '1';
            return Promise.resolve();
        };
    } );

    await page.addScriptTag( {
        path: path.resolve( __dirname, '../../assets/js/elp-upload-fullscreen.js' ),
    } );
    await page.locator( '.exelearning-fullscreen-btn' ).click();

    await expect( page.locator( 'iframe' ) ).toHaveAttribute( 'data-fullscreen-requested', '1' );
} );

test( 'fullscreen script (unit): disables the button without a preview iframe', async function( { page } ) {
    await page.setContent(
        '<div data-type="exelearning/elp-upload">'
            + '<button type="button" class="exelearning-fullscreen-btn">Fullscreen</button>'
            + '<div class="exelearning-block-preview"></div>'
            + '</div>'
    );

    await page.addScriptTag( {
        path: path.resolve( __dirname, '../../assets/js/elp-upload-fullscreen.js' ),
    } );

    await expect( page.locator( '.exelearning-fullscreen-btn' ) ).toBeDisabled();
    await expect( page.locator( '.exelearning-fullscreen-btn' ) )
        .toHaveAttribute( 'aria-disabled', 'true' );
} );

test( 'block editor: toggling fullscreen adds a button that targets the real preview iframe', async function( { page } ) {
    await loginAsAdmin( page );
    await page.goto( `/wp-admin/post.php?post=${ fixtures.pages.blockedit }&action=edit` );

    // Wait for the editor to hydrate and parse our seeded block.
    await page.waitForFunction( function() {
        return window.wp && window.wp.data
            && window.wp.data.select( 'core/block-editor' )
            && window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0;
    } );

    // Drive selection and chrome through the data layer so the test does not
    // depend on translated UI, then dismiss the one-time welcome guide.
    await page.evaluate( function() {
        try {
            window.wp.data.dispatch( 'core/preferences' )
                .set( 'core/edit-post', 'welcomeGuide', false );
        } catch ( e ) {}

        var blocks = window.wp.data.select( 'core/block-editor' ).getBlocks();
        var block = blocks.find( function( b ) {
            return b.name === 'exelearning/elp-upload';
        } );
        window.wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId );

        try {
            window.wp.data.dispatch( 'core/edit-post' )
                .openGeneralSidebar( 'edit-post/block' );
        } catch ( e ) {}
    } );

    // The block's edit component renders the real, proxied preview iframe. Its
    // presence in the main document (not an iframed canvas) is what lets the
    // editor script find and sync the button.
    const preview = page.locator(
        '[data-type="exelearning/elp-upload"] .exelearning-block-preview iframe'
    );
    await expect( preview ).toHaveCount( 1 );

    // Fixture ships with fullscreen off, so there is no button yet.
    await expect( page.locator( '.exelearning-fullscreen-btn' ) ).toHaveCount( 0 );

    // Flip "Show fullscreen button" through the real inspector control.
    await page.locator( '.exelearning-fullscreen-toggle input[type="checkbox"]' ).click();

    // elp-upload-fullscreen.js (enqueue_block_editor_assets) must observe the
    // new button and enable it against the real preview iframe.
    const button = page.locator(
        '[data-type="exelearning/elp-upload"] .exelearning-fullscreen-btn'
    );
    await expect( button ).toHaveCount( 1 );
    await expect( button ).toBeEnabled();
    await expect( button ).toHaveAttribute( 'aria-disabled', 'false' );

    // Clicking must request fullscreen on the real preview iframe.
    await preview.evaluate( function( iframe ) {
        iframe.requestFullscreen = function() {
            iframe.dataset.fullscreenRequested = '1';
            return Promise.resolve();
        };
    } );
    await button.click();
    await expect( preview ).toHaveAttribute( 'data-fullscreen-requested', '1' );
} );

// Proves the JavaScript translation pipeline end to end: a string authored in
// assets/js/elp-upload.js is served through wp_set_script_translations() and the
// generated exelearning-es_ES-<md5>.json, and shown translated in the real block
// editor. The environment runs in es_ES (see .wp-env.json / setup-tests-env).
test( 'block editor: elp-upload.js inspector strings render in Spanish (es_ES)', async function( { page } ) {
    await loginAsAdmin( page );
    await page.goto( `/wp-admin/post.php?post=${ fixtures.pages.blockedit }&action=edit` );

    await page.waitForFunction( function() {
        return window.wp && window.wp.data
            && window.wp.data.select( 'core/block-editor' )
            && window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0;
    } );

    // Guard: the editor must actually be running in Spanish, otherwise the
    // assertion below would be meaningless.
    const editorLocale = await page.evaluate( function() {
        return window.wp && window.wp.i18n
            ? window.wp.i18n.getLocaleData( 'exelearning' )[ '' ].lang
            : null;
    } );
    expect( editorLocale ).toBe( 'es_ES' );

    // Select the block and open its inspector through the data layer.
    await page.evaluate( function() {
        try {
            window.wp.data.dispatch( 'core/preferences' )
                .set( 'core/edit-post', 'welcomeGuide', false );
        } catch ( e ) {}

        var blocks = window.wp.data.select( 'core/block-editor' ).getBlocks();
        var block = blocks.find( function( b ) {
            return b.name === 'exelearning/elp-upload';
        } );
        window.wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId );

        try {
            window.wp.data.dispatch( 'core/edit-post' )
                .openGeneralSidebar( 'edit-post/block' );
        } catch ( e ) {}
    } );

    // "Show fullscreen button" (assets/js/elp-upload.js) must be translated.
    const toggle = page.locator( '.exelearning-fullscreen-toggle' );
    await expect( toggle ).toContainText( 'Mostrar el botón de pantalla completa' );
    await expect( toggle ).not.toContainText( 'Show fullscreen button' );
} );
