<?php
/**
 * WP-Cron build poller.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Cron;

use CAW\PluginBuilder\Agent\AgentService;
use CAW\PluginBuilder\Agent\AuthoredPlugin;
use CAW\PluginBuilder\Agent\BuildHandle;
use CAW\PluginBuilder\Agent\BuildProvider;
use CAW\PluginBuilder\Agent\BuildProgress;
use CAW\PluginBuilder\Agent\BuildSpec;
use CAW\PluginBuilder\Agent\CiResultsHarvester;
use CAW\PluginBuilder\Agent\ProviderException;
use CAW\PluginBuilder\Artifact\ArtifactBuilder;
use CAW\PluginBuilder\Build\Build;
use CAW\PluginBuilder\Build\BuildRepository;
use CAW\PluginBuilder\KeyResolver;
use CAW\PluginBuilder\Support\Logger;
use CAW\PluginBuilder\Support\Paths;

/**
 * Advances in-flight builds one step per cron tick.
 *
 * The poller is the engine of stage A (BUILD + CI). It never blocks: each tick
 * starts pending builds, polls running ones, and — when a build's sandbox work
 * is done — harvests the structured CI results, computes pass/fail with OUR
 * code, and packages the artifact.
 *
 * Managed Agents create endpoints are rate limited (~60 rpm), so the poller
 * runs on a multi-minute interval, advances only a few builds per tick, and
 * treats a retryable API error as "try again next tick" rather than a failure.
 */
final class Poller {

	/**
	 * Cron hook name.
	 */
	public const HOOK = 'caw_poll_builds';

	/**
	 * Custom cron schedule name.
	 */
	public const SCHEDULE = 'caw_poll_interval';

	/**
	 * Poll interval in seconds.
	 */
	private const INTERVAL = 120;

	/**
	 * Maximum builds advanced per tick (keeps well under create-endpoint limits).
	 */
	private const BATCH = 4;

	/**
	 * Max poll attempts before a running build is abandoned as timed out.
	 */
	private const MAX_POLL_ATTEMPTS = 120;

	/**
	 * Max retryable start failures before a pending build is abandoned.
	 */
	private const MAX_START_RETRIES = 8;

	/**
	 * Register cron wiring. Call once on boot.
	 *
	 * Also self-heals the recurring event: if it is missing (for example after
	 * a WP-CLI activation, where the plugin's own boot never ran during the
	 * activation request), it is rescheduled here on the next normal request.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'add_schedule' ] );
		add_action( self::HOOK, [ self::class, 'run' ] );
		self::schedule();
	}

	/**
	 * Add the custom poll interval to WordPress's cron schedules.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}> Schedules with the poll interval added.
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => self::INTERVAL,
			'display'  => __( 'Every two minutes (CAW Plugin Builder)', 'caw-plugin-builder' ),
		];
		return $schedules;
	}

	/**
	 * Ensure the recurring poll event is scheduled.
	 *
	 * The custom schedule must be registered with the `cron_schedules` filter
	 * before wp_schedule_event() is called, or WordPress rejects the unknown
	 * recurrence. Activation can run before the filter is otherwise added, so
	 * it is (idempotently) added here too.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', [ self::class, 'add_schedule' ] );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Remove the recurring poll event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Kick the poller as soon as possible (used right after a build is queued).
	 */
	public static function nudge(): void {
		if ( ! wp_next_scheduled( self::HOOK, [] ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Cron callback: advance every active build by one step.
	 */
	public static function run(): void {
		$repository = new BuildRepository();
		$builds     = $repository->find_active( self::BATCH );
		if ( [] === $builds ) {
			return;
		}

		$resolution = ( new KeyResolver() )->resolve();
		if ( ! $resolution->is_resolved() ) {
			Logger::warn( 'Poller has active builds but no API key resolves; leaving them queued' );
			return;
		}

		$service     = new AgentService( $resolution->key() );
		$provider    = $service->provider();
		$harvester   = new CiResultsHarvester();
		$artifacts   = new ArtifactBuilder();
		$poller      = new self();

		foreach ( $builds as $build ) {
			$poller->advance( $build, $provider, $harvester, $artifacts, $repository );
		}

		// Keep a single-event fallback alive while work remains.
		if ( $repository->has_active() ) {
			self::nudge();
		}
	}

	/**
	 * Advance one build by a single step.
	 *
	 * @param Build              $build      Build to advance.
	 * @param BuildProvider      $provider   Build provider.
	 * @param CiResultsHarvester $harvester  CI results harvester.
	 * @param ArtifactBuilder    $artifacts  Artifact builder.
	 * @param BuildRepository    $repository Build repository.
	 */
	private function advance(
		Build $build,
		BuildProvider $provider,
		CiResultsHarvester $harvester,
		ArtifactBuilder $artifacts,
		BuildRepository $repository
	): void {
		try {
			if ( Build::STATUS_PENDING === $build->status ) {
				$this->start( $build, $provider );
			} elseif ( Build::STATUS_BUILDING === $build->status ) {
				$this->poll( $build, $provider, $harvester, $artifacts );
			}
		} catch ( \Throwable $e ) {
			// An unexpected fault must never wedge a build forever.
			Logger::error( 'Unexpected poller fault', [ 'build' => $build->id, 'error' => Logger::redact( $e->getMessage() ) ] );
			$build->status = Build::STATUS_FAILED;
			$build->error  = __( 'The build failed because of an unexpected internal error.', 'caw-plugin-builder' );
		}

		$repository->save( $build );
	}

	/**
	 * Start a pending build.
	 *
	 * @param Build         $build    Build.
	 * @param BuildProvider $provider Provider.
	 */
	private function start( Build $build, BuildProvider $provider ): void {
		try {
			$handle               = $provider->start( $this->spec_for( $build ) );
			$build->provider_ref  = $handle->to_array();
			$build->status        = Build::STATUS_BUILDING;
			$build->poll_attempts = 0;
			$build->error         = '';
			Logger::info( 'Build started', [ 'build' => $build->id ] );
		} catch ( ProviderException $e ) {
			if ( $e->is_retryable() && $build->poll_attempts < self::MAX_START_RETRIES ) {
				++$build->poll_attempts;
				Logger::warn( 'Retryable error starting build; will retry', [ 'build' => $build->id ] );
				return;
			}
			$build->status = Build::STATUS_FAILED;
			$build->error  = $e->getMessage();
		}
	}

	/**
	 * Poll a running build and, on success, harvest and package it.
	 *
	 * @param Build              $build     Build.
	 * @param BuildProvider      $provider  Provider.
	 * @param CiResultsHarvester $harvester Harvester.
	 * @param ArtifactBuilder    $artifacts Artifact builder.
	 */
	private function poll(
		Build $build,
		BuildProvider $provider,
		CiResultsHarvester $harvester,
		ArtifactBuilder $artifacts
	): void {
		$handle   = BuildHandle::from_array( $build->provider_ref );
		$progress = $provider->poll( $handle );

		if ( BuildProgress::STATE_RUNNING === $progress->state() ) {
			++$build->poll_attempts;
			if ( $build->poll_attempts > self::MAX_POLL_ATTEMPTS ) {
				$provider->cancel( $handle );
				$build->status = Build::STATUS_FAILED;
				$build->error  = __( 'The build ran longer than the allowed window and was cancelled.', 'caw-plugin-builder' );
			}
			return;
		}

		if ( BuildProgress::STATE_FAILED === $progress->state() ) {
			$build->status = Build::STATUS_FAILED;
			$build->error  = $progress->message();
			return;
		}

		// STATE_SUCCEEDED: harvest the work, judge CI, package the artifact.
		$authored = $progress->authored();
		if ( ! $authored instanceof AuthoredPlugin || $authored->is_empty() ) {
			$build->status = Build::STATUS_FAILED;
			$build->error  = __( 'The agent reported success but submitted no usable files.', 'caw-plugin-builder' );
			return;
		}

		$this->write_staging( $build, $authored );

		$ci                = $harvester->harvest( $progress->raw_ci() );
		$build->ci_report  = $ci->to_array();

		if ( ! $ci->passed() ) {
			$build->status = Build::STATUS_FAILED;
			$build->error  = sprintf(
				/* translators: %s: CI summary */
				__( 'Sandbox CI did not pass: %s', 'caw-plugin-builder' ),
				$ci->summary()
			);
			Logger::info( 'Build failed sandbox CI', [ 'build' => $build->id ] );
			return;
		}

		try {
			$build->artifact_path = $artifacts->build( $build, $authored, $ci );
		} catch ( \RuntimeException $e ) {
			$build->status = Build::STATUS_FAILED;
			$build->error  = __( 'CI passed but the artifact could not be packaged: ', 'caw-plugin-builder' ) . $e->getMessage();
			return;
		}

		$build->status = Build::STATUS_COMPLETED;
		$build->error  = '';
		Logger::info( 'Build completed', [ 'build' => $build->id ] );
	}

	/**
	 * Write the harvested files into the build's staging directory.
	 *
	 * Agent-authored code lands ONLY here, under wp-content/uploads/caw-staging.
	 * It is never written into wp-content/plugins by this method.
	 *
	 * @param Build          $build    Build.
	 * @param AuthoredPlugin $authored Authored plugin.
	 */
	private function write_staging( Build $build, AuthoredPlugin $authored ): void {
		$staging = Paths::build_staging_dir( $build->id );
		if ( '' === $staging ) {
			return;
		}
		$src = $staging . '/src';
		Paths::rmtree( $src );
		wp_mkdir_p( $src );

		foreach ( $authored->files() as $relative => $contents ) {
			$target = $src . '/' . $relative;
			wp_mkdir_p( dirname( $target ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $target, $contents );
			if ( function_exists( 'opcache_invalidate' ) && str_ends_with( strtolower( $target ), '.php' ) ) {
				opcache_invalidate( $target, true );
			}
		}

		$build->staging_dir = $src;
	}

	/**
	 * Build the provider spec for a build record.
	 *
	 * @param Build $build Build.
	 * @return BuildSpec Spec.
	 */
	private function spec_for( Build $build ): BuildSpec {
		/**
		 * Filter the build spec before it is sent to the provider.
		 *
		 * @param BuildSpec $spec  Default spec.
		 * @param Build     $build The build record.
		 */
		return apply_filters(
			'caw_build_spec',
			new BuildSpec( $build->prompt, $build->slug ),
			$build
		);
	}
}
