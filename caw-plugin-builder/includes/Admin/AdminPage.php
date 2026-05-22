<?php
/**
 * Admin UI controller.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Admin;

use CAW\PluginBuilder\Build\Build;
use CAW\PluginBuilder\Build\BuildRepository;
use CAW\PluginBuilder\Capabilities;
use CAW\PluginBuilder\Cron\Poller;
use CAW\PluginBuilder\Gates\GateReport;
use CAW\PluginBuilder\Gates\HostGatePipeline;
use CAW\PluginBuilder\Installer;
use CAW\PluginBuilder\KeyResolver;
use CAW\PluginBuilder\Sentinel;
use CAW\PluginBuilder\Support\Paths;

/**
 * The admin surface: a build queue, a per-build review screen, and the opt-in
 * destination actions for a completed artifact.
 *
 * The screen is organised exactly as the workflow runs — build, then review,
 * then choose a destination — with hard stops between. "Install on this site"
 * is never one click: it routes through an explicit confirmation screen and is
 * not even offered when the host cannot run the safety gates.
 */
final class AdminPage {

	private const MENU_SLUG     = 'caw-plugin-builder';
	private const SETTINGS_SLUG = 'caw-plugin-builder-settings';
	private const CAPABILITY    = 'manage_options';

	private BuildRepository $builds;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builds = new BuildRepository();
	}

	/**
	 * Register every admin hook this controller owns.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		add_action( 'admin_post_caw_create_build', [ $this, 'handle_create' ] );
		add_action( 'admin_post_caw_install_build', [ $this, 'handle_install' ] );
		add_action( 'admin_post_caw_download_build', [ $this, 'handle_download' ] );
		add_action( 'admin_post_caw_delete_build', [ $this, 'handle_delete' ] );
	}

	/**
	 * Register the admin menu entries.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Plugin Builder', 'caw-plugin-builder' ),
			__( 'Plugin Builder', 'caw-plugin-builder' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ],
			'dashicons-hammer',
			65
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Plugin Builder', 'caw-plugin-builder' ),
			__( 'Builds', 'caw-plugin-builder' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Plugin Builder Settings', 'caw-plugin-builder' ),
			__( 'Settings', 'caw-plugin-builder' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			[ $this, 'render_settings' ]
		);
	}

	/**
	 * Enqueue the admin stylesheet on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'caw-plugin-builder-admin',
			plugins_url( 'assets/admin.css', CAW_PB_FILE ),
			[],
			CAW_PB_VERSION
		);
	}

	/**
	 * Register the legacy API key setting (used only on pre-7.0 hosts).
	 */
	public function register_settings(): void {
		register_setting(
			'caw_plugin_builder_settings',
			KeyResolver::LEGACY_OPTION,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * Dispatch the main page to the right view.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'caw-plugin-builder' ) );
		}

		$build_id = isset( $_GET['build'] ) ? absint( wp_unslash( $_GET['build'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap caw-wrap">';

		if ( $build_id > 0 ) {
			$build = $this->builds->find( $build_id );
			if ( null === $build ) {
				echo '<h1>' . esc_html__( 'Build not found', 'caw-plugin-builder' ) . '</h1>';
				echo '<p><a href="' . esc_url( $this->menu_url() ) . '">' . esc_html__( 'Back to builds', 'caw-plugin-builder' ) . '</a></p>';
			} elseif ( 'confirm-install' === $action ) {
				$this->render_confirm_install( $build );
			} else {
				$this->render_detail( $build );
			}
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * Render the build queue and the new-build form.
	 */
	private function render_list(): void {
		echo '<h1>' . esc_html__( 'CAW Plugin Builder', 'caw-plugin-builder' ) . '</h1>';
		echo '<p class="caw-tagline">' . esc_html__( 'Describe a plugin. An Anthropic Managed Agent authors and tests it in a sandbox; a host-side safety gauntlet validates it before it ever touches this site.', 'caw-plugin-builder' ) . '</p>';

		$this->notices();
		$this->key_banner();
		$this->capability_banner();

		$resolution = ( new KeyResolver() )->resolve();

		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'New build', 'caw-plugin-builder' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'caw_create_build' );
		echo '<input type="hidden" name="action" value="caw_create_build" />';
		echo '<p><label for="caw-prompt"><strong>' . esc_html__( 'What should the plugin do?', 'caw-plugin-builder' ) . '</strong></label></p>';
		echo '<textarea id="caw-prompt" name="prompt" rows="5" class="large-text" placeholder="' . esc_attr__( 'e.g. Add a dashboard widget that shows the five most recent draft posts with their authors.', 'caw-plugin-builder' ) . '" required></textarea>';
		echo '<p><label for="caw-slug"><strong>' . esc_html__( 'Plugin slug', 'caw-plugin-builder' ) . '</strong></label><br />';
		echo '<input type="text" id="caw-slug" name="slug" class="regular-text" pattern="[a-z0-9\-]+" placeholder="my-plugin" required /></p>';
		echo '<p class="description">' . esc_html__( 'Lowercase letters, numbers and dashes. This becomes the plugin folder name.', 'caw-plugin-builder' ) . '</p>';

		if ( $resolution->is_resolved() ) {
			submit_button( __( 'Start build', 'caw-plugin-builder' ) );
		} else {
			echo '<p>';
			submit_button( __( 'Start build', 'caw-plugin-builder' ), 'primary', 'submit', false, [ 'disabled' => 'disabled' ] );
			echo ' <span class="caw-muted">' . esc_html__( 'Configure an API key first.', 'caw-plugin-builder' ) . '</span></p>';
		}
		echo '</form>';
		echo '</div>';

		$builds = $this->builds->find_recent( 50 );
		echo '<h2>' . esc_html__( 'Builds', 'caw-plugin-builder' ) . '</h2>';

		if ( [] === $builds ) {
			echo '<p>' . esc_html__( 'No builds yet.', 'caw-plugin-builder' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped caw-builds">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'caw-plugin-builder' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'caw-plugin-builder' ) . '</th>';
		echo '<th>' . esc_html__( 'Request', 'caw-plugin-builder' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'caw-plugin-builder' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'caw-plugin-builder' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $builds as $build ) {
			$url = add_query_arg( 'build', $build->id, $this->menu_url() );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '">#' . (int) $build->id . '</a></td>';
			echo '<td><code>' . esc_html( $build->slug ) . '</code></td>';
			echo '<td>' . esc_html( $this->excerpt( $build->prompt, 70 ) ) . '</td>';
			echo '<td>' . $this->status_badge( $build ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $build->created_at ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the per-build review screen.
	 *
	 * @param Build $build Build.
	 */
	private function render_detail( Build $build ): void {
		echo '<h1>';
		printf(
			/* translators: %d: build id */
			esc_html__( 'Build #%d', 'caw-plugin-builder' ),
			(int) $build->id
		);
		echo ' ' . $this->status_badge( $build ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p><a href="' . esc_url( $this->menu_url() ) . '">&larr; ' . esc_html__( 'All builds', 'caw-plugin-builder' ) . '</a></p>';

		$this->notices();

		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Request', 'caw-plugin-builder' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Slug:', 'caw-plugin-builder' ) . '</strong> <code>' . esc_html( $build->slug ) . '</code></p>';
		echo '<blockquote class="caw-prompt">' . nl2br( esc_html( $build->prompt ) ) . '</blockquote>';
		if ( '' !== $build->error ) {
			echo '<p class="caw-error"><strong>' . esc_html__( 'Error:', 'caw-plugin-builder' ) . '</strong> ' . esc_html( $build->error ) . '</p>';
		}
		echo '</div>';

		$this->render_ci_report( $build );

		if ( $build->has_artifact() ) {
			$this->render_destinations( $build );
		}

		$this->render_gate_report( $build );
		$this->render_files( $build );

		if ( ! $build->is_active() ) {
			echo '<div class="caw-card">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete this build and its artifact?', 'caw-plugin-builder' ) ) . '\');">';
			wp_nonce_field( 'caw_delete_build_' . $build->id );
			echo '<input type="hidden" name="action" value="caw_delete_build" />';
			echo '<input type="hidden" name="build" value="' . (int) $build->id . '" />';
			submit_button( __( 'Delete build', 'caw-plugin-builder' ), 'delete', 'submit', false );
			echo '</form>';
			echo '</div>';
		}
	}

	/**
	 * Render the sandbox CI report card.
	 *
	 * @param Build $build Build.
	 */
	private function render_ci_report( Build $build ): void {
		if ( [] === $build->ci_report ) {
			return;
		}
		$ci = $build->ci_report;

		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Sandbox CI report', 'caw-plugin-builder' ) . '</h2>';
		echo '<p class="caw-muted">' . esc_html__( 'The agent ran CI in the sandbox. CAW Plugin Builder computed this pass/fail verdict itself from the structured output — the agent does not get to pass its own CI.', 'caw-plugin-builder' ) . '</p>';

		$passed = ! empty( $ci['passed'] );
		echo '<p class="caw-verdict ' . ( $passed ? 'caw-pass' : 'caw-fail' ) . '">';
		echo $passed ? esc_html__( 'CI PASSED', 'caw-plugin-builder' ) : esc_html__( 'CI FAILED', 'caw-plugin-builder' );
		echo '</p>';
		echo '<p>' . esc_html( (string) ( $ci['summary'] ?? '' ) ) . '</p>';

		if ( ! empty( $ci['lint'] ) && is_array( $ci['lint'] ) ) {
			$failed = array_filter( $ci['lint'], static fn ( $r ): bool => 0 !== (int) ( $r['exit_code'] ?? 1 ) );
			if ( [] !== $failed ) {
				echo '<h3>' . esc_html__( 'Lint failures', 'caw-plugin-builder' ) . '</h3><ul class="caw-list">';
				foreach ( $failed as $row ) {
					echo '<li><code>' . esc_html( (string) ( $row['file'] ?? '' ) ) . '</code> — ' . esc_html( (string) ( $row['message'] ?? '' ) ) . '</li>';
				}
				echo '</ul>';
			}
		}

		if ( ! empty( $ci['notes'] ) && is_array( $ci['notes'] ) ) {
			echo '<h3>' . esc_html__( 'Notes', 'caw-plugin-builder' ) . '</h3><ul class="caw-list">';
			foreach ( $ci['notes'] as $note ) {
				echo '<li>' . esc_html( (string) $note ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Render the destination actions for a completed artifact.
	 *
	 * @param Build $build Build.
	 */
	private function render_destinations( Build $build ): void {
		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Destinations', 'caw-plugin-builder' ) . '</h2>';
		echo '<p class="caw-muted">' . esc_html__( 'The build produced a validated artifact. Choose what to do with it.', 'caw-plugin-builder' ) . '</p>';

		// Download — always available.
		$download = wp_nonce_url(
			add_query_arg(
				[
					'action' => 'caw_download_build',
					'build'  => $build->id,
				],
				admin_url( 'admin-post.php' )
			),
			'caw_download_build_' . $build->id
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $download ) . '">' . esc_html__( 'Download plugin zip', 'caw-plugin-builder' ) . '</a> ';
		echo '<span class="caw-muted">' . esc_html__( 'The zip bundles VALIDATION.md and the CI report.', 'caw-plugin-builder' ) . '</span></p>';

		// Install on this site — opt-in, gated, with an explicit confirm step.
		if ( $build->installed ) {
			echo '<p class="caw-verdict caw-pass">' . esc_html__( 'Installed on this site.', 'caw-plugin-builder' ) . '</p>';
		} elseif ( Capabilities::can_install_locally() ) {
			$confirm = add_query_arg(
				[
					'build'  => $build->id,
					'action' => 'confirm-install',
				],
				$this->menu_url()
			);
			echo '<p><a class="button" href="' . esc_url( $confirm ) . '">' . esc_html__( 'Install on this site…', 'caw-plugin-builder' ) . '</a> ';
			echo '<span class="caw-muted">' . esc_html__( 'Runs the three host safety gates first.', 'caw-plugin-builder' ) . '</span></p>';
		} else {
			echo '<p><span class="button" aria-disabled="true">' . esc_html__( 'Install on this site', 'caw-plugin-builder' ) . '</span> ';
			echo '<span class="caw-error">' . esc_html__( 'Disabled: this host cannot run the runtime probe (Gate 2).', 'caw-plugin-builder' ) . '</span></p>';
		}

		echo '<p class="caw-muted">' . esc_html__( 'Future destinations: install to another site, push to a Git repository.', 'caw-plugin-builder' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the host gate report, if the build has been through the gauntlet.
	 *
	 * @param Build $build Build.
	 */
	private function render_gate_report( Build $build ): void {
		if ( [] === $build->gate_report ) {
			return;
		}
		$report = $build->gate_report;

		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Host gate report', 'caw-plugin-builder' ) . '</h2>';
		echo '<p>' . esc_html( (string) ( $report['message'] ?? '' ) ) . '</p>';

		if ( ! empty( $report['results'] ) && is_array( $report['results'] ) ) {
			foreach ( $report['results'] as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}
				$ok = ! empty( $result['passed'] );
				echo '<div class="caw-gate ' . ( $ok ? 'caw-pass' : 'caw-fail' ) . '">';
				echo '<h3>';
				printf(
					/* translators: 1: gate number, 2: gate name */
					esc_html__( 'Gate %1$d — %2$s', 'caw-plugin-builder' ),
					(int) ( $result['number'] ?? 0 ),
					esc_html( (string) ( $result['name'] ?? '' ) )
				);
				echo ' — ' . ( $ok ? esc_html__( 'passed', 'caw-plugin-builder' ) : esc_html__( 'failed', 'caw-plugin-builder' ) ) . '</h3>';
				echo '<p>' . esc_html( (string) ( $result['summary'] ?? '' ) ) . '</p>';
				if ( ! empty( $result['details'] ) && is_array( $result['details'] ) ) {
					echo '<ul class="caw-list">';
					foreach ( $result['details'] as $detail ) {
						echo '<li><code>' . esc_html( (string) $detail ) . '</code></li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
		}
		echo '</div>';
	}

	/**
	 * Render the harvested file listing.
	 *
	 * @param Build $build Build.
	 */
	private function render_files( Build $build ): void {
		if ( '' === $build->staging_dir || ! is_dir( $build->staging_dir ) ) {
			return;
		}

		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Authored files', 'caw-plugin-builder' ) . '</h2>';
		echo '<p class="caw-muted">' . esc_html__( 'Agent-authored code, as harvested into staging. It has not run on this site.', 'caw-plugin-builder' ) . '</p>';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $build->staging_dir, \FilesystemIterator::SKIP_DOTS )
		);
		$files = [];
		foreach ( $iterator as $item ) {
			if ( $item->isFile() ) {
				$files[] = $item->getPathname();
			}
		}
		sort( $files );

		foreach ( $files as $file ) {
			$relative = substr( $file, strlen( $build->staging_dir ) + 1 );
			$size     = (int) filesize( $file );
			echo '<details class="caw-file">';
			echo '<summary><code>' . esc_html( $relative ) . '</code> <span class="caw-muted">' . esc_html( size_format( $size ) ) . '</span></summary>';
			if ( $size <= 200000 ) {
				$contents = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				echo '<pre class="caw-code">' . esc_html( $contents ) . '</pre>';
			} else {
				echo '<p class="caw-muted">' . esc_html__( 'File too large to preview.', 'caw-plugin-builder' ) . '</p>';
			}
			echo '</details>';
		}
		echo '</div>';
	}

	/**
	 * Render the explicit install confirmation screen.
	 *
	 * @param Build $build Build.
	 */
	private function render_confirm_install( Build $build ): void {
		echo '<h1>' . esc_html__( 'Install on this site', 'caw-plugin-builder' ) . '</h1>';

		if ( ! $build->has_artifact() ) {
			echo '<p>' . esc_html__( 'This build has no installable artifact.', 'caw-plugin-builder' ) . '</p>';
			return;
		}
		if ( ! Capabilities::can_install_locally() ) {
			echo '<p class="caw-error">' . esc_html__( 'This host cannot run the safety gates, so local installation is disabled.', 'caw-plugin-builder' ) . '</p>';
			return;
		}

		echo '<div class="caw-card">';
		echo '<p>' . esc_html__( 'You are about to install agent-authored code into this live WordPress. Before it is activated, three host-side gates run in order:', 'caw-plugin-builder' ) . '</p>';
		echo '<ol class="caw-list">';
		echo '<li>' . esc_html__( 'Gate 1 — every PHP file is linted in a separate process, while still in staging.', 'caw-plugin-builder' ) . '</li>';
		echo '<li>' . esc_html__( 'Gate 2 — the plugin is loaded and its activation hook run inside a throwaway process.', 'caw-plugin-builder' ) . '</li>';
		echo '<li>' . esc_html__( 'Gate 3 — it is activated through WordPress\'s own guarded activation path.', 'caw-plugin-builder' ) . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__( 'If any gate fails, the plugin is removed and your site is left unchanged. A watchdog mu-plugin can recover wp-admin if activation ever locks you out.', 'caw-plugin-builder' ) . '</p>';

		$token = (string) get_option( Installer::OPTION_PANIC_TOKEN, '' );
		if ( '' !== $token ) {
			$panic = add_query_arg(
				[
					'caw_panic' => '1',
					'caw_token' => $token,
				],
				home_url( '/' )
			);
			echo '<p class="caw-muted">' . esc_html__( 'Emergency recovery URL (bookmark this before installing):', 'caw-plugin-builder' ) . '<br /><code>' . esc_html( $panic ) . '</code></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'caw_install_build_' . $build->id );
		echo '<input type="hidden" name="action" value="caw_install_build" />';
		echo '<input type="hidden" name="build" value="' . (int) $build->id . '" />';
		echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ';
		echo esc_html__( 'I understand this runs agent-authored code on this site and I want to proceed.', 'caw-plugin-builder' ) . '</label></p>';
		submit_button( __( 'Run the gates and install', 'caw-plugin-builder' ) );
		echo ' <a href="' . esc_url( add_query_arg( 'build', $build->id, $this->menu_url() ) ) . '">' . esc_html__( 'Cancel', 'caw-plugin-builder' ) . '</a>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'caw-plugin-builder' ) );
		}

		echo '<div class="wrap caw-wrap">';
		echo '<h1>' . esc_html__( 'Plugin Builder Settings', 'caw-plugin-builder' ) . '</h1>';

		$this->key_banner();

		if ( Capabilities::has_connectors_api() ) {
			echo '<div class="caw-card"><p>';
			echo esc_html__( 'This site has the WordPress 7.0 Connectors API. Manage the Anthropic API key under Settings → Connectors; this plugin reads it automatically.', 'caw-plugin-builder' );
			echo '</p></div>';
		} else {
			echo '<div class="caw-card">';
			echo '<h2>' . esc_html__( 'Anthropic API key (legacy)', 'caw-plugin-builder' ) . '</h2>';
			echo '<p class="caw-muted">' . esc_html__( 'This site predates the Connectors API. The key is stored as a plugin option. An environment variable or PHP constant named ANTHROPIC_API_KEY always takes precedence.', 'caw-plugin-builder' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			settings_fields( 'caw_plugin_builder_settings' );
			$current = (string) get_option( KeyResolver::LEGACY_OPTION, '' );
			echo '<p><input type="password" name="' . esc_attr( KeyResolver::LEGACY_OPTION ) . '" value="' . esc_attr( $current ) . '" class="regular-text" autocomplete="off" placeholder="sk-ant-..." /></p>';
			submit_button( __( 'Save key', 'caw-plugin-builder' ) );
			echo '</form>';
			echo '</div>';
		}

		$this->render_diagnostics();
		echo '</div>';
	}

	/**
	 * Render the host diagnostics card.
	 */
	private function render_diagnostics(): void {
		$summary = Capabilities::summary();
		echo '<div class="caw-card">';
		echo '<h2>' . esc_html__( 'Host diagnostics', 'caw-plugin-builder' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		$rows = [
			__( 'WordPress version', 'caw-plugin-builder' )      => (string) $summary['wp_version'],
			__( 'PHP version', 'caw-plugin-builder' )            => (string) $summary['php_version'],
			__( 'Connectors API', 'caw-plugin-builder' )         => $summary['connectors_api'] ? __( 'available', 'caw-plugin-builder' ) : __( 'not available (6.x fallback)', 'caw-plugin-builder' ),
			__( 'exec() available', 'caw-plugin-builder' )       => $summary['can_exec'] ? __( 'yes', 'caw-plugin-builder' ) : __( 'no', 'caw-plugin-builder' ),
			__( 'Local install', 'caw-plugin-builder' )          => $summary['can_install_locally'] ? __( 'enabled', 'caw-plugin-builder' ) : __( 'disabled', 'caw-plugin-builder' ),
			__( 'Watchdog mu-plugin', 'caw-plugin-builder' )     => Installer::watchdog_installed() ? __( 'installed', 'caw-plugin-builder' ) : __( 'missing', 'caw-plugin-builder' ),
		];
		foreach ( $rows as $label => $value ) {
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Handle the new-build form submission.
	 */
	public function handle_create(): void {
		$this->guard( 'caw_create_build' );

		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$slug   = isset( $_POST['slug'] ) ? $this->sanitize_slug( wp_unslash( $_POST['slug'] ) ) : '';

		if ( '' === trim( $prompt ) || '' === $slug ) {
			$this->redirect_with_notice( $this->menu_url(), 'error', __( 'A description and a valid slug are both required.', 'caw-plugin-builder' ) );
		}

		$build         = new Build();
		$build->status = Build::STATUS_PENDING;
		$build->prompt = $prompt;
		$build->slug   = $slug;
		$build         = $this->builds->insert( $build );

		Poller::nudge();

		$this->redirect_with_notice(
			add_query_arg( 'build', $build->id, $this->menu_url() ),
			'success',
			__( 'Build queued. The agent will start shortly.', 'caw-plugin-builder' )
		);
	}

	/**
	 * Handle the install confirmation submission.
	 */
	public function handle_install(): void {
		$build_id = isset( $_POST['build'] ) ? absint( wp_unslash( $_POST['build'] ) ) : 0;
		$this->guard( 'caw_install_build_' . $build_id );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'caw-plugin-builder' ) );
		}

		$build = $this->builds->find( $build_id );
		$back  = add_query_arg( 'build', $build_id, $this->menu_url() );

		if ( null === $build || ! $build->has_artifact() ) {
			$this->redirect_with_notice( $back, 'error', __( 'That build has no installable artifact.', 'caw-plugin-builder' ) );
		}
		if ( empty( $_POST['confirm'] ) ) {
			$this->redirect_with_notice( $back, 'error', __( 'You must confirm before installing.', 'caw-plugin-builder' ) );
		}

		// The gauntlet (especially the Gate 2 probe) can take a while.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilentErrors
		}

		$report = ( new HostGatePipeline() )->install( $build->artifact_path, $build->slug, $build->id );

		$build->gate_report = $report->to_array();
		$build->installed   = $report->installed();
		$this->builds->save( $build );

		$this->redirect_with_notice(
			$back,
			$report->passed() ? 'success' : 'error',
			$report->message()
		);
	}

	/**
	 * Stream a build artifact zip as a download.
	 */
	public function handle_download(): void {
		$build_id = isset( $_GET['build'] ) ? absint( wp_unslash( $_GET['build'] ) ) : 0;

		if ( ! current_user_can( self::CAPABILITY )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'caw_download_build_' . $build_id )
		) {
			wp_die( esc_html__( 'Security check failed.', 'caw-plugin-builder' ) );
		}

		$build = $this->builds->find( $build_id );
		if ( null === $build || ! $build->has_artifact() ) {
			wp_die( esc_html__( 'Artifact not found.', 'caw-plugin-builder' ) );
		}

		$path = $build->artifact_path;
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $build->slug . '-build-' . $build->id . '.zip' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Handle a build deletion.
	 */
	public function handle_delete(): void {
		$build_id = isset( $_POST['build'] ) ? absint( wp_unslash( $_POST['build'] ) ) : 0;
		$this->guard( 'caw_delete_build_' . $build_id );

		$build = $this->builds->find( $build_id );
		if ( null !== $build ) {
			if ( '' !== $build->artifact_path && is_file( $build->artifact_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.PHP.NoSilentErrors
				@unlink( $build->artifact_path );
			}
			$staging = Paths::build_staging_dir( $build->id );
			if ( '' !== $staging ) {
				Paths::rmtree( $staging );
			}
			$this->builds->delete( $build->id );
		}

		$this->redirect_with_notice( $this->menu_url(), 'success', __( 'Build deleted.', 'caw-plugin-builder' ) );
	}

	/**
	 * Verify nonce + capability for a POST action, or die.
	 *
	 * @param string $nonce_action Nonce action string.
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'caw-plugin-builder' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Render the API key status banner.
	 */
	private function key_banner(): void {
		$resolver   = new KeyResolver();
		$resolution = $resolver->resolve();

		if ( $resolution->is_resolved() ) {
			echo '<div class="notice notice-success inline"><p>';
			printf(
				/* translators: %s: key source label */
				esc_html__( 'Anthropic API key detected — source: %s.', 'caw-plugin-builder' ),
				'<strong>' . esc_html( $resolution->source_label() ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html__( 'No Anthropic API key is configured. Builds cannot start until one is available.', 'caw-plugin-builder' ) . ' ';
		echo '<a href="' . esc_url( $resolver->settings_link() ) . '">' . esc_html__( 'Configure a key', 'caw-plugin-builder' ) . '</a>.';
		echo '</p></div>';
	}

	/**
	 * Render a banner when local install is unavailable.
	 */
	private function capability_banner(): void {
		$blockers = Capabilities::install_blockers();
		if ( [] === $blockers ) {
			return;
		}
		echo '<div class="notice notice-info inline"><p>';
		echo esc_html__( 'Builds and downloads work normally, but "Install on this site" is disabled on this host:', 'caw-plugin-builder' );
		echo '</p><ul class="caw-list">';
		foreach ( $blockers as $blocker ) {
			echo '<li>' . esc_html( $blocker ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Render a transient notice carried across a redirect.
	 */
	private function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['caw_notice'] ) ? sanitize_key( wp_unslash( $_GET['caw_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key  = isset( $_GET['caw_msg'] ) ? sanitize_key( wp_unslash( $_GET['caw_msg'] ) ) : '';
		if ( '' === $type || '' === $key ) {
			return;
		}
		$message = get_transient( 'caw_notice_' . $key );
		if ( false === $message ) {
			return;
		}
		delete_transient( 'caw_notice_' . $key );
		$class = 'error' === $type ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $message ) . '</p></div>';
	}

	/**
	 * Redirect to a URL carrying a one-shot notice.
	 *
	 * @param string $url     Destination URL.
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 */
	private function redirect_with_notice( string $url, string $type, string $message ): void {
		$key = wp_generate_password( 8, false, false );
		set_transient( 'caw_notice_' . $key, $message, 60 );
		wp_safe_redirect(
			add_query_arg(
				[
					'caw_notice' => $type,
					'caw_msg'    => $key,
				],
				$url
			)
		);
		exit;
	}

	/**
	 * Render a status badge for a build.
	 *
	 * @param Build $build Build.
	 * @return string HTML.
	 */
	private function status_badge( Build $build ): string {
		$class = 'caw-badge caw-badge-' . sanitize_html_class( $build->status );
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $build->status_label() ) . '</span>';
	}

	/**
	 * The menu page URL.
	 *
	 * @return string URL.
	 */
	private function menu_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Sanitise a slug from user input.
	 *
	 * @param string $slug Raw slug.
	 * @return string Safe slug.
	 */
	private function sanitize_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9\-]+/', '-', $slug );
		return trim( (string) preg_replace( '/-+/', '-', $slug ), '-' );
	}

	/**
	 * Truncate a string for table display.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length.
	 * @return string Excerpt.
	 */
	private function excerpt( string $text, int $length ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		return mb_substr( $text, 0, $length - 1 ) . '…';
	}
}
