<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gère les réglages globaux du plugin.
 */
class Settings {

	/**
	 * Clé d'option WordPress.
	 */
	private string $option_key = 'lumia_settings';

	/**
	 * Cache des settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Récupère tous les settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		if ( null === $this->settings ) {
			$stored         = get_option( $this->option_key, [] );
			$this->settings = is_array( $stored ) ? $stored : [];
		}
		return $this->settings;
	}

	/**
	 * Récupère une valeur de setting.
	 *
	 * @param string $key     Clé du setting (peut être imbriquée avec des points).
	 * @param mixed  $fallback Valeur par défaut.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$settings = $this->get_all();

		// Support des clés imbriquées (ex: "modules.image_optimizer")
		$keys  = explode( '.', $key );
		$value = $settings;

		foreach ( $keys as $k ) {
			if ( ! is_array( $value ) || ! array_key_exists( $k, $value ) ) {
				return $fallback;
			}
			$value = $value[ $k ];
		}

		return $value;
	}

	/**
	 * Met à jour une valeur de setting.
	 *
	 * @param string $key   Clé du setting.
	 * @param mixed  $value Nouvelle valeur.
	 */
	public function set( string $key, $value ): bool {
		$settings = $this->get_all();

		// Support des clés imbriquées
		$keys   = explode( '.', $key );
		$target = &$settings;

		foreach ( $keys as $k ) {
			if ( ! is_array( $target ) ) {
				$target = [];
			}
			if ( ! array_key_exists( $k, $target ) ) {
				$target[ $k ] = [];
			}
			$target = &$target[ $k ];
		}

		$target         = $value;
		$this->settings = $settings;

		return update_option( $this->option_key, $settings );
	}

	/**
	 * Met à jour plusieurs settings en une fois.
	 *
	 * @param array<string, mixed> $data Tableau de settings.
	 */
	public function update( array $data ): bool {
		$settings       = $this->get_all();
		$settings       = $this->merge_recursive( $settings, $data );
		$this->settings = $settings;

		return update_option( $this->option_key, $settings );
	}

	/**
	 * Fusionne deux tableaux récursivement en remplaçant les valeurs.
	 *
	 * @param array<string, mixed> $base
	 * @param array<string, mixed> $updates
	 * @return array<string, mixed>
	 */
	private function merge_recursive( array $base, array $updates ): array {
		foreach ( $updates as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->merge_recursive( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
