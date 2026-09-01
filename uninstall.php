<?php
/**
 * Uninstall handler for Comic Easel REST.
 *
 * Removes the single autoloaded option we own. Does not touch the parent
 * comic-easel plugin's data.
 *
 * @package ComicEaselREST
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'comic-easel-rest-settings' );