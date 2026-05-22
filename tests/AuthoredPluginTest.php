<?php
/**
 * Tests for AuthoredPlugin path handling.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Agent\AuthoredPlugin;

/**
 * The AuthoredPlugin value object normalises untrusted paths from the agent
 * payload. A buggy or malicious payload must never be able to escape staging,
 * so these tests pin the path-sanitisation rules hard.
 */
final class AuthoredPluginTest extends IntegrationTestCase {

	/**
	 * Ordinary nested paths survive normalisation unchanged.
	 */
	public function test_keeps_normal_paths(): void {
		$plugin = new AuthoredPlugin(
			[
				'my-plugin/my-plugin.php' => '<?php',
				'my-plugin/inc/Thing.php' => '<?php',
			]
		);

		$this->assertSame( 2, $plugin->count() );
		$this->assertArrayHasKey( 'my-plugin/my-plugin.php', $plugin->files() );
	}

	/**
	 * Any traversal segment voids the whole path.
	 */
	public function test_rejects_directory_traversal(): void {
		$plugin = new AuthoredPlugin(
			[
				'../evil.php'           => '<?php',
				'my-plugin/../../x.php' => '<?php',
				'ok/file.php'           => '<?php',
			]
		);

		$this->assertSame( 1, $plugin->count() );
		$this->assertArrayHasKey( 'ok/file.php', $plugin->files() );
	}

	/**
	 * Leading slashes are stripped so absolute paths become contained.
	 */
	public function test_strips_leading_slash(): void {
		$plugin = new AuthoredPlugin( [ '/etc/passwd' => 'x' ] );

		$this->assertArrayHasKey( 'etc/passwd', $plugin->files() );
		$this->assertArrayNotHasKey( '/etc/passwd', $plugin->files() );
	}

	/**
	 * Null bytes and protocol wrappers are rejected outright.
	 */
	public function test_rejects_null_bytes_and_wrappers(): void {
		$plugin = new AuthoredPlugin(
			[
				"bad\0.php"           => 'x',
				'php://filter/x.php'  => 'x',
				'good.php'            => 'x',
			]
		);

		$this->assertSame( [ 'good.php' ], array_keys( $plugin->files() ) );
	}

	/**
	 * The main file is the shallowest file carrying a plugin header.
	 */
	public function test_detects_main_file(): void {
		$plugin = new AuthoredPlugin(
			[
				'my-plugin/readme.txt'       => 'just text',
				'my-plugin/my-plugin.php'    => "<?php\n/* Plugin Name: X */",
				'my-plugin/inc/bootstrap.php' => "<?php\n/* Plugin Name: nested */",
			]
		);

		$this->assertSame( 'my-plugin/my-plugin.php', $plugin->main_file() );
	}

	/**
	 * A uniformly nested payload reports its single root folder.
	 */
	public function test_detects_root_folder(): void {
		$nested = new AuthoredPlugin(
			[
				'my-plugin/a.php' => 'x',
				'my-plugin/b.php' => 'x',
			]
		);
		$this->assertSame( 'my-plugin', $nested->root_folder() );

		$mixed = new AuthoredPlugin(
			[
				'a/x.php' => 'x',
				'b/y.php' => 'x',
			]
		);
		$this->assertSame( '', $mixed->root_folder() );
	}

	/**
	 * An empty payload is reported as empty.
	 */
	public function test_empty_payload(): void {
		$plugin = new AuthoredPlugin( [] );
		$this->assertTrue( $plugin->is_empty() );
		$this->assertSame( 0, $plugin->count() );
	}
}
