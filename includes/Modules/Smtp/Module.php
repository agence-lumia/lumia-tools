<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * SMTP module — sending through an authenticated SMTP server or the Brevo API,
 * and the email log.
 */
class Module extends AbstractModule {

	/** Hook of the daily purge cron. */
	const CRON_HOOK = 'lumia_smtp_log_purge';

	/** Rows per page in the list. */
	const PER_PAGE = 50;

	/** Bounds of the retention settings. */
	const DAYS_MIN = 1;
	const DAYS_MAX = 3650;
	const ROWS_MIN = 100;
	const ROWS_MAX = 1000000;

	/** Available encryptions: none, implicit SSL (465), STARTTLS (587). */
	const ENCRYPTIONS = [ 'none', 'ssl', 'tls' ];

	private ?Logger $logger = null;

	public function init(): void {
		$settings = $this->get_settings();

		( new Mailer( $settings ) )->register();

		// Even with logging off: the screen list reads the table.
		Store::maybe_install();

		if ( ! empty( $settings['log_enabled'] ) ) {
			$this->logger = new Logger();
			$this->logger->register();
		}

		add_action( self::CRON_HOOK, [ $this, 'purge' ] );

		// Scheduled here and not only on activation: a module activated by a
		// configuration import does not call on_activate().
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
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( self::get_defaults() );
	}

	/**
	 * The password and the API key are NOT stored in `lumia_module_smtp` but in
	 * their own option: the configuration export writes module options as is
	 * into the JSON, and the activity log lists the fields that changed. Empty
	 * field = secret unchanged (the form never displays it again); the import,
	 * which carries none, therefore leaves it in place.
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
			// A Brevo key (`xkeysib-…`) only has letters, digits and dashes.
			self::store_secret( Mailer::BREVO_KEY_OPTION, (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', self::sanitize_password( $settings['brevo_key'] ?? '' ) ) );
		}

		return $this->save_module_settings( $sanitized );
	}

	/**
	 * Never in clear text: without openssl, the secret is not saved, and the
	 * screen reports it (see the template). Empty = unchanged.
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
	 * Host only: strip a scheme pasted by mistake (`smtp://`, `https://`) and
	 * refuse any character that has no place in a host name or an IP — the
	 * field ends up in a network connection.
	 */
	private static function sanitize_host( string $host ): string {
		$host = strtolower( trim( sanitize_text_field( $host ) ) );
		$host = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $host );
		$host = rtrim( $host, '/' );

		return preg_match( '/^[a-z0-9.\-]{1,253}$|^\[[0-9a-f:.]+\]$/', $host ) ? $host : '';
	}

	/**
	 * No sanitize_text_field(): it strips tags and `%xx` sequences, and a
	 * password may contain `<a` or `%41`. Only control characters are removed
	 * (a carriage return in an SMTP exchange ends the command).
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
	 * LIFECYCLE
	 * ================================================================ */

	public function on_activate(): void {
		Store::install();
	}

	/**
	 * The table stays: deactivating the module must not erase the history.
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
				'smLoading'        => __( 'Loading…', 'lumia-tools' ),
				'smEmpty'          => __( 'No emails match these criteria.', 'lumia-tools' ),
				'smError'          => __( 'Could not load the log.', 'lumia-tools' ),
				/* translators: %s: number of emails. */
				'smTotal'          => __( '%s email(s)', 'lumia-tools' ),
				/* translators: 1: current page, 2: number of pages. */
				'smPage'           => __( 'Page %1$s of %2$s', 'lumia-tools' ),
				'smSent'           => __( 'Sent', 'lumia-tools' ),
				'smFailed'         => __( 'Failed', 'lumia-tools' ),
				'smResentOf'       => __( 'Resend of', 'lumia-tools' ),
				'smNoSubject'      => __( '(no subject)', 'lumia-tools' ),
				'smTesting'        => __( 'Sending…', 'lumia-tools' ),
				'smResending'      => __( 'Resending…', 'lumia-tools' ),
				'smClearTitle'     => __( 'Clear the email log?', 'lumia-tools' ),
				'smClearMessage'   => __( 'All logged emails will be permanently deleted.', 'lumia-tools' ),
				'smClearConfirm'   => __( 'Clear log', 'lumia-tools' ),
				'smDate'           => __( 'Date', 'lumia-tools' ),
				'smStatus'         => __( 'Status', 'lumia-tools' ),
				'smFrom'           => __( 'Sender', 'lumia-tools' ),
				'smTo'             => __( 'Recipient', 'lumia-tools' ),
				'smTransport'      => __( 'Transport', 'lumia-tools' ),
				'smAttachments'    => __( 'Attachments', 'lumia-tools' ),
				'smMissing'        => __( 'not found', 'lumia-tools' ),
				'smProviderHint'   => __( 'Choosing a provider pre-fills the host, port and encryption; everything stays editable.', 'lumia-tools' ),
				'smTruncatedLabel' => __( 'Truncated', 'lumia-tools' ),
				'smTruncated'      => __( 'Message too long, truncated when saved: it cannot be resent.', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * Shared nonce + module capability, at the top of every endpoint.
	 */
	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( static::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ], 403 );
		}
	}

	public function ajax_list(): void {
		$this->guard();

		$filters = $this->read_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard(); read_filters() sanitizes each field.
		$page    = max( 1, absint( wp_unslash( $_POST['page'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard().
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
	 * Detail of an email: body, headers and attachments, absent from the list.
	 */
	public function ajax_detail(): void {
		$this->guard();

		$row = Store::get( absint( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard().

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Email not found.', 'lumia-tools' ) ], 404 );
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
	 * Replays wp_mail() with the original request. Attachments that no longer
	 * exist are dropped (form plugins often delete their temporary files after
	 * sending) and reported.
	 */
	public function ajax_resend(): void {
		$this->guard();

		$row = Store::get( absint( wp_unslash( $_POST['id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard().

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Email not found.', 'lumia-tools' ) ], 404 );
		}

		if ( $row['truncated'] ) {
			wp_send_json_error( [ 'message' => __( 'This email was truncated when saved: it cannot be resent as is.', 'lumia-tools' ) ] );
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

		// Replaying the wp_mail() arguments is not enough: many plugins set the
		// sender and content type through filters that are only active while
		// they send (WooCommerce: wp_mail_from, wp_mail_content_type). On
		// resend, those filters are absent — the order went out again from the
		// default sender, the HTML as plain text. So we reapply what actually
		// went out, as logged.
		// Priority 9000: below the forced sender's (9999), which keeps the
		// last word.
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

		$message = __( 'Email resent.', 'lumia-tools' );
		if ( $missing ) {
			/* translators: %s: names of the attachments that were not found. */
			$message .= ' ' . sprintf( __( 'Attachments not found, not attached: %s.', 'lumia-tools' ), implode( ', ', $missing ) );
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	public function ajax_clear(): void {
		$this->guard();

		Store::clear();

		wp_send_json_success( [ 'message' => __( 'Log cleared.', 'lumia-tools' ) ] );
	}

	/**
	 * Test email, with the transcript of the SMTP exchange (or the API
	 * response) on failure:
	 * "Could not authenticate" does not say whether the username, the port or
	 * the encryption is wrong, the server's response does.
	 */
	public function ajax_test(): void {
		$this->guard();

		$to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard().

		if ( ! is_email( $to ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid destination address.', 'lumia-tools' ) ] );
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

		// After Mailer::configure() (priority 10), which resets SMTPDebug to zero:
		// the PHPMailer instance is global, the next email must not inherit the
		// trace.
		add_action( 'phpmailer_init', $debug, 1000 );

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$error = $this->send_capturing_error(
			static function () use ( $to, $site ): bool {
				return wp_mail(
					$to,
					/* translators: %s: site name. */
					sprintf( __( 'Test email — %s', 'lumia-tools' ), $site ),
					'<p>' . esc_html__( 'This test email was sent from Lümia Tools. If you can read it, sending works.', 'lumia-tools' ) . '</p>'
					. '<p style="color:#666;font-size:12px">' . esc_html( home_url() ) . ' — ' . esc_html( (string) wp_date( 'Y-m-d H:i:s' ) ) . '</p>',
					[ 'Content-Type: text/html; charset=UTF-8' ]
				);
			}
		);

		remove_action( 'phpmailer_init', $debug, 1000 );

		// Sending through the API: the HTTP response stands in for the
		// transcript. It does not contain the key, which only goes in a header.
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
				/* translators: %s: destination address. */
				'message' => sprintf( __( 'Test email sent to %s.', 'lumia-tools' ), $to ),
			]
		);
	}

	/**
	 * Runs a send and returns the error message, or null if all went well.
	 * wp_mail() only returns false: the detail only exists in the WP_Error
	 * passed to `wp_mail_failed`.
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

		// Failure without a WP_Error: a pre_wp_mail short-circuited the send.
		return '' !== (string) $error ? (string) $error : __( 'Sending failed without an error message (another plugin may have intercepted it).', 'lumia-tools' );
	}

	/**
	 * Masks the authentication in the SMTP transcript. At debug level 2,
	 * PHPMailer writes the client commands as they are: the AUTH PLAIN line and
	 * the answers to AUTH LOGIN carry the username and password in base64 —
	 * that is, in clear text.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	public static function mask_transcript( array $lines ): array {
		$masked   = [];
		$in_auth  = false;
		$client   = 'CLIENT -> SERVER:';
		$server   = 'SERVER -> CLIENT:';
		$redacted = '[' . __( 'masked', 'lumia-tools' ) . ']';

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
				// 334 = the server is waiting for the rest of the authentication; any
				// other code ends it (235 succeeded, 5xx refused).
				$in_auth = 0 === strpos( trim( substr( $line, strlen( $server ) ) ), '334' );
			}

			$masked[] = $line;
		}

		return $masked;
	}

	/* ================================================================
	 * PRESENTATION
	 * ================================================================ */

	/**
	 * @param array<string, mixed> $source `$_POST` of a request whose nonce is already verified.
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
			'brevo'    => __( 'Brevo API', 'lumia-tools' ),
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
	 * "Name <address>" or "address" => name and address.
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
