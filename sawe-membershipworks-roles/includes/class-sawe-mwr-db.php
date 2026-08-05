<?php
/**
 * Database layer for SAWE MembershipWorks Role Sync.
 *
 * Owns the single custom table this plugin uses, {$wpdb->prefix}sawe_mwr_check_log,
 * which replaces the old 'sawe_check_date' user-meta throttle timestamp used by
 * the original code snippet. One row is kept per WordPress user (upserted on
 * every check), so the table doubles as both the throttle clock and the
 * diagnostic history for the most recent check.
 *
 * Table columns:
 *   id               bigint unsigned  Primary key.
 *   user_id          bigint unsigned  WordPress user ID. Unique — one row/user.
 *   user_login       varchar(60)      Snapshot of the username at last check.
 *   display_name     varchar(250)     Snapshot of the display name at last check.
 *   is_member        tinyint(1)       1 if the user currently holds the 'member' role
 *                                     as of the last successful check.
 *   is_corporate     tinyint(1)       1 if the user currently holds 'member-company'.
 *   status           varchar(20)      'ok' or 'error'.
 *   api_response     longtext         JSON-encoded raw MembershipWorks shortcode
 *                                     responses for the member/corporate checks.
 *   error_message    text             Human-readable diagnostic message when
 *                                     status = 'error' (e.g. "sf_shortcode()
 *                                     function not found"). Empty when status = 'ok'.
 *   last_checked_at  datetime         When this row was last updated. Used to
 *                                     enforce the once-per-day (members) / every-
 *                                     five-minutes (non-members) throttle.
 *   created_at       datetime         When this row was first inserted.
 *
 * @package SAWE_MWR
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MWR_DB {

	/**
	 * Return the fully-qualified table name (with the site's $wpdb prefix).
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'sawe_mwr_check_log';
	}

	/**
	 * Create (or upgrade) the log table using dbDelta().
	 *
	 * dbDelta() is idempotent: calling this on a site that already has the
	 * table will only apply schema differences, never drop data. Safe to call
	 * on every activation.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() is picky about formatting: two spaces after PRIMARY KEY,
		// each field on its own line, no trailing comma issues, etc.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			user_login varchar(60) NOT NULL DEFAULT '',
			display_name varchar(250) NOT NULL DEFAULT '',
			is_member tinyint(1) NOT NULL DEFAULT 0,
			is_corporate tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'ok',
			api_response longtext NULL,
			error_message text NULL,
			last_checked_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY user_login (user_login),
			KEY last_checked_at (last_checked_at),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the log table entirely. Only called from uninstall.php, and only
	 * when the admin has opted in via the 'sawe_mwr_remove_table_on_uninstall'
	 * option (see admin/class-sawe-mwr-admin.php Settings section).
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, no user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Fetch the single log row for a given user, if one exists.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return object|null stdClass row, or null if no check has ever been logged.
	 */
	public static function get_log_for_user( int $user_id ): ?object {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ?: null;
	}

	/**
	 * Insert or update the single log row for a user.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE keyed on the unique user_id
	 * column, so this is safe to call concurrently without a race condition
	 * creating two rows for the same user.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $data {
	 *     Fields to save. All keys optional except where noted.
	 *
	 *     @type string $user_login      Required. Current username.
	 *     @type string $display_name    Required. Current display name.
	 *     @type bool   $is_member       Whether the user currently holds the 'member' role.
	 *     @type bool   $is_corporate    Whether the user currently holds 'member-company'.
	 *     @type string $status          'ok' or 'error'. Default 'ok'.
	 *     @type string $api_response    Raw JSON string of the MembershipWorks response(s).
	 *     @type string $error_message   Diagnostic message, empty string if none.
	 *     @type string $last_checked_at MySQL datetime string. Defaults to now (site time).
	 * }
	 *
	 * @return void
	 */
	public static function upsert_log( int $user_id, array $data ): void {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$fields = array(
			'user_id'         => $user_id,
			'user_login'      => (string) ( $data['user_login'] ?? '' ),
			'display_name'    => (string) ( $data['display_name'] ?? '' ),
			'is_member'       => empty( $data['is_member'] ) ? 0 : 1,
			'is_corporate'    => empty( $data['is_corporate'] ) ? 0 : 1,
			'status'          => (string) ( $data['status'] ?? 'ok' ),
			'api_response'    => (string) ( $data['api_response'] ?? '' ),
			'error_message'   => (string) ( $data['error_message'] ?? '' ),
			'last_checked_at' => (string) ( $data['last_checked_at'] ?? $now ),
			'created_at'      => $now,
		);

		$existing = self::get_log_for_user( $user_id );

		if ( $existing ) {
			// Preserve the original created_at on update.
			unset( $fields['created_at'] );
			$wpdb->update(
				$table,
				$fields,
				array( 'user_id' => $user_id ),
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$table,
				$fields,
				array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Delete one or more log rows by ID.
	 *
	 * Used by the admin list table's row "Delete" action and bulk action so an
	 * admin can force a user to be re-checked on their next login/page load —
	 * with no row present, maybe_sync_current_user() treats the user as never
	 * checked and skips the throttle entirely.
	 *
	 * @param int[] $ids Row IDs (the table's own auto-increment `id` column,
	 *                   not user_id) to delete.
	 *
	 * @return int Number of rows actually deleted.
	 */
	public static function delete_logs( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return 0;
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name hard-coded, placeholders built from a fixed-length array of %d above.
		$sql    = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
		$result = $wpdb->query( $wpdb->prepare( $sql, $ids ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Query log rows for the admin list table, with search, error filtering,
	 * sorting, and pagination.
	 *
	 * @param array $args {
	 *     @type string $search    Matched against user_login, display_name, and
	 *                             error_message via LIKE.
	 *     @type string $status    'ok', 'error', or '' (any) — filters the status column.
	 *     @type string $error     Exact error_message to filter on (used by the
	 *                             "Error message" dropdown filter). Empty = no filter.
	 *     @type string $orderby   Column to sort by. Whitelisted in code below.
	 *     @type string $order     'ASC' or 'DESC'.
	 *     @type int    $paged     1-based page number.
	 *     @type int    $per_page  Rows per page.
	 * }
	 *
	 * @return array { rows: object[], total: int }
	 */
	public static function query_rows( array $args ): array {
		global $wpdb;
		$table = self::table_name();

		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$status   = (string) ( $args['status'] ?? '' );
		$error    = (string) ( $args['error'] ?? '' );
		$orderby  = (string) ( $args['orderby'] ?? 'last_checked_at' );
		$order    = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );

		// Whitelist sortable columns to prevent SQL injection via orderby.
		$allowed_orderby = array(
			'user_login'      => 'user_login',
			'display_name'    => 'display_name',
			'last_checked_at' => 'last_checked_at',
			'status'          => 'status',
			'is_member'       => 'is_member',
		);
		$orderby_sql = $allowed_orderby[ $orderby ] ?? 'last_checked_at';

		$where  = array( '1=1' );
		$values = array();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(user_login LIKE %s OR display_name LIKE %s OR error_message LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( in_array( $status, array( 'ok', 'error' ), true ) ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		if ( '' !== $error ) {
			$where[]  = 'error_message = %s';
			$values[] = $error;
		}

		$where_sql = implode( ' AND ', $where );

		// Total count for pagination (same filters, no LIMIT).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are hard-coded, values are passed to prepare() below.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( empty( $values )
			? $wpdb->get_var( $count_sql )
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) );

		$offset = ( $paged - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names are hard-coded and whitelisted above.
		$rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby_sql} {$order} LIMIT %d OFFSET %d";
		$row_values = array_merge( $values, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $rows_sql, $row_values ) );

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Return a list of distinct, non-empty error messages currently in the
	 * table, most recently seen first. Used to populate the "Error message"
	 * filter dropdown on the admin screen.
	 *
	 * @return string[]
	 */
	public static function get_distinct_errors(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no user input.
		$results = $wpdb->get_col(
			"SELECT DISTINCT error_message FROM {$table}
			 WHERE error_message IS NOT NULL AND error_message != ''
			 ORDER BY last_checked_at DESC"
		);
		return $results ?: array();
	}

	/**
	 * Return simple counts used for the list table's status views
	 * ("All | OK | Errors").
	 *
	 * @return array { all: int, ok: int, error: int }
	 */
	public static function get_status_counts(): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, no user input.
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
			OBJECT_K
		);
		$ok    = isset( $counts['ok'] ) ? (int) $counts['ok']->cnt : 0;
		$error = isset( $counts['error'] ) ? (int) $counts['error']->cnt : 0;
		return array(
			'all'   => $ok + $error,
			'ok'    => $ok,
			'error' => $error,
		);
	}
}
