<?php
/**
 * Tests for the Anthropic API key resolver.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\KeyResolution;
use CAW\PluginBuilder\KeyResolver;

/**
 * Pins the key-resolution precedence to WordPress core's documented order:
 * environment variable, PHP constant, Connectors API setting, legacy option.
 *
 * The rig is WordPress 7.0 with the Connectors API present, so the legacy
 * option must be IGNORED here — that branch is a pre-7.0 fallback only.
 */
final class KeyResolverTest extends IntegrationTestCase {

	private const ENV_VAR    = 'ANTHROPIC_API_KEY';
	private const DB_SETTING = 'connectors_ai_anthropic_api_key';

	/** @var string|false Saved environment value to restore. */
	private string|false $saved_env = false;

	/**
	 * Snapshot and clear credential state before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->saved_env = getenv( self::ENV_VAR );
		putenv( self::ENV_VAR );
		delete_option( self::DB_SETTING );
		delete_option( KeyResolver::LEGACY_OPTION );
	}

	/**
	 * Restore credential state after each test.
	 */
	protected function tearDown(): void {
		if ( false === $this->saved_env ) {
			putenv( self::ENV_VAR );
		} else {
			putenv( self::ENV_VAR . '=' . $this->saved_env );
		}
		delete_option( self::DB_SETTING );
		delete_option( KeyResolver::LEGACY_OPTION );
		parent::tearDown();
	}

	/**
	 * With nothing configured, the resolver reports no key.
	 */
	public function test_resolves_to_none_when_unconfigured(): void {
		$resolution = ( new KeyResolver() )->resolve();
		$this->assertFalse( $resolution->is_resolved() );
		$this->assertSame( KeyResolution::SOURCE_NONE, $resolution->source() );
	}

	/**
	 * An environment variable is resolved with the env source.
	 */
	public function test_resolves_from_environment(): void {
		putenv( self::ENV_VAR . '=sk-ant-env-key' );

		$resolution = ( new KeyResolver() )->resolve();

		$this->assertTrue( $resolution->is_resolved() );
		$this->assertSame( KeyResolution::SOURCE_ENV, $resolution->source() );
		$this->assertSame( 'sk-ant-env-key', $resolution->key() );
	}

	/**
	 * The Connectors API database setting is resolved with the database source.
	 */
	public function test_resolves_from_connectors_setting(): void {
		update_option( self::DB_SETTING, 'sk-ant-db-key' );

		$resolution = ( new KeyResolver() )->resolve();

		$this->assertTrue( $resolution->is_resolved() );
		$this->assertSame( KeyResolution::SOURCE_DATABASE, $resolution->source() );
		$this->assertSame( 'sk-ant-db-key', $resolution->key() );
	}

	/**
	 * The environment variable wins over the Connectors API setting.
	 */
	public function test_environment_beats_database(): void {
		putenv( self::ENV_VAR . '=sk-ant-env-wins' );
		update_option( self::DB_SETTING, 'sk-ant-db-loses' );

		$resolution = ( new KeyResolver() )->resolve();

		$this->assertSame( KeyResolution::SOURCE_ENV, $resolution->source() );
		$this->assertSame( 'sk-ant-env-wins', $resolution->key() );
	}

	/**
	 * On a host with the Connectors API, the legacy option is never consulted.
	 */
	public function test_legacy_option_ignored_when_connectors_api_present(): void {
		update_option( KeyResolver::LEGACY_OPTION, 'sk-ant-legacy-key' );

		$resolution = ( new KeyResolver() )->resolve();

		$this->assertFalse(
			$resolution->is_resolved(),
			'The legacy option must be ignored on WordPress 7.0+.'
		);
	}

	/**
	 * A PHP constant is resolved, and beats the database, in a fresh process.
	 *
	 * The constant cannot be undefined once set, so this case is exercised in
	 * a subprocess to keep it isolated from the rest of the suite.
	 */
	public function test_resolves_from_constant_in_subprocess(): void {
		$script = sprintf(
			'<?php require %s; putenv("ANTHROPIC_API_KEY"); '
			. 'update_option(%s, "sk-ant-db-loses"); '
			. 'define("ANTHROPIC_API_KEY", "sk-ant-const-wins"); '
			. '$r = (new \CAW\PluginBuilder\KeyResolver())->resolve(); '
			. 'echo $r->source() . "|" . $r->key(); '
			. 'delete_option(%s);',
			var_export( CAW_TEST_WP_PATH . '/wp-load.php', true ),
			var_export( self::DB_SETTING, true ),
			var_export( self::DB_SETTING, true )
		);

		$file = $this->make_scratch_file( '.php' );
		file_put_contents( $file, $script );

		$output = [];
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output );
		$result = trim( implode( "\n", $output ) );

		$this->assertSame( KeyResolution::SOURCE_CONSTANT . '|sk-ant-const-wins', $result );
	}
}
