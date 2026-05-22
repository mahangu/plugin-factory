<?php
/**
 * Build request specification.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * What the admin asked for, plus the environment the build must target.
 *
 * The version/extension pins flow straight into the sandbox environment
 * definition, which doubles as reproducible CI configuration.
 */
final class BuildSpec {

	/**
	 * @param string   $prompt      Natural-language description of the plugin.
	 * @param string   $slug        Desired plugin slug (sanitised, lowercase, dashed).
	 * @param string   $php_version PHP version to pin in the sandbox/CI.
	 * @param string   $wp_version  WordPress version to pin in the sandbox/CI.
	 * @param string[] $php_extensions Required PHP extensions.
	 */
	public function __construct(
		private string $prompt,
		private string $slug,
		private string $php_version = '8.3',
		private string $wp_version = '7.0',
		private array $php_extensions = [ 'mysqli', 'json', 'mbstring', 'zip' ]
	) {}

	/**
	 * The natural-language plugin description.
	 *
	 * @return string Prompt.
	 */
	public function prompt(): string {
		return $this->prompt;
	}

	/**
	 * The desired plugin slug.
	 *
	 * @return string Slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The PHP version to pin.
	 *
	 * @return string Version string.
	 */
	public function php_version(): string {
		return $this->php_version;
	}

	/**
	 * The WordPress version to pin.
	 *
	 * @return string Version string.
	 */
	public function wp_version(): string {
		return $this->wp_version;
	}

	/**
	 * Required PHP extensions.
	 *
	 * @return string[] Extension names.
	 */
	public function php_extensions(): array {
		return $this->php_extensions;
	}

	/**
	 * A stable hash of the environment-defining fields.
	 *
	 * Used to decide whether a cached sandbox environment can be reused or a
	 * fresh one must be created.
	 *
	 * @return string Short hash.
	 */
	public function environment_fingerprint(): string {
		$exts = $this->php_extensions;
		sort( $exts );
		return substr(
			hash( 'sha256', $this->php_version . '|' . $this->wp_version . '|' . implode( ',', $exts ) ),
			0,
			16
		);
	}
}
