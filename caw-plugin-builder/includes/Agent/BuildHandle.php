<?php
/**
 * Opaque provider handle for an in-flight build.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * A provider-specific reference to one in-flight build.
 *
 * Callers treat this as opaque: only the provider that created it knows what
 * the references mean. It round-trips through the build record's provider_ref
 * column so polling can resume across cron ticks and process boundaries.
 */
final class BuildHandle {

	/**
	 * @param string               $provider   Provider id that owns this handle.
	 * @param array<string, string> $references Provider-specific identifiers.
	 */
	public function __construct(
		private string $provider,
		private array $references
	) {}

	/**
	 * The owning provider id.
	 *
	 * @return string Provider id.
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Read one reference value.
	 *
	 * @param string $key     Reference key.
	 * @param string $default Fallback when absent.
	 * @return string Reference value.
	 */
	public function ref( string $key, string $default = '' ): string {
		return $this->references[ $key ] ?? $default;
	}

	/**
	 * All references.
	 *
	 * @return array<string, string> Reference map.
	 */
	public function references(): array {
		return $this->references;
	}

	/**
	 * Serialise for storage in the build record.
	 *
	 * @return array<string, mixed> Storable array.
	 */
	public function to_array(): array {
		return [
			'provider'   => $this->provider,
			'references' => $this->references,
		];
	}

	/**
	 * Rehydrate a handle from stored data.
	 *
	 * @param array<string, mixed> $data Stored array.
	 * @return self Handle.
	 */
	public static function from_array( array $data ): self {
		$references = [];
		if ( isset( $data['references'] ) && is_array( $data['references'] ) ) {
			foreach ( $data['references'] as $key => $value ) {
				$references[ (string) $key ] = (string) $value;
			}
		}
		return new self( (string) ( $data['provider'] ?? '' ), $references );
	}
}
