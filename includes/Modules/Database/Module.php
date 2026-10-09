<?php
namespace Lumia\Tools\Modules\Database;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Database module: browse, edit and export the WordPress tables.
 */
class Module extends AbstractModule {

	/** Maximum number of rows returned by a free-form SELECT query without an explicit LIMIT. */
	const QUERY_ROW_CAP = 1000;

	/**
	 * Above this estimate, the table list no longer counts exactly: a
	 * COUNT(*) on a postmeta table or a log with several million rows blocked
	 * the tab for several seconds. The exact count arrives anyway once the
	 * table is opened.
	 */
	const EXACT_COUNT_THRESHOLD = 100000;

	/** Keywords forbidden in the free-form SQL editor (out-of-scope or server-level destructive operations). */
	const FORBIDDEN_KEYWORDS = [
		'DROP DATABASE',
		'DROP SCHEMA',
		'CREATE USER',
		'DROP USER',
		'ALTER USER',
		'SET PASSWORD',
		'GRANT',
		'REVOKE',
		'SHUTDOWN',
		'CREATE DATABASE',
		// Writing / reading files from the MySQL server. INTO OUTFILE hides
		// behind a SELECT, that is behind the "read" classification, yet it
		// writes to the database server's disk.
		'INTO OUTFILE',
		'INTO DUMPFILE',
		'LOAD DATA',
		'LOAD_FILE',
		// Statements that touch the MySQL SERVER, not this site: global
		// variables (general_log_file writes wherever you like), code loading
		// (UDF via SONAME, plugins), killing connections.
		'SET GLOBAL',
		'SET PERSIST',
		'INSTALL PLUGIN',
		'INSTALL COMPONENT',
		'SONAME',
		'KILL',
		'ALTER DATABASE',
		'ALTER SCHEMA',
		'CREATE FUNCTION',
	];

	public function init(): void {
		add_action( 'wp_ajax_lumia_db_get_tables', [ $this, 'ajax_get_tables' ] );
		add_action( 'wp_ajax_lumia_db_get_rows', [ $this, 'ajax_get_rows' ] );
		add_action( 'wp_ajax_lumia_db_get_structure', [ $this, 'ajax_get_structure' ] );
		add_action( 'wp_ajax_lumia_db_update_row', [ $this, 'ajax_update_row' ] );
		add_action( 'wp_ajax_lumia_db_delete_row', [ $this, 'ajax_delete_row' ] );
		add_action( 'wp_ajax_lumia_db_insert_row', [ $this, 'ajax_insert_row' ] );
		add_action( 'wp_ajax_lumia_db_truncate', [ $this, 'ajax_truncate_table' ] );
		add_action( 'wp_ajax_lumia_db_drop_table', [ $this, 'ajax_drop_table' ] );
		add_action( 'wp_ajax_lumia_db_export_sql', [ $this, 'ajax_export_sql' ] );
		add_action( 'wp_ajax_lumia_db_run_query', [ $this, 'ajax_run_query' ] );
		add_action( 'wp_ajax_lumia_db_cleanup_scan', [ $this, 'ajax_cleanup_scan' ] );
		add_action( 'wp_ajax_lumia_db_cleanup_run', [ $this, 'ajax_cleanup_run' ] );
		add_action( 'wp_ajax_lumia_db_cleanup_optimize', [ $this, 'ajax_cleanup_optimize' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return []; }
	/**
	 * @param array<string, mixed> $s
	 */
	public function save_settings( array $s ): bool {
		return false; }
	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return []; }
	public static function get_uninstall_keys(): array {
		return [
			'options' => [],
			'meta'    => [],
		]; }

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/database.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/database.js' ];
	}

	/**
	 * Strings for database.js, read as `lumiaAdmin.i18n.<key>`. The generic
	 * keys (confirm, cancel, error) come from the core.
	 *
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n' => [
				'confirmDelete'         => __( 'Delete this row?', 'lumia-tools' ),
				'confirmTruncate'       => __( 'Empty the table? This action cannot be undone.', 'lumia-tools' ),
				'queryWarning'          => __( 'Warning: modifying queries (UPDATE, DELETE, DROP…) run directly on the database. There is no undo.', 'lumia-tools' ),
				'confirmWrite'          => __( 'This query modifies the database and cannot be undone. Confirm execution?', 'lumia-tools' ),
				// Generic actions.
				'delete'                => __( 'Delete', 'lumia-tools' ),
				'execute'               => __( 'Execute', 'lumia-tools' ),
				// States / feedback.
				'loading'               => __( 'Loading…', 'lumia-tools' ),
				'executing'             => __( 'Executing…', 'lumia-tools' ),
				'inserting'             => __( 'Inserting…', 'lumia-tools' ),
				'rowAdded'              => __( 'Row added', 'lumia-tools' ),
				'rowUpdated'            => __( 'Row updated', 'lumia-tools' ),
				'rowDeleted'            => __( 'Row deleted', 'lumia-tools' ),
				'tableTruncated'        => __( 'Table emptied', 'lumia-tools' ),
				'tableDropped'          => __( 'Table deleted', 'lumia-tools' ),
				'networkError'          => __( 'Network error', 'lumia-tools' ),
				// Table / search labels.
				'noTables'              => __( 'No tables found.', 'lumia-tools' ),
				'noRows'                => __( 'No rows.', 'lumia-tools' ),
				'noColumn'              => __( 'No columns.', 'lumia-tools' ),
				'noHistory'             => __( 'No history.', 'lumia-tools' ),
				'clearHistory'          => __( 'Clear history', 'lumia-tools' ),
				'history'               => _x( 'History', 'SQL query history', 'lumia-tools' ),
				'searchInTable'         => __( 'Search in table…', 'lumia-tools' ),
				'rowsLabel'             => __( 'rows', 'lumia-tools' ),
				'perPageLabel'          => __( 'Rows / page', 'lumia-tools' ),
				'setNull'               => __( 'Set NULL', 'lumia-tools' ),
				'otherTables'           => __( 'Other tables', 'lumia-tools' ),
				/* translators: %s: size in bytes. */
				'sizeBytes'             => __( '%s B', 'lumia-tools' ),
				/* translators: %s: size in kilobytes. */
				'sizeKb'                => __( '%s KB', 'lumia-tools' ),
				/* translators: %s: size in megabytes. */
				'sizeMb'                => __( '%s MB', 'lumia-tools' ),
				// Structure tab.
				'structureColumns'      => __( 'Columns', 'lumia-tools' ),
				'structureIndexes'      => __( 'Indexes / Keys', 'lumia-tools' ),
				'colName'               => _x( 'Name', 'table structure column', 'lumia-tools' ),
				'colType'               => _x( 'Type', 'table structure column', 'lumia-tools' ),
				'colDefault'            => _x( 'Default', 'table structure column', 'lumia-tools' ),
				'colKey'                => _x( 'Key', 'table structure column', 'lumia-tools' ),
				'colExtra'              => _x( 'Extra', 'table structure column', 'lumia-tools' ),
				'colColumn'             => _x( 'Column', 'table structure column', 'lumia-tools' ),
				'colUnique'             => _x( 'Unique', 'table structure column', 'lumia-tools' ),
				'yes'                   => _x( 'Yes', 'index is unique', 'lumia-tools' ),
				'no'                    => _x( 'No', 'index is unique', 'lumia-tools' ),
				// Insert row modal.
				'primaryKeyHint'        => __( 'primary key', 'lumia-tools' ),
				'autoHint'              => __( 'auto', 'lumia-tools' ),
				'autoPlaceholder'       => __( '(auto)', 'lumia-tools' ),
				// Cleanup.
				'cleanupTitle'          => __( 'Cleanup', 'lumia-tools' ),
				'cleanupIntro'          => __( 'Each item is counted first; nothing is deleted without your confirmation. Back up the database before a large cleanup.', 'lumia-tools' ),
				'cleanupItems'          => __( 'Unneeded data', 'lumia-tools' ),
				'cleanupClean'          => __( 'Clean up', 'lumia-tools' ),
				'cleanupAll'            => __( 'Clean up everything', 'lumia-tools' ),
				'cleanupRescan'         => __( 'Recount', 'lumia-tools' ),
				'cleanupRunning'        => __( 'Cleaning…', 'lumia-tools' ),
				/* translators: 1: number of items, 2: item label. */
				'cleanupConfirm'        => __( 'Permanently delete %1$s item(s): %2$s?', 'lumia-tools' ),
				/* translators: %s: total number of items. */
				'cleanupConfirmAll'     => __( 'Permanently delete %s item(s), across all categories?', 'lumia-tools' ),
				/* translators: 1: number of items deleted, 2: item label. */
				'cleanupDone'           => __( '%1$s item(s) deleted: %2$s', 'lumia-tools' ),
				/* translators: %s: total number of items deleted. */
				'cleanupDoneTotal'      => __( '%s item(s) deleted', 'lumia-tools' ),
				/* translators: %s: number of items left. */
				'cleanupLeft'           => __( '%s item(s) could not be deleted.', 'lumia-tools' ),
				'optimizeTitle'         => __( 'Table optimization', 'lumia-tools' ),
				/* translators: 1: number of tables, 2: recoverable size. */
				'optimizeSummary'       => __( '%1$s fragmented table(s), %2$s recoverable.', 'lumia-tools' ),
				'optimizeNone'          => __( 'No fragmented tables.', 'lumia-tools' ),
				'optimizeBtn'           => __( 'Optimize', 'lumia-tools' ),
				'optimizeConfirm'       => __( 'OPTIMIZE TABLE rebuilds each table and may lock it for a few seconds. Start the optimization?', 'lumia-tools' ),
				/* translators: %s: number of tables optimized. */
				'optimizeDone'          => __( '%s table(s) optimized', 'lumia-tools' ),
				'foreignTitle'          => __( 'Plugin tables', 'lumia-tools' ),
				'foreignIntro'          => __( 'Tables outside WordPress core. The owning plugin is guessed from the table name: check before deleting. No table is deleted automatically.', 'lumia-tools' ),
				'foreignNone'           => __( 'No plugin tables.', 'lumia-tools' ),
				'foreignUnknown'        => __( 'No matching plugin', 'lumia-tools' ),
				/* translators: %s: plugin name(s). */
				'foreignInactive'       => __( 'Inactive plugin: %s', 'lumia-tools' ),
				/* translators: %s: plugin name(s). */
				'foreignActive'         => __( 'Active plugin: %s', 'lumia-tools' ),
				'open'                  => __( 'Open', 'lumia-tools' ),
				// SQL query tab.
				'queryTablePlaceholder' => __( 'my_table', 'lumia-tools' ),
				/* translators: %s: number of rows affected by the query. */
				'queryAffected'         => __( '%s row(s) affected.', 'lumia-tools' ),
				/* translators: %s: ID of the last inserted row. */
				'queryLastId'           => __( 'Last inserted ID: %s.', 'lumia-tools' ),
				'queryNoResult'         => __( 'Query executed. No results.', 'lumia-tools' ),
				/* translators: %s: number of rows returned by the query. */
				'queryRowCount'         => __( '%s row(s)', 'lumia-tools' ),
				/* translators: %d: maximum number of rows displayed. */
				'queryTruncated'        => __( 'Result truncated to %d rows. Add a LIMIT clause to target your query.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * SECURITY
	 * ================================================================ */

	/**
	 * On multisite, `manage_options` is a per-site capability while the
	 * database is shared by the whole network: a sub-site administrator would
	 * get SQL editing of the entire network here. See AbstractModule.
	 */
	public static function get_required_capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}
		// Stops $wpdb->print_error() from ECHOing error HTML before our JSON
		// (otherwise the response is corrupted and the JS shows "Network error"
		// instead of the SQL message).
		global $wpdb;
		$wpdb->suppress_errors( true );
	}

	/* ================================================================
	 * AJAX: TABLE LIST
	 * ================================================================ */

	public function ajax_get_tables(): void {
		$this->guard();

		global $wpdb;
		$prefix = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$tables_raw = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		$tables = [];
		foreach ( (array) $tables_raw as $t ) {
			$name     = $t['Name'];
			$is_wp    = str_starts_with( $name, $prefix );
			$estimate = (int) $t['Rows'];
			$approx   = false;

			// `SHOW TABLE STATUS`.Rows is only an estimate for InnoDB (often 0 or
			// very approximate). We correct it with a COUNT(*) while it stays
			// reasonable; above the threshold we keep the estimate and say so.
			// The name comes from SHOW TABLE STATUS, so it is safe to escape in backticks.
			if ( $estimate <= self::EXACT_COUNT_THRESHOLD ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $name ) . '`' );
				$rows  = null === $count ? $estimate : (int) $count;
			} else {
				$rows   = $estimate;
				$approx = true;
			}

			$tables[] = [
				'name'         => $name,
				'rows'         => $rows,
				'approx'       => $approx,
				'size'         => ( (int) $t['Data_length'] + (int) $t['Index_length'] ),
				'engine'       => $t['Engine'],
				'is_wp_prefix' => $is_wp,
				'prefix'       => $is_wp ? $prefix : '',
				'short_name'   => $is_wp ? substr( $name, strlen( $prefix ) ) : $name,
			];
		}

		wp_send_json_success(
			[
				'tables' => $tables,
				'prefix' => $prefix,
			]
		);
	}

	/* ================================================================
	 * IDENTIFIER VALIDATION
	 * ================================================================ */

	/**
	 * Checks that a table exists in the current database. Returns the validated
	 * name (safe to escape in backticks) or null.
	 */
	private function validate_table( string $table ): ?string {
		if ( '' === $table ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);
		return $exists ? $table : null;
	}

	/**
	 * Reads and validates the `table` POST parameter. Uses sanitize_text_field
	 * (and NOT sanitize_key, which lowercases and would break table/column
	 * names that are case-sensitive depending on the MySQL file system).
	 * Validation against information_schema guarantees the backticks are safe.
	 */
	private function read_table(): ?string {
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only called after guard().
		return $this->validate_table( $table );
	}

	/**
	 * Gets the real columns of a table, indexed by name (allowlist + typing).
	 * @return array<string, array<string, mixed>> Field => SHOW COLUMNS row.
	 */
	private function get_columns_map( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
		$map     = [];
		foreach ( (array) $columns as $col ) {
			$map[ $col['Field'] ] = $col;
		}
		return $map;
	}

	/**
	 * Determines the $wpdb placeholder (%d/%f/%s) that fits a column's SQL type.
	 *
	 * @param array<string, mixed> $col SHOW COLUMNS row.
	 */
	private function column_format( array $col ): string {
		$type = strtolower( $col['Type'] ?? '' );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|bit|year)\b/', $type ) ) {
			return '%d';
		}
		if ( preg_match( '/^(decimal|dec|numeric|float|double|real)\b/', $type ) ) {
			return '%f';
		}
		return '%s';
	}

	/** Translates common MySQL errors into readable messages (the raw message stays as a fallback). */
	private function friendly_db_error( string $raw, string $fallback ): string {
		if ( '' === $raw ) {
			return $fallback;
		}
		if ( stripos( $raw, 'Duplicate entry' ) !== false ) {
			return __( 'This value already exists (unique constraint).', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'foreign key' ) !== false ) {
			return __( 'Foreign key constraint violated.', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'cannot be null' ) !== false || stripos( $raw, "doesn't have a default" ) !== false ) {
			return __( 'A required field is missing.', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'Incorrect' ) !== false && stripos( $raw, 'value' ) !== false ) {
			return __( 'Incorrect value type for a column.', 'lumia-tools' ) . ' — ' . $raw;
		}
		return $raw;
	}

	public function ajax_get_rows(): void {
		$this->guard();

		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by guard() at the top of the handler.
		$page      = max( 1, isset( $_POST['page'] ) ? (int) $_POST['page'] : 1 );
		$per_page  = min( 200, max( 10, isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50 ) );
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$order_col = isset( $_POST['order_col'] ) ? sanitize_text_field( wp_unslash( $_POST['order_col'] ) ) : '';
		$order_dir = strtoupper( isset( $_POST['order_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['order_dir'] ) ) : 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}

		// Columns: $table validated via information_schema, backticks are safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$columns   = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
		$col_names = array_column( $columns, 'Field' );
		$primary   = '';
		foreach ( $columns as $col ) {
			if ( 'PRI' === $col['Key'] ) {
				$primary = $col['Field'];
				break; }
		}

		// Search: WHERE over all text-type columns (LIKE).
		$where = '';
		if ( '' !== $search ) {
			$text_cols = array_filter( $columns, static fn( $c ) => str_contains( strtolower( $c['Type'] ), 'char' ) || str_contains( strtolower( $c['Type'] ), 'text' ) );
			$clauses   = array_map( static fn( $c ) => '`' . $c['Field'] . '` LIKE ' . $wpdb->prepare( '%s', '%' . $wpdb->esc_like( $search ) . '%' ), $text_cols );
			if ( $clauses ) {
				$where = ' WHERE ' . implode( ' OR ', $clauses );
			}
		}

		// ORDER BY.
		$order = '';
		if ( $order_col && in_array( $order_col, $col_names, true ) ) {
			$order = ' ORDER BY `' . $order_col . '` ' . $order_dir;
		}

		// COUNT.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' . $where );

		// ROWS.
		$offset = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '`' . $where . $order . ' LIMIT ' . $per_page . ' OFFSET ' . $offset, ARRAY_A );

		wp_send_json_success(
			[
				'columns'  => $col_names,
				'primary'  => $primary,
				'rows'     => $rows,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => (int) ceil( $total / $per_page ),
			]
		);
	}

	public function ajax_get_structure(): void {
		$this->guard();

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`', ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A );

		wp_send_json_success(
			[
				'columns' => $columns,
				'indexes' => $indexes,
			]
		);
	}

	public function ajax_update_row(): void {
		$this->guard();

		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by guard() at the top of the handler; raw value and primary key, passed to $wpdb->update() which escapes.
		$primary_col = isset( $_POST['primary_col'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_col'] ) ) : '';
		$primary_val = isset( $_POST['primary_val'] ) ? wp_unslash( $_POST['primary_val'] ) : '';
		$col         = isset( $_POST['col'] ) ? sanitize_text_field( wp_unslash( $_POST['col'] ) ) : '';
		$value       = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$set_null    = ! empty( $_POST['set_null'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$table = $this->read_table();
		if ( null === $table || ! $primary_col || ! $col ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'lumia-tools' ) ] );
		}

		// Allowlist the column + primary key against the table's real columns.
		$columns = $this->get_columns_map( $table );
		if ( ! isset( $columns[ $col ], $columns[ $primary_col ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown column.', 'lumia-tools' ) ] );
		}

		// Explicit NULL: null value (forbidden if the column does not accept NULL).
		if ( $set_null ) {
			if ( 'YES' !== ( $columns[ $col ]['Null'] ?? 'NO' ) ) {
				wp_send_json_error( [ 'message' => __( 'This column does not accept the NULL value.', 'lumia-tools' ) ] );
			}
			$data    = [ $col => null ];
			$formats = null; // lets $wpdb produce NULL.
		} else {
			$data    = [ $col => $value ];
			$formats = [ $this->column_format( $columns[ $col ] ) ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( $table, $data, [ $primary_col => $primary_val ], $formats, [ $this->column_format( $columns[ $primary_col ] ) ] );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success( [ 'is_null' => $set_null ] );
	}

	public function ajax_delete_row(): void {
		$this->guard();

		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by guard() at the top of the handler; primary key passed to $wpdb->delete() which escapes.
		$primary_col = isset( $_POST['primary_col'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_col'] ) ) : '';
		$primary_val = isset( $_POST['primary_val'] ) ? wp_unslash( $_POST['primary_val'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$table = $this->read_table();
		if ( null === $table || ! $primary_col ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'lumia-tools' ) ] );
		}

		$columns = $this->get_columns_map( $table );
		if ( ! isset( $columns[ $primary_col ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown column.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->delete( $table, [ $primary_col => $primary_val ], [ $this->column_format( $columns[ $primary_col ] ) ] );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	public function ajax_insert_row(): void {
		$this->guard();

		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}

		global $wpdb;

		// Submitted fields (col => raw value) + columns explicitly set to NULL.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by guard() at the top of the handler; raw values typed below against the real columns, written via $wpdb->insert() which escapes.
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
		$nulls  = isset( $_POST['nulls'] ) && is_array( $_POST['nulls'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['nulls'] ) ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Real columns of the table (allowlist + typing).
		$columns = $this->get_columns_map( $table );

		$data    = [];
		$formats = [];
		foreach ( $columns as $field => $col ) {
			$extra = strtolower( $col['Extra'] ?? '' );

			// Column explicitly NULL: typed null value (refused if NOT NULL without a default).
			if ( in_array( $field, $nulls, true ) ) {
				if ( 'YES' !== ( $col['Null'] ?? 'NO' ) ) {
					/* translators: %s: column name. */
					wp_send_json_error( [ 'message' => sprintf( __( 'The column "%s" does not accept NULL.', 'lumia-tools' ), $field ) ] );
				}
				$data[ $field ] = null;
				$formats[]      = $this->column_format( $col );
				continue;
			}
			// Auto-increment left empty: delegated to MySQL.
			if ( str_contains( $extra, 'auto_increment' ) && ( ! isset( $fields[ $field ] ) || '' === $fields[ $field ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}
			$data[ $field ] = $fields[ $field ];
			$formats[]      = $this->column_format( $col );
		}

		if ( empty( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'No values to insert.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table, $data, $formats );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $this->friendly_db_error( $wpdb->last_error, __( 'Insert failed.', 'lumia-tools' ) ) ] );
		}
		wp_send_json_success( [ 'insert_id' => $wpdb->insert_id ] );
	}

	public function ajax_truncate_table(): void {
		$this->guard();

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( 'TRUNCATE TABLE `' . $table . '`' );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	public function ajax_drop_table(): void {
		$this->guard();

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( 'DROP TABLE `' . $table . '`' );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	/* ================================================================
	 * AJAX: CLEANUP (typed endpoints, never through ajax_run_query)
	 * ================================================================ */

	public function ajax_cleanup_scan(): void {
		$this->guard();

		$cleanup = new Cleanup();
		$items   = [];
		foreach ( Cleanup::items() as $key => $item ) {
			$items[] = [
				'key'         => $key,
				'label'       => $item['label'],
				'description' => $item['description'],
				'count'       => $cleanup->count( $key ),
			];
		}

		wp_send_json_success(
			[
				'items'      => $items,
				'fragmented' => $cleanup->fragmented_tables(),
				'foreign'    => $cleanup->foreign_tables(),
			]
		);
	}

	/**
	 * Purges one batch of an item. The client calls again as long as `deleted`
	 * and `remaining` are non-zero.
	 */
	public function ajax_cleanup_run(): void {
		$this->guard();

		$item = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		if ( ! array_key_exists( $item, Cleanup::items() ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown item.', 'lumia-tools' ) ] );
		}

		$cleanup = new Cleanup();
		$deleted = $cleanup->run( $item );
		wp_send_json_success(
			[
				'deleted'   => $deleted,
				'remaining' => $cleanup->count( $item ),
			]
		);
	}

	/**
	 * Optimizes ONE site table: `OPTIMIZE TABLE` rebuilds a whole InnoDB
	 * table, so one call per table avoids a timeout.
	 */
	public function ajax_cleanup_optimize(): void {
		$this->guard();

		$table   = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by guard().
		$cleanup = new Cleanup();
		if ( ! $cleanup->is_site_table( $table ) ) {
			wp_send_json_error( [ 'message' => __( 'Table not found.', 'lumia-tools' ) ] );
		}
		if ( ! $cleanup->optimize( $table ) ) {
			global $wpdb;
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	/**
	 * Finds a forbidden operation in a query, or null.
	 *
	 * Two precautions against false positives, which used to block perfectly
	 * legitimate queries:
	 *  - the comparison is done on whole WORDS ("migrant" no longer contains
	 *    "GRANT");
	 *  - string literals are neutralized beforehand, a value never being a
	 *    statement.
	 *
	 * The test only applies to this copy: it is the original query that is
	 * executed afterwards.
	 */
	private function find_forbidden_keyword( string $sql ): ?string {
		$subject = $this->normalize_sql( $sql );

		foreach ( self::FORBIDDEN_KEYWORDS as $kw ) {
			$pattern = '/\b' . str_replace( ' ', '\s+', preg_quote( $kw, '/' ) ) . '\b/i';
			if ( preg_match( $pattern, $subject ) ) {
				return $kw;
			}
		}

		return null;
	}

	/**
	 * Reduces a query to a comparable form, for the guard-rail tests only.
	 * The EXECUTED query remains the original.
	 *
	 * Three passes, in this order: the order is the whole point.
	 *
	 *  1. neutralize the literals ('…', "…") and the backtick-quoted
	 *     identifiers. A value is never a statement, and this must come FIRST:
	 *     a comment opener inside a string opens nothing at all, and treating
	 *     it as one would truncate the normalized query and hide what follows;
	 *  2. strip the comments. This is the hole the audit exploited: MySQL
	 *     accepts an empty comment as a word separator, so a DROP followed by
	 *     an empty comment and then DATABASE looked like no forbidden keyword
	 *     while being executed as "DROP DATABASE". Extending the blacklist did
	 *     not change that: there are infinitely many ways to write a space;
	 *  3. collapse every run of whitespace (line breaks included) into a
	 *     single space, so that the `\s+` of the patterns has even ground.
	 *
	 * Each `preg_replace` can fail (unterminated string, recursion limit); the
	 * previous state is then kept rather than letting an empty string pass
	 * every test.
	 */
	private function normalize_sql( string $sql ): string {
		$out = preg_replace(
			[ "#'[^']*'#", '#"[^"]*"#', '#`[^`]*`#' ],
			[ "''", '""', '``' ],
			$sql
		);
		$out = ( null === $out ) ? $sql : $out;

		$without_comments = preg_replace(
			[
				'#/\*.*?\*/#s',   // /* … */, including over several lines
				'#--[^\n]*#',     // -- up to the end of the line
				'#\#[^\n]*#',     // #  up to the end of the line
			],
			' ',
			$out
		);
		$out              = ( null === $without_comments ) ? $out : $without_comments;

		$collapsed = preg_replace( '/\s+/', ' ', $out );
		$out       = ( null === $collapsed ) ? $out : $collapsed;

		return trim( $out );
	}

	/**
	 * Is the query a plain read?
	 *
	 * Basing the answer on the first word alone is not enough: a `SELECT` can
	 * write (`INTO OUTFILE`), a `WITH … AS (…) DELETE …` starts with a read
	 * word, and `SELECT 1; DELETE FROM …` hides one behind a semicolon. The
	 * classification decides both the write confirmation AND the row cap:
	 * getting it wrong in that direction lets a modification through without
	 * confirmation.
	 *
	 * Three things are therefore required: a read opening word, no write verb
	 * anywhere else in the query, and no inner semicolon.
	 *
	 * The direction of the error is deliberate: classifying a read as a write
	 * only costs one more confirmation, the opposite costs a table.
	 */
	private function is_read_query( string $normalized ): bool {
		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $normalized ) ) {
			return false;
		}

		// `SHOW CREATE TABLE` stays a read: CREATE and DROP are not in this
		// list, and they cannot start a query classified as a read anyway.
		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|RENAME|INTO\s+(OUTFILE|DUMPFILE)|LOAD\s+DATA)\b/i', $normalized ) ) {
			return false;
		}

		// A semicolon anywhere but at the end of the query announces a second
		// statement, which this test has not examined.
		if ( preg_match( '/;\s*\S/', $normalized ) ) {
			return false;
		}

		return true;
	}

	public function ajax_run_query(): void {
		$this->guard();

		global $wpdb;
		$sql = isset( $_POST['sql'] ) ? trim( (string) wp_unslash( $_POST['sql'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by guard() at the top of the handler; SQL typed by the administrator, filtered by find_forbidden_keyword() and confirmed client-side for any write.
		if ( '' === $sql ) {
			wp_send_json_error( [ 'message' => __( 'Empty query.', 'lumia-tools' ) ] );
		}

		// Guard 1: forbidden operations (database/user management, server shutdown…).
		$forbidden = $this->find_forbidden_keyword( $sql );
		if ( null !== $forbidden ) {
			/* translators: %s: forbidden SQL keyword. */
			wp_send_json_error( [ 'message' => sprintf( __( 'Operation not allowed in this editor: %s.', 'lumia-tools' ), $forbidden ) ] );
		}

		// Detect whether it is a read query. The test applies to the normalized
		// form: otherwise a comment was enough to disguise a write.
		$normalized = $this->normalize_sql( $sql );
		$is_select  = $this->is_read_query( $normalized );

		// Guard 2: any write query requires an explicit confirmation from the client.
		if ( ! $is_select && empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard() at the top of the handler.
			wp_send_json_error(
				[
					'message'       => __( 'This query modifies the database. Confirmation required.', 'lumia-tools' ),
					'needs_confirm' => true,
				]
			);
		}

		$wpdb->flush();

		if ( $is_select ) {
			// Guard 3: memory bound: SELECTs without an explicit LIMIT are capped.
			$capped    = $sql;
			$truncated = false;
			$bare      = rtrim( $sql, "; \t\n\r" );
			// Only SELECT and WITH can return an unbounded volume. SHOW,
			// DESCRIBE and EXPLAIN return an already finite set, and do not
			// accept a LIMIT: the cap turned "SHOW CREATE TABLE x" into a
			// syntax error.
			//
			// The presence of a LIMIT is read on the normalized form: a
			// "/* LIMIT 1 */" comment used to bypass the cap.
			$cappable = (bool) preg_match( '/^\s*(SELECT|WITH)\b/i', $normalized );

			if ( $cappable && ! preg_match( '/\bLIMIT\b/i', $normalized ) ) {
				$capped    = $bare . ' LIMIT ' . self::QUERY_ROW_CAP;
				$truncated = true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$results = $wpdb->get_results( $capped, ARRAY_A );
			if ( $wpdb->last_error ) {
				wp_send_json_error( [ 'message' => $wpdb->last_error ] );
			}
			$count = count( (array) $results );
			wp_send_json_success(
				[
					'type'      => 'select',
					'columns'   => ! empty( $results ) ? array_keys( $results[0] ) : [],
					'rows'      => $results,
					'total'     => $count,
					'truncated' => $truncated && $count >= self::QUERY_ROW_CAP ? self::QUERY_ROW_CAP : 0,
				]
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $this->friendly_db_error( $wpdb->last_error, __( 'Query failed.', 'lumia-tools' ) ) ] );
		}
		wp_send_json_success(
			[
				'type'      => 'write',
				'affected'  => $result,
				'insert_id' => $wpdb->insert_id,
			]
		);
	}

	public function ajax_export_sql(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_die( esc_html__( 'Invalid table.', 'lumia-tools' ) );
		}

		$filename = $table . '_' . gmdate( 'Y-m-d_His' ) . '.sql';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: ' . Admin::content_disposition( $filename ) );

		// Columns + typing: determines which values are numeric (unquoted) or binary (hex).
		$columns   = $this->get_columns_map( $table );
		$col_names = array_keys( $columns );
		$is_num    = [];
		$is_binary = [];
		foreach ( $columns as $field => $col ) {
			$type                = strtolower( $col['Type'] ?? '' );
			$is_num[ $field ]    = (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|dec|numeric|float|double|real|bit|year)\b/', $type );
			$is_binary[ $field ] = (bool) preg_match( '/(blob|binary)\b/', $type );
		}
		// Escaped column list for an explicit INSERT (re-importable even if the order/number changes).
		$col_list = '`' . implode( '`, `', array_map( 'esc_sql', $col_names ) ) . '`';

		// Table structure.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N );

		// Raw SQL output downloaded as application/octet-stream, never rendered
		// as HTML: HTML escaping would corrupt the dump. The table comes from
		// read_table() (SHOW TABLES allowlist), the values from esc_sql().
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "-- Lümia Tools - Export SQL\n";
		echo '-- Table: ' . $table . "\n";
		echo '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		echo "SET NAMES utf8mb4;\n";
		echo 'DROP TABLE IF EXISTS `' . $table . "`;\n";
		echo $create[1] . ";\n\n";

		// Data in batches of 500.
		$offset = 0;
		$batch  = 500;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` LIMIT ' . $batch . ' OFFSET ' . $offset, ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$values = [];
				foreach ( $row as $field => $v ) {
					if ( null === $v ) {
						$values[] = 'NULL';
					} elseif ( ! empty( $is_binary[ $field ] ) ) {
						// Binary data: hexadecimal literal (0x…), always re-importable.
						$values[] = '0x' . bin2hex( $v );
					} elseif ( ! empty( $is_num[ $field ] ) && is_numeric( $v ) ) {
						$values[] = $v; // numeric: unquoted.
					} else {
						// esc_sql() replaces every "%" with a hash token meant for
						// $wpdb->prepare(): outside prepare() it must be removed, otherwise
						// the dump contains that token in place of the original "%".
						$values[] = "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $v ) ) . "'";
					}
				}
				echo 'INSERT INTO `' . $table . '` (' . $col_list . ') VALUES (' . implode( ', ', $values ) . ");\n";
			}
			$offset += $batch;
			$fetched = count( $rows );
		} while ( $fetched === $batch );
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}
}
