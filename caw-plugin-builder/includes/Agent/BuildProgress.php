<?php
/**
 * Result of polling an in-flight build.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * A snapshot returned by BuildProvider::poll().
 *
 * The poller drives the build forward based on the state:
 *
 *  - STATE_RUNNING   The sandbox session is still working; poll again later.
 *  - STATE_SUCCEEDED The agent finished and submitted its work. The authored
 *                    plugin and the raw CI artifacts are attached for harvest.
 *  - STATE_FAILED    The session ended without a usable submission.
 */
final class BuildProgress {

	public const STATE_RUNNING   = 'running';
	public const STATE_SUCCEEDED = 'succeeded';
	public const STATE_FAILED    = 'failed';

	/**
	 * @param string              $state           One of the STATE_* constants.
	 * @param string              $message         Human-readable status detail.
	 * @param AuthoredPlugin|null  $authored        Harvested files (success only).
	 * @param array<string, mixed> $raw_ci         Raw, unparsed CI artifacts (success only).
	 */
	private function __construct(
		private string $state,
		private string $message,
		private ?AuthoredPlugin $authored = null,
		private array $raw_ci = []
	) {}

	/**
	 * Build a "still running" progress.
	 *
	 * @param string $message Status detail.
	 * @return self Progress.
	 */
	public static function running( string $message ): self {
		return new self( self::STATE_RUNNING, $message );
	}

	/**
	 * Build a "succeeded" progress carrying the harvested work.
	 *
	 * @param AuthoredPlugin       $authored Harvested files.
	 * @param array<string, mixed> $raw_ci   Raw CI artifacts (lint/junit/phpstan).
	 * @param string               $message  Status detail.
	 * @return self Progress.
	 */
	public static function succeeded( AuthoredPlugin $authored, array $raw_ci, string $message = '' ): self {
		return new self( self::STATE_SUCCEEDED, $message, $authored, $raw_ci );
	}

	/**
	 * Build a "failed" progress.
	 *
	 * @param string $message Failure detail.
	 * @return self Progress.
	 */
	public static function failed( string $message ): self {
		return new self( self::STATE_FAILED, $message );
	}

	/**
	 * The progress state.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Whether the build is still running.
	 *
	 * @return bool True when running.
	 */
	public function is_running(): bool {
		return self::STATE_RUNNING === $this->state;
	}

	/**
	 * Whether the build succeeded.
	 *
	 * @return bool True when succeeded.
	 */
	public function is_succeeded(): bool {
		return self::STATE_SUCCEEDED === $this->state;
	}

	/**
	 * Whether the build failed.
	 *
	 * @return bool True when failed.
	 */
	public function is_failed(): bool {
		return self::STATE_FAILED === $this->state;
	}

	/**
	 * Status detail message.
	 *
	 * @return string Message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * The harvested authored plugin (success only).
	 *
	 * @return AuthoredPlugin|null Authored plugin, or null.
	 */
	public function authored(): ?AuthoredPlugin {
		return $this->authored;
	}

	/**
	 * Raw, unparsed CI artifacts (success only).
	 *
	 * @return array<string, mixed> Raw CI artifacts.
	 */
	public function raw_ci(): array {
		return $this->raw_ci;
	}
}
