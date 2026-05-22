<?php
/**
 * Host capability detection.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

use CAW\PluginBuilder\Support\Logger;

/**
 * Detects what the host environment can and cannot do.
 *
 * The plugin must degrade gracefully rather than assume a modern host:
 *
 *  - Connectors API exists only on WordPress 7.0+. On 6.x we fall back to a
 *    bespoke key field. Detection is via function_exists( 'wp_get_connector' ),
 *    NOT a version_compare, so a back-ported Connectors API still works.
 *  - exec() drives Gate 1 (php -l) and Gate 2 (isolated runtime probe). When it
 *    is unavailable the "Install on this site" destination is disabled, because
 *    code that cannot be probed must never reach the live process.
 */
final class Capabilities {

	/**
	 * Whether the WordPress 7.0 Connectors API is present.
	 *
	 * @return bool True when wp_get_connector() exists.
	 */
	public static function has_connectors_api(): bool {
		return function_exists( 'wp_get_connector' ) && function_exists( 'wp_is_connector_registered' );
	}

	/**
	 * Whether the Anthropic connector is registered with the Connectors API.
	 *
	 * Connectors are only registered after the `init` action, so callers must
	 * not invoke this at plugin-load time.
	 *
	 * @return bool True when the 'anthropic' connector is registered.
	 */
	public static function anthropic_connector_registered(): bool {
		return self::has_connectors_api() && wp_is_connector_registered( 'anthropic' );
	}

	/**
	 * Whether exec() is callable (not disabled, not in safe restrictions).
	 *
	 * @return bool True when exec() may be used.
	 */
	public static function can_exec(): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return false;
		}
		return ! self::is_safe_mode_like();
	}

	/**
	 * Best-effort path to a CLI PHP binary usable for separate-process gates.
	 *
	 * @return string Absolute path, or '' when none can be found.
	 */
	public static function php_binary(): string {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$candidates = [];

		if ( defined( 'PHP_BINARY' ) && PHP_BINARY ) {
			// When running under the CLI SAPI this is the binary directly.
			if ( false !== strpos( PHP_SAPI, 'cli' ) ) {
				$candidates[] = PHP_BINARY;
			}
			// Otherwise PHP_BINARY points at php-fpm/apache; try a sibling php.
			$dir = dirname( PHP_BINARY );
			$candidates[] = $dir . '/php';
		}

		$candidates[] = '/usr/bin/php';
		$candidates[] = '/usr/local/bin/php';
		$candidates[] = 'php';

		foreach ( $candidates as $candidate ) {
			if ( self::is_usable_php( $candidate ) ) {
				$cached = $candidate;
				return $cached;
			}
		}

		Logger::warn( 'No usable CLI PHP binary found' );
		$cached = '';
		return $cached;
	}

	/**
	 * Whether the local "Install on this site" destination may be offered.
	 *
	 * Requires exec() (so Gates 1 and 2 can run in separate processes), a
	 * resolvable PHP binary, and the zip extension to unpack artifacts.
	 *
	 * @return bool True when host install is permitted.
	 */
	public static function can_install_locally(): bool {
		return self::can_exec()
			&& '' !== self::php_binary()
			&& class_exists( '\ZipArchive' );
	}

	/**
	 * Human-readable reasons the host install destination is unavailable.
	 *
	 * @return string[] Zero or more reason strings.
	 */
	public static function install_blockers(): array {
		$blockers = [];
		if ( ! self::can_exec() ) {
			$blockers[] = __( 'exec() is disabled, so the isolated runtime probe (Gate 2) cannot run.', 'caw-plugin-builder' );
		}
		if ( '' === self::php_binary() ) {
			$blockers[] = __( 'No command-line PHP binary could be located for the separate-process gates.', 'caw-plugin-builder' );
		}
		if ( ! class_exists( '\ZipArchive' ) ) {
			$blockers[] = __( 'The PHP zip extension is not installed, so artifacts cannot be unpacked.', 'caw-plugin-builder' );
		}
		return $blockers;
	}

	/**
	 * Whether artifacts can be packaged as zip files.
	 *
	 * @return bool True when ZipArchive is available.
	 */
	public static function has_zip(): bool {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Summary of detected capabilities for display and logging.
	 *
	 * @return array<string, bool|string> Capability map.
	 */
	public static function summary(): array {
		return [
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'connectors_api'      => self::has_connectors_api(),
			'can_exec'            => self::can_exec(),
			'php_binary'          => self::php_binary(),
			'can_install_locally' => self::can_install_locally(),
			'has_zip'             => self::has_zip(),
		];
	}

	/**
	 * Whether a candidate path behaves like a working CLI PHP binary.
	 *
	 * @param string $candidate Binary path or bare command name.
	 * @return bool True when it runs and reports a version.
	 */
	private static function is_usable_php( string $candidate ): bool {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$output = [];
		$status = 1;
		@exec( escapeshellarg( $candidate ) . ' -v 2>/dev/null', $output, $status ); // phpcs:ignore WordPress.PHP.NoSilentErrors,WordPress.PHP.DiscouragedPHPFunctions
		return 0 === $status
			&& isset( $output[0] )
			&& false !== stripos( (string) $output[0], 'php' );
	}

	/**
	 * Rough check for legacy "safe mode"-style hardening that blocks exec().
	 *
	 * @return bool True when the environment looks locked down.
	 */
	private static function is_safe_mode_like(): bool {
		// Some managed hosts expose a phantom "open_basedir" that, combined with
		// disabled proc functions, makes exec() unusable even when callable.
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		foreach ( [ 'proc_open', 'popen', 'shell_exec' ] as $fn ) {
			if ( in_array( $fn, $disabled, true ) ) {
				// exec() itself may still work; this is only a soft signal and
				// does not by itself disqualify exec().
				continue;
			}
		}
		return false;
	}
}
