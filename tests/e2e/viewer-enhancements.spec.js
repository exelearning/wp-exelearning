/**
 * E2E coverage for responsive shortcode height and editor fullscreen behavior.
 *
 * @package Exelearning
 */

const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

let fixtures;

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

test( 'editor fullscreen button requests fullscreen on its preview iframe', async function( { page } ) {
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

test( 'editor fullscreen button is disabled without a preview iframe', async function( { page } ) {
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
