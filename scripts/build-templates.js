#!/usr/bin/env node
/**
 * Build script for compiling eXeLearning Nunjucks templates
 *
 * This script compiles the workarea.njk template and its includes into
 * HTML files that can be used by WordPress.
 *
 * Usage: node scripts/build-templates.js
 */

const nunjucks = require('nunjucks');
const fs = require('fs');
const path = require('path');

// Paths
const VIEWS_DIR = path.join(__dirname, '../exelearning/views');
const OUTPUT_DIR = path.join(__dirname, '../dist');

// Ensure output directory exists
if (!fs.existsSync(OUTPUT_DIR)) {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });
}

// Configure Nunjucks with lenient settings
const env = nunjucks.configure(VIEWS_DIR, {
    autoescape: false,
    throwOnUndefined: false,
    trimBlocks: true,
    lstripBlocks: true
});

// Add asset filter - ensures path starts with /
env.addFilter('asset', function(path) {
    // Ensure path starts with / for proper URL concatenation
    if (!path.startsWith('/')) {
        path = '/' + path;
    }
    return '{{ASSET_BASE_URL}}' + path;
});

// Add default filter
env.addFilter('default', function(value, defaultValue) {
    return value !== undefined && value !== null ? value : (defaultValue || '');
});

// Add dump filter (for JSON serialization like Twig)
env.addFilter('dump', function(value) {
    return JSON.stringify(value);
});

// Create a Proxy-based object that returns empty string for any undefined property
function createSafeContext() {
    const handler = {
        get(target, prop) {
            if (prop in target) {
                const value = target[prop];
                if (typeof value === 'object' && value !== null) {
                    return new Proxy(value, handler);
                }
                return value;
            }
            // Return empty string for undefined properties
            return '';
        },
        has(target, prop) {
            return true; // Pretend all properties exist
        }
    };

    const translations = {
        // Common
        file: 'File', new: 'New', open: 'Open', save: 'Save', close: 'Close',
        cancel: 'Cancel', confirm: 'Confirm', accept: 'Accept', ok: 'OK',
        yes: 'Yes', no: 'No', delete: 'Delete', edit: 'Edit', add: 'Add',

        // File menu
        new_from_template: 'New from Template...',
        recent_projects: 'Recent projects',
        import_elpx: 'Import (.elpx...)',
        save_as: 'Save as',
        download_as: 'Download as...',
        export_as: 'Export as...',
        export_to_folder: 'Export to Folder',
        exelearning_content: 'eXeLearning content (.elpx)',
        website: 'Website',
        single_page: 'Single page',
        print: 'Print',
        upload_to: 'Upload to',
        metadata: 'Metadata',
        import: 'Import',
        export: 'Export',

        // Utilities menu
        utilities: 'Utilities',
        preview: 'Preview',
        idevice_manager: 'iDevice manager',
        resources_report: 'Resources report',
        link_validation: 'Link validation',
        file_manager: 'File manager',

        // Help menu
        help: 'Help',
        assistant: 'Assistant',
        user_manual: 'User manual',
        about_exelearning: 'About eXeLearning',
        release_notes: 'Release notes',
        legal_notes: 'Legal notes',
        exelearning_website: 'eXeLearning website',
        report_bug: 'Report a bug',

        // Structure
        structure: 'Structure',
        add_page: 'Add page',
        add_subpage: 'Add subpage',
        delete_page: 'Delete page',
        rename_page: 'Rename page',
        duplicate_page: 'Duplicate page',
        move_up: 'Move up',
        move_down: 'Move down',

        // iDevices
        idevices: 'iDevices',
        search_idevices: 'Search iDevices...',

        // Top menu
        download: 'Download',
        styles: 'Styles',
        settings: 'Settings',
        logout: 'Logout',
        toggle_panels: 'Toggle panels',
        preferences: 'Preferences',
        last_edition: 'Last saved',

        // Properties
        properties: 'Properties',
        general: 'General',
        title: 'Title',
        author: 'Author',
        description: 'Description',
        language: 'Language',

        // Style manager
        style_manager: 'Style manager',
        base_styles: 'Base styles',
        user_styles: 'User styles',

        // Session
        session_logout: 'Session logout',
        session_expired: 'Your session has expired',

        // Share
        share: 'Share',
        visibility: 'Visibility',
        private: 'Private',
        public: 'Public',
        copy_link: 'Copy link',

        // About
        version: 'Version',

        // LOPD
        privacy_policy: 'Privacy policy',
        accept_continue: 'Accept and continue'
    };

    const config = {
        isOfflineInstallation: true,
        enableCollaborativeEditing: false,
        userStyles: false,
        userIdevices: false,
        platformIntegration: false,
        platformName: '',
        defaultTheme: 'base'
    };

    const user = {
        email: '',
        name: 'User',
        roles: ['ROLE_USER']
    };

    const context = {
        t: new Proxy(translations, handler),
        config: new Proxy(config, handler),
        user: new Proxy(user, handler),
        symfony: new Proxy({
            basePath: '',
            baseURL: '',
            fullURL: '',
            locale: 'en',
            themeBaseType: 'base',
            themeTypeBase: 'base',
            themeTypeUser: 'user'
        }, handler),
        // Root-level variables used in templates
        locale: 'en',
        version: '{{VERSION}}',
        app_version: '{{VERSION}}',
        expires: '',
        extension: 'elpx',
        projectId: null,
        mercure: null
    };

    return new Proxy(context, handler);
}

console.log('Compiling Nunjucks templates...\n');

const context = createSafeContext();

// Helper to compile a template safely
function compileTemplate(templatePath, outputPath) {
    try {
        const html = env.render(templatePath, context);
        fs.writeFileSync(outputPath, html, 'utf8');
        console.log(`✓ ${templatePath}`);
        return true;
    } catch (err) {
        console.warn(`✗ ${templatePath}: ${err.message.split('\n')[0]}`);
        return false;
    }
}

// Compile main workarea template
compileTemplate('workarea/workarea.njk', path.join(OUTPUT_DIR, 'workarea.html'));

// Create components directory
const componentsDir = path.join(OUTPUT_DIR, 'components');
if (!fs.existsSync(componentsDir)) {
    fs.mkdirSync(componentsDir, { recursive: true });
}

// Compile menu components
const menuComponents = [
    'workarea/menus/menuNavbar.njk',
    'workarea/menus/menuHeadTop.njk',
    'workarea/menus/menuHeadBottom.njk',
    'workarea/menus/menuStructure.njk',
    'workarea/menus/menuIdevices.njk'
];

for (const comp of menuComponents) {
    const outputName = path.basename(comp).replace('.njk', '.html');
    compileTemplate(comp, path.join(componentsDir, outputName));
}

// Compile modals
const modalsDir = path.join(OUTPUT_DIR, 'modals');
if (!fs.existsSync(modalsDir)) {
    fs.mkdirSync(modalsDir, { recursive: true });
}

// Generic modals
const genericModals = fs.readdirSync(path.join(VIEWS_DIR, 'workarea/modals/generic'))
    .filter(f => f.endsWith('.njk'));

for (const modal of genericModals) {
    compileTemplate(
        `workarea/modals/generic/${modal}`,
        path.join(modalsDir, modal.replace('.njk', '.html'))
    );
}

// Page modals
const pageModals = fs.readdirSync(path.join(VIEWS_DIR, 'workarea/modals/pages'))
    .filter(f => f.endsWith('.njk'));

for (const modal of pageModals) {
    compileTemplate(
        `workarea/modals/pages/${modal}`,
        path.join(modalsDir, modal.replace('.njk', '.html'))
    );
}

// Compile toast template
compileTemplate(
    'workarea/toast/toastDefault.njk',
    path.join(componentsDir, 'toastDefault.html')
);

console.log('\n✓ Template compilation complete!');
console.log(`  Output directory: ${OUTPUT_DIR}`);
