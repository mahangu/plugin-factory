<?php
/**
 * PHPStan stubs for the WordPress 7.0 Connectors API.
 *
 * php-stubs/wordpress-stubs tracks released WordPress versions, so the 7.0
 * Connectors API functions are not yet in it. These signatures mirror
 * wp-includes/connectors.php so the analyser understands the key resolver.
 *
 * @package CAW\PluginBuilder
 */

/**
 * Whether a connector is registered with the Connectors API.
 *
 * @param string $id Connector identifier.
 * @return bool True when registered.
 */
function wp_is_connector_registered( string $id ): bool {}

/**
 * Retrieve a registered connector's metadata.
 *
 * @param string $id Connector identifier.
 * @return array<string, mixed>|null Connector metadata, or null when not registered.
 */
function wp_get_connector( string $id ): ?array {}

/**
 * Retrieve all registered connectors.
 *
 * @return array<string, array<string, mixed>> Connector metadata keyed by id.
 */
function wp_get_connectors(): array {}
