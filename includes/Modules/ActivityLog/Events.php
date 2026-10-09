<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue of logged events: groups and labels.
 *
 * An event belongs to a single group; the group is the unit used by the
 * exclusions and by the list filter. The `event` column of the table only
 * stores the key: the label is translated at display time, so a log written
 * in French reads back in English after a language change.
 */
class Events {

	/**
	 * Event groups.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function groups(): array {
		return [
			'auth'     => __( 'Logins', 'lumia-tools' ),
			'content'  => __( 'Content', 'lumia-tools' ),
			'media'    => __( 'Media', 'lumia-tools' ),
			'users'    => __( 'Users', 'lumia-tools' ),
			'plugins'  => __( 'Plugins', 'lumia-tools' ),
			'themes'   => __( 'Themes', 'lumia-tools' ),
			'options'  => __( 'WordPress settings', 'lumia-tools' ),
			'settings' => __( 'Lümia Tools settings', 'lumia-tools' ),
		];
	}

	/**
	 * Known events.
	 *
	 * @return array<string, array{group: string, label: string}>
	 */
	public static function all(): array {
		return [
			'login'            => [
				'group' => 'auth',
				'label' => __( 'Login', 'lumia-tools' ),
			],
			'login_failed'     => [
				'group' => 'auth',
				'label' => __( 'Login failed', 'lumia-tools' ),
			],
			'logout'           => [
				'group' => 'auth',
				'label' => __( 'Logout', 'lumia-tools' ),
			],
			'post_created'     => [
				'group' => 'content',
				'label' => __( 'Content created', 'lumia-tools' ),
			],
			'post_updated'     => [
				'group' => 'content',
				'label' => __( 'Content updated', 'lumia-tools' ),
			],
			'post_trashed'     => [
				'group' => 'content',
				'label' => __( 'Content trashed', 'lumia-tools' ),
			],
			'post_restored'    => [
				'group' => 'content',
				'label' => __( 'Content restored', 'lumia-tools' ),
			],
			'post_deleted'     => [
				'group' => 'content',
				'label' => __( 'Content deleted', 'lumia-tools' ),
			],
			'media_added'      => [
				'group' => 'media',
				'label' => __( 'Media added', 'lumia-tools' ),
			],
			'media_updated'    => [
				'group' => 'media',
				'label' => __( 'Media updated', 'lumia-tools' ),
			],
			'media_deleted'    => [
				'group' => 'media',
				'label' => __( 'Media deleted', 'lumia-tools' ),
			],
			'user_created'     => [
				'group' => 'users',
				'label' => __( 'User created', 'lumia-tools' ),
			],
			'user_updated'     => [
				'group' => 'users',
				'label' => __( 'Profile updated', 'lumia-tools' ),
			],
			'user_role'        => [
				'group' => 'users',
				'label' => __( 'Role changed', 'lumia-tools' ),
			],
			'user_deleted'     => [
				'group' => 'users',
				'label' => __( 'User deleted', 'lumia-tools' ),
			],
			'password_reset'   => [
				'group' => 'users',
				'label' => __( 'Password reset', 'lumia-tools' ),
			],
			'plugin_activated' => [
				'group' => 'plugins',
				'label' => __( 'Plugin activated', 'lumia-tools' ),
			],
			'plugin_disabled'  => [
				'group' => 'plugins',
				'label' => __( 'Plugin deactivated', 'lumia-tools' ),
			],
			'plugin_installed' => [
				'group' => 'plugins',
				'label' => __( 'Plugin installed', 'lumia-tools' ),
			],
			'plugin_updated'   => [
				'group' => 'plugins',
				'label' => __( 'Plugin updated', 'lumia-tools' ),
			],
			'plugin_deleted'   => [
				'group' => 'plugins',
				'label' => __( 'Plugin deleted', 'lumia-tools' ),
			],
			'theme_switched'   => [
				'group' => 'themes',
				'label' => __( 'Theme activated', 'lumia-tools' ),
			],
			'theme_installed'  => [
				'group' => 'themes',
				'label' => __( 'Theme installed', 'lumia-tools' ),
			],
			'theme_updated'    => [
				'group' => 'themes',
				'label' => __( 'Theme updated', 'lumia-tools' ),
			],
			'theme_deleted'    => [
				'group' => 'themes',
				'label' => __( 'Theme deleted', 'lumia-tools' ),
			],
			'option_updated'   => [
				'group' => 'options',
				'label' => __( 'Setting changed', 'lumia-tools' ),
			],
			'lumia_settings'   => [
				'group' => 'settings',
				'label' => __( 'Settings changed', 'lumia-tools' ),
			],
		];
	}

	/**
	 * Group of an event, '' if it is unknown.
	 */
	public static function group_of( string $event ): string {
		return self::all()[ $event ]['group'] ?? '';
	}

	/**
	 * Label of an event; the raw key if it is unknown (row written by a later
	 * version, or by a third party through the filter).
	 */
	public static function label( string $event ): string {
		return self::all()[ $event ]['label'] ?? $event;
	}

	/**
	 * Keys of the events of a group.
	 *
	 * @return string[]
	 */
	public static function in_group( string $group ): array {
		return array_keys(
			array_filter(
				self::all(),
				static function ( array $def ) use ( $group ): bool {
					return $def['group'] === $group;
				}
			)
		);
	}
}
