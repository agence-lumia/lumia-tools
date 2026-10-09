<?php
namespace Lumia\Tools\Modules\Database;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Module Base de données — exploration, édition et export des tables WordPress.
 */
class Module extends AbstractModule {

	/** Nb de lignes maximum renvoyées par une requête SELECT libre sans LIMIT explicite. */
	const QUERY_ROW_CAP = 1000;

	/**
	 * Au-delà de cette estimation, on ne compte plus exactement dans la liste
	 * des tables : un COUNT(*) sur un postmeta ou un journal de plusieurs
	 * millions de lignes bloquait l'ouverture de l'onglet plusieurs secondes.
	 * Le compte exact arrive de toute façon quand la table est ouverte.
	 */
	const EXACT_COUNT_THRESHOLD = 100000;

	/** Mots-clés interdits dans l'éditeur SQL libre (opérations hors périmètre / destructrices au niveau serveur). */
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
		// Écriture / lecture de fichiers depuis le serveur MySQL. INTO OUTFILE
		// se cache derrière un SELECT — c'est-à-dire derrière la classification
		// « lecture » — et écrit pourtant sur le disque du serveur de bases.
		'INTO OUTFILE',
		'INTO DUMPFILE',
		'LOAD DATA',
		'LOAD_FILE',
		// Instructions qui touchent le SERVEUR MySQL, pas ce site : variables
		// globales (general_log_file écrit où on veut), chargement de code
		// (UDF via SONAME, plugins), arrêt de connexions.
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
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n' => [
				'confirmDelete'     => __( 'Supprimer cette ligne ?', 'lumia-tools' ),
				'confirmTruncate'   => __( 'Vider la table ? Cette action est irréversible.', 'lumia-tools' ),
				'queryWarning'      => __( 'Attention : les requêtes de modification (UPDATE, DELETE, DROP…) s\'exécutent directement sur la base de données. Aucun undo possible.', 'lumia-tools' ),
				'confirmWrite'      => __( 'Cette requête modifie la base de données et est irréversible. Confirmer l\'exécution ?', 'lumia-tools' ),
				// Actions génériques
				'confirm'           => __( 'Confirmer', 'lumia-tools' ),
				'cancel'            => __( 'Annuler', 'lumia-tools' ),
				'delete'            => __( 'Supprimer', 'lumia-tools' ),
				'execute'           => __( 'Exécuter', 'lumia-tools' ),
				// États / feedback
				'loading'           => __( 'Chargement…', 'lumia-tools' ),
				'executing'         => __( 'Exécution…', 'lumia-tools' ),
				'inserting'         => __( 'Insertion…', 'lumia-tools' ),
				'rowAdded'          => __( 'Ligne ajoutée', 'lumia-tools' ),
				'rowUpdated'        => __( 'Ligne mise à jour', 'lumia-tools' ),
				'rowDeleted'        => __( 'Ligne supprimée', 'lumia-tools' ),
				'tableTruncated'    => __( 'Table vidée', 'lumia-tools' ),
				'tableDropped'      => __( 'Table supprimée', 'lumia-tools' ),
				'error'             => __( 'Erreur', 'lumia-tools' ),
				'networkError'      => __( 'Erreur réseau', 'lumia-tools' ),
				// Libellés de tableau / recherche
				'noTables'          => __( 'Aucune table trouvée.', 'lumia-tools' ),
				'noRows'            => __( 'Aucune ligne.', 'lumia-tools' ),
				'noColumn'          => __( 'Aucune colonne.', 'lumia-tools' ),
				'noHistory'         => __( 'Aucun historique.', 'lumia-tools' ),
				'clearHistory'      => __( 'Vider l\'historique', 'lumia-tools' ),
				'searchInTable'     => __( 'Rechercher dans la table…', 'lumia-tools' ),
				'rowsLabel'         => __( 'lignes', 'lumia-tools' ),
				'perPageLabel'      => __( 'Lignes / page', 'lumia-tools' ),
				'setNull'           => __( 'Définir NULL', 'lumia-tools' ),
				// Nettoyage
				'cleanupTitle'      => __( 'Nettoyage', 'lumia-tools' ),
				'cleanupIntro'      => __( 'Chaque élément est d\'abord compté ; rien n\'est supprimé sans votre confirmation. Faites une sauvegarde de la base avant un gros nettoyage.', 'lumia-tools' ),
				'cleanupItems'      => __( 'Données superflues', 'lumia-tools' ),
				'cleanupClean'      => __( 'Nettoyer', 'lumia-tools' ),
				'cleanupAll'        => __( 'Tout nettoyer', 'lumia-tools' ),
				'cleanupRescan'     => __( 'Recompter', 'lumia-tools' ),
				'cleanupRunning'    => __( 'Nettoyage…', 'lumia-tools' ),
				/* translators: 1: nombre d'éléments, 2: libellé de l'élément. */
				'cleanupConfirm'    => __( 'Supprimer définitivement %1$s élément(s) : %2$s ?', 'lumia-tools' ),
				/* translators: %s: nombre total d'éléments. */
				'cleanupConfirmAll' => __( 'Supprimer définitivement %s élément(s), toutes catégories confondues ?', 'lumia-tools' ),
				/* translators: 1: nombre d'éléments supprimés, 2: libellé de l'élément. */
				'cleanupDone'       => __( '%1$s élément(s) supprimé(s) : %2$s', 'lumia-tools' ),
				/* translators: %s: nombre total d'éléments supprimés. */
				'cleanupDoneTotal'  => __( '%s élément(s) supprimé(s)', 'lumia-tools' ),
				/* translators: %s: nombre d'éléments restants. */
				'cleanupLeft'       => __( '%s élément(s) n\'ont pas pu être supprimés.', 'lumia-tools' ),
				'optimizeTitle'     => __( 'Optimisation des tables', 'lumia-tools' ),
				/* translators: 1: nombre de tables, 2: taille récupérable. */
				'optimizeSummary'   => __( '%1$s table(s) fragmentée(s), %2$s récupérables.', 'lumia-tools' ),
				'optimizeNone'      => __( 'Aucune table fragmentée.', 'lumia-tools' ),
				'optimizeBtn'       => __( 'Optimiser', 'lumia-tools' ),
				'optimizeConfirm'   => __( 'OPTIMIZE TABLE reconstruit chaque table et peut la verrouiller quelques secondes. Lancer l\'optimisation ?', 'lumia-tools' ),
				/* translators: %s: nombre de tables optimisées. */
				'optimizeDone'      => __( '%s table(s) optimisée(s)', 'lumia-tools' ),
				'foreignTitle'      => __( 'Tables d\'extensions', 'lumia-tools' ),
				'foreignIntro'      => __( 'Tables hors cœur WordPress. L\'extension propriétaire est devinée d\'après le nom de la table : vérifiez avant de supprimer. Aucune table n\'est supprimée automatiquement.', 'lumia-tools' ),
				'foreignNone'       => __( 'Aucune table d\'extension.', 'lumia-tools' ),
				'foreignUnknown'    => __( 'Aucune extension correspondante', 'lumia-tools' ),
				/* translators: %s: nom(s) d'extension. */
				'foreignInactive'   => __( 'Extension inactive : %s', 'lumia-tools' ),
				/* translators: %s: nom(s) d'extension. */
				'foreignActive'     => __( 'Extension active : %s', 'lumia-tools' ),
				'open'              => __( 'Ouvrir', 'lumia-tools' ),
				/* translators: %d: nombre maximal de lignes affichées. */
				'queryTruncated'    => __( 'Résultat tronqué à %d lignes. Ajoutez une clause LIMIT pour cibler votre requête.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * SÉCURITÉ
	 * ================================================================ */

	/**
	 * Sous multisite, `manage_options` est une capacité par site, alors que la
	 * base est commune au réseau : un administrateur de sous-site obtiendrait
	 * ici l'édition SQL de tout le réseau. Voir AbstractModule.
	 */
	public static function get_required_capability(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}
		// Empêche $wpdb->print_error() d'ÉCHO du HTML d'erreur avant notre JSON
		// (sinon la réponse est corrompue → « Erreur réseau » côté JS au lieu du message SQL).
		global $wpdb;
		$wpdb->suppress_errors( true );
	}

	/* ================================================================
	 * AJAX — LISTE DES TABLES
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

			// `SHOW TABLE STATUS`.Rows est une estimation pour InnoDB (souvent 0 ou
			// très approximative). On la corrige par un COUNT(*) tant qu'elle reste
			// raisonnable ; au-delà du seuil on garde l'estimation et on le dit.
			// Le nom provient de SHOW TABLE STATUS, donc sûr à échapper en backticks.
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
	 * VALIDATION DES IDENTIFIANTS
	 * ================================================================ */

	/**
	 * Vérifie qu'une table existe dans la base courante. Retourne le nom validé
	 * (échappable en backticks) ou null.
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
	 * Lit et valide le paramètre `table` du POST. Utilise sanitize_text_field
	 * (et NON sanitize_key qui force en minuscules et casserait les noms de
	 * tables/colonnes sensibles à la casse selon le système de fichiers MySQL).
	 * La validation contre information_schema garantit que les backticks sont sûrs.
	 */
	private function read_table(): ?string {
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- appelé uniquement après guard().
		return $this->validate_table( $table );
	}

	/**
	 * Récupère les colonnes réelles d'une table indexées par nom (whitelist + typage).
	 * @return array<string, array<string, mixed>> Field => ligne SHOW COLUMNS.
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
	 * Détermine le placeholder $wpdb (%d/%f/%s) adapté au type SQL d'une colonne.
	 *
	 * @param array<string, mixed> $col Ligne SHOW COLUMNS.
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

	/** Traduit les erreurs MySQL courantes en messages lisibles (le message brut reste en repli). */
	private function friendly_db_error( string $raw, string $fallback ): string {
		if ( '' === $raw ) {
			return $fallback;
		}
		if ( stripos( $raw, 'Duplicate entry' ) !== false ) {
			return __( 'Cette valeur existe déjà (contrainte d\'unicité).', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'foreign key' ) !== false ) {
			return __( 'Contrainte de clé étrangère non respectée.', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'cannot be null' ) !== false || stripos( $raw, "doesn't have a default" ) !== false ) {
			return __( 'Un champ obligatoire est manquant.', 'lumia-tools' ) . ' — ' . $raw;
		}
		if ( stripos( $raw, 'Incorrect' ) !== false && stripos( $raw, 'value' ) !== false ) {
			return __( 'Valeur de type incorrect pour une colonne.', 'lumia-tools' ) . ' — ' . $raw;
		}
		return $raw;
	}

	public function ajax_get_rows(): void {
		$this->guard();

		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard() en tête de handler.
		$page      = max( 1, isset( $_POST['page'] ) ? (int) $_POST['page'] : 1 );
		$per_page  = min( 200, max( 10, isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50 ) );
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$order_col = isset( $_POST['order_col'] ) ? sanitize_text_field( wp_unslash( $_POST['order_col'] ) ) : '';
		$order_dir = strtoupper( isset( $_POST['order_dir'] ) ? sanitize_text_field( wp_unslash( $_POST['order_dir'] ) ) : 'ASC' ) === 'DESC' ? 'DESC' : 'ASC';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
		}

		// Colonnes — $table validé via information_schema, backticks sûrs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$columns   = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
		$col_names = array_column( $columns, 'Field' );
		$primary   = '';
		foreach ( $columns as $col ) {
			if ( 'PRI' === $col['Key'] ) {
				$primary = $col['Field'];
				break; }
		}

		// Recherche : WHERE sur toutes les colonnes de type texte (LIKE).
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
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par guard() en tête de handler ; valeur et clé primaire brutes, passées à $wpdb->update() qui échappe.
		$primary_col = isset( $_POST['primary_col'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_col'] ) ) : '';
		$primary_val = isset( $_POST['primary_val'] ) ? wp_unslash( $_POST['primary_val'] ) : '';
		$col         = isset( $_POST['col'] ) ? sanitize_text_field( wp_unslash( $_POST['col'] ) ) : '';
		$value       = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';
		$set_null    = ! empty( $_POST['set_null'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$table = $this->read_table();
		if ( null === $table || ! $primary_col || ! $col ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'lumia-tools' ) ] );
		}

		// Whitelist colonne + clé primaire contre les colonnes réelles de la table.
		$columns = $this->get_columns_map( $table );
		if ( ! isset( $columns[ $col ], $columns[ $primary_col ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Colonne inconnue.', 'lumia-tools' ) ] );
		}

		// NULL explicite → valeur null (interdit si la colonne n'accepte pas NULL).
		if ( $set_null ) {
			if ( 'YES' !== ( $columns[ $col ]['Null'] ?? 'NO' ) ) {
				wp_send_json_error( [ 'message' => __( 'Cette colonne n\'accepte pas la valeur NULL.', 'lumia-tools' ) ] );
			}
			$data    = [ $col => null ];
			$formats = null; // laisse $wpdb produire NULL.
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
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par guard() en tête de handler ; clé primaire passée à $wpdb->delete() qui échappe.
		$primary_col = isset( $_POST['primary_col'] ) ? sanitize_text_field( wp_unslash( $_POST['primary_col'] ) ) : '';
		$primary_val = isset( $_POST['primary_val'] ) ? wp_unslash( $_POST['primary_val'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$table = $this->read_table();
		if ( null === $table || ! $primary_col ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'lumia-tools' ) ] );
		}

		$columns = $this->get_columns_map( $table );
		if ( ! isset( $columns[ $primary_col ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Colonne inconnue.', 'lumia-tools' ) ] );
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
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
		}

		global $wpdb;

		// Champs soumis (col => valeur brute) + colonnes explicitement NULL.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par guard() en tête de handler ; valeurs brutes typées plus bas contre les colonnes réelles, écrites via $wpdb->insert() qui échappe.
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];
		$nulls  = isset( $_POST['nulls'] ) && is_array( $_POST['nulls'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['nulls'] ) ) : [];
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Colonnes réelles de la table (whitelist + typage).
		$columns = $this->get_columns_map( $table );

		$data    = [];
		$formats = [];
		foreach ( $columns as $field => $col ) {
			$extra = strtolower( $col['Extra'] ?? '' );

			// Colonne explicitement NULL → valeur null typée (refusée si NOT NULL sans défaut).
			if ( in_array( $field, $nulls, true ) ) {
				if ( 'YES' !== ( $col['Null'] ?? 'NO' ) ) {
					/* translators: %s: nom de la colonne. */
					wp_send_json_error( [ 'message' => sprintf( __( 'La colonne « %s » n\'accepte pas NULL.', 'lumia-tools' ), $field ) ] );
				}
				$data[ $field ] = null;
				$formats[]      = $this->column_format( $col );
				continue;
			}
			// Auto-increment laissé vide → délégué à MySQL.
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
			wp_send_json_error( [ 'message' => __( 'Aucune valeur à insérer.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert( $table, $data, $formats );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $this->friendly_db_error( $wpdb->last_error, __( 'Insertion échouée.', 'lumia-tools' ) ) ] );
		}
		wp_send_json_success( [ 'insert_id' => $wpdb->insert_id ] );
	}

	public function ajax_truncate_table(): void {
		$this->guard();

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
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
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( 'DROP TABLE `' . $table . '`' );
		if ( false === $result ) {
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	/* ================================================================
	 * AJAX — NETTOYAGE (endpoints typés, jamais via ajax_run_query)
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
	 * Purge un lot d'un élément. Le client rappelle tant que `deleted` et
	 * `remaining` sont non nuls.
	 */
	public function ajax_cleanup_run(): void {
		$this->guard();

		$item = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par guard().
		if ( ! array_key_exists( $item, Cleanup::items() ) ) {
			wp_send_json_error( [ 'message' => __( 'Élément inconnu.', 'lumia-tools' ) ] );
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
	 * Optimise UNE table du site : `OPTIMIZE TABLE` reconstruit une table
	 * InnoDB entière, un appel par table évite le dépassement de délai.
	 */
	public function ajax_cleanup_optimize(): void {
		$this->guard();

		$table   = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- vérifié par guard().
		$cleanup = new Cleanup();
		if ( ! $cleanup->is_site_table( $table ) ) {
			wp_send_json_error( [ 'message' => __( 'Table introuvable.', 'lumia-tools' ) ] );
		}
		if ( ! $cleanup->optimize( $table ) ) {
			global $wpdb;
			wp_send_json_error( [ 'message' => $wpdb->last_error ] );
		}
		wp_send_json_success();
	}

	/**
	 * Repère une opération interdite dans une requête, ou null.
	 *
	 * Deux précautions contre les faux positifs, qui bloquaient des requêtes
	 * parfaitement légitimes :
	 *  - la comparaison se fait sur des MOTS entiers (« migrant » ne contient
	 *    plus « GRANT ») ;
	 *  - les chaînes littérales sont neutralisées au préalable, une valeur
	 *    n'étant jamais une instruction.
	 *
	 * Le test ne porte que sur cette copie : c'est bien la requête d'origine
	 * qui est exécutée ensuite.
	 */
	private function find_forbidden_keyword( string $sql ): ?string {
		$sujet = $this->normalize_sql( $sql );

		foreach ( self::FORBIDDEN_KEYWORDS as $kw ) {
			$motif = '/\b' . str_replace( ' ', '\s+', preg_quote( $kw, '/' ) ) . '\b/i';
			if ( preg_match( $motif, $sujet ) ) {
				return $kw;
			}
		}

		return null;
	}

	/**
	 * Réduit une requête à une forme comparable, pour les seuls tests de
	 * garde-fou. La requête EXÉCUTÉE reste l'originale.
	 *
	 * Trois passes, dans cet ordre — l'ordre est le fond du sujet :
	 *
	 *  1. neutraliser les littéraux ('…', "…") et les identifiants entre
	 *     accents graves. Une valeur n'est jamais une instruction, et il faut
	 *     le faire EN PREMIER : une ouverture de commentaire à l'intérieur
	 *     d'une chaîne n'ouvre rien du tout, et la traiter comme telle
	 *     tronquerait la requête normalisée, donc masquerait ce qui suit ;
	 *  2. retirer les commentaires. C'est le trou que l'audit a exploité :
	 *     MySQL accepte un commentaire vide comme séparateur de mots, si bien
	 *     qu'un DROP suivi d'un commentaire vide puis de DATABASE ne
	 *     ressemblait à aucun mot-clé interdit tout en étant exécuté comme
	 *     « DROP DATABASE ». Étendre la liste noire n'y changeait rien : il y a
	 *     une infinité de façons d'écrire l'espace ;
	 *  3. réduire toute suite d'espaces (y compris les retours à la ligne) à
	 *     un espace simple, pour que `\s+` des motifs ait un terrain régulier.
	 *
	 * Chaque `preg_replace` peut échouer (chaîne non close, limite de
	 * récursion) ; on conserve alors l'état précédent plutôt que de laisser une
	 * chaîne vide passer tous les tests.
	 */
	private function normalize_sql( string $sql ): string {
		$out = preg_replace(
			[ "#'[^']*'#", '#"[^"]*"#', '#`[^`]*`#' ],
			[ "''", '""', '``' ],
			$sql
		);
		$out = ( null === $out ) ? $sql : $out;

		$sans_commentaires = preg_replace(
			[
				'#/\*.*?\*/#s',   // /* … */, y compris sur plusieurs lignes
				'#--[^\n]*#',     // -- jusqu'à la fin de la ligne
				'#\#[^\n]*#',     // #  jusqu'à la fin de la ligne
			],
			' ',
			$out
		);
		$out               = ( null === $sans_commentaires ) ? $out : $sans_commentaires;

		$compacte = preg_replace( '/\s+/', ' ', $out );
		$out      = ( null === $compacte ) ? $out : $compacte;

		return trim( $out );
	}

	/**
	 * La requête est-elle une simple lecture ?
	 *
	 * Ne fonder la réponse que sur le premier mot ne suffit pas : un `SELECT`
	 * peut écrire (`INTO OUTFILE`), un `WITH … AS (…) DELETE …` commence par un
	 * mot de lecture, et `SELECT 1; DELETE FROM …` en cache une derrière un
	 * point-virgule. La classification décide de la confirmation d'écriture ET
	 * du plafond de lignes : se tromper dans ce sens-là laisse passer une
	 * modification sans confirmation.
	 *
	 * On exige donc trois choses : un mot d'ouverture de lecture, aucun verbe
	 * d'écriture ailleurs dans la requête, et aucun point-virgule interne.
	 *
	 * Le sens de l'erreur est assumé : classer une lecture en écriture ne coûte
	 * qu'une confirmation de plus, l'inverse coûte une table.
	 */
	private function is_read_query( string $normalized ): bool {
		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $normalized ) ) {
			return false;
		}

		// `SHOW CREATE TABLE` reste une lecture : CREATE et DROP ne figurent pas
		// dans cette liste, ils ne peuvent de toute façon pas commencer une
		// requête classée en lecture.
		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|RENAME|INTO\s+(OUTFILE|DUMPFILE)|LOAD\s+DATA)\b/i', $normalized ) ) {
			return false;
		}

		// Un point-virgule ailleurs qu'en fin de requête annonce une seconde
		// instruction, que ce test-ci n'a pas examinée.
		if ( preg_match( '/;\s*\S/', $normalized ) ) {
			return false;
		}

		return true;
	}

	public function ajax_run_query(): void {
		$this->guard();

		global $wpdb;
		$sql = isset( $_POST['sql'] ) ? trim( (string) wp_unslash( $_POST['sql'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce vérifié par guard() en tête de handler ; SQL saisi par l'administrateur, filtré par find_forbidden_keyword() et confirmé côté client pour toute écriture.
		if ( '' === $sql ) {
			wp_send_json_error( [ 'message' => __( 'Requête vide.', 'lumia-tools' ) ] );
		}

		// Garde-fou 1 : opérations interdites (gestion des bases/utilisateurs, arrêt serveur…).
		$forbidden = $this->find_forbidden_keyword( $sql );
		if ( null !== $forbidden ) {
			/* translators: %s: mot-clé SQL interdit. */
			wp_send_json_error( [ 'message' => sprintf( __( 'Opération interdite dans cet éditeur : %s.', 'lumia-tools' ), $forbidden ) ] );
		}

		// Détecter si c'est une requête de lecture. Le test porte sur la forme
		// normalisée : un commentaire suffisait sinon à déguiser une écriture.
		$normalized = $this->normalize_sql( $sql );
		$is_select  = $this->is_read_query( $normalized );

		// Garde-fou 2 : toute requête d'écriture exige une confirmation explicite côté client.
		if ( ! $is_select && empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard() en tête de handler.
			wp_send_json_error(
				[
					'message'       => __( 'Cette requête modifie la base. Confirmation requise.', 'lumia-tools' ),
					'needs_confirm' => true,
				]
			);
		}

		$wpdb->flush();

		if ( $is_select ) {
			// Garde-fou 3 : borne mémoire — on plafonne les SELECT sans LIMIT explicite.
			$capped    = $sql;
			$truncated = false;
			$bare      = rtrim( $sql, "; \t\n\r" );
			// Seuls SELECT et WITH peuvent ramener un volume non borné. SHOW,
			// DESCRIBE et EXPLAIN rendent un jeu déjà fini — et n'acceptent pas
			// de LIMIT : le plafond transformait « SHOW CREATE TABLE x » en
			// erreur de syntaxe.
			//
			// La présence d'un LIMIT se lit sur la forme normalisée : un
			// « /* LIMIT 1 */ » en commentaire faisait sauter le plafond.
			$plafonnable = (bool) preg_match( '/^\s*(SELECT|WITH)\b/i', $normalized );

			if ( $plafonnable && ! preg_match( '/\bLIMIT\b/i', $normalized ) ) {
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
			wp_send_json_error( [ 'message' => $this->friendly_db_error( $wpdb->last_error, __( 'Requête échouée.', 'lumia-tools' ) ) ] );
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
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		global $wpdb;
		$table = $this->read_table();
		if ( null === $table ) {
			wp_die( esc_html__( 'Table invalide.', 'lumia-tools' ) );
		}

		$filename = $table . '_' . gmdate( 'Y-m-d_His' ) . '.sql';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: ' . Admin::content_disposition( $filename ) );

		// Colonnes + typage : détermine quelles valeurs sont numériques (non quotées) ou binaires (hex).
		$columns   = $this->get_columns_map( $table );
		$col_names = array_keys( $columns );
		$is_num    = [];
		$is_binary = [];
		foreach ( $columns as $field => $col ) {
			$type                = strtolower( $col['Type'] ?? '' );
			$is_num[ $field ]    = (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|dec|numeric|float|double|real|bit|year)\b/', $type );
			$is_binary[ $field ] = (bool) preg_match( '/(blob|binary)\b/', $type );
		}
		// Liste de colonnes échappées pour un INSERT explicite (réimportable même si l'ordre/nombre change).
		$col_list = '`' . implode( '`, `', array_map( 'esc_sql', $col_names ) ) . '`';

		// Structure de la table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N );

		// Sortie SQL brute téléchargée en application/octet-stream, jamais rendue
		// en HTML : un échappement HTML corromprait le dump. La table sort de
		// read_table() (liste blanche SHOW TABLES), les valeurs de esc_sql().
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "-- Lümia Tools - Export SQL\n";
		echo '-- Table: ' . $table . "\n";
		echo '-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		echo "SET NAMES utf8mb4;\n";
		echo 'DROP TABLE IF EXISTS `' . $table . "`;\n";
		echo $create[1] . ";\n\n";

		// Données par lots de 500.
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
						// Données binaires → littéral hexadécimal (0x…), toujours réimportable.
						$values[] = '0x' . bin2hex( $v );
					} elseif ( ! empty( $is_num[ $field ] ) && is_numeric( $v ) ) {
						$values[] = $v; // numérique → non quoté.
					} else {
						// esc_sql() remplace chaque « % » par un jeton de hachage destiné à
						// $wpdb->prepare() : hors prepare(), il faut le retirer, sinon le
						// dump contient ce jeton à la place des « % » d'origine.
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
