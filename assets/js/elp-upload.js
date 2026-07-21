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
    var CheckboxControl = wp.components.CheckboxControl;
    var useRef = wp.element.useRef;
    var useEffect = wp.element.useEffect;

    var TEACHER_MODE_STYLE_ID = 'exelearning-teacher-mode-style';
    var TEACHER_MODE_CSS = '#teacher-mode-toggler-wrapper { visibility: hidden !important; }';

    var useState = wp.element.useState;

    // Mirrors ExeLearning_Download_Formats::all() (id, label, suffix, client).
    // `client` formats are generated client-side by the static editor, so they
    // require the editor to be installed; `.elpx` is a direct download.
    // Labels are wrapped in literal __() calls (not via a variable key) so that
    // `wp i18n make-pot`/`make-json` can statically extract them. With
    // wp_set_script_translations() the locale data is printed before this script
    // runs, so resolving the labels at module load is correct.
    var DOWNLOAD_FORMAT_DEFINITIONS = [
        { id: 'elpx',    label: __( 'Download .elpx', 'exelearning' ),         suffix: '.elpx',     client: false },
        { id: 'html5',   label: __( 'Web (_web.zip)', 'exelearning' ),         suffix: '_web.zip',  client: true },
        { id: 'scorm12', label: __( 'SCORM 1.2 (_scorm.zip)', 'exelearning' ), suffix: '_scorm.zip', client: true },
        { id: 'ims',     label: __( 'IMS Package (_ims.zip)', 'exelearning' ), suffix: '_ims.zip',  client: true },
        { id: 'epub3',   label: __( 'EPUB3 (.epub)', 'exelearning' ),          suffix: '.epub',     client: true }
    ];
    var DEFAULT_DOWNLOAD_FORMATS = DOWNLOAD_FORMAT_DEFINITIONS.map( function( f ) { return f.id; } );

    /**
     * Build a filename-safe slug from the block title (mirrors sanitize_title).
     *
     * @param {Object} attributes Block attributes.
     * @return {string} Slug.
     */
    function downloadSlug( attributes ) {
        var fallback = 'exelearning-' + attributes.attachmentId;
        var base = ( attributes.title || fallback )
            .toLowerCase()
            .replace( /[^a-z0-9]+/g, '-' )
            .replace( /^-+|-+$/g, '' );
        return base || fallback;
    }

    /**
     * Edit-mode download split-button. Mirrors the frontend markup produced by
     * ExeLearning_Download_Button_Renderer and reuses window.wpExeDownload so
     * the export pipeline is shared (single source of truth).
     *
     * @param {Object} props          Component props.
     * @param {Object} props.attributes Block attributes.
     * @return {Object|null} Element tree or null when no formats are enabled.
     */
    function DownloadToolbar( props ) {
        var attributes = props.attributes;
        var config = window.wpExeDownloadConfig || {};
        // Client-side export formats need the static editor installed.
        // wp_localize_script() stringifies booleans (true -> '1', false -> ''),
        // so normalize here; default to installed when the key is absent.
        var ei = config.editorInstalled;
        var editorInstalled = ( ei === undefined )
            ? true
            : ( ei === true || ei === 1 || ei === '1' );
        var editorRequiredMsg = ( config.i18n && config.i18n.editorRequired )
            || __( 'Install the eXeLearning editor from the plugin settings page to enable this format.', 'exelearning' );

        var enabledIds = Array.isArray( attributes.downloadFormats ) && attributes.downloadFormats.length
            ? attributes.downloadFormats
            : DEFAULT_DOWNLOAD_FORMATS;
        // Preserve canonical order, then flag client formats as disabled when the
        // editor is missing and sort them last so the primary slot stays usable
        // (mirrors ExeLearning_Download_Button_Renderer::build_items()).
        var items = DOWNLOAD_FORMAT_DEFINITIONS.filter( function( f ) {
            return enabledIds.indexOf( f.id ) !== -1;
        } ).map( function( f ) {
            return { id: f.id, label: f.label, suffix: f.suffix, disabled: f.client && ! editorInstalled };
        } );
        if ( ! editorInstalled ) {
            items.sort( function( a, b ) {
                return ( a.disabled ? 1 : 0 ) - ( b.disabled ? 1 : 0 );
            } );
        }

        var openState = useState( false );
        var isOpen = openState[ 0 ];
        var setOpen = openState[ 1 ];
        var busyState = useState( false );
        var busy = busyState[ 0 ];
        var setBusy = busyState[ 1 ];
        var statusState = useState( '' );
        var status = statusState[ 0 ];
        var setStatus = statusState[ 1 ];

        if ( ! items.length ) {
            return null;
        }

        function run( fmt, ev ) {
            if ( ev ) {
                ev.preventDefault();
            }
            if ( fmt.disabled ) {
                return;
            }
            setOpen( false );
            if ( ! window.wpExeDownload || ! window.wpExeDownload.downloadFormat ) {
                console.error( '[eXeLearning Block] Download helper (wp-exe-download.js) not loaded' );
                return;
            }
            setStatus( '' );
            setBusy( true );
            window.wpExeDownload.downloadFormat( {
                format: fmt.id,
                suffix: fmt.suffix,
                attachmentId: attributes.attachmentId,
                elpUrl: attributes.url,
                slug: downloadSlug( attributes ),
                // No container: edit-mode busy/status is handled via React state
                // to avoid mutating React-owned DOM directly.
                container: null,
            } ).catch( function() {
                setStatus( __( 'Download failed. Please try again.', 'exelearning' ) );
            } ).finally( function() {
                setBusy( false );
            } );
        }

        function renderItem( fmt, isPrimary ) {
            var isElpx = fmt.id === 'elpx';
            var className = isPrimary ? 'exelearning-download__primary' : 'exelearning-download__item';
            if ( fmt.disabled ) {
                className += ' exelearning-download__item--disabled';
            }
            return el( isElpx ? 'a' : 'button',
                {
                    href: isElpx ? '#' : undefined,
                    type: isElpx ? undefined : 'button',
                    className: className,
                    role: isPrimary ? 'button' : 'menuitem',
                    disabled: ( ! isElpx && fmt.disabled ) || undefined,
                    'aria-disabled': fmt.disabled ? 'true' : undefined,
                    title: fmt.disabled ? editorRequiredMsg : undefined,
                    onClick: function( ev ) { run( fmt, ev ); },
                },
                el( 'span', { className: 'dashicons dashicons-download' } ),
                el( 'span', { className: 'exelearning-download__label' }, fmt.label )
            );
        }

        var primary = items[ 0 ];
        var rest = items.slice( 1 );

        return el( 'div',
            {
                className: 'exelearning-download',
                'data-attachment-id': attributes.attachmentId,
                'data-busy': busy ? '1' : undefined,
            },
            renderItem( primary, true ),
            rest.length ? el( 'button', {
                type: 'button',
                className: 'exelearning-download__toggle',
                'aria-haspopup': 'true',
                'aria-expanded': isOpen ? 'true' : 'false',
                'aria-label': __( 'More download formats', 'exelearning' ),
                onClick: function( ev ) { ev.preventDefault(); setOpen( ! isOpen ); },
            },
                el( 'span', { className: 'dashicons dashicons-arrow-down-alt2' } )
            ) : null,
            rest.length ? el( 'ul', {
                className: 'exelearning-download__menu',
                role: 'menu',
                hidden: ! isOpen,
            },
                rest.map( function( fmt ) {
                    return el( 'li', { key: fmt.id, role: 'none' }, renderItem( fmt, false ) );
                } )
            ) : null,
            status ? el( 'span', { className: 'exelearning-download__status' }, status ) : null
        );
    }

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
                default: false,
            },
            showDownload: {
                type: 'boolean',
                default: false,
            },
            downloadFormats: {
                type: 'array',
                default: DEFAULT_DOWNLOAD_FORMATS,
            },
            fullscreen: {
                type: 'boolean',
                default: false,
            },
        },

        edit: function( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var isSelected = props.isSelected;
            var iframeRef = useRef( null );

            // Toggle the teacher-mode toggler inside the preview iframe. The
            // iframe is same-origin (sandbox includes allow-same-origin), so we
            // can inject/remove a small stylesheet into its document directly,
            // which updates instantly without reloading the preview.
            useEffect( function() {
                var iframe = iframeRef.current;
                if ( ! iframe ) {
                    return;
                }

                function applyStyle() {
                    try {
                        var doc = iframe.contentDocument;
                        if ( ! doc || ! doc.head ) {
                            return;
                        }

                        // Prevent the preview iframe from triggering "Leave site?" dialogs.
                        var iframeWin = iframe.contentWindow;
                        iframeWin.onbeforeunload = null;
                        try {
                            Object.defineProperty( iframeWin, 'onbeforeunload', {
                                get: function() { return null; },
                                set: function() {},
                                configurable: true,
                            } );
                        } catch ( ignore ) {}

                        var existing = doc.getElementById( TEACHER_MODE_STYLE_ID );

                        if ( ! attributes.teacherModeVisible ) {
                            if ( ! existing ) {
                                var style = doc.createElement( 'style' );
                                style.id = TEACHER_MODE_STYLE_ID;
                                style.textContent = TEACHER_MODE_CSS;
                                doc.head.appendChild( style );
                            }
                        } else if ( existing ) {
                            existing.remove();
                        }
                    } catch ( e ) {
                        // Cross-origin or not-yet-loaded -- ignore.
                    }
                }

                applyStyle();
                iframe.addEventListener( 'load', applyStyle );

                return function() {
                    iframe.removeEventListener( 'load', applyStyle );
                };
            }, [ attributes.teacherModeVisible ] );

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
                    teacherModeVisible: false,
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
                    el( Placeholder, {
                            icon: 'media-default',
                            label: __( 'eXeLearning Content', 'exelearning' ),
                            instructions: __( 'Upload or select a .elpx file from your media library', 'exelearning' ),
                            className: 'exelearning-upload-placeholder'
                        },
                        el( 'div', { className: 'components-placeholder__controls' },
                            el( MediaUpload, {
                                onSelect: onSelectFile,
                                allowedTypes: [ 'application/zip', 'application/x-exe-learning' ],
                                value: attributes.attachmentId,
                                mode: 'upload',
                                render: function( obj ) {
                                    return el( Button, {
                                        isPrimary: true,
                                        onClick: obj.open
                                    }, __( 'Upload .elpx File', 'exelearning' ) );
                                }
                            }),
                            el( MediaUpload, {
                                onSelect: onSelectFile,
                                allowedTypes: [ 'application/zip', 'application/x-exe-learning' ],
                                value: attributes.attachmentId,
                                mode: 'browse',
                                render: function( obj ) {
                                    return el( Button, {
                                        isSecondary: true,
                                        onClick: obj.open,
                                        style: { marginLeft: '10px' }
                                    }, __( 'Media Library', 'exelearning' ) );
                                }
                            })
                        )
                    )
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
                            label: __( 'Show teacher layer selector', 'exelearning' ),
                            help: __( 'If disabled, the teacher layer selector is hidden in the embedded eXeLearning content.', 'exelearning' ),
                            checked: attributes.teacherModeVisible === true,
                            onChange: function( value ) {
                                setAttributes( { teacherModeVisible: value } );
                            },
                        }),
                        el( ToggleControl, {
                            className: 'exelearning-fullscreen-toggle',
                            label: __( 'Show fullscreen button', 'exelearning' ),
                            checked: attributes.fullscreen === true,
                            onChange: function( value ) {
                                setAttributes( { fullscreen: value } );
                            },
                        }),
                        el( Button, {
                            isPrimary: true,
                            onClick: onEditInExeLearning,
                            style: { marginTop: '10px', width: '100%', justifyContent: 'center' }
                        }, __( 'Edit in eXeLearning', 'exelearning' ) )
                    ),
                    el( PanelBody, { title: __( 'Download options', 'exelearning' ), initialOpen: false },
                        el( ToggleControl, {
                            label: __( 'Show download button', 'exelearning' ),
                            checked: attributes.showDownload !== false,
                            onChange: function( value ) {
                                setAttributes( { showDownload: value } );
                            },
                        }),
                        attributes.showDownload !== false && el( Fragment, null,
                            el( 'p', { style: { marginTop: '10px', marginBottom: '6px', fontWeight: 600 } },
                                __( 'Available formats', 'exelearning' )
                            ),
                            DOWNLOAD_FORMAT_DEFINITIONS.map( function( fmt ) {
                                var current = Array.isArray( attributes.downloadFormats ) ? attributes.downloadFormats : DEFAULT_DOWNLOAD_FORMATS;
                                var checked = current.indexOf( fmt.id ) !== -1;
                                return el( CheckboxControl, {
                                    key: fmt.id,
                                    label: fmt.label,
                                    checked: checked,
                                    onChange: function( value ) {
                                        var next = current.filter( function( id ) { return id !== fmt.id; } );
                                        if ( value ) {
                                            next.push( fmt.id );
                                        }
                                        // Preserve canonical order.
                                        next = DEFAULT_DOWNLOAD_FORMATS.filter( function( id ) { return next.indexOf( id ) !== -1; } );
                                        setAttributes( { downloadFormats: next } );
                                    }
                                });
                            })
                        )
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
                ( attributes.showDownload !== false || attributes.fullscreen === true ) && el( 'div', { className: 'exelearning-block-toolbar' },
                    attributes.showDownload !== false && el( DownloadToolbar, { attributes: attributes } ),
                    attributes.fullscreen === true && el( 'button', {
                        type: 'button',
                        className: 'exelearning-toolbar-btn exelearning-fullscreen-btn',
                        'aria-label': __( 'View fullscreen', 'exelearning' ),
                        title: __( 'View fullscreen', 'exelearning' ),
                    }, el( 'span', { className: 'dashicons dashicons-fullscreen-alt', 'aria-hidden': 'true' } ) )
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
                                    ref: iframeRef,
                                    src: attributes.previewUrl,
                                    // allow-same-origin is required: the eXeLearning viewer is a
                                    // same-origin app and will not render without it. No
                                    // allow-modals so the preview cannot raise "Leave site?"
                                    // dialogs. (Isolating untrusted content in a separate origin
                                    // is tracked as follow-up; see the proxy CSP for mitigation.)
                                    sandbox: 'allow-scripts allow-same-origin allow-popups',
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
