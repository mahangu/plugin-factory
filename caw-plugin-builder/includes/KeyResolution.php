<?php
/**
 * Resolved API key value object.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

/**
 * The outcome of a single key resolution pass.
 *
 * Holds the secret only long enough to hand it to the agent service. The key
 * is excluded from var_dump()/print_r() output via __debugInfo() so a stray
 * debug statement can never spill it.
 */
final class KeyResolution {

	public const SOURCE_ENV      = 'env';
	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_DATABASE = 'database';
	public const SOURCE_LEGACY   = 'legacy';
	public const SOURCE_NONE     = 'none';

	/**
	 * Construct a resolution.
	 *
	 * @param string $key    The resolved key, or '' when none was found.
	 * @param string $source One of the SOURCE_* constants.
	 */
	private function __construct(
		private string $key,
		private string $source
	) {}

	/**
	 * Build a resolution carrying a found key.
	 *
	 * @param string $key    The key.
	 * @param string $source One of the SOURCE_* constants.
	 * @return self Resolution.
	 */
	public static function found( string $key, string $source ): self {
		return new self( $key, $source );
	}

	/**
	 * Build the "no key anywhere" resolution.
	 *
	 * @return self Resolution.
	 */
	public static function none(): self {
		return new self( '', self::SOURCE_NONE );
	}

	/**
	 * Whether a non-empty key was resolved.
	 *
	 * @return bool True when a key is present.
	 */
	public function is_resolved(): bool {
		return '' !== $this->key;
	}

	/**
	 * The resolved secret. Callers must never log or echo this.
	 *
	 * @return string The key, or '' when unresolved.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * The source the key came from.
	 *
	 * @return string One of the SOURCE_* constants.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Human-readable, non-sensitive description of the source.
	 *
	 * @return string Label.
	 */
	public function source_label(): string {
		switch ( $this->source ) {
			case self::SOURCE_ENV:
				return __( 'Environment variable (ANTHROPIC_API_KEY)', 'caw-plugin-builder' );
			case self::SOURCE_CONSTANT:
				return __( 'PHP constant (ANTHROPIC_API_KEY)', 'caw-plugin-builder' );
			case self::SOURCE_DATABASE:
				return __( 'WordPress Connectors API', 'caw-plugin-builder' );
			case self::SOURCE_LEGACY:
				return __( 'Plugin setting (legacy)', 'caw-plugin-builder' );
			default:
				return __( 'Not configured', 'caw-plugin-builder' );
		}
	}

	/**
	 * Debug representation with the secret stripped.
	 *
	 * @return array<string, string> Safe debug info.
	 */
	public function __debugInfo(): array {
		return [
			'source'      => $this->source,
			'is_resolved' => $this->is_resolved() ? 'true' : 'false',
			'key'         => $this->is_resolved() ? '***REDACTED***' : '(none)',
		];
	}
}
