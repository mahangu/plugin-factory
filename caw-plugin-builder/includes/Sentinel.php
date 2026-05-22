<?php
/**
 * Activation sentinel + managed-plugin registry.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

/**
 * The shared contract between Gate 3 and the watchdog mu-plugin.
 *
 * Gate 3 sets a sentinel option immediately before it calls activate_plugin().
 * If activation hangs or fatals, the sentinel is left behind. The watchdog
 * mu-plugin — which loads on EVERY request, before any breakable regular
 * plugin — sees the stale sentinel and force-deactivates the offending plugin,
 * so a bad activation can never lock an admin out of wp-admin.
 *
 * The watchdog cannot rely on this class or the Composer autoloader (it runs
 * too early), so it re-declares these option names as literals. The constants
 * here are the single source of truth they must agree on.
 */
final class Sentinel {

	/**
	 * Option holding the in-progress activation sentinel.
	 */
	public const OPTION_ACTIVATING = 'caw_activating';

	/**
	 * Option holding the list of plugin basenames this plugin installed.
	 */
	public const OPTION_MANAGED = 'caw_managed_plugins';

	/**
	 * Seconds after which an activation sentinel is considered stale.
	 */
	public const STALE_SECONDS = 10;

	/**
	 * Mark the start of a guarded activation.
	 *
	 * @param string $plugin_basename Plugin basename being activated.
	 */
	public static function begin_activation( string $plugin_basename ): void {
		update_option(
			self::OPTION_ACTIVATING,
			[
				'plugin' => $plugin_basename,
				'time'   => time(),
			],
			false
		);
	}

	/**
	 * Clear the activation sentinel after a guarded activation finishes.
	 */
	public static function end_activation(): void {
		delete_option( self::OPTION_ACTIVATING );
	}

	/**
	 * The plugin basename currently mid-activation, if any.
	 *
	 * @return string Basename, or '' when no activation is in progress.
	 */
	public static function activating_plugin(): string {
		$sentinel = get_option( self::OPTION_ACTIVATING, [] );
		if ( ! is_array( $sentinel ) || empty( $sentinel['plugin'] ) ) {
			return '';
		}
		return (string) $sentinel['plugin'];
	}

	/**
	 * Record that this plugin installed a given plugin.
	 *
	 * @param string $plugin_basename Plugin basename.
	 */
	public static function track( string $plugin_basename ): void {
		$managed = self::managed();
		if ( ! in_array( $plugin_basename, $managed, true ) ) {
			$managed[] = $plugin_basename;
			update_option( self::OPTION_MANAGED, $managed, false );
		}
	}

	/**
	 * Forget a plugin this plugin installed.
	 *
	 * @param string $plugin_basename Plugin basename.
	 */
	public static function untrack( string $plugin_basename ): void {
		$managed = array_values( array_diff( self::managed(), [ $plugin_basename ] ) );
		update_option( self::OPTION_MANAGED, $managed, false );
	}

	/**
	 * Plugin basenames this plugin has installed.
	 *
	 * @return string[] Managed plugin basenames.
	 */
	public static function managed(): array {
		$managed = get_option( self::OPTION_MANAGED, [] );
		if ( ! is_array( $managed ) ) {
			return [];
		}
		return array_values(
			array_filter(
				array_map( 'strval', $managed ),
				static fn ( string $item ): bool => '' !== $item
			)
		);
	}
}
