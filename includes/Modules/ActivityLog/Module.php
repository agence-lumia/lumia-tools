<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Admin\Admin;

/**
 * Module Journal d'activité — qui a modifié quoi, et quand.
 */
class Module extends AbstractModule {

	/** Hook du cron de purge quotidienne. */
	const CRON_HOOK = 'lumia_activity_log_purge';

	/** Lignes par page dans la liste. */
	const PER_PAGE = 50;

	/** Lignes lues par requête pendant l'export CSV. */
	const EXPORT_CHUNK = 1000;

	/** Bornes des réglages de rétention. */
	const DAYS_MIN = 1;
	const DAYS_MAX = 3650;
	const ROWS_MIN = 100;
	const ROWS_MAX = 1000000;

	public function init(): void {
		Store::maybe_install();

		( new Recorder( $this->get_module_settings( self::get_defaults() ) ) )->register();

		add_action( self::CRON_HOOK, [ $this, 'purge' ] );

		// Programmé ici et pas seulement à l'activation : un module activé par
		// import de configuration n'appelle pas on_activate().
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		add_action( 'wp_ajax_lumia_activity_log_list', [ $this, 'ajax_list' ] );
		add_action( 'admin_post_lumia_activity_log_export', [ $this, 'handle_export' ] );
	}

	/* ================================================================
	 * RÉGLAGES
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( self::get_defaults() );
	}

	/**
	 * Le formulaire poste les familles et rôles SUIVIS (cases cochées) ; on
	 * stocke les exclusions. Une famille ajoutée par une version ultérieure est
	 * ainsi journalisée d'office, au lieu d'arriver exclue parce qu'absente
	 * d'une liste enregistrée avant qu'elle existe.
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
	 * Le stockage parle d'exclusions, le formulaire de cases cochées : l'import
	 * de configuration doit repasser par la forme du formulaire.
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
	 * CYCLE DE VIE
	 * ================================================================ */

	public function on_activate(): void {
		Store::install();
	}

	/**
	 * La table reste : désactiver le module suspend le journal, ça ne doit pas
	 * effacer l'historique. Seule la désinstallation la supprime.
	 */
	public function on_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Purge quotidienne : par âge, puis par volume.
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
				'alLoading' => __( 'Chargement…', 'lumia-tools' ),
				'alEmpty'   => __( 'Aucun événement pour ces critères.', 'lumia-tools' ),
				'alError'   => __( 'Impossible de charger le journal.', 'lumia-tools' ),
				/* translators: %s: nombre d'événements. */
				'alTotal'   => __( '%s événement(s)', 'lumia-tools' ),
				/* translators: 1: page courante, 2: nombre de pages. */
				'alPage'    => __( 'Page %1$s sur %2$s', 'lumia-tools' ),
				'alDate'    => __( 'Date', 'lumia-tools' ),
				'alUser'    => __( 'Utilisateur', 'lumia-tools' ),
				'alRole'    => __( 'Rôle', 'lumia-tools' ),
				'alIp'      => __( 'Adresse IP', 'lumia-tools' ),
				'alEvent'   => __( 'Événement', 'lumia-tools' ),
				'alObject'  => __( 'Objet', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * LISTE (AJAX)
	 * ================================================================ */

	public function ajax_list(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ], 403 );
		}

		$filters = $this->read_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié en tête ; read_filters() assainit chaque champ.
		$page    = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié en tête.
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
	 * EXPORT CSV
	 * ================================================================ */

	/**
	 * Exporte les lignes correspondant aux filtres de la liste.
	 */
	public function handle_export(): void {
		check_admin_referer( 'lumia_activity_log_export', 'lumia_nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ), '', [ 'response' => 403 ] );
		}

		$filters = $this->read_filters( $_POST );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: ' . Admin::content_disposition( 'journal-activite-' . wp_date( 'Y-m-d-His' ) . '.csv' ) );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		// BOM : sans lui, Excel ouvre le fichier en Windows-1252 et casse les accents.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- flux de sortie HTTP, pas un fichier.

		// Échappement vide (RFC 4180, guillemets doublés) : le défaut `\` de
		// fputcsv() est déprécié depuis PHP 8.4 et produit des CSV que les
		// tableurs relisent de travers quand une valeur finit par un antislash.
		fputcsv(
			$out,
			[
				__( 'Date', 'lumia-tools' ),
				__( 'Utilisateur', 'lumia-tools' ),
				__( 'Rôle', 'lumia-tools' ),
				__( 'Adresse IP', 'lumia-tools' ),
				__( 'Famille', 'lumia-tools' ),
				__( 'Événement', 'lumia-tools' ),
				__( 'Objet', 'lumia-tools' ),
				__( 'ID de l\'objet', 'lumia-tools' ),
				__( 'Détails', 'lumia-tools' ),
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
						return $pair[0] . ' : ' . $pair[1];
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

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- flux de sortie HTTP, pas un fichier.
		exit;
	}

	/**
	 * Neutralise l'injection de formule : un titre d'article qui commence par
	 * `=` est exécuté comme formule par Excel ou LibreOffice à l'ouverture du
	 * CSV. Or titres, identifiants de connexion tentés et e-mails sont saisis
	 * par n'importe qui.
	 */
	private static function csv_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ================================================================
	 * PRÉSENTATION
	 * ================================================================ */

	/**
	 * Filtres de la liste, assainis. Source : `$_POST` d'une requête dont le
	 * nonce a déjà été vérifié par l'appelant.
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
	 * Ligne de table => valeurs affichables (liste et CSV).
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
			// Échec de connexion : personne n'est connecté, l'identifiant
			// tenté est l'objet de l'événement.
			$user = 'login_failed' === $event ? __( 'Anonyme', 'lumia-tools' ) : __( 'Système', 'lumia-tools' );
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
				return __( 'Réglages généraux', 'lumia-tools' );
			}
			$module = \Lumia\Tools\Core\Plugin::instance()->modules->get( $label );
			return is_array( $module ) && ! empty( $module['name'] ) ? (string) $module['name'] : $label;
		}

		return $label;
	}

	/**
	 * Lien d'édition de l'objet s'il existe encore et que l'utilisateur
	 * courant peut l'ouvrir ; '' sinon.
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
	 * Détail d'un événement en lignes « libellé / valeur », prêtes à afficher.
	 *
	 * @param array<string, mixed> $details
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function detail_lines( array $details ): array {
		$labels = [
			'title'        => __( 'Titre', 'lumia-tools' ),
			'status'       => __( 'Statut', 'lumia-tools' ),
			'slug'         => __( 'Identifiant (slug)', 'lumia-tools' ),
			'parent'       => __( 'Parent', 'lumia-tools' ),
			'date'         => __( 'Date de publication', 'lumia-tools' ),
			'order'        => __( 'Ordre', 'lumia-tools' ),
			'comments'     => __( 'Commentaires', 'lumia-tools' ),
			'author'       => __( 'Auteur', 'lumia-tools' ),
			'content'      => __( 'Contenu', 'lumia-tools' ),
			'excerpt'      => __( 'Extrait', 'lumia-tools' ),
			'password'     => __( 'Mot de passe', 'lumia-tools' ),
			'builder'      => __( 'Constructeur de page', 'lumia-tools' ),
			'alt'          => __( 'Texte alternatif', 'lumia-tools' ),
			'email'        => __( 'E-mail', 'lumia-tools' ),
			'display_name' => __( 'Nom affiché', 'lumia-tools' ),
			'url'          => __( 'Site web', 'lumia-tools' ),
			'roles'        => __( 'Rôles', 'lumia-tools' ),
			'reassign'     => __( 'Contenus attribués à', 'lumia-tools' ),
			'file'         => __( 'Fichier', 'lumia-tools' ),
			'stylesheet'   => __( 'Dossier', 'lumia-tools' ),
			'version'      => __( 'Version', 'lumia-tools' ),
			'network'      => __( 'Tout le réseau', 'lumia-tools' ),
			'mime'         => __( 'Type de fichier', 'lumia-tools' ),
			'label'        => __( 'Réglage', 'lumia-tools' ),
			'from'         => __( 'Avant', 'lumia-tools' ),
			'to'           => __( 'Après', 'lumia-tools' ),
			'paths'        => __( 'Champs modifiés', 'lumia-tools' ),
			'modules'      => __( 'Modules', 'lumia-tools' ),
			'via'          => __( 'Origine', 'lumia-tools' ),
		];

		$channels = [
			'web'    => __( 'Interface web', 'lumia-tools' ),
			'ajax'   => __( 'Interface web (AJAX)', 'lumia-tools' ),
			'rest'   => __( 'API REST / éditeur de blocs', 'lumia-tools' ),
			'cron'   => __( 'Tâche planifiée', 'lumia-tools' ),
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
					__( 'Limite atteinte', 'lumia-tools' ),
					/* translators: %d: nombre d'échecs journalisés par heure. */
					sprintf( __( 'Les échecs suivants de cette IP ne sont plus journalisés pendant une heure (%d par heure au plus).', 'lumia-tools' ), (int) $value ),
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
					$parts[] = $this->object_label(
						[
							'object_type'  => 'lumia',
							'object_label' => (string) $module,
						]
					) . ' : ' . ( $active ? __( 'activé', 'lumia-tools' ) : __( 'désactivé', 'lumia-tools' ) );
				}
				$value = implode( ', ', $parts );
			}

			$lines[] = [ $labels[ $key ] ?? $key, $this->scalar_text( $value ) ];
		}

		return $lines;
	}

	/**
	 * Libellé traduit d'un statut de contenu (« Publié » plutôt que `publish`).
	 *
	 * @param mixed $status
	 */
	private function status_label( $status ): string {
		$object = get_post_status_object( (string) $status );

		return $object && is_string( $object->label ) ? $object->label : (string) $status;
	}

	/**
	 * @param mixed $change `true` (champ modifié, valeur non conservée) ou {from, to}.
	 */
	private function change_text( $change ): string {
		if ( is_array( $change ) && array_key_exists( 'from', $change ) ) {
			return $this->scalar_text( $change['from'] ) . ' → ' . $this->scalar_text( $change['to'] ?? '' );
		}

		if ( true === $change ) {
			return __( 'modifié', 'lumia-tools' );
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
			return $value ? __( 'oui', 'lumia-tools' ) : __( 'non', 'lumia-tools' );
		}

		$value = (string) $value;

		return '' === $value ? '∅' : $value;
	}
}
