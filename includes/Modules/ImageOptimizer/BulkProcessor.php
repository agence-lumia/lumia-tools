<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Gère le workflow d'optimisation en masse :
 * AJAX start/status, traitement par batch, planification cron.
 *
 * Reçoit ses dépendances via closures pour éviter le couplage circulaire.
 */
class BulkProcessor {

	private const CRON_HOOK = 'lumia_image_optimizer_cron';

	/**
	 * Clé WordPress pour persister l'état du bulk.
	 */
	private string $state_key;

	/**
	 * Callable : traite un attachment (optimise + alt text).
	 * Signature : function( int $attachment_id ): void
	 */
	private \Closure $process_fn;

	/**
	 * Callable : retourne les stats globales pour estimer les gains.
	 * Signature : function(): array
	 */
	private \Closure $get_stats_fn;

	/**
	 * Callable optionnel : appelé une fois quand le bulk passe de "running" à "terminé".
	 * Signature : function( int $user_id ): void
	 */
	private ?\Closure $on_complete_fn;

	/**
	 * Callable optionnel : appelé après chaque lot, que ses images aient
	 * réussi ou non. Le module y réécrit les URLs accumulées du lot.
	 * Signature : function(): void
	 */
	private ?\Closure $after_batch_fn;

	public function __construct(
		string $state_key,
		\Closure $process_fn,
		\Closure $get_stats_fn,
		?\Closure $on_complete_fn = null,
		?\Closure $after_batch_fn = null
	) {
		$this->state_key      = $state_key;
		$this->process_fn     = $process_fn;
		$this->get_stats_fn   = $get_stats_fn;
		$this->on_complete_fn = $on_complete_fn;
		$this->after_batch_fn = $after_batch_fn;
	}

	/* ================================================================
	 * AJAX HANDLERS
	 * ================================================================ */

	/**
	 * Lance ou reprend l'optimisation en masse.
	 */
	public function ajax_start( int $batch_size ): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		$state = $this->get_state();

		if ( ! $state['running'] ) {
			$total = $this->count_unoptimized();
			$state = [
				'running'    => $total > 0,
				'total'      => $total,
				'processed'  => 0,
				'remaining'  => $total,
				'updated_at' => time(),
				'user_id'    => get_current_user_id(),
			];
			$this->set_state( $state );
		}

		if ( $state['running'] ) {
			$this->run_batch( $batch_size );
			$this->schedule_next( $batch_size );
			$state = $this->get_state();
		}

		$preview = $this->get_preview();

		wp_send_json_success(
			[
				'processed'             => $state['processed'],
				'remaining'             => $state['remaining'],
				'done'                  => 0 === $state['remaining'] && ! $state['running'],
				'running'               => $state['running'],
				'total'                 => $state['total'],
				'estimated_bytes_saved' => (int) ( $preview['estimated_bytes_saved'] ?? 0 ),
			]
		);
	}

	/**
	 * Scan à la demande : compte les images restantes et estime les gains,
	 * sans rien lancer. Alimente l'UI « Scanner la médiathèque ».
	 */
	public function ajax_scan(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		$preview = $this->get_preview();

		wp_send_json_success(
			[
				'remaining'             => (int) ( $preview['remaining'] ?? 0 ),
				'estimated_bytes_saved' => (int) ( $preview['estimated_bytes_saved'] ?? 0 ),
				'avg_saved_per_image'   => (int) ( $preview['avg_saved_per_image'] ?? 0 ),
			]
		);
	}

	/**
	 * Retourne l'état courant du bulk (polling JS).
	 */
	public function ajax_status( int $batch_size ): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		$state = $this->get_state();

		// Relance un batch si le cron est en retard (> 20 s sans mise à jour).
		if ( $state['running'] && $state['updated_at'] && ( time() - (int) $state['updated_at'] ) > 20 ) {
			$this->run_batch( $batch_size );
			$state = $this->get_state();
		}

		$preview = $this->get_preview();

		wp_send_json_success(
			[
				'remaining'             => $state['remaining'],
				'processed'             => $state['processed'],
				'done'                  => 0 === $state['remaining'] && ! $state['running'],
				'running'               => $state['running'],
				'total'                 => $state['total'],
				'estimated_bytes_saved' => (int) ( $preview['estimated_bytes_saved'] ?? 0 ),
			]
		);
	}

	/* ================================================================
	 * TRAITEMENT PAR BATCH
	 * ================================================================ */

	/**
	 * Traite un lot d'images non optimisées.
	 * Appelé via cron WP ou directement depuis les handlers AJAX.
	 */
	public function run_batch( int $batch_size ): void {
		$state = $this->get_state();

		if ( ! $state['running'] ) {
			return;
		}

		$ids = $this->get_unoptimized_ids( $batch_size );

		if ( empty( $ids ) ) {
			$state['running']    = false;
			$state['remaining']  = 0;
			$state['updated_at'] = time();
			$this->set_state( $state );
			if ( $this->on_complete_fn ) {
				( $this->on_complete_fn )( (int) ( $state['user_id'] ?? 0 ) );
			}
			return;
		}

		$processed_now = 0;

		foreach ( $ids as $attachment_id ) {
			try {
				( $this->process_fn )( $attachment_id );
			} catch ( \Throwable $e ) {
				// Un attachment en erreur ne bloque pas les suivants, mais on le signale.
				error_log( sprintf( '[LUMIA Image Optimizer] traitement en lot : attachment %d en erreur : %s', $attachment_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- journal d'erreur volontaire, sans interface pour l'afficher.
			}
			++$processed_now;
		}

		// Toujours, même après une erreur : ce qui a été converti avant doit
		// voir ses URLs réécrites.
		if ( $this->after_batch_fn ) {
			( $this->after_batch_fn )();
		}

		$state['processed'] += $processed_now;
		$state['remaining']  = max( $state['remaining'] - $processed_now, 0 );
		$state['updated_at'] = time();

		if ( 0 === $state['remaining'] ) {
			$state['running'] = false;
		}

		$this->set_state( $state );

		if ( $state['running'] ) {
			$this->schedule_next( $batch_size );
		} elseif ( $this->on_complete_fn ) {
			( $this->on_complete_fn )( (int) ( $state['user_id'] ?? 0 ) );
		}
	}

	/* ================================================================
	 * ÉTAT ET PRÉVISUALISATION
	 * ================================================================ */

	/**
	 * Retourne l'état courant du bulk avec ses valeurs par défaut.
	 *
	 * @return array<string, mixed>
	 */
	public function get_state(): array {
		$stored   = get_option( $this->state_key, [] );
		$defaults = [
			'running'    => false,
			'total'      => 0,
			'processed'  => 0,
			'remaining'  => 0,
			'updated_at' => 0,
			'user_id'    => 0,
		];
		return wp_parse_args( is_array( $stored ) ? $stored : [], $defaults );
	}

	/**
	 * Calcule une estimation des gains potentiels du bulk.
	 *
	 * @return array<string, mixed>
	 */
	public function get_preview(): array {
		$state     = $this->get_state();
		$remaining = (int) $state['remaining'];

		if ( 0 === $remaining && ! $state['running'] ) {
			$remaining = $this->count_unoptimized();
		}

		$stats     = ( $this->get_stats_fn )();
		$avg_saved = 0;

		if ( ! empty( $stats['optimized'] ) ) {
			$avg_saved = (int) floor( (int) $stats['bytes_saved'] / max( 1, (int) $stats['optimized'] ) );
		}

		return [
			'remaining'             => $remaining,
			'estimated_bytes_saved' => max( 0, $avg_saved * $remaining ),
			'avg_saved_per_image'   => $avg_saved,
		];
	}

	/* ================================================================
	 * REQUÊTES MÉDIATHÈQUE
	 * ================================================================ */

	/**
	 * Compte les images sans le meta _lumia_optimized (accurate count).
	 */
	public function count_unoptimized(): int {
		$query = new \WP_Query(
			array_merge(
				$this->base_query_args(),
				[
					'posts_per_page' => 1,
					'no_found_rows'  => false,
				]
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Retourne un lot d'IDs non optimisés.
	 *
	 * @return int[]
	 */
	private function get_unoptimized_ids( int $limit ): array {
		$query = new \WP_Query(
			array_merge(
				$this->base_query_args(),
				[
					'posts_per_page'         => $limit,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				]
			)
		);

		// fields => ids : WP_Query renvoie des entiers, mais son type déclaré reste int|WP_Post.
		return array_map(
			static fn( $post ): int => $post instanceof \WP_Post ? (int) $post->ID : (int) $post,
			(array) $query->posts
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function base_query_args(): array {
		return [
			'post_type'      => 'attachment',
			'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ],
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- repérer les images non optimisées n'a pas d'alternative sans index dédié.
			'meta_query'     => [
				[
					'key'     => '_lumia_optimized',
					'compare' => 'NOT EXISTS',
				],
			],
		];
	}

	/* ================================================================
	 * PERSISTENCE ET CRON
	 * ================================================================ */

	/**
	 * @param array<string, mixed> $state
	 */
	private function set_state( array $state ): void {
		update_option( $this->state_key, $state, false );
	}

	private function schedule_next( int $batch_size ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, [ $batch_size ] ) ) {
			wp_schedule_single_event( time() + 2, self::CRON_HOOK, [ $batch_size ] );
		}
	}
}
