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

	if ( cer_get_option( 'enable_cpt_args_shim' ) ) {
		ComicEaselRest\CPT_Shim::register();
	}

	if ( cer_get_option( 'enable_rest_namespace' ) ) {
		add_action( 'rest_api_init', array( new ComicEaselRest\REST_Controller(), 'register_routes' ) );
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
 * @param string $option   Option name.
 * @param bool   $autoload Desired autoload state.
 */
function cer_ensure_option_autoload( $option, $autoload = true ) {
	if ( function_exists( 'wp_set_option_autoload' ) ) {
		wp_set_option_autoload( $option, $autoload );
		return;
	}
	global $wpdb;
	$value = $autoload ? 'on' : 'off';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET autoload = %s WHERE option_name = %s",
			$value,
			$option
		)
	);
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