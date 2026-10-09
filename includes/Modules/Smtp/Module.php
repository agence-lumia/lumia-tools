<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Module SMTP — envoi par un serveur SMTP authentifié ou l'API Brevo, et
 * journal des mails.
 */
class Module extends AbstractModule {

	/** Hook du cron de purge quotidienne. */
	const CRON_HOOK = 'lumia_smtp_log_purge';

	/** Lignes par page dans la liste. */
	const PER_PAGE = 50;

	/** Bornes des réglages de rétention. */
	const DAYS_MIN = 1;
	const DAYS_MAX = 3650;
	const ROWS_MIN = 100;
	const ROWS_MAX = 1000000;

	/** Chiffrements proposés : aucun, SSL implicite (465), STARTTLS (587). */
	const ENCRYPTIONS = [ 'none', 'ssl', 'tls' ];

	private ?Logger $logger = null;

	public function init(): void {
		$settings = $this->get_settings();

		( new Mailer( $settings ) )->register();

		// Même journal coupé : la liste de l'écran lit la table.
		Store::maybe_install();

		if ( ! empty( $settings['log_enabled'] ) ) {
			$this->logger = new Logger();
			$this->logger->register();
		}

		add_action( self::CRON_HOOK, [ $this, 'purge' ] );

		// Programmé ici et pas seulement à l'activation : un module activé par
		// import de configuration n'appelle pas on_activate().
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		add_action( 'wp_ajax_lumia_smtp_log_list', [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_lumia_smtp_log_detail', [ $this, 'ajax_detail' ] );
		add_action( 'wp_ajax_lumia_smtp_log_resend', [ $this, 'ajax_resend' ] );
		add_action( 'wp_ajax_lumia_smtp_log_clear', [ $this, 'ajax_clear' ] );
		add_action( 'wp_ajax_lumia_smtp_test', [ $this, 'ajax_test' ] );
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
	 * Le mot de passe et la clé API ne sont PAS rangés dans `lumia_module_smtp` mais dans leur
	 * propre option : l'export de configuration écrit les options de module
	 * telles quelles dans le JSON, et le journal d'activité en liste les
	 * champs modifiés. Champ vide = secret inchangé (le formulaire ne le
	 * réaffiche jamais) ; l'import, qui n'en porte pas, le laisse donc en place.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$encryption = (string) ( $settings['encryption'] ?? 'tls' );
		$from_email = sanitize_email( (string) ( $settings['from_email'] ?? '' ) );
		$provider   = sanitize_key( (string) ( $settings['provider'] ?? Providers::CUSTOM ) );

		$sanitized = [
			'smtp_enabled'       => ! empty( $settings['smtp_enabled'] ),
			'transport'          => Mailer::transport( $settings ),
			'provider'           => Providers::is_valid( $provider ) ? $provider : Providers::CUSTOM,
			'host'               => self::sanitize_host( (string) ( $settings['host'] ?? '' ) ),
			'port'               => min( 65535, max( 1, absint( $settings['port'] ?? 587 ) ) ),
			'encryption'         => in_array( $encryption, self::ENCRYPTIONS, true ) ? $encryption : 'tls',
			'auto_tls'           => ! empty( $settings['auto_tls'] ),
			'auth'               => ! empty( $settings['auth'] ),
			'username'           => mb_substr( sanitize_text_field( (string) ( $settings['username'] ?? '' ) ), 0, 255 ),
			'from_email'         => is_email( $from_email ) ? $from_email : '',
			'from_name'          => mb_substr( sanitize_text_field( (string) ( $settings['from_name'] ?? '' ) ), 0, 255 ),
			'force_from_email'   => ! empty( $settings['force_from_email'] ),
			'force_from_name'    => ! empty( $settings['force_from_name'] ),
			'set_return_path'    => ! empty( $settings['set_return_path'] ),
			'log_enabled'        => ! empty( $settings['log_enabled'] ),
			'log_retention_days' => min( self::DAYS_MAX, max( self::DAYS_MIN, absint( $settings['log_retention_days'] ?? 30 ) ) ),
			'log_max_rows'       => min( self::ROWS_MAX, max( self::ROWS_MIN, absint( $settings['log_max_rows'] ?? 5000 ) ) ),
		];

		if ( ! defined( 'LUMIA_SMTP_PASSWORD' ) ) {
			self::store_secret( Mailer::PASSWORD_OPTION, self::sanitize_password( $settings['password'] ?? '' ) );
		}

		if ( ! defined( 'LUMIA_BREVO_API_KEY' ) ) {
			// Une clé Brevo (`xkeysib-…`) n'a que des lettres, chiffres et tirets.
			self::store_secret( Mailer::BREVO_KEY_OPTION, (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', self::sanitize_password( $settings['brevo_key'] ?? '' ) ) );
		}

		return $this->save_module_settings( $sanitized );
	}

	/**
	 * Jamais en clair : sans openssl, le secret n'est pas enregistré, et
	 * l'écran le signale (voir le gabarit). Vide = inchangé.
	 */
	private static function store_secret( string $option, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$encrypted = Crypto::encrypt( $value );

		if ( '' !== $encrypted ) {
			update_option( $option, $encrypted, false );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'smtp_enabled'       => false,
			'transport'          => 'smtp',
			'provider'           => Providers::CUSTOM,
			'host'               => '',
			'port'               => 587,
			'encryption'         => 'tls',
			'auto_tls'           => true,
			'auth'               => true,
			'username'           => '',
			'from_email'         => '',
			'from_name'          => '',
			'force_from_email'   => false,
			'force_from_name'    => false,
			'set_return_path'    => true,
			'log_enabled'        => true,
			'log_retention_days' => 30,
			'log_max_rows'       => 5000,
		];
	}

	/**
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [ 'lumia_module_smtp', Mailer::PASSWORD_OPTION, Mailer::BREVO_KEY_OPTION, Store::SCHEMA_OPTION ],
			'tables'  => [ Store::TABLE ],
			'cron'    => [ self::CRON_HOOK ],
		];
	}

	/**
	 * Hôte seul : on retire un schéma collé par erreur (`smtp://`, `https://`)
	 * et on refuse tout caractère qui n'a rien à faire dans un nom d'hôte ou
	 * une IP — le champ finit dans une connexion réseau.
	 */
	private static function sanitize_host( string $host ): string {
		$host = strtolower( trim( sanitize_text_field( $host ) ) );
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
		$host = rtrim( $host, '/' );

		return preg_match( '/^[a-z0-9.\-]{1,253}$|^\[[0-9a-f:.]+\]$/', $host ) ? $host : '';
	}

	/**
	 * Pas de sanitize_text_field() : il retire les balises et les séquences
	 * `%xx`, et un mot de passe a le droit de contenir `<a` ou `%41`. On ne
	 * retire que les caractères de contrôle (un retour chariot dans un
	 * échange SMTP termine la commande).
	 *
	 * @param mixed $value
	 */
	private static function sanitize_password( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ), 0, 1024 );
	}

	/* ================================================================
	 * CYCLE DE VIE
	 * ================================================================ */

	public function on_activate(): void {
		Store::install();
	}

	/**
	 * La table reste : désactiver le module ne doit pas effacer l'historique.
	 */
	public function on_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function purge(): void {
		$settings = $this->get_settings();

		Store::purge( (int) $settings['log_retention_days'], (int) $settings['log_max_rows'] );
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/smtp.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/smtp.js' ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'smProviders' => Providers::all(),
			'i18n'        => [
				'smLoading'        => __( 'Chargement…', 'lumia-tools' ),
				'smEmpty'          => __( 'Aucun mail pour ces critères.', 'lumia-tools' ),
				'smError'          => __( 'Impossible de charger le journal.', 'lumia-tools' ),
				/* translators: %s: nombre de mails. */
				'smTotal'          => __( '%s mail(s)', 'lumia-tools' ),
				/* translators: 1: page courante, 2: nombre de pages. */
				'smPage'           => __( 'Page %1$s sur %2$s', 'lumia-tools' ),
				'smSent'           => __( 'Envoyé', 'lumia-tools' ),
				'smFailed'         => __( 'Échec', 'lumia-tools' ),
				'smResentOf'       => __( 'Renvoi de', 'lumia-tools' ),
				'smNoSubject'      => __( '(sans objet)', 'lumia-tools' ),
				'smTesting'        => __( 'Envoi en cours…', 'lumia-tools' ),
				'smResending'      => __( 'Renvoi en cours…', 'lumia-tools' ),
				'smClearTitle'     => __( 'Vider le journal des mails ?', 'lumia-tools' ),
				'smClearMessage'   => __( 'Tous les mails journalisés seront supprimés définitivement.', 'lumia-tools' ),
				'smClearConfirm'   => __( 'Vider le journal', 'lumia-tools' ),
				'smCancel'         => __( 'Annuler', 'lumia-tools' ),
				'smDate'           => __( 'Date', 'lumia-tools' ),
				'smStatus'         => __( 'Statut', 'lumia-tools' ),
				'smFrom'           => __( 'Expéditeur', 'lumia-tools' ),
				'smTo'             => __( 'Destinataire', 'lumia-tools' ),
				'smTransport'      => __( 'Transport', 'lumia-tools' ),
				'smAttachments'    => __( 'Pièces jointes', 'lumia-tools' ),
				'smMissing'        => __( 'introuvable', 'lumia-tools' ),
				'smProviderHint'   => __( 'Choisir un fournisseur pré-remplit l\'hôte, le port et le chiffrement ; tout reste modifiable.', 'lumia-tools' ),
				'smTruncatedLabel' => __( 'Tronqué', 'lumia-tools' ),
				'smTruncated'      => __( 'Message trop long, tronqué à l\'enregistrement : il ne peut pas être renvoyé.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * Nonce partagé + capacité du module, en tête de chaque endpoint.
	 */
	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ], 403 );
		}
	}

	public function ajax_list(): void {
		$this->guard();

		$filters = $this->read_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard() ; read_filters() assainit chaque champ.
		$page    = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().
		$total   = Store::count( $filters );
		$pages   = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page    = min( $page, $pages );

		wp_send_json_success(
			[
				'rows'  => array_map( [ $this, 'present' ], Store::query( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) ),
				'total' => $total,
				'page'  => $page,
				'pages' => $pages,
			]
		);
	}

	/**
	 * Détail d'un mail : corps, en-têtes et pièces jointes, absents de la liste.
	 */
	public function ajax_detail(): void {
		$this->guard();

		$row = Store::get( absint( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Mail introuvable.', 'lumia-tools' ) ], 404 );
		}

		$attachments = self::json_list( $row['attachments'] ?? '' );

		wp_send_json_success(
			array_merge(
				$this->present( $row ),
				[
					'message'     => (string) $row['message'],
					'is_html'     => 'text/html' === $row['content_type'] || 'multipart/alternative' === $row['content_type'],
					'headers'     => self::json_list( $row['headers'] ?? '' ),
					'attachments' => array_map(
						static function ( string $path ): array {
							return [
								'name'   => wp_basename( $path ),
								'exists' => is_file( $path ),
							];
						},
						$attachments
					),
					'truncated'   => (bool) $row['truncated'],
					'can_resend'  => ! $row['truncated'] && '' !== trim( (string) $row['to_address'] ),
				]
			)
		);
	}

	/**
	 * Rejoue wp_mail() avec la demande d'origine. Les pièces jointes qui
	 * n'existent plus sont écartées (les extensions de formulaire effacent
	 * souvent leurs fichiers temporaires après l'envoi) et signalées.
	 */
	public function ajax_resend(): void {
		$this->guard();

		$row = Store::get( absint( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Mail introuvable.', 'lumia-tools' ) ], 404 );
		}

		if ( $row['truncated'] ) {
			wp_send_json_error( [ 'message' => __( 'Ce mail a été tronqué à l\'enregistrement : il ne peut pas être renvoyé à l\'identique.', 'lumia-tools' ) ] );
		}

		$attachments = [];
		$missing     = [];
		foreach ( self::json_list( $row['attachments'] ?? '' ) as $path ) {
			if ( is_file( $path ) ) {
				$attachments[] = $path;
			} else {
				$missing[] = wp_basename( $path );
			}
		}

		if ( $this->logger ) {
			$this->logger->set_resent_of( (int) $row['id'] );
		}

		// Rejouer les arguments de wp_mail() ne suffit pas : beaucoup
		// d'extensions posent expéditeur et type de contenu par des filtres
		// actifs le temps de leur envoi (WooCommerce : wp_mail_from,
		// wp_mail_content_type). Au renvoi, ces filtres sont absents — la
		// commande repartait de l'expéditeur par défaut, le HTML en texte
		// brut. On réapplique donc ce qui est réellement parti, journalisé.
		// Priorité 9000 : sous celle de l'expéditeur forcé (9999), qui garde
		// le dernier mot.
		$sent_from = self::parse_address( (string) $row['from_address'] );
		$sent_type = (string) $row['content_type'];
		$filters   = [
			'wp_mail_from'         => static function ( $email ) use ( $sent_from ) {
				return '' !== $sent_from['email'] ? $sent_from['email'] : $email;
			},
			'wp_mail_from_name'    => static function ( $name ) use ( $sent_from ) {
				return '' !== $sent_from['email'] ? $sent_from['name'] : $name;
			},
			'wp_mail_content_type' => static function ( $type ) use ( $sent_type ) {
				return '' !== $sent_type ? $sent_type : $type;
			},
		];

		foreach ( $filters as $hook => $callback ) {
			add_filter( $hook, $callback, 9000 );
		}

		$error = $this->send_capturing_error(
			static function () use ( $row, $attachments ): bool {
				return wp_mail( (string) $row['to_address'], (string) $row['subject'], (string) $row['message'], self::json_list( $row['headers'] ?? '' ), $attachments );
			}
		);

		foreach ( $filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 9000 );
		}

		if ( null !== $error ) {
			wp_send_json_error( [ 'message' => $error ] );
		}

		$message = __( 'Mail renvoyé.', 'lumia-tools' );
		if ( $missing ) {
			/* translators: %s: noms des pièces jointes introuvables. */
			$message .= ' ' . sprintf( __( 'Pièces jointes introuvables, non jointes : %s.', 'lumia-tools' ), implode( ', ', $missing ) );
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	public function ajax_clear(): void {
		$this->guard();

		Store::clear();

		wp_send_json_success( [ 'message' => __( 'Journal vidé.', 'lumia-tools' ) ] );
	}

	/**
	 * Mail de test, avec la transcription de l'échange SMTP (ou la réponse de
	 * l'API) en cas d'échec :
	 * « Could not authenticate » ne dit pas si c'est l'identifiant, le port ou
	 * le chiffrement qui cloche, la réponse du serveur si.
	 */
	public function ajax_test(): void {
		$this->guard();

		$to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par guard().

		if ( ! is_email( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'Adresse de destination invalide.', 'lumia-tools' ) ] );
		}

		$transcript = [];
		$debug      = static function ( $phpmailer ) use ( &$transcript ): void {
			if ( 'smtp' !== $phpmailer->Mailer ) {
				return;
			}
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( $line ) use ( &$transcript ): void {
				$transcript[] = rtrim( (string) $line );
			};
		};

		// Après Mailer::configure() (priorité 10), qui remet SMTPDebug à zéro :
		// l'instance PHPMailer est globale, le mail suivant ne doit pas hériter
		// de la trace.
		add_action( 'phpmailer_init', $debug, 1000 );

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$error = $this->send_capturing_error(
			static function () use ( $to, $site ): bool {
				return wp_mail(
					$to,
					/* translators: %s: nom du site. */
					sprintf( __( 'Mail de test — %s', 'lumia-tools' ), $site ),
					'<p>' . esc_html__( 'Ce mail de test a été envoyé depuis Lümia Tools. Si vous le lisez, l\'envoi fonctionne.', 'lumia-tools' ) . '</p>'
					. '<p style="color:#666;font-size:12px">' . esc_html( home_url() ) . ' — ' . esc_html( (string) wp_date( 'Y-m-d H:i:s' ) ) . '</p>',
					[ 'Content-Type: text/html; charset=UTF-8' ]
				);
			}
		);

		remove_action( 'phpmailer_init', $debug, 1000 );

		// Envoi par l'API : la réponse HTTP tient lieu de transcription. Elle
		// ne contient pas la clé, qui ne part que dans un en-tête.
		global $phpmailer;
		if ( null !== $error && $phpmailer instanceof BrevoMailer && null !== $phpmailer->lumia_last_response ) {
			$transcript = [
				'POST ' . BrevoMailer::ENDPOINT,
				'HTTP ' . $phpmailer->lumia_last_response['code'],
				$phpmailer->lumia_last_response['body'],
			];
		}

		if ( null !== $error ) {
			wp_send_json_error(
				[
					'message'    => $error,
					'transcript' => self::mask_transcript( $transcript ),
				]
			);
		}

		wp_send_json_success(
			[
				/* translators: %s: adresse de destination. */
				'message' => sprintf( __( 'Mail de test envoyé à %s.', 'lumia-tools' ), $to ),
			]
		);
	}

	/**
	 * Exécute un envoi et renvoie le message d'erreur, ou null si tout va bien.
	 * wp_mail() ne renvoie que false : le détail n'existe que dans le WP_Error
	 * passé à `wp_mail_failed`.
	 *
	 * @param callable(): bool $send
	 */
	private function send_capturing_error( callable $send ): ?string {
		$error   = null;
		$capture = static function ( $wp_error ) use ( &$error ): void {
			if ( $wp_error instanceof \WP_Error ) {
				$error = $wp_error->get_error_message();
			}
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = $send();
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			return null;
		}

		// Échec sans WP_Error : un pre_wp_mail a court-circuité l'envoi.
		return '' !== (string) $error ? (string) $error : __( 'L\'envoi a échoué sans message d\'erreur (une autre extension l\'a peut-être intercepté).', 'lumia-tools' );
	}

	/**
	 * Masque l'authentification dans la transcription SMTP. Au niveau de
	 * débogage 2, PHPMailer écrit les commandes du client telles quelles : la
	 * ligne AUTH PLAIN et les réponses à AUTH LOGIN portent l'identifiant et
	 * le mot de passe en base64 — c'est-à-dire en clair.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	public static function mask_transcript( array $lines ): array {
		$masked   = [];
		$in_auth  = false;
		$client   = 'CLIENT -> SERVER:';
		$server   = 'SERVER -> CLIENT:';
		$redacted = '[' . __( 'masqué', 'lumia-tools' ) . ']';

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, $client ) ) {
				$command = trim( substr( $line, strlen( $client ) ) );

				if ( preg_match( '/^AUTH\s+(\S+)/i', $command, $match ) ) {
					$in_auth  = true;
					$masked[] = $client . ' AUTH ' . $match[1] . ( strlen( $command ) > strlen( $match[0] ) ? ' ' . $redacted : '' );
					continue;
				}

				if ( $in_auth ) {
					$masked[] = $client . ' ' . $redacted;
					continue;
				}
			} elseif ( $in_auth && 0 === strpos( $line, $server ) ) {
				// 334 = le serveur attend la suite de l'authentification ; tout
				// autre code la termine (235 réussie, 5xx refusée).
				$in_auth = 0 === strpos( trim( substr( $line, strlen( $server ) ) ), '334' );
			}

			$masked[] = $line;
		}

		return $masked;
	}

	/* ================================================================
	 * PRÉSENTATION
	 * ================================================================ */

	/**
	 * @param array<string, mixed> $source `$_POST` d'une requête au nonce déjà vérifié.
	 * @return array<string, string>
	 */
	private function read_filters( array $source ): array {
		$source = wp_unslash( $source );
		$date   = static function ( $value ): string {
			$value = sanitize_text_field( (string) $value );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		$status = sanitize_key( (string) ( $source['status'] ?? '' ) );

		return [
			'status' => in_array( $status, [ Store::STATUS_SENT, Store::STATUS_FAILED ], true ) ? $status : '',
			'from'   => $date( $source['from'] ?? '' ),
			'to'     => $date( $source['to'] ?? '' ),
			'search' => mb_substr( sanitize_text_field( (string) ( $source['search'] ?? '' ) ), 0, 100 ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present( array $row ): array {
		$transports = [
			'smtp'     => 'SMTP',
			'mail'     => 'PHP mail()',
			'sendmail' => 'Sendmail',
			'qmail'    => 'Qmail',
			'brevo'    => 'API Brevo',
		];
		$transport  = (string) $row['transport'];

		return [
			'id'        => (int) $row['id'],
			'date'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ),
			'status'    => (string) $row['status'],
			'transport' => $transports[ $transport ] ?? $transport,
			'from'      => (string) $row['from_address'],
			'to'        => (string) $row['to_address'],
			'subject'   => (string) $row['subject'],
			'error'     => (string) $row['error'],
			'resent_of' => (int) $row['resent_of'],
		];
	}

	/**
	 * « Nom <adresse> » ou « adresse » => nom et adresse.
	 *
	 * @return array{name: string, email: string}
	 */
	private static function parse_address( string $value ): array {
		if ( preg_match( '/^(.*)<([^<>]+)>\s*$/', $value, $match ) ) {
			return [
				'name'  => trim( $match[1], " \t\"" ),
				'email' => is_email( trim( $match[2] ) ) ? trim( $match[2] ) : '',
			];
		}

		return [
			'name'  => '',
			'email' => is_email( trim( $value ) ) ? trim( $value ) : '',
		];
	}

	/**
	 * @param mixed $json
	 * @return string[]
	 */
	private static function json_list( $json ): array {
		$list  = json_decode( (string) $json, true );
		$items = [];

		foreach ( is_array( $list ) ? $list : [] as $item ) {
			if ( is_scalar( $item ) && '' !== (string) $item ) {
				$items[] = (string) $item;
			}
		}

		return $items;
	}
}
