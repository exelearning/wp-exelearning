// blocks/elp-block.js
( function( wp ) {
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var __ = wp.i18n.__;
    var registerBlockType = wp.blocks.registerBlockType;
    var MediaUpload = wp.blockEditor.MediaUpload;
    var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    var BlockControls = wp.blockEditor.BlockControls;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var Button = wp.components.Button;
    var Placeholder = wp.components.Placeholder;
    var ToolbarGroup = wp.components.ToolbarGroup;
    var ToolbarButton = wp.components.ToolbarButton;
    var ResizableBox = wp.components.ResizableBox;
    var PanelBody = wp.components.PanelBody;
    var RangeControl = wp.components.RangeControl;
    var ToggleControl = wp.components.ToggleControl;
    var useState = wp.element.useState;

    registerBlockType( 'exelearning/elp-upload', {
        title: 'eXeLearning',
        icon: el( 'svg', { width: 24, height: 24, viewBox: '0 0 350 230', xmlns: 'http://www.w3.org/2000/svg' },
            el( 'path', { d: 'M102.249.584c9.46 0 19.566 3.44 30.316 10.32 10.536 6.665 25.049 18.491 43.539 35.476 31.821-26.661 48.795-32.666 65.135-32.666 10.536 0 19.566 2.903 27.091 8.708 13.863 10.601 16.175 34.81 6.463 50.727-7.74 12.686-17.846 26.339-30.316 40.959 28.381 32.251 44.233 55.457 44.233 71.368 0 11.395-3.548 20.103-10.643 26.123-7.31 6.02-16.448 9.03-27.413 9.03-16.556 0-42.514-12.562-74.55-39.438-18.275 16.125-32.681 27.306-43.216 33.541-10.751 6.235-20.964 9.353-30.639 9.353-12.9 0-22.898-4.193-29.994-12.578-7.31-8.601-10.965-18.706-10.965-30.316 0-7.526 1.075-14.083 3.225-19.674 2.15-5.59 6.128-11.825 11.933-18.705 5.805-7.096 15.051-16.878 27.736-29.349-12.255-12.47-21.286-22.468-27.091-29.993-6.02-7.74-10.105-14.513-12.255-20.318-2.365-5.805-3.548-12.255-3.548-19.351 0-7.525 1.613-14.513 4.838-20.963 3.225-6.665 7.955-12.041 14.19-16.126C88.704 2.626 95.864.584 102.249.584z' })
        ),
        category: 'embed',
        keywords: [ 'exe', 'learning', 'elp', 'elpx', 'scorm' ],
        supports: {
            align: [ 'left', 'center', 'right', 'wide', 'full' ],
            html: false,
        },
        attributes: {
            attachmentId: {
                type: 'number',
            },
            url: {
                type: 'string',
            },
            previewUrl: {
                type: 'string',
            },
            title: {
                type: 'string',
            },
            hasPreview: {
                type: 'boolean',
                default: false,
            },
            height: {
                type: 'number',
                default: 600,
            },
            align: {
                type: 'string',
                default: 'none',
            },
            teacherModeVisible: {
                type: 'boolean',
                default: true,
            },
        },

        edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var isSelected = props.isSelected;

            function onSelectFile( media ) {
                console.log( '[eXeLearning Block] Media selected:', media );

                if ( ! media || ! media.id ) {
                    console.log( '[eXeLearning Block] No valid media selected' );
                    return;
                }

                // Check if it's an ELPX file by extension or mime type
                var filename = media.filename || media.url || '';
                var isElpFile = filename.toLowerCase().endsWith( '.elpx' ) ||
                                media.mime === 'application/zip' ||
                                media.subtype === 'zip' ||
                                ( media.exelearning && media.exelearning.version );

                console.log( '[eXeLearning Block] Is ELP file:', isElpFile, 'filename:', filename );

                if ( isElpFile ) {
                    var exeData = media.exelearning || {};
                    console.log( '[eXeLearning Block] Setting attributes with exeData:', exeData );
                    setAttributes({
                        attachmentId: media.id,
                        url: media.url,
                        previewUrl: exeData.preview_url || '',
                        title: media.title || media.filename || __( 'eXeLearning Content', 'exelearning' ),
                        hasPreview: exeData.has_preview || false,
                    });
                } else {
                    console.log( '[eXeLearning Block] File is not an ELP file. mime:', media.mime, 'type:', media.type );
                }
            }

            function onRemoveFile() {
                setAttributes({
                    attachmentId: undefined,
                    url: undefined,
                    previewUrl: undefined,
                    title: undefined,
                    hasPreview: false,
                    teacherModeVisible: true,
                });
            }

            function onEditInExeLearning() {
                if ( attributes.attachmentId && window.ExeLearningEditor ) {
                    window.ExeLearningEditor.open( attributes.attachmentId );
                }
            }

            // If no file selected, show placeholder
            if ( ! attributes.attachmentId ) {
                return el( MediaUploadCheck, null,
                    el( MediaUpload, {
                        onSelect: onSelectFile,
                        allowedTypes: [ 'application/zip', 'application/x-exe-learning' ],
                        value: attributes.attachmentId,
                        render: function( obj ) {
                            return el( Placeholder, {
                                    icon: 'media-default',
                                    label: __( 'eXeLearning Content', 'exelearning' ),
                                    instructions: __( 'Upload or select a .elpx file from your media library', 'exelearning' ),
                                    className: 'exelearning-upload-placeholder'
                                },
                                el( 'div', { className: 'components-placeholder__controls' },
                                    el( Button, {
                                        isPrimary: true,
                                        onClick: obj.open
                                    }, __( 'Upload .elpx File', 'exelearning' ) ),
                                    el( Button, {
                                        isSecondary: true,
                                        onClick: obj.open,
                                        style: { marginLeft: '10px' }
                                    }, __( 'Media Library', 'exelearning' ) )
                                )
                            );
                        }
                    })
                );
            }

            // File is selected - show preview or info
            return el( Fragment, null,
                // Inspector Controls (sidebar)
                el( InspectorControls, null,
                    el( PanelBody, { title: __( 'Settings', 'exelearning' ), initialOpen: true },
                        el( RangeControl, {
                            label: __( 'Height (px)', 'exelearning' ),
                            value: attributes.height,
                            onChange: function( value ) {
                                setAttributes( { height: value } );
                            },
                            min: 200,
                            max: 1200,
                            step: 10,
                        }),
                        el( ToggleControl, {
                            label: __( 'Show Teacher Mode toggler', 'exelearning' ),
                            checked: attributes.teacherModeVisible !== false,
                            onChange: function( value ) {
                                setAttributes( { teacherModeVisible: value } );
                            },
                        }),
                        el( Button, {
                            isPrimary: true,
                            onClick: onEditInExeLearning,
                            style: { marginTop: '10px', width: '100%', justifyContent: 'center' }
                        }, __( 'Edit in eXeLearning', 'exelearning' ) )
                    )
                ),
                // Block Controls (toolbar)
                el( BlockControls, null,
                    el( ToolbarGroup, null,
                        el( ToolbarButton, {
                            icon: 'edit',
                            label: __( 'Edit in eXeLearning', 'exelearning' ),
                            onClick: onEditInExeLearning
                        }),
                        el( MediaUpload, {
                            onSelect: onSelectFile,
                            allowedTypes: [ 'application/zip', 'application/x-exe-learning' ],
                            value: attributes.attachmentId,
                            render: function( obj ) {
                                return el( ToolbarButton, {
                                    icon: 'update',
                                    label: __( 'Change file', 'exelearning' ),
                                    onClick: obj.open
                                });
                            }
                        }),
                        el( ToolbarButton, {
                            icon: 'trash',
                            label: __( 'Remove', 'exelearning' ),
                            onClick: onRemoveFile
                        })
                    )
                ),
                el( 'div', { className: 'exelearning-block-preview' },
                    attributes.hasPreview && attributes.previewUrl
                        ? el( ResizableBox, {
                            size: { height: attributes.height },
                            minHeight: 200,
                            enable: {
                                top: false,
                                right: false,
                                bottom: true,
                                left: false,
                            },
                            onResizeStop: function( event, direction, elt, delta ) {
                                setAttributes({ height: attributes.height + delta.height });
                            },
                            style: {
                                margin: '0 auto',
                            }
                        },
                            el( 'div', { style: { position: 'relative', width: '100%', height: '100%' } },
                                el( 'iframe', {
                                    src: attributes.previewUrl,
                                    style: {
                                        width: '100%',
                                        height: '100%',
                                        border: '1px solid #ddd',
                                        borderRadius: '4px',
                                        background: '#fff',
                                    },
                                    title: attributes.title || __( 'eXeLearning Content', 'exelearning' ),
                                }),
                                ! isSelected && el( 'div', {
                                    style: {
                                        position: 'absolute',
                                        top: 0,
                                        left: 0,
                                        right: 0,
                                        bottom: 0,
                                        cursor: 'pointer',
                                    },
                                })
                            )
                        )
                        : el( 'div', {
                            style: {
                                padding: '40px 20px',
                                background: '#fff3cd',
                                border: '1px solid #ffc107',
                                borderRadius: '4px',
                                textAlign: 'center',
                            }
                        },
                            el( 'p', { style: { margin: '0 0 10px', fontWeight: '600' } }, __( 'No preview available', 'exelearning' ) ),
                            el( 'p', { style: { margin: '0', fontSize: '13px', color: '#666' } },
                                __( 'This is an eXeLearning v2 source file. The content will be displayed on the frontend if exported HTML is available.', 'exelearning' )
                            )
                        )
                )
            );
        },

        save: function() {
            // Rendering is handled dynamically on the server.
            return null;
        }
    } );
} )( window.wp );
