<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * The AVIF negotiation block of `wp-content/uploads/.htaccess` on Apache and LiteSpeed
 * (spec sections 1 and 9.2).
 *
 * - Only the lines between the markers belong to the plugin: the rest of the file is kept as
 *   is, and the file is deleted only when nothing else is left in it.
 * - Written through a temporary file in the same folder, then renamed: a half-written
 *   `.htaccess` would answer 500 for every upload.
 * - Every directive sits inside `<IfModule>`: a missing module never turns into a 500.
 *   A host that forbids these directives (`AllowOverride` without `FileInfo`) still answers
 *   500: the delivery self-test, run right after the write, removes the block at once.
 * - `RewriteOptions Inherit`: an `.htaccess` that turns the rewrite engine on stops the
 *   parent's rules (WordPress's) from applying below it; a missing file of the uploads would
 *   get Apache's 404 instead of WordPress's.
 * - `Vary: Accept` on the JPEG/PNG answer and on the rewritten AVIF one (`(\.avif)?`): the
 *   self-test refuses a delivery without it.
 */
final class HtaccessWriter {

	public const BEGIN = '# BEGIN Lumia Tools AVIF';
	public const END   = '# END Lumia Tools AVIF';

	/**
	 * The web server, from `SERVER_SOFTWARE` (or mod_php's apache_get_modules()):
	 * `apache`, `litespeed`, `nginx`, `other`, or '' when unknown (WP-CLI, system cron).
	 */
	public static function server_software(): string {
		if ( 'cli' === PHP_SAPI ) {
			return '';
		}

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		// OpenLiteSpeed and LiteSpeed Enterprise both say "LiteSpeed": they cannot be told
		// apart, the self-test decides.
		if ( str_contains( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( str_contains( $software, 'apache' ) || function_exists( 'apache_get_modules' ) ) {
			return 'apache';
		}
		if ( str_contains( $software, 'nginx' ) ) {
			return 'nginx';
		}

		return '' === $software ? '' : 'other';
	}

	/**
	 * Apache or LiteSpeed: a server that reads `.htaccess`.
	 */
	public static function is_supported_server(): bool {
		return in_array( self::server_software(), [ 'apache', 'litespeed' ], true );
	}

	/**
	 * The block, markers included, without a trailing newline.
	 */
	public function block(): string {
		return implode(
			"\n",
			[
				self::BEGIN,
				'<IfModule mod_mime.c>',
				'AddType image/avif .avif',
				'</IfModule>',
				'<IfModule mod_rewrite.c>',
				'RewriteEngine On',
				'RewriteOptions Inherit',
				'RewriteCond %{QUERY_STRING} !(^|&)original(=|&|$)',
				'RewriteCond %{HTTP_ACCEPT} image/avif',
				'RewriteCond %{REQUEST_FILENAME}.avif -f',
				'RewriteRule ^(.+\.(?:jpe?g|png))$ $1.avif [NC,T=image/avif,L]',
				'</IfModule>',
				'<IfModule mod_headers.c>',
				'<FilesMatch "\.(?i:jpe?g|png)(\.avif)?$">',
				'Header merge Vary Accept',
				'</FilesMatch>',
				'</IfModule>',
				self::END,
			]
		);
	}

	public function path(): string {
		return trailingslashit( wp_upload_dir( null, false )['basedir'] ) . '.htaccess';
	}

	/**
	 * A block of ours is in the file (current or not).
	 */
	public function is_present(): bool {
		$content = $this->read();

		return null !== $content && (bool) preg_match( $this->block_pattern(), $content );
	}

	/**
	 * The file holds exactly the current block, once.
	 */
	public function is_current(): bool {
		$content = $this->read();

		return null !== $content
			&& 1 === preg_match_all( $this->block_pattern(), $content, $matches )
			&& trim( $matches[0][0] ) === $this->block();
	}

	/**
	 * Puts the current block at the end of the file (replacing any previous one).
	 *
	 * @return bool True when the file holds the current block afterwards.
	 */
	public function write(): bool {
		if ( $this->is_current() ) {
			return true;
		}

		$content = $this->read();
		if ( null === $content ) {
			return false;
		}

		$rest = rtrim( $this->strip( $content ) );

		return $this->save( ( '' === $rest ? '' : $rest . "\n\n" ) . $this->block() . "\n" );
	}

	/**
	 * Takes the block out of the file; deletes the file when nothing else is left.
	 *
	 * @return bool True when no block is left.
	 */
	public function remove(): bool {
		$content = $this->read();
		if ( null === $content ) {
			return false;
		}
		if ( ! preg_match( $this->block_pattern(), $content ) ) {
			return true;
		}

		$rest = rtrim( $this->strip( $content ) );
		if ( '' === $rest ) {
			wp_delete_file( $this->path() );
			clearstatcache( true, $this->path() );

			return ! file_exists( $this->path() );
		}

		return $this->save( $rest . "\n" );
	}

	/**
	 * Every block of ours, markers included, with the blank lines before it.
	 */
	private function block_pattern(): string {
		return '/(?:\r?\n)*^' . preg_quote( self::BEGIN, '/' ) . '\r?$.*?^' . preg_quote( self::END, '/' ) . '\r?$\r?\n?/ms';
	}

	/**
	 * The file without our block(s); leading blank lines left by a block at the top go too.
	 */
	private function strip( string $content ): string {
		return ltrim( (string) preg_replace( $this->block_pattern(), "\n", $content ), "\r\n" );
	}

	/**
	 * Content of the file: '' when it does not exist, null when it cannot be read.
	 */
	private function read(): ?string {
		$path = $this->path();
		clearstatcache( true, $path );

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$content = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return false === $content ? null : $content;
	}

	/**
	 * Temporary file in the same folder, then rename(): never a half-written `.htaccess`.
	 * The name starts with `.ht`: Apache refuses to serve it.
	 */
	private function save( string $content ): bool {
		$path = $this->path();
		$dir  = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$tmp = $dir . '/.htaccess.lumia-' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local file, renamed below.
			return false;
		}

		$perms = file_exists( $path ) ? fileperms( $path ) : false;
		chmod( $tmp, false === $perms ? 0644 : ( $perms & 0777 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the file it replaces.

		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-folder atomic rename of a local file.
			wp_delete_file( $tmp );
			return false;
		}

		clearstatcache( true, $path );

		return true;
	}
}
