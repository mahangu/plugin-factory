<?php
/**
 * Tests for the WP-Cron build poller.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Agent\AuthoredPlugin;
use CAW\PluginBuilder\Agent\BuildHandle;
use CAW\PluginBuilder\Agent\BuildProgress;
use CAW\PluginBuilder\Build\Build;
use CAW\PluginBuilder\Build\BuildRepository;
use CAW\PluginBuilder\Cron\Poller;
use CAW\PluginBuilder\Support\Paths;
use CAW\PluginBuilder\Tests\Support\FakeProvider;
use CAW\PluginBuilder\Tests\Support\Fixtures;

/**
 * Drives the poller's build state machine with a scripted provider.
 *
 * This is where the "agent runs CI, our code passes CI" rule is proven
 * end-to-end: the fake provider hands back RAW CI artifacts, and it is the
 * poller's own harvesting that decides completed vs. failed.
 */
final class PollerTest extends IntegrationTestCase {

	private BuildRepository $repo;

	/** @var int[] Build ids created by a test, for cleanup. */
	private array $build_ids = [];

	/** @var string|false Saved environment key. */
	private string|false $saved_env = false;

	/**
	 * Arrange a resolvable API key so the poller proceeds past key resolution.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->repo      = new BuildRepository();
		$this->saved_env = getenv( 'ANTHROPIC_API_KEY' );
		putenv( 'ANTHROPIC_API_KEY=sk-ant-fake-for-poller' );
		delete_transient( 'caw_poll_lock' );
	}

	/**
	 * Remove the scripted provider, restore the key, and delete test builds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'caw_build_provider' );

		if ( false === $this->saved_env ) {
			putenv( 'ANTHROPIC_API_KEY' );
		} else {
			putenv( 'ANTHROPIC_API_KEY=' . $this->saved_env );
		}

		foreach ( $this->build_ids as $id ) {
			$path = Paths::artifact_path( $id );
			if ( '' !== $path && is_file( $path ) ) {
				unlink( $path );
			}
			$staging = Paths::build_staging_dir( $id );
			if ( '' !== $staging ) {
				$this->rmtree( $staging );
			}
			$this->repo->delete( $id );
		}
		$this->build_ids = [];

		delete_transient( 'caw_poll_lock' );
		delete_option( 'caw_last_poll' );

		parent::tearDown();
	}

	/**
	 * A pending build is handed to the provider and moves to "building".
	 */
	public function test_pending_build_is_started(): void {
		$this->use_provider( new FakeProvider() );
		$build = $this->insert_build( Build::STATUS_PENDING );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_BUILDING, $reloaded->status );
		$this->assertNotEmpty( $reloaded->provider_ref );
	}

	/**
	 * A still-running build stays "building" and records the poll attempt.
	 */
	public function test_running_build_stays_building(): void {
		$this->use_provider( new FakeProvider( BuildProgress::running( 'still going' ) ) );
		$build = $this->insert_build( Build::STATUS_BUILDING );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_BUILDING, $reloaded->status );
		$this->assertSame( 1, $reloaded->poll_attempts );
	}

	/**
	 * A submission whose harvested CI passes completes with an artifact.
	 */
	public function test_passing_ci_completes_the_build(): void {
		$authored = new AuthoredPlugin(
			[ 'demo/demo.php' => Fixtures::clean( 'demo' ) ]
		);
		$progress = BuildProgress::succeeded(
			$authored,
			[
				'lint'              => [ [ 'file' => 'demo/demo.php', 'exit_code' => 0, 'message' => 'ok' ] ],
				'phpunit_junit_xml' => '<?xml version="1.0"?><testsuites><testsuite name="s" tests="3" failures="0" errors="0" skipped="0" assertions="3"/></testsuites>',
				'phpstan_json'      => '',
			]
		);
		$this->use_provider( new FakeProvider( $progress ) );
		$build = $this->insert_build( Build::STATUS_BUILDING );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_COMPLETED, $reloaded->status );
		$this->assertTrue( $reloaded->has_artifact() );
		$this->assertTrue( $reloaded->ci_report['passed'] );
	}

	/**
	 * A submission whose harvested CI fails ends as failed, with no artifact.
	 *
	 * The provider supplies raw artifacts only; the poller's harvesting decides
	 * the verdict — the provider never claims success.
	 */
	public function test_failing_ci_fails_the_build(): void {
		$authored = new AuthoredPlugin(
			[ 'demo/demo.php' => Fixtures::clean( 'demo' ) ]
		);
		$progress = BuildProgress::succeeded(
			$authored,
			[
				// A lint failure: our harvester must judge this CI as failed.
				'lint'              => [ [ 'file' => 'demo/demo.php', 'exit_code' => 255, 'message' => 'parse error' ] ],
				'phpunit_junit_xml' => '<?xml version="1.0"?><testsuites><testsuite name="s" tests="3" failures="0" errors="0" skipped="0" assertions="3"/></testsuites>',
				'phpstan_json'      => '',
			]
		);
		$this->use_provider( new FakeProvider( $progress ) );
		$build = $this->insert_build( Build::STATUS_BUILDING );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_FAILED, $reloaded->status );
		$this->assertFalse( $reloaded->has_artifact() );
		$this->assertFalse( $reloaded->ci_report['passed'] );
	}

	/**
	 * A build whose files cannot be written to staging is failed, not completed.
	 *
	 * A build with no files on disk must never be allowed to look completed.
	 */
	public function test_staging_write_failure_fails_the_build(): void {
		$authored = new AuthoredPlugin(
			[ 'demo/demo.php' => Fixtures::clean( 'demo' ) ]
		);
		$progress = BuildProgress::succeeded(
			$authored,
			[
				'lint'              => [ [ 'file' => 'demo/demo.php', 'exit_code' => 0, 'message' => 'ok' ] ],
				'phpunit_junit_xml' => '<?xml version="1.0"?><testsuites><testsuite name="s" tests="1" failures="0" errors="0" skipped="0" assertions="1"/></testsuites>',
				'phpstan_json'      => '',
			]
		);
		$this->use_provider( new FakeProvider( $progress ) );
		$build = $this->insert_build( Build::STATUS_BUILDING );

		// Block the staging write by occupying the target "src" path with a
		// file, so the directory it must become cannot be created.
		$staging = Paths::build_staging_dir( $build->id );
		$this->assertNotSame( '', $staging );
		file_put_contents( $staging . '/src', 'not a directory' );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_FAILED, $reloaded->status );
		$this->assertStringContainsString( 'staging', $reloaded->error );
		$this->assertFalse( $reloaded->has_artifact() );
	}

	/**
	 * A failed poll result fails the build.
	 */
	public function test_failed_progress_fails_the_build(): void {
		$this->use_provider( new FakeProvider( BuildProgress::failed( 'the sandbox session ended badly' ) ) );
		$build = $this->insert_build( Build::STATUS_BUILDING );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame( Build::STATUS_FAILED, $reloaded->status );
		$this->assertStringContainsString( 'sandbox session', $reloaded->error );
	}

	/**
	 * A poll run is skipped entirely while another run holds the lock.
	 *
	 * Without the lock, an overlapping recurring tick and caw_poll_now nudge
	 * could both start the same pending build — duplicate sandbox sessions and
	 * double billing.
	 */
	public function test_run_is_skipped_when_lock_is_held(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'The transient-based lock path requires no persistent object cache.' );
		}

		$this->use_provider( new FakeProvider() );
		$build = $this->insert_build( Build::STATUS_PENDING );
		delete_option( 'caw_last_poll' );

		// Simulate another poll run already holding the lock.
		set_transient( 'caw_poll_lock', time(), 300 );

		Poller::run();

		$reloaded = $this->repo->find( $build->id );
		$this->assertNotNull( $reloaded );
		$this->assertSame(
			Build::STATUS_PENDING,
			$reloaded->status,
			'A locked-out poll run must not advance any build.'
		);
		$this->assertSame(
			0,
			(int) get_option( 'caw_last_poll', 0 ),
			'A locked-out run did not poll, so it must not record a poll timestamp.'
		);

		delete_transient( 'caw_poll_lock' );
	}

	/**
	 * A completed poll run records its timestamp for the diagnostics screen.
	 */
	public function test_run_records_last_poll_timestamp(): void {
		$this->use_provider( new FakeProvider() );
		delete_option( 'caw_last_poll' );

		Poller::run();

		$this->assertGreaterThan(
			0,
			(int) get_option( 'caw_last_poll', 0 ),
			'Poller::run() must record when it last ran.'
		);
	}

	/**
	 * Route the poller's provider through a scripted fake.
	 *
	 * @param FakeProvider $provider Fake provider.
	 */
	private function use_provider( FakeProvider $provider ): void {
		add_filter( 'caw_build_provider', static fn () => $provider );
	}

	/**
	 * Insert a build in the given status and register it for cleanup.
	 *
	 * @param string $status Build status.
	 * @return Build The inserted build.
	 */
	private function insert_build( string $status ): Build {
		$build         = new Build();
		$build->status = $status;
		$build->slug   = 'poller-' . strtolower( wp_generate_password( 6, false, false ) );
		$build->prompt = 'A build for the poller tests.';
		if ( Build::STATUS_BUILDING === $status ) {
			$build->provider_ref = ( new BuildHandle( 'fake', [ 'session_id' => 'fake-session' ] ) )->to_array();
		}
		$build               = $this->repo->insert( $build );
		$this->build_ids[]   = $build->id;
		return $build;
	}
}
