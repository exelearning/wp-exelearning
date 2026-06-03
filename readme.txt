=== eXeLearning ===
Contributors: intef
Tags: elearning, education, elpx, exelearning, learning
Requires at least: 6.1
Tested up to: 7.0
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

`id` (required), `height`, `teacher_mode`, `teacher_mode_visible`, `show_download`, `download_formats` and `screenshot`. See the full shortcode reference at https://github.com/exelearning/wp-exelearning/blob/main/docs/SHORTCODES.md.

= How do I start content in teacher mode? =

Add `teacher_mode="1"` to the shortcode, for example `[exelearning id="123" teacher_mode="1"]`. Use `teacher_mode_visible="0"` to also hide the teacher-mode toggle.

= How do I show the package screenshot? =

Use `screenshot="poster"` to show the screenshot as a clickable poster that loads the content on click, or `screenshot="only"` to show just the image. Packages built with eXeLearning 4.0.1 or newer ship a screenshot; older packages fall back to the normal embed.

= Are there developer hooks? =

Yes. The plugin exposes actions and filters (all prefixed with `exelearning_`) for ELPX extraction, metadata, REST saves, shortcode rendering, styles and editor installation. See https://github.com/exelearning/wp-exelearning/blob/main/docs/HOOKS.md.

== Changelog ==

= 0.0.0 =
* Initial release
* Add developer lifecycle hooks (actions and filters) for ELPX extraction, metadata, REST saves, shortcode rendering, styles, and static editor installation. See docs/HOOKS.md.
