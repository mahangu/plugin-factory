<?php
/**
 * Build provider capability interface.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * The capability this plugin depends on: "author and test a WordPress plugin
 * in an isolated sandbox, then return structured CI results."
 *
 * This interface is deliberately described in terms of that CAPABILITY, not in
 * terms of "an LLM" or "a chat API". A provider could be a hosted agent, a
 * remote CI farm, or anything else that can take a natural-language spec and
 * return authored code plus machine-readable test results.
 *
 * Exactly one provider ships today: AnthropicProvider, backed by Anthropic
 * Managed Agents. The seam exists for a future second provider; no second
 * provider is built, because no PHP-callable hosted-agent equivalent exists.
 *
 * Build vs. CI happen ONLY in the provider's sandbox, never on the host
 * (HARD CONSTRAINT 1). A provider implementation must never execute
 * agent-authored code in the host process.
 */
interface BuildProvider {

	/**
	 * Stable provider identifier (stored on the build record).
	 *
	 * @return string Provider id, e.g. 'anthropic'.
	 */
	public function id(): string;

	/**
	 * Human-readable provider name.
	 *
	 * @return string Label.
	 */
	public function label(): string;

	/**
	 * Whether the provider is configured well enough to attempt a build.
	 *
	 * @return bool True when usable.
	 */
	public function is_available(): bool;

	/**
	 * Start a build in the provider's sandbox.
	 *
	 * Implementations provision (or reuse) a reproducible environment, hand the
	 * spec to the agent, and return a handle for later polling. This method
	 * must not block waiting for the build to finish.
	 *
	 * @param BuildSpec $spec The build request.
	 * @return BuildHandle Opaque handle for polling.
	 *
	 * @throws ProviderException When the build cannot be started.
	 */
	public function start( BuildSpec $spec ): BuildHandle;

	/**
	 * Poll an in-flight build for progress.
	 *
	 * On success the returned progress carries the harvested authored plugin
	 * and the RAW CI artifacts (JUnit XML, lint exit codes, PHPStan JSON). It
	 * must NOT carry a pass/fail verdict — computing that is the host's job.
	 *
	 * @param BuildHandle $handle Handle from start().
	 * @return BuildProgress Current progress.
	 *
	 * @throws ProviderException When polling fails irrecoverably.
	 */
	public function poll( BuildHandle $handle ): BuildProgress;

	/**
	 * Best-effort cancellation and cleanup of an in-flight build.
	 *
	 * @param BuildHandle $handle Handle from start().
	 */
	public function cancel( BuildHandle $handle ): void;
}
