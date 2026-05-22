<?php
/**
 * Gate 1 — separate-process lint.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

use CAW\PluginBuilder\Capabilities;
use CAW\PluginBuilder\Support\Logger;

/**
 * Gate 1: run "php -l" on every PHP file, each in its own process.
 *
 * Parse errors (E_PARSE) are raised at COMPILE time. They cannot be caught by
 * a try/catch inside the file being parsed, and a single fatal parse error
 * aborts the whole PHP process. So this gate must run BEFORE the candidate is
 * ever copied near wp-content/plugins, and it must lint each file in a
 * SEPARATE process so one unparseable file cannot abort the lint of the rest.
 *
 * This is the cheapest gate and the one that catches the most common failure
 * mode for machine-authored PHP, so it runs first.
 */
final class LintGate {

	/**
	 * @param string $php_binary CLI PHP binary path. Defaults to the detected one.
	 */
	public function __construct(
		private string $php_binary = ''
	) {
		if ( '' === $this->php_binary ) {
			$this->php_binary = Capabilities::php_binary();
		}
	}

	/**
	 * Lint every PHP file under a directory tree.
	 *
	 * @param string $directory Absolute path to the tree to lint.
	 * @return GateResult Gate 1 result.
	 */
	public function run( string $directory ): GateResult {
		$name = __( 'Separate-process lint', 'caw-plugin-builder' );

		if ( ! Capabilities::can_exec() ) {
			return GateResult::fail(
				1,
				$name,
				__( 'exec() is unavailable, so files cannot be linted in separate processes.', 'caw-plugin-builder' )
			);
		}
		if ( '' === $this->php_binary ) {
			return GateResult::fail(
				1,
				$name,
				__( 'No CLI PHP binary is available to run php -l.', 'caw-plugin-builder' )
			);
		}
		if ( ! is_dir( $directory ) ) {
			return GateResult::fail(
				1,
				$name,
				__( 'The candidate directory does not exist.', 'caw-plugin-builder' )
			);
		}

		$php_files = $this->php_files( $directory );
		if ( [] === $php_files ) {
			return GateResult::fail(
				1,
				$name,
				__( 'The candidate contains no PHP files — nothing to lint, nothing to install.', 'caw-plugin-builder' )
			);
		}

		$failures = [];
		$checked  = 0;

		foreach ( $php_files as $file ) {
			++$checked;
			[ $exit_code, $message ] = $this->lint_file( $file );
			if ( 0 !== $exit_code ) {
				$relative   = $this->relativize( $file, $directory );
				$failures[] = sprintf( '%s: %s', $relative, $message );
			}
		}

		if ( [] !== $failures ) {
			Logger::warn( 'Gate 1 failed', [ 'failures' => count( $failures ) ] );
			return GateResult::fail(
				1,
				$name,
				sprintf(
					/* translators: 1: failing file count, 2: total file count */
					__( '%1$d of %2$d PHP files did not parse.', 'caw-plugin-builder' ),
					count( $failures ),
					$checked
				),
				$failures
			);
		}

		return GateResult::pass(
			1,
			$name,
			sprintf(
				/* translators: %d: number of files */
				__( 'All %d PHP files parsed cleanly.', 'caw-plugin-builder' ),
				$checked
			)
		);
	}

	/**
	 * Lint a single file in its own process.
	 *
	 * @param string $file Absolute file path.
	 * @return array{0: int, 1: string} Exit code and a one-line message.
	 */
	private function lint_file( string $file ): array {
		$command = escapeshellarg( $this->php_binary )
			. ' -d display_errors=1 -d error_reporting=E_ALL -l '
			. escapeshellarg( $file )
			. ' 2>&1';

		$output = [];
		$status = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		@exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.NoSilentErrors

		$text = trim( implode( "\n", $output ) );
		// "php -l" prints a success line on exit 0; surface only the error line.
		if ( 0 !== $status ) {
			$message = '';
			foreach ( $output as $line ) {
				if ( false !== stripos( $line, 'error' ) || false !== stripos( $line, 'parse' ) ) {
					$message = trim( $line );
					break;
				}
			}
			if ( '' === $message ) {
				$message = '' !== $text ? $text : __( 'Unknown parse failure.', 'caw-plugin-builder' );
			}
			return [ $status, Logger::redact( $message ) ];
		}

		return [ 0, 'ok' ];
	}

	/**
	 * Collect every .php file under a directory tree.
	 *
	 * @param string $directory Root directory.
	 * @return string[] Absolute file paths.
	 */
	private function php_files( string $directory ): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $item ) {
			if ( $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Express an absolute path relative to the candidate root.
	 *
	 * @param string $file Absolute file path.
	 * @param string $root Candidate root.
	 * @return string Relative path.
	 */
	private function relativize( string $file, string $root ): string {
		$root = rtrim( $root, '/\\' ) . '/';
		if ( str_starts_with( $file, $root ) ) {
			return substr( $file, strlen( $root ) );
		}
		return basename( $file );
	}
}
