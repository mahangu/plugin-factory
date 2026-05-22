<?php
/**
 * Anthropic API error classification.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APITimeoutException;
use CAW\PluginBuilder\Support\Logger;

/**
 * Classifies SDK exceptions into retryable vs. terminal.
 *
 * Managed Agents create endpoints are rate limited (~60 rpm); a 429 must be
 * backed off rather than treated as a hard failure. Connection and timeout
 * faults, and 5xx responses, are likewise transient. Everything else
 * (auth, bad request, not found) is terminal.
 */
final class ApiErrors {

	/**
	 * Whether an operation that raised this throwable may be retried later.
	 *
	 * @param \Throwable $e The caught throwable.
	 * @return bool True when a later retry might succeed.
	 */
	public static function is_retryable( \Throwable $e ): bool {
		if ( $e instanceof APIConnectionException || $e instanceof APITimeoutException ) {
			return true;
		}
		if ( $e instanceof APIException ) {
			$status = $e->status ?? 0;
			return 429 === $status || ( $status >= 500 && $status <= 599 );
		}
		return false;
	}

	/**
	 * A credential-safe, human-readable description of an SDK throwable.
	 *
	 * @param \Throwable $e The caught throwable.
	 * @return string Description.
	 */
	public static function describe( \Throwable $e ): string {
		$status = ( $e instanceof APIException && null !== $e->status ) ? $e->status : 0;
		$prefix = $status > 0 ? sprintf( 'HTTP %d: ', $status ) : '';
		return Logger::redact( $prefix . $e->getMessage() );
	}
}
