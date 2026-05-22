<?php
/**
 * Provider failure exception.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * Raised by a BuildProvider when an operation fails.
 *
 * Carries a "retryable" flag so the poller can distinguish a transient fault
 * (rate limit, network blip) from a terminal one (bad request, auth failure).
 */
final class ProviderException extends \RuntimeException {

	/**
	 * @param string          $message   Failure message (already credential-safe).
	 * @param bool            $retryable Whether retrying later might succeed.
	 * @param \Throwable|null $previous  Underlying cause.
	 */
	public function __construct(
		string $message,
		private bool $retryable = false,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	/**
	 * Whether the failed operation may be safely retried later.
	 *
	 * @return bool True when retryable.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
