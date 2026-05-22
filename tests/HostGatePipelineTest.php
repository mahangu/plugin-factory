<?php
/**
 * End-to-end tests for the host gate pipeline.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Gates\HostGatePipeline;
use CAW\PluginBuilder\Sentinel;
use CAW\PluginBuilder\Support\Paths;
use CAW\PluginBuilder\Tests\Support\Fixtures;

/**
 * Drives the full three-gate gauntlet against a real WordPress 7.0.
 *
 * These are the tests that prove the central safety promise: a clean artifact
 * installs and activates, while a broken one is stopped at the correct gate
 * and leaves wp-content/plugins exactly as it was found.
 */
final class HostGatePipelineTest extends IntegrationTestCase {

	/**
	 * Ensure plugin admin functions are available.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * A clean artifact passes all three gates and ends up active.
	 */
	public function test_clean_artifact_installs_and_activates(): void {
		$slug     = $this->unique_slug( 'pipe-ok' );
		$build_id = random_int( 100000, 999999 );
		$zip      = $this->make_scratch_file( '.zip' );
		Fixtures::write_artifact_zip( $zip, $slug, Fixtures::clean( $slug ) );

		$this->track_plugin_dir( WP_PLUGIN_DIR . '/' . $slug );
		$this->track_dir( Paths::build_staging_dir( $build_id ) );

		$report = $this->ignoringHeaderWarnings(
			static fn () => ( new HostGatePipeline() )->install( $zip, $slug, $build_id )
		);

		$this->assertTrue( $report->passed(), $report->message() );
		$this->assertTrue( $report->installed() );
		$this->assertCount( 3, $report->results() );
		$this->assertTrue( is_plugin_active( $slug . '/' . $slug . '.php' ) );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/' . $slug );

		Sentinel::untrack( $slug . '/' . $slug . '.php' );
	}

	/**
	 * A parse-error artifact is stopped at Gate 1, before it reaches plugins/.
	 */
	public function test_parse_error_artifact_stopped_at_gate_1(): void {
		$slug     = $this->unique_slug( 'pipe-parse' );
		$build_id = random_int( 100000, 999999 );
		$zip      = $this->make_scratch_file( '.zip' );
		Fixtures::write_artifact_zip( $zip, $slug, Fixtures::parse_error( $slug ) );

		$this->track_plugin_dir( WP_PLUGIN_DIR . '/' . $slug );
		$this->track_dir( Paths::build_staging_dir( $build_id ) );

		$report = ( new HostGatePipeline() )->install( $zip, $slug, $build_id );

		$this->assertFalse( $report->passed() );
		$this->assertFalse( $report->installed() );

		$failure = $report->first_failure();
		$this->assertNotNull( $failure );
		$this->assertSame( 1, $failure->number() );

		// Lint-failing code must never have been copied into plugins/.
		$this->assertDirectoryDoesNotExist(
			WP_PLUGIN_DIR . '/' . $slug,
			'A candidate that failed Gate 1 must not appear in wp-content/plugins.'
		);
	}

	/**
	 * A load-fatal artifact is stopped at Gate 2 and removed from plugins/.
	 */
	public function test_load_fatal_artifact_stopped_at_gate_2(): void {
		$slug     = $this->unique_slug( 'pipe-fatal' );
		$build_id = random_int( 100000, 999999 );
		$zip      = $this->make_scratch_file( '.zip' );
		Fixtures::write_artifact_zip( $zip, $slug, Fixtures::load_fatal_uncatchable( $slug ) );

		$this->track_plugin_dir( WP_PLUGIN_DIR . '/' . $slug );
		$this->track_dir( Paths::build_staging_dir( $build_id ) );

		$report = ( new HostGatePipeline() )->install( $zip, $slug, $build_id );

		$this->assertFalse( $report->passed() );
		$this->assertFalse( $report->installed() );

		$failure = $report->first_failure();
		$this->assertNotNull( $failure );
		$this->assertSame( 2, $failure->number() );

		// Gate 1 passed (it parses), so it was copied — then Gate 2 failed and
		// the copy must have been rolled back.
		$this->assertDirectoryDoesNotExist(
			WP_PLUGIN_DIR . '/' . $slug,
			'A candidate that failed Gate 2 must be removed from wp-content/plugins.'
		);
		$this->assertFalse( is_plugin_active( $slug . '/' . $slug . '.php' ) );
	}

	/**
	 * Re-using the slug of an existing plugin folder is refused outright.
	 */
	public function test_existing_plugin_folder_is_refused(): void {
		$slug = $this->unique_slug( 'pipe-collide' );
		$dir  = Fixtures::write_plugin( WP_PLUGIN_DIR, $slug, Fixtures::clean( $slug ) );
		$this->track_plugin_dir( $dir );

		$build_id = random_int( 100000, 999999 );
		$zip      = $this->make_scratch_file( '.zip' );
		Fixtures::write_artifact_zip( $zip, $slug, Fixtures::clean( $slug ) );
		$this->track_dir( Paths::build_staging_dir( $build_id ) );

		$report = ( new HostGatePipeline() )->install( $zip, $slug, $build_id );

		$this->assertFalse( $report->passed() );
		$this->assertFalse( $report->installed() );
	}
}
