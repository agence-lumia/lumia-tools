<?php
namespace Lumia\Tools\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Résolution de l'IP cliente — source unique de vérité pour tout le module.
 *
 * Les en-têtes de proxy (CF-Connecting-IP, X-Forwarded-For) sont envoyés par le
 * client : sur un site qui n'est PAS derrière un proxy, n'importe qui peut les
 * fabriquer. Les lire sans condition rend le rate limiting inopérant — il suffit
 * de changer l'en-tête à chaque tentative pour repartir d'un compteur neuf — et
 * permet d'usurper une IP de la liste blanche.
 *
 * On ne les consulte donc que si l'administrateur a explicitement déclaré la
 * topologie du site (réglage « Origine de l'IP »). Par défaut : REMOTE_ADDR,
 * la seule valeur que le client ne peut pas falsifier.
 */
class ClientIp {

	/** Sources d'IP proposées dans les réglages. */
	const SOURCE_REMOTE_ADDR = 'remote_addr';
	const SOURCE_CLOUDFLARE  = 'cloudflare';
	const SOURCE_FORWARDED   = 'x_forwarded_for';

	const SOURCES = [
		self::SOURCE_REMOTE_ADDR,
		self::SOURCE_CLOUDFLARE,
		self::SOURCE_FORWARDED,
	];

	/** Valeur retournée quand aucune IP exploitable n'est disponible. */
	const UNKNOWN = '0.0.0.0';

	/**
	 * Retourne l'IP cliente selon la source déclarée.
	 *
	 * Toute valeur non conforme à une IP retombe sur REMOTE_ADDR : un en-tête
	 * de proxy absent ou malformé ne doit jamais produire une clé de compteur
	 * arbitraire.
	 *
	 * @param string $source Une des constantes SOURCE_*.
	 */
	public static function resolve( string $source = self::SOURCE_REMOTE_ADDR ): string {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );

		$candidate = '';

		if ( self::SOURCE_CLOUDFLARE === $source ) {
			$candidate = self::valid_ip( self::server( 'HTTP_CF_CONNECTING_IP' ) );
		} elseif ( self::SOURCE_FORWARDED === $source ) {
			$candidate = self::from_forwarded_for( self::server( 'HTTP_X_FORWARDED_FOR' ) );
		}

		if ( '' !== $candidate ) {
			return $candidate;
		}

		return '' !== $remote ? $remote : self::UNKNOWN;
	}

	/**
	 * Normalise une source lue depuis un formulaire ou une option.
	 *
	 * @param mixed $source
	 */
	public static function sanitize_source( $source ): string {
		$source = is_string( $source ) ? sanitize_key( $source ) : '';

		return in_array( $source, self::SOURCES, true ) ? $source : self::SOURCE_REMOTE_ADDR;
	}

	/**
	 * En-têtes de proxy effectivement présents sur la requête courante.
	 *
	 * Sert uniquement à informer l'administrateur dans l'écran de réglages :
	 * il choisit ainsi sa topologie en connaissance de cause, plutôt qu'en
	 * devinant. Ne JAMAIS s'en servir pour décider automatiquement de la
	 * source — leur présence est précisément ce qu'un attaquant contrôle.
	 *
	 * @return array<string, string> Nom d'en-tête => valeur brute.
	 */
	public static function detected_headers(): array {
		$found = [];

		foreach ( [
			'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
			'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
		] as $key => $label ) {
			$value = self::server( $key );
			if ( '' !== $value ) {
				$found[ $label ] = $value;
			}
		}

		return $found;
	}

	/**
	 * Extrait l'IP cliente d'un X-Forwarded-For.
	 *
	 * L'en-tête se lit « client, proxy1, proxy2… » : chaque intermédiaire
	 * ajoute à droite l'adresse qu'il a vue. La DERNIÈRE entrée est donc celle
	 * qu'a inscrite le proxy le plus proche de nous — la seule que le client ne
	 * puisse pas avoir écrite lui-même. Les entrées de gauche, elles, viennent
	 * telles quelles de la requête entrante.
	 */
	private static function from_forwarded_for( string $header ): string {
		if ( '' === $header ) {
			return '';
		}

		$parts = array_filter( array_map( 'trim', explode( ',', $header ) ) );
		if ( ! $parts ) {
			return '';
		}

		return self::valid_ip( (string) end( $parts ) );
	}

	/**
	 * Retourne '' si la valeur n'est pas une IP valide.
	 */
	private static function valid_ip( string $value ): string {
		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}

	private static function server( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	}
}
