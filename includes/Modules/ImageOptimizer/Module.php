<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Module Image Optimizer — orchestrateur.
 *
 * Délègue le traitement des fichiers à ImageProcessor,
 * le workflow bulk à BulkProcessor,
 * et l'UI médiathèque à MediaLibrary.
 */
class Module extends AbstractModule {

	private const BATCH_SIZE = 5;

	private const STATS_SUFFIX      = '_stats';
	private const BULK_STATE_SUFFIX = '_bulk_state';

	/** Dossier (sous uploads) des originaux intacts, suffixé d'un jeton : voir get_backup_dir(). */
	private const BACKUP_DIR          = 'lumia-originals';
	private const BACKUP_TOKEN_SUFFIX = '_backup_token';

	/**
	 * Méta : fichiers source laissés à côté des convertis (keep_original),
	 * chemins relatifs au dossier uploads. Absents des métadonnées WordPress,
	 * ils ne partiraient pas avec le média sans cette liste.
	 */
	private const FALLBACK_META = '_lumia_fallback_files';

	/** Métas qui décrivent l'optimisation d'un média (effacées à la restauration). */
	private const OPTIMIZATION_META = [
		'_lumia_optimized',
		'_lumia_original_bytes',
		'_lumia_optimized_bytes',
		'_lumia_bytes_saved',
		'_lumia_main_original_bytes',
		'_lumia_main_optimized_bytes',
		'_lumia_main_bytes_saved',
		'_lumia_optimized_format',
		'_lumia_optimized_mime',
	];

	/* ================================================================
	 * SOUS-OBJETS (initialisés dans init())
	 * ================================================================ */

	private ImageProcessor $processor;
	private BulkProcessor $bulk;
	private MediaLibrary $media_library;
	private SvgHandler $svg;

	/**
	 * Réglages actifs du module (cache mémoire).
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * Paires d'URLs en attente de réécriture pendant un lot du bulk : elles
	 * sont réunies puis passées en un seul appel à UrlRewriter (une requête
	 * par table pour tout le lot, au lieu d'une par image).
	 *
	 * @var array<string, string>
	 */
	private array $pending_url_pairs = [];

	/** Vrai entre begin_deferred_url_rewrites() et flush_url_rewrites(). */
	private bool $defer_url_rewrites = false;

	/* ================================================================
	 * INITIALISATION
	 * ================================================================ */

	/**
	 * Charge les réglages, crée les sous-objets et enregistre les hooks.
	 */
	public function init(): void {
		$this->settings = $this->get_settings();

		// Sous-objets
		$this->processor = new ImageProcessor( $this->settings );

		$this->bulk = new BulkProcessor(
			$this->get_module_option_key() . self::BULK_STATE_SUFFIX,
			function ( int $id ): void {
				$this->begin_deferred_url_rewrites();
				$this->process_and_update_attachment( $id, true );
			},
			fn(): array   => $this->get_stats(),
			fn( int $user_id ) => $this->notify_bulk_complete( $user_id ),
			fn() => $this->flush_url_rewrites()
		);

		$this->media_library = new MediaLibrary( $this, $this->processor );
		$this->media_library->init();

		// Support SVG sécurisé (ne branche ses filtres que si activé).
		$this->svg = new SvgHandler( $this->settings );
		$this->svg->init();

		// Hook d'upload : tout le pipeline (original + miniatures) passe par
		// wp_generate_attachment_metadata, qui mesure la vraie taille d'origine.
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'optimize_attachment_sizes' ], 10, 2 );

		// Alt text automatique
		add_action( 'add_attachment', [ $this, 'generate_alt_text' ] );

		// L'original conservé et les fichiers de repli suivent le média dans
		// la corbeille définitive.
		add_action( 'delete_attachment', [ $this, 'delete_kept_files' ] );

		// Bulk AJAX
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_scan', [ $this, 'ajax_bulk_scan' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk', [ $this, 'ajax_bulk_start' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_bulk_status', [ $this, 'ajax_bulk_status' ] );

		// Cron
		add_action( 'lumia_image_optimizer_cron', [ $this, 'run_cron_batch' ] );
	}

	/* ================================================================
	 * RÉGLAGES
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings(
			[
				'optimize_on_upload' => true,
				'format_mode'        => 'auto',
				'quality'            => 75,
				'max_width'          => 2560,
				'max_height'         => 2560,
				'strip_exif'         => true,
				'generate_alt'       => true,
				'keep_original'      => false,
				'svg_upload'         => true,
				'svg_roles'          => [ 'administrator' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$sanitized = [
			'optimize_on_upload' => isset( $settings['optimize_on_upload'] ),
			'format_mode'        => isset( $settings['format_mode'] ) && in_array( $settings['format_mode'], [ 'auto', 'avif', 'webp' ], true )
				? sanitize_key( $settings['format_mode'] )
				: 'auto',
			'quality'            => isset( $settings['quality'] ) ? min( 100, max( 1, absint( $settings['quality'] ) ) ) : 75,
			'max_width'          => isset( $settings['max_width'] ) ? max( 100, absint( $settings['max_width'] ) ) : 2560,
			'max_height'         => isset( $settings['max_height'] ) ? max( 100, absint( $settings['max_height'] ) ) : 2560,
			'strip_exif'         => isset( $settings['strip_exif'] ),
			'generate_alt'       => isset( $settings['generate_alt'] ),
			'keep_original'      => isset( $settings['keep_original'] ),
			'svg_upload'         => isset( $settings['svg_upload'] ),
			'svg_roles'          => $this->sanitize_roles( $settings['svg_roles'] ?? [] ),
		];

		$this->settings = $sanitized;

		return $this->save_module_settings( $sanitized );
	}

	/**
	 * Ne conserve que des slugs de rôles WordPress réellement existants.
	 *
	 * @param mixed $roles
	 * @return string[]
	 */
	private function sanitize_roles( $roles ): array {
		if ( ! is_array( $roles ) ) {
			return [];
		}
		$valid = array_keys( wp_roles()->get_names() );
		return array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
	}

	/* ================================================================
	 * ASSETS ADMIN
	 * ================================================================ */

	public function get_admin_css(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/css/modules/image-optimizer.css',
		];
	}

	public function get_admin_js(): array {
		return [
			LUMIA_ASSETS_URL . 'admin/js/modules/image-optimizer.js',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'bulkState' => $this->bulk->get_state(),
			'i18n'      => [
				'bulkScanning'  => __( 'Analyse…', 'lumia-tools' ),
				'bulkRunning'   => __( 'Optimisation en cours…', 'lumia-tools' ),
				'bulkProcessed' => __( 'Traité :', 'lumia-tools' ),
				'bulkRemaining' => __( 'Restant :', 'lumia-tools' ),
				'bulkDone'      => __( 'Optimisation terminée', 'lumia-tools' ),
				'bulkComplete'  => __( 'Toutes les images ont été optimisées.', 'lumia-tools' ),
				'bulkRetry'     => __( 'Réessayer', 'lumia-tools' ),
				'mediaRunning'  => __( 'Traitement…', 'lumia-tools' ),
				'mediaError'    => __( 'Erreur', 'lumia-tools' ),
				'cancel'        => __( 'Annuler', 'lumia-tools' ),
				'format'        => __( 'Format cible', 'lumia-tools' ),
				'reoptimize'    => [
					'title'    => __( "Ré-optimiser l'image ?", 'lumia-tools' ),
					'backup'   => __( "L'image est retraitée depuis l'original conservé, avec les réglages actuels.", 'lumia-tools' ),
					'nobackup' => __( "Aucun original n'a été conservé : l'image est recompressée depuis sa version actuelle, et la qualité baisse un peu à chaque passage.", 'lumia-tools' ),
					'confirm'  => __( 'Ré-optimiser', 'lumia-tools' ),
				],
				'convert'       => [
					'title'   => __( "Convertir l'image", 'lumia-tools' ),
					'message' => __( "Le fichier et ses miniatures changent d'extension ; les URL déjà insérées dans le site sont réécrites.", 'lumia-tools' ),
					'confirm' => __( 'Convertir', 'lumia-tools' ),
				],
				'restore'       => [
					'title'   => __( "Restaurer l'original ?", 'lumia-tools' ),
					'message' => __( "Les versions optimisées sont supprimées, les miniatures régénérées depuis l'original et les URL du site réécrites vers lui.", 'lumia-tools' ),
					'confirm' => __( 'Restaurer', 'lumia-tools' ),
				],
			],
		];
	}

	/**
	 * Ajoute une notice persistante à l'utilisateur qui a lancé le bulk,
	 * pour qu'il soit informé même si le lot s'est terminé pendant qu'il
	 * avait quitté la page (ou via une reprise cron en arrière-plan).
	 */
	private function notify_bulk_complete( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}
		Admin::add_persistent_notice(
			'image_optimizer_bulk_done',
			__( 'Optimisation en masse des images terminée.', 'lumia-tools' ),
			'success',
			$user_id
		);
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	public function on_deactivate(): void {
		// Supprimer les crons en attente.
		$timestamp = wp_next_scheduled( 'lumia_image_optimizer_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'lumia_image_optimizer_cron' );
		}
	}

	/* ================================================================
	 * STATIC : INSTALL / UNINSTALL
	 * ================================================================ */

	public static function get_uninstall_keys(): array {
		return [
			'options' => [
				'lumia_module_image_optimizer',
				'lumia_module_image_optimizer' . self::STATS_SUFFIX,
				'lumia_module_image_optimizer' . self::BULK_STATE_SUFFIX,
				'lumia_module_image_optimizer' . self::BACKUP_TOKEN_SUFFIX,
			],
			// Les fichiers de lumia-originals/ restent sur le disque : ce sont
			// des photos du client, pas des données du plugin.
			'meta'    => array_merge( self::OPTIMIZATION_META, [ '_lumia_backup_file', self::FALLBACK_META ] ),
		];
	}

	/* ================================================================
	 * HOOK D'UPLOAD
	 * ================================================================ */

	/**
	 * Hook wp_generate_attachment_metadata : optimise + convertit original et miniatures.
	 *
	 * Unique point d'optimisation à l'upload : il s'exécute après la génération
	 * des miniatures et mesure la taille réelle du fichier d'origine. Ne PAS
	 * pré-optimiser le fichier dans wp_handle_upload — sinon la mesure « avant »
	 * porte sur un fichier déjà compressé et le gain affiché est nul (l'image est
	 * quand même marquée « optimisée »).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function optimize_attachment_sizes( array $metadata, int $attachment_id ): array {
		if ( ! $this->settings['optimize_on_upload'] ) {
			return $metadata;
		}

		// wp_generate_attachment_metadata ne sert pas qu'aux téléversements :
		// un outil de régénération de miniatures le rejoue sur des médias déjà
		// insérés en page. On réécrit donc ici aussi ; sur un vrai
		// téléversement le balayage ne trouve simplement rien.
		return $this->process_attachment_metadata( $metadata, $attachment_id, false );
	}

	/* ================================================================
	 * TRAITEMENT D'UN ATTACHMENT
	 * ================================================================ */

	/**
	 * Point d'entrée public : traite un attachment et met à jour ses métadonnées WP.
	 * Utilisé par MediaLibrary (single) et BulkProcessor (batch).
	 */
	public function process_and_update_attachment( int $attachment_id, bool $force = true ): void {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( $metadata ) {
			$metadata = $this->process_attachment_metadata( $metadata, $attachment_id, $force );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$this->generate_alt_text( $attachment_id );
	}

	/**
	 * Traite toutes les tailles d'un attachment (optimisation + conversion).
	 *
	 * @param bool $force Ignore le flag "déjà optimisé".
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	public function process_attachment_metadata( array $metadata, int $attachment_id, bool $force ): array {
		$mime_type = $this->processor->get_mime_type( '', $attachment_id );

		if ( empty( $mime_type ) || ! $this->processor->is_supported_mime( $mime_type ) ) {
			return $metadata;
		}

		$attached_file = get_attached_file( $attachment_id );

		if ( $this->processor->is_animated( (string) $attached_file, $mime_type ) ) {
			return $metadata;
		}

		if ( ! $force && $this->is_already_optimized( $attachment_id ) ) {
			return $metadata;
		}

		if ( empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$upload_dir = wp_upload_dir();
		$base_path  = trailingslashit( $upload_dir['basedir'] );
		$subdir     = dirname( $metadata['file'] );
		$sizes_path = trailingslashit( $base_path . $subdir );
		$rel_dir    = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( str_replace( '\\', '/', $subdir ) );

		$main_before = 0;
		$main_after  = 0;

		// --- Miniatures ---
		$sizes        = $this->process_sizes( $metadata, $mime_type, $sizes_path, $rel_dir );
		$total_before = $sizes['before'];
		$total_after  = $sizes['after'];
		$size_updates = $sizes['updates'];
		$url_pairs    = $sizes['url_pairs']; // ancien chemin relatif uploads => nouveau (voir UrlRewriter)

		// --- Fichier original ---
		$original_file      = $base_path . $metadata['file'];
		$original_converted = false;
		$original_new_file  = '';

		if ( file_exists( $original_file ) ) {
			// Sauvegarde AVANT optimize() : c'est lui qui recompresse et
			// redimensionne en place. Un média déjà optimisé n'a plus d'original
			// à sauver — on ne copierait qu'une version dégradée.
			if ( ! $this->is_already_optimized( $attachment_id ) ) {
				$this->backup_original( $attachment_id, $original_file, str_replace( '\\', '/', $metadata['file'] ) );
			}

			$before        = (int) filesize( $original_file );
			$main_before   = $before;
			$total_before += $before;

			$this->processor->optimize( $original_file );
			$converted  = $this->processor->convert( $original_file, $mime_type, $attachment_id );
			$final_file = false !== $converted ? $converted : $original_file;

			$after        = file_exists( $final_file ) ? (int) filesize( $final_file ) : $before;
			$main_after   = $after;
			$total_after += $after;

			if ( $converted && $converted !== $original_file ) {
				$original_converted = true;
				$original_new_file  = $converted;
				$this->update_attachment_database_refs( $attachment_id, $original_file, $converted );
				$url_pairs[ str_replace( '\\', '/', $metadata['file'] ) ] = $rel_dir . basename( $converted );
			}
		}

		// Avec keep_original, convert() laisse la source à côté du converti.
		// Les clés de $url_pairs sont justement les anciens chemins.
		$this->record_fallbacks( $attachment_id, array_keys( $url_pairs ), $base_path );

		// Un fichier renommé est un lien cassé partout où son URL a déjà été
		// insérée : on réécrit dans le même traitement. En lot (bulk), les
		// paires sont accumulées et réécrites en une fois par flush.
		if ( $url_pairs ) {
			if ( $this->defer_url_rewrites ) {
				$this->pending_url_pairs += $url_pairs;
			} else {
				( new UrlRewriter() )->rewrite( $url_pairs );
			}
		}

		// --- Mise à jour des métadonnées WP ---
		if ( $original_converted ) {
			$metadata = $this->update_metadata_after_conversion( $metadata, $original_file, $original_new_file, $size_updates );
		} elseif ( ! empty( $size_updates ) ) {
			$metadata = $this->update_metadata_after_conversion( $metadata, '', '', $size_updates );
		}

		$metadata = $this->refresh_metadata_filesizes( $metadata, $base_path );

		// --- Stats et marquage ---
		if ( $total_before > 0 ) {
			$bytes_saved = max( $total_before - $total_after, 0 );
			$this->update_stats( $bytes_saved, $total_before );

			$final_mime = $original_converted
				? $this->processor->get_mime_type( $original_new_file )
				: $mime_type;

			$final_path = $original_converted ? $original_new_file : $original_file;
			$this->mark_attachment_optimized(
				$attachment_id,
				$total_before,
				$total_after,
				$final_path,
				$final_mime,
				$main_before,
				$main_after
			);
		}

		return $metadata;
	}

	/**
	 * Optimise et convertit les miniatures d'un attachment.
	 *
	 * @param array<string, mixed> $metadata
	 * @return array{before: int, after: int, updates: array<string, array<string, string>>, url_pairs: array<string, string>}
	 */
	private function process_sizes( array $metadata, string $mime_type, string $sizes_path, string $rel_dir ): array {
		$result = [
			'before'    => 0,
			'after'     => 0,
			'updates'   => [],
			'url_pairs' => [],
		];

		foreach ( $metadata['sizes'] ?? [] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}

			$size_file = $sizes_path . $size_data['file'];
			if ( ! file_exists( $size_file ) ) {
				continue;
			}

			$before            = (int) filesize( $size_file );
			$result['before'] += $before;

			$this->processor->optimize( $size_file );
			$converted  = $this->processor->convert( $size_file, $mime_type );
			$final_file = false !== $converted ? $converted : $size_file;

			$result['after'] += file_exists( $final_file ) ? (int) filesize( $final_file ) : $before;

			if ( $converted && $converted !== $size_file ) {
				$result['updates'][ $size ]                           = [
					'file' => $converted,
					'mime' => $this->processor->get_mime_type( $converted ),
				];
				$result['url_pairs'][ $rel_dir . $size_data['file'] ] = $rel_dir . basename( $converted );
			}
		}

		return $result;
	}

	/**
	 * Accumule les réécritures d'URL au lieu de les exécuter une par une.
	 * À appeler avant chaque image d'un lot ; flush_url_rewrites() les vide.
	 */
	public function begin_deferred_url_rewrites(): void {
		$this->defer_url_rewrites = true;
	}

	/**
	 * Réécrit en une seule passe tout ce qu'un lot a accumulé.
	 */
	public function flush_url_rewrites(): void {
		$this->defer_url_rewrites = false;
		if ( ! $this->pending_url_pairs ) {
			return;
		}
		$pairs                   = $this->pending_url_pairs;
		$this->pending_url_pairs = [];
		( new UrlRewriter() )->rewrite( $pairs );
	}

	/* ================================================================
	 * ACTIONS SUR UN MÉDIA (panneau de la fiche)
	 * ================================================================ */

	/**
	 * Chemin absolu de l'original conservé, '' s'il n'y en a pas.
	 */
	public function get_backup_path( int $attachment_id ): string {
		$rel = (string) get_post_meta( $attachment_id, '_lumia_backup_file', true );

		// Le chemin finit dans copy() et wp_delete_file() : pas de « .. ».
		if ( '' === $rel || 0 !== validate_file( $rel ) ) {
			return '';
		}

		$path = $this->get_backup_dir() . '/' . $rel;

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Dossier des originaux : uploads/lumia-originals-{jeton}.
	 *
	 * Les originaux gardent leurs EXIF (GPS compris), et uploads/ est servi
	 * tel quel : un nom fixe rendrait chaque copie devinable depuis l'URL
	 * publique de l'image. Le .htaccess ne protège que sous Apache (nginx
	 * l'ignore) ; c'est le jeton aléatoire, propre au site, qui protège.
	 */
	private function get_backup_dir(): string {
		$key   = $this->get_module_option_key() . self::BACKUP_TOKEN_SUFFIX;
		$token = (string) get_option( $key, '' );
		if ( '' === $token ) {
			$token = strtolower( wp_generate_password( 24, false ) );
			update_option( $key, $token, false );
		}

		return trailingslashit( wp_upload_dir()['basedir'] ) . self::BACKUP_DIR . '-' . $token;
	}

	/**
	 * Copie le fichier principal intact dans lumia-originals/, une seule fois.
	 *
	 * @param string $rel Chemin relatif au dossier uploads (metadata['file']).
	 */
	private function backup_original( int $attachment_id, string $file, string $rel ): void {
		if ( ! $this->settings['keep_original'] || '' !== $this->get_backup_path( $attachment_id ) || 0 !== validate_file( $rel ) ) {
			return;
		}

		$dir  = $this->get_backup_dir();
		$dest = $dir . '/' . $rel;

		if ( ! wp_mkdir_p( dirname( $dest ) ) || ! copy( $file, $dest ) ) {
			return;
		}

		// Pas de listage du dossier, et refus d'accès sous Apache.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fichier local créé une fois.
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- idem.
		}

		update_post_meta( $attachment_id, '_lumia_backup_file', $rel );
	}

	/**
	 * Mémorise les fichiers source restés sur le disque après conversion.
	 *
	 * L'extension d'origine n'est plus connue une fois les métadonnées
	 * réécrites : c'est maintenant ou jamais.
	 *
	 * @param string[] $rels Chemins relatifs au dossier uploads.
	 */
	private function record_fallbacks( int $attachment_id, array $rels, string $base_path ): void {
		$rels = array_filter(
			$rels,
			static function ( string $rel ) use ( $base_path ): bool {
				return 0 === validate_file( $rel ) && file_exists( $base_path . $rel );
			}
		);
		if ( ! $rels ) {
			return;
		}

		$known = get_post_meta( $attachment_id, self::FALLBACK_META, true );
		$known = is_array( $known ) ? $known : [];

		update_post_meta( $attachment_id, self::FALLBACK_META, array_values( array_unique( array_merge( $known, $rels ) ) ) );
	}

	/**
	 * Hook delete_attachment : supprime l'original conservé et les fichiers
	 * de repli du média.
	 */
	public function delete_kept_files( int $attachment_id ): void {
		$backup = $this->get_backup_path( $attachment_id );
		if ( '' !== $backup ) {
			wp_delete_file( $backup );
		}

		$rels = get_post_meta( $attachment_id, self::FALLBACK_META, true );
		if ( ! is_array( $rels ) || ! $rels ) {
			return;
		}

		$rels = array_values( array_filter( $rels, 'is_string' ) );
		if ( ! $rels ) {
			return;
		}

		// Supprimé à la main puis re-téléversé, un fichier de repli peut être
		// devenu le fichier d'un autre média, ou son propre repli : on ne
		// touche pas à ce qui appartient à un autre.
		global $wpdb;
		$like  = implode( ' OR ', array_fill( 0, count( $rels ), 'meta_value LIKE %s' ) );
		$other = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- suppression ponctuelle, rien à mettre en cache.
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id <> %d AND ( ( meta_key = '_wp_attached_file' AND meta_value IN (" . implode( ',', array_fill( 0, count( $rels ), '%s' ) ) . ") ) OR ( meta_key = %s AND ( {$like} ) ) )", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- un %s par chemin.
				array_merge(
					[ $attachment_id ],
					$rels,
					[ self::FALLBACK_META ],
					array_map(
						static function ( string $rel ) use ( $wpdb ): string {
							return '%' . $wpdb->esc_like( '"' . $rel . '"' ) . '%';
						},
						$rels
					)
				)
			)
		);

		$taken = [];
		foreach ( $other as $row ) {
			// Le fichier principal d'un autre média : ses tailles portent le
			// même nom de base, aucun de nos replis n'est sûr.
			if ( '_wp_attached_file' === $row->meta_key ) {
				return;
			}
			$list  = maybe_unserialize( $row->meta_value );
			$taken = array_merge( $taken, is_array( $list ) ? $list : [] );
		}

		$base_path = trailingslashit( wp_upload_dir()['basedir'] );
		foreach ( array_diff( $rels, $taken ) as $rel ) {
			if ( 0 === validate_file( $rel ) ) {
				wp_delete_file( $base_path . $rel );
			}
		}
	}

	/**
	 * Ré-optimise un média avec les réglages actuels, éventuellement vers un
	 * autre format.
	 *
	 * Repart de l'original conservé s'il existe : sinon chaque passage
	 * recompresse une image déjà compressée.
	 *
	 * @param string $format '' (réglages) ou 'webp' / 'avif'.
	 */
	public function reprocess_attachment( int $attachment_id, string $format = '' ): ?\WP_Error {
		if ( '' !== $format ) {
			$cap = $this->processor->get_capabilities();
			if ( ! in_array( $format, [ 'webp', 'avif' ], true ) || empty( $cap[ $format ] ) ) {
				return new \WP_Error( 'lumia_format', __( 'Ce format n\'est pas disponible sur ce serveur.', 'lumia-tools' ) );
			}
		}

		$previous = strtolower( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );

		if ( '' !== $this->get_backup_path( $attachment_id ) ) {
			$error = $this->restore_original( $attachment_id, true );
			if ( $error ) {
				return $error;
			}
		} else {
			// Les métas restent : le média est toujours « optimisé », et
			// process_attachment_metadata() ne sauvegarde pas sa version
			// dégradée comme s'il s'agissait d'un original.
			$this->unrecord_stats( $attachment_id );
		}

		$this->with_format(
			$format,
			function () use ( $attachment_id ): void {
				$this->process_and_update_attachment( $attachment_id, true );
			}
		);

		// convert() ne garde le format demandé que s'il allège l'image. Parti
		// de l'original, un WebP converti en vain vers l'AVIF retomberait en
		// JPEG : on le ré-encode dans son format précédent.
		$result = strtolower( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_EXTENSION ) );
		if ( '' !== $format && $result !== $format && $result !== $previous
			&& in_array( $previous, [ 'webp', 'avif' ], true ) && ! empty( $this->processor->get_capabilities()[ $previous ] ) ) {
			return $this->reprocess_attachment( $attachment_id, $previous );
		}

		return null;
	}

	/**
	 * Exécute $callback avec un format de conversion imposé ('' : réglages).
	 */
	private function with_format( string $format, callable $callback ): void {
		$processor = $this->processor;
		if ( '' !== $format ) {
			$this->processor = new ImageProcessor( array_merge( $this->settings, [ 'format_mode' => $format ] ) );
		}

		try {
			$callback();
		} finally {
			$this->processor = $processor;
		}
	}

	/**
	 * Recrée les miniatures depuis le fichier principal, puis les optimise
	 * si le média l'est.
	 */
	public function regenerate_thumbnails( int $attachment_id ): ?\WP_Error {
		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$file         = (string) get_attached_file( $attachment_id );

		if ( ! is_array( $old_metadata ) || '' === $file || ! file_exists( $file ) ) {
			return new \WP_Error( 'lumia_missing', __( 'Fichier introuvable.', 'lumia-tools' ) );
		}

		$metadata = $this->generate_metadata( $attachment_id, $file, $old_metadata );
		if ( null === $metadata ) {
			return new \WP_Error( 'lumia_regenerate', __( 'La génération des miniatures a échoué.', 'lumia-tools' ) );
		}

		if ( $this->is_already_optimized( $attachment_id ) ) {
			$subdir  = dirname( $metadata['file'] );
			$rel_dir = ( '.' === $subdir || '' === $subdir ) ? '' : trailingslashit( str_replace( '\\', '/', $subdir ) );

			// Les miniatures suivent le format du fichier principal : après une
			// conversion en WebP depuis la fiche, les réglages (AVIF, auto…)
			// donneraient un principal WebP et des miniatures AVIF.
			$format = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$this->with_format(
				in_array( $format, [ 'webp', 'avif' ], true ) ? $format : '',
				function () use ( &$metadata, $file, $rel_dir, $attachment_id ): void {
					$base_path = trailingslashit( wp_upload_dir()['basedir'] );
					$sizes     = $this->process_sizes( $metadata, $this->processor->get_mime_type( $file ), $base_path . $rel_dir, $rel_dir );
					$metadata  = $this->update_metadata_after_conversion( $metadata, '', '', $sizes['updates'] );
					$this->record_fallbacks( $attachment_id, array_keys( $sizes['url_pairs'] ), $base_path );
				}
			);
		}

		$metadata = $this->replace_metadata( $attachment_id, $old_metadata, $metadata );

		if ( $this->is_already_optimized( $attachment_id ) ) {
			$this->refresh_attachment_totals( $attachment_id, $metadata );
		}

		return null;
	}

	/**
	 * Remet l'original conservé à la place des versions optimisées.
	 *
	 * @param bool $keep_backup Garder la copie (ré-optimisation depuis
	 *                          l'original) ou la supprimer (restauration).
	 */
	public function restore_original( int $attachment_id, bool $keep_backup = false ): ?\WP_Error {
		$backup       = $this->get_backup_path( $attachment_id );
		$old_metadata = wp_get_attachment_metadata( $attachment_id );

		if ( '' === $backup || ! is_array( $old_metadata ) || empty( $old_metadata['file'] ) ) {
			return new \WP_Error( 'lumia_no_backup', __( 'Aucun original conservé pour ce média.', 'lumia-tools' ) );
		}

		$target  = trailingslashit( wp_upload_dir()['basedir'] ) . get_post_meta( $attachment_id, '_lumia_backup_file', true );
		$current = (string) get_attached_file( $attachment_id );

		if ( ! copy( $backup, $target ) ) {
			return new \WP_Error( 'lumia_restore', __( 'Impossible de recopier l\'original.', 'lumia-tools' ) );
		}

		if ( wp_normalize_path( $current ) !== wp_normalize_path( $target ) ) {
			$this->update_attachment_database_refs( $attachment_id, $current, $target );
		}

		$metadata = $this->generate_metadata( $attachment_id, $target, $old_metadata );
		if ( null === $metadata ) {
			return new \WP_Error( 'lumia_regenerate', __( 'La génération des miniatures a échoué.', 'lumia-tools' ) );
		}

		$this->replace_metadata( $attachment_id, $old_metadata, $metadata );

		$this->unrecord_stats( $attachment_id );
		foreach ( self::OPTIMIZATION_META as $key ) {
			delete_post_meta( $attachment_id, $key );
		}

		// Les fichiers de repli sont redevenus les fichiers du média, ou ont
		// été écrasés : une liste périmée finirait par viser ceux d'un autre.
		delete_post_meta( $attachment_id, self::FALLBACK_META );

		if ( ! $keep_backup ) {
			wp_delete_file( $backup );
			delete_post_meta( $attachment_id, '_lumia_backup_file' );
		}

		return null;
	}

	/**
	 * Métadonnées WordPress recalculées depuis $file, sans notre traitement.
	 *
	 * @param array<string, mixed> $old_metadata
	 * @return array<string, mixed>|null
	 */
	private function generate_metadata( int $attachment_id, string $file, array $old_metadata ): ?array {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Grande image : WordPress tire les miniatures de l'original d'avant
		// « -scaled » (photo-150x150.jpg), pas du fichier réduit
		// (photo-scaled-150x150.jpg). On fait de même, sinon chaque miniature
		// change de nom et toute URL hors base (cache, CDN, e-mail) casse.
		$original = empty( $old_metadata['original_image'] ) ? '' : path_join( dirname( $file ), $old_metadata['original_image'] );
		if ( '' !== $original && file_exists( $original ) ) {
			$metadata          = $old_metadata;
			$metadata['file']  = _wp_relative_upload_path( $file );
			$metadata['sizes'] = [];
			$dimensions        = wp_getimagesize( $file );
			if ( $dimensions ) {
				$metadata['width']  = $dimensions[0];
				$metadata['height'] = $dimensions[1];
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur, appliqué comme dans wp_create_image_subsizes().
			$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $metadata, $attachment_id );

			return _wp_make_subsizes( $sizes, $original, $metadata, $attachment_id );
		}

		// Sans notre filtre : l'appelant décide de ce qui s'optimise. Sans le
		// seuil « big image » : le fichier principal est déjà le bon, WordPress
		// en ferait sinon un « -scaled » de plus.
		remove_filter( 'wp_generate_attachment_metadata', [ $this, 'optimize_attachment_sizes' ], 10 );
		add_filter( 'big_image_size_threshold', '__return_false', 999 );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

		remove_filter( 'big_image_size_threshold', '__return_false', 999 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'optimize_attachment_sizes' ], 10, 2 );

		if ( empty( $metadata['file'] ) ) {
			return null;
		}

		return $metadata;
	}

	/**
	 * Enregistre les nouvelles métadonnées, supprime les fichiers qui n'y
	 * figurent plus et réécrit les URL des fichiers renommés.
	 *
	 * @param array<string, mixed> $old_metadata
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function replace_metadata( int $attachment_id, array $old_metadata, array $metadata ): array {
		$base_path = trailingslashit( wp_upload_dir()['basedir'] );
		$old_files = $this->metadata_files( $old_metadata );
		$new_files = $this->metadata_files( $metadata );

		// Même clé (fichier principal, taille « medium »…) : ancien → nouveau.
		$url_pairs = [];
		foreach ( $old_files as $key => $rel ) {
			if ( isset( $new_files[ $key ] ) && $new_files[ $key ] !== $rel ) {
				$url_pairs[ $rel ] = $new_files[ $key ];
			}
		}

		$kept = array_flip( $new_files );
		foreach ( $old_files as $rel ) {
			if ( ! isset( $kept[ $rel ] ) ) {
				wp_delete_file( $base_path . $rel );
			}
		}

		if ( $url_pairs ) {
			( new UrlRewriter() )->rewrite( $url_pairs );
		}

		$metadata = $this->refresh_metadata_filesizes( $metadata, $base_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Fichiers d'un attachment, relatifs au dossier uploads, indexés par rôle
	 * ('' pour le principal, nom de la taille sinon).
	 *
	 * @param array<string, mixed> $metadata
	 * @return array<string, string>
	 */
	private function metadata_files( array $metadata ): array {
		if ( empty( $metadata['file'] ) ) {
			return [];
		}

		$main    = str_replace( '\\', '/', $metadata['file'] );
		$subdir  = dirname( $main );
		$rel_dir = '.' === $subdir ? '' : trailingslashit( $subdir );
		$files   = [ '' => $main ];

		foreach ( $metadata['sizes'] ?? [] as $size => $size_data ) {
			if ( ! empty( $size_data['file'] ) ) {
				$files[ (string) $size ] = $rel_dir . $size_data['file'];
			}
		}

		return $files;
	}

	/**
	 * Recalcule le poids final d'un média optimisé après régénération de ses
	 * miniatures, et reporte l'écart dans les statistiques globales.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function refresh_attachment_totals( int $attachment_id, array $metadata ): void {
		$after = (int) ( $metadata['filesize'] ?? 0 );
		foreach ( $metadata['sizes'] ?? [] as $size_data ) {
			$after += (int) ( $size_data['filesize'] ?? 0 );
		}

		$original  = (int) get_post_meta( $attachment_id, '_lumia_original_bytes', true );
		$old_saved = (int) get_post_meta( $attachment_id, '_lumia_bytes_saved', true );
		$new_saved = max( $original - $after, 0 );

		update_post_meta( $attachment_id, '_lumia_optimized_bytes', $after );
		update_post_meta( $attachment_id, '_lumia_bytes_saved', $new_saved );

		$stats                = $this->get_raw_stats();
		$stats['bytes_saved'] = max( $stats['bytes_saved'] - $old_saved + $new_saved, 0 );
		update_option( $this->get_stats_key(), $stats, false );
	}

	/**
	 * Retire un média optimisé des statistiques globales, avant de le
	 * retraiter ou de le restaurer : sinon il compterait deux fois.
	 */
	private function unrecord_stats( int $attachment_id ): void {
		if ( ! $this->is_already_optimized( $attachment_id ) ) {
			return;
		}

		$stats                   = $this->get_raw_stats();
		$stats['optimized']      = max( $stats['optimized'] - 1, 0 );
		$stats['bytes_saved']    = max( $stats['bytes_saved'] - (int) get_post_meta( $attachment_id, '_lumia_bytes_saved', true ), 0 );
		$stats['original_bytes'] = max( $stats['original_bytes'] - (int) get_post_meta( $attachment_id, '_lumia_original_bytes', true ), 0 );
		update_option( $this->get_stats_key(), $stats, false );
	}

	/* ================================================================
	 * ALT TEXT
	 * ================================================================ */

	/**
	 * Génère automatiquement le texte alternatif depuis le nom de fichier.
	 */
	public function generate_alt_text( int $attachment_id ): void {
		if ( ! $this->settings['generate_alt'] ) {
			return;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			return;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$filename = pathinfo( $file, PATHINFO_FILENAME );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $this->processor->filename_to_alt( $filename ) );
	}

	/* ================================================================
	 * STATS
	 * ================================================================ */

	private function get_stats_key(): string {
		return $this->get_module_option_key() . self::STATS_SUFFIX;
	}

	/**
	 * Compteurs globaux tels qu'enregistrés (sans les capacités serveur).
	 *
	 * @return array{optimized: int, bytes_saved: int, original_bytes: int}
	 */
	private function get_raw_stats(): array {
		$stats = (array) get_option( $this->get_stats_key(), [] );

		return [
			'optimized'      => (int) ( $stats['optimized'] ?? 0 ),
			'bytes_saved'    => (int) ( $stats['bytes_saved'] ?? 0 ),
			'original_bytes' => (int) ( $stats['original_bytes'] ?? 0 ),
		];
	}

	private function update_stats( int $bytes_saved, int $original_bytes ): void {
		$stats = $this->get_raw_stats();

		++$stats['optimized'];
		$stats['bytes_saved']    += max( $bytes_saved, 0 );
		$stats['original_bytes'] += max( $original_bytes, 0 );

		update_option( $this->get_stats_key(), $stats, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_stats(): array {
		return array_merge(
			$this->get_raw_stats(),
			[
				'capabilities' => $this->processor->get_capabilities(),
			]
		);
	}

	/**
	 * Estimation des gains pour le bulk (utilisée par le template de réglages).
	 *
	 * @return array<string, mixed>
	 */
	public function get_bulk_preview(): array {
		return $this->bulk->get_preview();
	}

	/* ================================================================
	 * META ATTACHMENTS
	 * ================================================================ */

	public function is_already_optimized( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, '_lumia_optimized', true );
	}

	private function mark_attachment_optimized(
		int $attachment_id,
		int $original_bytes,
		int $optimized_bytes,
		string $final_file,
		string $final_mime,
		int $main_original = 0,
		int $main_optimized = 0
	): void {
		$bytes_saved      = max( $original_bytes - $optimized_bytes, 0 );
		$main_bytes_saved = max( $main_original - $main_optimized, 0 );
		$format           = strtolower( pathinfo( $final_file, PATHINFO_EXTENSION ) );

		update_post_meta( $attachment_id, '_lumia_optimized', time() );
		update_post_meta( $attachment_id, '_lumia_original_bytes', $original_bytes );
		update_post_meta( $attachment_id, '_lumia_optimized_bytes', $optimized_bytes );
		update_post_meta( $attachment_id, '_lumia_bytes_saved', $bytes_saved );
		update_post_meta( $attachment_id, '_lumia_main_original_bytes', $main_original );
		update_post_meta( $attachment_id, '_lumia_main_optimized_bytes', $main_optimized );
		update_post_meta( $attachment_id, '_lumia_main_bytes_saved', $main_bytes_saved );
		update_post_meta( $attachment_id, '_lumia_optimized_format', $format );
		update_post_meta( $attachment_id, '_lumia_optimized_mime', $final_mime );
	}

	/* ================================================================
	 * MÉTADONNÉES WP
	 * ================================================================ */

	private function update_attachment_database_refs( int $attachment_id, string $old_file, string $new_file ): void {
		update_attached_file( $attachment_id, $new_file );

		$mime = $this->processor->get_mime_type( $new_file );
		if ( $mime ) {
			wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $mime,
				]
			);
		}

		// Le guid porte l'URL d'origine du fichier ; certains outils le lisent
		// comme URL. wp_update_post() ne le réécrit pas sur une mise à jour :
		// on passe par $wpdb, puis on purge le cache de l'objet.
		global $wpdb;
		$guid = (string) get_post_field( 'guid', $attachment_id );
		if ( '' !== $guid && false !== strpos( $guid, basename( $old_file ) ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->posts, [ 'guid' => str_replace( basename( $old_file ), basename( $new_file ), $guid ) ], [ 'ID' => $attachment_id ] );
			clean_post_cache( $attachment_id );
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @param array<string, array<string, string>> $size_updates
	 * @return array<string, mixed>
	 */
	private function update_metadata_after_conversion( array $metadata, string $old_file, string $new_file, array $size_updates ): array {
		if ( $old_file && $new_file && ! empty( $metadata['file'] ) ) {
			$old_info         = pathinfo( $old_file );
			$new_info         = pathinfo( $new_file );
			$metadata['file'] = str_replace( $old_info['basename'], $new_info['basename'], $metadata['file'] );
		}

		if ( ! empty( $metadata['sizes'] ) && ! empty( $size_updates ) ) {
			foreach ( $size_updates as $size => $update ) {
				if ( empty( $metadata['sizes'][ $size ] ) ) {
					continue;
				}
				$metadata['sizes'][ $size ]['file']      = basename( $update['file'] );
				$metadata['sizes'][ $size ]['mime-type'] = $update['mime'] ?? $metadata['sizes'][ $size ]['mime-type'];
			}
		}

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function refresh_metadata_filesizes( array $metadata, string $base_path ): array {
		if ( ! empty( $metadata['file'] ) ) {
			$original_path = $base_path . $metadata['file'];
			if ( file_exists( $original_path ) ) {
				$metadata['filesize'] = (int) filesize( $original_path );
			}
		}

		if ( empty( $metadata['sizes'] ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		$subdir          = dirname( $metadata['file'] );
		$sizes_base_path = trailingslashit( $base_path . $subdir );

		foreach ( $metadata['sizes'] as $size => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}
			$size_path = $sizes_base_path . $size_data['file'];
			if ( file_exists( $size_path ) ) {
				$metadata['sizes'][ $size ]['filesize'] = (int) filesize( $size_path );
			}
		}

		return $metadata;
	}

	/* ================================================================
	 * DÉLÉGATION BULK (hooks cron + AJAX)
	 * ================================================================ */

	public function ajax_bulk_scan(): void {
		$this->bulk->ajax_scan();
	}

	public function ajax_bulk_start(): void {
		$this->bulk->ajax_start( self::BATCH_SIZE );
	}

	public function ajax_bulk_status(): void {
		$this->bulk->ajax_status( self::BATCH_SIZE );
	}

	public function run_cron_batch(): void {
		$this->bulk->run_batch( self::BATCH_SIZE );
	}

	/* ================================================================
	 * COMPATIBILITÉ : capacités serveur (utilisées dans le template réglages)
	 * ================================================================ */

	/**
	 * @return array<string, bool|string>
	 */
	public function get_capabilities(): array {
		return $this->processor->get_capabilities();
	}
}
