<?php
/**
 * Plugin orchestrator.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

use CAW\PluginBuilder\Admin\AdminPage;
use CAW\PluginBuilder\Cron\Poller;

/**
 * Central wiring for the plugin.
 *
 * Kept deliberately thin: it self-heals the install on boot, registers the
 * cron poller, and hands the admin surface to AdminPage. Everything heavy
 * lives in its own focused class.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin The instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Wire the plugin into WordPress. Called on plugins_loaded.
	 */
	public function boot(): void {
		// Self-heal the schema, watchdog and cron on every boot. This is cheap
		// (a single option read on the hot path) and means a manually copied
		// plugin, or one whose activation hook never ran, still works.
		add_action( 'init', [ Installer::class, 'maybe_upgrade' ], 1 );

		Poller::register();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}
}
