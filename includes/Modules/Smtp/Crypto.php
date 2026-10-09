<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Compat;

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

	/** Key derivation context, prepended to the key material. */
	const CONTEXT = 'lumia-smtp|';

	/**
	 * Context of Studio Kyne Mini Tools, the former name of the plugin: only read
	 * by reencrypt_from_legacy(), during the migration of its data.
	 */
	const LEGACY_CONTEXT = 'skmt-smtp|';

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
		return self::decrypt_with( $stored, self::key() );
	}

	/**
	 * Re-encrypts a value written by Studio Kyne Mini Tools (`'skmt-smtp|'`
	 * context, see legacy_key()) with the Lumia key. On a site without
	 * `LUMIA_ENCRYPTION_KEY` both keys share the same material, so a site that
	 * fixed it with `SKMT_ENCRYPTION_KEY` keeps reading its secret.
	 *
	 * @return string The Lumia cipher text; `$stored` unchanged when the legacy
	 *                value cannot be decrypted (salts regenerated since, value
	 *                tampered with, openssl missing): the same state as before,
	 *                the administrator types the secret again.
	 */
	public static function reencrypt_from_legacy( string $stored ): string {
		$plain = self::decrypt_with( $stored, self::legacy_key() );

		if ( null === $plain || '' === $plain ) {
			return $stored;
		}

		$encrypted = self::encrypt( $plain );

		return '' === $encrypted ? $stored : $encrypted;
	}

	private static function decrypt_with( string $stored, string $key ): ?string {
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
			$key,
			OPENSSL_RAW_DATA,
			substr( $raw, 0, self::IV_LENGTH ),
			substr( $raw, self::IV_LENGTH, self::TAG_LENGTH )
		);

		return false === $plain ? null : $plain;
	}

	/**
	 * Key derived from the site keys. `LUMIA_ENCRYPTION_KEY` (or the legacy
	 * `SKMT_ENCRYPTION_KEY`) allows setting one that survives a regeneration of
	 * the `wp-config.php` salts.
	 */
	private static function key(): string {
		return self::derive( self::CONTEXT, Compat::constant( 'ENCRYPTION_KEY' ) );
	}

	/**
	 * Key Studio Kyne Mini Tools encrypted with, from ITS material order:
	 * `SKMT_ENCRYPTION_KEY`, else the salts, else wp_salt(). Never
	 * `LUMIA_ENCRYPTION_KEY`: SKMT did not know it, and a site that defined it
	 * before migrating (the help texts name it) must still decrypt its secrets.
	 */
	private static function legacy_key(): string {
		return self::derive( self::LEGACY_CONTEXT, Compat::legacy_constant( 'ENCRYPTION_KEY' ) );
	}

	/**
	 * @param string $context  self::CONTEXT or self::LEGACY_CONTEXT.
	 * @param mixed  $constant Value of the encryption-key constant, null if undefined.
	 */
	private static function derive( string $context, $constant ): string {
		if ( null !== $constant && '' !== (string) $constant ) {
			$material = (string) $constant;
		} else {
			$material = ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? (string) LOGGED_IN_SALT : '' );
		}

		// With no key at all (incomplete wp-config.php), fall back on wp_salt(),
		// which WordPress generates and stores in the database: weaker, but never empty.
		if ( '' === $material ) {
			$material = wp_salt( 'logged_in' );
		}

		return hash( 'sha256', $context . $material, true );
	}
}
