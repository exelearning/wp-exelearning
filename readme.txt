=== eXeLearning ===
Contributors: intef
Tags: elearning, education, elpx, exelearning, learning
Requires at least: 6.1
Tested up to: 7.1
Stable tag: 0.0.0
Requires PHP: 8.0
License: AGPLv3 or later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

WordPress plugin for eXeLearning content management. Upload, manage and embed eXeLearning .elpx files.

== Description ==

eXeLearning is a WordPress plugin that allows you to upload, manage and embed eXeLearning .elpx files directly in your WordPress site.

For more information, see the [full documentation on GitHub](https://github.com/exelearning/wp-exelearning).

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/exelearning` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.

== Frequently Asked Questions ==

= How do I embed an eXeLearning file? =

Upload your `.elpx` file to the Media Library, then embed it either with the eXeLearning Gutenberg block or with the shortcode `[exelearning id="123"]`, replacing `123` with the attachment ID of your file. You can also find this help under Settings → eXeLearning.

= What attributes does the [exelearning] shortcode support? =

`id` (required), `width` and `height` (pixels or percentages), `teacher_mode`, `teacher_mode_visible`, `show_download`, `download_formats`, `screenshot` and `fullscreen`. See the full shortcode reference at https://github.com/exelearning/wp-exelearning/blob/main/docs/SHORTCODES.md.

= How do I reveal teacher-only content? =

eXeLearning packages hide teacher-only content by default, and the teacher layer selector is also hidden unless you enable it. Add `teacher_mode_visible="1"` to the shortcode, for example `[exelearning id="123" teacher_mode_visible="1"]`, to make that selector available; the viewer then turns it on. A bare embed keeps the selector hidden.

= How do I show the package screenshot? =

Use `screenshot="poster"` to show the screenshot as a clickable poster that loads the content on click, or `screenshot="only"` to show just the image. Packages built with eXeLearning 4.0.1 or newer ship a screenshot; older packages fall back to the normal embed.

= Are there developer hooks? =

Yes. The plugin exposes actions and filters (all prefixed with `exelearning_`) for ELPX extraction, metadata, REST saves, shortcode rendering and styles. See https://github.com/exelearning/wp-exelearning/blob/main/docs/HOOKS.md.

== External services ==

ELPX uploads and storage are handled on your WordPress site. The bundled editor
runs locally in the browser, but some editor features and embedded content make
requests to external providers. Requests can occur while editing, previewing or
viewing published content, including content created by another author.

For each browser request, the provider receives the requested resource or content
identifier, the user's IP address and browser request headers. Depending on browser
settings and the provider, requests may also include a referrer and cookies; embedded
services may collect interactions within their content. Account and access
requirements depend on the provider and the selected content. The plugin does not
require a third-party account for uploading or editing local ELPX files.

The features below use external services or remote resources. Listing a resource
here describes its use; it does not make a CDN-hosted library an external service.

* **YouTube** — used by the media and video iDevices. When an author embeds a
YouTube video, the editor loads the YouTube iframe player API and the video is
played from youtube.com; the visitor's browser and IP address reach YouTube like
they would on any page with a YouTube embed. Terms: https://www.youtube.com/t/terms
Privacy: https://policies.google.com/privacy

* **Vimeo** — same as above for videos hosted on Vimeo, played from
player.vimeo.com. Terms: https://vimeo.com/terms
Privacy: https://vimeo.com/privacy

* **GeoGebra** — used by the GeoGebra iDevice. When an author loads an
activity by URL or identifier, the material identifier is sent
to https://www.geogebra.org/api/json.php, and the applet is then loaded from
geogebra.org for the visitor. Terms: https://www.geogebra.org/tos
Privacy: https://www.geogebra.org/privacy

* **H5P** — when an author embeds an H5P activity, the activity is loaded from
the site that hosts it (h5p.org, or the WordPress site the author copied the
embed code from), when the iframe is loaded. Activity identifiers and interactions
within the iframe go to that host. Its own access rules, terms and privacy policy
apply; H5P is a format, not a single hosting provider.
H5P.org content licensing: https://h5p.org/licensing
H5P Hub terms (for content hosted by that service): https://h5p.org/node/1075432
H5P.org privacy: https://h5p.org/privacy

* **RCSB Protein Data Bank and PubChem** — used by the 3D molecule iDevice. When
an author or a visitor loads a structure by its identifier, that identifier is
requested from https://files.rcsb.org or https://pubchem.ncbi.nlm.nih.gov.
RCSB PDB policies: https://www.rcsb.org/pages/policies
PubChem (NCBI) policies: https://www.ncbi.nlm.nih.gov/home/about/policies/

* **Google (gstatic.com)** — the 3D model viewer iDevice loads the Draco and
KTX2 decoders from https://www.gstatic.com when, and only when, the 3D model the
author added uses those compression formats.
Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

* **jsDelivr** — the bundled MathJax loads its speech-rule engine from
https://cdn.jsdelivr.net when a reader turns on the math accessibility (speech)
features of a formula. Terms and privacy: https://www.jsdelivr.com/terms
https://www.jsdelivr.com/terms/privacy-policy

* **EducaMadrid Mediateca** — the interactive video iDevice loads the JW Player
script from https://mediateca.educa.madrid.org when its editing page is opened,
even before an EducaMadrid video is selected. Playing hosted media also requests
the selected media URL. Some hosted content requires an EducaMadrid account.
Terms, privacy and cookies: https://mediateca.educa.madrid.org/aviso-legal

* **X (Twitter) and Facebook** — the bundled legacy lightbox includes optional share
widgets from platform.twitter.com and www.facebook.com. The editor's normal
lightbox initialization disables them (`social_tools: ""`). Content that enables
these widgets can send the page URL and browser request data to those providers
when the lightbox opens, before a visitor clicks a share button.
X terms: https://x.com/en/tos  Privacy: https://x.com/en/privacy
Facebook terms: https://www.facebook.com/terms.php
Privacy: https://www.facebook.com/privacy/policy/

== Source Code ==

The plugin's own PHP and JavaScript ship as human-readable source in this package.

The bundled eXeLearning editor (the `dist/static` directory) is a compiled artifact: minified JavaScript bundles, compiled stylesheets, and compressed non-executable data files (curricular datasets and editor data shipped as `*.json.zst`/`*.json.gz`, decoded in the browser by the bundled `fzstd` library). Its complete, non-compiled source code and build tools are publicly maintained at:

https://github.com/exelearning/exelearning

Each plugin release records the bundled editor version in the `.editor-version` file and the exact source commit in `dist/static/.build-commit`. The editor is built reproducibly from that repository with `make build-static` (Bun; see `scripts/build-static-bundle.ts`).

The plugin itself is developed at https://github.com/exelearning/wp-exelearning. Release packages are produced with `make package`, which builds the editor from source (`make build-editor`) and assembles the ZIP with `wp dist-archive`.

== Changelog ==

= 0.0.0 =
* Initial release
* Document external services and remote resources used by editor features, and under
  which conditions, in the new "External services" section of this readme.
* Ship the shortcode and block embed behavior (fullscreen button, click-to-load
  poster) as an enqueued script instead of an inline <script> printed once per
  embed; percentage heights are now plain CSS (aspect-ratio) with no script at all.
* Stop setting error_reporting()/display_errors for the editor screen request,
  and drop the wp-admin includes the upload paths never used.
* Add developer lifecycle hooks (actions and filters) for ELPX extraction, metadata, REST saves, shortcode rendering, and styles. See docs/HOOKS.md.
* The embedded editor is bundled exclusively in release packages; the runtime editor installer/updater was removed (ADR-72-01).
* Shortcode viewer: add a `fullscreen` attribute to show/hide the fullscreen button, support percentage `height` values, and render the Download and Fullscreen toolbar controls consistently with accessible names and correctly enqueued frontend styles and Dashicons.
* readme: add a Source Code section documenting the public repositories and build tools for the bundled compiled editor and the plugin (WordPress.org human-readable-code guideline).
