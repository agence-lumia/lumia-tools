<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Table du journal des mails : schéma, écriture, lecture filtrée, purge.
 *
 * Même conception que le journal d'activité (voir ActivityLog\Store) : table
 * dédiée, nom interpolé (pas de `%i` avant WP 6.2), installation vérifiée à
 * chaque chargement, purge par lots.
 */
class Store {

	/** Nom de table, sans préfixe. */
	const TABLE = 'lumia_mail_log';

	/** Version du schéma ; toute modification de CREATE TABLE l'incrémente. */
	const SCHEMA_VERSION = '1';

	/** Option mémorisant la version de schéma installée. */
	const SCHEMA_OPTION = 'lumia_mail_log_schema';

	/**
	 * Corps de message conservé au plus (octets). Un mail avec images inline
	 * en base64 pèse plusieurs mégaoctets ; au-delà, le corps est tronqué et
	 * le renvoi désactivé — renvoyer un message amputé serait pire que rien.
	 */
	const MAX_MESSAGE_BYTES = 524288;

	const PURGE_BATCH       = 2000;
	const PURGE_MAX_BATCHES = 200;

	/** Statuts possibles. */
	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';

	/** Colonnes de la liste : le corps et les en-têtes ne partent qu'avec le détail. */
	const LIST_COLUMNS = 'id, created_at, status, transport, from_address, to_address, subject, error, resent_of';

	const SEARCH_COLUMNS = [ 'subject', 'to_address', 'from_address' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crée ou met à jour la table si le schéma installé n'est pas le bon.
	 * Une lecture d'option autochargée par requête.
	 */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION ) ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() : deux espaces après PRIMARY KEY, un champ par ligne.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			status varchar(10) NOT NULL DEFAULT '',
			transport varchar(20) NOT NULL DEFAULT '',
			from_address varchar(255) NOT NULL DEFAULT '',
			to_address text NULL,
			subject text NULL,
			message longtext NULL,
			content_type varchar(40) NOT NULL DEFAULT '',
			headers text NULL,
			attachments text NULL,
			error text NULL,
			truncated tinyint(1) unsigned NOT NULL DEFAULT 0,
			resent_of bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
			) {$charset};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): void {
		global $wpdb;

		$message   = (string) ( $row['message'] ?? '' );
		$truncated = strlen( $message ) > self::MAX_MESSAGE_BYTES;

		if ( $truncated ) {
			// mb_strcut() coupe en octets sans casser un caractère UTF-8.
			$message = mb_strcut( $message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8' );
		}

		$data   = [
			'created_at'   => current_time( 'mysql', true ),
			'status'       => self::STATUS_SENT === ( $row['status'] ?? '' ) ? self::STATUS_SENT : self::STATUS_FAILED,
			'transport'    => mb_substr( (string) ( $row['transport'] ?? '' ), 0, 20 ),
			'from_address' => mb_substr( (string) ( $row['from_address'] ?? '' ), 0, 255 ),
			'to_address'   => mb_substr( (string) ( $row['to_address'] ?? '' ), 0, 20000 ),
			'subject'      => mb_substr( (string) ( $row['subject'] ?? '' ), 0, 2000 ),
			'message'      => $message,
			'content_type' => mb_substr( (string) ( $row['content_type'] ?? '' ), 0, 40 ),
			'headers'      => wp_json_encode( array_values( (array) ( $row['headers'] ?? [] ) ) ),
			'attachments'  => wp_json_encode( array_values( (array) ( $row['attachments'] ?? [] ) ) ),
			'error'        => mb_substr( (string) ( $row['error'] ?? '' ), 0, 5000 ),
			'truncated'    => $truncated ? 1 : 0,
			'resent_of'    => absint( $row['resent_of'] ?? 0 ),
		];
		$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au module.
		if ( false !== $wpdb->insert( self::table(), $data, $format ) ) {
			return;
		}

		// Table supprimée à la main alors que l'option de schéma dit
		// « installée » : on ne paie la vérification qu'en cas d'échec (voir
		// ActivityLog\Store::insert()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture de schéma.
		if ( self::table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ) ) {
			self::install();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- voir plus haut.
			$wpdb->insert( self::table(), $data, $format );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lignes filtrées, les plus récentes d'abord, sans corps ni en-têtes.
	 *
	 * @param array<string, mixed> $filters Voir where().
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$columns          = self::LIST_COLUMNS;
		$args[]           = $limit;
		$args[]           = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- nom de table et colonnes internes ; la clause WHERE n'assemble que des marqueurs de where().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	public static function count( array $filters ): int {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$sql              = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql n'assemble que des marqueurs de where().
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- préparée juste au-dessus quand elle porte des valeurs.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Vide le journal. TRUNCATE remet aussi l'auto-incrément à zéro, sans
	 * verrouiller ligne par ligne.
	 */
	public static function clear(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Supprime les lignes plus anciennes que `$days` jours, puis tout ce qui
	 * dépasse les `$max_rows` plus récentes.
	 */
	public static function purge( int $days, int $max_rows ): int {
		global $wpdb;

		$table   = self::table();
		$deleted = 0;

		if ( $days > 0 ) {
			$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$deleted += self::delete_in_batches(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT %d", $cutoff, self::PURGE_BATCH )
			);
		}

		if ( $max_rows > 0 ) {
			// MySQL refuse LIMIT dans une sous-requête sur la table modifiée.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
			$threshold = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max_rows ) );

			if ( $threshold > 0 ) {
				$deleted += self::delete_in_batches(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
					$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d LIMIT %d", $threshold, self::PURGE_BATCH )
				);
			}
		}

		return $deleted;
	}

	/**
	 * @param array<string, mixed> $filters status, from, to (Y-m-d, heure du site), search — déjà assainis.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$clauses = [ '1=1' ];
		$args    = [];

		if ( ! empty( $filters['status'] ) ) {
			$clauses[] = 'status = %s';
			$args[]    = (string) $filters['status'];
		}

		// Les dates saisies sont celles du site ; la table est en UTC.
		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = get_gmt_from_date( $filters['from'] . ' 00:00:00' );
		}

		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = get_gmt_from_date( $filters['to'] . ' 23:59:59' );
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$like  = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$parts = [];
			foreach ( self::SEARCH_COLUMNS as $column ) {
				$parts[] = "{$column} LIKE %s";
				$args[]  = $like;
			}
			$clauses[] = '(' . implode( ' OR ', $parts ) . ')';
		}

		return [ implode( ' AND ', $clauses ), $args ];
	}

	private static function delete_in_batches( string $sql ): int {
		global $wpdb;

		$total = 0;

		for ( $i = 0; $i < self::PURGE_MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- préparée par l'appelant.
			$affected = (int) $wpdb->query( $sql );
			$total   += $affected;

			if ( $affected < self::PURGE_BATCH ) {
				break;
			}
		}

		return $total;
	}
}
