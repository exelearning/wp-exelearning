# Changes Needed in eXeLearning for WordPress Integration

This document tracks changes that may need to be applied to the eXeLearning codebase to support WordPress integration.

## Current Status

The WordPress plugin embeds the eXeLearning editor in an iframe, serving static files from the `exelearning/public/` folder. API calls are intercepted and mocked in the browser using a custom fetch interceptor.

## API Mocking Strategy

The following API endpoints are mocked via JavaScript fetch interception (`assets/js/api-mock/mock-interceptor.js`):

### Critical APIs (mocked with full data):
1. **`/api/parameter-management/parameters/data/list`** - Routes and configuration
2. **`/api/translations/{locale}/list`** - UI translations (en, es)
3. **`/api/idevice/installed`** - List of 44 available iDevices
4. **`/api/theme/installed`** - List of 5 themes (base, flux, neo, nova, zen)
5. **`/api/user/preferences`** - User preferences (empty stub)

### Stub APIs (return OK/empty):
- `/api/user/lopd-accepted` - Always OK
- `/api/config/upload-limits` - Returns 100MB limit
- `/api/ode-management/*` - Various save/load operations
- `/api/nav-structure-management/*` - Page structure operations
- `/api/pag-structure-management/*` - Block structure operations
- `/api/idevice-management/*` - iDevice CRUD operations
- All cloud storage APIs - Return NOT_AVAILABLE

## Potential Changes to eXeLearning

### 1. Offline Mode Enhancement (HIGH PRIORITY)

**File:** `public/app/app.js`

The app already checks for `isOfflineInstallation` but still makes API calls. Add a check to skip API calls entirely in offline mode and use bundled defaults.

```javascript
// In loadApiParameters()
async loadApiParameters() {
    if (this.eXeLearning.config.isOfflineInstallation) {
        // Use bundled default routes instead of API call
        this.parameters = await this.loadBundledParameters();
        return;
    }
    // ... existing API call code
}

async loadBundledParameters() {
    // Load from bundled JSON file
    const response = await fetch('/app/data/default-parameters.json');
    return await response.json();
}
```

### 2. Dynamic Modal Creation (MEDIUM PRIORITY)

**File:** `public/app/common/modal.js`

**Issue:** The app expects modal HTML elements to exist in the DOM (modalAlert, modalInfo, modalConfirm, etc.). If they don't exist, errors occur.

**Suggestion:** Create modals dynamically if they don't exist:

```javascript
// In modal initialization
initModal(modalId) {
    let modal = document.getElementById(modalId);
    if (!modal) {
        modal = this.createModalElement(modalId);
        document.body.appendChild(modal);
    }
    return modal;
}

createModalElement(id) {
    const modal = document.createElement('div');
    modal.className = 'modal exe-modal-fade';
    modal.id = id;
    modal.setAttribute('tabindex', '-1');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('data-open', 'false');
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-title"></div>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer"></div>
            </div>
        </div>
    `;
    return modal;
}
```

### 3. Import from URL/Blob (HIGH PRIORITY)

**File:** `public/app/workarea/project/projectManager.js`

Add a method to import an ELP file directly from a URL or Blob for WordPress integration:

```javascript
/**
 * Import an ELP file from a URL
 * @param {string} url - URL to fetch the ELP file from
 * @returns {Promise<void>}
 */
async importFromUrl(url) {
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`Failed to fetch ELP: ${response.status}`);
    }
    const blob = await response.blob();
    return this.importFromBlob(blob, 'import.elp');
}

/**
 * Import an ELP file from a Blob
 * @param {Blob} blob - The ELP file as a blob
 * @param {string} filename - Original filename
 * @returns {Promise<void>}
 */
async importFromBlob(blob, filename = 'import.elp') {
    const file = new File([blob], filename, { type: 'application/zip' });
    return this.importElpDirectly(file);
}
```

### 4. Bundled Static Data (MEDIUM PRIORITY)

Consider bundling static JSON data for offline mode in `public/app/data/`:

**Files to create:**
- `default-parameters.json` - API routes and configuration
- `default-idevices.json` - List of available iDevices
- `default-themes.json` - List of available themes
- `translations/en.json` - English translations
- `translations/es.json` - Spanish translations

These would be loaded when `isOfflineInstallation` is true.

### 5. External Embeddable Mode (LOW PRIORITY)

**File:** `public/app/app.js`

Add a configuration option for "embeddable" mode that:
- Hides certain menu items (File > Open, File > Save As)
- Disables cloud storage integrations
- Exposes a global API for external control

```javascript
// In app initialization
if (this.eXeLearning.config.embeddableMode) {
    this.hideMenuItems(['openUserOdeFiles', 'saveAs', 'cloudStorage']);
    this.exposeExternalApi();
}

exposeExternalApi() {
    window.eXeLearningApi = {
        importFromBlob: (blob) => this.project.importFromBlob(blob),
        exportToBlob: () => this.project.exportToBlob(),
        getProjectData: () => this.project.getProjectData(),
        setProjectData: (data) => this.project.setProjectData(data)
    };
}
```

### 6. ApiCallManager Override Support (LOW PRIORITY)

**File:** `public/app/rest/apiCallManager.js`

Allow external code to provide mock responses:

```javascript
constructor(app) {
    // ... existing code

    // Allow external mock responses
    this.mockResponses = window.eXeLearningMockResponses || {};
}

async getApiParameters() {
    // Check for mock response first
    if (this.mockResponses.parameters) {
        return this.mockResponses.parameters;
    }

    let url = this.apiUrlParameters;
    return await this.func.get(url);
}
```

## WordPress Integration Architecture

### Bootstrap Page Structure

The WordPress bootstrap page (`admin/views/editor-bootstrap.php`) provides:

1. **Mock Configuration** - Before any eXeLearning scripts:
```javascript
window.wpExeMockConfig = {
    baseUrl: '/wp-content/plugins/exelearning/exelearning/public',
    attachmentId: 123,
    elpUrl: 'https://site.com/wp-content/uploads/2024/01/project.elp',
    restUrl: '/wp-json/exelearning/v1',
    nonce: 'wp_rest_nonce'
};
```

2. **Mock Data Files** - Loaded before app.js:
   - `mock-parameters.js` - Routes and config
   - `mock-idevices.js` - 44 iDevices
   - `mock-themes.js` - 5 themes
   - `mock-translations.js` - EN/ES translations
   - `mock-interceptor.js` - Fetch override

3. **eXeLearning Configuration**:
```javascript
window.eXeLearning = {
    version: "0.0.0-wp",
    user: JSON.stringify({ id: 1, username: "admin", ... }),
    config: JSON.stringify({ isOfflineInstallation: true, ... }),
    symfony: JSON.stringify({ basePath: "...", baseURL: "" }),
    projectId: "wp-attachment-123"
};
```

4. **Required DOM Elements**:
   - `#main` (body id)
   - `#workarea` container
   - `#menu_nav` and `#menu_idevices` sidebars
   - `#node-content` main content area
   - Modal elements (modalAlert, modalInfo, modalConfirm, etc.)
   - `#stylessidenav-content` sidebar

### Save Flow

1. User clicks "Save to WordPress" button
2. `ElpxExporter.exportToBlob()` generates the ELP file
3. Blob is uploaded via WordPress REST API:
   ```
   POST /wp-json/exelearning/v1/save/{attachment_id}
   Content-Type: multipart/form-data
   X-WP-Nonce: {nonce}
   Body: file=blob
   ```
4. WordPress replaces the attachment file
5. WordPress re-extracts ELP metadata
6. Parent window is notified via postMessage

### Load Flow

1. WordPress opens editor with `?attachment_id=X`
2. Bootstrap page loads with ELP URL in config
3. eXeLearning app initializes with mocked APIs
4. App imports ELP from WordPress attachment URL
5. Yjs/IndexedDB handles local state

## Testing Checklist

- [ ] Editor loads without console errors
- [ ] iDevices menu populates correctly
- [ ] Can add pages and iDevices
- [ ] Can edit text content
- [ ] Can save to WordPress
- [ ] Metadata updates after save
- [ ] Can re-open and continue editing
