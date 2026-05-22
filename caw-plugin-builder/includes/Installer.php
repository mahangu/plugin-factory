<?php
/**
 * Activation / deactivation installer.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

use CAW\PluginBuilder\Build\BuildRepository;
use CAW\PluginBuilder\Cron\Poller;
use CAW\PluginBuilder\Support\Logger;
use CAW\PluginBuilder\Support\Paths;

/**
 * Provisions everything the plugin needs at activation time and tidies up at
 * deactivation time.
 *
 * The watchdog mu-plugin is installed here, NOT lazily, because it must be in
 * place before the first build is ever installed — it is the lock-out recovery
 * net. It is intentionally left behind on deactivation: it protects plugins
 * this tool installed, and those keep running even when the builder is off.
 */
final class Installer {

	/**
	 * Option storing the emergency panic token for the watchdog control surface.
	 */
	public const OPTION_PANIC_TOKEN = 'caw_panic_token';

	/**
	 * Option storing the installed schema/asset version.
	 */
	private const OPTION_VERSION = 'caw_installed_version';

	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		BuildRepository::install_table();

		// Provision and harden the working directories.
		Paths::staging_root();
		Paths::artifacts_root();

		self::ensure_panic_token();
		self::install_watchdog();

		Poller::schedule();

		update_option( self::OPTION_VERSION, CAW_PB_VERSION, false );
		Logger::info( 'Plugin activated', [ 'version' => CAW_PB_VERSION ] );
	}

	/**
	 * Run deactivation tasks.
	 *
	 * The watchdog and the builds table are deliberately preserved.
	 */
	public static function deactivate(): void {
		Poller::unschedule();
		Logger::info( 'Plugin deactivated' );
	}

	/**
	 * Ensure schema/assets are current; called on every boot to self-heal.
	 */
	public static function maybe_upgrade(): void {
		// The watchdog is lockout-recovery infrastructure. If its file has gone
		// missing — a manual deletion, a half-finished copy — restore it on the
		// next request regardless of version. This is a single is_file() on the
		// hot path, cheap enough to run unconditionally.
		if ( ! self::watchdog_installed() ) {
			self::install_watchdog();
		}

		$installed = get_option( self::OPTION_VERSION, '' );
		if ( CAW_PB_VERSION === $installed ) {
			return;
		}

		BuildRepository::install_table();
		self::ensure_panic_token();
		self::install_watchdog();
		Poller::schedule();

		update_option( self::OPTION_VERSION, CAW_PB_VERSION, false );
		Logger::info( 'Plugin upgraded', [ 'from' => (string) $installed, 'to' => CAW_PB_VERSION ] );
	}

	/**
	 * Copy the watchdog into wp-content/mu-plugins so it loads on every request.
	 *
	 * The file is refreshed whenever its bytes differ, so an upgraded watchdog
	 * is picked up without manual intervention.
	 *
	 * @return bool True when the watchdog is in place.
	 */
	public static function install_watchdog(): bool {
		$source = CAW_PB_DIR . '/mu-plugin/caw-watchdog.php';
		if ( ! is_readable( $source ) ) {
			Logger::error( 'Watchdog source file is missing; lockout protection unavailable' );
			return false;
		}

		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			Logger::error( 'WPMU_PLUGIN_DIR is undefined; cannot install watchdog' );
			return false;
		}

		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			Logger::error( 'Could not create the mu-plugins directory for the watchdog' );
			return false;
		}

		$target = WPMU_PLUGIN_DIR . '/caw-watchdog.php';

		$source_bytes = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$target_bytes = is_file( $target )
			? (string) file_get_contents( $target ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			: '';

		if ( $source_bytes === $target_bytes ) {
			return true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $target, $source_bytes );
		if ( false === $written ) {
			Logger::error( 'Failed to write the watchdog mu-plugin' );
			return false;
		}

		Logger::info( 'Watchdog mu-plugin installed/updated' );
		return true;
	}

	/**
	 * Whether the watchdog mu-plugin is currently installed.
	 *
	 * @return bool True when present.
	 */
	public static function watchdog_installed(): bool {
		return defined( 'WPMU_PLUGIN_DIR' ) && is_file( WPMU_PLUGIN_DIR . '/caw-watchdog.php' );
	}

	/**
	 * Generate the emergency panic token once, if it does not already exist.
	 */
	private static function ensure_panic_token(): void {
		$existing = get_option( self::OPTION_PANIC_TOKEN, '' );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}
		update_option( self::OPTION_PANIC_TOKEN, wp_generate_password( 32, false, false ), false );
	}
}
