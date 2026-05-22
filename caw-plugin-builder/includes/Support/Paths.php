<?php
/**
 * Filesystem path helpers.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Support;

/**
 * Resolves and provisions the plugin's working directories.
 *
 * Two roots live under wp-content/uploads:
 *
 *  - caw-staging/   Agent-authored code is extracted here. Host gates run
 *                   against this tree. Code is NEVER written straight into
 *                   the plugins directory (HARD CONSTRAINT 1).
 *  - caw-artifacts/ Validated, completed plugin zips plus their reports.
 *
 * Both roots are hardened against direct web access on creation.
 */
final class Paths {

	public const STAGING_DIRNAME   = 'caw-staging';
	public const ARTIFACTS_DIRNAME = 'caw-artifacts';

	/**
	 * Absolute path to the staging root, creating and hardening it if needed.
	 *
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	public static function staging_root(): string {
		return self::ensure_root( self::STAGING_DIRNAME );
	}

	/**
	 * Absolute path to the artifacts root, creating and hardening it if needed.
	 *
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	public static function artifacts_root(): string {
		return self::ensure_root( self::ARTIFACTS_DIRNAME );
	}

	/**
	 * Per-build staging directory where agent-authored code is extracted.
	 *
	 * @param int $build_id Build identifier.
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	public static function build_staging_dir( int $build_id ): string {
		$root = self::staging_root();
		if ( '' === $root ) {
			return '';
		}
		$dir = $root . '/build-' . $build_id;
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Absolute path to a completed build's artifact zip.
	 *
	 * @param int $build_id Build identifier.
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	public static function artifact_path( int $build_id ): string {
		$root = self::artifacts_root();
		if ( '' === $root ) {
			return '';
		}
		return $root . '/caw-build-' . $build_id . '.zip';
	}

	/**
	 * Absolute path to the plugin debug log file.
	 *
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	public static function log_file(): string {
		$root = self::staging_root();
		if ( '' === $root ) {
			return '';
		}
		return $root . '/caw-debug.log';
	}

	/**
	 * Recursively delete a directory tree.
	 *
	 * Refuses to operate outside the plugin's own staging/artifacts roots so a
	 * bad caller can never be tricked into removing host files.
	 *
	 * @param string $path Directory to remove.
	 * @return bool True when the tree is gone.
	 */
	public static function rmtree( string $path ): bool {
		$path = (string) realpath( $path );
		if ( '' === $path || ! is_dir( $path ) ) {
			return true;
		}

		if ( ! self::is_within_workspace( $path ) ) {
			Logger::warn( 'Refused rmtree outside workspace', [ 'path' => $path ] );
			return false;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilentErrors
			}
		}
		return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilentErrors
	}

	/**
	 * Whether an absolute path lives inside the plugin's staging/artifacts roots.
	 *
	 * @param string $path Absolute path (need not exist).
	 * @return bool True when contained.
	 */
	public static function is_within_workspace( string $path ): bool {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}
		$base = (string) realpath( $uploads['basedir'] );
		if ( '' === $base ) {
			return false;
		}
		$real = (string) realpath( $path );
		$probe = '' !== $real ? $real : $path;

		foreach ( [ self::STAGING_DIRNAME, self::ARTIFACTS_DIRNAME ] as $dirname ) {
			$root = $base . '/' . $dirname;
			if ( $probe === $root || str_starts_with( $probe, $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Create and harden one of the plugin roots under uploads.
	 *
	 * @param string $dirname Directory name beneath uploads.
	 * @return string Absolute path, or '' when uploads are unavailable.
	 */
	private static function ensure_root( string $dirname ): string {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			Logger::error( 'Uploads directory unavailable', [ 'error' => (string) $uploads['error'] ] );
			return '';
		}

		$root = rtrim( $uploads['basedir'], '/\\' ) . '/' . $dirname;
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		if ( ! is_dir( $root ) ) {
			return '';
		}

		// Block direct web access: deny via .htaccess and silence directory listings.
		$htaccess = $root . '/.htaccess';
		if ( ! is_file( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		$index = $root . '/index.php';
		if ( ! is_file( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $root;
	}
}
