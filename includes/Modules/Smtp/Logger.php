<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Logs every call to wp_mail(): what was requested, what actually went out,
 * and the error if there is one.
 *
 * Three stages, because no single hook sees everything:
 * - `wp_mail` (filter): the request as the caller made it, headers and
 *   attachments included — this is what must be replayed for a resend;
 * - `phpmailer_init`: the final message (sender kept after filters, content
 *   type, transport);
 * - `wp_mail_succeeded` / `wp_mail_failed`: the outcome. A failure can happen
 *   before `phpmailer_init` (invalid sender address): the final message is
 *   then unknown and the row only holds the request.
 *
 * A `pre_wp_mail` that short-circuits sending triggers neither success nor
 * failure: nothing is logged, nothing was sent.
 */
class Logger {

	/**
	 * Request in progress (wp_mail() arguments after filters).
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $request = null;

	/**
	 * Final message read from PHPMailer.
	 *
	 * @var array<string, string>
	 */
	private array $final = [];

	/** Original row when the current send is a resend. */
	private int $resent_of = 0;

	public function register(): void {
		// Maximum priority: we want the request after all the other filters.
		add_filter( 'wp_mail', [ $this, 'capture_request' ], PHP_INT_MAX );
		add_action( 'phpmailer_init', [ $this, 'capture_final' ], PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', [ $this, 'on_succeeded' ] );
		add_action( 'wp_mail_failed', [ $this, 'on_failed' ] );
	}

	/**
	 * Marks the next send as a resend of row `$id`.
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
	 * @param mixed $error WP_Error whose data carries the request.
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
	 * Recipients in the form wp_mail() accepts: comma-separated string or
	 * array.
	 *
	 * @param mixed $to
	 * @return string[]
	 */
	private static function recipients( $to ): array {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );

		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $list ) ) ) );
	}

	/**
	 * Headers and attachments: wp_mail() accepts a multiline string or an
	 * array. We always store an array of lines.
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
