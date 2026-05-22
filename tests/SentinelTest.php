<?php
/**
 * Tests for the activation sentinel + managed-plugin registry.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Sentinel;

/**
 * The sentinel is the contract between Gate 3 and the watchdog. These tests
 * pin the option shape both sides depend on.
 */
final class SentinelTest extends IntegrationTestCase {

	/**
	 * Clear sentinel options before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( Sentinel::OPTION_ACTIVATING );
		delete_option( Sentinel::OPTION_MANAGED );
	}

	/**
	 * Clear sentinel options after each test.
	 */
	protected function tearDown(): void {
		delete_option( Sentinel::OPTION_ACTIVATING );
		delete_option( Sentinel::OPTION_MANAGED );
		parent::tearDown();
	}

	/**
	 * begin_activation arms the sentinel; end_activation clears it.
	 */
	public function test_begin_and_end_activation(): void {
		$this->assertSame( '', Sentinel::activating_plugin() );

		Sentinel::begin_activation( 'demo/demo.php' );
		$this->assertSame( 'demo/demo.php', Sentinel::activating_plugin() );

		$stored = get_option( Sentinel::OPTION_ACTIVATING );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'time', $stored );
		$this->assertIsInt( $stored['time'] );

		Sentinel::end_activation();
		$this->assertSame( '', Sentinel::activating_plugin() );
	}

	/**
	 * Tracking and untracking maintain the managed-plugin list without dupes.
	 */
	public function test_track_and_untrack(): void {
		$this->assertSame( [], Sentinel::managed() );

		Sentinel::track( 'one/one.php' );
		Sentinel::track( 'two/two.php' );
		Sentinel::track( 'one/one.php' ); // Duplicate: must be ignored.

		$this->assertSame( [ 'one/one.php', 'two/two.php' ], Sentinel::managed() );

		Sentinel::untrack( 'one/one.php' );
		$this->assertSame( [ 'two/two.php' ], Sentinel::managed() );
	}

	/**
	 * The sentinel staleness threshold matches the watchdog's literal.
	 */
	public function test_stale_threshold_is_ten_seconds(): void {
		$this->assertSame( 10, Sentinel::STALE_SECONDS );
	}
}
