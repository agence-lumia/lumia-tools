<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Branche PHPMailer (celui du cœur) sur le serveur SMTP ou l'API configurés
 * et impose l'expéditeur.
 *
 * FluentSMTP remplace la fonction enfichable wp_mail() tout entière pour
 * router par adresse d'expédition et parler aux API des fournisseurs. Un seul
 * relais SMTP n'en demande pas tant : `phpmailer_init` suffit, et laisse
 * wp_mail() du cœur — ses filtres, ses hooks de succès et d'échec — intact.
 */
class Mailer {

	/** Option du mot de passe chiffré, hors de `lumia_module_smtp` : voir Module::save_settings(). */
	const PASSWORD_OPTION = 'lumia_smtp_password';

	/** Option de la clé API Brevo chiffrée, même traitement que le mot de passe. */
	const BREVO_KEY_OPTION = 'lumia_smtp_brevo_key';

	/**
	 * Transport « API Brevo ». Défini ici et pas dans BrevoMailer : toucher une
	 * constante de BrevoMailer charge la classe, qui étend PHPMailer, et
	 * PHPMailer n'est chargé qu'au premier wp_mail() — erreur fatale sinon.
	 */
	const BREVO = 'brevo';

	/** Transports proposés : relais SMTP ou API HTTP de Brevo. */
	const TRANSPORTS = [ 'smtp', self::BREVO ];

	/**
	 * Délai de connexion SMTP (secondes). Le défaut de PHPMailer est de cinq
	 * minutes : un hôte injoignable figeait la page qui envoyait le mail.
	 */
	const TIMEOUT = 20;

	/** Nom d'expéditeur par défaut de wp_mail(). */
	const WP_DEFAULT_FROM_NAME = 'WordPress';

	/**
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * @param array<string, mixed> $settings Réglages du module.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		if ( $this->smtp_ready() ) {
			add_action( 'phpmailer_init', [ $this, 'configure' ] );
		} elseif ( $this->brevo_ready() ) {
			// pre_wp_mail tourne avant que wp_mail() ne crée son instance.
			add_filter( 'pre_wp_mail', [ $this, 'install_brevo' ], PHP_INT_MAX );
			add_action( 'phpmailer_init', [ $this, 'configure_brevo' ] );
		}

		// Priorité tardive : un expéditeur forcé doit l'emporter sur celui
		// qu'une extension (formulaire de contact, WooCommerce) pose au défaut.
		add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ], 9999 );
		add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ], 9999 );

		if ( ! empty( $this->settings['set_return_path'] ) ) {
			add_action( 'phpmailer_init', [ $this, 'set_return_path' ], 20 );
		}
	}

	/**
	 * Le SMTP n'est branché que s'il est activé ET qu'un hôte est renseigné :
	 * activer l'interrupteur sur un formulaire vide ne doit pas couper l'envoi.
	 */
	public function smtp_ready(): bool {
		return ! empty( $this->settings['smtp_enabled'] ) && 'smtp' === self::transport( $this->settings ) && '' !== (string) ( $this->settings['host'] ?? '' );
	}

	/**
	 * Même règle pour l'API : activée, et une clé enregistrée (qu'elle se
	 * déchiffre ou non : une clé illisible doit échouer bruyamment à l'envoi,
	 * pas retomber en silence sur mail()).
	 */
	public function brevo_ready(): bool {
		return ! empty( $this->settings['smtp_enabled'] ) && self::BREVO === self::transport( $this->settings ) && self::has_brevo_key();
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function transport( array $settings ): string {
		$transport = (string) ( $settings['transport'] ?? 'smtp' );

		return in_array( $transport, self::TRANSPORTS, true ) ? $transport : 'smtp';
	}

	/**
	 * Installe BrevoMailer comme instance globale : wp_mail() garde toute
	 * instance de PHPMailer existante. Une instance d'une autre classe (une
	 * autre extension d'envoi) est laissée en place, et configure_brevo() ne
	 * la touche pas.
	 *
	 * @param mixed $pre Valeur du filtre, rendue telle quelle.
	 * @return mixed
	 */
	public function install_brevo( $pre ) {
		global $phpmailer;

		if ( null !== $pre || $phpmailer instanceof BrevoMailer ) {
			return $pre;
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		if ( is_object( $phpmailer ) && ! in_array( get_class( $phpmailer ), [ \PHPMailer\PHPMailer\PHPMailer::class, 'WP_PHPMailer' ], true ) ) {
			return $pre;
		}

		if ( file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
		}

		// Comme wp_mail() : exceptions actives, validation par is_email().
		$phpmailer = new BrevoMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- instance d'envoi de wp_mail(), remplacée à dessein.

		$phpmailer::$validator = static function ( $email ) {
			return (bool) is_email( $email );
		};

		return $pre;
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure_brevo( $phpmailer ): void {
		if ( ! $phpmailer instanceof BrevoMailer ) {
			return;
		}

		$phpmailer->Mailer        = BrevoMailer::MAILER;
		$phpmailer->lumia_api_key = (string) self::brevo_key();
	}

	/**
	 * L'instance PHPMailer est globale et survit d'un envoi à l'autre :
	 * wp_mail() ne remet à zéro que les destinataires, le corps et le
	 * transport. Toute propriété de connexion est donc assignée à chaque
	 * appel, sans condition — une valeur posée par un envoi précédent
	 * (identifiants, trace de débogage d'un mail de test) resterait sinon.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure( $phpmailer ): void {
		$encryption = (string) ( $this->settings['encryption'] ?? 'tls' );

		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) $this->settings['host'];
		$phpmailer->Port        = (int) $this->settings['port'];
		$phpmailer->SMTPSecure  = in_array( $encryption, [ 'ssl', 'tls' ], true ) ? $encryption : '';
		$phpmailer->SMTPAutoTLS = 'none' === $encryption && ! empty( $this->settings['auto_tls'] );
		$phpmailer->Timeout     = self::TIMEOUT;
		$phpmailer->SMTPDebug   = 0;

		if ( ! empty( $this->settings['auth'] ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = self::username( $this->settings );
			$phpmailer->Password = (string) self::password();
		} else {
			$phpmailer->SMTPAuth = false;
			$phpmailer->Username = '';
			$phpmailer->Password = '';
		}
	}

	/**
	 * Enveloppe (Return-Path) sur l'adresse d'expédition configurée : les
	 * retours de non-distribution reviennent à une boîte lue, et SPF vérifie
	 * cette adresse-là.
	 *
	 * Pas sur l'expéditeur du message : non forcé, ce peut être l'adresse
	 * d'un visiteur (`From` d'un formulaire de contact), et le relais refuse
	 * une enveloppe hors de ses domaines (« Sender address rejected »).
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function set_return_path( $phpmailer ): void {
		$configured = (string) ( $this->settings['from_email'] ?? '' );

		$phpmailer->Sender = '' !== $configured ? $configured : $phpmailer->From;
	}

	/**
	 * Non forcé, l'adresse configurée ne remplace que celle que WordPress pose
	 * par défaut (`wordpress@domaine`) : une extension qui choisit son
	 * expéditeur garde la main.
	 *
	 * @param mixed $email
	 * @return mixed
	 */
	public function filter_from_email( $email ) {
		$configured = (string) ( $this->settings['from_email'] ?? '' );

		if ( '' === $configured ) {
			return $email;
		}

		if ( ! empty( $this->settings['force_from_email'] ) || strtolower( (string) $email ) === self::wp_default_from_email() ) {
			return $configured;
		}

		return $email;
	}

	/**
	 * @param mixed $name
	 * @return mixed
	 */
	public function filter_from_name( $name ) {
		$configured = (string) ( $this->settings['from_name'] ?? '' );

		if ( '' === $configured ) {
			return $name;
		}

		if ( ! empty( $this->settings['force_from_name'] ) || self::WP_DEFAULT_FROM_NAME === $name ) {
			return $configured;
		}

		return $name;
	}

	/**
	 * Adresse que wp_mail() pose quand personne n'en donne, calculée comme
	 * dans le cœur (`wordpress@` + hôte du réseau sans `www.`).
	 */
	public static function wp_default_from_email(): string {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return 'wordpress@' . $host;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function username( array $settings ): string {
		if ( defined( 'LUMIA_SMTP_USER' ) ) {
			return (string) LUMIA_SMTP_USER;
		}

		return (string) ( $settings['username'] ?? '' );
	}

	/**
	 * Mot de passe en clair, depuis `LUMIA_SMTP_PASSWORD` ou l'option chiffrée.
	 *
	 * @return string|null null si l'option ne se déchiffre plus (clés du site changées).
	 */
	public static function password(): ?string {
		if ( defined( 'LUMIA_SMTP_PASSWORD' ) ) {
			return (string) LUMIA_SMTP_PASSWORD;
		}

		return Crypto::decrypt( (string) get_option( self::PASSWORD_OPTION, '' ) );
	}

	public static function has_brevo_key(): bool {
		return defined( 'LUMIA_BREVO_API_KEY' ) || '' !== (string) get_option( self::BREVO_KEY_OPTION, '' );
	}

	/**
	 * Clé API Brevo en clair, depuis `LUMIA_BREVO_API_KEY` ou l'option chiffrée.
	 *
	 * @return string|null null si l'option ne se déchiffre plus.
	 */
	public static function brevo_key(): ?string {
		if ( defined( 'LUMIA_BREVO_API_KEY' ) ) {
			return (string) LUMIA_BREVO_API_KEY;
		}

		return Crypto::decrypt( (string) get_option( self::BREVO_KEY_OPTION, '' ) );
	}

	/**
	 * Une autre extension a-t-elle remplacé wp_mail() ? La fonction est
	 * enfichable : FluentSMTP, Post SMTP et d'autres la redéfinissent, et
	 * `phpmailer_init` n'est alors plus garanti d'être appelé.
	 *
	 * @return string Fichier qui définit wp_mail(), relatif à ABSPATH ; '' si c'est le cœur.
	 */
	public static function wp_mail_override(): string {
		try {
			$file = (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}

		$file = wp_normalize_path( $file );
		$core = wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' );

		if ( $file === $core ) {
			return '';
		}

		$root = wp_normalize_path( ABSPATH );

		return 0 === strpos( $file, $root ) ? substr( $file, strlen( $root ) ) : $file;
	}
}
