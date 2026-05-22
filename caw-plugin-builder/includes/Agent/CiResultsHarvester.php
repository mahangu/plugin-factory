<?php
/**
 * Structured CI results harvester.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use CAW\PluginBuilder\Support\Logger;

/**
 * Turns the RAW CI artifacts produced in the sandbox into a structured CiReport.
 *
 * This class is the teeth of the CI TRUST RULE. The agent runs CI in the
 * sandbox; this code reads the machine output it produced — JUnit XML, lint
 * exit codes, PHPStan JSON — and the resulting CiReport computes pass/fail
 * itself. The agent's natural-language claims are never parsed or trusted.
 *
 * Parsing is defensive: a malformed or absent artifact degrades to a recorded
 * note and a conservative (failing) verdict rather than an exception.
 */
final class CiResultsHarvester {

	/**
	 * Harvest a CiReport from raw sandbox CI artifacts.
	 *
	 * @param array<string, mixed> $raw_ci Raw artifacts as returned by a provider's poll().
	 * @return CiReport Structured, host-computed report.
	 */
	public function harvest( array $raw_ci ): CiReport {
		$notes = [];

		$lint = $this->harvest_lint( $raw_ci['lint'] ?? null, $notes );

		[ $phpunit, $junit_present ] = $this->harvest_phpunit( $raw_ci['phpunit_junit_xml'] ?? null, $notes );

		[ $phpstan, $phpstan_present ] = $this->harvest_phpstan( $raw_ci['phpstan_json'] ?? null, $notes );

		return new CiReport( $lint, $phpunit, $phpstan, $notes, $junit_present, $phpstan_present );
	}

	/**
	 * Normalise per-file lint results.
	 *
	 * @param mixed    $raw   Raw lint data.
	 * @param string[] $notes Notes accumulator (by reference).
	 * @return array<int, array{file: string, exit_code: int, message: string}> Lint results.
	 */
	private function harvest_lint( mixed $raw, array &$notes ): array {
		if ( ! is_array( $raw ) ) {
			$notes[] = __( 'No lint results were reported by the sandbox CI run.', 'caw-plugin-builder' );
			return [];
		}

		$results = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$results[] = [
				'file'      => Logger::redact( (string) ( $entry['file'] ?? '' ) ),
				'exit_code' => (int) ( $entry['exit_code'] ?? 1 ),
				'message'   => Logger::redact( (string) ( $entry['message'] ?? '' ) ),
			];
		}

		if ( [] === $results ) {
			$notes[] = __( 'The sandbox CI lint stage produced no file entries.', 'caw-plugin-builder' );
		}

		return $results;
	}

	/**
	 * Parse PHPUnit totals out of JUnit XML.
	 *
	 * @param mixed    $xml   Raw JUnit XML string.
	 * @param string[] $notes Notes accumulator (by reference).
	 * @return array{0: array{tests:int,failures:int,errors:int,skipped:int,assertions:int}, 1: bool} Totals and presence flag.
	 */
	private function harvest_phpunit( mixed $xml, array &$notes ): array {
		$empty = [
			'tests'      => 0,
			'failures'   => 0,
			'errors'     => 0,
			'skipped'    => 0,
			'assertions' => 0,
		];

		if ( ! is_string( $xml ) || '' === trim( $xml ) ) {
			$notes[] = __( 'No PHPUnit JUnit XML was harvested from the sandbox CI run.', 'caw-plugin-builder' );
			return [ $empty, false ];
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $doc ) {
			$notes[] = __( 'The harvested PHPUnit JUnit XML could not be parsed.', 'caw-plugin-builder' );
			return [ $empty, false ];
		}

		// JUnit XML may root at <testsuites> or a single <testsuite>. Sum the
		// top-level suite attributes so nested suites are not double counted.
		$suites = [];
		if ( isset( $doc->testsuite ) ) {
			foreach ( $doc->testsuite as $suite ) {
				$suites[] = $suite;
			}
		}
		if ( [] === $suites && 'testsuite' === $doc->getName() ) {
			$suites[] = $doc;
		}

		$totals = $empty;
		foreach ( $suites as $suite ) {
			$attrs               = $suite->attributes();
			$totals['tests']      += isset( $attrs['tests'] ) ? (int) $attrs['tests'] : 0;
			$totals['failures']   += isset( $attrs['failures'] ) ? (int) $attrs['failures'] : 0;
			$totals['errors']     += isset( $attrs['errors'] ) ? (int) $attrs['errors'] : 0;
			$totals['skipped']    += isset( $attrs['skipped'] ) ? (int) $attrs['skipped'] : 0;
			$totals['assertions'] += isset( $attrs['assertions'] ) ? (int) $attrs['assertions'] : 0;
		}

		if ( [] === $suites ) {
			$notes[] = __( 'The harvested JUnit XML contained no test suites.', 'caw-plugin-builder' );
			return [ $empty, false ];
		}

		return [ $totals, true ];
	}

	/**
	 * Parse PHPStan totals out of its JSON report.
	 *
	 * @param mixed    $json  Raw PHPStan JSON string.
	 * @param string[] $notes Notes accumulator (by reference).
	 * @return array{0: array{errors:int,files:int}, 1: bool} Totals and presence flag.
	 */
	private function harvest_phpstan( mixed $json, array &$notes ): array {
		$empty = [
			'errors' => 0,
			'files'  => 0,
		];

		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			// PHPStan is advisory; absence is a note, not a failure.
			return [ $empty, false ];
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			$notes[] = __( 'The harvested PHPStan JSON could not be parsed.', 'caw-plugin-builder' );
			return [ $empty, false ];
		}

		$totals = $empty;
		if ( isset( $decoded['totals'] ) && is_array( $decoded['totals'] ) ) {
			$totals['errors'] = (int) ( $decoded['totals']['file_errors'] ?? $decoded['totals']['errors'] ?? 0 );
		}
		if ( isset( $decoded['files'] ) && is_array( $decoded['files'] ) ) {
			$totals['files'] = count( $decoded['files'] );
		}

		return [ $totals, true ];
	}
}
