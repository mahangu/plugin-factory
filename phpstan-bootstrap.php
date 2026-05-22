<?php
/**
 * PHPStan bootstrap: makes the plugin's own runtime constants known to the
 * analyser. WordPress core symbols come from php-stubs/wordpress-stubs; the
 * WordPress 7.0 Connectors API functions come from phpstan-stubs.php.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

define( 'CAW_PB_VERSION', '0.0.0' );
define( 'CAW_PB_FILE', __FILE__ );
define( 'CAW_PB_DIR', __DIR__ );
define( 'CAW_PB_BASENAME', 'caw-plugin-builder/caw-plugin-builder.php' );
define( 'CAW_PB_MIN_PHP', '8.2' );

// WordPress path constants are defined at runtime, not in the core stubs.
define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_CONTENT_DIR', '/tmp/wp/wp-content' );
define( 'WP_PLUGIN_DIR', '/tmp/wp/wp-content/plugins' );
define( 'WPMU_PLUGIN_DIR', '/tmp/wp/wp-content/mu-plugins' );

// $wpdb result-format constants.
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
