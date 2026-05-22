<?php
/**
 * Agent service facade.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use Anthropic\Client;

/**
 * Owns the configured SDK client and the build provider behind it.
 *
 * The service receives the already-resolved API key as a constructor argument
 * and deliberately does NOT know where that key came from — environment
 * variable, PHP constant, Connectors API, or a legacy option. Key resolution
 * and its precedence live entirely in KeyResolver; this class only consumes
 * the result. That keeps the credential-sourcing policy in one place and makes
 * the service trivial to unit test with a fake key.
 */
final class AgentService {

	private Client $client;

	private BuildProvider $provider;

	/**
	 * @param string $api_key The resolved Anthropic API key.
	 */
	public function __construct( string $api_key ) {
		$this->client   = new Client( apiKey: $api_key );
		$this->provider = new AnthropicProvider( $this->client );
	}

	/**
	 * The build provider — the capability seam the rest of the plugin uses.
	 *
	 * @return BuildProvider Provider.
	 */
	public function provider(): BuildProvider {
		return $this->provider;
	}
}
