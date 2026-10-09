<?php
namespace Lumia\Tools\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin's global settings.
 */
class Settings {

	/**
	 * WordPress option key.
	 */
	private string $option_key = 'lumia_settings';

	/**
	 * Settings cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings = null;

	/**
	 * Gets all the settings.
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
	 * Gets a setting value.
	 *
	 * @param string $key      Setting key (can be nested with dots).
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$settings = $this->get_all();

		// Nested keys support (e.g. "modules.image_optimizer")
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
	 * Updates a setting value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public function set( string $key, $value ): bool {
		$settings = $this->get_all();

		// Nested keys support
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
	 * Updates several settings at once.
	 *
	 * @param array<string, mixed> $data Settings array.
	 */
	public function update( array $data ): bool {
		$settings       = $this->get_all();
		$settings       = $this->merge_recursive( $settings, $data );
		$this->settings = $settings;

		return update_option( $this->option_key, $settings );
	}

	/**
	 * Merges two arrays recursively, replacing values.
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
