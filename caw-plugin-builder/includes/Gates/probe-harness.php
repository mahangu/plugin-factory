<?php
/**
 * Gate 2 runtime probe harness.
 *
 * This file is NEVER autoloaded and NEVER included by the plugin. It is a
 * standalone script executed in a throwaway CLI process by RuntimeProbeGate:
 *
 *   php probe-harness.php --wp-load=... --plugin-file=... --activate-hook=...
 *                         --report=...
 *
 * Its job is to load a candidate plugin and run its activation hook the way a
 * real activation would, but inside a disposable process, so that any fatal —
 * including an uncatchable parse error or an activation-hook crash — kills only
 * this process and is reported back as structured data rather than white-
 * screening the host.
 *
 * Two safety nets cover every failure mode:
 *
 *   1. A register_shutdown_function installed before anything else runs. It
 *      fires even after an uncatchable E_ERROR / E_PARSE / E_COMPILE_ERROR and
 *      writes the report, so a hard crash still produces a verdict.
 *   2. A try/catch(\Throwable) around the include and the activation hook,
 *      which captures the catchable failures with a full class + trace.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

// This script is meaningful only on the command line.
if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

/**
 * Shared probe state. The shutdown function reads this to write the final
 * report, so progress must be recorded into it as each stage completes.
 *
 * @var array<string, mixed> $caw_probe
 */
$caw_probe = [
	'stage'          => 'startup',
	'wp_loaded'      => false,
	'included'       => false,
	'activation_ran' => false,
	'reached_end'    => false,
	'fatal'          => null,
	'caught'         => null,
	'output'         => '',
	'report_path'    => '',
	'ok'             => false,
];
$GLOBALS['caw_probe'] = &$caw_probe;

/**
 * Write the probe report to disk as JSON.
 *
 * Called both on the normal path and from the shutdown function, so it must be
 * safe to call more than once.
 *
 * @param array<string, mixed> $probe Probe state.
 */
function caw_probe_write_report( array $probe ): void {
	$path = (string) ( $probe['report_path'] ?? '' );
	if ( '' === $path ) {
		return;
	}
	unset( $probe['report_path'] );

	$json = json_encode( $probe, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE );
	if ( false === $json ) {
		$json = '{"ok":false,"stage":"report_encode_failed"}';
	}
	file_put_contents( $path, $json );
}

/**
 * Whether an error_get_last() entry is an unrecoverable fatal.
 *
 * @param array{type?: int}|null $error Error record.
 * @return bool True when fatal.
 */
function caw_probe_is_fatal( ?array $error ): bool {
	if ( null === $error || ! isset( $error['type'] ) ) {
		return false;
	}
	return in_array(
		$error['type'],
		[ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ],
		true
	);
}

// SAFETY NET 1: install the shutdown function before anything else can fail.
register_shutdown_function(
	static function (): void {
		$probe = $GLOBALS['caw_probe'];

		$last = error_get_last();
		if ( ! $probe['reached_end'] && caw_probe_is_fatal( $last ) ) {
			$probe['fatal'] = [
				'type'    => (int) $last['type'],
				'message' => (string) $last['message'],
				'file'    => (string) $last['file'],
				'line'    => (int) $last['line'],
			];
			$probe['ok'] = false;
		}

		if ( ! $probe['reached_end'] && null === $probe['fatal'] && null === $probe['caught'] ) {
			// The process ended early with no recorded error: treat as a crash.
			$probe['fatal'] = [
				'type'    => 0,
				'message' => 'The probe process exited unexpectedly before completing.',
				'file'    => '',
				'line'    => 0,
			];
			$probe['ok'] = false;
		}

		$buffered = '';
		while ( ob_get_level() > 0 ) {
			$buffered = ob_get_clean() . $buffered;
		}
		if ( '' !== $buffered ) {
			$probe['output'] = trim( $buffered . "\n" . (string) $probe['output'] );
		}

		caw_probe_write_report( $probe );
	}
);

// Parse arguments.
$options = getopt( '', [ 'wp-load:', 'plugin-file:', 'activate-hook:', 'report:' ] );
$wp_load       = (string) ( $options['wp-load'] ?? '' );
$plugin_file   = (string) ( $options['plugin-file'] ?? '' );
$activate_hook = (string) ( $options['activate-hook'] ?? '' );
$report_path   = (string) ( $options['report'] ?? '' );

$caw_probe['report_path'] = $report_path;

if ( '' === $wp_load || '' === $plugin_file || '' === $report_path ) {
	$caw_probe['stage']       = 'bad_arguments';
	$caw_probe['reached_end'] = true;
	$caw_probe['ok']          = false;
	$caw_probe['caught']      = [
		'class'   => 'InvalidArgumentException',
		'message' => 'probe-harness.php requires --wp-load, --plugin-file and --report.',
		'file'    => __FILE__,
		'line'    => __LINE__,
	];
	exit( 2 );
}

if ( ! is_file( $wp_load ) || ! is_file( $plugin_file ) ) {
	$caw_probe['stage']       = 'missing_files';
	$caw_probe['reached_end'] = true;
	$caw_probe['ok']          = false;
	$caw_probe['caught']      = [
		'class'   => 'RuntimeException',
		'message' => 'wp-load.php or the plugin file could not be found.',
		'file'    => __FILE__,
		'line'    => __LINE__,
	];
	exit( 2 );
}

// Mark the process so the candidate and WordPress can detect a probe run.
if ( ! defined( 'CAW_RUNTIME_PROBE' ) ) {
	define( 'CAW_RUNTIME_PROBE', true );
}
// A defensive hard ceiling in case the candidate spins.
set_time_limit( 45 );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

ob_start();

// STAGE: load the host WordPress so the candidate runs against real WP APIs.
$caw_probe['stage'] = 'wp_load';
try {
	require $wp_load;
	$caw_probe['wp_loaded'] = true;
} catch ( \Throwable $e ) {
	$caw_probe['caught'] = caw_probe_describe( $e );
	$caw_probe['reached_end'] = true;
	$caw_probe['ok'] = false;
	exit( 1 );
}

// STAGE: include the candidate plugin — SAFETY NET 2 for catchable failures.
// A parse error here is uncatchable and will be handled by the shutdown
// function (SAFETY NET 1) instead.
$caw_probe['stage'] = 'include';
try {
	include $plugin_file;
	$caw_probe['included'] = true;
} catch ( \Throwable $e ) {
	$caw_probe['caught'] = caw_probe_describe( $e );
	$caw_probe['reached_end'] = true;
	$caw_probe['ok'] = false;
	exit( 1 );
}

// STAGE: run the activation hook so its side effects (table creation, cron
// scheduling) surface here instead of on the live host.
$caw_probe['stage'] = 'activation';
if ( '' !== $activate_hook && function_exists( 'do_action' ) ) {
	try {
		do_action( $activate_hook );
		$caw_probe['activation_ran'] = true;
	} catch ( \Throwable $e ) {
		$caw_probe['caught'] = caw_probe_describe( $e );
		$caw_probe['reached_end'] = true;
		$caw_probe['ok'] = false;
		exit( 1 );
	}
} else {
	$caw_probe['activation_ran'] = false;
}

// Reached the end with no fatal and nothing caught: the candidate is sane.
$caw_probe['stage']       = 'complete';
$caw_probe['reached_end'] = true;
$caw_probe['ok']          = true;
exit( 0 );

/**
 * Describe a throwable as a credential-free structured array.
 *
 * Declared after the top-level flow purely for readability; PHP hoists
 * function declarations so this is available everywhere above.
 *
 * @param \Throwable $e Throwable.
 * @return array{class: string, message: string, file: string, line: int} Description.
 */
function caw_probe_describe( \Throwable $e ): array {
	return [
		'class'   => get_class( $e ),
		'message' => $e->getMessage(),
		'file'    => $e->getFile(),
		'line'    => $e->getLine(),
	];
}
