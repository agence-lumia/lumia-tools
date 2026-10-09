<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * PHPMailer qui envoie par l'API HTTP de Brevo au lieu de SMTP ou de mail().
 *
 * Architecture de WP Mail SMTP : l'instance est installée dans
 * `$GLOBALS['phpmailer']` avant que wp_mail() ne la crée (il garde toute
 * instance existante), et seul send() change. wp_mail() construit donc le
 * message comme d'habitude — filtres, `phpmailer_init`, hooks de succès et
 * d'échec, journal : rien ne bouge. Un échec de l'API lève une exception
 * PHPMailer, que wp_mail() transforme en `wp_mail_failed`.
 *
 * Ce fichier ne doit être chargé qu'après PHPMailer : voir Mailer::install_brevo().
 */
class BrevoMailer extends PHPMailer {

	/** Valeur de `$Mailer` qui aiguille send() vers l'API. */
	const MAILER = Mailer::BREVO;

	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Clé API en clair, posée par Mailer::configure_brevo() avant chaque envoi.
	 */
	public string $lumia_api_key = '';

	/**
	 * Dernière réponse de l'API, pour le mail de test (jamais la clé).
	 *
	 * @var array{code: int, body: string}|null
	 */
	public ?array $lumia_last_response = null;

	/**
	 * @param bool|null $exceptions
	 */
	public function __construct( $exceptions = null ) {
		parent::__construct( $exceptions );

		// WP_PHPMailer (WordPress 6.8+) ne fait que traduire les messages
		// d'erreur, dans une propriété statique partagée avec PHPMailer.
		if ( class_exists( 'WP_PHPMailer' ) ) {
			\WP_PHPMailer::setLanguage();
		}
	}

	/**
	 * wp_mail() repasse le transport sur mail() à chaque appel : sans
	 * Mailer::configure_brevo() (réglages changés, autre transport posé par une
	 * extension), c'est l'envoi normal de PHPMailer.
	 *
	 * @throws PHPMailerException
	 */
	public function send() {
		if ( self::MAILER !== $this->Mailer ) {
			return parent::send();
		}

		$this->lumia_last_response = null;

		// Lu avant preSend(), qui passe le type sur multipart/alternative dès
		// qu'un AltBody existe : le HTML partirait sinon comme texte brut.
		$is_html = PHPMailer::CONTENT_TYPE_TEXT_HTML === $this->ContentType;

		try {
			// Validation des adresses et lecture des pièces jointes : les
			// erreurs sont celles, traduites, d'un envoi ordinaire.
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
			throw new PHPMailerException( __( 'Clé API Brevo absente ou illisible : saisissez-la à nouveau dans les réglages SMTP.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- texte brut (WP_Error, journal), pas du HTML.
		}

		$body = wp_json_encode( $this->payload( $is_html ) );

		if ( false === $body ) {
			throw new PHPMailerException( __( 'Message impossible à encoder pour l\'API Brevo (encodage des caractères invalide).', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- idem.
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
			/* translators: %s: message d'erreur réseau. */
			throw new PHPMailerException( sprintf( __( 'API Brevo injoignable : %s', 'lumia-tools' ), $response->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- idem.
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

		/* translators: 1: code HTTP, 2: message de l'API. */
		throw new PHPMailerException( sprintf( __( 'L\'API Brevo a refusé le mail (HTTP %1$d) : %2$s', 'lumia-tools' ), $code, $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- idem.
	}

	/**
	 * Corps de `POST /v3/smtp/email`, depuis le message tel que wp_mail() l'a
	 * assemblé.
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

		// Brevo n'accepte qu'une adresse de réponse : la première.
		$reply_to = array_values( $this->getReplyToAddresses() );
		if ( $reply_to ) {
			$payload['replyTo'] = self::contact( (string) $reply_to[0][0], (string) ( $reply_to[0][1] ?? '' ) );
		}

		// Corps jamais vide ici : preSend() l'a déjà refusé.
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
	 * Pièces jointes en base64. Les images intégrées (`cid:`) partent comme
	 * pièces jointes ordinaires : l'API n'a pas d'équivalent.
	 *
	 * @return array<int, array{content: string, name: string}>
	 * @throws PHPMailerException
	 */
	private function attachments(): array {
		$list = [];

		foreach ( $this->getAttachments() as $attachment ) {
			// [0] chemin ou contenu, [2] nom, [5] vrai si [0] est le contenu.
			if ( ! empty( $attachment[5] ) ) {
				$content = (string) $attachment[0];
			} else {
				$content = is_readable( (string) $attachment[0] ) ? file_get_contents( (string) $attachment[0] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local joint par wp_mail().
				if ( false === $content ) {
					/* translators: %s: nom du fichier. */
					throw new PHPMailerException( sprintf( __( 'Pièce jointe illisible : %s', 'lumia-tools' ), (string) $attachment[2] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- idem.
				}
			}

			$list[] = [
				'content' => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- format imposé par l'API.
				'name'    => (string) $attachment[2],
			];
		}

		return $list;
	}

	/**
	 * @param array<int|string, array{0: string, 1?: string}> $addresses Format de PHPMailer : [ adresse, nom ].
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
	 * Le nom est omis s'il est vide, plutôt qu'envoyé en `"name": ""`.
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
