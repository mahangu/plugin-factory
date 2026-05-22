<?php
/**
 * Tests for Gate 2 — isolated runtime probe.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Gates\RuntimeProbeGate;
use CAW\PluginBuilder\Tests\Support\Fixtures;

/**
 * Gate 2 proves a candidate RUNS, not merely that it parses. It loads the
 * candidate and runs its activation hook inside a throwaway process so that
 * every runtime failure mode — a catchable throw, an uncatchable fatal, an
 * activation-hook crash — is caught as data instead of white-screening a host.
 *
 * Each test installs its fixture into wp-content/plugins (where an inactive
 * plugin is harmless) and probes it there.
 */
final class RuntimeProbeGateTest extends IntegrationTestCase {

	/**
	 * A clean plugin loads and activates cleanly: Gate 2 passes.
	 */
	public function test_clean_plugin_passes(): void {
		[ $main, $basename ] = $this->install_fixture( 'probe-clean', 'clean' );

		$result = ( new RuntimeProbeGate() )->run( $main, $basename );

		$this->assertTrue( $result->passed(), $result->summary() . ' :: ' . implode( ' | ', $result->details() ) );
		$this->assertSame( 2, $result->number() );
	}

	/**
	 * A plugin that throws on load fails Gate 2 (catchable path).
	 */
	public function test_catchable_load_fatal_fails(): void {
		[ $main, $basename ] = $this->install_fixture( 'probe-throw', 'load_fatal_catchable' );

		$result = ( new RuntimeProbeGate() )->run( $main, $basename );

		$this->assertFalse( $result->passed() );
		$this->assertStringContainsString(
			'deliberate load-time crash',
			implode( "\n", $result->details() )
		);
	}

	/**
	 * A plugin that fatals uncatchably on load fails Gate 2 (shutdown net).
	 *
	 * A failed require is an E_ERROR no try/catch can intercept; only the
	 * harness's register_shutdown_function can report it.
	 */
	public function test_uncatchable_load_fatal_fails(): void {
		[ $main, $basename ] = $this->install_fixture( 'probe-fatal', 'load_fatal_uncatchable' );

		$result = ( new RuntimeProbeGate() )->run( $main, $basename );

		$this->assertFalse(
			$result->passed(),
			'An uncatchable load fatal must be caught by the harness shutdown function.'
		);
	}

	/**
	 * A plugin whose activation hook crashes fails Gate 2.
	 */
	public function test_activation_hook_crash_fails(): void {
		[ $main, $basename ] = $this->install_fixture( 'probe-activate', 'activation_fatal' );

		$result = ( new RuntimeProbeGate() )->run( $main, $basename );

		$this->assertFalse( $result->passed() );
		$this->assertStringContainsString(
			'activation',
			strtolower( $result->summary() . ' ' . implode( ' ', $result->details() ) )
		);
	}

	/**
	 * Install a fixture plugin into wp-content/plugins for probing.
	 *
	 * @param string $label   Short unique label.
	 * @param string $fixture Fixtures method name.
	 * @return array{0: string, 1: string} Main file path and plugin basename.
	 */
	private function install_fixture( string $label, string $fixture ): array {
		$slug = $this->unique_slug( $label );
		$dir  = Fixtures::write_plugin( WP_PLUGIN_DIR, $slug, Fixtures::{$fixture}( $slug ) );
		$this->track_plugin_dir( $dir );

		return [ $dir . '/' . $slug . '.php', $slug . '/' . $slug . '.php' ];
	}
}
