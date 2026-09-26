/* eXeLearning Media Modal - Updated 2026-06-02 (process-as-eXeLearning button) */
jQuery( document ).ready( function( $ ) {

    // Localized strings from PHP (via wp_localize_script)
    var strings = window.exelearningMediaStrings || {};

    // REST settings for the one-click "Process as eXeLearning" action.
    var settings = window.exelearningMediaSettings || {};

    // Cache buster to avoid stale iframe content
    var cacheBuster = Date.now();

    // Escape a value for safe insertion into HTML. Attachment titles/filenames
    // and .elpx metadata are attacker-controlled (a low-privileged uploader can
    // set them), and below they are concatenated into markup injected via
    // jQuery .html()/.after(); without escaping that is a stored XSS sink that
    // runs in the viewing admin's session.
    function esc( value ) {
        return $( '<div>' ).text( null === value || undefined === value ? '' : String( value ) ).html();
    }

    // Fullscreen a content preview in a full-viewport overlay with a fresh,
    // interactive iframe, so external videos render via the host relay. Used by the
    // meta box + modal fullscreen buttons; the raw content URL is never opened
    // top-level. Esc or the close button dismisses it.
    function openFullscreenOverlay( src ) {
        if ( ! src ) {
            return;
        }
        var sandbox = ( settings && settings.sandbox ) || 'allow-scripts allow-popups allow-forms';
        var $overlay = $( '<div class="exelearning-fs-overlay" style="position:fixed;inset:0;z-index:2147483647;background:#1a1a1a;display:flex;flex-direction:column;"></div>' );
        var $bar = $( '<div style="flex:none;display:flex;justify-content:flex-end;padding:6px;background:#111;"></div>' );
        var $close = $( '<button type="button" class="button" aria-label="' + ( strings.close || 'Close' ) + '" style="font-size:16px;line-height:1;">✕</button>' );
        var $iframe = $( '<iframe referrerpolicy="no-referrer" style="flex:1;width:100%;border:0;background:#fff;"></iframe>' );
        $iframe.attr( 'sandbox', sandbox );
        $iframe.attr( 'src', src );
        $bar.append( $close );
        $overlay.append( $bar ).append( $iframe );
        function remove() {
            $( document ).off( 'keydown.exeFs' );
            $overlay.remove();
        }
        $close.on( 'click', remove );
        $( document ).on( 'keydown.exeFs', function( e ) {
            if ( 'Escape' === e.key ) {
                remove();
            }
        } );
        $( 'body' ).append( $overlay );
    }

    // Delegated click handler for every fullscreen button (meta box + modal).
    $( document ).on( 'click', '.exelearning-fullscreen-btn[data-fullscreen-src]', function( e ) {
        e.preventDefault();
        openFullscreenOverlay( $( this ).attr( 'data-fullscreen-src' ) );
    } );

    // Function to replace thumbnail with a preview iframe
    function replaceElpThumbnail() {
        $( '.attachment-preview.type-application' ).each( function() {
            var $preview = $( this );
            var $thumbnail = $preview.find( '.thumbnail' );
            var $attachment = $preview.closest( '.attachment' );

            // Check if already processed
            if ( $thumbnail.hasClass( 'exelearning-preview-added' ) || $thumbnail.hasClass( 'exelearning-no-preview' ) ) {
                return;
            }

            // Find the attachment model
            var attachmentId = $attachment.data( 'id' );
            if ( ! attachmentId ) {
                return;
            }

            var attachment = wp.media.attachment( attachmentId );
            if ( ! attachment || ! attachment.get( 'exelearning' ) ) {
                return;
            }

            var metadata = attachment.get( 'exelearning' );

            // Add class to parent for CSS targeting
            $attachment.addClass( 'exelearning-attachment' );

            // Check if this file has a preview (version 3 files with index.html)
            if ( ! metadata.has_preview || ! metadata.preview_url ) {
                // Mark as processed but show version info instead
                $thumbnail.addClass( 'exelearning-no-preview' );
                var versionText = metadata.version === 2 ? 'v2 (source)' : 'v' + metadata.version;
                $thumbnail.find( '.centered' ).after(
                    '<div class="exelearning-version-badge">' +
                    'eXe ' + esc( versionText ) +
                    '</div>'
                );
                return;
            }

            // Mark as processed
            $thumbnail.addClass( 'exelearning-preview-added' );

            // Get filename for overlay
            var filename = attachment.get( 'filename' ) || attachment.get( 'title' ) || '';

            // Wait for the thumbnail to get its proper size from CSS (4:3 aspect ratio)
            // Then calculate scale based on actual container size
            setTimeout( function() {
                var containerWidth = $thumbnail.width() || 200;
                var containerHeight = $thumbnail.height() || 150;

                // Iframe renders at full size, then scaled down
                var iframeW = 1200;
                var iframeH = 900;
                var scale = Math.min( containerWidth / iframeW, containerHeight / iframeH );

                // Create wrapper div with iframe and filename overlay
                // Add cache buster to prevent stale content
                var iframeSrc = metadata.preview_url + ( metadata.preview_url.indexOf( '?' ) > -1 ? '&' : '?' ) + '_cb=' + cacheBuster;

                $thumbnail.html(
                    '<div class="exelearning-preview-wrapper">' +
                        '<iframe src="' + iframeSrc + '" ' +
                        'style="' +
                            'width: ' + iframeW + 'px; ' +
                            'height: ' + iframeH + 'px; ' +
                            'transform: scale(' + scale + '); ' +
                            'transform-origin: 0 0;" ' +
                        'scrolling="no" ' +
                        'sandbox="allow-scripts allow-same-origin" ' +
                        'referrerpolicy="no-referrer"></iframe>' +
                    '</div>' +
                    '<div class="exelearning-filename-overlay">' + esc( filename ) + '</div>'
                );
            }, 50 );
        });
    }

    // Function to add preview in the attachment details panel
    function addElpPreviewToDetails() {
        var $detailsThumbnail = $( '.attachment-details .thumbnail' );

        if ( $detailsThumbnail.length === 0 ) {
            return;
        }

        // Check if already processed
        if ( $detailsThumbnail.hasClass( 'exelearning-details-preview-added' ) || $detailsThumbnail.hasClass( 'exelearning-details-no-preview' ) ) {
            return;
        }

        // Try multiple ways to get the attachment
        var attachment = null;
        var attachmentId = null;

        // Method 1: Try from selection
        var selection = wp.media.frame && wp.media.frame.state() && wp.media.frame.state().get( 'selection' );
        if ( selection && selection.first() ) {
            attachment = selection.first();
            attachmentId = attachment.get( 'id' );
        }

        // Method 2: Try from URL parameter 'item'
        if ( ! attachmentId ) {
            var urlParams = new URLSearchParams( window.location.search );
            attachmentId = urlParams.get( 'item' );
        }

        // Method 3: Try from data attribute on details wrapper
        if ( ! attachmentId ) {
            var $wrapper = $detailsThumbnail.closest( '.attachment-details' );
            if ( $wrapper.length > 0 && $wrapper.data( 'id' ) ) {
                attachmentId = $wrapper.data( 'id' );
            }
        }

        if ( ! attachmentId ) {
            return;
        }

        // Get attachment from wp.media if not already have it
        if ( ! attachment || ! attachment.get( 'exelearning' ) ) {
            attachment = wp.media.attachment( parseInt( attachmentId, 10 ) );

            // If attachment data not loaded yet, fetch it
            if ( ! attachment.get( 'id' ) ) {
                attachment.fetch().done( function() {
                    // Re-run after fetch completes
                    setTimeout( addElpPreviewToDetails, 100 );
                });
                return;
            }
        }

        if ( ! attachment || ! attachment.get( 'exelearning' ) ) {
            // Unprocessed eXeLearning candidate (e.g. a .zip or a not-yet-processed
            // .elpx): offer a one-click "Process as eXeLearning" button.
            if ( attachment && attachment.get( 'exelearningReprocessable' ) ) {
                addProcessButtonToDetails( attachment, $detailsThumbnail );
            }
            return;
        }

        var metadata = attachment.get( 'exelearning' );

        // The eXeLearning metadata (license, ...) is surfaced through the native
        // attachment fields, so no extra "info" block is rendered here.

        // Check if this file has a preview
        if ( ! metadata.has_preview || ! metadata.preview_url ) {
            // Mark as processed but show info message instead
            $detailsThumbnail.addClass( 'exelearning-details-no-preview' );
            $detailsThumbnail.after(
                '<div class="exelearning-no-preview-notice" style="margin-top: 10px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; font-size: 12px;">' +
                '<strong>' + ( strings.noPreview || 'No preview available' ) + '</strong><br>' +
                ( strings.noPreviewDesc || 'This is an eXeLearning v2 source file (.elp). To view the content, open it in eXeLearning and export it as HTML.' ) +
                '</div>'
            );
            addExeEditAction( attachment, $detailsThumbnail );
            return;
        }

        // Mark as processed
        $detailsThumbnail.addClass( 'exelearning-details-preview-added' );

        var detailsIframeSrc = metadata.preview_url + ( metadata.preview_url.indexOf( '?' ) > -1 ? '&' : '?' ) + '_cb=' + cacheBuster;

        var sandbox = ( settings.sandbox || 'allow-scripts allow-popups allow-forms' );

        if ( $detailsThumbnail.closest( '.media-sidebar' ).length > 0 ) {
            // Media selection sidebar: a compact, zoomed-out thumbnail (fit to
            // width) so almost the whole resource is visible without dominating the
            // picker. Non-interactive (it is only a thumbnail here).
            var boxH = 220;
            var boxW = Math.max( 200, $detailsThumbnail.width() || 340 );
            var srcW = 1200;
            var s = boxW / srcW;
            var srcH = Math.round( boxH / s );
            $detailsThumbnail.css({
                'width': '100%', 'max-width': 'none', 'height': boxH + 'px', 'max-height': boxH + 'px',
                'overflow': 'hidden', 'margin-bottom': '12px', 'border': '1px solid #ddd',
                'border-radius': '4px', 'background': '#fff', 'display': 'block'
            });
            $detailsThumbnail.html(
                '<iframe src="' + detailsIframeSrc + '" scrolling="no" ' +
                    'style="width:' + srcW + 'px;height:' + srcH + 'px;border:0;transform:scale(' + s + ');' +
                    'transform-origin:0 0;pointer-events:none;background:#fff;" ' +
                    'sandbox="' + sandbox + '" referrerpolicy="no-referrer"></iframe>'
            );
        } else {
            // Two-column details modal: large, interactive preview (like WordPress
            // shows an image at full size).
            $detailsThumbnail.css({
                'width': '100%', 'max-width': 'none', 'height': 'auto', 'max-height': 'none',
                'overflow': 'visible', 'margin-bottom': '12px'
            });
            $detailsThumbnail.html(
                '<iframe src="' + detailsIframeSrc + '" ' +
                    'style="width:100%;height:60vh;min-height:360px;border:1px solid #ddd;border-radius:4px;background:#fff;display:block;" ' +
                    'sandbox="' + sandbox + '" referrerpolicy="no-referrer"></iframe>'
            );
        }

        // "Edit in eXeLearning" centered below the preview (like the native
        // "Edit image" button).
        addExeEditAction( attachment, $detailsThumbnail );
    }

    // Add the centered "Edit in eXeLearning" action below $anchor, mirroring the
    // native "Edit image" button. The exelearning-edit-page-button class makes
    // exelearning-editor.js open the in-page editor modal (href is the fallback).
    function addExeEditAction( attachment, $anchor ) {
        if ( ! attachment.get( 'exelearningCanEdit' ) ) {
            return;
        }
        // The panel re-renders after a save, and nothing removes the previous
        // action, so without this the link accumulates one copy per save.
        if ( $anchor.siblings( '.exelearning-edit-action' ).length > 0 ) {
            return;
        }
        var editUrl = attachment.get( 'exelearningEditUrl' );
        var id = esc( attachment.get( 'id' ) );
        var label = ( strings.editInExe || 'Edit in eXeLearning' );

        if ( $anchor.closest( '.media-sidebar' ).length > 0 ) {
            // Media selection sidebar: a plain blue link, like the native
            // "Edit image" link — editing is not the primary task here.
            $anchor.after(
                '<p class="exelearning-edit-action" style="margin:8px 0;">' +
                    '<a href="' + editUrl + '" class="exelearning-edit-page-button" data-attachment-id="' + id + '">' +
                    label + '</a></p>'
            );
        } else {
            // Details modal: a prominent centered button, like "Edit image".
            $anchor.after(
                '<p class="exelearning-edit-action" style="text-align:center;margin:12px 0;">' +
                    '<a href="' + editUrl + '" class="button button-primary button-large exelearning-edit-page-button" data-attachment-id="' + id + '">' +
                    label + '</a></p>'
            );
        }
    }

    // Build a "Process as eXeLearning" button element.
    function makeProcessButton( extraClass, extraStyle ) {
        var $btn = $( '<button type="button"></button>' )
            .addClass( 'button button-primary ' + extraClass )
            .attr( 'style', extraStyle )
            .text( strings.processAsExe || 'Process as eXeLearning' );
        $( '<span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>' ).prependTo( $btn );
        return $btn;
    }

    // Show an inline error under an anchor element (never use blocking dialogs).
    function showProcessError( $anchor, message ) {
        $anchor.siblings( '.exelearning-process-error' ).remove();
        $( '<div class="exelearning-process-error" style="margin-top: 8px; color: #b32d2e; font-size: 12px;"></div>' )
            .text( message )
            .insertAfter( $anchor );
    }

    // Call the REST reprocess endpoint for an attachment, then refresh the modal.
    function reprocessAttachment( attachmentId, $button ) {
        if ( ! settings.restUrl || ! window.fetch ) {
            return;
        }

        var originalHtml = $button.html();
        $button.prop( 'disabled', true ).text( strings.processing || 'Processing…' );

        fetch( settings.restUrl + '/reprocess/' + attachmentId, {
            method: 'POST',
            headers: { 'X-WP-Nonce': settings.nonce || '' },
            credentials: 'same-origin'
        } ).then( function( resp ) {
            return resp.json().then( function( body ) {
                return { ok: resp.ok, body: body };
            } );
        } ).then( function( res ) {
            var failed = ! res.ok || ( res.body && res.body.code && true !== res.body.success );
            if ( failed ) {
                var msg = ( res.body && res.body.message ) ? res.body.message : ( strings.processFailed || 'This file could not be processed as eXeLearning.' );
                $button.prop( 'disabled', false ).html( originalHtml );
                showProcessError( $button, msg );
                return;
            }

            // Success: refresh the attachment so its prepared data now carries the
            // eXeLearning preview/edit info, then re-render the modal.
            var attachment = wp.media.attachment( attachmentId );
            attachment.set( 'exelearningReprocessable', false );
            attachment.fetch().always( function() {
                $( '.exelearning-process-button, .exelearning-process-hint, .exelearning-process-error' ).remove();
                $( '.attachment-details .thumbnail' ).removeClass( 'exelearning-details-preview-added exelearning-details-no-preview' );
                $( '.attachment-preview.type-application .thumbnail' ).removeClass( 'exelearning-preview-added exelearning-no-preview' );
                runAllUpdates();
            } );
        } ).catch( function() {
            $button.prop( 'disabled', false ).html( originalHtml );
            showProcessError( $button, strings.processFailed || 'This file could not be processed as eXeLearning.' );
        } );
    }

    // Add the process button + hint under the single-column details thumbnail.
    function addProcessButtonToDetails( attachment, $container ) {
        if ( $container.siblings( '.exelearning-process-button' ).length > 0 ) {
            return;
        }

        var attachmentId = attachment.get( 'id' );
        var $button      = makeProcessButton( 'exelearning-process-button', 'margin-top: 10px; width: 100%;' );
        var $hint        = $( '<div class="exelearning-process-hint" style="margin-top: 8px; font-size: 12px; color: #646970;"></div>' )
            .text( strings.notProcessed || 'eXeLearning file (not processed yet)' );

        $button.on( 'click', function( e ) {
            e.preventDefault();
            reprocessAttachment( attachmentId, $button );
        } );

        $container.after( $button );
        $button.after( $hint );
    }

    // Run all update functions
    function runAllUpdates() {
        replaceElpThumbnail();
        // addElpPreviewToDetails() renders the large preview + a single centered
        // "Edit in eXeLearning" action below it (addExeEditAction), mirroring the
        // native "Edit image" button — in both the details modal and the selection
        // sidebar. No separate button in the actions area, no fullscreen here.
        addElpPreviewToDetails();
    }

    // Observe DOM changes to detect when attachments are added
    var observer = new MutationObserver( function() {
        runAllUpdates();
    });

    observer.observe( document.body, {
        childList: true,
        subtree: true
    });

    // Also run when the modal opens
    if ( wp.media ) {
        wp.media.view.Modal.prototype.on( 'open', function() {
            setTimeout( runAllUpdates, 100 );
        });
    }

    // Run on page load with multiple delays to catch async-loaded content
    runAllUpdates();
    setTimeout( runAllUpdates, 300 );
    setTimeout( runAllUpdates, 800 );
    setTimeout( runAllUpdates, 1500 );
});
