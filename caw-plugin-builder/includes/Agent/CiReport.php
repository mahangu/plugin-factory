<?php
/**
 * Structured CI report.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * The verdict on a sandbox CI run, computed by OUR code.
 *
 * CI TRUST RULE: the agent runs CI in the sandbox but does not get to *pass*
 * it. The agent's prose ("all tests pass") is never consulted. This object is
 * built only from structured machine output — JUnit XML, lint exit codes,
 * PHPStan JSON — and the passed() verdict is derived here, on the host.
 *
 * The sandbox CI report does NOT replace the host gates: Gate 1 and Gate 2
 * still run independently, because they execute where the agent never ran.
 */
final class CiReport {

	/**
	 * @param array<int, array{file: string, exit_code: int, message: string}> $lint    Per-file php -l results.
	 * @param array{tests: int, failures: int, errors: int, skipped: int, assertions: int} $phpunit PHPUnit totals.
	 * @param array{errors: int, files: int}                                  $phpstan PHPStan totals.
	 * @param string[]                                                        $notes   Non-fatal harvest notes.
	 * @param bool                                                            $junit_present Whether JUnit XML was harvested.
	 * @param bool                                                            $phpstan_present Whether PHPStan JSON was harvested.
	 */
	public function __construct(
		private array $lint,
		private array $phpunit,
		private array $phpstan,
		private array $notes = [],
		private bool $junit_present = false,
		private bool $phpstan_present = false
	) {}

	/**
	 * The CI verdict, computed here from structured output only.
	 *
	 * Fails when: any lint exit code is non-zero; PHPUnit reports failures or
	 * errors; JUnit XML was expected but missing; or zero tests ran. PHPStan
	 * findings are advisory and do not fail CI on their own.
	 *
	 * @return bool True when sandbox CI is considered passing.
	 */
	public function passed(): bool {
		foreach ( $this->lint as $result ) {
			if ( 0 !== (int) $result['exit_code'] ) {
				return false;
			}
		}

		if ( ! $this->junit_present ) {
			return false;
		}
		if ( $this->phpunit['failures'] > 0 || $this->phpunit['errors'] > 0 ) {
			return false;
		}
		if ( $this->phpunit['tests'] <= 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * A short human-readable summary line.
	 *
	 * @return string Summary.
	 */
	public function summary(): string {
		$lint_failed = 0;
		foreach ( $this->lint as $result ) {
			if ( 0 !== (int) $result['exit_code'] ) {
				++$lint_failed;
			}
		}

		return sprintf(
			/* translators: 1: lint failures, 2: tests, 3: test failures, 4: test errors, 5: phpstan errors */
			__( 'Lint: %1$d failing; Tests: %2$d run, %3$d failed, %4$d errored; PHPStan: %5$d issues.', 'caw-plugin-builder' ),
			$lint_failed,
			$this->phpunit['tests'],
			$this->phpunit['failures'],
			$this->phpunit['errors'],
			$this->phpstan['errors']
		);
	}

	/**
	 * Per-file lint results.
	 *
	 * @return array<int, array{file: string, exit_code: int, message: string}> Lint results.
	 */
	public function lint(): array {
		return $this->lint;
	}

	/**
	 * PHPUnit totals.
	 *
	 * @return array{tests: int, failures: int, errors: int, skipped: int, assertions: int} Totals.
	 */
	public function phpunit(): array {
		return $this->phpunit;
	}

	/**
	 * PHPStan totals.
	 *
	 * @return array{errors: int, files: int} Totals.
	 */
	public function phpstan(): array {
		return $this->phpstan;
	}

	/**
	 * Non-fatal notes recorded while harvesting.
	 *
	 * @return string[] Notes.
	 */
	public function notes(): array {
		return $this->notes;
	}

	/**
	 * Serialise to a storable array.
	 *
	 * @return array<string, mixed> Storable report.
	 */
	public function to_array(): array {
		return [
			'passed'          => $this->passed(),
			'summary'         => $this->summary(),
			'lint'            => $this->lint,
			'phpunit'         => $this->phpunit,
			'phpstan'         => $this->phpstan,
			'notes'           => $this->notes,
			'junit_present'   => $this->junit_present,
			'phpstan_present' => $this->phpstan_present,
		];
	}

	/**
	 * Rehydrate a report from stored data.
	 *
	 * @param array<string, mixed> $data Stored report.
	 * @return self Report.
	 */
	public static function from_array( array $data ): self {
		$phpunit = is_array( $data['phpunit'] ?? null ) ? $data['phpunit'] : [];
		$phpstan = is_array( $data['phpstan'] ?? null ) ? $data['phpstan'] : [];

		return new self(
			is_array( $data['lint'] ?? null ) ? $data['lint'] : [],
			[
				'tests'      => (int) ( $phpunit['tests'] ?? 0 ),
				'failures'   => (int) ( $phpunit['failures'] ?? 0 ),
				'errors'     => (int) ( $phpunit['errors'] ?? 0 ),
				'skipped'    => (int) ( $phpunit['skipped'] ?? 0 ),
				'assertions' => (int) ( $phpunit['assertions'] ?? 0 ),
			],
			[
				'errors' => (int) ( $phpstan['errors'] ?? 0 ),
				'files'  => (int) ( $phpstan['files'] ?? 0 ),
			],
			is_array( $data['notes'] ?? null ) ? array_map( 'strval', $data['notes'] ) : [],
			(bool) ( $data['junit_present'] ?? false ),
			(bool) ( $data['phpstan_present'] ?? false )
		);
	}
}
