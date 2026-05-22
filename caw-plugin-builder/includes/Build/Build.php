<?php
/**
 * Build record.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Build;

/**
 * One end-to-end build: a natural-language request, the agent session that
 * fulfils it, the harvested CI report, the completed artifact, and — if the
 * admin chose the local destination — the host gate report.
 *
 * The lifecycle has hard stops between stages (see the STATUS_* constants).
 * A build only ever moves forward; a failure at any stage is terminal.
 */
final class Build {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_BUILDING  = 'building';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	public int $id = 0;
	public string $created_at = '';
	public string $updated_at = '';
	public string $status = self::STATUS_PENDING;
	public string $slug = '';
	public string $prompt = '';
	public string $provider = 'anthropic';

	/** @var array<string, mixed> Opaque provider references (agent/session/environment IDs). */
	public array $provider_ref = [];

	/** @var array<string, mixed> Structured CI report computed by our code from harvested results. */
	public array $ci_report = [];

	/** @var array<string, mixed> Host gate report (populated only on local install). */
	public array $gate_report = [];

	public string $artifact_path = '';
	public string $staging_dir = '';
	public string $error = '';
	public bool $installed = false;
	public int $poll_attempts = 0;

	/**
	 * Rehydrate a build from a database row.
	 *
	 * @param array<string, mixed> $row Raw row from $wpdb.
	 * @return self Build.
	 */
	public static function from_row( array $row ): self {
		$build = new self();

		$build->id            = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$build->created_at    = (string) ( $row['created_at'] ?? '' );
		$build->updated_at    = (string) ( $row['updated_at'] ?? '' );
		$build->status        = (string) ( $row['status'] ?? self::STATUS_PENDING );
		$build->slug          = (string) ( $row['slug'] ?? '' );
		$build->prompt        = (string) ( $row['prompt'] ?? '' );
		$build->provider      = (string) ( $row['provider'] ?? 'anthropic' );
		$build->artifact_path = (string) ( $row['artifact_path'] ?? '' );
		$build->staging_dir   = (string) ( $row['staging_dir'] ?? '' );
		$build->error         = (string) ( $row['error'] ?? '' );
		$build->installed     = ! empty( $row['installed'] );
		$build->poll_attempts = isset( $row['poll_attempts'] ) ? (int) $row['poll_attempts'] : 0;

		$build->provider_ref = self::decode_json( $row['provider_ref'] ?? '' );
		$build->ci_report    = self::decode_json( $row['ci_report'] ?? '' );
		$build->gate_report  = self::decode_json( $row['gate_report'] ?? '' );

		return $build;
	}

	/**
	 * Serialise the build to a database row (id excluded).
	 *
	 * @return array<string, mixed> Row data for $wpdb.
	 */
	public function to_row(): array {
		return [
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
			'status'        => $this->status,
			'slug'          => $this->slug,
			'prompt'        => $this->prompt,
			'provider'      => $this->provider,
			'provider_ref'  => (string) wp_json_encode( $this->provider_ref ),
			'ci_report'     => (string) wp_json_encode( $this->ci_report ),
			'gate_report'   => (string) wp_json_encode( $this->gate_report ),
			'artifact_path' => $this->artifact_path,
			'staging_dir'   => $this->staging_dir,
			'error'         => $this->error,
			'installed'     => $this->installed ? 1 : 0,
			'poll_attempts' => $this->poll_attempts,
		];
	}

	/**
	 * Whether the build has reached a terminal state.
	 *
	 * @return bool True when no further work will happen.
	 */
	public function is_terminal(): bool {
		return in_array(
			$this->status,
			[ self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED ],
			true
		);
	}

	/**
	 * Whether the build is still being processed by the poller.
	 *
	 * @return bool True when the poller should keep handling it.
	 */
	public function is_active(): bool {
		return in_array(
			$this->status,
			[ self::STATUS_PENDING, self::STATUS_BUILDING ],
			true
		);
	}

	/**
	 * Whether a completed, downloadable artifact exists.
	 *
	 * @return bool True when the artifact zip is present.
	 */
	public function has_artifact(): bool {
		return self::STATUS_COMPLETED === $this->status
			&& '' !== $this->artifact_path
			&& is_file( $this->artifact_path );
	}

	/**
	 * Human-readable status label.
	 *
	 * @return string Label.
	 */
	public function status_label(): string {
		$labels = [
			self::STATUS_PENDING   => __( 'Queued', 'caw-plugin-builder' ),
			self::STATUS_BUILDING  => __( 'Agent building & testing', 'caw-plugin-builder' ),
			self::STATUS_COMPLETED => __( 'Completed', 'caw-plugin-builder' ),
			self::STATUS_FAILED    => __( 'Failed', 'caw-plugin-builder' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'caw-plugin-builder' ),
		];
		return $labels[ $this->status ] ?? $this->status;
	}

	/**
	 * Decode a JSON column into an array, tolerating empty/invalid values.
	 *
	 * @param mixed $raw Raw column value.
	 * @return array<string, mixed> Decoded array.
	 */
	private static function decode_json( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
