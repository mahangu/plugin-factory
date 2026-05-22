<?php
/**
 * Tests for the structured CI results harvester.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Agent\CiResultsHarvester;

/**
 * The harvester enforces the CI TRUST RULE: it reads only structured machine
 * output and the resulting report decides pass/fail itself. These tests prove
 * the verdict is driven by data, never by an agent's say-so.
 */
final class CiResultsHarvesterTest extends IntegrationTestCase {

	/**
	 * A passing JUnit suite with clean lint yields a passing report.
	 */
	public function test_passes_with_clean_results(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [
					[ 'file' => 'a.php', 'exit_code' => 0, 'message' => 'ok' ],
					[ 'file' => 'b.php', 'exit_code' => 0, 'message' => 'ok' ],
				],
				'phpunit_junit_xml' => $this->junit( 5, 0, 0 ),
				'phpstan_json'      => '{"totals":{"file_errors":0,"errors":0},"files":{}}',
			]
		);

		$this->assertTrue( $report->passed() );
		$this->assertSame( 5, $report->phpunit()['tests'] );
	}

	/**
	 * A non-zero lint exit code fails the report.
	 */
	public function test_fails_when_a_file_does_not_lint(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [
					[ 'file' => 'a.php', 'exit_code' => 0, 'message' => 'ok' ],
					[ 'file' => 'b.php', 'exit_code' => 255, 'message' => 'parse error' ],
				],
				'phpunit_junit_xml' => $this->junit( 3, 0, 0 ),
			]
		);

		$this->assertFalse( $report->passed() );
	}

	/**
	 * PHPUnit failures fail the report.
	 */
	public function test_fails_on_test_failures(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => $this->junit( 4, 2, 0 ),
			]
		);

		$this->assertFalse( $report->passed() );
		$this->assertSame( 2, $report->phpunit()['failures'] );
	}

	/**
	 * PHPUnit errors fail the report.
	 */
	public function test_fails_on_test_errors(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => $this->junit( 4, 0, 1 ),
			]
		);

		$this->assertFalse( $report->passed() );
	}

	/**
	 * A missing JUnit report is a failure, not a pass-by-default.
	 */
	public function test_fails_when_junit_absent(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[ 'lint' => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ] ]
		);

		$this->assertFalse( $report->passed() );
		$this->assertNotEmpty( $report->notes() );
	}

	/**
	 * A suite that ran zero tests is a failure.
	 */
	public function test_fails_when_no_tests_ran(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => $this->junit( 0, 0, 0 ),
			]
		);

		$this->assertFalse( $report->passed() );
	}

	/**
	 * Malformed JUnit XML degrades to a failing verdict with a note.
	 */
	public function test_handles_malformed_junit(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => 'this is not xml <<<',
			]
		);

		$this->assertFalse( $report->passed() );
		$this->assertNotEmpty( $report->notes() );
	}

	/**
	 * Nested test suites are summed from the top level, not double counted.
	 */
	public function test_sums_nested_testsuites(): void {
		$xml = '<?xml version="1.0"?><testsuites>'
			. '<testsuite name="outer" tests="6" failures="0" errors="0" assertions="6">'
			. '<testsuite name="inner" tests="6" failures="0" errors="0" assertions="6"/>'
			. '</testsuite></testsuites>';

		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => $xml,
			]
		);

		$this->assertSame( 6, $report->phpunit()['tests'] );
		$this->assertTrue( $report->passed() );
	}

	/**
	 * PHPStan findings are advisory and do not, alone, fail CI.
	 */
	public function test_phpstan_issues_do_not_fail_ci(): void {
		$report = ( new CiResultsHarvester() )->harvest(
			[
				'lint'              => [ [ 'file' => 'a.php', 'exit_code' => 0, 'message' => '' ] ],
				'phpunit_junit_xml' => $this->junit( 3, 0, 0 ),
				'phpstan_json'      => '{"totals":{"file_errors":7,"errors":0},"files":{"a.php":{}}}',
			]
		);

		$this->assertTrue( $report->passed() );
		$this->assertSame( 7, $report->phpstan()['errors'] );
	}

	/**
	 * Build a minimal JUnit XML document.
	 *
	 * @param int $tests    Test count.
	 * @param int $failures Failure count.
	 * @param int $errors   Error count.
	 * @return string JUnit XML.
	 */
	private function junit( int $tests, int $failures, int $errors ): string {
		return sprintf(
			'<?xml version="1.0"?><testsuites><testsuite name="s" tests="%d" failures="%d" errors="%d" skipped="0" assertions="%d"/></testsuites>',
			$tests,
			$failures,
			$errors,
			$tests
		);
	}
}
