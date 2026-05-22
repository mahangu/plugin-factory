<?php
/**
 * PHPUnit bootstrap.
 *
 * The gate tests are, by design, INTEGRATION tests: Gate 2 and Gate 3 only
 * mean anything when run against a real WordPress, so the whole suite boots a
 * genuine WordPress 7.0 install and exercises the plugin inside it.
 *
 * The WordPress path comes from the CAW_WP_PATH environment variable so the
 * same suite runs against the local sandbox rig and against the throwaway
 * WordPress that repository CI provisions.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

$caw_wp_path = getenv( 'CAW_WP_PATH' );
if ( false === $caw_wp_path || '' === $caw_wp_path ) {
	$caw_wp_path = '/home/user/wp-test';
}
$caw_wp_path = rtrim( $caw_wp_path, '/' );

$caw_wp_load = $caw_wp_path . '/wp-load.php';
if ( ! is_file( $caw_wp_load ) ) {
	fwrite( STDERR, "Cannot find WordPress at {$caw_wp_path}. Set CAW_WP_PATH.\n" );
	exit( 1 );
}

// Give WordPress a plausible request context for the CLI process.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

define( 'CAW_TEST_WP_PATH', $caw_wp_path );

// Booting WordPress also loads the (active) plugin and its Composer autoloader,
// so every CAW\PluginBuilder class is available to the tests afterwards.
require $caw_wp_load;

require __DIR__ . '/Support/Fixtures.php';
require __DIR__ . '/IntegrationTestCase.php';

if ( ! class_exists( '\CAW\PluginBuilder\Plugin' ) ) {
	fwrite( STDERR, "The CAW Plugin Builder plugin is not active in the test WordPress.\n" );
	exit( 1 );
}
