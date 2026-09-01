=== Comic Easel REST ===
Contributors: heretek-ai
Tags: rest-api, comic-easel, automation, n8n, application-passwords
Requires at least: 6.0
Requires PHP: 8.1
Tested up to: 6.6
Requires Plugins: comic-easel
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: comic-easel-rest

Companion REST API for Comic Easel — exposes comics, chapters, and CEO options over WP REST for automation tools using Application Passwords.

== Description ==

Comic Easel REST is a companion plugin to [Comic Easel](https://github.com/Frumph/comic-easel) that exposes the comic custom post type, its taxonomies (chapters, characters, locations), and the plugin's settings through the WordPress REST API under the `comic-easel/v1` namespace.

The primary use case is author automation: post and manage comics from external tools (n8n, Zapier, custom scripts) using **WordPress Application Passwords** as the credential. No cookies, no nonce, no wp-admin UI needed.

= Endpoints =

* `POST /wp-json/comic-easel/v1/comics/with-thumbnail` — create a comic post with a featured-image upload in one request.
* `POST /wp-json/comic-easel/v1/chapters` — create a chapter term.
* `POST /wp-json/comic-easel/v1/comics/schedule` — schedule a comic for future publish (`post_status=future` + ISO 8601 `post_date_gmt`).
* `GET /wp-json/comic-easel/v1/settings` — read whitelisted plugin options.
* `POST /wp-json/comic-easel/v1/settings` — update whitelisted plugin options (requires `manage_options`).
* `POST /wp-json/comic-easel/v1/comics/bulk-import` — import a batch of comics under a chapter.

The companion also flips `show_in_rest` on the existing `comic` CPT and the three taxonomies, so `/wp-json/wp/v2/comic`, `/wp-json/wp/v2/chapters`, `/wp-json/wp/v2/characters`, and `/wp-json/wp/v2/locations` become available automatically.

= Authentication =

This plugin requires **Application Passwords** (shipped in WordPress core since 5.6). The plugin will display an admin notice if the site is not on HTTPS, since Application Passwords are disabled over plain HTTP outside of the `local` environment type.

For automation users, create a dedicated low-privilege account (role: `author` or `editor`) and generate an Application Password for it. The plugin reads user capabilities normally — there are no per-token scopes in WordPress core yet.

== Installation ==

1. Install and activate the [Comic Easel](https://github.com/Frumph/comic-easel) plugin first.
2. Upload this plugin's folder to `/wp-content/plugins/comic-easel-rest/`.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Ensure your site uses HTTPS (Application Passwords require it on production sites).
5. For each automation user, go to **Users → Profile → Application Passwords** and generate a password. Use that password as the Basic Auth credential from your automation tool.

== Frequently Asked Questions ==

= Does this plugin work without Comic Easel? =

No. Comic Easel REST depends on Comic Easel and will display an admin notice if the parent plugin is not active.

= Can I publish to wp.org with this? =

The plugin is GPLv3+ compatible and structured for wp.org submission (proper plugin headers, text domain matches slug, scoped phpcs). However, v0.1 ships from GitHub only; wp.org submission is a separate task.

= Will this conflict with other REST plugins? =

It uses its own namespace (`comic-easel/v1`) so it should not conflict with anything else. The CPT-args shim hooks at priority 99, after comic-easel's default 10.

== Changelog ==

= 0.1.0 =
* Initial release.
* CPT-args shim exposes comic + chapters/characters/locations to REST.
* Five companion endpoints under `comic-easel/v1`.
* PHPUnit + PHPCS test suite; CI on GitHub Actions.

== Upgrade Notice ==

= 0.1.0 =
First release.