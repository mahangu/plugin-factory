<?php
/**
 * Base test case for the integration suite.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that run against the live WordPress 7.0 rig.
 *
 * Because these tests mutate real WordPress state — plugin folders, the
 * active_plugins option, the builds table — every test must leave the rig as
 * it found it. This base class tracks the things a test creates and unwinds
 * them in tearDown(), so an order-independent, repeatable suite is possible
 * even against a non-throwaway install.
 */
abstract class IntegrationTestCase extends TestCase {

	/** @var string[] Absolute plugin folders created under WP_PLUGIN_DIR. */
	private array $plugin_dirs = [];

	/** @var string[] Absolute scratch directories to remove. */
	private array $scratch_dirs = [];

	/** @var string[] Absolute files to remove. */
	private array $scratch_files = [];

	/**
	 * Clean up everything the test created.
	 */
	protected function tearDown(): void {
		foreach ( $this->plugin_dirs as $dir ) {
			$basename = basename( $dir );
			foreach ( glob( $dir . '/*.php' ) ?: [] as $php ) {
				$candidate = $basename . '/' . basename( $php );
				if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $candidate ) ) {
					deactivate_plugins( $candidate, true );
				}
			}
			$this->rmtree( $dir );
		}
		foreach ( $this->scratch_dirs as $dir ) {
			$this->rmtree( $dir );
		}
		foreach ( $this->scratch_files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		$this->plugin_dirs   = [];
		$this->scratch_dirs  = [];
		$this->scratch_files = [];

		parent::tearDown();
	}

	/**
	 * Register a plugin folder for automatic cleanup.
	 *
	 * @param string $dir Absolute plugin folder.
	 * @return string The same path, for chaining.
	 */
	protected function track_plugin_dir( string $dir ): string {
		$this->plugin_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Create and track a fresh scratch directory.
	 *
	 * @param string $prefix Name prefix.
	 * @return string Absolute scratch directory path.
	 */
	protected function make_scratch_dir( string $prefix = 'caw-test' ): string {
		$dir = sys_get_temp_dir() . '/' . $prefix . '-' . wp_generate_password( 8, false, false );
		mkdir( $dir, 0755, true );
		$this->scratch_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Register an existing directory for removal in tearDown.
	 *
	 * @param string $dir Absolute directory path.
	 * @return string The same path, for chaining.
	 */
	protected function track_dir( string $dir ): string {
		$this->scratch_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Create and track a scratch file path (the file need not exist yet).
	 *
	 * @param string $suffix File suffix.
	 * @return string Absolute path.
	 */
	protected function make_scratch_file( string $suffix = '.tmp' ): string {
		$path = sys_get_temp_dir() . '/caw-test-' . wp_generate_password( 10, false, false ) . $suffix;
		$this->scratch_files[] = $path;
		return $path;
	}

	/**
	 * A unique plugin slug for this test run.
	 *
	 * @param string $label Short label.
	 * @return string Slug.
	 */
	protected function unique_slug( string $label ): string {
		return 'caw-test-' . $label . '-' . strtolower( wp_generate_password( 6, false, false ) );
	}

	/**
	 * Run a callable while swallowing "headers already sent" warnings.
	 *
	 * Gate 3 calls activate_plugin() with a non-empty redirect, which makes
	 * WordPress call header(). Under the PHPUnit CLI runner output has already
	 * started, so header() warns. That warning is a pure test-context artifact
	 * — it has no bearing on what activation does — so it is swallowed here
	 * while every other warning is still passed through to PHPUnit.
	 *
	 * @param callable $fn Callable to run.
	 * @return mixed The callable's return value.
	 */
	protected function ignoringHeaderWarnings( callable $fn ): mixed {
		$previous = set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$previous ) {
				if ( str_contains( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $previous ) ) {
					return ( $previous )( $errno, $errstr, $errfile, $errline );
				}
				return false;
			}
		);
		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Directory.
	 */
	protected function rmtree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $path );
	}
}
