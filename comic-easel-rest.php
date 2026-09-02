<?php
/*
Plugin Name: Comic Easel REST
Plugin URI: https://github.com/Heretek-AI/comic-easel-rest
Description: REST API endpoints for Comic Easel — exposes the comic CPT, chapters/characters/locations taxonomies, and CEO options over WP REST for automation tools (e.g. n8n) using Application Passwords.
Version: 0.1.0
Author: Heretek-AI
Author URI: https://github.com/Heretek-AI
Text Domain: comic-easel-rest
Domain Path: /languages
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires at least: 6.0
Requires PHP: 8.1
Update URI: https://github.com/Heretek-AI/comic-easel-rest

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CER_VERSION', '0.1.0' );
define( 'CER_FILE', __FILE__ );
define( 'CER_PATH', plugin_dir_path( __FILE__ ) );
define( 'CER_URL', plugin_dir_url( __FILE__ ) );
define( 'CER_OPTION_KEY', 'comic-easel-rest-settings' );
define( 'CER_REST_NAMESPACE', 'comic-easel/v1' );

$cer_autoload = CER_PATH . 'vendor/autoload.php';
if ( file_exists( $cer_autoload ) ) {
	require_once $cer_autoload;
} else {
	require_once CER_PATH . 'includes/class-cpt-shim.php';
	require_once CER_PATH . 'includes/class-rest-controller.php';
}

require_once CER_PATH . 'functions/helpers.php';
require_once CER_PATH . 'functions/settings.php';

register_activation_hook( __FILE__, 'cer_activation' );
register_deactivation_hook( __FILE__, 'cer_deactivation' );
register_uninstall_hook( __FILE__, 'cer_uninstall' );

add_action( 'plugins_loaded', 'cer_boot' );
add_action( 'plugins_loaded', 'cer_load_textdomain' );

/**
 * Boot the companion plugin. Wires the CPT-args shim and the REST controller
 * if the parent comic-easel plugin is loaded.
 */
function cer_boot() {
	// comic-easel defines ceo_pluginfo() at plugin load, but registers the
	// `comic` CPT on `init` — after `plugins_loaded`. Checking the post type
	// here would make the whole companion a no-op, so gate only on the parent
	// plugin being present.
	if ( ! function_exists( 'ceo_pluginfo' ) ) {
		add_action( 'admin_notices', 'cer_parent_missing_notice' );
		return;
	}

	cer_init_option_defaults();

	// Always force the classic editor for the comic CPT. The parent
	// comic-easel plugin registers seven classic-style meta boxes (HTML above/
	// below, transcript, hovertext, buy print, etc.) and the editing UX is
	// built around them. Forcing classic avoids the block-editor iframe-mode
	// codepath entirely, which on WP 6.9 / 7.x collides with the deprecation
	// warnings from third-party blocks registered at apiVersion < 3.
	add_filter( 'use_block_editor_for_post_type', 'cer_force_classic_editor_for_comic', 10, 2 );

	if ( cer_get_option( 'enable_cpt_args_shim' ) ) {
		ComicEaselRest\CPT_Shim::register();
	}

	if ( cer_get_option( 'enable_rest_namespace' ) ) {
		add_action( 'rest_api_init', array( new ComicEaselRest\REST_Controller(), 'register_routes' ) );
		// Register the comic CPT meta fields used by the parent plugin's
		// ceo_html_below_comic meta box handler (and historically by the
		// n8n Twitter-to-comic workflow). Direct get_post_meta /
		// update_post_meta calls work without show_in_rest; we intentionally
		// leave it off to avoid crossing the block-editor iframe-mode
		// threshold on WP 6.9 / 7.x. See the docblock on
		// cer_register_comic_meta_for_rest() for the full rationale.
		cer_register_comic_meta_for_rest();
	}
}

/**
 * Load the text domain for translations.
 */
function cer_load_textdomain() {
	load_plugin_textdomain(
		'comic-easel-rest',
		false,
		dirname( plugin_basename( CER_FILE ) ) . '/languages/'
	);
}

/**
 * Register the comic CPT meta fields used by automation tools (e.g. the n8n
 * Twitter-to-comic workflow) and the parent plugin's `ceo_html_below_comic`
 * meta box handler.
 *
 * Each meta is `single` (one value per post) and registered under its plain
 * key. `auth_callback` permits any user who can edit the post, matching the
 * comic REST controller's permission scheme.
 *
 * Note: we intentionally do NOT set `show_in_rest => true`. On WP 6.9 / 7.x
 * the block editor's iframe-mode preload path treats a CPT as REST-active
 * when any of its meta is REST-visible, and on sites where other plugins
 * register blocks with `apiVersion < 3` this combination makes the iframe
 * editor fail to initialise (`global-styles-css-custom-properties-inline-css
 * was added to the iframe incorrectly`) and silently fall back to the
 * classic editor. Keeping these keys out of the REST schema avoids that
 * trigger while leaving direct `get_post_meta` / `update_post_meta` calls —
 * which is what the parent plugin and this plugin's own endpoints use —
 * fully functional.
 */
function cer_register_comic_meta_for_rest() {
	$slug = cer_resolve_comic_slug();
	foreach ( array( 'source_tweet_id', 'source_url', 'ceo_html_below_comic' ) as $key ) {
		register_post_meta(
			$slug,
			$key,
			array(
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}

/**
 * Force the classic editor for the comic CPT regardless of WP defaults.
 *
 * WP 6.9 / 7.x evaluate the block editor's iframe-mode preload path against
 * REST-visible meta on the post type. On sites where third-party plugins
 * register blocks with `apiVersion < 3` (e.g. `olympus-google-fonts/google-fonts`,
 * `emcp/post-title`, `wp-quads/adds`), the iframe editor fails to initialise
 * with `global-styles-css-custom-properties-inline-css was added to the iframe
 * incorrectly` and silently falls back to the classic editor. The parent
 * comic-easel plugin registers several classic-style meta boxes
 * (`ceo_toggle_in_post`, `ceo_html_below_comic`, `ceo_transcript_in_post`,
 * etc.) so the editing UX is built around classic; opting in here is the
 * path of least resistance.
 *
 * The filter signature is `use_block_editor_for_post_type( $use_block_editor, $post_type )`
 * since WP 5.6; we accept `$post_type` directly so we don't need to consult
 * the global screen state (which can be empty on early `init` calls).
 *
 * @param bool   $use_block_editor Whether the block editor is enabled for
 *                                 the given post type. WP default is true
 *                                 on any post type that supports 'editor'.
 * @param string $post_type        The post type slug being evaluated.
 * @return bool
 */
function cer_force_classic_editor_for_comic( $use_block_editor, $post_type = '' ) { // NOSONAR: cer_* snake_case is the project naming convention.
	// Defensive: older WP releases pass only one argument. Fall back to the
	// admin screen's post type when $post_type is empty.
	if ( '' === $post_type && is_admin() && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && isset( $screen->post_type ) ) {
			$post_type = $screen->post_type;
		}
	}
	if ( '' !== $post_type && cer_resolve_comic_slug() === $post_type ) {
		return false;
	}
	return $use_block_editor;
}

/**
 * Initialise the plugin's option defaults. Called on activation and on every
 * boot so a fresh install picks up defaults without requiring activation.
 * Also ensures the option is autoloaded — settings are read on every request.
 */
function cer_init_option_defaults() {
	$existing = get_option( CER_OPTION_KEY, array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	$defaults = array(
		'enable_cpt_args_shim'     => true,
		'enable_rest_namespace'    => true,
		'enable_settings_endpoint' => true,
		'enable_bulk_import'       => true,
		'enable_throttle'          => true,
		'throttle_per_minute'      => 60,
	);
	$merged = array_merge( $defaults, $existing );
	if ( $merged !== $existing ) {
		// Autoload yes: the settings are read on every request.
		update_option( CER_OPTION_KEY, $merged, true );
	} else {
		// Values already match, but autoload may still be off (e.g. option
		// was written by an older version without the autoload arg). Flip
		// it to 'yes' without touching the value.
		cer_ensure_option_autoload( CER_OPTION_KEY, true );
	}
}

/**
 * Set the autoload flag on a single option without changing its value.
 * Uses wp_set_option_autoload() when available (WordPress 6.4+); falls back
 * to a direct $wpdb update for 5.6–6.3 so the plugin still ships the
 * autoload fix to older installs.
 *
 * Caller should already know the autoload differs from the desired value;
 * this function performs the write unconditionally and invalidates the
 * Options API alloptions object cache so persistent object caches see
 * the change on the next request.
 *
 * @param string $option   Option name.
 * @param bool   $autoload Desired autoload state.
 */
function cer_ensure_option_autoload( $option, $autoload = true ) { // NOSONAR: cer_* snake_case is the project naming convention.
	if ( function_exists( 'wp_set_option_autoload' ) ) {
		wp_set_option_autoload( $option, $autoload );
		return;
	}
	global $wpdb;
	// WP 5.6-6.3 stored 'yes' / 'no' in the autoload column. WP 6.7+
	// normalises to 'on' / 'off', but wp_set_option_autoload (above) is
	// available from WP 6.4 so this fallback only runs on the legacy
	// spellings.
	$value = $autoload ? 'yes' : 'no';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = %s WHERE option_name = %s",
			$value,
			$option
		)
	);
	// Invalidate the Options API alloptions cache so persistent object
	// caches don't keep serving the old autoload value on the next
	// request. update_option() handles this internally; our $wpdb path
	// bypasses that helper.
	wp_cache_delete( 'alloptions', 'options' );
}

/**
 * Activation hook: set defaults, flush rewrite rules.
 */
function cer_activation() {
	cer_init_option_defaults();
	flush_rewrite_rules( false );
}

/**
 * Deactivation hook: flush rewrite rules so any cached show_in_rest URLs are
 * invalidated.
 */
function cer_deactivation() {
	flush_rewrite_rules( false );
}

/**
 * Uninstall hook: delete the plugin's option. The actual uninstall.php file
 * also deletes it; this function exists so the registered hook is callable.
 */
function cer_uninstall() {
	delete_option( CER_OPTION_KEY );
}

/**
 * Admin notice shown when the parent plugin is missing.
 */
function cer_parent_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Comic Easel REST requires the Comic Easel plugin to be installed and active.', 'comic-easel-rest' );
	echo '</p></div>';
}