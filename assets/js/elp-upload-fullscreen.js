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

    /**
     * Re-sync only the fullscreen buttons contained in a single block.
     *
     * @param {Element} block eXeLearning block element.
     */
    function syncBlock( block ) {
        block.querySelectorAll( '.exelearning-fullscreen-btn' ).forEach( syncButtonState );
    }

    /**
     * Collect the eXeLearning blocks touched by a batch of mutations.
     *
     * Gutenberg emits a high volume of mutations, so instead of rescanning the
     * whole document we look only at each mutation's target and added subtrees.
     * Inspecting the target keeps a button in sync when its preview iframe
     * appears or is removed under an already-present block.
     *
     * @param {MutationRecord[]} mutations Observed mutations.
     * @return {Set<Element>} Affected block elements.
     */
    function affectedBlocks( mutations ) {
        var blocks = new Set();

        function addEnclosing( node ) {
            if ( node instanceof Element ) {
                var block = node.closest( '[data-type="exelearning/elp-upload"]' );
                if ( block ) {
                    blocks.add( block );
                }
            }
        }

        function addSubtree( node ) {
            if ( ! ( node instanceof Element ) ) {
                return;
            }

            addEnclosing( node );
            node.querySelectorAll( '[data-type="exelearning/elp-upload"]' )
                .forEach( function( inner ) {
                    blocks.add( inner );
                } );
        }

        mutations.forEach( function( mutation ) {
            addEnclosing( mutation.target );
            mutation.addedNodes.forEach( addSubtree );
        } );

        return blocks;
    }

    function initialize() {
        syncButtons( document );

        var observer = new MutationObserver( function( mutations ) {
            affectedBlocks( mutations ).forEach( syncBlock );
        } );
        observer.observe( document.body, { childList: true, subtree: true } );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', initialize );
    } else {
        initialize();
    }
} )();
