<?php
/**
 * Agent service facade.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;

/**
 * Owns the configured SDK client and the build provider behind it.
 *
 * The service receives the already-resolved API key as a constructor argument
 * and deliberately does NOT know where that key came from — environment
 * variable, PHP constant, the plugin option, or the Connectors API. Key
 * resolution and its precedence live entirely in KeyResolver; this class only
 * consumes the result. That keeps the credential-sourcing policy in one place
 * and makes the service trivial to unit test with a fake key.
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

	/**
	 * Verify the configured key against the Managed Agents API.
	 *
	 * Makes one cheap authenticated call (listing agents, limit 1). Crucially
	 * this checks more than generic Anthropic API access: the request carries
	 * the Managed Agents beta header, so a key whose account is not enrolled in
	 * that beta is reported distinctly. Never throws — the result is advisory.
	 *
	 * @return array{level: string, message: string} A WordPress notice level
	 *                                                ('success'|'warning'|'error')
	 *                                                and a human-readable message.
	 */
	public function check_credentials(): array {
		try {
			$this->client->beta->agents->list(
				limit: 1,
				betas: [ AnthropicBeta::MANAGED_AGENTS_2026_04_01->value ],
			);

			return [
				'level'   => 'success',
				'message' => __( 'The API key is valid and has Managed Agents access.', 'caw-plugin-builder' ),
			];
		} catch ( AuthenticationException $e ) {
			return [
				'level'   => 'error',
				'message' => __( 'The API key was rejected: authentication failed. Double-check the key. It has still been saved.', 'caw-plugin-builder' ),
			];
		} catch ( PermissionDeniedException $e ) {
			return [
				'level'   => 'warning',
				'message' => __( 'The API key authenticated, but it does not have Managed Agents access. The owning Anthropic account may not be enrolled in the Managed Agents beta. The key has been saved, but builds will fail until access is granted.', 'caw-plugin-builder' ),
			];
		} catch ( APIException $e ) {
			return [
				'level'   => 'warning',
				'message' => sprintf(
					/* translators: %s: API error description */
					__( 'The API key was saved, but could not be fully verified: %s', 'caw-plugin-builder' ),
					ApiErrors::describe( $e )
				),
			];
		} catch ( \Throwable $e ) {
			return [
				'level'   => 'warning',
				'message' => __( 'The API key was saved, but could not be verified — the Anthropic API could not be reached from this host.', 'caw-plugin-builder' ),
			];
		}
	}
}
