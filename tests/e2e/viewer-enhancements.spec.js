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

/**
 * The surface the block renders into.
 *
 * The block declares apiVersion 3, so WordPress renders the canvas in an
 * iframe and the block's DOM lives inside it -- the surrounding chrome
 * (inspector sidebar, block toolbar) stays in the outer document. Falls back
 * to the page for a WordPress that renders the canvas inline, so the test
 * describes where things are rather than assuming.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Object} Something exposing .locator() for the canvas.
 */
async function editorCanvas( page ) {
    const canvas = page.locator( 'iframe[name="editor-canvas"]' );
    if ( await canvas.count() ) {
        return page.frameLocator( 'iframe[name="editor-canvas"]' );
    }
    return page;
}

test( 'block editor: toggling fullscreen adds a working button inside the canvas', async function( { page } ) {
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

    const canvas = await editorCanvas( page );

    // The block's edit component renders the real, proxied preview iframe --
    // an iframe inside the canvas iframe.
    const preview = canvas.locator(
        '[data-type="exelearning/elp-upload"] .exelearning-block-preview iframe'
    );
    await expect( preview ).toHaveCount( 1 );

    // Fixture ships with fullscreen off, so there is no button yet.
    await expect( canvas.locator( '.exelearning-fullscreen-btn' ) ).toHaveCount( 0 );

    // Flip "Show fullscreen button" through the real inspector control, which
    // renders into the sidebar in the outer document.
    await page.locator( '.exelearning-fullscreen-toggle input[type="checkbox"]' ).click();

    // The button is wired by the block component itself: no script outside the
    // canvas could see this click.
    const button = canvas.locator(
        '[data-type="exelearning/elp-upload"] .exelearning-fullscreen-btn'
    );
    await expect( button ).toHaveCount( 1 );
    await expect( button ).toBeEnabled();
    await expect( button ).toHaveAttribute( 'aria-disabled', 'false' );

    // The icons come from the dashicons font. Inside the canvas iframe only the
    // block's declared styles and their dependencies arrive, so a missing
    // dependency shows up as blank boxes here while the published page looks
    // fine -- assert the font actually applies rather than that a link exists.
    const iconFont = await canvas
        .locator( '[data-type="exelearning/elp-upload"] .exelearning-fullscreen-btn .dashicons' )
        .evaluate( function( icon ) {
            return window.getComputedStyle( icon ).fontFamily;
        } );
    expect( iconFont.toLowerCase() ).toContain( 'dashicons' );

    // Clicking must request fullscreen on the real preview iframe. The stub
    // goes on the prototype inside the canvas rather than on the element: the
    // editor re-renders the block freely, and a stub pinned to one node
    // disappears with it, which made this assertion flaky.
    await canvas.locator( 'body' ).evaluate( function() {
        window.__exeFullscreenRequests = [];
        window.HTMLIFrameElement.prototype.requestFullscreen = function() {
            // Identify it by where it sits, so the assertion is about the
            // preview and not just "some iframe went fullscreen".
            window.__exeFullscreenRequests.push(
                !! this.closest( '.exelearning-block-preview' )
            );
            return Promise.resolve();
        };
    } );

    await button.click();

    await expect.poll( function() {
        return canvas.locator( 'body' ).evaluate( function() {
            return window.__exeFullscreenRequests || [];
        } );
    } ).toEqual( [ true ] );
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
