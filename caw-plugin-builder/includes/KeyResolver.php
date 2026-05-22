<?php
/**
 * Anthropic API key resolver.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder;

/**
 * The single source of truth for "where does the Anthropic API key come from".
 *
 * Precedence:
 *
 *   1. Environment variable ANTHROPIC_API_KEY
 *   2. PHP constant ANTHROPIC_API_KEY
 *   3. The plugin's own key field (option caw_api_key), with the pre-0.1
 *      option caw_legacy_api_key consulted as a migration fallback
 *   4. Connectors API database setting connectors_ai_anthropic_api_key
 *      (only consulted when the 'anthropic' connector is registered)
 *
 * The plugin's own field deliberately outranks the Connectors API setting:
 * CAW calls the Managed Agents API directly and does not use the WP AI Client,
 * and on stock WordPress 7.0 the Connectors screen cannot reliably accept an
 * Anthropic key unless the separate ai-provider-for-anthropic plugin is active.
 * Reading the Connectors setting as a lower-priority fallback is still useful.
 *
 * WordPress 7.0 ships NO public accessor that returns a *resolved* connector
 * key — the only core helper, _wp_connectors_get_api_key_source(), is private
 * and returns the source string, not the value. So this resolver performs the
 * resolution itself, reading the env/constant/setting NAMES from the connector
 * metadata (wp_get_connector()) so it tracks core rather than hard-coding them.
 *
 * Connector functions only exist after the `init` action; resolve() must never
 * be called at plugin-load time.
 */
final class KeyResolver {

	/**
	 * The plugin's own first-class API key option.
	 */
	public const OPTION = 'caw_api_key';

	/**
	 * Pre-0.1 plugin key option, still read as a migration fallback so an
	 * existing install does not lose its key when upgrading.
	 */
	public const LEGACY_OPTION = 'caw_legacy_api_key';

	/**
	 * Default credential names, used when the Connectors API is absent.
	 */
	private const DEFAULT_ENV_VAR     = 'ANTHROPIC_API_KEY';
	private const DEFAULT_CONSTANT    = 'ANTHROPIC_API_KEY';
	private const DEFAULT_DB_SETTING  = 'connectors_ai_anthropic_api_key';

	/**
	 * Resolve the Anthropic API key following the documented precedence.
	 *
	 * @return KeyResolution The resolution (key + source), never null.
	 */
	public function resolve(): KeyResolution {
		$names = $this->credential_names();

		// 1. Environment variable.
		$env = getenv( $names['env_var'] );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return KeyResolution::found( trim( $env ), KeyResolution::SOURCE_ENV );
		}

		// 2. PHP constant.
		if ( defined( $names['constant'] ) ) {
			$value = constant( $names['constant'] );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return KeyResolution::found( trim( $value ), KeyResolution::SOURCE_CONSTANT );
			}
		}

		// 3. The plugin's own key field, with the pre-0.1 option as a fallback.
		$own = get_option( self::OPTION, '' );
		if ( is_string( $own ) && '' !== trim( $own ) ) {
			return KeyResolution::found( trim( $own ), KeyResolution::SOURCE_PLUGIN );
		}
		$legacy = get_option( self::LEGACY_OPTION, '' );
		if ( is_string( $legacy ) && '' !== trim( $legacy ) ) {
			return KeyResolution::found( trim( $legacy ), KeyResolution::SOURCE_LEGACY );
		}

		// 4. Connectors API database setting — only when the connector exists.
		if ( Capabilities::anthropic_connector_registered() ) {
			$value = get_option( $names['db_setting'], '' );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return KeyResolution::found( trim( $value ), KeyResolution::SOURCE_DATABASE );
			}
		}

		return KeyResolution::none();
	}

	/**
	 * Deep link an admin should follow to supply a key.
	 *
	 * Always the plugin's own settings screen: that field is the key path CAW
	 * controls and the one that outranks the Connectors setting.
	 *
	 * @return string Admin URL.
	 */
	public function settings_link(): string {
		return admin_url( 'admin.php?page=caw-plugin-builder-settings' );
	}

	/**
	 * The env/constant/setting names to consult.
	 *
	 * When the Connectors API is present these are read from the connector's
	 * own metadata so the resolver stays correct even if core renames them.
	 *
	 * @return array{env_var: string, constant: string, db_setting: string} Names.
	 */
	private function credential_names(): array {
		$names = [
			'env_var'    => self::DEFAULT_ENV_VAR,
			'constant'   => self::DEFAULT_CONSTANT,
			'db_setting' => self::DEFAULT_DB_SETTING,
		];

		if ( ! Capabilities::has_connectors_api() ) {
			return $names;
		}

		$connector = wp_get_connector( 'anthropic' );
		if ( ! is_array( $connector ) || empty( $connector['authentication'] ) || ! is_array( $connector['authentication'] ) ) {
			return $names;
		}

		$auth = $connector['authentication'];
		if ( ! empty( $auth['env_var_name'] ) && is_string( $auth['env_var_name'] ) ) {
			$names['env_var'] = $auth['env_var_name'];
		}
		if ( ! empty( $auth['constant_name'] ) && is_string( $auth['constant_name'] ) ) {
			$names['constant'] = $auth['constant_name'];
		}
		if ( ! empty( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) ) {
			$names['db_setting'] = $auth['setting_name'];
		}

		return $names;
	}
}
