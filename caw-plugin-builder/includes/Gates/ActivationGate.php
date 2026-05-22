<?php
/**
 * Gate 3 — guarded activation.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

use CAW\PluginBuilder\Sentinel;
use CAW\PluginBuilder\Support\Logger;

/**
 * Gate 3: activate the candidate through WordPress's own guarded path.
 *
 * By the time this gate runs the candidate has already parsed (Gate 1) and
 * loaded + activated cleanly in an isolated process (Gate 2). Gate 3 is the
 * final, real activation, performed defensively:
 *
 *  - It is done with activate_plugin() and a NON-EMPTY $redirect, so WordPress
 *    issues its error-redirect before plugin_sandbox_scrape() includes the
 *    candidate. If the candidate somehow still fatals here, the browser has
 *    already been handed a safe destination.
 *  - It NEVER writes the active_plugins option directly. Direct manipulation
 *    skips plugin_sandbox_scrape() and every requirement check — the exact
 *    protections this gate exists to use.
 *  - It sets the caw_activating sentinel first, so the watchdog mu-plugin can
 *    recover from a lock-out if activation hangs or crashes the request.
 */
final class ActivationGate {

	/**
	 * Activate a candidate that is already staged under wp-content/plugins.
	 *
	 * @param string $plugin_basename Plugin basename, e.g. "my-plugin/my-plugin.php".
	 * @return GateResult Gate 3 result.
	 */
	public function run( string $plugin_basename ): GateResult {
		$name = __( 'Guarded activation', 'caw-plugin-builder' );

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Track the plugin and arm the sentinel BEFORE activation begins, so the
		// watchdog can recover even if the very next line crashes the request.
		Sentinel::track( $plugin_basename );
		Sentinel::begin_activation( $plugin_basename );

		// A non-empty redirect makes activate_plugin() emit WordPress's own
		// error redirect before it includes the candidate. The target is never
		// actually followed for our AJAX caller (a 200 response ignores it);
		// its only job is to arm WordPress's guarded activation path.
		$redirect = admin_url( 'admin.php?page=caw-plugin-builder' );

		$result = activate_plugin( $plugin_basename, $redirect, false, false );

		// Reaching this line means the candidate did NOT fatal during the
		// sandbox scrape — a hard fatal would have ended the request here.
		Sentinel::end_activation();

		if ( is_wp_error( $result ) ) {
			Logger::warn( 'Gate 3 activation returned WP_Error', [ 'code' => $result->get_error_code() ] );
			$this->rollback( $plugin_basename );
			return GateResult::fail(
				3,
				$name,
				__( 'WordPress refused to activate the candidate.', 'caw-plugin-builder' ),
				[ Logger::redact( (string) $result->get_error_message() ) ]
			);
		}

		if ( ! is_plugin_active( $plugin_basename ) ) {
			$this->rollback( $plugin_basename );
			return GateResult::fail(
				3,
				$name,
				__( 'Activation completed without error but the plugin is not active. It has been removed.', 'caw-plugin-builder' )
			);
		}

		Logger::info( 'Gate 3 activated plugin', [ 'plugin' => $plugin_basename ] );

		return GateResult::pass(
			3,
			$name,
			__( 'The plugin activated successfully through WordPress\'s guarded activation path.', 'caw-plugin-builder' )
		);
	}

	/**
	 * Ensure a plugin that failed Gate 3 is not left half-active.
	 *
	 * @param string $plugin_basename Plugin basename.
	 */
	private function rollback( string $plugin_basename ): void {
		Sentinel::end_activation();
		if ( is_plugin_active( $plugin_basename ) ) {
			deactivate_plugins( $plugin_basename, true );
		}
		Sentinel::untrack( $plugin_basename );
	}
}
