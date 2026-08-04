// Unit tests for assets/js/elp-upload.js, the eXeLearning Gutenberg block.
//
// The block is registered against the real WordPress packages rather than
// hand-written stand-ins: @wordpress/element (React 18, the version WordPress
// ships), @wordpress/blocks, @wordpress/components and @wordpress/i18n are the
// same code that runs in the editor, so registerBlockType really validates the
// block, a real ToggleControl really renders its checkbox, and getByLabelText
// finds a control only if it was wired up accessibly. A stub would have agreed
// with whatever the block passed it.
//
// One thing is stubbed, because it cannot be anything else: wp.blockEditor.
// MediaUpload *is* the WordPress media modal, which needs wp.media and the
// editor data stores; BlockControls and InspectorControls are Slot/Fill pairs
// whose children render into slots the editor owns. They are replaced with
// pass-throughs so their children render inline and can be asserted on -- that
// substitutes editor chrome, not the block's own behaviour.
//
// The block is registered once for the file: registerBlockType refuses a name
// that is already taken, and the module registers on import.

const path = require( 'path' );
const { pathToFileURL } = require( 'url' );
const { render, screen, fireEvent, cleanup } = require( '@testing-library/react' );
const element = require( '@wordpress/element' );
const blocks = require( '@wordpress/blocks' );
const components = require( '@wordpress/components' );
const i18n = require( '@wordpress/i18n' );

const SCRIPT = pathToFileURL(
	path.resolve( __dirname, '../../assets/js/elp-upload.js' )
).href;

const BLOCK_NAME = 'exelearning/elp-upload';

/** MediaUpload invocations, so tests can drive onSelect the way the modal does. */
let mediaUploads = [];
/** Arguments every setAttributes call received. */
let attributeUpdates = [];
/** Anything the block reported to the console. */
let consoleErrors = [];
/** downloadFormat() calls made through the shared helper. */
let downloadCalls = [];

let blockType;
let originalConsoleLog;
let originalConsoleError;

beforeAll( async () => {
	originalConsoleLog = console.log;
	originalConsoleError = console.error;
	// The block narrates media selection to the console; keep the suite readable.
	console.log = () => {};
	console.error = ( ...args ) => consoleErrors.push( args.join( ' ' ) );

	/** Render children inline instead of into an editor slot. */
	const passthrough = ( props ) =>
		element.createElement( element.Fragment, null, props.children );

	global.wp = {
		element,
		blocks,
		components,
		i18n,
		blockEditor: {
			BlockControls: passthrough,
			InspectorControls: passthrough,
			MediaUploadCheck: passthrough,
			// The real one opens the media modal; record the props and expose
			// its render prop, which is the only part the block controls.
			MediaUpload: ( props ) => {
				const entry = { onSelect: props.onSelect, mode: props.mode, allowedTypes: props.allowedTypes };
				mediaUploads.push( entry );
				return props.render( { open: () => entry.opened = true } );
			},
		},
	};
	window.wp = global.wp;

	await import( /* @vite-ignore */ SCRIPT );
	blockType = blocks.getBlockType( BLOCK_NAME );
} );

afterAll( () => {
	console.log = originalConsoleLog;
	console.error = originalConsoleError;
	if ( blocks.getBlockType( BLOCK_NAME ) ) {
		blocks.unregisterBlockType( BLOCK_NAME );
	}
	delete global.wp;
	delete window.wp;
} );

beforeEach( () => {
	mediaUploads = [];
	attributeUpdates = [];
	consoleErrors = [];
	downloadCalls = [];
} );

afterEach( () => {
	cleanup();
	delete window.wpExeDownloadConfig;
	delete window.wpExeDownload;
	delete window.ExeLearningEditor;
} );

/**
 * Render the block's edit component.
 *
 * @param {Object}  attributes   Block attributes, merged over the defaults.
 * @param {boolean} [isSelected] Whether the block is selected in the editor.
 * @return {Object} Testing Library render result.
 */
function renderEdit( attributes, isSelected ) {
	const defaults = {};
	Object.keys( blockType.attributes ).forEach( ( key ) => {
		if ( undefined !== blockType.attributes[ key ].default ) {
			defaults[ key ] = blockType.attributes[ key ].default;
		}
	} );

	return render(
		element.createElement( blockType.edit, {
			attributes: { ...defaults, ...attributes },
			setAttributes: ( update ) => attributeUpdates.push( update ),
			isSelected: undefined === isSelected ? true : isSelected,
		} )
	);
}

/**
 * Expand a collapsed settings panel.
 *
 * PanelBody renders its children only once open, and "Download options" ships
 * collapsed, so its controls do not exist until the panel is clicked.
 *
 * @param {string} title Panel title.
 */
function openPanel( title ) {
	fireEvent.click( screen.getByRole( 'button', { name: title } ) );
}

/** Install the shared download helper the frontend script publishes. */
function installDownloadHelper( behaviour ) {
	window.wpExeDownload = {
		downloadFormat: ( params ) => {
			downloadCalls.push( params );
			return behaviour ? behaviour() : Promise.resolve();
		},
	};
}

describe( 'elp-upload: the registered block', () => {
	it( 'registers under its own name in the embed category', () => {
		expect( blockType ).toBeTruthy();
		expect( blockType.name ).toBe( BLOCK_NAME );
		expect( blockType.category ).toBe( 'embed' );
	} );

	it( 'declares the attributes the shortcode and renderer read back', () => {
		// These names are a contract: public/class-shortcodes.php and
		// includes/class-elp-upload-block.php render from the saved attributes,
		// so renaming one here silently breaks every existing post.
		expect( Object.keys( blockType.attributes ).sort() ).toEqual( [
			'align',
			'attachmentId',
			'downloadFormats',
			'fullscreen',
			'hasPreview',
			'height',
			'previewUrl',
			'showDownload',
			'teacherModeVisible',
			'title',
			'url',
		] );
	} );

	it( 'defaults to a closed-down embed rather than an opted-in one', () => {
		expect( blockType.attributes.teacherModeVisible.default ).toBe( false );
		expect( blockType.attributes.showDownload.default ).toBe( false );
		expect( blockType.attributes.fullscreen.default ).toBe( false );
		expect( blockType.attributes.height.default ).toBe( 600 );
	} );

	it( 'offers every download format by default', () => {
		expect( blockType.attributes.downloadFormats.default ).toEqual( [
			'elpx',
			'html5',
			'scorm12',
			'ims',
			'epub3',
		] );
	} );

	it( 'renders server-side, so save() stores no markup', () => {
		expect( blockType.save() ).toBeNull();
		expect( blockType.supports.html ).toBe( false );
	} );
} );

describe( 'elp-upload: choosing a file', () => {
	it( 'offers an upload button and a media library button', () => {
		renderEdit( {} );

		expect( screen.getByText( 'Upload .elpx File' ) ).toBeTruthy();
		expect( screen.getByText( 'Media Library' ) ).toBeTruthy();
		expect( mediaUploads.map( ( m ) => m.mode ) ).toEqual( [ 'upload', 'browse' ] );
	} );

	it( 'stores the file and its eXeLearning metadata once one is picked', () => {
		renderEdit( {} );

		mediaUploads[ 0 ].onSelect( {
			id: 42,
			url: 'http://example.test/curso.elpx',
			filename: 'curso.elpx',
			title: 'Mi curso',
			exelearning: { preview_url: 'http://example.test/preview/index.html', has_preview: true },
		} );

		expect( attributeUpdates ).toEqual( [
			{
				attachmentId: 42,
				url: 'http://example.test/curso.elpx',
				previewUrl: 'http://example.test/preview/index.html',
				title: 'Mi curso',
				hasPreview: true,
			},
		] );
	} );

	it( 'accepts a file WordPress reported as a plain zip', () => {
		// .elpx is a ZIP; depending on the server, WordPress may report it as
		// application/zip rather than by extension.
		renderEdit( {} );

		mediaUploads[ 0 ].onSelect( {
			id: 7,
			url: 'http://example.test/sin-extension',
			mime: 'application/zip',
			title: 'Paquete',
		} );

		expect( attributeUpdates ).toHaveLength( 1 );
		expect( attributeUpdates[ 0 ].attachmentId ).toBe( 7 );
	} );

	it( 'ignores a file that is not an eXeLearning package', () => {
		renderEdit( {} );

		mediaUploads[ 0 ].onSelect( {
			id: 9,
			url: 'http://example.test/foto.jpg',
			filename: 'foto.jpg',
			mime: 'image/jpeg',
		} );

		expect( attributeUpdates ).toEqual( [] );
	} );

	it( 'ignores a selection with no media behind it', () => {
		renderEdit( {} );

		mediaUploads[ 0 ].onSelect( null );
		mediaUploads[ 0 ].onSelect( {} );

		expect( attributeUpdates ).toEqual( [] );
	} );

	it( 'names an untitled file so the block is not blank', () => {
		renderEdit( {} );

		mediaUploads[ 0 ].onSelect( {
			id: 11,
			url: 'http://example.test/x.elpx',
			filename: 'x.elpx',
		} );

		expect( attributeUpdates[ 0 ].title ).toBe( 'x.elpx' );
	} );
} );

describe( 'elp-upload: the settings panel', () => {
	/** Attributes of a block that already has a file. */
	const WITH_FILE = {
		attachmentId: 42,
		url: 'http://example.test/curso.elpx',
		title: 'Mi curso',
	};

	it( 'turns the teacher layer selector on through a real control', () => {
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByLabelText( 'Show teacher layer selector' ) );

		expect( attributeUpdates ).toEqual( [ { teacherModeVisible: true } ] );
	} );

	it( 'turns the fullscreen button on', () => {
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByLabelText( 'Show fullscreen button' ) );

		expect( attributeUpdates ).toEqual( [ { fullscreen: true } ] );
	} );

	it( 'turns the download button on', () => {
		renderEdit( WITH_FILE );
		openPanel( 'Download options' );

		fireEvent.click( screen.getByLabelText( 'Show download button' ) );

		expect( attributeUpdates ).toEqual( [ { showDownload: true } ] );
	} );

	it( 'stores a new embed height from the range control', () => {
		renderEdit( WITH_FILE );

		// RangeControl renders a slider and a number field, both labelled; the
		// slider is the one carrying the range role.
		fireEvent.change( screen.getByRole( 'slider', { name: 'Height (px)' } ), {
			target: { value: '850' },
		} );

		expect( attributeUpdates ).toEqual( [ { height: 850 } ] );
	} );

	it( 'reflects the stored state back into the controls', () => {
		renderEdit( { ...WITH_FILE, teacherModeVisible: true, fullscreen: false } );

		expect( screen.getByLabelText( 'Show teacher layer selector' ).checked ).toBe( true );
		expect( screen.getByLabelText( 'Show fullscreen button' ).checked ).toBe( false );
	} );

	it( 'lists a checkbox per download format once downloads are on', () => {
		renderEdit( { ...WITH_FILE, showDownload: true } );
		openPanel( 'Download options' );

		expect( screen.getByLabelText( 'Download .elpx' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'Web (_web.zip)' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'SCORM 1.2 (_scorm.zip)' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'IMS Package (_ims.zip)' ) ).toBeTruthy();
		expect( screen.getByLabelText( 'EPUB3 (.epub)' ) ).toBeTruthy();
	} );

	it( 'removes a format from the list when its box is cleared', () => {
		renderEdit( {
			...WITH_FILE,
			showDownload: true,
			downloadFormats: [ 'elpx', 'html5' ],
		} );
		openPanel( 'Download options' );

		fireEvent.click( screen.getByLabelText( 'Web (_web.zip)' ) );

		expect( attributeUpdates ).toEqual( [ { downloadFormats: [ 'elpx' ] } ] );
	} );

	it( 'adds a format back when its box is ticked', () => {
		renderEdit( {
			...WITH_FILE,
			showDownload: true,
			downloadFormats: [ 'elpx' ],
		} );
		openPanel( 'Download options' );

		fireEvent.click( screen.getByLabelText( 'EPUB3 (.epub)' ) );

		expect( attributeUpdates ).toHaveLength( 1 );
		expect( attributeUpdates[ 0 ].downloadFormats ).toContain( 'epub3' );
		expect( attributeUpdates[ 0 ].downloadFormats ).toContain( 'elpx' );
	} );
} );

describe( 'elp-upload: the edit-mode download button', () => {
	const WITH_FILE = {
		attachmentId: 42,
		url: 'http://example.test/curso.elpx',
		title: 'Mi curso',
		showDownload: true,
	};

	it( 'downloads through the same helper the frontend uses', () => {
		// Not a second export pipeline: the block reuses window.wpExeDownload so
		// edit mode and the frontend cannot drift apart.
		installDownloadHelper();
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );

		expect( downloadCalls ).toHaveLength( 1 );
		expect( downloadCalls[ 0 ].format ).toBe( 'elpx' );
		expect( downloadCalls[ 0 ].attachmentId ).toBe( 42 );
		expect( downloadCalls[ 0 ].elpUrl ).toBe( 'http://example.test/curso.elpx' );
		expect( downloadCalls[ 0 ].container ).toBeNull();
	} );

	it( 'names the file after the block title', () => {
		installDownloadHelper();
		renderEdit( { ...WITH_FILE, title: '¡Mi Curso 2026!' } );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );

		expect( downloadCalls[ 0 ].slug ).toBe( 'mi-curso-2026' );
	} );

	it( 'falls back to the attachment id when the title yields no slug', () => {
		installDownloadHelper();
		renderEdit( { ...WITH_FILE, title: '???' } );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );

		expect( downloadCalls[ 0 ].slug ).toBe( 'exelearning-42' );
	} );

	it( 'says so when the download helper was never loaded', () => {
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );

		expect( downloadCalls ).toEqual( [] );
		expect( consoleErrors.join( ' ' ) ).toContain( 'wp-exe-download.js' );
	} );

	it( 'opens the other formats from the toggle', () => {
		installDownloadHelper();
		renderEdit( WITH_FILE );

		const toggle = screen.getByLabelText( 'More download formats' );
		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'false' );

		fireEvent.click( toggle );

		expect( toggle.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
	} );

	it( 'shows only the formats the block enabled', () => {
		installDownloadHelper();
		renderEdit( { ...WITH_FILE, downloadFormats: [ 'elpx', 'epub3' ] } );

		expect( screen.getByText( 'Download .elpx' ) ).toBeTruthy();
		expect( screen.getByText( 'EPUB3 (.epub)' ) ).toBeTruthy();
		expect( screen.queryByText( 'SCORM 1.2 (_scorm.zip)' ) ).toBeNull();
	} );

	it( 'falls back to the full set when the list was emptied', () => {
		installDownloadHelper();
		const { container } = renderEdit( { ...WITH_FILE, downloadFormats: [] } );

		expect( container.querySelector( '.exelearning-download' ) ).not.toBeNull();
	} );

	it( 'renders no toolbar at all when nothing in the list is a real format', () => {
		installDownloadHelper();
		const { container } = renderEdit( {
			...WITH_FILE,
			downloadFormats: [ 'formato-que-no-existe' ],
		} );

		expect( container.querySelector( '.exelearning-download' ) ).toBeNull();
	} );

	it( 'reports a failed export instead of staying busy', async () => {
		installDownloadHelper( () => Promise.reject( new Error( 'boom' ) ) );
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( screen.getByText( 'Download failed. Please try again.' ) ).toBeTruthy();
	} );
} );

describe( 'elp-upload: formats that need the editor installed', () => {
	const WITH_FILE = {
		attachmentId: 42,
		url: 'http://example.test/curso.elpx',
		title: 'Mi curso',
		showDownload: true,
	};

	it( 'disables the client-side formats and explains why', () => {
		window.wpExeDownloadConfig = { editorInstalled: '' };
		installDownloadHelper();
		renderEdit( WITH_FILE );

		const scorm = screen.getByText( 'SCORM 1.2 (_scorm.zip)' ).closest( 'button' );
		expect( scorm.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( scorm.getAttribute( 'title' ) ).toContain( 'Install the eXeLearning editor' );
	} );

	it( 'keeps .elpx usable, because it is the attachment itself', () => {
		window.wpExeDownloadConfig = { editorInstalled: '' };
		installDownloadHelper();
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByText( 'Download .elpx' ) );

		expect( downloadCalls ).toHaveLength( 1 );
	} );

	it( 'refuses to run a disabled format', () => {
		window.wpExeDownloadConfig = { editorInstalled: '' };
		installDownloadHelper();
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByLabelText( 'More download formats' ) );
		const scorm = screen.getByText( 'SCORM 1.2 (_scorm.zip)' ).closest( 'button' );

		// The refusal is the button's own disabled attribute, not a handler that
		// runs and bails: asserting only "nothing downloaded" would pass even if
		// the wiring were gone entirely.
		expect( scorm.disabled ).toBe( true );
		fireEvent.click( scorm );
		expect( downloadCalls ).toEqual( [] );
	} );

	it( 'sorts the unusable formats last so the primary slot stays usable', () => {
		// Mirrors ExeLearning_Download_Button_Renderer::build_items(): with no
		// editor, the primary button must still be the .elpx one.
		window.wpExeDownloadConfig = { editorInstalled: '' };
		installDownloadHelper();
		renderEdit( { ...WITH_FILE, downloadFormats: [ 'scorm12', 'elpx' ] } );

		const primary = document.querySelector( '.exelearning-download__primary' );
		expect( primary.textContent ).toContain( 'Download .elpx' );
	} );

	it( 'reads the stringified true wp_localize_script produces', () => {
		// wp_localize_script() turns booleans into '1' and '', so a raw truthiness
		// check on the value would call an absent editor installed.
		window.wpExeDownloadConfig = { editorInstalled: '1' };
		installDownloadHelper();
		renderEdit( WITH_FILE );

		const scorm = screen.getByText( 'SCORM 1.2 (_scorm.zip)' ).closest( 'button' );
		expect( scorm.getAttribute( 'aria-disabled' ) ).toBeNull();
	} );

	it( 'assumes the editor is there when the flag was never localized', () => {
		installDownloadHelper();
		renderEdit( WITH_FILE );

		const scorm = screen.getByText( 'SCORM 1.2 (_scorm.zip)' ).closest( 'button' );
		expect( scorm.getAttribute( 'aria-disabled' ) ).toBeNull();
	} );
} );

describe( 'elp-upload: the teacher-layer selector in the preview', () => {
	// The preview iframe is same-origin, so the block hides the editor's own
	// teacher-mode toggler by injecting a stylesheet into it rather than
	// reloading the preview. Getting this wrong shows authors a control the
	// published page will not have.
	const WITH_PREVIEW = {
		attachmentId: 42,
		url: 'http://example.test/curso.elpx',
		title: 'Mi curso',
		hasPreview: true,
		previewUrl: 'http://example.test/preview/index.html',
	};

	const STYLE_ID = 'exelearning-teacher-mode-style';

	/**
	 * Give the preview iframe a document the block can write into.
	 *
	 * The suite runs with happy-dom's iframe page loading disabled (no unit test
	 * should fetch anything), which leaves contentDocument null. A detached
	 * HTML document is the same shape the real same-origin preview exposes.
	 *
	 * @param {HTMLElement} iframe The preview iframe.
	 * @return {Document} The attached document.
	 */
	function attachPreviewDocument( iframe ) {
		const doc = document.implementation.createHTMLDocument( 'preview' );
		const win = { onbeforeunload: null };
		Object.defineProperty( iframe, 'contentDocument', { configurable: true, value: doc } );
		Object.defineProperty( iframe, 'contentWindow', { configurable: true, value: win } );
		return doc;
	}

	/**
	 * Render with a preview whose document the block can reach, and apply.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {Object} The iframe, its document and the render result.
	 */
	function renderWithPreview( attributes ) {
		const result = renderEdit( { ...WITH_PREVIEW, ...attributes } );
		const iframe = result.container.querySelector( 'iframe' );
		const doc = attachPreviewDocument( iframe );
		// The effect bound a load handler on mount; the preview "loading" is what
		// re-runs it now that the document exists.
		fireEvent.load( iframe );
		return { ...result, iframe, doc };
	}

	it( 'hides the toggler while the teacher layer is off', () => {
		const { doc } = renderWithPreview( { teacherModeVisible: false } );

		const style = doc.getElementById( STYLE_ID );
		expect( style ).not.toBeNull();
		expect( style.textContent ).toContain( 'visibility: hidden' );
		expect( style.textContent ).toContain( 'teacher-mode-toggler-wrapper' );
	} );

	it( 'leaves the toggler alone once the teacher layer is on', () => {
		const { doc } = renderWithPreview( { teacherModeVisible: true } );

		expect( doc.getElementById( STYLE_ID ) ).toBeNull();
	} );

	it( 'removes the stylesheet when the author turns the layer on', () => {
		const { doc, rerender } = renderWithPreview( { teacherModeVisible: false } );
		expect( doc.getElementById( STYLE_ID ) ).not.toBeNull();

		rerender(
			element.createElement( blockType.edit, {
				attributes: {
					...WITH_PREVIEW,
					height: 600,
					align: 'none',
					showDownload: false,
					fullscreen: false,
					downloadFormats: [ 'elpx' ],
					teacherModeVisible: true,
				},
				setAttributes: ( update ) => attributeUpdates.push( update ),
				isSelected: true,
			} )
		);

		expect( doc.getElementById( STYLE_ID ) ).toBeNull();
	} );

	it( 'injects the stylesheet only once however often the preview reloads', () => {
		const { doc, iframe } = renderWithPreview( { teacherModeVisible: false } );

		fireEvent.load( iframe );
		fireEvent.load( iframe );

		expect( doc.querySelectorAll( '#' + STYLE_ID ) ).toHaveLength( 1 );
	} );

	it( 'stops the preview from raising a leave-site prompt', () => {
		// An unsaved-changes dialog from the preview would trap the author in the
		// editor, so the block neutralises the handler and locks the property.
		const { iframe } = renderWithPreview( { teacherModeVisible: false } );

		iframe.contentWindow.onbeforeunload = () => 'stay?';

		expect( iframe.contentWindow.onbeforeunload ).toBeNull();
	} );

	it( 'survives a preview it is not allowed to read', () => {
		const result = renderEdit( { ...WITH_PREVIEW, teacherModeVisible: false } );
		const iframe = result.container.querySelector( 'iframe' );
		Object.defineProperty( iframe, 'contentDocument', {
			configurable: true,
			get() {
				throw new Error( 'cross-origin' );
			},
		} );

		expect( () => fireEvent.load( iframe ) ).not.toThrow();
	} );
} );

describe( 'elp-upload: the block toolbar', () => {
	const WITH_FILE = {
		attachmentId: 42,
		url: 'http://example.test/curso.elpx',
		title: 'Mi curso',
	};

	it( 'clears every trace of the file when it is removed', () => {
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByLabelText( 'Remove' ) );

		expect( attributeUpdates ).toEqual( [
			{
				attachmentId: undefined,
				url: undefined,
				previewUrl: undefined,
				title: undefined,
				hasPreview: false,
				teacherModeVisible: false,
			},
		] );
	} );

	it( 'opens the embedded editor for the selected attachment', () => {
		const opened = [];
		window.ExeLearningEditor = { open: ( id ) => opened.push( id ) };
		renderEdit( WITH_FILE );

		fireEvent.click( screen.getByLabelText( 'Edit in eXeLearning' ) );

		expect( opened ).toEqual( [ 42 ] );
	} );

	it( 'does nothing when the editor modal is not loaded', () => {
		renderEdit( WITH_FILE );

		expect( () =>
			fireEvent.click( screen.getByLabelText( 'Edit in eXeLearning' ) )
		).not.toThrow();
	} );
} );
