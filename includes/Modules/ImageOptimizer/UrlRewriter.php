<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Réécrit les URLs d'un média converti partout où elles ont déjà été insérées.
 *
 * Convertir une image change son nom de fichier (photo.jpg → photo.webp), et
 * ImageProcessor::convert() supprime l'original par défaut. Sans cette passe,
 * toute occurrence déjà insérée — contenu des articles, métas de constructeur
 * (Bricks, ACF…), options de thème, srcset — pointait vers un fichier disparu :
 * une optimisation en masse cassait chaque image déjà en page.
 *
 * Principes :
 *  - on cherche le chemin RELATIF au dossier uploads, précédé d'un « / » et
 *    suivi d'une frontière (`/2024/01/photo.jpg`) : indifférent au schéma, au
 *    domaine et à un CDN ; `other-photo.jpg` et `photo.jpg-old.jpg` ne sont
 *    pas touchés ;
 *  - la variante à barres échappées (`\/2024\/01\/photo.jpg`) est traitée
 *    aussi : c'est la forme des attributs de blocs Gutenberg et des JSON ;
 *  - les valeurs sérialisées sont désérialisées, parcourues, puis
 *    re-sérialisées — un remplacement textuel y fausserait les longueurs de
 *    chaîne (`s:42:"…"`) et corromprait la valeur. Seul stdClass est
 *    instancié : toute autre classe reste incomplète (pas de __wakeup) et
 *    se re-sérialise sous son nom d'origine, intacte ;
 *  - une seule requête par table et par appel, quel que soit le nombre de
 *    médias : les racines (`/2024/01/photo`) sont réunies dans un même OR.
 *    Le bulk regroupe ainsi les paires d'un lot entier avant d'appeler
 *    rewrite() — cinq images, trois requêtes, et non quinze.
 */
class UrlRewriter {

	/**
	 * Réécrit un jeu de correspondances dans les contenus, les métas et les options.
	 *
	 * @param array<string, string> $pairs Chemins relatifs au dossier uploads,
	 *                                     ancien => nouveau (ex. `2024/01/photo.jpg`
	 *                                     => `2024/01/photo.webp`).
	 * @return int Nombre de lignes effectivement mises à jour.
	 */
	public function rewrite( array $pairs ): int {
		$pairs = $this->normalize_pairs( $pairs );
		if ( ! $pairs ) {
			return 0;
		}

		$stems = $this->stems( array_keys( $pairs ) );
		if ( ! $stems ) {
			return 0;
		}

		global $wpdb;

		$updated  = 0;
		$updated += $this->rewrite_table( $wpdb->posts, 'ID', [ 'post_content', 'post_excerpt' ], $stems, $pairs );
		$updated += $this->rewrite_table( $wpdb->postmeta, 'meta_id', [ 'meta_value' ], $stems, $pairs );
		$updated += $this->rewrite_table( $wpdb->options, 'option_id', [ 'option_value' ], $stems, $pairs );

		if ( $updated > 0 ) {
			// Les caches objet de ces lignes sont périmés.
			wp_cache_flush();
		}

		return $updated;
	}

	/**
	 * Ne garde que des paires valides et distinctes, sans slash de tête.
	 *
	 * @param array<string, string> $pairs
	 * @return array<string, string>
	 */
	private function normalize_pairs( array $pairs ): array {
		$clean = [];
		foreach ( $pairs as $old => $new ) {
			$old = ltrim( str_replace( '\\', '/', (string) $old ), '/' );
			$new = ltrim( str_replace( '\\', '/', (string) $new ), '/' );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$clean[ $old ] = $new;
		}
		return $clean;
	}

	/**
	 * Racines distinctes des chemins : `2024/01/photo` pour `photo.jpg` et
	 * `photo-300x200.jpg`. Chaque racine devient un motif LIKE.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	private function stems( array $paths ): array {
		$stems = [];
		foreach ( $paths as $path ) {
			$stem = (string) preg_replace( '/(-\d+x\d+)?\.[a-z0-9]+$/i', '', $path );
			if ( '' !== $stem ) {
				$stems[ $stem ] = true;
			}
		}
		return array_keys( $stems );
	}

	/**
	 * Parcourt les lignes d'une table contenant l'une des racines et réécrit
	 * celles qui changent réellement.
	 *
	 * @param string   $table   Nom de table.
	 * @param string   $id_col  Clé primaire.
	 * @param string[] $columns Colonnes texte à traiter.
	 * @param string[] $stems   Racines (relatives uploads, sans slash de tête).
	 * @param array<string, string> $pairs   ancien => nouveau.
	 */
	private function rewrite_table( string $table, string $id_col, array $columns, array $stems, array $pairs ): int {
		global $wpdb;

		$where = [];
		$args  = [];
		foreach ( $columns as $col ) {
			foreach ( $stems as $stem ) {
				$where[] = "`{$col}` LIKE %s";
				$args[]  = '%' . $wpdb->esc_like( '/' . $stem ) . '%';
				$where[] = "`{$col}` LIKE %s";
				$args[]  = '%' . $wpdb->esc_like( '\/' . str_replace( '/', '\/', $stem ) ) . '%';
			}
		}
		$cols_sql = '`' . implode( '`, `', $columns ) . '`';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `{$id_col}`, {$cols_sql} FROM `{$table}` WHERE " . implode( ' OR ', $where ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- identifiants codés en dur par rewrite(), placeholders %s portés par $where.
				$args
			),
			ARRAY_A
		);

		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$changes = [];
			foreach ( $columns as $col ) {
				$new = $this->replace_in_value( $row[ $col ], $pairs );
				if ( $new !== $row[ $col ] ) {
					$changes[ $col ] = $new;
				}
			}
			if ( ! $changes ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->update( $table, $changes, [ $id_col => $row[ $id_col ] ] );
			if ( false === $result ) {
				// Le fichier a déjà changé de nom : une ligne non réécrite est un
				// lien cassé. On ne peut pas revenir en arrière ici, mais on le
				// dit, avec de quoi corriger à la main.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- journal d'erreur volontaire, sans interface pour l'afficher.
				error_log(
					sprintf(
						'[LUMIA Image Optimizer] réécriture d\'URL échouée dans %s (%s=%s) : %s',
						$table,
						$id_col,
						(string) $row[ $id_col ],
						$wpdb->last_error
					)
				);
				continue;
			}
			++$updated;
		}

		return $updated;
	}

	/**
	 * Remplace dans une valeur brute de base : chaîne, ou sérialisé PHP.
	 *
	 * @param mixed $value
	 * @return mixed
	 * @param array<string, string> $pairs
	 */
	public function replace_in_value( $value, array $pairs ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			// Liste blanche : seul stdClass est instancié. Toute autre classe
			// arrive en __PHP_Incomplete_Class — aucun __wakeup/__unserialize
			// n'est exécuté (pas d'injection d'objet depuis une méta ou une
			// option), et elle se re-sérialise sous son nom d'origine.
			$data = @unserialize( $value, [ 'allowed_classes' => [ 'stdClass' ] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $data || 'b:0;' === $value ) {
				$replaced = $this->replace_recursive( $data, $pairs );
				return serialize( $replaced ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}

		return $this->replace_in_string( $value, $pairs );
	}

	/**
	 * @param mixed $data
	 * @return mixed
	 * @param array<string, string> $pairs
	 */
	private function replace_recursive( $data, array $pairs ) {
		if ( is_string( $data ) ) {
			return $this->replace_in_string( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->replace_recursive( $v, $pairs );
			}
			return $data;
		}
		if ( $data instanceof \stdClass ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = $this->replace_recursive( $v, $pairs );
			}
			return $data;
		}
		return $data; // Les scalaires, les valeurs nulles et les objets incomplets restent intacts.
	}

	/**
	 * @param array<string, string> $pairs
	 */
	private function replace_in_string( string $value, array $pairs ): string {
		foreach ( $pairs as $old => $new ) {
			$old_esc = str_replace( '/', '\/', $old );
			$new_esc = str_replace( '/', '\/', $new );

			// Callbacks plutôt qu'une chaîne de remplacement : preg_replace y
			// interprète l'antislash, et `\/` en ressortirait doublé.
			$value = (string) preg_replace_callback(
				'#\\\\/' . preg_quote( $old_esc, '#' ) . '(?![A-Za-z0-9._-])#',
				static function () use ( $new_esc ) {
					return '\\/' . $new_esc;
				},
				$value
			);
			$value = (string) preg_replace_callback(
				'#/' . preg_quote( $old, '#' ) . '(?![A-Za-z0-9._-])#',
				static function () use ( $new ) {
					return '/' . $new;
				},
				$value
			);
		}
		return $value;
	}
}
