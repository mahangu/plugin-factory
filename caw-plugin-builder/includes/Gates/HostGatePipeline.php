<?php
/**
 * Host gate pipeline.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

use CAW\PluginBuilder\Capabilities;
use CAW\PluginBuilder\Sentinel;
use CAW\PluginBuilder\Support\Logger;
use CAW\PluginBuilder\Support\Paths;

/**
 * Runs the three host safety gates in order, with hard stops between them.
 *
 * This is the gauntlet that agent-authored code must survive before it is
 * allowed to run in the live WordPress process. The ordering is deliberate and
 * is itself a safety property:
 *
 *   Gate 1  Lint every file, in separate processes, while the code is still in
 *           staging — BEFORE anything is copied near wp-content/plugins. A
 *           parse error is uncatchable, so it must be excluded before it can
 *           ever be reached by an include.
 *   (copy)  Only lint-clean code is copied into wp-content/plugins. An inactive
 *           plugin sitting there does not load on normal requests, so this is
 *           safe; OPcache is invalidated for every file written.
 *   Gate 2  Probe the copied plugin in a throwaway process: load it, run its
 *           activation hook, catch every failure mode.
 *   Gate 3  Activate it for real through WordPress's own guarded path.
 *
 * If any gate fails, the copy is removed from wp-content/plugins and the host
 * is left exactly as it was found.
 */
final class HostGatePipeline {

	/**
	 * Run the full gate gauntlet for a completed artifact.
	 *
	 * @param string $artifact_zip Absolute path to the validated artifact zip.
	 * @param string $slug         Plugin slug (the folder name to install as).
	 * @param int    $build_id     Build id, used to name the scratch directory.
	 * @return GateReport Combined gate report.
	 */
	public function install( string $artifact_zip, string $slug, int $build_id ): GateReport {
		$results = [];

		// Precondition: host install is only ever offered when the gates can
		// actually run. This is a belt-and-braces re-check.
		if ( ! Capabilities::can_install_locally() ) {
			return new GateReport(
				[],
				false,
				__( 'This host cannot run the safety gates, so local installation is disabled.', 'caw-plugin-builder' )
			);
		}

		$slug = $this->sanitize_slug( $slug );
		if ( '' === $slug ) {
			return new GateReport( [], false, __( 'The plugin slug is invalid.', 'caw-plugin-builder' ) );
		}

		if ( ! is_file( $artifact_zip ) ) {
			return new GateReport( [], false, __( 'The artifact zip could not be found.', 'caw-plugin-builder' ) );
		}

		$plugins_dir = WP_PLUGIN_DIR . '/' . $slug;
		if ( is_dir( $plugins_dir ) ) {
			return new GateReport(
				[],
				false,
				sprintf(
					/* translators: %s: plugin slug */
					__( 'A plugin folder named "%s" already exists. Remove it first or rebuild with a different slug.', 'caw-plugin-builder' ),
					$slug
				)
			);
		}

		// Extract the artifact into a fresh scratch directory under staging.
		$scratch = Paths::build_staging_dir( $build_id ) . '/install';
		Paths::rmtree( $scratch );
		wp_mkdir_p( $scratch );

		$candidate_root = $this->extract( $artifact_zip, $scratch, $slug );
		if ( '' === $candidate_root ) {
			Paths::rmtree( $scratch );
			return new GateReport( [], false, __( 'The artifact zip could not be safely extracted.', 'caw-plugin-builder' ) );
		}

		// ---- GATE 1: lint, while still in staging -------------------------
		$gate1 = ( new LintGate() )->run( $candidate_root );
		$results[] = $gate1;
		if ( ! $gate1->passed() ) {
			Paths::rmtree( $scratch );
			return new GateReport( $results, false, $gate1->summary() );
		}

		// ---- COPY: lint-clean code may now enter wp-content/plugins -------
		if ( ! $this->copy_tree( $candidate_root, $plugins_dir ) ) {
			Paths::rmtree( $scratch );
			Paths::rmtree( $plugins_dir );
			return new GateReport( $results, false, __( 'The candidate could not be copied into the plugins directory.', 'caw-plugin-builder' ) );
		}
		Paths::rmtree( $scratch );

		$main_file = $this->find_main_file( $plugins_dir );
		if ( '' === $main_file ) {
			Paths::rmtree( $plugins_dir );
			return new GateReport( $results, false, __( 'The candidate has no recognisable main plugin file.', 'caw-plugin-builder' ) );
		}
		$basename = $slug . '/' . $main_file;

		// ---- GATE 2: isolated runtime probe ------------------------------
		$gate2 = ( new RuntimeProbeGate() )->run( $plugins_dir . '/' . $main_file, $basename );
		$results[] = $gate2;
		if ( ! $gate2->passed() ) {
			Paths::rmtree( $plugins_dir );
			return new GateReport( $results, false, $gate2->summary() );
		}

		// ---- GATE 3: guarded activation ----------------------------------
		$gate3 = ( new ActivationGate() )->run( $basename );
		$results[] = $gate3;
		if ( ! $gate3->passed() ) {
			// ActivationGate already rolled back activation; remove the files.
			Paths::rmtree( $plugins_dir );
			Sentinel::untrack( $basename );
			return new GateReport( $results, false, $gate3->summary() );
		}

		Logger::info( 'Host gate gauntlet passed', [ 'plugin' => $basename ] );

		return new GateReport(
			$results,
			true,
			__( 'All three host gates passed. The plugin is installed and active.', 'caw-plugin-builder' )
		);
	}

	/**
	 * Safely extract an artifact zip into a scratch directory.
	 *
	 * Every entry is path-checked against zip-slip before it is written.
	 *
	 * @param string $zip     Artifact zip path.
	 * @param string $scratch Scratch directory.
	 * @param string $slug    Expected plugin slug.
	 * @return string Absolute path to the extracted plugin root, or '' on failure.
	 */
	private function extract( string $zip, string $scratch, string $slug ): string {
		$archive = new \ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			return '';
		}

		$scratch_real = (string) realpath( $scratch );
		if ( '' === $scratch_real ) {
			$archive->close();
			return '';
		}

		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$entry = (string) $archive->getNameIndex( $i );
			if ( '' === $entry ) {
				continue;
			}

			$relative = str_replace( '\\', '/', $entry );
			// Reject absolute paths and any traversal segment.
			if ( str_starts_with( $relative, '/' ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
				$archive->close();
				return '';
			}

			$target = $scratch_real . '/' . $relative;

			if ( str_ends_with( $relative, '/' ) ) {
				wp_mkdir_p( $target );
				continue;
			}

			wp_mkdir_p( dirname( $target ) );
			$contents = $archive->getFromIndex( $i );
			if ( false === $contents ) {
				$archive->close();
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $target, $contents );
		}
		$archive->close();

		// The artifact nests everything under the slug folder.
		$root = $scratch_real . '/' . $slug;
		if ( is_dir( $root ) ) {
			return $root;
		}
		// Tolerate a flat zip: treat the scratch dir itself as the root.
		return $scratch_real;
	}

	/**
	 * Recursively copy a directory tree, invalidating OPcache for each file.
	 *
	 * OPcache caches compiled bytecode keyed by path. Web and CLI processes
	 * keep separate OPcache state, so a freshly written file can otherwise be
	 * served as stale bytecode. Every PHP file written here is invalidated so
	 * Gate 2 and Gate 3 see the bytes actually on disk.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return bool True on success.
	 */
	private function copy_tree( string $source, string $destination ): bool {
		if ( ! is_dir( $source ) ) {
			return false;
		}
		if ( ! wp_mkdir_p( $destination ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
			$target   = $destination . '/' . $relative;

			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target ) ) {
					return false;
				}
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore WordPress.PHP.NoSilentErrors
				return false;
			}

			if ( 'php' === strtolower( $item->getExtension() ) && function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $target, true );
			}
		}

		return true;
	}

	/**
	 * Locate the main plugin file within an installed plugin folder.
	 *
	 * @param string $plugin_dir Absolute plugin directory.
	 * @return string Main file path relative to the plugin folder, or ''.
	 */
	private function find_main_file( string $plugin_dir ): string {
		$candidates = [];

		$entries = glob( $plugin_dir . '/*.php' );
		foreach ( is_array( $entries ) ? $entries : [] as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			if ( false !== stripos( $head, 'Plugin Name:' ) ) {
				$candidates[] = basename( $file );
			}
		}

		if ( [] === $candidates ) {
			return '';
		}
		sort( $candidates );
		return $candidates[0];
	}

	/**
	 * Sanitise a plugin slug to a safe folder name.
	 *
	 * @param string $slug Raw slug.
	 * @return string Safe slug, or '' when nothing usable remains.
	 */
	private function sanitize_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9\-]+/', '-', $slug );
		$slug = trim( (string) preg_replace( '/-+/', '-', $slug ), '-' );
		return $slug;
	}
}
