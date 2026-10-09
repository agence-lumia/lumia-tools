<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Journalise chaque appel à wp_mail() : ce qui a été demandé, ce qui est
 * réellement parti, et l'erreur s'il y en a une.
 *
 * Trois temps, parce qu'aucun hook ne voit tout :
 * - `wp_mail` (filtre) : la demande telle que l'appelant l'a faite, en-têtes
 *   et pièces jointes compris — c'est ce qu'il faut rejouer pour un renvoi ;
 * - `phpmailer_init` : le message final (expéditeur retenu après filtres,
 *   type de contenu, transport) ;
 * - `wp_mail_succeeded` / `wp_mail_failed` : l'issue. L'échec peut survenir
 *   avant `phpmailer_init` (adresse d'expéditeur invalide) : le message final
 *   est alors inconnu et la ligne se contente de la demande.
 *
 * Un `pre_wp_mail` qui court-circuite l'envoi ne déclenche ni succès ni
 * échec : rien n'est journalisé, rien n'est parti.
 */
class Logger {

	/**
	 * Demande en cours (arguments de wp_mail() après filtres).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $request = null;

	/**
	 * Message final lu sur PHPMailer.
	 *
	 * @var array<string, string>
	 */
	private array $final = [];

	/** Ligne d'origine quand l'envoi en cours est un renvoi. */
	private int $resent_of = 0;

	public function register(): void {
		// Priorité maximale : on veut la demande après tous les autres filtres.
		add_filter( 'wp_mail', [ $this, 'capture_request' ], PHP_INT_MAX );
		add_action( 'phpmailer_init', [ $this, 'capture_final' ], PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', [ $this, 'on_succeeded' ] );
		add_action( 'wp_mail_failed', [ $this, 'on_failed' ] );
	}

	/**
	 * Marque le prochain envoi comme renvoi de la ligne `$id`.
	 */
	public function set_resent_of( int $id ): void {
		$this->resent_of = $id;
	}

	/**
	 * @param mixed $atts
	 * @return mixed
	 */
	public function capture_request( $atts ) {
		$this->request = is_array( $atts ) ? $atts : null;
		$this->final   = [];

		return $atts;
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function capture_final( $phpmailer ): void {
		$from = (string) $phpmailer->From;

		$this->final = [
			'from'         => '' !== (string) $phpmailer->FromName ? $phpmailer->FromName . ' <' . $from . '>' : $from,
			'content_type' => (string) $phpmailer->ContentType,
			'transport'    => (string) $phpmailer->Mailer,
		];
	}

	/**
	 * @param mixed $mail_data compact( 'to', 'subject', 'message', 'headers', 'attachments' ).
	 */
	public function on_succeeded( $mail_data ): void {
		$this->write( Store::STATUS_SENT, is_array( $mail_data ) ? $mail_data : [], '' );
	}

	/**
	 * @param mixed $error WP_Error dont les données portent la demande.
	 */
	public function on_failed( $error ): void {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}

		$data = $error->get_error_data();

		$this->write( Store::STATUS_FAILED, is_array( $data ) ? $data : [], $error->get_error_message() );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function write( string $status, array $data, string $error ): void {
		$request = $this->request ?? $data;

		$headers = self::lines( $request['headers'] ?? [] );

		Store::insert(
			[
				'status'       => $status,
				'transport'    => $this->final['transport'] ?? '',
				'from_address' => $this->final['from'] ?? self::header_value( $headers, 'from' ),
				'to_address'   => implode( ', ', self::recipients( $request['to'] ?? '' ) ),
				'subject'      => (string) ( $request['subject'] ?? '' ),
				'message'      => (string) ( $request['message'] ?? '' ),
				'content_type' => $this->final['content_type'] ?? self::content_type( $headers ),
				'headers'      => $headers,
				'attachments'  => self::lines( $request['attachments'] ?? [] ),
				'error'        => $error,
				'resent_of'    => $this->resent_of,
			]
		);

		$this->request   = null;
		$this->final     = [];
		$this->resent_of = 0;
	}

	/**
	 * Destinataires sous la forme que wp_mail() accepte : chaîne séparée par
	 * des virgules ou tableau.
	 *
	 * @param mixed $to
	 * @return string[]
	 */
	private static function recipients( $to ): array {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ) ) );
	}

	/**
	 * En-têtes et pièces jointes : wp_mail() accepte une chaîne multiligne ou
	 * un tableau. On range toujours un tableau de lignes.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	private static function lines( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = explode( "\n", str_replace( "\r\n", "\n", (string) $value ) );
		}

		$lines = [];
		foreach ( $value as $line ) {
			if ( is_scalar( $line ) && '' !== trim( (string) $line ) ) {
				$lines[] = trim( (string) $line );
			}
		}

		return $lines;
	}

	/**
	 * @param string[] $headers
	 */
	private static function header_value( array $headers, string $name ): string {
		foreach ( $headers as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) && strtolower( trim( $parts[0] ) ) === $name ) {
				return trim( $parts[1] );
			}
		}

		return '';
	}

	/**
	 * @param string[] $headers
	 */
	private static function content_type( array $headers ): string {
		$value = self::header_value( $headers, 'content-type' );

		return '' === $value ? 'text/plain' : strtolower( trim( explode( ';', $value )[0] ) );
	}
}
