<?php
/**
 * Plugin Name: CAW Plugin Builder Watchdog
 * Description: Lockout-recovery watchdog for CAW Plugin Builder. Auto-deactivates a plugin whose guarded activation hangs or crashes, and provides an emergency control surface that stays reachable even when a regular plugin has broken wp-admin.
 * Version:     0.1.0
 * Author:      Plugin Factory
 *
 * This file is a MUST-USE plugin. It is installed into wp-content/mu-plugins by
 * CAW Plugin Builder and loads on EVERY request, before any regular (and
 * therefore breakable) plugin. That ordering is the whole point: if an
 * agent-authored plugin crashes the site during or after activation, this
 * watchdog has already loaded and can undo the damage.
 *
 * It is deliberately self-contained: no namespaces, no Composer autoloader, no
 * dependency on the main plugin. The main plugin may be broken, deactivated, or
 * mid-upgrade; the watchdog must still work. The option names below are the
 * literal counterparts of the constants in CAW\PluginBuilder\Sentinel.
 *
 * @package CAW\PluginBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'CAW_WATCHDOG_LOADED' ) ) {
	return;
}
define( 'CAW_WATCHDOG_LOADED', true );

define( 'CAW_WATCHDOG_OPTION_ACTIVATING', 'caw_activating' );
define( 'CAW_WATCHDOG_OPTION_MANAGED', 'caw_managed_plugins' );
define( 'CAW_WATCHDOG_OPTION_TOKEN', 'caw_panic_token' );
define( 'CAW_WATCHDOG_OPTION_RECOVERY', 'caw_watchdog_recovery' );
define( 'CAW_WATCHDOG_STALE_SECONDS', 10 );

/**
 * Remove a plugin from the active list without loading or trusting its code.
 *
 * This is the recovery primitive. It only ever REMOVES entries from
 * active_plugins — it never adds one — so it cannot bypass the guarded
 * activation path that Gate 3 relies on. Removing a broken plugin from
 * active_plugins is exactly what WordPress's own fatal-error protection does.
 *
 * @param string $basename Plugin basename to deactivate.
 * @return bool True when the plugin was active and has been removed.
 */
function caw_watchdog_force_deactivate( $basename ) {
	if ( ! is_string( $basename ) || '' === $basename ) {
		return false;
	}

	$active = get_option( 'active_plugins', array() );
	if ( ! is_array( $active ) || ! in_array( $basename, $active, true ) ) {
		return false;
	}

	$active = array_values( array_diff( $active, array( $basename ) ) );
	update_option( 'active_plugins', $active );

	return true;
}

/**
 * Record that the watchdog performed a recovery, for a later admin notice.
 *
 * @param string $reason   Machine-readable reason code.
 * @param string $detail   Human-readable detail.
 * @param string $basename Plugin basename involved, if any.
 */
function caw_watchdog_log_recovery( $reason, $detail, $basename = '' ) {
	$log = get_option( CAW_WATCHDOG_OPTION_RECOVERY, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'reason'   => (string) $reason,
		'detail'   => (string) $detail,
		'plugin'   => (string) $basename,
		'time'     => time(),
	);
	// Keep only the most recent handful.
	$log = array_slice( $log, -10 );
	update_option( CAW_WATCHDOG_OPTION_RECOVERY, $log, false );
}

/**
 * Whether an error_get_last() record is an unrecoverable fatal.
 *
 * @param array|null $error Error record.
 * @return bool True when fatal.
 */
function caw_watchdog_is_fatal( $error ) {
	if ( ! is_array( $error ) || ! isset( $error['type'] ) ) {
		return false;
	}
	return in_array(
		(int) $error['type'],
		array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ),
		true
	);
}

/**
 * The directory a managed plugin lives in, relative to wp-content/plugins.
 *
 * @param string $basename Plugin basename, e.g. "my-plugin/my-plugin.php".
 * @return string Folder name, e.g. "my-plugin".
 */
function caw_watchdog_plugin_folder( $basename ) {
	$slash = strpos( $basename, '/' );
	return false === $slash ? $basename : substr( $basename, 0, $slash );
}

/*
 * MECHANISM 1 — staleness sweep.
 *
 * Runs now, at mu-plugin load time, which is BEFORE wp-settings.php reads
 * active_plugins and loads the regular plugins. If a previous request armed the
 * activation sentinel and then crashed, the sentinel is still set and stale.
 * Deactivating the offending plugin here means it is not even loaded on this
 * request — the lock-out is over immediately.
 */
$caw_watchdog_sentinel = get_option( CAW_WATCHDOG_OPTION_ACTIVATING, array() );
if ( is_array( $caw_watchdog_sentinel ) && ! empty( $caw_watchdog_sentinel['plugin'] ) ) {
	$caw_watchdog_age = time() - (int) ( $caw_watchdog_sentinel['time'] ?? 0 );
	if ( $caw_watchdog_age >= CAW_WATCHDOG_STALE_SECONDS ) {
		$caw_watchdog_bad = (string) $caw_watchdog_sentinel['plugin'];
		delete_option( CAW_WATCHDOG_OPTION_ACTIVATING );
		if ( caw_watchdog_force_deactivate( $caw_watchdog_bad ) ) {
			caw_watchdog_log_recovery(
				'stale_activation',
				'A guarded activation did not finish within the safety window and was rolled back.',
				$caw_watchdog_bad
			);
		}
	}
}
unset( $caw_watchdog_sentinel, $caw_watchdog_age, $caw_watchdog_bad );

/*
 * MECHANISM 2 — fatal-error shutdown handler.
 *
 * Catches a fatal that happens DURING this request inside a plugin that is
 * either mid-activation or one the builder installed. The faulting plugin is
 * removed from active_plugins so the very next request is clean. This is the
 * net for a plugin that crashes the activation request itself, faster than
 * waiting for the staleness window.
 */
register_shutdown_function(
	function () {
		$error = error_get_last();
		if ( ! caw_watchdog_is_fatal( $error ) ) {
			return;
		}

		$error_file = isset( $error['file'] ) ? (string) $error['file'] : '';

		// First suspect: a plugin caught mid-activation.
		$sentinel = get_option( CAW_WATCHDOG_OPTION_ACTIVATING, array() );
		if ( is_array( $sentinel ) && ! empty( $sentinel['plugin'] ) ) {
			$basename = (string) $sentinel['plugin'];
			delete_option( CAW_WATCHDOG_OPTION_ACTIVATING );
			if ( caw_watchdog_force_deactivate( $basename ) ) {
				caw_watchdog_log_recovery(
					'activation_fatal',
					'A guarded activation crashed the request; the plugin was deactivated.',
					$basename
				);
				return;
			}
		}

		// Second suspect: any builder-installed plugin whose folder the fatal
		// occurred inside.
		if ( '' !== $error_file ) {
			$managed = get_option( CAW_WATCHDOG_OPTION_MANAGED, array() );
			if ( is_array( $managed ) ) {
				foreach ( $managed as $basename ) {
					if ( ! is_string( $basename ) || '' === $basename ) {
						continue;
					}
					$folder = caw_watchdog_plugin_folder( $basename );
					$needle = '/plugins/' . $folder . '/';
					if ( false !== strpos( str_replace( '\\', '/', $error_file ), $needle ) ) {
						if ( caw_watchdog_force_deactivate( $basename ) ) {
							caw_watchdog_log_recovery(
								'managed_plugin_fatal',
								'A builder-installed plugin caused a fatal error and was deactivated.',
								$basename
							);
						}
						break;
					}
				}
			}
		}
	}
);

/*
 * MECHANISM 3 — emergency control surface.
 *
 * A token-protected URL that deactivates every builder-installed plugin at
 * once. It is handled here, at mu-plugin time, so it works even when a regular
 * plugin has broken wp-admin. The token is generated by the builder on
 * activation and shown to the admin; it does NOT require a logged-in session,
 * precisely because the lock-out being recovered from may block logging in.
 */
if ( isset( $_GET['caw_panic'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$caw_watchdog_token    = get_option( CAW_WATCHDOG_OPTION_TOKEN, '' );
	$caw_watchdog_supplied = isset( $_GET['caw_token'] ) ? (string) wp_unslash( $_GET['caw_token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	header( 'Content-Type: text/html; charset=utf-8' );
	nocache_headers();

	if ( ! is_string( $caw_watchdog_token ) || '' === $caw_watchdog_token || ! hash_equals( $caw_watchdog_token, $caw_watchdog_supplied ) ) {
		status_header( 403 );
		echo '<!doctype html><meta charset="utf-8"><title>CAW Watchdog</title>';
		echo '<p style="font-family:sans-serif">Invalid or missing recovery token.</p>';
		exit;
	}

	$caw_watchdog_managed = get_option( CAW_WATCHDOG_OPTION_MANAGED, array() );
	$caw_watchdog_done    = array();
	if ( is_array( $caw_watchdog_managed ) ) {
		foreach ( $caw_watchdog_managed as $caw_watchdog_basename ) {
			if ( caw_watchdog_force_deactivate( (string) $caw_watchdog_basename ) ) {
				$caw_watchdog_done[] = (string) $caw_watchdog_basename;
			}
		}
	}
	delete_option( CAW_WATCHDOG_OPTION_ACTIVATING );
	caw_watchdog_log_recovery(
		'panic',
		'Emergency control surface used: all builder-installed plugins were deactivated.',
		implode( ', ', $caw_watchdog_done )
	);

	status_header( 200 );
	echo '<!doctype html><meta charset="utf-8"><title>CAW Watchdog</title>';
	echo '<div style="font-family:sans-serif;max-width:40em;margin:3em auto">';
	echo '<h1>Recovery complete</h1>';
	echo '<p>Deactivated ' . (int) count( $caw_watchdog_done ) . ' builder-installed plugin(s). ';
	echo 'Your site and wp-admin should now be reachable.</p>';
	echo '<p><a href="' . esc_url( admin_url() ) . '">Return to the dashboard</a></p>';
	echo '</div>';
	exit;
}

/*
 * Surface a dashboard notice after any recovery, so the admin is never left
 * wondering why a plugin silently switched off.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$log = get_option( CAW_WATCHDOG_OPTION_RECOVERY, array() );
		if ( ! is_array( $log ) || array() === $log ) {
			return;
		}
		echo '<div class="notice notice-error is-dismissible"><p><strong>CAW Plugin Builder watchdog:</strong></p><ul style="list-style:disc;margin-left:2em">';
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			echo '<li>' . esc_html( (string) ( $entry['detail'] ?? '' ) );
			if ( ! empty( $entry['plugin'] ) ) {
				echo ' <code>' . esc_html( (string) $entry['plugin'] ) . '</code>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
		// The notice is one-shot: clear the log once it has been shown.
		delete_option( CAW_WATCHDOG_OPTION_RECOVERY );
	}
);
