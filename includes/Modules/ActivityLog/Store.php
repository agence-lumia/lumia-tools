<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Table du journal d'activité : schéma, écriture, lecture filtrée, purge.
 *
 * Table dédiée plutôt que postmeta ou option : un journal grossit sans cesse,
 * se filtre par date et par utilisateur, et se purge par lots — trois choses
 * qu'une option sérialisée ou un type de contenu font mal.
 *
 * Le nom de table est interpolé dans le SQL : `%i` (identifiant) n'existe dans
 * wpdb::prepare() que depuis WordPress 6.2, l'extension en supporte 6.0. Le
 * nom ne vient jamais d'une requête, seulement de `$wpdb->prefix`.
 */
class Store {

	/** Nom de table, sans préfixe. */
	const TABLE = 'lumia_activity_log';

	/** Version du schéma ; toute modification de CREATE TABLE l'incrémente. */
	const SCHEMA_VERSION = '1';

	/** Option mémorisant la version de schéma installée. */
	const SCHEMA_OPTION = 'lumia_activity_log_schema';

	/**
	 * Lignes supprimées par requête lors d'une purge : un DELETE de cent mille
	 * lignes d'un bloc verrouille la table le temps de l'opération, et chaque
	 * écriture du site attend derrière.
	 */
	const PURGE_BATCH = 5000;

	/** Plafond de lots par purge, pour qu'un cron ne tourne jamais sans fin. */
	const PURGE_MAX_BATCHES = 200;

	/** Colonnes renvoyées par la recherche plein texte. */
	const SEARCH_COLUMNS = [ 'object_label', 'user_login', 'ip' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Crée ou met à jour la table si le schéma installé n'est pas le bon.
	 *
	 * Appelé à chaque chargement du module : le test ne coûte qu'une lecture
	 * d'option autochargée. L'activation seule ne suffit pas : un module activé
	 * par import de configuration n'y passe pas. Une table supprimée à la main
	 * (onglet Base de données) garde l'option à jour : ce cas-là est rattrapé
	 * par insert(), au premier échec d'écriture.
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

		// dbDelta() est pointilleux : deux espaces après PRIMARY KEY, un champ
		// par ligne, pas de guillemets inversés autour des noms de colonnes.
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			user_role varchar(64) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			event_group varchar(20) NOT NULL DEFAULT '',
			event varchar(40) NOT NULL DEFAULT '',
			object_type varchar(20) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_label varchar(255) NOT NULL DEFAULT '',
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY event_group (event_group)
			) {$charset};"
		);

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Insère une ligne. Les valeurs sont tronquées à la largeur des colonnes :
	 * en mode SQL strict, un titre de 300 caractères faisait échouer l'INSERT
	 * entier, et l'événement était perdu.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function insert( array $row ): void {
		global $wpdb;

		$details = $row['details'] ?? [];
		$data    = [
			'created_at'   => current_time( 'mysql', true ),
			'user_id'      => absint( $row['user_id'] ?? 0 ),
			'user_login'   => self::cut( (string) ( $row['user_login'] ?? '' ), 60 ),
			'user_role'    => self::cut( (string) ( $row['user_role'] ?? '' ), 64 ),
			'ip'           => self::cut( (string) ( $row['ip'] ?? '' ), 45 ),
			'event_group'  => self::cut( (string) ( $row['event_group'] ?? '' ), 20 ),
			'event'        => self::cut( (string) ( $row['event'] ?? '' ), 40 ),
			'object_type'  => self::cut( (string) ( $row['object_type'] ?? '' ), 20 ),
			'object_id'    => absint( $row['object_id'] ?? 0 ),
			'object_label' => self::cut( (string) ( $row['object_label'] ?? '' ), 255 ),
			'details'      => empty( $details ) ? null : wp_json_encode( $details ),
		];
		$format  = [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au module, pas d'API WordPress pour l'écrire.
		if ( false !== $wpdb->insert( self::table(), $data, $format ) ) {
			return;
		}

		// Table supprimée depuis l'onglet Base de données : l'option de schéma
		// dit encore « installée », maybe_install() ne la recrée donc pas, et
		// chaque écriture échouait en silence — journal muet jusqu'à une
		// réactivation. Tester l'existence de la table à chaque requête
		// coûterait une requête SQL par page ; on ne paie qu'en cas d'échec.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture de schéma, pas de cache pertinent.
		if ( self::table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) ) ) {
			self::install();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- voir plus haut.
			$wpdb->insert( self::table(), $data, $format );
		}
	}

	/**
	 * Lignes filtrées, les plus récentes d'abord.
	 *
	 * @param array<string, mixed> $filters Voir where().
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$table            = self::table();
		$args[]           = $limit;
		$args[]           = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- nom de table interne ; la clause WHERE n'assemble que des marqueurs de where(), dont les valeurs arrivent dans $args avec LIMIT et OFFSET.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args ), ARRAY_A );

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
	 * Utilisateurs présents dans le journal, pour le filtre de la liste.
	 *
	 * Lus dans la table et non dans wp_users : un compte supprimé doit rester
	 * filtrable, c'est souvent lui qu'on cherche.
	 *
	 * @return array<int, array{user_id: int, user_login: string}>
	 */
	public static function users(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne, aucune valeur externe.
		$rows = $wpdb->get_results( "SELECT user_id, MAX(user_login) AS user_login FROM {$table} WHERE user_id > 0 GROUP BY user_id ORDER BY user_login LIMIT 500", ARRAY_A );

		$users = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$users[] = [
				'user_id'    => (int) $row['user_id'],
				'user_login' => (string) $row['user_login'],
			];
		}

		return $users;
	}

	/**
	 * Supprime les lignes plus anciennes que `$days` jours, puis tout ce qui
	 * dépasse les `$max_rows` plus récentes.
	 *
	 * @return int Lignes supprimées.
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
			// MySQL refuse LIMIT dans une sous-requête sur la table qu'on
			// modifie : on lit d'abord l'identifiant de la plus récente ligne
			// en trop, puis on supprime tout ce qui est plus ancien.
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
	 * Clause WHERE et ses valeurs à partir de filtres DÉJÀ assainis par le
	 * module (entiers, clés, dates `Y-m-d`) : ici on ne fait que les placer
	 * derrière des marqueurs.
	 *
	 * @param array<string, mixed> $filters before_id, user_id, group, event, from, to (Y-m-d, heure du site), search.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function where( array $filters ): array {
		global $wpdb;

		$clauses = [ '1=1' ];
		$args    = [];

		// Pagination par clé pour l'export : un OFFSET se décale si des lignes
		// arrivent pendant qu'on lit.
		if ( ! empty( $filters['before_id'] ) ) {
			$clauses[] = 'id < %d';
			$args[]    = (int) $filters['before_id'];
		}

		if ( ! empty( $filters['user_id'] ) ) {
			$clauses[] = 'user_id = %d';
			$args[]    = (int) $filters['user_id'];
		}

		if ( ! empty( $filters['group'] ) ) {
			$clauses[] = 'event_group = %s';
			$args[]    = (string) $filters['group'];
		}

		if ( ! empty( $filters['event'] ) ) {
			$clauses[] = 'event = %s';
			$args[]    = (string) $filters['event'];
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

	/**
	 * Rejoue un DELETE … LIMIT jusqu'à ce qu'il ne supprime plus rien.
	 */
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

	/**
	 * Tronque en caractères, pas en octets : la colonne est en utf8mb4.
	 */
	private static function cut( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}
}
