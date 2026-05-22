<?php
/**
 * Safe logger.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Support;

/**
 * Append-only logger that redacts anything resembling a credential before it
 * ever reaches disk. The API key must never be logged, even masked, so this
 * class scrubs token-shaped substrings on every write as a defence in depth.
 */
final class Logger {

	private const MAX_BYTES = 1048576; // 1 MiB before rotation.

	/**
	 * Write an informational line to the plugin log.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::write( 'INFO', $message, $context );
	}

	/**
	 * Write a warning line to the plugin log.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public static function warn( string $message, array $context = [] ): void {
		self::write( 'WARN', $message, $context );
	}

	/**
	 * Write an error line to the plugin log.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Redact token-shaped substrings from arbitrary text.
	 *
	 * Used both internally and by callers that need to surface third-party
	 * output (CI logs, exception messages) without leaking a credential.
	 *
	 * @param string $text Untrusted text.
	 * @return string Text with credential-shaped runs replaced.
	 */
	public static function redact( string $text ): string {
		// Anthropic keys look like "sk-ant-...". Also scrub generic long
		// high-entropy bearer-ish tokens to be safe.
		$text = (string) preg_replace( '/sk-ant-[A-Za-z0-9_\-]{8,}/', 'sk-ant-***REDACTED***', $text );
		$text = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9._\-]{12,}/i', 'Bearer ***REDACTED***', $text );
		return $text;
	}

	/**
	 * Write a line to the log file, rotating it once it grows past the cap.
	 *
	 * @param string               $level   Log level label.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private static function write( string $level, string $message, array $context ): void {
		$file = Paths::log_file();
		if ( '' === $file ) {
			return;
		}

		if ( is_file( $file ) && filesize( $file ) > self::MAX_BYTES ) {
			@rename( $file, $file . '.1' ); // phpcs:ignore WordPress.PHP.NoSilentErrors
		}

		$line = sprintf(
			'[%s] %s: %s',
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			$message
		);

		if ( [] !== $context ) {
			$encoded = wp_json_encode( $context );
			if ( is_string( $encoded ) ) {
				$line .= ' ' . $encoded;
			}
		}

		$line = self::redact( $line ) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Return the most recent log lines for display in the admin UI.
	 *
	 * @param int $lines Maximum number of trailing lines.
	 * @return string[] Trailing log lines, oldest first.
	 */
	public static function tail( int $lines = 200 ): array {
		$file = Paths::log_file();
		if ( '' === $file || ! is_file( $file ) ) {
			return [];
		}

		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$all     = array_values( array_filter( explode( "\n", $content ), 'strlen' ) );

		return array_slice( $all, -$lines );
	}
}
