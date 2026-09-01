<?php
/**
 * PHPUnit bootstrap for Comic Easel REST.
 *
 * Uses the standard WordPress test suite (WP_UnitTestCase,
 * WP_Test_REST_TestCase) loaded via bin/install-wp-tests.sh. Requires
 * WP_TESTS_DIR to be set (it is by install-wp-tests.sh).
 *
 * @package ComicEaselREST
 */

// ABSPATH and WPINC must be defined before requiring any WP files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'WP_TESTS_DIR' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once WP_TESTS_DIR . '/includes/functions.php';

// Both the parent plugin (comic-easel) and the companion must be installed
// into /tmp/wordpress/wp-content/plugins by bin/install-wp-tests.sh. The WP
// test bootstrap reads `wp_tests_options['active_plugins']` to know what to
// load, so we list both here. The order matters: parent first so its CPT
// is registered before our shim filters run.
$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => array(
		'comic-easel/comiceasel.php',
		'comic-easel-rest/comic-easel-rest.php',
	),
);

require WP_TESTS_DIR . '/includes/bootstrap.php';