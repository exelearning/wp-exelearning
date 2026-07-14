/**
 * Fullscreen behavior for the eXeLearning Gutenberg editor preview.
 *
 * @package Exelearning
 */

( function() {
    'use strict';

    /**
     * Find the preview iframe associated with a fullscreen button.
     *
     * @param {Element} button Fullscreen button.
     * @return {HTMLIFrameElement|null} Preview iframe.
     */
    function findPreviewIframe( button ) {
        var block = button.closest( '[data-type="exelearning/elp-upload"]' );
        return block ? block.querySelector( '.exelearning-block-preview iframe' ) : null;
    }

    /**
     * Enable the button only when its preview iframe exists.
     *
     * @param {HTMLButtonElement} button Fullscreen button.
     */
    function syncButtonState( button ) {
        var iframe = findPreviewIframe( button );
        button.disabled = ! iframe;
        button.setAttribute( 'aria-disabled', iframe ? 'false' : 'true' );
    }

    /**
     * Synchronize every eXeLearning fullscreen button below a root node.
     *
     * @param {Document|Element} root Search root.
     */
    function syncButtons( root ) {
        root.querySelectorAll( '[data-type="exelearning/elp-upload"] .exelearning-fullscreen-btn' )
            .forEach( syncButtonState );
    }

    /**
     * Request fullscreen using the available browser API.
     *
     * @param {HTMLIFrameElement} iframe Preview iframe.
     */
    function requestFullscreen( iframe ) {
        if ( iframe.requestFullscreen ) {
            iframe.requestFullscreen();
        } else if ( iframe.webkitRequestFullscreen ) {
            iframe.webkitRequestFullscreen();
        } else if ( iframe.msRequestFullscreen ) {
            iframe.msRequestFullscreen();
        }
    }

    document.addEventListener( 'click', function( event ) {
        if ( ! ( event.target instanceof Element ) ) {
            return;
        }

        var button = event.target.closest(
            '[data-type="exelearning/elp-upload"] .exelearning-fullscreen-btn'
        );
        if ( ! button ) {
            return;
        }

        var iframe = findPreviewIframe( button );
        if ( ! iframe ) {
            event.preventDefault();
            syncButtonState( button );
            return;
        }

        event.preventDefault();
        requestFullscreen( iframe );
    } );

    function initialize() {
        syncButtons( document );

        var observer = new MutationObserver( function() {
            syncButtons( document );
        } );
        observer.observe( document.body, { childList: true, subtree: true } );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', initialize );
    } else {
        initialize();
    }
} )();
