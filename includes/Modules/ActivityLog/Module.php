<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Activity Log module: who changed what, and when.
 */
class Module extends AbstractModule {

	/** Daily purge cron hook. */
	const CRON_HOOK = 'lumia_activity_log_purge';

	/** Rows per page in the list. */
	const PER_PAGE = 50;

	/** Rows read per query during the CSV export. */
	const EXPORT_CHUNK = 1000;

	/** Bounds of the retention settings. */
	const DAYS_MIN = 1;
	const DAYS_MAX = 3650;
	const ROWS_MIN = 100;
	const ROWS_MAX = 1000000;

	public function init(): void {
		Store::maybe_install();

		( new Recorder( $this->get_module_settings( self::get_defaults() ) ) )->register();

		add_action( self::CRON_HOOK, [ $this, 'purge' ] );

		// Scheduled here and not only on activation: a module enabled by a
		// configuration import does not call on_activate().
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		add_action( 'wp_ajax_lumia_activity_log_list', [ $this, 'ajax_list' ] );
		add_action( 'admin_post_lumia_activity_log_export', [ $this, 'handle_export' ] );
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( self::get_defaults() );
	}

	/**
	 * The form posts the TRACKED groups and roles (checked boxes); we store the
	 * exclusions. A group added by a later version is thus logged by default,
	 * instead of arriving excluded because it is missing from a list saved
	 * before it existed.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$tracked_groups = array_map( 'sanitize_key', (array) ( $settings['tracked_groups'] ?? [] ) );
		$tracked_roles  = array_map( 'sanitize_key', (array) ( $settings['tracked_roles'] ?? [] ) );

		$sanitized = [
			'retention_days'  => min( self::DAYS_MAX, max( self::DAYS_MIN, absint( $settings['retention_days'] ?? 90 ) ) ),
			'max_rows'        => min( self::ROWS_MAX, max( self::ROWS_MIN, absint( $settings['max_rows'] ?? 10000 ) ) ),
			'anonymize_ip'    => ! empty( $settings['anonymize_ip'] ),
			'excluded_groups' => array_values( array_diff( array_keys( Events::groups() ), $tracked_groups ) ),
			'excluded_roles'  => array_values( array_diff( array_keys( wp_roles()->get_names() ), $tracked_roles ) ),
		];

		return $this->save_module_settings( $sanitized );
	}

	/**
	 * Storage deals in exclusions, the form in checked boxes: the configuration
	 * import must go back through the form shape.
	 *
	 * @param array<string, mixed> $stored
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		$payload = $stored;

		$payload['tracked_groups'] = array_values( array_diff( array_keys( Events::groups() ), (array) ( $stored['excluded_groups'] ?? [] ) ) );
		$payload['tracked_roles']  = array_values( array_diff( array_keys( wp_roles()->get_names() ), (array) ( $stored['excluded_roles'] ?? [] ) ) );
		unset( $payload['excluded_groups'], $payload['excluded_roles'] );

		return $payload;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'retention_days'  => 90,
			'max_rows'        => 10000,
			'anonymize_ip'    => false,
			'excluded_groups' => [],
			'excluded_roles'  => [],
		];
	}

	/**
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [ 'lumia_module_activity_log', Store::SCHEMA_OPTION ],
			'tables'  => [ Store::TABLE ],
			'cron'    => [ self::CRON_HOOK ],
		];
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	public function on_activate(): void {
		Store::install();
	}

	/**
	 * The table stays: disabling the module pauses the log, it must not erase
	 * the history. Only uninstalling deletes it.
	 */
	public function on_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Daily purge: by age, then by volume.
	 */
	public function purge(): void {
		$settings = $this->get_module_settings( self::get_defaults() );

		Store::purge( (int) $settings['retention_days'], (int) $settings['max_rows'] );
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/activity-log.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/activity-log.js' ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'alExportNonce' => wp_create_nonce( 'lumia_activity_log_export' ),
			'alExportUrl'   => admin_url( 'admin-post.php' ),
			'i18n'          => [
				'alLoading' => __( 'Loading…', 'lumia-tools' ),
				'alEmpty'   => __( 'No events match these criteria.', 'lumia-tools' ),
				'alError'   => __( 'Unable to load the log.', 'lumia-tools' ),
				/* translators: %s: number of events. */
				'alTotal'   => __( '%s event(s)', 'lumia-tools' ),
				/* translators: 1: current page, 2: number of pages. */
				'alPage'    => __( 'Page %1$s of %2$s', 'lumia-tools' ),
				'alDate'    => __( 'Date', 'lumia-tools' ),
				'alUser'    => __( 'User', 'lumia-tools' ),
				'alRole'    => __( 'Role', 'lumia-tools' ),
				'alIp'      => __( 'IP address', 'lumia-tools' ),
				'alEvent'   => __( 'Event', 'lumia-tools' ),
				'alObject'  => __( 'Object', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * LIST (AJAX)
	 * ================================================================ */

	public function ajax_list(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ], 403 );
		}

		$filters = $this->read_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top; read_filters() sanitizes every field.
		$page    = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified at the top.
		$total   = Store::count( $filters );
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page    = min( $page, $pages );

		$rows = array_map( [ $this, 'present' ], Store::query( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) );

		wp_send_json_success(
			[
				'rows'  => $rows,
				'total' => $total,
				'page'  => $page,
				'pages' => $pages,
			]
		);
	}

	/* ================================================================
	 * CSV EXPORT
	 * ================================================================ */

	/**
	 * Exports the rows matching the list filters.
	 */
	public function handle_export(): void {
		check_admin_referer( 'lumia_activity_log_export', 'lumia_nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ), '', [ 'response' => 403 ] );
		}

		$filters = $this->read_filters( $_POST );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		/* translators: %s: export date and time (Y-m-d-His). */
		$filename = sprintf( __( 'activity-log-%s.csv', 'lumia-tools' ), wp_date( 'Y-m-d-His' ) );
		header( 'Content-Disposition: ' . Admin::content_disposition( $filename ) );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// BOM: without it, Excel opens the file as Windows-1252 and breaks accented characters.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- HTTP output stream, not a file.

		// Empty escape character (RFC 4180, doubled quotes): the default `\` of
		// fputcsv() is deprecated since PHP 8.4 and produces CSVs that
		// spreadsheets misread when a value ends with a backslash.
		fputcsv(
			$out,
			[
				__( 'Date', 'lumia-tools' ),
				__( 'User', 'lumia-tools' ),
				__( 'Role', 'lumia-tools' ),
				__( 'IP address', 'lumia-tools' ),
				__( 'Group', 'lumia-tools' ),
				__( 'Event', 'lumia-tools' ),
				__( 'Object', 'lumia-tools' ),
				__( 'Object ID', 'lumia-tools' ),
				__( 'Details', 'lumia-tools' ),
			],
			';',
			'"',
			''
		);

		do {
			$rows = Store::query( $filters, self::EXPORT_CHUNK );

			foreach ( $rows as $row ) {
				$item = $this->present( $row );
				$line = array_map(
					static function ( array $pair ): string {
						return self::label_value( $pair[0], $pair[1] );
					},
					$item['details']
				);

				fputcsv(
					$out,
					array_map(
						[ self::class, 'csv_cell' ],
						[
							$item['date'],
							$item['user'],
							$item['role'],
							$item['ip'],
							$item['group'],
							$item['event'],
							$item['object'],
							$item['object_id'] ? (string) $item['object_id'] : '',
							implode( ' | ', $line ),
						]
					),
					';',
					'"',
					''
				);
			}

			$read = count( $rows );
			if ( $read ) {
				$filters['before_id'] = (int) end( $rows )['id'];
			}
		} while ( self::EXPORT_CHUNK === $read );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- HTTP output stream, not a file.
		exit;
	}

	/**
	 * Neutralizes formula injection: a post title starting with `=` is run as
	 * a formula by Excel or LibreOffice when the CSV is opened. Titles,
	 * attempted login names and emails are typed by anyone.
	 */
	private static function csv_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ================================================================
	 * PRESENTATION
	 * ================================================================ */

	/**
	 * List filters, sanitized. Source: `$_POST` of a request whose nonce has
	 * already been verified by the caller.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	private function read_filters( array $source ): array {
		$source = wp_unslash( $source );
		$date   = static function ( $value ): string {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};

		$group = sanitize_key( (string) ( $source['group'] ?? '' ) );
		$event = sanitize_key( (string) ( $source['event'] ?? '' ) );

		return [
			'user_id' => absint( $source['user_id'] ?? 0 ),
			'group'   => isset( Events::groups()[ $group ] ) ? $group : '',
			'event'   => isset( Events::all()[ $event ] ) ? $event : '',
			'from'    => $date( $source['from'] ?? '' ),
			'to'      => $date( $source['to'] ?? '' ),
			'search'  => mb_substr( sanitize_text_field( (string) ( $source['search'] ?? '' ) ), 0, 100 ),
		];
	}

	/**
	 * Table row => displayable values (list and CSV).
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present( array $row ): array {
		$details = json_decode( (string) ( $row['details'] ?? '' ), true );
		$details = is_array( $details ) ? $details : [];
		$event   = (string) $row['event'];
		$login   = (string) $row['user_login'];

		if ( '' === $login ) {
			// Failed login: nobody is logged in, the attempted login name is
			// the object of the event.
			$user = 'login_failed' === $event ? __( 'Anonymous', 'lumia-tools' ) : __( 'System', 'lumia-tools' );
		} else {
			$user = $login;
		}

		$groups = Events::groups();

		return [
			'id'        => (int) $row['id'],
			'date'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ),
			'user'      => $user,
			'user_id'   => (int) $row['user_id'],
			'role'      => $this->role_name( (string) $row['user_role'] ),
			'ip'        => (string) $row['ip'],
			'group_key' => (string) $row['event_group'],
			'group'     => $groups[ $row['event_group'] ] ?? (string) $row['event_group'],
			'event_key' => $event,
			'event'     => Events::label( $event ),
			'object'    => $this->object_label( $row ),
			'object_id' => (int) $row['object_id'],
			'link'      => $this->object_link( (string) $row['object_type'], (int) $row['object_id'] ),
			'details'   => $this->detail_lines( $details ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function object_label( array $row ): string {
		$label = (string) $row['object_label'];

		if ( 'lumia' === $row['object_type'] ) {
			if ( 'global' === $label ) {
				return __( 'General settings', 'lumia-tools' );
			}
			$module = \Lumia\Tools\Core\Plugin::instance()->modules->get( $label );
			return is_array( $module ) && ! empty( $module['name'] ) ? (string) $module['name'] : $label;
		}

		return $label;
	}

	/**
	 * "Label: value" pair. The separator belongs to the translation: French
	 * puts a space before the colon.
	 */
	private static function label_value( string $label, string $value ): string {
		/* translators: 1: label, 2: value. */
		return sprintf( __( '%1$s: %2$s', 'lumia-tools' ), $label, $value );
	}

	/**
	 * Edit link of the object if it still exists and the current user can
	 * open it; '' otherwise.
	 */
	private function object_link( string $type, int $id ): string {
		if ( ! $id ) {
			return '';
		}

		if ( in_array( $type, [ 'post', 'attachment' ], true ) && get_post( $id ) ) {
			return (string) get_edit_post_link( $id, 'raw' );
		}

		if ( 'user' === $type && get_userdata( $id ) && current_user_can( 'edit_user', $id ) ) {
			return get_edit_user_link( $id );
		}

		return '';
	}

	private function role_name( string $role ): string {
		if ( '' === $role ) {
			return '';
		}

		$names = wp_roles()->get_names();

		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
	}

	/**
	 * Detail of an event as "label / value" lines, ready to display.
	 *
	 * @param array<string, mixed> $details
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function detail_lines( array $details ): array {
		$labels = [
			'title'        => __( 'Title', 'lumia-tools' ),
			'status'       => __( 'Status', 'lumia-tools' ),
			'slug'         => __( 'Slug', 'lumia-tools' ),
			'parent'       => __( 'Parent', 'lumia-tools' ),
			'date'         => __( 'Publication date', 'lumia-tools' ),
			'order'        => __( 'Order', 'lumia-tools' ),
			'comments'     => __( 'Comments', 'lumia-tools' ),
			'author'       => __( 'Author', 'lumia-tools' ),
			'content'      => _x( 'Content', 'post content field', 'lumia-tools' ),
			'excerpt'      => __( 'Excerpt', 'lumia-tools' ),
			'password'     => __( 'Password', 'lumia-tools' ),
			'builder'      => __( 'Page builder', 'lumia-tools' ),
			'alt'          => __( 'Alt text', 'lumia-tools' ),
			'email'        => __( 'Email', 'lumia-tools' ),
			'display_name' => __( 'Display name', 'lumia-tools' ),
			'url'          => __( 'Website', 'lumia-tools' ),
			'roles'        => __( 'Roles', 'lumia-tools' ),
			'reassign'     => __( 'Content reassigned to', 'lumia-tools' ),
			'file'         => __( 'File', 'lumia-tools' ),
			'stylesheet'   => __( 'Folder', 'lumia-tools' ),
			'version'      => __( 'Version', 'lumia-tools' ),
			'network'      => __( 'Network-wide', 'lumia-tools' ),
			'mime'         => __( 'File type', 'lumia-tools' ),
			'label'        => __( 'Setting', 'lumia-tools' ),
			'from'         => __( 'Before', 'lumia-tools' ),
			'to'           => __( 'After', 'lumia-tools' ),
			'paths'        => __( 'Changed fields', 'lumia-tools' ),
			'modules'      => __( 'Modules', 'lumia-tools' ),
			'via'          => __( 'Source', 'lumia-tools' ),
		];

		$channels = [
			'web'    => __( 'Web interface', 'lumia-tools' ),
			'ajax'   => __( 'Web interface (AJAX)', 'lumia-tools' ),
			'rest'   => __( 'REST API / block editor', 'lumia-tools' ),
			'cron'   => __( 'Scheduled task', 'lumia-tools' ),
			'cli'    => __( 'WP-CLI', 'lumia-tools' ),
			'xmlrpc' => __( 'XML-RPC', 'lumia-tools' ),
		];

		$lines = [];

		foreach ( $details as $key => $value ) {
			$key = (string) $key;

			if ( 'changes' === $key && is_array( $value ) ) {
				foreach ( $value as $field => $change ) {
					if ( 'status' === $field && is_array( $change ) ) {
						$change = array_map( [ $this, 'status_label' ], $change );
					}
					$lines[] = [ $labels[ $field ] ?? (string) $field, $this->change_text( $change ) ];
				}
				continue;
			}

			if ( 'capped' === $key ) {
				$lines[] = [
					__( 'Limit reached', 'lumia-tools' ),
					/* translators: %d: number of failed attempts logged per hour. */
					sprintf( __( 'Further failed attempts from this IP are not logged for one hour (at most %d per hour).', 'lumia-tools' ), (int) $value ),
				];
				continue;
			}

			if ( 'status' === $key ) {
				$value = $this->status_label( $value );
			} elseif ( 'via' === $key ) {
				$value = $channels[ $value ] ?? (string) $value;
			} elseif ( 'modules' === $key && is_array( $value ) ) {
				$parts = [];
				foreach ( $value as $module => $active ) {
					$parts[] = self::label_value(
						$this->object_label(
							[
								'object_type'  => 'lumia',
								'object_label' => (string) $module,
							]
						),
						$active ? __( 'enabled', 'lumia-tools' ) : __( 'disabled', 'lumia-tools' )
					);
				}
				$value = implode( ', ', $parts );
			}

			$lines[] = [ $labels[ $key ] ?? $key, $this->scalar_text( $value ) ];
		}

		return $lines;
	}

	/**
	 * Translated label of a content status ("Published" rather than `publish`).
	 *
	 * @param mixed $status
	 */
	private function status_label( $status ): string {
		$object = get_post_status_object( (string) $status );

		return $object && is_string( $object->label ) ? $object->label : (string) $status;
	}

	/**
	 * @param mixed $change `true` (field changed, value not kept) or {from, to}.
	 */
	private function change_text( $change ): string {
		if ( is_array( $change ) && array_key_exists( 'from', $change ) ) {
			return $this->scalar_text( $change['from'] ) . ' → ' . $this->scalar_text( $change['to'] ?? '' );
		}

		if ( true === $change ) {
			return __( 'changed', 'lumia-tools' );
		}

		return $this->scalar_text( $change );
	}

	/**
	 * @param mixed $value
	 */
	private function scalar_text( $value ): string {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( [ $this, 'scalar_text' ], $value ) );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'yes', 'lumia-tools' ) : __( 'no', 'lumia-tools' );
		}

		$value = (string) $value;

		return '' === $value ? '∅' : $value;
	}
}
