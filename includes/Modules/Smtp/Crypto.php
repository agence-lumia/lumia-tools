<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Encryption of the SMTP password at rest.
 *
 * The goal is not to resist whoever can read `wp-config.php` — the key comes
 * from there — but to make sure a leak of the database alone (backup, SQL
 * dump, export from the Database tab) does not hand over the password of the
 * sending mailbox.
 *
 * AES-256-GCM: authenticated encryption, a tampered text or one decrypted with
 * the wrong key fails instead of returning arbitrary bytes. FluentSMTP uses
 * AES-256-CTR, without authentication, and detects the failure through a salt
 * concatenated to the plaintext; GCM does the same thing properly.
 */
class Crypto {

	/** Format prefix: allows changing the algorithm without breaking existing values. */
	const PREFIX = 'v1:';

	const CIPHER = 'aes-256-gcm';

	const IV_LENGTH  = 12;
	const TAG_LENGTH = 16;

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * @return string '' if openssl is missing: better to refuse to save than to
	 *                store the password in clear text without the user knowing.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::available() ) {
			return '';
		}

		$iv     = random_bytes( self::IV_LENGTH );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );

		if ( false === $cipher ) {
			return '';
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary storage in a text option, not obfuscation.
	}

	/**
	 * @return string|null null if the value cannot be decrypted: `wp-config.php`
	 *                     keys changed (migration, salt regeneration), or the
	 *                     value was tampered with.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) || ! self::available() ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().

		if ( false === $raw || strlen( $raw ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
			return null;
		}

		$plain = openssl_decrypt(
			substr( $raw, self::IV_LENGTH + self::TAG_LENGTH ),
			self::CIPHER,
			self::key(),
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::IV_LENGTH ),
			substr( $raw, self::IV_LENGTH, self::TAG_LENGTH )
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * Key derived from the site keys. `LUMIA_ENCRYPTION_KEY` allows setting one
	 * that survives a regeneration of the `wp-config.php` salts.
	 */
	private static function key(): string {
		if ( defined( 'LUMIA_ENCRYPTION_KEY' ) && '' !== (string) LUMIA_ENCRYPTION_KEY ) {
			$material = (string) LUMIA_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? (string) LOGGED_IN_SALT : '' );
		}

		// With no key at all (incomplete wp-config.php), fall back on wp_salt(),
		// which WordPress generates and stores in the database: weaker, but never empty.
		if ( '' === $material ) {
			$material = wp_salt( 'logged_in' );
		}

		return hash( 'sha256', 'lumia-smtp|' . $material, true );
	}
}
