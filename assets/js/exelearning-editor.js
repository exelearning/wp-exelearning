/**
 * eXeLearning Editor Handler
 *
 * Parent-side controller for the embedded editor modal.
 * Uses EmbeddingBridge protocol only.
 */
( function( $ ) {
	'use strict';

	const FORMAT_OPTIONS = [
		{ value: 'elpx', label: 'ELPX (.elpx)' },
		{ value: 'scorm12', label: 'SCORM 1.2 (.zip)' },
		{ value: 'epub3', label: 'EPUB3 (.epub)' },
	];

	const ExeLearningEditor = {
		modal: null,
		iframe: null,
		saveBtn: null,
		formatSelect: null,
		loadingModal: null,
		currentAttachmentId: null,
		isOpen: false,
		isSaving: false,
		hasUnsavedChanges: false,
		editorOrigin: '*',
		requestCounter: 0,
		exportRequestId: null,

		init: function() {
			this.modal = $( '#exelearning-editor-modal' );
			this.iframe = $( '#exelearning-editor-iframe' );
			this.saveBtn = $( '#exelearning-editor-save' );
			this.bindEvents();
		},

		insertFormatSelector: function() {
			if ( this.formatSelect ) {
				return;
			}
			const select = document.createElement( 'select' );
			select.id = 'exelearning-editor-format';
			select.className = 'regular-text';
			select.style.maxWidth = '180px';
			select.style.marginRight = '8px';

			FORMAT_OPTIONS.forEach( ( option ) => {
				const element = document.createElement( 'option' );
				element.value = option.value;
				element.textContent = option.label;
				if ( option.value === 'elpx' ) {
					element.selected = true;
				}
				select.appendChild( element );
			} );

			const closeBtn = document.getElementById( 'exelearning-editor-close' );
			if ( closeBtn && closeBtn.parentNode ) {
				closeBtn.parentNode.insertBefore( select, closeBtn );
			}
			this.formatSelect = $( select );
		},

		nextRequestId: function( prefix ) {
			this.requestCounter += 1;
			return `${ prefix }-${ Date.now() }-${ this.requestCounter }`;
		},

		getFormat: function() {
			return 'elpx';
		},

		getEditorOrigin: function() {
			try {
				return new URL( this.iframe.attr( 'src' ), window.location.href ).origin;
			} catch ( e ) {
				return '*';
			}
		},

		postToEditor: function( message ) {
			const iframeWindow = this.iframe[0]?.contentWindow;
			if ( iframeWindow ) {
				iframeWindow.postMessage( message, this.editorOrigin );
			}
		},

		bindEvents: function() {
			const self = this;

			$( '#exelearning-editor-save' ).on( 'click', function() {
				self.requestSave();
			} );

			$( '#exelearning-editor-close' ).on( 'click', function() {
				self.close();
			} );

			window.addEventListener( 'message', function( event ) {
				self.handleMessage( event );
			} );

			$( document ).on( 'keydown', function( e ) {
				if ( e.key === 'Escape' && self.isOpen ) {
					self.close();
				}
			} );
		},

		createLoadingModal: function() {
			if ( this.loadingModal ) {
				return;
			}
			const savingText = exelearningEditorVars.i18n?.saving || 'Saving...';
			const waitText = exelearningEditorVars.i18n?.pleaseWait || 'Please wait while the file is being saved.';
			const errorCloseText = exelearningEditorVars.i18n?.close || 'Close';
			const html =
				'<div class="exelearning-loading-modal" id="exelearning-loading-modal">' +
					'<div class="exelearning-loading-modal__content">' +
						'<div class="exelearning-loading-modal__spinner"></div>' +
						'<h3 class="exelearning-loading-modal__title">' + savingText + '</h3>' +
						'<p class="exelearning-loading-modal__message">' + waitText + '</p>' +
						'<div class="exelearning-loading-modal__error">' +
							'<p class="exelearning-loading-modal__error-text"></p>' +
							'<button type="button" class="button exelearning-loading-modal__close">' + errorCloseText + '</button>' +
						'</div>' +
					'</div>' +
				'</div>';
			$( 'body' ).append( html );
			this.loadingModal = $( '#exelearning-loading-modal' );

			const self = this;
			this.loadingModal.on( 'click', '.exelearning-loading-modal__close', function() {
				self.hideLoadingModal();
			} );
		},

		showLoadingModal: function() {
			this.createLoadingModal();
			this.loadingModal.removeClass( 'is-error' ).addClass( 'is-visible' );
		},

		hideLoadingModal: function() {
			if ( this.loadingModal ) {
				this.loadingModal.removeClass( 'is-visible is-error' );
			}
		},

		showLoadingError: function( message ) {
			this.createLoadingModal();
			this.loadingModal.addClass( 'is-error' );
			this.loadingModal.find( '.exelearning-loading-modal__error-text' ).text( message );
		},

		setSavingState: function( saving ) {
			this.isSaving = saving;
			if ( this.saveBtn.length ) {
				this.saveBtn.prop( 'disabled', saving );
				this.saveBtn.html(
					'<span class="dashicons dashicons-wordpress"></span> ' +
					( saving
						? ( exelearningEditorVars.i18n?.saving || 'Saving...' )
						: ( exelearningEditorVars.i18n?.saveToWordPress || 'Save to WordPress' ) )
				);
			}
			if ( saving ) {
				this.showLoadingModal();
			} else {
				this.hideLoadingModal();
			}
		},

		open: function( attachmentId ) {
			if ( ! attachmentId ) {
				console.error( 'No attachment ID provided' );
				return;
			}

			this.currentAttachmentId = attachmentId;
			this.exportRequestId = null;
			this.editorOrigin = '*';

			const baseEditorUrl =
				exelearningEditorVars.editorPageUrl +
				'&attachment_id=' + attachmentId +
				'&_wpnonce=' + exelearningEditorVars.editorNonce;

			if ( this.modal.length && this.iframe.length ) {
				this.modal.show();
				this.isOpen = true;
				$( 'body' ).addClass( 'exelearning-editor-open' );
				this.saveBtn.prop( 'disabled', true );
				this.saveBtn.html(
					'<span class="dashicons dashicons-wordpress"></span> ' +
					( exelearningEditorVars.i18n?.saveToWordPress || 'Save to WordPress' )
				);

				const self = this;
				( async function() {
					let importUrl = '';
					try {
						const metaResponse = await fetch(
							`${ exelearningEditorVars.restUrl }/elp-data/${ attachmentId }`,
							{
								headers: { 'X-WP-Nonce': exelearningEditorVars.nonce },
								credentials: 'same-origin',
							}
						);
						if ( metaResponse.ok ) {
							const meta = await metaResponse.json();
							if ( meta?.url ) {
								importUrl = meta.url;
							}
						}
					} catch ( metaError ) {
						console.warn( 'ExeLearningEditor: Could not resolve import URL', metaError );
					}

					const freshUrl = importUrl
						? `${ baseEditorUrl }&import=${ encodeURIComponent( importUrl ) }`
						: baseEditorUrl;

					self.iframe.attr( 'src', freshUrl );
					self.editorOrigin = self.getEditorOrigin();
				} )();
			} else {
				window.open( baseEditorUrl, '_blank', 'width=900,height=700' );
			}
		},

		checkUnsavedChanges: function() {
			if ( this.hasUnsavedChanges ) {
				return true;
			}
			try {
				const iframeWindow = this.iframe[0]?.contentWindow;
				const dm = iframeWindow?.eXeLearning?.app?.project?._yjsBridge?.documentManager;
				if ( dm && dm.isDirty ) {
					return true;
				}
			} catch ( e ) {}
			return false;
		},

		close: function( skipConfirm ) {
			if ( ! this.isOpen ) {
				return;
			}

			if ( ! skipConfirm && this.checkUnsavedChanges() ) {
				const message = exelearningEditorVars.i18n?.unsavedChanges ||
					'You have unsaved changes. Are you sure you want to close?';
				if ( ! window.confirm( message ) ) {
					return;
				}
			}

			try {
				const iframeWindow = this.iframe[0]?.contentWindow;
				if ( iframeWindow ) {
					iframeWindow.onbeforeunload = null;
				}
			} catch ( e ) {}

			var wasShowingLoader = this.isSaving || ( skipConfirm === true );
			this.modal.hide();
			this.isOpen = false;
			this.hasUnsavedChanges = false;
			this.iframe.attr( 'src', 'about:blank' );
			$( 'body' ).removeClass( 'exelearning-editor-open' );
			this.currentAttachmentId = null;
			this.exportRequestId = null;
			this.isSaving = false;
			if ( this.saveBtn.length ) {
				this.saveBtn.prop( 'disabled', false );
				this.saveBtn.html(
					'<span class="dashicons dashicons-wordpress"></span> ' +
					( exelearningEditorVars.i18n?.saveToWordPress || 'Save to WordPress' )
				);
			}
			this.refreshMediaLibrary();
			if ( wasShowingLoader ) {
				var self = this;
				setTimeout( function() {
					self.hideLoadingModal();
				}, 1500 );
			} else {
				this.hideLoadingModal();
			}
		},

		requestSave: function() {
			if ( this.isSaving || ! this.iframe.length ) {
				return;
			}

			this.setSavingState( true );
			this.exportRequestId = this.nextRequestId( 'export' );
			this.postToEditor( {
				type: 'WP_REQUEST_SAVE',
				requestId: this.exportRequestId,
			} );
		},

		handleMessage: async function( event ) {
			const data = event.data;
			const iframeWindow = this.iframe[0]?.contentWindow;

			if ( ! data || ! data.type || ! iframeWindow || event.source !== iframeWindow ) {
				if ( data?.source === 'wp-exe-editor' && data.type === 'request-save' ) {
					this.requestSave();
				}
				return;
			}

			if ( this.editorOrigin !== '*' && event.origin !== this.editorOrigin ) {
				return;
			}

			switch ( data.type ) {
				case 'EXELEARNING_READY':
					this.postToEditor( {
						type: 'CONFIGURE',
						requestId: this.nextRequestId( 'configure' ),
						data: {
							hideUI: {
								fileMenu: true,
								saveButton: true,
								userMenu: true,
							},
						},
					} );
					break;

				case 'DOCUMENT_LOADED':
					if ( ! this.isSaving ) {
						this.saveBtn.prop( 'disabled', false );
					}
					this.hasUnsavedChanges = false;
					break;

				case 'DOCUMENT_CHANGED':
					this.hasUnsavedChanges = true;
					break;

				case 'WP_SAVE_FILE':
					if ( data.requestId === this.exportRequestId ) {
						await this.handleExportFile( data );
					}
					break;

				case 'WP_REQUEST_SAVE_ERROR':
					if ( data.requestId === this.exportRequestId ) {
						console.error( 'ExeLearningEditor: Save request error', data.error );
						this.setSavingState( false );
					}
					break;
			}
		},

		handleExportFile: async function( payload ) {
			const format = 'elpx';
			const filename = payload.filename || 'project.elpx';
			const mimeType = payload.mimeType || 'application/zip';
			try {
				const blob = new Blob( [ payload.bytes ], { type: mimeType } );
				const formData = new FormData();
				formData.append( 'file', blob, filename );
				formData.append( 'format', format );

				const endpoint = this.currentAttachmentId
					? `${ exelearningEditorVars.restUrl }/save/${ this.currentAttachmentId }`
					: `${ exelearningEditorVars.restUrl }/create`;

				const response = await fetch( endpoint, {
					method: 'POST',
					headers: { 'X-WP-Nonce': exelearningEditorVars.nonce },
					body: formData,
					credentials: 'same-origin',
				} );

				const result = await response.json();
				if ( ! response.ok || ! result?.success ) {
					throw new Error( result?.message || `Save failed (${ response.status })` );
				}

				this.onSaveComplete( {
					attachmentId: result.attachment_id || result.attachmentId || this.currentAttachmentId,
					previewUrl: result.preview_url || result.previewUrl || null,
				} );
				try {
					const iframeWindow = this.iframe[0]?.contentWindow;
					if ( iframeWindow ) {
						iframeWindow.onbeforeunload = null;
					}
				} catch ( e ) {}
				this.postToEditor( {
					type: 'WP_SAVE_CONFIRMED',
					requestId: this.nextRequestId( 'wp-save-confirmed' ),
				} );
				this.hasUnsavedChanges = false;
				this.close( true );
			} catch ( error ) {
				console.error( 'ExeLearningEditor: Save failed', error );
				this.setSavingState( false );
				this.showLoadingError( error.message || 'Save failed' );
			}
		},

		defaultFilenameForFormat: function( format ) {
			if ( format === 'elpx' ) {
				return 'project.elpx';
			}
			if ( format === 'epub3' || format === 'epub' ) {
				return 'project.epub';
			}
			return 'project.zip';
		},

		mimeForFilename: function( filename ) {
			if ( filename.endsWith( '.epub' ) ) {
				return 'application/epub+zip';
			}
			return 'application/zip';
		},

		downloadExport: function( bytes, filename, mimeType ) {
			const blob = new Blob( [ bytes ], { type: mimeType } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );
		},

		onSaveComplete: function( data ) {
			if ( data.attachmentId ) {
				this.refreshAttachment( data.attachmentId, data.previewUrl );
				this.updateBlockPreview( data.attachmentId, data.previewUrl );
			}
			this.refreshMediaLibrary();
		},

		updateBlockPreview: function( attachmentId, previewUrl ) {
			if ( ! attachmentId || ! previewUrl || ! wp.data ) {
				return;
			}

			let updated = false;
			const blocks = wp.data.select( 'core/block-editor' ).getBlocks();
			blocks.forEach( ( block ) => {
				if (
					block.name === 'exelearning/elp-upload' &&
					block.attributes.attachmentId === attachmentId
				) {
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes(
						block.clientId,
						{ previewUrl }
					);
					updated = true;
				}
			} );

			if ( updated && wp.data.select( 'core/editor' ) ) {
				wp.data.dispatch( 'core/editor' ).savePost();
			}

			if ( updated && previewUrl ) {
				this.ensurePreviewLoaded( previewUrl );
			}
		},

		ensurePreviewLoaded: function( previewUrl ) {
			setTimeout( function() {
				const iframes = document.querySelectorAll( '.exelearning-block-preview iframe' );
				iframes.forEach( function( iframe ) {
					try {
						const doc = iframe.contentDocument;
						if ( ! doc || ! doc.body || doc.body.innerHTML.length === 0 ) {
							fetch( previewUrl, { credentials: 'same-origin' } )
								.then( function( response ) {
									return response.ok ? response.text() : null;
								} )
								.then( function( html ) {
									if ( html && html.length > 0 ) {
										iframe.srcdoc = html;
									}
								} )
								.catch( function() {} );
						}
					} catch ( e ) {}
				} );
			}, 2000 );
		},

		refreshAttachment: function( attachmentId, previewUrl ) {
			if ( ! wp.media ) {
				return;
			}

			try {
				const attachment = wp.media.attachment( attachmentId );
				if ( attachment && ! attachment.destroyed ) {
					attachment.fetch().done( function() {
						try {
							if ( previewUrl ) {
								const exeData = attachment.get( 'exelearning' ) || {};
								exeData.preview_url = previewUrl;
								attachment.set( 'exelearning', exeData, { silent: true } );
							}

							$( '.attachment-details .thumbnail' )
								.removeClass( 'exelearning-details-preview-added' )
								.removeClass( 'exelearning-details-no-preview' );

							$( '.exelearning-preview-actions, .exelearning-preview-link, .exelearning-metadata, .exelearning-edit-button' ).remove();

							if ( attachment && ! attachment.destroyed ) {
								attachment.trigger( 'change' );
							}
						} catch ( e ) {
							console.warn( 'ExeLearningEditor: Error updating attachment', e );
						}
					} ).fail( function() {
						console.warn( 'ExeLearningEditor: Failed to fetch attachment', attachmentId );
					} );
				}
			} catch ( e ) {
				console.warn( 'ExeLearningEditor: Error refreshing attachment', e );
			}
		},

		refreshMediaLibrary: function() {
			if ( wp.media && wp.media.frame ) {
				try {
					wp.media.frame.content.get().collection.props.set( { ignore: + new Date() } );
				} catch ( e ) {}
			}
		},
	};

	$( document ).ready( function() {
		ExeLearningEditor.init();
		window.ExeLearningEditor = ExeLearningEditor;
	} );
} )( jQuery );
