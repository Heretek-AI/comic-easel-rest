<?php
/**
 * PHPUnit bootstrap for Comic Easel REST.
 *
 * Uses the standard WordPress test suite (WP_UnitTestCase,
 * WP_Test_REST_TestCase) loaded via bin/install-wp-tests.sh, which also
 * installs WP core + plugins and generates the required wp-tests-config.php
 * (ABSPATH and DB constants included). WP_TESTS_DIR is honoured from the
 * environment when set (default /tmp/wordpress-tests-lib).
 *
 * @package ComicEaselREST
 */

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
	$wp_tests_dir = '/tmp/wordpress-tests-lib';
}
if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found at {$wp_tests_dir}. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}
define( 'WP_TESTS_DIR', $wp_tests_dir );

require_once WP_TESTS_DIR . '/includes/functions.php';

// The WP test suite (6.5+) requires the PHPUnit Polyfills library. It ships
// as a dev dependency of this plugin, so load its autoloader before the WP
// test bootstrap and avoid the suite's default path lookup.
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

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