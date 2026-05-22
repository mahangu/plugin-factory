<?php
/**
 * Tests for Gate 3 — guarded activation.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Gates\ActivationGate;
use CAW\PluginBuilder\Sentinel;
use CAW\PluginBuilder\Tests\Support\Fixtures;

/**
 * Gate 3 activates a candidate through WordPress's own guarded path. These
 * tests confirm a clean plugin ends up genuinely active, that WordPress's
 * validation refusals are reported as a gate failure, and that the activation
 * sentinel is always cleared so the watchdog is not left armed.
 */
final class ActivationGateTest extends IntegrationTestCase {

	/**
	 * Ensure plugin admin functions are available.
	 */
	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * A clean plugin activates and is genuinely active afterwards.
	 */
	public function test_clean_plugin_activates(): void {
		$slug     = $this->unique_slug( 'activate-ok' );
		$dir      = Fixtures::write_plugin( WP_PLUGIN_DIR, $slug, Fixtures::clean( $slug ) );
		$this->track_plugin_dir( $dir );
		$basename = $slug . '/' . $slug . '.php';

		$result = $this->ignoringHeaderWarnings(
			static fn () => ( new ActivationGate() )->run( $basename )
		);

		$this->assertTrue( $result->passed(), $result->summary() );
		$this->assertTrue( is_plugin_active( $basename ) );
		$this->assertSame( 3, $result->number() );

		// The sentinel must not be left armed after a successful activation.
		$this->assertSame( '', Sentinel::activating_plugin() );
	}

	/**
	 * A successful activation registers the plugin as watchdog-managed.
	 */
	public function test_activation_tracks_managed_plugin(): void {
		$slug     = $this->unique_slug( 'activate-tracked' );
		$dir      = Fixtures::write_plugin( WP_PLUGIN_DIR, $slug, Fixtures::clean( $slug ) );
		$this->track_plugin_dir( $dir );
		$basename = $slug . '/' . $slug . '.php';

		$this->ignoringHeaderWarnings(
			static fn () => ( new ActivationGate() )->run( $basename )
		);

		$this->assertContains( $basename, Sentinel::managed() );

		// Clean up the managed-list entry this test added.
		Sentinel::untrack( $basename );
	}

	/**
	 * WordPress refusing to validate a plugin is reported as a gate failure.
	 *
	 * A file with no plugin header is rejected by validate_plugin().
	 */
	public function test_invalid_plugin_fails(): void {
		$slug = $this->unique_slug( 'activate-bad' );
		$dir  = Fixtures::write_plugin( WP_PLUGIN_DIR, $slug, "<?php\n// No plugin header here.\n" );
		$this->track_plugin_dir( $dir );
		$basename = $slug . '/' . $slug . '.php';

		$result = ( new ActivationGate() )->run( $basename );

		$this->assertFalse( $result->passed() );
		$this->assertFalse( is_plugin_active( $basename ) );
		$this->assertSame( '', Sentinel::activating_plugin() );
	}
}
