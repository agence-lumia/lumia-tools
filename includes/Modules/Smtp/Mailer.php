<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs PHPMailer (the core one) into the configured SMTP server or API and
 * enforces the sender.
 *
 * FluentSMTP replaces the whole pluggable wp_mail() function to route by
 * sender address and talk to provider APIs. A single SMTP relay does not need
 * that much: `phpmailer_init` is enough, and leaves the core wp_mail() — its
 * filters, its success and failure hooks — intact.
 */
class Mailer {

	/** Option of the encrypted password, outside `lumia_module_smtp`: see Module::save_settings(). */
	const PASSWORD_OPTION = 'lumia_smtp_password';

	/** Option of the encrypted Brevo API key, same treatment as the password. */
	const BREVO_KEY_OPTION = 'lumia_smtp_brevo_key';

	/**
	 * "Brevo API" transport. Defined here and not in BrevoMailer: touching a
	 * BrevoMailer constant loads the class, which extends PHPMailer, and
	 * PHPMailer is only loaded on the first wp_mail() — fatal error otherwise.
	 */
	const BREVO = 'brevo';

	/** Available transports: SMTP relay or Brevo HTTP API. */
	const TRANSPORTS = [ 'smtp', self::BREVO ];

	/**
	 * SMTP connection timeout (seconds). PHPMailer's default is five minutes:
	 * an unreachable host froze the page that was sending the email.
	 */
	const TIMEOUT = 20;

	/** Default sender name of wp_mail(). */
	const WP_DEFAULT_FROM_NAME = 'WordPress';

	/**
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * @param array<string, mixed> $settings Module settings.
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		if ( $this->smtp_ready() ) {
			add_action( 'phpmailer_init', [ $this, 'configure' ] );
		} elseif ( $this->brevo_ready() ) {
			// pre_wp_mail runs before wp_mail() creates its instance.
			add_filter( 'pre_wp_mail', [ $this, 'install_brevo' ], PHP_INT_MAX );
			add_action( 'phpmailer_init', [ $this, 'configure_brevo' ] );
		}

		// Late priority: a forced sender must win over the one a plugin
		// (contact form, WooCommerce) sets by default.
		add_filter( 'wp_mail_from', [ $this, 'filter_from_email' ], 9999 );
		add_filter( 'wp_mail_from_name', [ $this, 'filter_from_name' ], 9999 );

		if ( ! empty( $this->settings['set_return_path'] ) ) {
			add_action( 'phpmailer_init', [ $this, 'set_return_path' ], 20 );
		}
	}

	/**
	 * SMTP is only plugged in when it is enabled AND a host is set: turning the
	 * switch on with an empty form must not cut off sending.
	 */
	public function smtp_ready(): bool {
		return ! empty( $this->settings['smtp_enabled'] ) && 'smtp' === self::transport( $this->settings ) && '' !== (string) ( $this->settings['host'] ?? '' );
	}

	/**
	 * Same rule for the API: enabled, and a saved key (whether or not it can be
	 * decrypted: an unreadable key must fail loudly when sending, not silently
	 * fall back on mail()).
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
	 * Installs BrevoMailer as the global instance: wp_mail() keeps any existing
	 * PHPMailer instance. An instance of another class (another sending plugin)
	 * is left in place, and configure_brevo() does not touch it.
	 *
	 * @param mixed $pre Filter value, returned as is.
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

		// Like wp_mail(): exceptions on, validation through is_email().
		$phpmailer = new BrevoMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- sending instance of wp_mail(), replaced on purpose.

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
	 * The PHPMailer instance is global and survives from one send to the next:
	 * wp_mail() only resets the recipients, the body and the transport. Every
	 * connection property is therefore assigned on each call, unconditionally —
	 * a value set by a previous send (credentials, debug trace of a test email)
	 * would otherwise remain.
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
	 * Envelope (Return-Path) on the configured sender address: bounces come
	 * back to a mailbox that is read, and SPF checks that address.
	 *
	 * Not on the message sender: when not forced, it may be a visitor's
	 * address (`From` of a contact form), and the relay refuses an envelope
	 * outside its domains ("Sender address rejected").
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function set_return_path( $phpmailer ): void {
		$configured = (string) ( $this->settings['from_email'] ?? '' );

		$phpmailer->Sender = '' !== $configured ? $configured : $phpmailer->From;
	}

	/**
	 * When not forced, the configured address only replaces the one WordPress
	 * sets by default (`wordpress@domain`): a plugin that chooses its own
	 * sender keeps control.
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
	 * Address wp_mail() sets when nobody provides one, computed as in core
	 * (`wordpress@` + network host without `www.`).
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
	 * Clear-text password, from `LUMIA_SMTP_PASSWORD` or the encrypted option.
	 *
	 * @return string|null null if the option can no longer be decrypted (site keys changed).
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
	 * Clear-text Brevo API key, from `LUMIA_BREVO_API_KEY` or the encrypted option.
	 *
	 * @return string|null null if the option can no longer be decrypted.
	 */
	public static function brevo_key(): ?string {
		if ( defined( 'LUMIA_BREVO_API_KEY' ) ) {
			return (string) LUMIA_BREVO_API_KEY;
		}

		return Crypto::decrypt( (string) get_option( self::BREVO_KEY_OPTION, '' ) );
	}

	/**
	 * Has another plugin replaced wp_mail()? The function is pluggable:
	 * FluentSMTP, Post SMTP and others redefine it, and `phpmailer_init` is
	 * then no longer guaranteed to be called.
	 *
	 * @return string File that defines wp_mail(), relative to ABSPATH; '' if it is core.
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
