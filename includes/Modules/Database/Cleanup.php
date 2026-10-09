<?php
namespace Lumia\Tools\Modules\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Onglet Nettoyage du module Base de données (remplace WP-Sweep).
 *
 * Chaque élément se compte, puis se purge par lots de BATCH : un site qui
 * traîne 80 000 révisions ne tient pas dans un seul appel AJAX. Les objets
 * qui ont une API WordPress (articles, révisions, commentaires, transients)
 * passent par elle, pour que les hooks et les caches suivent ; les
 * métadonnées et relations orphelines n'ont plus d'objet parent, donc plus
 * de cache à invalider, et partent en SQL direct.
 *
 * Portée : le site courant (`$wpdb->prefix`). En multisite, chaque site se
 * nettoie depuis son propre tableau de bord.
 */
class Cleanup {

	/** Nombre d'objets traités par appel. */
	const BATCH = 200;

	/** Espace libre minimal (octets) pour signaler une table fragmentée. */
	const MIN_FREE = 1048576;

	/** Part minimale d'espace libre rapportée à la taille de la table. */
	const MIN_FREE_RATIO = 0.2;

	/**
	 * Préfixes de tables connus qui ne ressemblent pas au nom de l'extension
	 * qui les crée. Jeton (premier segment du nom court) => dossiers
	 * d'extensions possibles.
	 */
	const OWNER_ALIASES = [
		'wc'              => [ 'woocommerce' ],
		'actionscheduler' => [ 'woocommerce', 'action-scheduler', 'wp-mail-smtp', 'seo-by-rank-math', 'mailpoet', 'wpforms-lite', 'wpforms' ],
		'e'               => [ 'elementor', 'elementor-pro' ],
		'gf'              => [ 'gravityforms' ],
		'rank'            => [ 'seo-by-rank-math' ],
		'fsmpt'           => [ 'fluent-smtp' ],
		'ewwwio'          => [ 'ewww-image-optimizer' ],
	];

	/**
	 * Éléments nettoyables, dans l'ordre d'affichage.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function items(): array {
		return [
			'revisions'                 => [
				'label'       => __( 'Révisions', 'lumia-tools' ),
				'description' => __( 'Anciennes versions des contenus, conservées à chaque enregistrement.', 'lumia-tools' ),
			],
			'auto_drafts'               => [
				'label'       => __( 'Brouillons automatiques', 'lumia-tools' ),
				'description' => __( 'Créés à l\'ouverture de l\'éditeur, jamais enregistrés.', 'lumia-tools' ),
			],
			'trashed_posts'             => [
				'label'       => __( 'Contenus dans la corbeille', 'lumia-tools' ),
				'description' => __( 'Articles, pages et types personnalisés supprimés définitivement.', 'lumia-tools' ),
			],
			'spam_comments'             => [
				'label'       => __( 'Commentaires indésirables', 'lumia-tools' ),
				'description' => __( 'Commentaires marqués comme spam.', 'lumia-tools' ),
			],
			'trashed_comments'          => [
				'label'       => __( 'Commentaires dans la corbeille', 'lumia-tools' ),
				'description' => __( 'Commentaires supprimés définitivement.', 'lumia-tools' ),
			],
			'expired_transients'        => [
				'label'       => __( 'Transients expirés', 'lumia-tools' ),
				'description' => __( 'Données de cache temporaires dont la date d\'expiration est passée.', 'lumia-tools' ),
			],
			'orphan_transient_timeouts' => [
				'label'       => __( 'Expirations de transients orphelines', 'lumia-tools' ),
				'description' => __( 'Dates d\'expiration dont le transient n\'existe plus.', 'lumia-tools' ),
			],
			'orphan_postmeta'           => [
				'label'       => __( 'Métadonnées de contenus orphelines', 'lumia-tools' ),
				'description' => __( 'Rattachées à un contenu qui n\'existe plus.', 'lumia-tools' ),
			],
			'orphan_commentmeta'        => [
				'label'       => __( 'Métadonnées de commentaires orphelines', 'lumia-tools' ),
				'description' => __( 'Rattachées à un commentaire qui n\'existe plus.', 'lumia-tools' ),
			],
			'orphan_usermeta'           => [
				'label'       => __( 'Métadonnées d\'utilisateurs orphelines', 'lumia-tools' ),
				'description' => __( 'Rattachées à un utilisateur qui n\'existe plus.', 'lumia-tools' ),
			],
			'orphan_termmeta'           => [
				'label'       => __( 'Métadonnées de termes orphelines', 'lumia-tools' ),
				'description' => __( 'Rattachées à une catégorie ou étiquette qui n\'existe plus.', 'lumia-tools' ),
			],
			'orphan_term_relationships' => [
				'label'       => __( 'Relations de termes orphelines', 'lumia-tools' ),
				'description' => __( 'Liens entre un terme et un contenu qui n\'existe plus.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * COMPTAGE
	 * ================================================================ */

	public function count( string $item ): int {
		global $wpdb;

		switch ( $item ) {
			case 'revisions':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );

			case 'auto_drafts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );

			case 'trashed_posts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );

			case 'spam_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );

			case 'trashed_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'" );

			case 'expired_transients':
				return $this->count_expired_transients();

			case 'orphan_transient_timeouts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_timeouts_sql() );

			case 'orphan_term_relationships':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_relationships_sql() );
		}

		$meta = $this->meta_spec( $item );
		if ( null !== $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . $this->orphan_meta_sql( $meta ) );
		}

		return 0;
	}

	/**
	 * Compte ce que `delete_expired_transients()` supprimera, et seulement
	 * cela : le cœur ne supprime que des PAIRES valeur + expiration. Une
	 * expiration seule n'est jamais touchée par lui (voir l'élément
	 * `orphan_transient_timeouts`) ; la compter ici laisserait un reste
	 * après chaque nettoyage.
	 */
	private function count_expired_transients(): int {
		global $wpdb;
		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} a
				INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
				WHERE a.option_name LIKE %s AND a.option_name NOT LIKE %s AND b.option_value < %d",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		if ( ! is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} a
					INNER JOIN {$wpdb->options} b ON b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
					WHERE a.option_name LIKE %s AND a.option_name NOT LIKE %s AND b.option_value < %d",
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
		} elseif ( is_main_site() && is_main_network() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->sitemeta} a
					INNER JOIN {$wpdb->sitemeta} b ON b.meta_key = CONCAT( '_site_transient_timeout_', SUBSTRING( a.meta_key, 17 ) )
					WHERE a.meta_key LIKE %s AND a.meta_key NOT LIKE %s AND b.meta_value < %d",
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now
				)
			);
		}

		return $count;
	}

	/**
	 * FROM … WHERE des expirations sans transient. L'inverse (un transient
	 * sans expiration) n'est PAS orphelin : c'est un transient créé sans
	 * durée, qui n'expire jamais. Le supprimer casserait l'extension qui l'a
	 * posé.
	 */
	private function orphan_timeouts_sql(): string {
		global $wpdb;
		return $wpdb->prepare(
			"FROM {$wpdb->options} t
			WHERE ( t.option_name LIKE %s AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->options} v WHERE v.option_name = CONCAT( '_transient_', SUBSTRING( t.option_name, 20 ) )
			) )
			OR ( t.option_name LIKE %s AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->options} v WHERE v.option_name = CONCAT( '_site_transient_', SUBSTRING( t.option_name, 25 ) )
			) )",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
		);
	}

	/**
	 * Description d'une table de métadonnées pour la détection d'orphelins.
	 *
	 * @return array{table: string, id: string, fk: string, parent: string, pk: string}|null
	 */
	private function meta_spec( string $item ): ?array {
		global $wpdb;
		switch ( $item ) {
			case 'orphan_postmeta':
				return [
					'table'  => $wpdb->postmeta,
					'id'     => 'meta_id',
					'fk'     => 'post_id',
					'parent' => $wpdb->posts,
					'pk'     => 'ID',
				];
			case 'orphan_commentmeta':
				return [
					'table'  => $wpdb->commentmeta,
					'id'     => 'meta_id',
					'fk'     => 'comment_id',
					'parent' => $wpdb->comments,
					'pk'     => 'comment_ID',
				];
			case 'orphan_usermeta':
				return [
					'table'  => $wpdb->usermeta,
					'id'     => 'umeta_id',
					'fk'     => 'user_id',
					'parent' => $wpdb->users,
					'pk'     => 'ID',
				];
			case 'orphan_termmeta':
				return [
					'table'  => $wpdb->termmeta,
					'id'     => 'meta_id',
					'fk'     => 'term_id',
					'parent' => $wpdb->terms,
					'pk'     => 'term_id',
				];
		}
		return null;
	}

	/**
	 * @param array{table: string, id: string, fk: string, parent: string, pk: string} $m
	 */
	private function orphan_meta_sql( array $m ): string {
		// Identifiants issus de $wpdb et de constantes : rien ne vient du client.
		return "FROM {$m['table']} m LEFT JOIN {$m['parent']} p ON p.{$m['pk']} = m.{$m['fk']} WHERE p.{$m['pk']} IS NULL";
	}

	/**
	 * FROM … WHERE des relations dont l'objet n'est plus un contenu.
	 *
	 * `term_relationships.object_id` n'est pas toujours un ID d'article : les
	 * catégories de liens pointent vers des liens, une taxonomie peut être
	 * enregistrée sur les utilisateurs (types de membres BuddyPress…).
	 *
	 * D'où une liste BLANCHE : seules les taxonomies enregistrées, et
	 * rattachées uniquement à des types de contenu, sont purgées. Une liste
	 * noire des taxonomies « non contenu » ne voit que celles enregistrées au
	 * moment du nettoyage : extension désactivée, ses relations vers des
	 * utilisateurs passaient pour orphelines et partaient définitivement.
	 */
	private function orphan_relationships_sql(): string {
		global $wpdb;

		$allowed    = [];
		$post_types = get_post_types();
		foreach ( get_taxonomies( [], 'objects' ) as $tax ) {
			$types = (array) $tax->object_type;
			if ( $types && ! array_diff( $types, $post_types ) ) {
				$allowed[] = $tax->name;
			}
		}
		if ( ! $allowed ) {
			// Aucune taxonomie sûre : une condition toujours fausse.
			return "FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE 1 = 0";
		}
		$placeholders = implode( ', ', array_fill( 0, count( $allowed ), '%s' ) );

		// $placeholders ne contient que des %s, un par taxonomie autorisée.
		return $wpdb->prepare(
			"FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE p.ID IS NULL AND tt.taxonomy IN ( {$placeholders} )", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$allowed
		);
	}

	/* ================================================================
	 * PURGE (un lot par appel)
	 * ================================================================ */

	/**
	 * Purge un lot et renvoie le nombre d'objets supprimés. 0 signifie « plus
	 * rien à faire ou plus rien de supprimable » : le client s'arrête là, ce
	 * qui évite une boucle infinie sur un objet que WordPress refuse de
	 * supprimer.
	 */
	public function run( string $item ): int {
		global $wpdb;

		switch ( $item ) {
			case 'revisions':
				return $this->delete_posts( "post_type = 'revision'", 'wp_delete_post_revision' );

			case 'auto_drafts':
				return $this->delete_posts( "post_status = 'auto-draft'", 'wp_delete_post' );

			case 'trashed_posts':
				return $this->delete_posts( "post_status = 'trash'", 'wp_delete_post' );

			case 'spam_comments':
				return $this->delete_comments( 'spam' );

			case 'trashed_comments':
				return $this->delete_comments( 'trash' );

			case 'expired_transients':
				$before = $this->count_expired_transients();
				// true : même avec un cache objet externe, ce sont les lignes
				// de la base que l'on vient de compter.
				delete_expired_transients( true );
				return max( 0, $before - $this->count_expired_transients() );

			case 'orphan_transient_timeouts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$names = $wpdb->get_col( 'SELECT t.option_name ' . $this->orphan_timeouts_sql() . ' LIMIT ' . self::BATCH );
				$done  = 0;
				foreach ( $names as $name ) {
					if ( delete_option( $name ) ) {
						++$done;
					}
				}
				return $done;

			case 'orphan_term_relationships':
				return $this->delete_orphan_relationships();
		}

		$meta = $this->meta_spec( $item );
		if ( null !== $meta ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT m.{$meta['id']} " . $this->orphan_meta_sql( $meta ) . ' LIMIT ' . self::BATCH ) );
			if ( ! $ids ) {
				return 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- entiers castés ci-dessus.
			return (int) $wpdb->query( "DELETE FROM {$meta['table']} WHERE {$meta['id']} IN (" . implode( ',', $ids ) . ')' );
		}

		return 0;
	}

	/**
	 * @param string   $where    Clause constante (jamais d'entrée client).
	 * @param callable $delete   wp_delete_post ou wp_delete_post_revision.
	 */
	private function delete_posts( string $where, callable $delete ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID LIMIT " . self::BATCH );
		$done = 0;
		foreach ( $ids as $id ) {
			// Le second argument force la suppression définitive pour
			// wp_delete_post ; wp_delete_post_revision l'ignore.
			if ( $delete( (int) $id, true ) ) {
				++$done;
			}
		}
		return $done;
	}

	private function delete_comments( string $status ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s ORDER BY comment_ID LIMIT %d", $status, self::BATCH ) );
		$done = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Supprime un lot de relations orphelines puis recalcule le compteur des
	 * termes concernés (colonne `count`, affichée dans l'admin).
	 */
	private function delete_orphan_relationships(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT tr.object_id, tr.term_taxonomy_id, tt.taxonomy ' . $this->orphan_relationships_sql() . ' LIMIT ' . self::BATCH, ARRAY_A );

		$done    = 0;
		$touched = [];
		foreach ( (array) $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				$wpdb->term_relationships,
				[
					'object_id'        => (int) $row['object_id'],
					'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
				],
				[ '%d', '%d' ]
			);
			if ( $deleted ) {
				$done                         += (int) $deleted;
				$touched[ $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
			}
		}

		foreach ( $touched as $taxonomy => $tt_ids ) {
			// Une taxonomie non enregistrée (extension retirée) n'a pas de
			// callback de comptage : wp_update_term_count_now() lirait une
			// propriété sur false.
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( array_unique( $tt_ids ), $taxonomy );
			}
			clean_term_cache( array_unique( $tt_ids ), $taxonomy, false );
		}

		return $done;
	}

	/* ================================================================
	 * OPTIMISATION ET TABLES ORPHELINES
	 * ================================================================ */

	/**
	 * Tables du site dont l'espace libre (`Data_free`) est non nul.
	 *
	 * @return list<array{name: string, free: int}>
	 */
	public function fragmented_tables(): array {
		global $wpdb;

		// Tablespace InnoDB partagé : chaque table y rapporte l'espace libre
		// du fichier COMMUN, que OPTIMIZE ne rend jamais au disque. Compter
		// ces tables multipliait le total et les laissait « fragmentées » à vie.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$per_table = $wpdb->get_var( 'SELECT @@innodb_file_per_table' );
		$shared    = null !== $per_table && ! in_array( strtoupper( (string) $per_table ), [ '1', 'ON' ], true );

		$tables = [];
		foreach ( $this->site_table_status() as $t ) {
			$free = (int) $t['Data_free'];
			$used = (int) $t['Data_length'] + (int) $t['Index_length'];
			if ( $shared && 'InnoDB' === $t['Engine'] ) {
				continue;
			}
			// InnoDB garde quelques Mo réservés par table, qu'OPTIMIZE ne
			// libère pas sur une grosse table : sans seuil relatif, elle
			// restait signalée après chaque optimisation.
			if ( $free >= self::MIN_FREE && $free > $used * self::MIN_FREE_RATIO ) {
				$tables[] = [
					'name' => (string) $t['Name'],
					'free' => $free,
				];
			}
		}
		return $tables;
	}

	/**
	 * Tables préfixées qui n'appartiennent pas au cœur, avec une supposition
	 * sur l'extension qui les a créées. Heuristique assumée : la liste sert à
	 * orienter, la suppression reste une décision manuelle, table par table.
	 *
	 * @return list<array{name: string, rows: int, size: int, status: string, owners: list<string>}>
	 */
	public function foreign_tables(): array {
		global $wpdb;

		$core   = $wpdb->tables( 'all', false );
		$prefix = $wpdb->prefix;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		/** @var array<string, array{Name: string}> $plugins */

		/**
		 * Filtre les alias jeton de table => dossiers d'extensions.
		 *
		 * @param array<string, list<string>> $aliases
		 */
		$aliases = (array) apply_filters( 'lumia_db_table_owner_aliases', self::OWNER_ALIASES );

		$tables = [];
		foreach ( $this->site_table_status() as $t ) {
			$name  = (string) $t['Name'];
			$short = substr( $name, strlen( $prefix ) );

			// Tables des sous-sites (wp_2_posts…) vues depuis le site principal.
			if ( in_array( $short, $core, true ) || ( is_multisite() && preg_match( '/^\d+_/', $short ) ) ) {
				continue;
			}

			$token  = strtolower( (string) strtok( $short, '_' ) );
			$owners = [];
			$active = false;
			foreach ( $plugins as $file => $data ) {
				$dir = strtolower( false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' ) );
				if ( ! $this->token_matches( $token, $dir, (string) $data['Name'], $aliases ) ) {
					continue;
				}
				$owners[] = (string) $data['Name'];
				if ( is_plugin_active( $file ) ) {
					$active = true;
				}
			}

			if ( $active ) {
				$status = 'active';
			} elseif ( $owners ) {
				$status = 'inactive';
			} else {
				$status = 'unknown';
			}

			$tables[] = [
				'name'   => $name,
				'rows'   => (int) $t['Rows'],
				'size'   => (int) $t['Data_length'] + (int) $t['Index_length'],
				'status' => $status,
				'owners' => array_values( array_unique( $owners ) ),
			];
		}

		// Les plus suspectes d'abord.
		$order = [
			'unknown'  => 0,
			'inactive' => 1,
			'active'   => 2,
		];
		usort(
			$tables,
			static function ( array $a, array $b ) use ( $order ): int {
				return [ $order[ $a['status'] ], $a['name'] ] <=> [ $order[ $b['status'] ], $b['name'] ];
			}
		);

		return $tables;
	}

	/**
	 * @param array<string, mixed> $aliases
	 */
	private function token_matches( string $token, string $dir, string $name, array $aliases ): bool {
		if ( isset( $aliases[ $token ] ) && in_array( $dir, (array) $aliases[ $token ], true ) ) {
			return true;
		}
		// Sous trois caractères, un jeton (« e », « wc ») correspond à tout.
		if ( strlen( $token ) < 3 ) {
			return false;
		}
		$normalize = static function ( string $s ): string {
			return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
		};
		return false !== strpos( $normalize( $dir ), $token ) || false !== strpos( $normalize( $name ), $token );
	}

	/**
	 * Vrai si la table appartient au site courant (préfixe) et existe.
	 */
	public function is_site_table( string $table ): bool {
		foreach ( $this->site_table_status() as $t ) {
			if ( $t['Name'] === $table ) {
				return true;
			}
		}
		return false;
	}

	public function optimize( string $table ): bool {
		global $wpdb;
		// Nom vérifié par is_site_table() : issu de SHOW TABLE STATUS.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'OPTIMIZE TABLE `' . str_replace( '`', '``', $table ) . '`', ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		// MySQL ne lève pas d'erreur SQL quand OPTIMIZE échoue (table
		// verrouillée, corrompue) : l'échec est une ligne Msg_type = error.
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Msg_type'] ) && 'error' === strtolower( (string) $row['Msg_type'] ) ) {
				$wpdb->last_error = (string) ( $row['Msg_text'] ?? '' );
				return false;
			}
		}
		return true;
	}

	/**
	 * SHOW TABLE STATUS restreint aux tables du préfixe courant.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function site_table_status(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A );
		return array_values( (array) $rows );
	}
}
