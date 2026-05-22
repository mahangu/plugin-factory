<?php
/**
 * Tests for the watchdog mu-plugin.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

/**
 * Exercises the lock-out recovery watchdog.
 *
 * The watchdog runs at mu-plugin load time, so its two automatic recovery
 * mechanisms — the staleness sweep and the fatal-error shutdown handler — are
 * tested by spawning fresh WordPress-loading subprocesses with the failure
 * preconditions arranged in advance. Its pure helpers are tested directly,
 * since the watchdog file is already loaded into the test process.
 */
final class WatchdogTest extends IntegrationTestCase {

	private const FAKE_PLUGIN = 'caw-watchdog-fixture/caw-watchdog-fixture.php';

	/** @var string[] Snapshot of active_plugins to restore. */
	private array $saved_active = [];

	/**
	 * Snapshot mutable options before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$active             = get_option( 'active_plugins', [] );
		$this->saved_active = is_array( $active ) ? $active : [];
	}

	/**
	 * Restore options after each test.
	 */
	protected function tearDown(): void {
		update_option( 'active_plugins', $this->saved_active );
		delete_option( 'caw_activating' );
		delete_option( 'caw_managed_plugins' );
		delete_option( 'caw_watchdog_recovery' );
		wp_cache_flush();
		parent::tearDown();
	}

	/**
	 * The fatal-type classifier accepts fatals and rejects warnings.
	 */
	public function test_is_fatal_classifier(): void {
		$this->assertTrue( caw_watchdog_is_fatal( [ 'type' => E_ERROR ] ) );
		$this->assertTrue( caw_watchdog_is_fatal( [ 'type' => E_PARSE ] ) );
		$this->assertTrue( caw_watchdog_is_fatal( [ 'type' => E_COMPILE_ERROR ] ) );
		$this->assertFalse( caw_watchdog_is_fatal( [ 'type' => E_WARNING ] ) );
		$this->assertFalse( caw_watchdog_is_fatal( [ 'type' => E_NOTICE ] ) );
		$this->assertFalse( caw_watchdog_is_fatal( null ) );
	}

	/**
	 * force_deactivate removes an active plugin and is idempotent.
	 */
	public function test_force_deactivate(): void {
		update_option( 'active_plugins', array_merge( $this->saved_active, [ self::FAKE_PLUGIN ] ) );

		$this->assertTrue( caw_watchdog_force_deactivate( self::FAKE_PLUGIN ) );
		$this->assertNotContains( self::FAKE_PLUGIN, get_option( 'active_plugins' ) );

		// Already gone: a second call is a no-op.
		$this->assertFalse( caw_watchdog_force_deactivate( self::FAKE_PLUGIN ) );
	}

	/**
	 * A STALE activation sentinel is swept on the next WordPress load.
	 */
	public function test_stale_sentinel_is_swept(): void {
		update_option( 'active_plugins', array_merge( $this->saved_active, [ self::FAKE_PLUGIN ] ) );
		update_option(
			'caw_activating',
			[ 'plugin' => self::FAKE_PLUGIN, 'time' => time() - 30 ]
		);

		$this->load_wordpress_in_subprocess();
		wp_cache_flush();

		$this->assertNotContains(
			self::FAKE_PLUGIN,
			get_option( 'active_plugins' ),
			'A stale activation sentinel must trigger a sweep.'
		);
		$this->assertFalse( get_option( 'caw_activating' ) );
	}

	/**
	 * A FRESH activation sentinel is left alone (the activation may still finish).
	 */
	public function test_fresh_sentinel_is_not_swept(): void {
		update_option( 'active_plugins', array_merge( $this->saved_active, [ self::FAKE_PLUGIN ] ) );
		update_option(
			'caw_activating',
			[ 'plugin' => self::FAKE_PLUGIN, 'time' => time() ]
		);

		$this->load_wordpress_in_subprocess();
		wp_cache_flush();

		$this->assertContains(
			self::FAKE_PLUGIN,
			get_option( 'active_plugins' ),
			'A fresh sentinel within the safety window must not be swept.'
		);
	}

	/**
	 * A fatal during an armed activation is caught by the shutdown handler.
	 */
	public function test_fatal_during_activation_is_recovered(): void {
		update_option( 'active_plugins', array_merge( $this->saved_active, [ self::FAKE_PLUGIN ] ) );
		update_option(
			'caw_activating',
			[ 'plugin' => self::FAKE_PLUGIN, 'time' => time() ]
		);

		// A subprocess that boots WordPress and then fatals while the sentinel
		// is armed — exactly the activation-crash scenario.
		$script = sprintf(
			'<?php require %s; caw_watchdog_fixture_undefined_call();',
			var_export( CAW_TEST_WP_PATH . '/wp-load.php', true )
		);
		$this->run_php_subprocess( $script );
		wp_cache_flush();

		$this->assertNotContains(
			self::FAKE_PLUGIN,
			get_option( 'active_plugins' ),
			'A fatal during an armed activation must deactivate the plugin.'
		);
		$this->assertFalse( get_option( 'caw_activating' ) );
	}

	/**
	 * Boot WordPress once in a clean subprocess (triggers the mu-plugin).
	 */
	private function load_wordpress_in_subprocess(): void {
		$script = sprintf(
			'<?php require %s; echo "loaded";',
			var_export( CAW_TEST_WP_PATH . '/wp-load.php', true )
		);
		$this->run_php_subprocess( $script );
	}

	/**
	 * Write a PHP script to a scratch file and run it in a subprocess.
	 *
	 * @param string $script PHP source.
	 */
	private function run_php_subprocess( string $script ): void {
		$file = $this->make_scratch_file( '.php' );
		file_put_contents( $file, $script );
		$output = [];
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	}
}
