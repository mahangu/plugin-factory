<?php
/**
 * Gate 2 — isolated runtime probe.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

use CAW\PluginBuilder\Capabilities;
use CAW\PluginBuilder\Support\Logger;

/**
 * Gate 2: load the candidate and run its activation hook in a throwaway process.
 *
 * Gate 1 proves the files PARSE. It cannot prove they RUN: a file can parse
 * perfectly and still fatal the instant it is included (a missing class, a
 * call to an undefined function) or when its activation hook fires (a failed
 * CREATE TABLE, a bad cron registration).
 *
 * This gate drives probe-harness.php in a separate PHP process. Because the
 * probe runs in its own process, a fatal there — even an uncatchable one —
 * kills only that process. The harness reports back as structured JSON, which
 * is the source of truth: a missing or non-"ok" report is always a failure.
 *
 * This gate runs on the host independently of the sandbox CI, because it
 * executes where the agent's CI never ran: the actual host PHP and WordPress.
 */
final class RuntimeProbeGate {

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
	 * Probe a candidate plugin already staged under wp-content/plugins.
	 *
	 * @param string $plugin_main_file Absolute path to the candidate's main file.
	 * @param string $plugin_basename  Plugin basename, e.g. "my-plugin/my-plugin.php".
	 * @return GateResult Gate 2 result.
	 */
	public function run( string $plugin_main_file, string $plugin_basename ): GateResult {
		$name = __( 'Isolated runtime probe', 'caw-plugin-builder' );

		if ( ! Capabilities::can_exec() ) {
			return GateResult::fail(
				2,
				$name,
				__( 'exec() is unavailable, so the runtime probe cannot run in a separate process.', 'caw-plugin-builder' )
			);
		}
		if ( '' === $this->php_binary ) {
			return GateResult::fail( 2, $name, __( 'No CLI PHP binary is available for the runtime probe.', 'caw-plugin-builder' ) );
		}
		if ( ! is_file( $plugin_main_file ) ) {
			return GateResult::fail( 2, $name, __( 'The candidate main file could not be found for probing.', 'caw-plugin-builder' ) );
		}

		$harness = __DIR__ . '/probe-harness.php';
		if ( ! is_file( $harness ) ) {
			return GateResult::fail( 2, $name, __( 'The runtime probe harness is missing.', 'caw-plugin-builder' ) );
		}

		$wp_load = rtrim( ABSPATH, '/\\' ) . '/wp-load.php';
		if ( ! is_file( $wp_load ) ) {
			return GateResult::fail( 2, $name, __( 'Could not locate the host wp-load.php for the runtime probe.', 'caw-plugin-builder' ) );
		}

		$report_path = $this->report_path();
		if ( '' === $report_path ) {
			return GateResult::fail( 2, $name, __( 'Could not create a temporary file for the probe report.', 'caw-plugin-builder' ) );
		}

		$activate_hook = 'activate_' . $plugin_basename;

		$command = $this->build_command( $harness, $wp_load, $plugin_main_file, $activate_hook, $report_path );

		$output = [];
		$status = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec,WordPress.PHP.NoSilentErrors
		@exec( $command, $output, $status );

		$result = $this->interpret( $name, $report_path, $status, implode( "\n", $output ) );

		// phpcs:ignore WordPress.PHP.NoSilentErrors
		@unlink( $report_path );

		return $result;
	}

	/**
	 * Interpret the harness report into a gate result.
	 *
	 * @param string $name        Gate name.
	 * @param string $report_path Path the harness wrote its JSON report to.
	 * @param int    $exit_status Exit status of the probe process.
	 * @param string $raw_output  Combined stdout/stderr of the probe process.
	 * @return GateResult Gate 2 result.
	 */
	private function interpret( string $name, string $report_path, int $exit_status, string $raw_output ): GateResult {
		if ( ! is_file( $report_path ) ) {
			// No report at all: the process died so hard the shutdown function
			// never ran (OOM, segfault, killed). Conservatively a failure.
			Logger::warn( 'Gate 2 produced no report', [ 'exit' => $exit_status ] );
			return GateResult::fail(
				2,
				$name,
				__( 'The runtime probe crashed without producing a report. The candidate is unsafe to install.', 'caw-plugin-builder' ),
				$this->tail_lines( $raw_output )
			);
		}

		$json   = (string) file_get_contents( $report_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$report = json_decode( $json, true );

		if ( ! is_array( $report ) ) {
			return GateResult::fail(
				2,
				$name,
				__( 'The runtime probe report could not be parsed. The candidate is unsafe to install.', 'caw-plugin-builder' )
			);
		}

		if ( ! empty( $report['ok'] ) ) {
			$details = [];
			if ( ! empty( $report['activation_ran'] ) ) {
				$details[] = __( 'The activation hook ran without error.', 'caw-plugin-builder' );
			} else {
				$details[] = __( 'No activation hook was registered by the candidate.', 'caw-plugin-builder' );
			}
			return GateResult::pass(
				2,
				$name,
				__( 'The candidate loaded and activated cleanly in an isolated process.', 'caw-plugin-builder' ),
				$details
			);
		}

		return GateResult::fail( 2, $name, $this->failure_summary( $report ), $this->failure_details( $report ) );
	}

	/**
	 * Build a one-line failure summary from the harness report.
	 *
	 * @param array<string, mixed> $report Decoded harness report.
	 * @return string Summary.
	 */
	private function failure_summary( array $report ): string {
		$stage = (string) ( $report['stage'] ?? 'unknown' );
		switch ( $stage ) {
			case 'wp_load':
				return __( 'The probe could not even load WordPress; the candidate could not be tested.', 'caw-plugin-builder' );
			case 'include':
				return __( 'The candidate fataled the moment it was loaded.', 'caw-plugin-builder' );
			case 'activation':
				return __( 'The candidate loaded but its activation hook crashed.', 'caw-plugin-builder' );
			default:
				return __( 'The runtime probe failed; the candidate is unsafe to install.', 'caw-plugin-builder' );
		}
	}

	/**
	 * Extract human-readable detail lines from the harness report.
	 *
	 * @param array<string, mixed> $report Decoded harness report.
	 * @return string[] Detail lines.
	 */
	private function failure_details( array $report ): array {
		$details = [];

		if ( ! empty( $report['fatal'] ) && is_array( $report['fatal'] ) ) {
			$fatal     = $report['fatal'];
			$details[] = sprintf(
				/* translators: 1: error message, 2: file, 3: line */
				__( 'Fatal error: %1$s (in %2$s line %3$d)', 'caw-plugin-builder' ),
				Logger::redact( (string) ( $fatal['message'] ?? '' ) ),
				(string) ( $fatal['file'] ?? '' ),
				(int) ( $fatal['line'] ?? 0 )
			);
		}

		if ( ! empty( $report['caught'] ) && is_array( $report['caught'] ) ) {
			$caught    = $report['caught'];
			$details[] = sprintf(
				/* translators: 1: exception class, 2: message, 3: file, 4: line */
				__( 'Uncaught %1$s: %2$s (in %3$s line %4$d)', 'caw-plugin-builder' ),
				(string) ( $caught['class'] ?? 'Throwable' ),
				Logger::redact( (string) ( $caught['message'] ?? '' ) ),
				(string) ( $caught['file'] ?? '' ),
				(int) ( $caught['line'] ?? 0 )
			);
		}

		$output = trim( (string) ( $report['output'] ?? '' ) );
		if ( '' !== $output ) {
			$details[] = __( 'Probe output:', 'caw-plugin-builder' );
			foreach ( $this->tail_lines( $output ) as $line ) {
				$details[] = '  ' . $line;
			}
		}

		return $details;
	}

	/**
	 * Build the probe command, wrapping it in `timeout` when available.
	 *
	 * @param string $harness       Harness script path.
	 * @param string $wp_load       Host wp-load.php path.
	 * @param string $plugin_file   Candidate main file path.
	 * @param string $activate_hook Activation hook name.
	 * @param string $report_path   Report output path.
	 * @return string Shell command.
	 */
	private function build_command( string $harness, string $wp_load, string $plugin_file, string $activate_hook, string $report_path ): string {
		$command = escapeshellarg( $this->php_binary )
			. ' -d display_errors=1 -d error_reporting=E_ALL '
			. escapeshellarg( $harness )
			. ' --wp-load=' . escapeshellarg( $wp_load )
			. ' --plugin-file=' . escapeshellarg( $plugin_file )
			. ' --activate-hook=' . escapeshellarg( $activate_hook )
			. ' --report=' . escapeshellarg( $report_path )
			. ' 2>&1';

		// A hard wall-clock ceiling in case the candidate hangs on I/O.
		if ( $this->has_timeout_command() ) {
			$command = 'timeout -k 5 60 ' . $command;
		}

		return $command;
	}

	/**
	 * Whether the `timeout` command is available on this host.
	 *
	 * @return bool True when usable.
	 */
	private function has_timeout_command(): bool {
		$output = [];
		$status = 1;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec,WordPress.PHP.NoSilentErrors
		@exec( 'command -v timeout 2>/dev/null', $output, $status );
		return 0 === $status && [] !== $output;
	}

	/**
	 * Create a temporary file path for the probe report.
	 *
	 * @return string Absolute path, or '' on failure.
	 */
	private function report_path(): string {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = wp_tempnam( 'caw-probe' );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Return the last few lines of a blob of text.
	 *
	 * @param string $text  Text.
	 * @param int    $count Maximum lines.
	 * @return string[] Trailing lines.
	 */
	private function tail_lines( string $text, int $count = 20 ): array {
		$lines = array_values(
			array_filter(
				array_map( 'trim', explode( "\n", $text ) ),
				static fn ( string $line ): bool => '' !== $line
			)
		);
		$lines = array_slice( $lines, -$count );
		return array_map( [ Logger::class, 'redact' ], $lines );
	}
}
