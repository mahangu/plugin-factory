<?php
/**
 * Aggregate host gate report.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

/**
 * The combined outcome of a host gate run.
 *
 * The pipeline stops at the first failing gate, so this report holds the
 * results of every gate that ran, in order, plus whether the candidate was
 * ultimately activated.
 */
final class GateReport {

	/**
	 * @param GateResult[] $results   Gate results in execution order.
	 * @param bool         $installed Whether the plugin ended up active.
	 * @param string       $message   Overall human-readable conclusion.
	 */
	public function __construct(
		private array $results,
		private bool $installed,
		private string $message
	) {}

	/**
	 * Whether every gate that ran passed.
	 *
	 * @return bool True when all gates passed.
	 */
	public function passed(): bool {
		foreach ( $this->results as $result ) {
			if ( ! $result->passed() ) {
				return false;
			}
		}
		return [] !== $this->results;
	}

	/**
	 * Whether the plugin was activated on the host.
	 *
	 * @return bool True when installed.
	 */
	public function installed(): bool {
		return $this->installed;
	}

	/**
	 * Gate results in execution order.
	 *
	 * @return GateResult[] Results.
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * Overall conclusion message.
	 *
	 * @return string Message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * The first failing gate, if any.
	 *
	 * @return GateResult|null Failing gate, or null when all passed.
	 */
	public function first_failure(): ?GateResult {
		foreach ( $this->results as $result ) {
			if ( ! $result->passed() ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Serialise to a storable array.
	 *
	 * @return array<string, mixed> Storable report.
	 */
	public function to_array(): array {
		return [
			'passed'    => $this->passed(),
			'installed' => $this->installed,
			'message'   => $this->message,
			'results'   => array_map(
				static fn ( GateResult $r ): array => $r->to_array(),
				$this->results
			),
		];
	}
}
