<?php
/**
 * Tests for the validated artifact builder.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Agent\AuthoredPlugin;
use CAW\PluginBuilder\Agent\CiReport;
use CAW\PluginBuilder\Artifact\ArtifactBuilder;
use CAW\PluginBuilder\Build\Build;
use CAW\PluginBuilder\Support\Paths;

/**
 * The artifact is the stage-B deliverable: a plugin zip with its provenance
 * bundled inside. These tests confirm the zip is well formed and that
 * VALIDATION.md and the machine-readable report travel with the code.
 */
final class ArtifactBuilderTest extends IntegrationTestCase {

	/**
	 * A completed build is packaged into a zip carrying its provenance.
	 */
	public function test_builds_artifact_with_bundled_provenance(): void {
		$build         = new Build();
		$build->id     = random_int( 100000, 999999 );
		$build->slug   = 'demo-plugin';
		$build->prompt = 'A demo plugin.';
		$build->created_at = '2026-05-22 00:00:00';

		$authored = new AuthoredPlugin(
			[
				'demo-plugin/demo-plugin.php' => "<?php\n/* Plugin Name: Demo */",
				'demo-plugin/inc/helper.php'  => '<?php // helper',
			]
		);
		$ci = CiReport::from_array(
			[
				'lint'          => [ [ 'file' => 'demo-plugin.php', 'exit_code' => 0, 'message' => 'ok' ] ],
				'phpunit'       => [ 'tests' => 4, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'assertions' => 9 ],
				'phpstan'       => [ 'errors' => 0, 'files' => 2 ],
				'junit_present' => true,
			]
		);

		$path = ( new ArtifactBuilder() )->build( $build, $authored, $ci );
		$this->track_dir( Paths::build_staging_dir( $build->id ) );
		$this->track_file( $path );

		$this->assertFileExists( $path );

		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $path ) );

		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}

		$this->assertContains( 'demo-plugin/demo-plugin.php', $names );
		$this->assertContains( 'demo-plugin/inc/helper.php', $names );
		$this->assertContains( 'demo-plugin/VALIDATION.md', $names, 'VALIDATION.md must be bundled inside the plugin.' );
		$this->assertContains( 'demo-plugin/caw-validation.json', $names, 'The CI report must be bundled inside the plugin.' );

		$report = json_decode( (string) $zip->getFromName( 'demo-plugin/caw-validation.json' ), true );
		$zip->close();

		$this->assertIsArray( $report );
		$this->assertSame( $build->id, $report['build_id'] );
		$this->assertTrue( $report['ci']['passed'] );
	}
}
