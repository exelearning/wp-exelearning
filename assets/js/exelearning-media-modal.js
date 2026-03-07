/* eXeLearning Media Modal - Updated 2026-02-02 22:50 */
jQuery( document ).ready( function( $ ) {

    // Localized strings from PHP (via wp_localize_script)
    var strings = window.exelearningMediaStrings || {};

    // Cache buster to avoid stale iframe content
    var cacheBuster = Date.now();

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
                    'eXe ' + versionText +
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
                    '<div class="exelearning-filename-overlay">' + filename + '</div>'
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
            return;
        }

        var metadata = attachment.get( 'exelearning' );

        // Build metadata HTML
        var metaHtml = '';
        if ( metadata.license || metadata.language || metadata.resource_type || metadata.version ) {
            metaHtml = '<div class="exelearning-metadata" style="margin-top: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px;">';
            metaHtml += '<strong style="display: block; margin-bottom: 5px;">' + ( strings.info || 'eXeLearning Info' ) + '</strong>';
            if ( metadata.version ) {
                metaHtml += '<div><small>' + ( strings.version || 'Version:' ) + ' ' + metadata.version + ( metadata.version === 2 ? ' ' + ( strings.sourceFile || '(source file)' ) : ' ' + ( strings.exported || '(exported)' ) ) + '</small></div>';
            }
            if ( metadata.license ) {
                metaHtml += '<div><small>' + ( strings.license || 'License:' ) + ' ' + metadata.license + '</small></div>';
            }
            if ( metadata.language ) {
                metaHtml += '<div><small>' + ( strings.language || 'Language:' ) + ' ' + metadata.language + '</small></div>';
            }
            if ( metadata.resource_type ) {
                metaHtml += '<div><small>' + ( strings.type || 'Type:' ) + ' ' + metadata.resource_type + '</small></div>';
            }
            metaHtml += '</div>';
        }

        // Check if this file has a preview
        if ( ! metadata.has_preview || ! metadata.preview_url ) {
            // Mark as processed but show info message instead
            $detailsThumbnail.addClass( 'exelearning-details-no-preview' );
            $detailsThumbnail.after(
                '<div class="exelearning-no-preview-notice" style="margin-top: 10px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; font-size: 12px;">' +
                '<strong>' + ( strings.noPreview || 'No preview available' ) + '</strong><br>' +
                ( strings.noPreviewDesc || 'This is an eXeLearning v2 source file (.elp). To view the content, open it in eXeLearning and export it as HTML.' ) +
                '</div>' + metaHtml
            );

            // Still add the edit button for v2 files
            addEditButton( attachment, $detailsThumbnail );
            return;
        }

        // Mark as processed
        $detailsThumbnail.addClass( 'exelearning-details-preview-added' );

        // Replace image with a scaled iframe (zoom out)
        // Container 4:3 aspect ratio for proper preview
        var containerWidth = 320;
        var containerHeight = 240;
        var iframeWidth = 1200;
        var iframeHeight = 900;
        var scale = containerWidth / iframeWidth;

        // Set fixed dimensions on thumbnail container to prevent overflow
        $detailsThumbnail.css({
            'width': containerWidth + 'px',
            'height': containerHeight + 'px',
            'max-width': containerWidth + 'px',
            'max-height': containerHeight + 'px',
            'overflow': 'hidden',
            'margin-bottom': '15px'
        });

        // Add cache buster to prevent stale content
        var detailsIframeSrc = metadata.preview_url + ( metadata.preview_url.indexOf( '?' ) > -1 ? '&' : '?' ) + '_cb=' + cacheBuster;

        $detailsThumbnail.html(
            '<div class="exelearning-preview-container" style="' +
                'width: ' + containerWidth + 'px; ' +
                'height: ' + containerHeight + 'px; ' +
                'overflow: hidden; ' +
                'border: 1px solid #ddd; ' +
                'border-radius: 4px; ' +
                'background: #f5f5f5; ' +
                'position: relative;">' +
                '<iframe src="' + detailsIframeSrc + '" ' +
                    'style="' +
                        'width: ' + iframeWidth + 'px; ' +
                        'height: ' + iframeHeight + 'px; ' +
                        'border: none; ' +
                        'transform: scale(' + scale + '); ' +
                        'transform-origin: 0 0; ' +
                        'pointer-events: none;" ' +
                    'scrolling="no" ' +
                    'sandbox="allow-scripts allow-same-origin" ' +
                    'referrerpolicy="no-referrer"></iframe>' +
            '</div>'
        );

        // Build and insert elements in correct order after thumbnail
        // Order: Preview → Edit button → Preview in new tab → Metadata
        var $insertPoint = $detailsThumbnail;

        // Add "Edit in eXeLearning" button if user can edit
        addEditButton( attachment, $insertPoint );

        // Add "Preview in new tab" link after the edit button
        var $previewLink = $(
            '<div class="exelearning-preview-link" style="margin-top: 10px;">' +
                '<a href="' + metadata.preview_url + '" target="_blank" class="button" style="width: 100%; text-align: center;">' +
                ( strings.previewNewTab || 'Preview in new tab' ) + '</a>' +
            '</div>'
        );
        // Find the edit button in the parent container and insert after it
        var $editBtn = $detailsThumbnail.parent().find( '.exelearning-edit-button' );
        if ( $editBtn.length > 0 ) {
            $editBtn.after( $previewLink );
            $insertPoint = $previewLink;
        } else {
            $insertPoint.after( $previewLink );
            $insertPoint = $previewLink;
        }

        // Add metadata at the end (after buttons)
        if ( metaHtml ) {
            var $meta = $( metaHtml );
            $insertPoint.after( $meta );
        }
    }

    // Function to add "Edit in eXeLearning" button
    function addEditButton( attachment, $container ) {
        // Check if editing is available
        if ( ! attachment.get( 'exelearningCanEdit' ) ) {
            return;
        }

        // Check if button already exists
        if ( $container.siblings( '.exelearning-edit-button' ).length > 0 ) {
            return;
        }

        var editUrl = attachment.get( 'exelearningEditUrl' );
        var attachmentId = attachment.get( 'id' );

        var $editButton = $( '<button type="button" class="button button-primary exelearning-edit-button" style="margin-top: 10px; width: 100%;">' +
            '<span class="dashicons dashicons-edit" style="vertical-align: middle; margin-right: 5px;"></span>' +
            ( strings.editInExe || 'Edit in eXeLearning' ) + '</button>' );

        $editButton.on( 'click', function( e ) {
            e.preventDefault();

            // Use the ExeLearningEditor modal if available
            if ( window.ExeLearningEditor && typeof window.ExeLearningEditor.open === 'function' ) {
                window.ExeLearningEditor.open( attachmentId, editUrl );
            } else {
                // Fallback: open in new window
                window.open( editUrl, '_blank', 'width=1200,height=800' );
            }
        });

        // Insert after the container passed to this function
        $container.after( $editButton );
    }

    // Function to add "Edit in eXeLearning" button to the two-column attachment details view
    function addEditButtonToAttachmentInfo() {
        var $attachmentInfo = $( '.attachment-info' );

        if ( $attachmentInfo.length === 0 ) {
            return;
        }

        // Check if button already exists
        if ( $attachmentInfo.find( '.exelearning-edit-button-actions' ).length > 0 ) {
            return;
        }

        // Get the attachment ID from multiple sources
        var attachmentId = null;

        // Try to get from the attachment details wrapper
        var $wrapper = $attachmentInfo.closest( '.attachment-details' );
        if ( $wrapper.length > 0 && $wrapper.data( 'id' ) ) {
            attachmentId = $wrapper.data( 'id' );
        }

        // Try to get from URL parameter 'item' (grid view selection)
        if ( ! attachmentId ) {
            var urlParams = new URLSearchParams( window.location.search );
            attachmentId = urlParams.get( 'item' );
        }

        // Try to get from URL parameter 'post' (edit attachment page)
        if ( ! attachmentId ) {
            var urlParams = new URLSearchParams( window.location.search );
            attachmentId = urlParams.get( 'post' );
        }

        // Try to get from the "edit more details" link href
        if ( ! attachmentId ) {
            var $editLink = $attachmentInfo.find( 'a[href*="post.php?post="]' );
            if ( $editLink.length > 0 ) {
                var match = $editLink.attr( 'href' ).match( /post=(\d+)/ );
                if ( match ) {
                    attachmentId = match[1];
                }
            }
        }

        // Try to get from the "view attachment" link href
        if ( ! attachmentId ) {
            var $viewLink = $attachmentInfo.find( 'a[href*="attachment_id="]' );
            if ( $viewLink.length > 0 ) {
                var match = $viewLink.attr( 'href' ).match( /attachment_id=(\d+)/ );
                if ( match ) {
                    attachmentId = match[1];
                }
            }
        }

        // Try to get from the media frame selection
        if ( ! attachmentId && wp.media && wp.media.frame ) {
            var state = wp.media.frame.state();
            if ( state ) {
                var selection = state.get( 'selection' );
                if ( selection && selection.first() ) {
                    attachmentId = selection.first().get( 'id' );
                }
            }
        }

        if ( ! attachmentId ) {
            return;
        }

        // Convert to integer
        attachmentId = parseInt( attachmentId, 10 );

        // Fetch attachment data
        var attachment = wp.media.attachment( attachmentId );

        // Wait for the attachment to be fetched if needed
        if ( ! attachment.get( 'id' ) ) {
            attachment.fetch().done( function() {
                insertEditButtonInActions( attachment, $attachmentInfo );
            });
        } else {
            insertEditButtonInActions( attachment, $attachmentInfo );
        }
    }

    // Helper function to insert the edit button into the actions div
    function insertEditButtonInActions( attachment, $attachmentInfo ) {
        // Check if this is an eXeLearning file
        if ( ! attachment.get( 'exelearningCanEdit' ) ) {
            return;
        }

        // Check if button already exists
        if ( $attachmentInfo.find( '.exelearning-edit-button-actions' ).length > 0 ) {
            return;
        }

        var editUrl = attachment.get( 'exelearningEditUrl' );
        var attachmentId = attachment.get( 'id' );

        // Find the actions div
        var $actions = $attachmentInfo.find( '.actions' );
        if ( $actions.length === 0 ) {
            return;
        }

        // Create the edit button - styled prominently
        var $editButton = $(
            '<a href="' + editUrl + '" class="button button-primary exelearning-edit-button-actions" ' +
            'style="display: inline-block; margin-bottom: 10px; padding: 6px 12px; font-size: 13px;">' +
            '<span class="dashicons dashicons-edit" style="vertical-align: text-top; margin-right: 4px; font-size: 16px;"></span>' +
            ( strings.editInExe || 'Edit in eXeLearning' ) + '</a>' +
            '<br>'
        );

        $editButton.on( 'click', function( e ) {
            e.preventDefault();

            // Use the ExeLearningEditor modal if available
            if ( window.ExeLearningEditor && typeof window.ExeLearningEditor.open === 'function' ) {
                window.ExeLearningEditor.open( attachmentId, editUrl );
            } else {
                // Fallback: open in new window
                window.open( editUrl, '_blank', 'width=1200,height=800' );
            }
        });

        // Insert at the beginning of the actions div
        $actions.prepend( $editButton );
    }

    // Run all update functions
    function runAllUpdates() {
        replaceElpThumbnail();
        addElpPreviewToDetails();
        addEditButtonToAttachmentInfo();
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
