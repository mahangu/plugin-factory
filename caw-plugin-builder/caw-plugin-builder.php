<?php
/**
 * Plugin Name:       CAW Plugin Builder
 * Plugin URI:        https://github.com/mahangu/plugin-factory
 * Description:       Describe a plugin in natural language. An Anthropic Managed Agent authors and tests it in a hosted sandbox; a host-side safety gauntlet validates it before it ever touches your live site.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Plugin Factory
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       caw-plugin-builder
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CAW_PB_VERSION', '0.1.0' );
define( 'CAW_PB_FILE', __FILE__ );
define( 'CAW_PB_DIR', __DIR__ );
define( 'CAW_PB_BASENAME', plugin_basename( __FILE__ ) );
define( 'CAW_PB_MIN_PHP', '8.2' );

/**
 * The plugin requires PHP 8.2+ — the floor of currently security-supported
 * PHP, and the version the bundled dependencies are resolved against.
 *
 * A version mismatch must NEVER fatal the host: we detect it, show an admin
 * notice, and self-deactivate. This runs before the autoloader is touched.
 */
if ( version_compare( PHP_VERSION, CAW_PB_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'CAW Plugin Builder requires PHP %1$s or newer. This site runs PHP %2$s. The plugin has been deactivated.', 'caw-plugin-builder' ),
					CAW_PB_MIN_PHP,
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	add_action(
		'admin_init',
		static function (): void {
			if ( function_exists( 'deactivate_plugins' ) ) {
				deactivate_plugins( CAW_PB_BASENAME );
			}
		}
	);
	return;
}

$caw_pb_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $caw_pb_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'CAW Plugin Builder is missing its Composer dependencies. Run "composer install" inside the plugin directory.', 'caw-plugin-builder' );
			echo '</p></div>';
		}
	);
	return;
}
require $caw_pb_autoload;

// Activation / deactivation / uninstall are deliberately thin wrappers around
// the Installer so the heavy logic stays unit-testable and namespaced.
register_activation_hook( __FILE__, [ Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Installer::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
