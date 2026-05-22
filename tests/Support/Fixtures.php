<?php
/**
 * Test fixtures: deliberately good and deliberately broken plugins.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Tests\Support;

/**
 * Generates fixture plugins covering every failure mode the gates must catch.
 *
 * The "broken" fixtures are the heart of the gate test suite: a parse error, a
 * catchable load fatal, an UNcatchable load fatal, and an activation-hook
 * crash. Each one must be stopped by a specific gate.
 */
final class Fixtures {

	/**
	 * A correct, well-behaved plugin.
	 *
	 * Parses, loads without error, and has an activation hook with a real side
	 * effect (an option write) so Gate 2 has something to exercise.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Main plugin file contents.
	 */
	public static function clean( string $slug ): string {
		$const = strtoupper( str_replace( '-', '_', $slug ) );
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: CAW Fixture {$slug}\n"
			. " * Description: A clean fixture plugin used by the CAW gate tests.\n"
			. " * Version: 1.0.0\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "define( '{$const}_LOADED', true );\n"
			. "function {$const}_activate() {\n"
			. "\tupdate_option( '{$const}_activated', time() );\n"
			. "}\n"
			. "register_activation_hook( __FILE__, '{$const}_activate' );\n"
			. "add_action( 'init', function () {} );\n";
	}

	/**
	 * A plugin with a PHP parse error. Gate 1 must reject it.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Main plugin file contents.
	 */
	public static function parse_error( string $slug ): string {
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: CAW Fixture {$slug}\n"
			. " * Description: A fixture with a deliberate parse error.\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "function caw_fixture_broken( {\n"   // Intentional syntax error.
			. "\treturn 1;\n"
			. "}\n";
	}

	/**
	 * A plugin that parses but throws a catchable Throwable on load.
	 *
	 * Gate 1 passes it; Gate 2's try/catch(\Throwable) must catch the crash.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Main plugin file contents.
	 */
	public static function load_fatal_catchable( string $slug ): string {
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: CAW Fixture {$slug}\n"
			. " * Description: A fixture that throws on load.\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "throw new \\RuntimeException( 'CAW fixture: deliberate load-time crash.' );\n";
	}

	/**
	 * A plugin that parses but triggers an UNcatchable fatal on load.
	 *
	 * A failed require is an E_ERROR that try/catch cannot intercept. Only the
	 * harness's register_shutdown_function safety net can report it.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Main plugin file contents.
	 */
	public static function load_fatal_uncatchable( string $slug ): string {
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: CAW Fixture {$slug}\n"
			. " * Description: A fixture that fatals uncatchably on load.\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "require __DIR__ . '/this-file-does-not-exist.php';\n";
	}

	/**
	 * A plugin that loads cleanly but whose activation hook crashes.
	 *
	 * Gates 1 and 2's load stage pass; the activation stage of Gate 2 must
	 * catch the crash so the side effect never reaches a live host.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Main plugin file contents.
	 */
	public static function activation_fatal( string $slug ): string {
		$const = strtoupper( str_replace( '-', '_', $slug ) );
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: CAW Fixture {$slug}\n"
			. " * Description: A fixture whose activation hook crashes.\n"
			. " */\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			. "function {$const}_activate() {\n"
			. "\tthrow new \\RuntimeException( 'CAW fixture: deliberate activation crash.' );\n"
			. "}\n"
			. "register_activation_hook( __FILE__, '{$const}_activate' );\n";
	}

	/**
	 * Write a fixture plugin into a directory as "{slug}/{slug}.php".
	 *
	 * @param string $parent_dir Directory to create the plugin folder in.
	 * @param string $slug       Plugin slug.
	 * @param string $contents   Main file contents.
	 * @return string Absolute path to the plugin folder.
	 */
	public static function write_plugin( string $parent_dir, string $slug, string $contents ): string {
		$dir = rtrim( $parent_dir, '/' ) . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '/' . $slug . '.php', $contents );
		return $dir;
	}

	/**
	 * Build an artifact-style zip containing a single plugin folder.
	 *
	 * @param string $zip_path Destination zip path.
	 * @param string $slug     Plugin slug (the folder inside the zip).
	 * @param string $contents Main file contents.
	 * @param array<string, string> $extra Extra files (relative-to-folder path => content).
	 * @return bool True on success.
	 */
	public static function write_artifact_zip( string $zip_path, string $slug, string $contents, array $extra = [] ): bool {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$zip->addFromString( $slug . '/' . $slug . '.php', $contents );
		$zip->addFromString( $slug . '/VALIDATION.md', "# Validation report\nFixture artifact.\n" );
		foreach ( $extra as $rel => $body ) {
			$zip->addFromString( $slug . '/' . ltrim( $rel, '/' ), $body );
		}
		return $zip->close();
	}
}
