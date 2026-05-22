<?php
/**
 * Validated artifact builder.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Artifact;

use CAW\PluginBuilder\Agent\AuthoredPlugin;
use CAW\PluginBuilder\Agent\CiReport;
use CAW\PluginBuilder\Build\Build;
use CAW\PluginBuilder\Support\Logger;
use CAW\PluginBuilder\Support\Paths;

/**
 * Packages a passing build into its final artifact: a downloadable plugin zip.
 *
 * This is stage B of the architecture — ARTIFACT. It runs only after sandbox
 * CI has been independently judged to pass (see CiReport). The artifact is
 * self-describing: the harvested CI report (caw-validation.json) and a
 * human-readable VALIDATION.md are bundled INSIDE the plugin folder, so the
 * provenance travels with the code wherever the zip goes.
 *
 * An artifact passing sandbox CI is NOT the same as being safe to run on the
 * host. The bundled VALIDATION.md says so explicitly: the host gate gauntlet
 * still runs independently if the admin chooses to install locally.
 */
final class ArtifactBuilder {

	/**
	 * Build the artifact zip for a completed build.
	 *
	 * @param Build          $build    The build (used for id, slug, prompt).
	 * @param AuthoredPlugin $authored The harvested plugin files.
	 * @param CiReport       $ci       The host-computed CI report.
	 * @return string Absolute path to the artifact zip.
	 *
	 * @throws \RuntimeException When the artifact cannot be written.
	 */
	public function build( Build $build, AuthoredPlugin $authored, CiReport $ci ): string {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'The PHP zip extension is required to build artifacts.' );
		}

		$folder = $authored->root_folder();
		if ( '' === $folder ) {
			$folder = $build->slug;
		}
		$folder = $this->safe_folder( $folder );

		$artifact_path = Paths::artifact_path( $build->id );
		if ( '' === $artifact_path ) {
			throw new \RuntimeException( 'The artifacts directory is unavailable.' );
		}
		if ( is_file( $artifact_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.PHP.NoSilentErrors
			@unlink( $artifact_path );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $artifact_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Could not open the artifact zip for writing.' );
		}

		// Re-root every authored file under a single, normalised plugin folder.
		foreach ( $authored->files() as $relative => $contents ) {
			$entry = $this->reroot( $relative, $authored->root_folder(), $folder );
			$zip->addFromString( $entry, $contents );
		}

		// Bundle provenance INSIDE the plugin folder.
		$zip->addFromString( $folder . '/VALIDATION.md', $this->validation_markdown( $build, $authored, $ci ) );
		$zip->addFromString(
			$folder . '/caw-validation.json',
			(string) wp_json_encode(
				[
					'build_id'   => $build->id,
					'slug'       => $build->slug,
					'created_at' => $build->created_at,
					'ci'         => $ci->to_array(),
				],
				JSON_PRETTY_PRINT
			)
		);

		$zip->close();

		Logger::info( 'Built artifact', [ 'build' => $build->id, 'path' => $artifact_path ] );

		return $artifact_path;
	}

	/**
	 * Re-root a relative path from the authored folder onto the artifact folder.
	 *
	 * @param string $relative      Authored relative path.
	 * @param string $authored_root The authored top-level folder, if any.
	 * @param string $folder        The artifact's plugin folder name.
	 * @return string Zip entry path.
	 */
	private function reroot( string $relative, string $authored_root, string $folder ): string {
		if ( '' !== $authored_root && str_starts_with( $relative, $authored_root . '/' ) ) {
			$relative = substr( $relative, strlen( $authored_root ) + 1 );
		}
		return $folder . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Render the human-readable VALIDATION.md bundled with the plugin.
	 *
	 * @param Build          $build    Build.
	 * @param AuthoredPlugin $authored Authored plugin.
	 * @param CiReport       $ci       CI report.
	 * @return string Markdown.
	 */
	private function validation_markdown( Build $build, AuthoredPlugin $authored, CiReport $ci ): string {
		$phpunit = $ci->phpunit();
		$phpstan = $ci->phpstan();

		$lint_failed = 0;
		foreach ( $ci->lint() as $entry ) {
			if ( 0 !== (int) $entry['exit_code'] ) {
				++$lint_failed;
			}
		}

		$lines = [
			'# Validation report',
			'',
			'This plugin was authored and tested by an Anthropic Managed Agent in a',
			'hosted sandbox, then validated by CAW Plugin Builder.',
			'',
			'## Build',
			'',
			'- Build id: ' . $build->id,
			'- Slug: ' . $build->slug,
			'- Created: ' . $build->created_at . ' UTC',
			'- Files: ' . $authored->count(),
			'',
			'## Request',
			'',
			'> ' . str_replace( "\n", "\n> ", trim( $build->prompt ) ),
			'',
			'## Sandbox CI result',
			'',
			'**' . ( $ci->passed() ? 'PASSED' : 'FAILED' ) . '** — ' . $ci->summary(),
			'',
			'- Lint: ' . count( $ci->lint() ) . ' files checked, ' . $lint_failed . ' failing',
			'- PHPUnit: ' . $phpunit['tests'] . ' tests, ' . $phpunit['failures'] . ' failures, ' . $phpunit['errors'] . ' errors',
			'- PHPStan: ' . $phpstan['errors'] . ' issues across ' . $phpstan['files'] . ' files',
			'',
			'## How this result was reached',
			'',
			'The agent RAN continuous integration inside the sandbox, but it did not',
			'get to PASS it. CAW Plugin Builder harvested the structured CI output',
			'(JUnit XML, lint exit codes, PHPStan JSON) and computed the pass/fail',
			'verdict itself. The agent\'s own claims were not consulted.',
			'',
			'## Before you install this on a live site',
			'',
			'A passing sandbox CI run is NOT a guarantee that this plugin is safe to',
			'run in your WordPress process. If you install it through CAW Plugin',
			'Builder, three further host-side gates run independently — separate-',
			'process lint, an isolated runtime probe, and a guarded activation —',
			'because they execute where the sandbox CI never did: on your host.',
		];

		if ( [] !== $ci->notes() ) {
			$lines[] = '';
			$lines[] = '## Harvest notes';
			$lines[] = '';
			foreach ( $ci->notes() as $note ) {
				$lines[] = '- ' . $note;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Sanitise a folder name for use inside the zip.
	 *
	 * @param string $folder Raw folder name.
	 * @return string Safe folder name.
	 */
	private function safe_folder( string $folder ): string {
		$folder = strtolower( trim( $folder ) );
		$folder = (string) preg_replace( '/[^a-z0-9\-]+/', '-', $folder );
		$folder = trim( (string) preg_replace( '/-+/', '-', $folder ), '-' );
		return '' !== $folder ? $folder : 'caw-plugin';
	}
}
