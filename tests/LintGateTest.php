<?php
/**
 * Tests for Gate 1 — separate-process lint.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Gates\LintGate;
use CAW\PluginBuilder\Tests\Support\Fixtures;

/**
 * Gate 1 must catch parse errors — the single most common failure mode for
 * machine-authored PHP — before a candidate ever moves toward the plugins
 * directory. A parse error is uncatchable at runtime, so it MUST be excluded
 * by static lint first.
 */
final class LintGateTest extends IntegrationTestCase {

	/**
	 * A clean candidate passes Gate 1.
	 */
	public function test_clean_candidate_passes(): void {
		$dir  = $this->make_scratch_dir( 'lint-clean' );
		$slug = 'caw-lint-clean';
		Fixtures::write_plugin( $dir, $slug, Fixtures::clean( $slug ) );

		$result = ( new LintGate() )->run( $dir );

		$this->assertTrue( $result->passed(), $result->summary() );
		$this->assertSame( 1, $result->number() );
	}

	/**
	 * A candidate with a parse error fails Gate 1.
	 */
	public function test_parse_error_fails(): void {
		$dir  = $this->make_scratch_dir( 'lint-parse' );
		$slug = 'caw-lint-parse';
		Fixtures::write_plugin( $dir, $slug, Fixtures::parse_error( $slug ) );

		$result = ( new LintGate() )->run( $dir );

		$this->assertFalse( $result->passed() );
		$this->assertNotEmpty( $result->details() );
		$this->assertStringContainsString( $slug, implode( "\n", $result->details() ) );
	}

	/**
	 * One bad file does not hide the rest: every file is linted regardless.
	 */
	public function test_one_bad_file_among_many(): void {
		$dir = $this->make_scratch_dir( 'lint-mixed' );
		Fixtures::write_plugin( $dir, 'good-a', Fixtures::clean( 'good-a' ) );
		Fixtures::write_plugin( $dir, 'good-b', Fixtures::clean( 'good-b' ) );
		Fixtures::write_plugin( $dir, 'bad-c', Fixtures::parse_error( 'bad-c' ) );

		$result = ( new LintGate() )->run( $dir );

		$this->assertFalse( $result->passed() );
		$this->assertStringContainsString( 'bad-c', implode( "\n", $result->details() ) );
	}

	/**
	 * A candidate with no PHP files cannot be installed and fails Gate 1.
	 */
	public function test_no_php_files_fails(): void {
		$dir = $this->make_scratch_dir( 'lint-empty' );
		file_put_contents( $dir . '/readme.txt', 'no php here' );

		$result = ( new LintGate() )->run( $dir );

		$this->assertFalse( $result->passed() );
	}
}
