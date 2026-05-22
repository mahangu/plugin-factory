<?php
/**
 * Tests for the Anthropic build provider.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests;

use Anthropic\Beta\Sessions\Events\ManagedAgentsAgentCustomToolUseEvent;
use CAW\PluginBuilder\Agent\AnthropicProvider;

/**
 * Covers the client-side submission filtering.
 *
 * Since the server-side `types` query filter was removed (the SDK serialized
 * it in a form the Managed Agents events endpoint rejects), find_submission()
 * relies entirely on selecting the caw_submit_build custom tool call out of an
 * unfiltered event list. select_submission() is the pure part of that and is
 * exercised here against a mixed list.
 */
final class AnthropicProviderTest extends IntegrationTestCase {

	/**
	 * The submission event is picked out of a mix of other events.
	 */
	public function test_selects_the_submission_event(): void {
		$events = [
			$this->custom_tool_event( 'other_tool', 'evt-1' ),
			$this->custom_tool_event( AnthropicProvider::SUBMIT_TOOL, 'evt-2' ),
			$this->custom_tool_event( 'another_tool', 'evt-3' ),
		];

		$found = AnthropicProvider::select_submission( $events );

		$this->assertInstanceOf( ManagedAgentsAgentCustomToolUseEvent::class, $found );
		$this->assertSame( 'evt-2', $found->id );
	}

	/**
	 * A list with no caw_submit_build call yields null.
	 */
	public function test_returns_null_without_a_submission(): void {
		$this->assertNull( AnthropicProvider::select_submission( [] ) );
		$this->assertNull(
			AnthropicProvider::select_submission( [ $this->custom_tool_event( 'other_tool', 'evt-1' ) ] )
		);
	}

	/**
	 * Non-event items in the list are ignored, not fatally mishandled.
	 */
	public function test_ignores_non_event_items(): void {
		$events = [
			new \stdClass(),
			'a stray string',
			$this->custom_tool_event( AnthropicProvider::SUBMIT_TOOL, 'evt-9' ),
		];

		$found = AnthropicProvider::select_submission( $events );

		$this->assertInstanceOf( ManagedAgentsAgentCustomToolUseEvent::class, $found );
		$this->assertSame( 'evt-9', $found->id );
	}

	/**
	 * Build a custom-tool-use event with a given tool name.
	 *
	 * @param string $name Tool name.
	 * @param string $id   Event id.
	 * @return ManagedAgentsAgentCustomToolUseEvent Event.
	 */
	private function custom_tool_event( string $name, string $id ): ManagedAgentsAgentCustomToolUseEvent {
		return ManagedAgentsAgentCustomToolUseEvent::with(
			id: $id,
			input: [],
			name: $name,
			processedAt: new \DateTimeImmutable(),
			type: 'agent.custom_tool_use',
		);
	}
}
