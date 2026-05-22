<?php
/**
 * Anthropic Managed Agents build provider.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use Anthropic\Beta\Agents\BetaManagedAgentsAgentToolset20260401Params;
use Anthropic\Beta\Agents\BetaManagedAgentsAlwaysAllowPolicy;
use Anthropic\Beta\Agents\BetaManagedAgentsAgentToolsetDefaultConfigParams;
use Anthropic\Beta\Agents\BetaManagedAgentsCustomToolInputSchema;
use Anthropic\Beta\Agents\BetaManagedAgentsCustomToolParams;
use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Sessions\BetaManagedAgentsSession\Status;
use Anthropic\Beta\Sessions\Events\ManagedAgentsAgentCustomToolUseEvent;
use Anthropic\Beta\Sessions\Events\ManagedAgentsUserCustomToolResultEventParams;
use Anthropic\Beta\Sessions\Events\ManagedAgentsUserMessageEventParams;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use CAW\PluginBuilder\Support\Logger;

/**
 * Drives a build through Anthropic Managed Agents.
 *
 * This is the only BuildProvider that ships. It provisions a reproducible
 * sandbox, hands the spec to a hosted agent, and harvests the result.
 *
 * The agent works entirely inside the sandbox — it authors the plugin, sets up
 * a throwaway WordPress, and runs CI there. It returns its work through a
 * single custom tool, caw_submit_build, whose structured payload carries the
 * authored files plus the RAW CI artifacts (lint exit codes, JUnit XML,
 * PHPStan JSON). Computing pass/fail from those artifacts is NOT done here; it
 * is the host's job (see CiResultsHarvester and CiReport).
 */
final class AnthropicProvider implements BuildProvider {

	/**
	 * Name of the custom tool the agent calls to submit its finished work.
	 */
	public const SUBMIT_TOOL = 'caw_submit_build';

	/**
	 * Option caching the toolchain-fingerprint => agent id map.
	 */
	private const AGENT_OPTION = 'caw_agents';

	/**
	 * Beta header value required by every Managed Agents request.
	 */
	private const BETA = AnthropicBeta::MANAGED_AGENTS_2026_04_01->value;

	private EnvironmentManager $environments;

	/**
	 * @param Client $client Configured Anthropic SDK client (key already injected).
	 * @param string $model  Model identifier for the agent.
	 */
	public function __construct(
		private Client $client,
		private string $model = 'claude-opus-4-7'
	) {
		$this->environments = new EnvironmentManager( $client );
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Anthropic Managed Agents', 'caw-plugin-builder' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function start( BuildSpec $spec ): BuildHandle {
		try {
			$environment_id = $this->environments->resolve( $spec );
			$agent_id       = $this->resolve_agent( $spec );

			$session = $this->client->beta->sessions->create(
				agent: $agent_id,
				environmentID: $environment_id,
				title: 'CAW build: ' . $spec->slug(),
				metadata: [ 'caw_slug' => $spec->slug() ],
				betas: [ self::BETA ],
			);

			$this->client->beta->sessions->events->send(
				sessionID: $session->id,
				events: [
					ManagedAgentsUserMessageEventParams::with(
						content: [ [ 'type' => 'text', 'text' => $this->user_message( $spec ) ] ],
						type: 'user.message',
					),
				],
				betas: [ self::BETA ],
			);
		} catch ( APIException $e ) {
			throw new ProviderException(
				'Could not start the build session: ' . ApiErrors::describe( $e ),
				ApiErrors::is_retryable( $e ),
				$e
			);
		}

		Logger::info(
			'Started build session',
			[
				'session'     => $session->id,
				'agent'       => $agent_id,
				'environment' => $environment_id,
			]
		);

		return new BuildHandle(
			$this->id(),
			[
				'session_id'     => $session->id,
				'agent_id'       => $agent_id,
				'environment_id' => $environment_id,
			]
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function poll( BuildHandle $handle ): BuildProgress {
		$session_id = $handle->ref( 'session_id' );
		if ( '' === $session_id ) {
			return BuildProgress::failed( __( 'The build handle has no session reference.', 'caw-plugin-builder' ) );
		}

		try {
			$session = $this->client->beta->sessions->retrieve( $session_id, betas: [ self::BETA ] );
			$status  = $session->status;

			$submission = $this->find_submission( $session_id );

			if ( null !== $submission ) {
				$this->acknowledge_submission( $session_id, $submission->id );
				return $this->progress_from_submission( $submission );
			}

			if ( Status::TERMINATED->value === $status ) {
				// A terminated session is final: every event has long since
				// propagated, so a missing submission here is a real failure.
				return BuildProgress::failed(
					__( 'The sandbox session ended without submitting a build. Check the agent transcript.', 'caw-plugin-builder' )
				);
			}

			if ( Status::IDLE->value === $status ) {
				// An idle session has only paused its turn. The agent goes idle
				// the instant it calls a tool, so a just-submitted build can be
				// idle a moment before its submission event is listable. Keep
				// polling rather than failing: a genuine no-submission build is
				// still bounded by the poller's overall attempt cap.
				return BuildProgress::running(
					__( 'The agent turn is idle; waiting for the build submission to surface.', 'caw-plugin-builder' )
				);
			}

			return BuildProgress::running(
				sprintf(
					/* translators: %s: session status */
					__( 'Sandbox session is %s.', 'caw-plugin-builder' ),
					$status
				)
			);
		} catch ( APIException $e ) {
			if ( ApiErrors::is_retryable( $e ) ) {
				return BuildProgress::running( __( 'Transient API error while polling; will retry.', 'caw-plugin-builder' ) );
			}
			return BuildProgress::failed( 'Polling failed: ' . ApiErrors::describe( $e ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function cancel( BuildHandle $handle ): void {
		$session_id = $handle->ref( 'session_id' );
		if ( '' === $session_id ) {
			return;
		}
		try {
			$this->client->beta->sessions->delete( $session_id, betas: [ self::BETA ] );
		} catch ( APIException $e ) {
			Logger::warn( 'Failed to cancel session', [ 'error' => ApiErrors::describe( $e ) ] );
		}
	}

	/**
	 * Find the caw_submit_build custom tool call among the session's events.
	 *
	 * The events are fetched without a server-side `types` filter: the SDK
	 * serializes an array query parameter as `types[0]=...`, but the Managed
	 * Agents events endpoint only accepts the indexless `types[]=...` form and
	 * rejects the request with HTTP 400 otherwise. Filtering is done
	 * client-side instead, which is reliable here because the submission is the
	 * agent's final action and `order: 'desc'` with a generous limit captures
	 * it on the first page.
	 *
	 * @param string $session_id Session id.
	 * @return ManagedAgentsAgentCustomToolUseEvent|null The submission event, or null.
	 */
	private function find_submission( string $session_id ): ?ManagedAgentsAgentCustomToolUseEvent {
		$page = $this->client->beta->sessions->events->list(
			$session_id,
			limit: 100,
			order: 'desc',
			betas: [ self::BETA ],
		);

		foreach ( $page->getItems() as $event ) {
			if ( $event instanceof ManagedAgentsAgentCustomToolUseEvent && self::SUBMIT_TOOL === $event->name ) {
				return $event;
			}
		}
		return null;
	}

	/**
	 * Turn a submission event into a succeeded progress.
	 *
	 * @param ManagedAgentsAgentCustomToolUseEvent $event Submission event.
	 * @return BuildProgress Succeeded progress.
	 */
	private function progress_from_submission( ManagedAgentsAgentCustomToolUseEvent $event ): BuildProgress {
		$input = $event->input;

		$files = [];
		if ( isset( $input['files'] ) && is_array( $input['files'] ) ) {
			foreach ( $input['files'] as $file ) {
				if ( is_array( $file ) && isset( $file['path'], $file['content'] ) ) {
					$files[ (string) $file['path'] ] = (string) $file['content'];
				}
			}
		}

		$authored = new AuthoredPlugin( $files );

		$raw_ci = [
			'lint'              => isset( $input['lint'] ) && is_array( $input['lint'] ) ? $input['lint'] : [],
			'phpunit_junit_xml' => isset( $input['phpunit_junit_xml'] ) ? (string) $input['phpunit_junit_xml'] : '',
			'phpstan_json'      => isset( $input['phpstan_json'] ) ? (string) $input['phpstan_json'] : '',
		];

		if ( $authored->is_empty() ) {
			return BuildProgress::failed( __( 'The agent submitted an empty file set.', 'caw-plugin-builder' ) );
		}

		return BuildProgress::succeeded(
			$authored,
			$raw_ci,
			sprintf(
				/* translators: %d: number of files */
				__( 'Agent submitted %d files.', 'caw-plugin-builder' ),
				$authored->count()
			)
		);
	}

	/**
	 * Acknowledge a submission so the agent can end its turn cleanly.
	 *
	 * Best-effort: the data has already been harvested, so a failure here is
	 * logged but not fatal.
	 *
	 * @param string $session_id       Session id.
	 * @param string $custom_tool_use_id Id of the custom tool use event.
	 */
	private function acknowledge_submission( string $session_id, string $custom_tool_use_id ): void {
		try {
			$this->client->beta->sessions->events->send(
				sessionID: $session_id,
				events: [
					ManagedAgentsUserCustomToolResultEventParams::with(
						customToolUseID: $custom_tool_use_id,
						type: 'user.custom_tool_result',
						content: [ [ 'type' => 'text', 'text' => 'Build received by CAW Plugin Builder. You may end the session.' ] ],
					),
				],
				betas: [ self::BETA ],
			);
		} catch ( APIException $e ) {
			Logger::warn( 'Failed to acknowledge submission', [ 'error' => ApiErrors::describe( $e ) ] );
		}
	}

	/**
	 * Return an agent id for the spec's toolchain, creating one if needed.
	 *
	 * @param BuildSpec $spec Build request.
	 * @return string Agent id.
	 *
	 * @throws APIException When agent creation fails.
	 */
	private function resolve_agent( BuildSpec $spec ): string {
		$fingerprint = $spec->environment_fingerprint();
		$cache       = get_option( self::AGENT_OPTION, [] );
		$cache       = is_array( $cache ) ? $cache : [];

		if ( isset( $cache[ $fingerprint ] ) && '' !== $cache[ $fingerprint ] ) {
			$agent_id = (string) $cache[ $fingerprint ];
			// Confirm the cached agent still exists before reusing it; a
			// server-side deletion would otherwise fail every build for this
			// toolchain. Mirrors EnvironmentManager's environment revalidation.
			try {
				$this->client->beta->agents->retrieve( $agent_id, betas: [ self::BETA ] );
				return $agent_id;
			} catch ( APIException $e ) {
				Logger::warn( 'Cached build agent is gone; recreating', [ 'fingerprint' => $fingerprint ] );
			}
		}

		$agent = $this->client->beta->agents->create(
			model: apply_filters( 'caw_agent_model', $this->model ),
			name: 'CAW Plugin Builder ' . $fingerprint,
			description: 'Authors and tests WordPress plugins in an isolated sandbox.',
			system: $this->system_prompt( $spec ),
			tools: $this->tools(),
			betas: [ self::BETA ],
		);

		$cache[ $fingerprint ] = $agent->id;
		update_option( self::AGENT_OPTION, $cache, false );

		Logger::info( 'Created build agent', [ 'agent' => $agent->id, 'fingerprint' => $fingerprint ] );

		return $agent->id;
	}

	/**
	 * The tool configuration handed to the agent.
	 *
	 * The agent gets the built-in toolset (shell, file editing) so it can work
	 * inside the sandbox, plus one custom tool through which it submits results.
	 *
	 * @return list<mixed> Tool configurations.
	 */
	private function tools(): array {
		return [
			BetaManagedAgentsAgentToolset20260401Params::with(
				type: 'agent_toolset_20260401',
				defaultConfig: BetaManagedAgentsAgentToolsetDefaultConfigParams::with(
					enabled: true,
					permissionPolicy: BetaManagedAgentsAlwaysAllowPolicy::with( type: 'always_allow' ),
				),
			),
			BetaManagedAgentsCustomToolParams::with(
				description: 'Submit the finished plugin and its sandbox CI artifacts. Call this EXACTLY ONCE, as the final step, after CI has run. The host computes pass/fail from the artifacts you provide here; prose claims are ignored.',
				inputSchema: BetaManagedAgentsCustomToolInputSchema::with(
					type: 'object',
					properties: [
						'files'             => [
							'type'        => 'array',
							'description' => 'Every file of the finished plugin, each as a path relative to the plugin folder and its full text content.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'path'    => [ 'type' => 'string' ],
									'content' => [ 'type' => 'string' ],
								],
								'required'   => [ 'path', 'content' ],
							],
						],
						'lint'              => [
							'type'        => 'array',
							'description' => 'Result of running "php -l" on every PHP file: one object per file with its path, the process exit code, and any message.',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'file'      => [ 'type' => 'string' ],
									'exit_code' => [ 'type' => 'integer' ],
									'message'   => [ 'type' => 'string' ],
								],
								'required'   => [ 'file', 'exit_code' ],
							],
						],
						'phpunit_junit_xml' => [
							'type'        => 'string',
							'description' => 'The full contents of the PHPUnit JUnit XML report (phpunit --log-junit).',
						],
						'phpstan_json'      => [
							'type'        => 'string',
							'description' => 'The full contents of the PHPStan JSON report (phpstan analyse --error-format=json). Optional.',
						],
					],
					required: [ 'files', 'lint', 'phpunit_junit_xml' ],
				),
				name: self::SUBMIT_TOOL,
				type: 'custom',
			),
		];
	}

	/**
	 * The agent's standing system prompt.
	 *
	 * @param BuildSpec $spec Build request (supplies the toolchain pins).
	 * @return string System prompt.
	 */
	private function system_prompt( BuildSpec $spec ): string {
		return implode(
			"\n",
			[
				'You are a WordPress plugin engineer working inside an isolated build sandbox.',
				'',
				'For every request you will:',
				sprintf( '1. Provision a throwaway WordPress %s in the sandbox (WP-CLI core download, a MariaDB database, wp core install). This WordPress is a disposable TEST RIG, not a production site.', $spec->wp_version() ),
				sprintf( '2. Author a complete, self-contained WordPress plugin in PHP targeting PHP %s and WordPress %s. Put every file under a single plugin folder. Follow WordPress coding standards, escape output, sanitise input, and guard direct access with a check for ABSPATH.', $spec->php_version(), $spec->wp_version() ),
				'3. Write PHPUnit tests that exercise the plugin\'s behaviour, including its activation hook.',
				'4. Run CI inside the sandbox:',
				'   - Run "php -l" on every PHP file and record each exit code.',
				'   - Run PHPUnit with "--log-junit" and capture the JUnit XML.',
				'   - Run PHPStan with "--error-format=json" if practical and capture the JSON.',
				'5. Fix anything CI surfaces, then re-run CI until it is clean.',
				'',
				'CRITICAL — how to finish:',
				sprintf( 'Call the %s tool EXACTLY ONCE as your final action. Pass every plugin file (path + content), the per-file lint results, the JUnit XML, and the PHPStan JSON.', self::SUBMIT_TOOL ),
				'Do NOT describe success in prose and stop. The host re-computes pass/fail from the artifacts you submit and re-validates the plugin independently. Only a complete, accurate submission counts.',
				'',
				'Safety: the plugin you author may be installed into a real, non-disposable WordPress later. Never write code that assumes the host can be broken or reset. Avoid fatal errors on load and on activation.',
			]
		);
	}

	/**
	 * The per-build user message carrying the admin's request.
	 *
	 * @param BuildSpec $spec Build request.
	 * @return string User message.
	 */
	private function user_message( BuildSpec $spec ): string {
		return implode(
			"\n",
			[
				'Build a WordPress plugin from this description:',
				'',
				$spec->prompt(),
				'',
				sprintf( 'Use the plugin folder name (slug): %s', $spec->slug() ),
				sprintf( 'Target: PHP %s, WordPress %s.', $spec->php_version(), $spec->wp_version() ),
				'',
				sprintf( 'When everything is built and CI is clean, submit your work with the %s tool.', self::SUBMIT_TOOL ),
			]
		);
	}
}
