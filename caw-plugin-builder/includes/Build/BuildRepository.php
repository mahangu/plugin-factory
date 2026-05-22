<?php
/**
 * Build persistence.
 *
 * @package CAW\PluginBuilder
 */

declare(strict_types=1);

namespace CAW\PluginBuilder\Build;

/**
 * Stores build records in a dedicated table.
 *
 * A custom table (rather than a custom post type) keeps build state out of the
 * content surface entirely: builds are operational records, never published.
 */
final class BuildRepository {

	/**
	 * Unprefixed table name.
	 */
	private const TABLE = 'caw_builds';

	/**
	 * Fully-qualified, prefixed table name.
	 *
	 * @return string Table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the builds table.
	 *
	 * Invoked from activation; safe to call repeatedly via dbDelta().
	 */
	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			slug VARCHAR(96) NOT NULL DEFAULT '',
			prompt LONGTEXT NOT NULL,
			provider VARCHAR(32) NOT NULL DEFAULT 'anthropic',
			provider_ref LONGTEXT NULL,
			ci_report LONGTEXT NULL,
			gate_report LONGTEXT NULL,
			artifact_path TEXT NULL,
			staging_dir TEXT NULL,
			error TEXT NULL,
			installed TINYINT(1) NOT NULL DEFAULT 0,
			poll_attempts INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a new build, returning it with its assigned id.
	 *
	 * @param Build $build Build to persist (id is ignored).
	 * @return Build The stored build with id populated.
	 */
	public function insert( Build $build ): Build {
		global $wpdb;

		$now               = current_time( 'mysql', true );
		$build->created_at = $now;
		$build->updated_at = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( self::table_name(), $build->to_row() );
		$build->id = (int) $wpdb->insert_id;

		return $build;
	}

	/**
	 * Persist changes to an existing build.
	 *
	 * @param Build $build Build with a non-zero id.
	 */
	public function save( Build $build ): void {
		global $wpdb;

		if ( $build->id <= 0 ) {
			return;
		}

		$build->updated_at = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table_name(), $build->to_row(), [ 'id' => $build->id ] );
	}

	/**
	 * Fetch a build by id.
	 *
	 * @param int $id Build id.
	 * @return Build|null The build, or null when not found.
	 */
	public function find( int $id ): ?Build {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Build::from_row( $row ) : null;
	}

	/**
	 * Fetch builds that the poller still needs to advance.
	 *
	 * @param int $limit Maximum number of builds to return.
	 * @return Build[] Active builds, oldest first.
	 */
	public function find_active( int $limit = 10 ): array {
		global $wpdb;

		$table    = self::table_name();
		$statuses = [ Build::STATUS_PENDING, Build::STATUS_BUILDING ];
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status IN ({$in}) ORDER BY id ASC LIMIT %d",
				array_merge( $statuses, [ $limit ] )
			),
			ARRAY_A
		);

		return array_map( [ Build::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Fetch the most recent builds for the admin listing.
	 *
	 * @param int $limit Maximum number of builds.
	 * @return Build[] Builds, newest first.
	 */
	public function find_recent( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );

		return array_map( [ Build::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Delete a build row.
	 *
	 * @param int $id Build id.
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), [ 'id' => $id ] );
	}
}
