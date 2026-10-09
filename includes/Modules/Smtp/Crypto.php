<?php
namespace Lumia\Tools\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

/**
 * Chiffrement du mot de passe SMTP au repos.
 *
 * Le but n'est pas de résister à qui lit `wp-config.php` — la clé en vient —
 * mais qu'une fuite de la base seule (sauvegarde, dump SQL, export de l'onglet
 * Base de données) ne livre pas le mot de passe de la boîte d'envoi.
 *
 * AES-256-GCM : chiffrement authentifié, un texte altéré ou déchiffré avec la
 * mauvaise clé échoue au lieu de rendre des octets quelconques. FluentSMTP
 * emploie AES-256-CTR, sans authentification, et détecte l'échec par un sel
 * concaténé au clair ; GCM fait la même chose proprement.
 */
class Crypto {

	/** Préfixe de format : permet de changer d'algorithme sans casser l'existant. */
	const PREFIX = 'v1:';

	const CIPHER = 'aes-256-gcm';

	const IV_LENGTH  = 12;
	const TAG_LENGTH = 16;

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * @return string '' si openssl manque : mieux vaut refuser d'enregistrer
	 *                que stocker le mot de passe en clair à l'insu de l'utilisateur.
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

		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stockage binaire en option texte, pas d'obfuscation.
	}

	/**
	 * @return string|null null si la valeur ne se déchiffre pas : clés de
	 *                     `wp-config.php` changées (migration, régénération des
	 *                     sels), ou valeur altérée.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) || ! self::available() ) {
			return null;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- voir encrypt().

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
	 * Clé dérivée des clés du site. `LUMIA_ENCRYPTION_KEY` permet d'en fixer une
	 * qui survit à une régénération des sels de `wp-config.php`.
	 */
	private static function key(): string {
		if ( defined( 'LUMIA_ENCRYPTION_KEY' ) && '' !== (string) LUMIA_ENCRYPTION_KEY ) {
			$material = (string) LUMIA_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '' ) . ( defined( 'LOGGED_IN_SALT' ) ? (string) LOGGED_IN_SALT : '' );
		}

		// Sans clé du tout (wp-config.php incomplet), on retombe sur wp_salt(),
		// que WordPress génère et range en base : plus faible, mais jamais vide.
		if ( '' === $material ) {
			$material = wp_salt( 'logged_in' );
		}

		return hash( 'sha256', 'lumia-smtp|' . $material, true );
	}
}
