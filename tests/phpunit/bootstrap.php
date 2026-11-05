<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library (see bin/install-wp-tests.sh)
 * and the plugin as a must-use plugin.
 *
 * @package Feriae
 */

$feriae_root      = dirname( __DIR__, 2 );
$feriae_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $feriae_root . '/.wp-tests/wordpress-develop/tests/phpunit';

if ( ! file_exists( $feriae_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Run: composer test:install\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}
if ( ! file_exists( $feriae_root . '/build/blocks-manifest.php' ) ) {
	fwrite( STDERR, "Plugin assets not built. Run: npm run build\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $feriae_root . '/vendor/yoast/phpunit-polyfills' );

require_once $feriae_tests_dir . '/includes/functions.php';

// Enable every bundled module so their tables are created at boot, outside the
// per-test transactions.
tests_add_filter( 'pre_option_feriae_modules', static fn() => array( 'ical', 'recurrence', 'series', 'rsvp', 'tickets', 'feeds' ) );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $feriae_root ): void {
		require $feriae_root . '/feriae.php';
	}
);

require $feriae_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
