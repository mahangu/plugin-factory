<?php
/**
 * Tests for the admin settings behaviour.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use CAW\PluginBuilder\Admin\AdminPage;
use CAW\PluginBuilder\KeyResolver;

/**
 * Covers the API key sanitize callback.
 *
 * The settings field is deliberately never pre-filled with the stored secret,
 * so a blank submission must mean "keep the current key" — not "clear it". A
 * regression here would silently wipe a working key, so it is pinned.
 */
final class AdminPageTest extends IntegrationTestCase {

	/**
	 * Clear the key option before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( KeyResolver::OPTION );
	}

	/**
	 * Clear the key option after each test.
	 */
	protected function tearDown(): void {
		delete_option( KeyResolver::OPTION );
		parent::tearDown();
	}

	/**
	 * A blank submission keeps the existing stored key.
	 */
	public function test_blank_submission_keeps_the_existing_key(): void {
		update_option( KeyResolver::OPTION, 'sk-ant-existing-key' );

		$result = ( new AdminPage() )->sanitize_api_key( '' );

		$this->assertSame(
			'sk-ant-existing-key',
			$result,
			'A blank submission must not wipe the stored key.'
		);
	}

	/**
	 * A blank submission with no stored key resolves to an empty string.
	 */
	public function test_blank_submission_with_no_stored_key_stays_empty(): void {
		$result = ( new AdminPage() )->sanitize_api_key( '' );

		$this->assertSame( '', $result );
	}

	/**
	 * Whitespace-only input is treated as blank and keeps the existing key.
	 */
	public function test_whitespace_only_submission_keeps_the_existing_key(): void {
		update_option( KeyResolver::OPTION, 'sk-ant-existing-key' );

		$result = ( new AdminPage() )->sanitize_api_key( '   ' );

		$this->assertSame( 'sk-ant-existing-key', $result );
	}
}
