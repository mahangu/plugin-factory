<?php
/**
 * Harvested agent-authored plugin.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Agent;

/**
 * The set of files the agent authored in the sandbox, harvested back to the
 * host as raw content. This is data only — it has NOT been validated. Nothing
 * here may be trusted until it has passed the host gate pipeline.
 *
 * Paths are normalised to be relative, forward-slashed, and free of traversal
 * segments on construction, so a malicious or buggy payload cannot escape the
 * staging directory when the tree is later materialised.
 */
final class AuthoredPlugin {

	/**
	 * @var array<string, string> Map of relative path => file content.
	 */
	private array $files;

	/**
	 * @param array<string, string> $files Map of path => content.
	 */
	public function __construct( array $files ) {
		$this->files = [];
		foreach ( $files as $path => $content ) {
			$safe = self::sanitize_relative_path( (string) $path );
			if ( '' === $safe ) {
				continue;
			}
			$this->files[ $safe ] = (string) $content;
		}
	}

	/**
	 * The harvested files.
	 *
	 * @return array<string, string> Map of relative path => content.
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * Whether any files were harvested.
	 *
	 * @return bool True when at least one file is present.
	 */
	public function is_empty(): bool {
		return [] === $this->files;
	}

	/**
	 * Number of files.
	 *
	 * @return int File count.
	 */
	public function count(): int {
		return count( $this->files );
	}

	/**
	 * The relative path of the main plugin file (the one with a Plugin Name header).
	 *
	 * @return string Relative path, or '' when none can be identified.
	 */
	public function main_file(): string {
		$candidates = [];
		foreach ( $this->files as $path => $content ) {
			if ( ! str_ends_with( strtolower( $path ), '.php' ) ) {
				continue;
			}
			if ( false !== stripos( $content, 'Plugin Name:' ) ) {
				$candidates[ $path ] = substr_count( $path, '/' );
			}
		}
		if ( [] === $candidates ) {
			return '';
		}
		// Prefer the shallowest plugin header file.
		asort( $candidates );
		return (string) array_key_first( $candidates );
	}

	/**
	 * The top-level directory shared by every file, if any.
	 *
	 * A well-formed plugin payload nests everything under one folder
	 * (e.g. "my-plugin/..."). This returns that folder name.
	 *
	 * @return string Folder name, or '' when files are not uniformly nested.
	 */
	public function root_folder(): string {
		$roots = [];
		foreach ( array_keys( $this->files ) as $path ) {
			$slash = strpos( $path, '/' );
			if ( false === $slash ) {
				return '';
			}
			$roots[ substr( $path, 0, $slash ) ] = true;
		}
		return 1 === count( $roots ) ? (string) array_key_first( $roots ) : '';
	}

	/**
	 * Normalise an untrusted relative path, rejecting traversal and absolutes.
	 *
	 * @param string $path Raw path from the agent payload.
	 * @return string Safe relative path, or '' when the path must be rejected.
	 */
	private static function sanitize_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		$path = ltrim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}
		// Reject Windows drive letters and protocol wrappers.
		if ( preg_match( '#^[a-zA-Z]:#', $path ) || false !== strpos( $path, '://' ) ) {
			return '';
		}

		$out = [];
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return ''; // Any traversal segment voids the whole path.
			}
			// Disallow null bytes and control characters.
			if ( preg_match( '/[\x00-\x1f]/', $segment ) ) {
				return '';
			}
			$out[] = $segment;
		}

		return implode( '/', $out );
	}
}
