<?php
/**
 * Single gate result.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Gates;

/**
 * The outcome of running one host safety gate.
 *
 * Gates are conservative by construction: a result is "passed" only when the
 * gate positively proved the candidate safe for the next stage. Anything
 * ambiguous — a gate that could not run, an unreadable report — is a failure.
 */
final class GateResult {

	/**
	 * @param int      $number  Gate number (1, 2 or 3).
	 * @param string   $name    Short gate name.
	 * @param bool     $passed  Whether the gate passed.
	 * @param string   $summary One-line human-readable outcome.
	 * @param string[] $details Zero or more detail lines (already credential-safe).
	 */
	public function __construct(
		private int $number,
		private string $name,
		private bool $passed,
		private string $summary,
		private array $details = []
	) {}

	/**
	 * Build a passing result.
	 *
	 * @param int      $number  Gate number.
	 * @param string   $name    Gate name.
	 * @param string   $summary Outcome line.
	 * @param string[] $details Detail lines.
	 * @return self Result.
	 */
	public static function pass( int $number, string $name, string $summary, array $details = [] ): self {
		return new self( $number, $name, true, $summary, $details );
	}

	/**
	 * Build a failing result.
	 *
	 * @param int      $number  Gate number.
	 * @param string   $name    Gate name.
	 * @param string   $summary Outcome line.
	 * @param string[] $details Detail lines.
	 * @return self Result.
	 */
	public static function fail( int $number, string $name, string $summary, array $details = [] ): self {
		return new self( $number, $name, false, $summary, $details );
	}

	/**
	 * Gate number.
	 *
	 * @return int Number.
	 */
	public function number(): int {
		return $this->number;
	}

	/**
	 * Gate name.
	 *
	 * @return string Name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether the gate passed.
	 *
	 * @return bool True when passed.
	 */
	public function passed(): bool {
		return $this->passed;
	}

	/**
	 * One-line outcome.
	 *
	 * @return string Summary.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Detail lines.
	 *
	 * @return string[] Details.
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * Serialise to a storable array.
	 *
	 * @return array<string, mixed> Storable result.
	 */
	public function to_array(): array {
		return [
			'number'  => $this->number,
			'name'    => $this->name,
			'passed'  => $this->passed,
			'summary' => $this->summary,
			'details' => $this->details,
		];
	}
}
