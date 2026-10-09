<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Updater GitHub pour le plugin.
 * Vérifie les mises à jour depuis un dépôt GitHub.
 */
class Updater {

	/**
	 * Utilisateur GitHub.
	 */
	private string $github_user = 'agence-lumia';

	/**
	 * Nom du dépôt GitHub.
	 */
	private string $github_repo = 'lumia-tools';

	/**
	 * Transient pour le cache des mises à jour.
	 */
	private string $transient_key = 'lumia_github_update';

	/**
	 * Canal de mise a jour.
	 */
	private string $channel = 'stable';

	/**
	 * Durée du cache (12 heures).
	 */
	private int $cache_duration = 43200;

	/**
	 * Durée du cache en cas d'échec (15 minutes).
	 *
	 * Sans cache négatif, une panne réseau ou un quota GitHub dépassé (60
	 * requêtes/heure en anonyme) relance un appel HTTP à 10 s de timeout à
	 * CHAQUE vérification de mise à jour — donc, en pratique, à chaque
	 * chargement d'écran d'administration.
	 */
	private int $failure_cache_duration = 900;

	/** Sentinelle stockée dans le transient pour mémoriser un échec. */
	private const FAILURE_MARKER = 'lumia_update_check_failed';

	/** Nombre maximal de notes de version conservées (canal dev). */
	private const MAX_NOTES = 10;

	/**
	 * Initialise l'updater.
	 */
	public function init(): void {
		$settings = get_option( 'lumia_settings', [] );

		if ( ! empty( $settings['global']['update_channel'] ) ) {
			$this->channel = sanitize_key( $settings['global']['update_channel'] );
		}

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );

		// Après une mise à jour du plugin, purger le cache de version distante
		// (12 h) et le transient WP des mises à jour, sinon la pastille « mise à
		// jour disponible » persiste jusqu'à une vérification manuelle.
		add_action( 'upgrader_process_complete', [ $this, 'purge_cache_after_update' ], 10, 2 );
	}

	/**
	 * Vide les caches de mise à jour après l'installation d'une nouvelle version.
	 *
	 * @param object $upgrader Instance de l'upgrader (non utilisée).
	 * @param array<string, mixed>  $options  Contexte de l'opération.
	 */
	public function purge_cache_after_update( $upgrader, array $options ): void {
		if ( ( $options['action'] ?? '' ) !== 'update' || ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}

		$our_plugin = plugin_basename( LUMIA_PLUGIN_FILE );
		$updated    = (array) ( $options['plugins'] ?? [] );

		if ( ! in_array( $our_plugin, $updated, true ) ) {
			return;
		}

		delete_transient( $this->transient_key . '_stable' );
		delete_transient( $this->transient_key . '_dev' );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Vérifie les mises à jour disponibles.
	 *
	 * @param \stdClass $transient Données du transient.
	 *
	 * @return \stdClass
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $transient;
		}

		$plugin_file = plugin_basename( LUMIA_PLUGIN_FILE );

		// Comparer les versions
		$has_update = $this->compare_versions( LUMIA_VERSION, $remote['version'] );

		$item = (object) [
			'slug'         => dirname( $plugin_file ),
			'plugin'       => $plugin_file,
			'new_version'  => $has_update ? $remote['version'] : LUMIA_VERSION,
			'url'          => $remote['url'],
			'package'      => $remote['download_url'],
			'icons'        => [],
			'banners'      => [],
			// Pas de champ `tested` : le renseigner avec la version courante du
			// site déclarait le plugin testé sur n'importe quelle version.
			'requires'     => '6.9',
			'requires_php' => '8.0',
		];

		if ( $has_update ) {
			$transient->response[ $plugin_file ] = $item;
		} else {
			// Aucune mise à jour, mais on déclare la source connue :
			// WordPress affiche alors la colonne « Mises à jour auto »
			// et peut gérer l'auto-update natif de ce plugin.
			$transient->no_update[ $plugin_file ] = $item;
		}

		return $transient;
	}

	/**
	 * Indique si la version distante est plus récente que la version installée.
	 *
	 * Les pré-versions visent le patch suivant (1.1.0 → 1.1.1-dev.N), donc
	 * l'ordre SemVer de version_compare() suffit : 1.1.0 < 1.1.1-dev.2 <
	 * 1.1.1-dev.10 < 1.1.1.
	 *
	 * @param string $installed_version Version installée.
	 * @param string $remote_version    Version distante.
	 * @return bool True si mise à jour disponible.
	 */
	private function compare_versions( string $installed_version, string $remote_version ): bool {
		return version_compare( $installed_version, $remote_version, '<' );
	}

	/**
	 * Fournit les informations du plugin pour l'écran de détails.
	 *
	 * @param false|object|array<string, mixed> $result Valeur par défaut.
	 * @param string             $action Action demandée.
	 * @param object             $args   Arguments.
	 * @return false|object|array<string, mixed>
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$plugin_file = plugin_basename( LUMIA_PLUGIN_FILE );

		if ( dirname( $plugin_file ) !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$remote = $this->get_remote_version();

		if ( false === $remote ) {
			return $result;
		}

		return (object) [
			'name'           => 'Lümia Tools',
			'slug'           => dirname( $plugin_file ),
			'author'         => '<a href="https://agence-lumia.com">Agence Lümia</a>',
			'author_profile' => 'https://agence-lumia.com',
			'homepage'       => $remote['url'],
			'download_link'  => $remote['download_url'],
			'version'        => $remote['version'],
			'requires'       => '6.9',
			'requires_php'   => '8.0',
			'last_updated'   => $remote['published_at'],
			'sections'       => [
				'description' => __( 'Suite d\'outils modulaires pour optimiser et améliorer votre site WordPress.', 'lumia-tools' ),
				'changelog'   => $this->render_changelog( $remote['notes'] ?? [] ),
			],
		];
	}

	/**
	 * Construit l'onglet « Journal des modifications » de la modale de détails.
	 *
	 * Sur le canal dev, une mise à jour peut sauter plusieurs pré-versions :
	 * on affiche toutes les notes postérieures à la version installée. Si le
	 * site est à jour, on retombe sur les notes de la dernière version.
	 *
	 * @param mixed $notes Notes normalisées (version, date, html).
	 */
	private function render_changelog( $notes ): string {
		if ( ! is_array( $notes ) || [] === $notes ) {
			return '<p>' . esc_html__( 'Aucune note de version disponible.', 'lumia-tools' ) . '</p>';
		}

		$newer = array_filter(
			$notes,
			function ( $note ) {
				return version_compare( LUMIA_VERSION, $note['version'], '<' );
			}
		);

		if ( [] === $newer ) {
			$newer = [ reset( $notes ) ];
		}

		$html = '';

		foreach ( $newer as $note ) {
			$date  = '' !== $note['date'] ? (string) mysql2date( (string) get_option( 'date_format' ), $note['date'] ) : '';
			$html .= '<h4>' . esc_html( 'v' . $note['version'] ) . ( '' !== $date ? ' — ' . esc_html( $date ) : '' ) . '</h4>';
			// HTML rendu par GitHub : filtré ici, puis de nouveau par WordPress
			// à l'affichage de la modale.
			$html .= wp_kses_post( $note['html'] );
		}

		return $html;
	}

	/**
	 * État connu de la mise à jour, sans appel réseau.
	 *
	 * Lit uniquement le cache : le tableau de bord ne doit jamais déclencher un
	 * appel HTTP de 10 s. Cache vide ou échec récent → `remote` à null.
	 *
	 * @return array{channel: string, remote: ?string, has_update: bool}
	 */
	public function get_status(): array {
		$cached = get_transient( $this->transient_key . '_' . $this->channel );
		$remote = is_array( $cached ) && ! empty( $cached['version'] ) ? (string) $cached['version'] : null;

		return [
			'channel'    => $this->channel,
			'remote'     => $remote,
			'has_update' => null !== $remote && $this->compare_versions( LUMIA_VERSION, $remote ),
		];
	}

	/**
	 * Récupère la dernière version depuis GitHub.
	 *
	 * @return array<string, mixed>|false Données de la release ou false en cas d'erreur.
	 */
	private function get_remote_version() {
		$cache_key = $this->transient_key . '_' . $this->channel;
		$cached    = get_transient( $cache_key );

		// Un échec récent est mémorisé comme tel : on ne réinterroge pas GitHub
		// avant l'expiration du cache négatif.
		if ( self::FAILURE_MARKER === $cached ) {
			return false;
		}

		if ( false !== $cached ) {
			return $cached;
		}

		$api_url = $this->get_api_url();

		$response = wp_remote_get(
			$api_url,
			[
				'timeout' => 10,
				'headers' => [
					// Variante « html » : GitHub renvoie les notes déjà rendues
					// (`body_html`), ce qui évite d'embarquer un parseur Markdown.
					'Accept'     => 'application/vnd.github.html+json',
					'User-Agent' => 'LumiaTools',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->remember_failure( $cache_key );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = $this->normalize_release_data( $body );

		if ( false === $data ) {
			return $this->remember_failure( $cache_key );
		}

		set_transient( $cache_key, $data, $this->cache_duration );

		return $data;
	}

	/**
	 * Mémorise un échec de consultation et retourne false.
	 *
	 * @param string $cache_key Clé de transient du canal courant.
	 * @return false
	 */
	private function remember_failure( string $cache_key ) {
		set_transient( $cache_key, self::FAILURE_MARKER, $this->failure_cache_duration );

		return false;
	}

	/**
	 * Retourne l'URL de l'API selon le canal.
	 */
	private function get_api_url(): string {
		if ( 'dev' === $this->channel ) {
			return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases";
		}

		return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
	}

	/**
	 * Normalise les donnees de release selon le canal.
	 *
	 * @param mixed $body Corps JSON décodé de l'API GitHub.
	 * @return array<string, mixed>|false
	 */
	private function normalize_release_data( $body ) {
		if ( 'dev' === $this->channel ) {
			if ( ! is_array( $body ) ) {
				return false;
			}

			// Trier par version décroissante pour garantir la plus récente (indépendamment de l'ordre de l'API)
			usort(
				$body,
				function ( $a, $b ) {
					return version_compare(
						ltrim( $b['tag_name'] ?? '', 'v' ),
						ltrim( $a['tag_name'] ?? '', 'v' )
					);
				}
			);

			// Pré-versions ET stables : le canal dev suit la plus haute des deux,
			// sinon un site en 1.0.13-dev.19 ne voit jamais la 1.1.0 stable.
			$releases = array_values(
				array_filter(
					$body,
					function ( $release ) {
						return is_array( $release ) && empty( $release['draft'] ) && ! empty( $release['tag_name'] );
					}
				)
			);

			if ( [] === $releases ) {
				return false;
			}

			$payload          = $this->format_release_payload( $releases[0] );
			$payload['notes'] = array_map( [ $this, 'format_release_note' ], array_slice( $releases, 0, self::MAX_NOTES ) );

			return $payload;
		}

		if ( empty( $body['tag_name'] ) ) {
			return false;
		}

		return $this->format_release_payload( $body );
	}

	/**
	 * Convertit une release GitHub en payload updater.
	 *
	 * @param array<string, mixed> $release
	 * @return array<string, mixed>
	 */
	private function format_release_payload( array $release ): array {
		$download_url = $this->find_asset_download_url( $release );

		return [
			'version'      => ltrim( $release['tag_name'] ?? '', 'v' ),
			'url'          => $release['html_url'] ?? '',
			'download_url' => '' !== $download_url ? $download_url : ( $release['zipball_url'] ?? '' ),
			'published_at' => $release['published_at'] ?? '',
			'notes'        => [ $this->format_release_note( $release ) ],
		];
	}

	/**
	 * Extrait la note d'une release (version, date, HTML rendu par GitHub).
	 *
	 * @param array<string, mixed> $release
	 * @return array{version: string, date: string, html: string}
	 */
	private function format_release_note( array $release ): array {
		return [
			'version' => ltrim( (string) ( $release['tag_name'] ?? '' ), 'v' ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
			'html'    => (string) ( $release['body_html'] ?? '' ),
		];
	}

	/**
	 * Recupere l'asset zip si disponible.
	 *
	 * @param array<string, mixed> $release
	 */
	private function find_asset_download_url( array $release ): string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( empty( $asset['name'] ) ) {
				continue;
			}

			// Chercher un fichier zip nommé lumia-tools-*.zip
			if ( preg_match( '/^lumia-tools-.*\.zip$/', $asset['name'] ) ) {
				return $asset['browser_download_url'] ?? '';
			}
		}

		return '';
	}
}
