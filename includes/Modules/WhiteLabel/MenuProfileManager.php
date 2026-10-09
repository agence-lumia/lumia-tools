<?php
namespace Lumia\Tools\Modules\WhiteLabel;

defined( 'ABSPATH' ) || exit;

/**
 * Gestion du stockage et de la résolution des profils de menu.
 */
class MenuProfileManager {

	const OPTION_KEY       = 'lumia_wl_menu_profiles';
	const CACHE_KEY_PREFIX = 'lumia_wl_menu_user_';

	/**
	 * Génération du cache : incrémentée à chaque mutation de profil et incluse
	 * dans la clé de transient. Invalider tout le monde coûte une écriture
	 * d'option, au lieu d'un delete_transient par utilisateur — plafonné à 500
	 * comptes, au-delà desquels les autres gardaient un profil périmé.
	 */
	const CACHE_GEN_OPTION = 'lumia_wl_menu_cache_gen';

	/* ================================================================
	 * CRUD
	 * ================================================================ */

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_all(): array {
		$profiles = get_option( self::OPTION_KEY, [] );
		return is_array( $profiles ) ? $profiles : [];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::get_all() as $profile ) {
			if ( isset( $profile['id'] ) && $profile['id'] === $id ) {
				return $profile;
			}
		}
		return null;
	}

	/**
	 * Insert ou met à jour un profil (match par id).
	 *
	 * @param array<string, mixed> $profile
	 */
	public static function save( array $profile ): bool {
		$profiles = self::get_all();
		$found    = false;

		foreach ( $profiles as $index => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $profile['id'] ) {
				$profiles[ $index ] = $profile;
				$found              = true;
				break;
			}
		}

		if ( ! $found ) {
			$profiles[] = $profile;
		}

		self::clear_all_cache();
		return (bool) update_option( self::OPTION_KEY, $profiles );
	}

	public static function delete( string $id ): bool {
		$profiles = array_values(
			array_filter(
				self::get_all(),
				fn( $p ) => ! isset( $p['id'] ) || $p['id'] !== $id
			)
		);

		self::clear_all_cache();
		return (bool) update_option( self::OPTION_KEY, $profiles );
	}

	/* ================================================================
	 * RÉSOLUTION PROFIL ACTIF
	 * ================================================================ */

	/**
	 * Retourne le profil actif le plus prioritaire pour un utilisateur donné.
	 *
	 * Priorité : include_users > include_roles > apply_to_all
	 * En cas d'égalité : profil le plus récent (updated_at).
	 * Les exclusions (exclude_users, exclude_roles) priment sur tout.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_active_for_user( int $user_id ): ?array {
		$cache_key = self::cache_key( $user_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return ! empty( $cached ) ? $cached : null;
		}

		$all_active = array_filter(
			self::get_all(),
			fn( $p ) => ( $p['status'] ?? '' ) === 'active'
		);

		if ( empty( $all_active ) ) {
			set_transient( $cache_key, [], 3600 );
			return null;
		}

		$user       = get_userdata( $user_id );
		$user_roles = $user ? (array) $user->roles : [];

		$by_user = null;
		$by_role = null;
		$by_all  = null;

		foreach ( $all_active as $profile ) {
			$exclude_users = array_map( 'intval', $profile['exclude_users'] ?? [] );
			$exclude_roles = $profile['exclude_roles'] ?? [];

			if ( in_array( $user_id, $exclude_users, true ) ) {
				continue;
			}

			$excluded_by_role = false;
			foreach ( $exclude_roles as $role ) {
				if ( in_array( $role, $user_roles, true ) ) {
					$excluded_by_role = true;
					break;
				}
			}
			if ( $excluded_by_role ) {
				continue;
			}

			$include_users = array_map( 'intval', $profile['include_users'] ?? [] );
			$include_roles = $profile['include_roles'] ?? [];
			$updated       = (int) ( $profile['updated_at'] ?? 0 );

			if ( in_array( $user_id, $include_users, true ) ) {
				if ( null === $by_user || $updated > (int) ( $by_user['updated_at'] ?? 0 ) ) {
					$by_user = $profile;
				}
			} elseif ( ! empty( $include_roles ) ) {
				foreach ( $include_roles as $role ) {
					if ( in_array( $role, $user_roles, true ) ) {
						if ( null === $by_role || $updated > (int) ( $by_role['updated_at'] ?? 0 ) ) {
							$by_role = $profile;
						}
						break;
					}
				}
			} elseif ( ! empty( $profile['apply_to_all'] ) ) {
				if ( null === $by_all || $updated > (int) ( $by_all['updated_at'] ?? 0 ) ) {
					$by_all = $profile;
				}
			}
		}

		$result = $by_user ?? $by_role ?? $by_all;
		set_transient( $cache_key, $result ?? [], 3600 );
		return $result;
	}

	/* ================================================================
	 * CACHE
	 * ================================================================ */

	private static function cache_key( int $user_id ): string {
		return self::CACHE_KEY_PREFIX . (int) get_option( self::CACHE_GEN_OPTION, 1 ) . '_' . $user_id;
	}

	public static function clear_user_cache( int $user_id = 0 ): void {
		if ( $user_id > 0 ) {
			delete_transient( self::cache_key( $user_id ) );
			return;
		}
		self::clear_all_cache();
	}

	/** Les transients de l'ancienne génération expirent d'eux-mêmes (1 h). */
	public static function clear_all_cache(): void {
		update_option( self::CACHE_GEN_OPTION, (int) get_option( self::CACHE_GEN_OPTION, 1 ) + 1 );
	}
}
