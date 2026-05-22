<?php
/**
 * Sandbox environment manager.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Beta\Environments\BetaCloudConfigParams;
use Anthropic\Beta\Environments\BetaPackagesParams;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use CAW\PluginBuilder\Support\Logger;

/**
 * Creates and reuses the Managed Agents sandbox environment.
 *
 * The environment definition IS the CI configuration: it pins the toolchain
 * (PHP and its extensions, a database, Composer, Subversion for WP-CLI). It is
 * created ONCE per toolchain fingerprint and reused for every subsequent build,
 * so CI is reproducible across builds rather than reprovisioned each time.
 *
 * Build and CI run only inside this sandbox — never on the WordPress host.
 */
final class EnvironmentManager {

	/**
	 * Option storing the fingerprint => environment id cache.
	 */
	private const OPTION = 'caw_environments';

	/**
	 * @param Client $client Configured Anthropic SDK client.
	 */
	public function __construct( private Client $client ) {}

	/**
	 * Return an environment id for the spec, creating one if none is cached.
	 *
	 * @param BuildSpec $spec Build request carrying the toolchain pins.
	 * @return string Environment id.
	 *
	 * @throws ProviderException When the environment cannot be provisioned.
	 */
	public function resolve( BuildSpec $spec ): string {
		$fingerprint = $spec->environment_fingerprint();
		$cache       = $this->cache();

		if ( isset( $cache[ $fingerprint ] ) && '' !== $cache[ $fingerprint ] ) {
			$environment_id = (string) $cache[ $fingerprint ];
			if ( $this->still_exists( $environment_id ) ) {
				return $environment_id;
			}
			Logger::warn( 'Cached sandbox environment is gone; recreating', [ 'fingerprint' => $fingerprint ] );
		}

		$environment_id = $this->create( $spec );

		$cache[ $fingerprint ] = $environment_id;
		update_option( self::OPTION, $cache, false );

		return $environment_id;
	}

	/**
	 * Create a fresh sandbox environment for the spec.
	 *
	 * @param BuildSpec $spec Build request.
	 * @return string New environment id.
	 *
	 * @throws ProviderException When creation fails.
	 */
	private function create( BuildSpec $spec ): string {
		$config = BetaCloudConfigParams::with(
			packages: BetaPackagesParams::with(
				apt: $this->apt_packages( $spec ),
			),
		);

		try {
			$environment = $this->client->beta->environments->create(
				name: 'caw-plugin-builder ' . $spec->environment_fingerprint(),
				config: $config,
				description: sprintf(
					'Reproducible CI sandbox for CAW Plugin Builder (PHP %s, WordPress %s).',
					$spec->php_version(),
					$spec->wp_version()
				),
				metadata: [
					'caw_fingerprint' => $spec->environment_fingerprint(),
					'caw_php'         => $spec->php_version(),
					'caw_wp'          => $spec->wp_version(),
				],
				betas: [ AnthropicBeta::MANAGED_AGENTS_2026_04_01->value ],
			);
		} catch ( APIException $e ) {
			throw new ProviderException(
				'Could not create the sandbox environment: ' . ApiErrors::describe( $e ),
				ApiErrors::is_retryable( $e ),
				$e
			);
		}

		Logger::info( 'Created sandbox environment', [ 'id' => $environment->id, 'fingerprint' => $spec->environment_fingerprint() ] );

		return $environment->id;
	}

	/**
	 * Whether a cached environment id still resolves on the API.
	 *
	 * @param string $environment_id Environment id.
	 * @return bool True when it still exists.
	 */
	private function still_exists( string $environment_id ): bool {
		try {
			$this->client->beta->environments->retrieve(
				$environment_id,
				betas: [ AnthropicBeta::MANAGED_AGENTS_2026_04_01->value ],
			);
			return true;
		} catch ( APIException $e ) {
			return false;
		}
	}

	/**
	 * The apt package set that pins the CI toolchain.
	 *
	 * @param BuildSpec $spec Build request.
	 * @return string[] apt package names.
	 */
	private function apt_packages( BuildSpec $spec ): array {
		// Core toolchain. PHP extension packages are added per the spec so the
		// environment fingerprint (and therefore CI) changes when they change.
		$packages = [
			'php-cli',
			'php-mysql',
			'mariadb-server',
			'composer',
			'unzip',
			'subversion',
			'curl',
			'git',
		];

		foreach ( $spec->php_extensions() as $extension ) {
			$extension = preg_replace( '/[^a-z0-9]/', '', strtolower( $extension ) );
			if ( null === $extension || '' === $extension ) {
				continue;
			}
			// json/mysqli ship with php-cli/php-mysql; skip duplicates.
			if ( in_array( $extension, [ 'json', 'mysqli', 'pdomysql' ], true ) ) {
				continue;
			}
			$packages[] = 'php-' . $extension;
		}

		return array_values( array_unique( $packages ) );
	}

	/**
	 * The fingerprint => environment id cache.
	 *
	 * @return array<string, string> Cache map.
	 */
	private function cache(): array {
		$cache = get_option( self::OPTION, [] );
		return is_array( $cache ) ? $cache : [];
	}
}
