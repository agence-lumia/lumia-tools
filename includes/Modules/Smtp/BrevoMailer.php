<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer that sends through the Brevo HTTP API instead of SMTP or mail().
 *
 * WP Mail SMTP architecture: the instance is installed in
 * `$GLOBALS['phpmailer']` before wp_mail() creates it (it keeps any existing
 * instance), and only send() changes. wp_mail() therefore builds the message
 * as usual — filters, `phpmailer_init`, success and failure hooks, log:
 * nothing moves. An API failure throws a PHPMailer exception, which wp_mail()
 * turns into `wp_mail_failed`.
 *
 * This file must only be loaded after PHPMailer: see Mailer::install_brevo().
 */
class BrevoMailer extends PHPMailer {

	/** Value of `$Mailer` that routes send() to the API. */
	const MAILER = Mailer::BREVO;

	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Clear-text API key, set by Mailer::configure_brevo() before each send.
	 */
	public string $lumia_api_key = '';

	/**
	 * Last API response, for the test email (never the key).
	 *
	 * @var array{code: int, body: string}|null
	 */
	public ?array $lumia_last_response = null;

	/**
	 * @param bool|null $exceptions
	 */
	public function __construct( $exceptions = null ) {
		parent::__construct( $exceptions );

		// WP_PHPMailer (WordPress 6.8+) only translates the error messages,
		// in a static property shared with PHPMailer.
		if ( class_exists( 'WP_PHPMailer' ) ) {
			\WP_PHPMailer::setLanguage();
		}
	}

	/**
	 * wp_mail() sets the transport back to mail() on every call: without
	 * Mailer::configure_brevo() (settings changed, another transport set by a
	 * plugin), this is PHPMailer's normal sending.
	 *
	 * @throws PHPMailerException
	 */
	public function send() {
		if ( self::MAILER !== $this->Mailer ) {
			return parent::send();
		}

		$this->lumia_last_response = null;

		// Read before preSend(), which switches the type to multipart/alternative
		// as soon as an AltBody exists: the HTML would otherwise go out as plain text.
		$is_html = PHPMailer::CONTENT_TYPE_TEXT_HTML === $this->ContentType;

		try {
			// Address validation and attachment reading: the errors are the
			// translated ones of an ordinary send.
			if ( ! $this->preSend() ) {
				return false;
			}

			return $this->send_via_api( $is_html );
		} catch ( PHPMailerException $exc ) {
			$this->setError( $exc->getMessage() );
			if ( $this->exceptions ) {
				throw $exc;
			}

			return false;
		}
	}

	/**
	 * @throws PHPMailerException
	 */
	private function send_via_api( bool $is_html ): bool {
		if ( '' === $this->lumia_api_key ) {
			throw new PHPMailerException( __( 'Brevo API key missing or unreadable: enter it again in the SMTP settings.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text (WP_Error, log), not HTML.
		}

		$body = wp_json_encode( $this->payload( $is_html ) );

		if ( false === $body ) {
			throw new PHPMailerException( __( 'Message cannot be encoded for the Brevo API (invalid character encoding).', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- same.
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => Mailer::TIMEOUT,
				'headers' => [
					'api-key'      => $this->lumia_api_key,
					'accept'       => 'application/json',
					'content-type' => 'application/json',
				],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: network error message. */
			throw new PHPMailerException( sprintf( __( 'Brevo API unreachable: %s', 'lumia-tools' ), $response->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- same.
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		$this->lumia_last_response = [
			'code' => $code,
			'body' => $raw,
		];

		if ( $code >= 200 && $code < 300 ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && ! empty( $data['messageId'] ) ) {
				$this->lastMessageID = (string) $data['messageId'];
			}

			return true;
		}

		$data    = json_decode( $raw, true );
		$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : wp_remote_retrieve_response_message( $response );

		/* translators: 1: HTTP code, 2: API message. */
		throw new PHPMailerException( sprintf( __( 'The Brevo API rejected the email (HTTP %1$d): %2$s', 'lumia-tools' ), $code, $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- same.
	}

	/**
	 * Body of `POST /v3/smtp/email`, from the message as wp_mail() assembled it.
	 *
	 * @return array<string, mixed>
	 * @throws PHPMailerException
	 */
	private function payload( bool $is_html ): array {
		$payload = [
			'sender'  => self::contact( $this->From, $this->FromName ),
			'to'      => self::contacts( $this->getToAddresses() ),
			'subject' => $this->Subject,
		];

		$cc = self::contacts( $this->getCcAddresses() );
		if ( $cc ) {
			$payload['cc'] = $cc;
		}

		$bcc = self::contacts( $this->getBccAddresses() );
		if ( $bcc ) {
			$payload['bcc'] = $bcc;
		}

		// Brevo only accepts one reply-to address: the first one.
		$reply_to = array_values( $this->getReplyToAddresses() );
		if ( $reply_to ) {
			$payload['replyTo'] = self::contact( (string) $reply_to[0][0], (string) ( $reply_to[0][1] ?? '' ) );
		}

		// Body never empty here: preSend() already refused it.
		if ( $is_html ) {
			$payload['htmlContent'] = $this->Body;
			if ( '' !== $this->AltBody ) {
				$payload['textContent'] = $this->AltBody;
			}
		} else {
			$payload['textContent'] = $this->Body;
		}

		$headers = [];
		foreach ( $this->getCustomHeaders() as $header ) {
			$headers[ (string) $header[0] ] = (string) $header[1];
		}
		if ( $headers ) {
			$payload['headers'] = $headers;
		}

		$attachments = $this->attachments();
		if ( $attachments ) {
			$payload['attachment'] = $attachments;
		}

		return $payload;
	}

	/**
	 * Attachments in base64. Embedded images (`cid:`) go out as ordinary
	 * attachments: the API has no equivalent.
	 *
	 * @return array<int, array{content: string, name: string}>
	 * @throws PHPMailerException
	 */
	private function attachments(): array {
		$list = [];

		foreach ( $this->getAttachments() as $attachment ) {
			// Index 0 is the path or the content, 2 the name, 5 is true when index 0 holds the content.
			if ( ! empty( $attachment[5] ) ) {
				$content = (string) $attachment[0];
			} else {
				$content = is_readable( (string) $attachment[0] ) ? file_get_contents( (string) $attachment[0] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file attached by wp_mail().
				if ( false === $content ) {
					/* translators: %s: file name. */
					throw new PHPMailerException( sprintf( __( 'Unreadable attachment: %s', 'lumia-tools' ), (string) $attachment[2] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- same.
				}
			}

			$list[] = [
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format required by the API.
				'name'    => (string) $attachment[2],
			];
		}

		return $list;
	}

	/**
	 * @param array<int|string, array{0: string, 1?: string}> $addresses PHPMailer format: [ address, name ].
	 * @return array<int, array{email: string, name?: string}>
	 */
	private static function contacts( array $addresses ): array {
		$list = [];

		foreach ( $addresses as $address ) {
			$list[] = self::contact( (string) $address[0], (string) ( $address[1] ?? '' ) );
		}

		return $list;
	}

	/**
	 * The name is omitted when empty, rather than sent as `"name": ""`.
	 *
	 * @return array{email: string, name?: string}
	 */
	private static function contact( string $email, string $name ): array {
		return '' !== $name ? [
			'email' => $email,
			'name'  => $name,
		] : [ 'email' => $email ];
	}
}
