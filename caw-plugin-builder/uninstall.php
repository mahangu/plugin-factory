<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the plugin is deleted from wp-admin. Removes everything the
 * plugin created: the builds table, its options, its working directories, and
 * — when nothing it installed is still active — the watchdog mu-plugin.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the builds table.
$caw_table = $wpdb->prefix . 'caw_builds';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$caw_table}" );

// Remove plugin options.
$caw_options = [
	'caw_activating',
	'caw_managed_plugins',
	'caw_panic_token',
	'caw_watchdog_recovery',
	'caw_installed_version',
	'caw_environments',
	'caw_agents',
	'caw_legacy_api_key',
];
foreach ( $caw_options as $caw_option ) {
	delete_option( $caw_option );
}

// Clear any scheduled poll events.
wp_clear_scheduled_hook( 'caw_poll_builds' );

// Remove the working directories under uploads.
$caw_uploads = wp_get_upload_dir();
if ( empty( $caw_uploads['error'] ) ) {
	foreach ( [ 'caw-staging', 'caw-artifacts' ] as $caw_dir ) {
		$caw_path = rtrim( $caw_uploads['basedir'], '/\\' ) . '/' . $caw_dir;
		if ( is_dir( $caw_path ) ) {
			$caw_items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $caw_path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $caw_items as $caw_item ) {
				if ( $caw_item->isDir() ) {
					@rmdir( $caw_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors
				} else {
					@unlink( $caw_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors
				}
			}
			@rmdir( $caw_path ); // phpcs:ignore WordPress.PHP.NoSilentErrors
		}
	}
}

// Remove the watchdog mu-plugin only if nothing it installed is still active.
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$caw_watchdog = WPMU_PLUGIN_DIR . '/caw-watchdog.php';
	if ( is_file( $caw_watchdog ) ) {
		@unlink( $caw_watchdog ); // phpcs:ignore WordPress.PHP.NoSilentErrors
	}
}
