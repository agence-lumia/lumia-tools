<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Compat;

/**
 * Role `lumia_mcp_agent` and the dedicated `claude-mcp` account that Claude
 * acts as by default.
 *
 * - A dedicated account rather than the approver's: what Claude does is
 *   attributed to it in the revisions and the Activity Log, and its
 *   permissions come from a preset: Editor (the default) never reaches the
 *   site settings, plugins, themes, users or code; Administrator does.
 * - The account only exists to carry OAuth connections: it cannot sign in with
 *   a password (it has none anyone knows), cannot use application passwords
 *   and never sees the dashboard.
 * - The role is resynced to the preset on every admin page load of an
 *   administrator, and the dedicated account is reset to that role alone
 *   (reset_account()), so a capability or a second role added by hand or by
 *   another plugin, on the role or on the account, does not survive the next
 *   one. Between two syncs, deny_forbidden_caps() keeps FORBIDDEN_CAPS out of
 *   the account at check time (Editor preset).
 */
final class Agent {

	/** Role name. */
	const ROLE = 'lumia_mcp_agent';

	/** Option holding the dedicated account's id (not autoloaded). */
	const USER_OPTION = 'lumia_mcp_agent_user_id';

	/** Preferred login of the dedicated account. */
	const LOGIN = 'claude-mcp';

	/** Display name of the role and of the account (a product name: not translated). */
	const DISPLAY_NAME = 'Claude (MCP)';

	/** Preset with every capability of the site, except FORBIDDEN_CAPS. */
	const PRESET_EDITOR = 'editor';

	/** Preset with every capability of an administrator. */
	const PRESET_ADMIN = 'admin';

	/** Preset used when the setting is unknown (former presets included). */
	const DEFAULT_PRESET = self::PRESET_EDITOR;

	/**
	 * Capabilities the Editor preset never gets, whatever the filter says
	 * (dropped by capabilities()), and that a locked agent never holds under
	 * it, whatever its role or account says (denied by deny_forbidden_caps()):
	 * each one hands over the site (settings, code, users). The Administrator
	 * preset keeps them all.
	 */
	const FORBIDDEN_CAPS = [
		'manage_options',
		'edit_users',
		'create_users',
		'delete_users',
		'promote_users',
		'list_users',
		'install_plugins',
		'activate_plugins',
		'edit_plugins',
		'update_plugins',
		'delete_plugins',
		'install_themes',
		'switch_themes',
		'edit_themes',
		'update_themes',
		'delete_themes',
		'update_core',
		'unfiltered_upload',
		'unfiltered_html',
		'edit_files',
		'export',
		'import',
		'bricks_execute_code',
		// Legacy user levels: some plugins still read level_10 as "administrator".
		'level_8',
		'level_9',
		'level_10',
		// Multisite: Files and Database require manage_network_options there.
		'manage_network',
		'manage_network_options',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_sites',
		'create_sites',
		'delete_sites',
		'upgrade_network',
		'setup_network',
	];

	/** Option holding the Bricks global settings. */
	const BRICKS_SETTINGS_OPTION = 'bricks_global_settings';

	/** Maximum `-N` suffix tried when the preferred login or email is taken. */
	const MAX_SUFFIX = 50;

	/**
	 * Permission presets offered in the settings.
	 *
	 * Both follow the administrator role rather than a fixed list, so whatever
	 * a plugin grants administrators (WooCommerce, Rank Math, a form or booking
	 * plugin) Claude gets too, without a preset per stack. Editor then drops
	 * FORBIDDEN_CAPS (capabilities()); Administrator keeps everything.
	 *
	 * @return array<string, array{label: string, caps: array<string, bool>}>
	 */
	public static function presets(): array {
		$admin = self::administrator_caps();

		return [
			self::PRESET_EDITOR => [
				'label' => _x( 'Editor', 'MCP Connect permission preset', 'lumia-tools' ),
				'caps'  => $admin,
			],
			self::PRESET_ADMIN  => [
				'label' => _x( 'Administrator', 'MCP Connect permission preset', 'lumia-tools' ),
				'caps'  => $admin,
			],
		];
	}

	/**
	 * Capabilities granted to the administrator role, plus builder access.
	 *
	 * Bricks gives administrators the builder without a capability on the role,
	 * so `bricks_full_access` is added (harmless where Bricks is absent). With
	 * no administrator role (renamed), the editor role and the core content
	 * capabilities are the floor.
	 *
	 * @return array<string, bool>
	 */
	private static function administrator_caps(): array {
		$caps = self::grant(
			[
				'read',
				'edit_posts',
				'edit_others_posts',
				'edit_published_posts',
				'publish_posts',
				'delete_posts',
				'delete_others_posts',
				'delete_published_posts',
				'edit_pages',
				'edit_others_pages',
				'edit_published_pages',
				'publish_pages',
				'delete_pages',
				'delete_others_pages',
				'delete_published_pages',
				'edit_private_posts',
				'read_private_posts',
				'delete_private_posts',
				'edit_private_pages',
				'read_private_pages',
				'delete_private_pages',
				'upload_files',
				'manage_categories',
				'moderate_comments',
				'edit_theme_options',
				'bricks_full_access',
			]
		);

		foreach ( [ 'administrator', 'editor' ] as $name ) {
			$role = get_role( $name );
			if ( $role instanceof \WP_Role ) {
				foreach ( $role->capabilities as $cap => $grant ) {
					if ( $grant ) {
						$caps[ (string) $cap ] = true;
					}
				}
			}
		}

		return $caps;
	}

	/**
	 * Preset stored in the settings, the default one when it is unknown (a
	 * preset of 2.3.0, "Content and Bricks" and the like, reads as Editor).
	 * Runs on every capability check of the agent (deny_forbidden_caps()): it
	 * does not build the presets.
	 */
	public static function current_preset(): string {
		$preset = Module::settings()['agent_preset'] ?? '';

		return in_array( $preset, [ self::PRESET_EDITOR, self::PRESET_ADMIN ], true ) ? $preset : self::DEFAULT_PRESET;
	}

	/**
	 * Capabilities of a preset, after the `lumia_mcp_connect_agent_capabilities`
	 * filter. An unknown preset falls back to the default one; under Editor the
	 * forbidden capabilities are dropped after the filter.
	 *
	 * @return array<string, bool>
	 */
	public static function capabilities( string $preset ): array {
		$presets = self::presets();
		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = self::DEFAULT_PRESET;
		}

		$filtered = Compat::apply_filters( 'mcp_connect_agent_capabilities', $presets[ $preset ]['caps'], $preset );
		if ( ! is_array( $filtered ) ) {
			$filtered = $presets[ $preset ]['caps'];
		}

		$forbidden = self::forbidden_caps( $preset );
		$caps      = [];
		foreach ( $filtered as $cap => $grant ) {
			if ( ! is_string( $cap ) || '' === $cap || in_array( $cap, $forbidden, true ) ) {
				continue;
			}
			$caps[ $cap ] = (bool) $grant;
		}

		// Without `read` the MCP Adapter's transport check refuses every call.
		$caps['read'] = true;

		return $caps;
	}

	/**
	 * Capabilities a preset never grants: FORBIDDEN_CAPS under Editor, none
	 * under Administrator.
	 *
	 * @return string[]
	 */
	public static function forbidden_caps( string $preset ): array {
		return self::PRESET_ADMIN === $preset ? [] : self::FORBIDDEN_CAPS;
	}

	/**
	 * Creates the role, or resets its capabilities to the preset.
	 *
	 * Called on every admin page load of an administrator
	 * (Module::maybe_ensure_agent()), on activation, on a preset change and on
	 * an approval in agent mode (through ensure()): when nothing changed it
	 * only compares two arrays. When something did, the
	 * role is dropped and recreated (two option writes) rather than updated cap
	 * by cap (one write per capability). Users keep the role in between: they
	 * store its name, not its capabilities.
	 */
	public static function sync_role( string $preset ): void {
		$caps = self::capabilities( $preset );
		$role = get_role( self::ROLE );

		if ( $role instanceof \WP_Role ) {
			$current = array_map( 'boolval', $role->capabilities );
			ksort( $current );
			ksort( $caps );
			if ( $current === $caps ) {
				return;
			}
			remove_role( self::ROLE );
		}

		add_role( self::ROLE, self::DISPLAY_NAME, $caps );
	}

	/**
	 * Makes sure the role and the dedicated account exist, and returns the
	 * account id (0 if it could not be created).
	 */
	public static function ensure(): int {
		self::sync_role( self::current_preset() );

		$user_id = self::user_id();
		if ( $user_id > 0 ) {
			self::reset_account( $user_id );
			return $user_id;
		}

		// The account still exists but lost the role (changed by hand on the
		// user screen): BearerAuth refuses an agent connection whose user is
		// not an agent, so every new connection would be dead on arrival.
		// The account exists only for this role: it gets it back.
		$stored = get_userdata( (int) get_option( self::USER_OPTION, 0 ) );
		if ( $stored instanceof \WP_User ) {
			$stored->set_role( self::ROLE );
			return (int) $stored->ID;
		}

		$user_id = self::create_user();
		if ( $user_id > 0 ) {
			update_option( self::USER_OPTION, $user_id, false );
		}

		return $user_id;
	}

	/**
	 * Resets the dedicated account to the agent role alone: drops any second
	 * role and any capability granted on the account itself. sync_role() only
	 * rebuilds the role, and the capabilities of an `administrator` or
	 * `shop_manager` role added to the account would otherwise stay live for
	 * every connection. Writes nothing when the account is already clean.
	 */
	public static function reset_account( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		if ( [ self::ROLE ] !== array_values( (array) $user->roles ) ) {
			$user->set_role( self::ROLE );
		}

		// $user->caps mixes role names (role => true) with capabilities granted
		// on the account: only the latter are removed.
		foreach ( array_keys( (array) $user->caps ) as $cap ) {
			$cap = (string) $cap;
			if ( self::ROLE !== $cap && null === get_role( $cap ) ) {
				$user->remove_cap( $cap );
			}
		}
	}

	/**
	 * Creates the dedicated account, or adopts an existing one carrying the role
	 * (option lost, e.g. after a database import without it).
	 */
	private static function create_user(): int {
		$login = '';
		for ( $i = 1; $i <= self::MAX_SUFFIX; $i++ ) {
			$candidate = 1 === $i ? self::LOGIN : self::LOGIN . '-' . $i;
			$existing  = get_user_by( 'login', $candidate );

			if ( ! $existing instanceof \WP_User ) {
				$login = $candidate;
				break;
			}
			if ( in_array( self::ROLE, (array) $existing->roles, true ) ) {
				return (int) $existing->ID;
			}
		}

		if ( '' === $login ) {
			return 0;
		}

		$email = self::free_email();
		if ( '' === $email ) {
			return 0;
		}

		// A random password nobody ever sees: block_login() refuses it anyway.
		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => $email,
				'display_name' => self::DISPLAY_NAME,
				'nickname'     => self::DISPLAY_NAME,
				'role'         => self::ROLE,
			]
		);

		return is_wp_error( $user_id ) ? 0 : (int) $user_id;
	}

	/**
	 * An unused address in the reserved `.invalid` domain (RFC 2606): no mail can
	 * ever be delivered, and the site host keeps it unique across a multisite
	 * network, where emails are shared.
	 */
	private static function free_email(): string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', $host ), '.-' );
		$base = self::LOGIN . ( '' !== $host ? '+' . $host : '' );

		for ( $i = 1; $i <= self::MAX_SUFFIX; $i++ ) {
			$email = $base . ( 1 === $i ? '' : '-' . $i ) . '@invalid.invalid';
			if ( ! email_exists( $email ) ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Id of the dedicated account if it still exists and still carries the
	 * agent role, else 0 (ensure() then repairs it).
	 */
	public static function user_id(): int {
		$user_id = (int) get_option( self::USER_OPTION, 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User && self::is_agent( $user ) ? $user_id : 0;
	}

	/**
	 * Whether an approver may hand a connection to the dedicated account.
	 *
	 * An administrator always may. Anyone else (approver capability set to
	 * edit_others_pages) only when they hold every capability of the active
	 * preset themselves: otherwise approving would give their own Claude
	 * session rights they do not have (Bricks builder, shop management…).
	 * Since the presets follow the administrator role, that is rarely the case
	 * for an editor: they connect Claude as themselves.
	 */
	public static function can_delegate( \WP_User $approver ): bool {
		if ( user_can( $approver, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::capabilities( self::current_preset() ) as $cap => $grant ) {
			if ( $grant && ! user_can( $approver, $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a user carries the agent role.
	 */
	public static function is_agent( \WP_User $user ): bool {
		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/**
	 * Whether a user is only an agent: the dedicated account, or a user whose
	 * single role is the agent role. A real person who was also given the role
	 * keeps their sign-in and dashboard.
	 */
	private static function is_locked_agent( \WP_User $user ): bool {
		if ( ! self::is_agent( $user ) ) {
			return false;
		}

		return self::user_id() === (int) $user->ID || 1 === count( (array) $user->roles );
	}

	/**
	 * `user_has_cap` (late): under the Editor preset, a locked agent (the
	 * dedicated account, or a user whose single role is the agent role) never
	 * holds FORBIDDEN_CAPS, even when a role editor, another plugin or Bricks'
	 * role settings wrote one on the role or the account since the last
	 * sync_role(). A real person who was
	 * also given the role is left alone, like for the sign-in.
	 *
	 * @param array<string, bool>|mixed $allcaps Capabilities the user holds.
	 * @param mixed                     $caps    Primitive capabilities checked.
	 * @param mixed                     $args    Arguments of the check.
	 * @param \WP_User|mixed            $user    User checked.
	 * @return array<string, bool>|mixed
	 */
	public static function deny_forbidden_caps( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args );

		if ( ! is_array( $allcaps ) || ! $user instanceof \WP_User || ! self::is_locked_agent( $user ) ) {
			return $allcaps;
		}

		foreach ( self::forbidden_caps( self::current_preset() ) as $cap ) {
			if ( isset( $allcaps[ $cap ] ) ) {
				$allcaps[ $cap ] = false;
			}
		}

		return $allcaps;
	}

	/**
	 * `wp_authenticate_user`: the agent account cannot sign in with a password.
	 *
	 * @param \WP_User|\WP_Error $user     User found by the login, or an error.
	 * @param string             $password Password submitted.
	 * @return \WP_User|\WP_Error
	 */
	public static function block_login( $user, $password ) {
		unset( $password );

		if ( $user instanceof \WP_User && self::is_locked_agent( $user ) ) {
			return new \WP_Error( 'lumia_mcp_agent_login', __( 'This account is used by Claude through MCP Connect and cannot sign in.', 'lumia-tools' ) );
		}

		return $user;
	}

	/**
	 * `wp_authenticate_application_password_errors`: nor with an application
	 * password (one created before the account was locked down, or by hand).
	 */
	public static function block_app_passwords( \WP_Error $error, \WP_User $user ): void {
		if ( self::is_locked_agent( $user ) ) {
			$error->add( 'lumia_mcp_agent_app_password', __( 'This account is used by Claude through MCP Connect and cannot use application passwords.', 'lumia-tools' ) );
		}
	}

	/**
	 * `admin_init`: the agent account never sees the dashboard. AJAX and
	 * admin-post requests are left alone: they are not screens, and some
	 * front-end features post to them.
	 */
	public static function block_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'admin-post.php' === basename( $script ) ) {
			return;
		}

		if ( self::is_locked_agent( wp_get_current_user() ) ) {
			wp_die(
				esc_html__( 'This account is used by Claude through MCP Connect and has no access to the dashboard.', 'lumia-tools' ),
				'',
				[ 'response' => 403 ]
			);
		}
	}

	/**
	 * Builder access of the agent role on a Bricks site.
	 *
	 * Bricks grants builder access through its own role settings, which write the
	 * `bricks_full_access` / `bricks_edit_content` capabilities on the role, and
	 * may remove them when those settings are saved: the role is checked, not
	 * the preset.
	 *
	 * @return string 'not_bricks' | 'ok' | 'missing'
	 */
	public static function bricks_status(): string {
		if ( 'bricks' !== get_template() ) {
			return 'not_bricks';
		}

		$role = get_role( self::ROLE );
		if ( $role instanceof \WP_Role && ( $role->has_cap( 'bricks_full_access' ) || $role->has_cap( 'bricks_edit_content' ) ) ) {
			return 'ok';
		}

		return 'missing';
	}

	/**
	 * Whether Bricks registers its abilities (Bricks > AI > "Enable Bricks
	 * abilities", global setting `abilitiesApi`, Bricks 2.4 or later). Off by
	 * default in Bricks: the MCP Adapter then only lists the core read-only
	 * abilities, and Claude, though connected, cannot edit anything.
	 *
	 * @return string 'not_bricks' | 'ok' | 'off'
	 */
	public static function bricks_abilities_status(): string {
		if ( 'bricks' !== get_template() ) {
			return 'not_bricks';
		}

		$settings = get_option( self::BRICKS_SETTINGS_OPTION, [] );

		return is_array( $settings ) && ! empty( $settings['abilitiesApi'] ) ? 'ok' : 'off';
	}

	/**
	 * Turns the Bricks abilities on, on a Bricks site where they are off.
	 */
	public static function enable_bricks_abilities(): void {
		if ( 'off' !== self::bricks_abilities_status() ) {
			return;
		}

		$settings                 = get_option( self::BRICKS_SETTINGS_OPTION, [] );
		$settings                 = is_array( $settings ) ? $settings : [];
		$settings['abilitiesApi'] = true;
		update_option( self::BRICKS_SETTINGS_OPTION, $settings );
	}

	/**
	 * `delete_user`: the connections acting as, or approved by, a deleted user
	 * are revoked; the agent account's option is cleared when it is the one.
	 */
	public static function on_delete_user( int $user_id ): void {
		Connections::revoke_for_user( $user_id );

		if ( (int) get_option( self::USER_OPTION, 0 ) === $user_id ) {
			delete_option( self::USER_OPTION );
		}
	}

	/**
	 * Uninstall: deletes the account, the role and the option.
	 *
	 * The content Claude wrote must survive: wp_delete_user() without a
	 * reassignment target deletes or trashes every post of the user. Target,
	 * in order: the oldest administrator, the user running the uninstall,
	 * any user with manage_options (renamed or custom admin roles). With none
	 * of them, the account is kept, without any role.
	 */
	public static function remove(): void {
		$user_id = (int) get_option( self::USER_OPTION, 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( $user instanceof \WP_User ) {
			$reassign = self::reassign_target( $user_id );

			if ( $reassign > 0 ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id, $reassign );
			} else {
				$user->remove_role( self::ROLE );
			}
		}

		remove_role( self::ROLE );
		delete_option( self::USER_OPTION );
	}

	/**
	 * User who receives the agent's content on uninstall, 0 if nobody fits.
	 */
	private static function reassign_target( int $agent_id ): int {
		$query = [
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => 1,
			'fields'  => 'ID',
			'exclude' => [ $agent_id ],
		];

		$admins = get_users( [ 'role' => 'administrator' ] + $query );
		if ( isset( $admins[0] ) ) {
			return (int) $admins[0];
		}

		$current = get_current_user_id();
		if ( $current > 0 && $current !== $agent_id && get_userdata( $current ) instanceof \WP_User ) {
			return $current;
		}

		$managers = get_users( [ 'capability' => 'manage_options' ] + $query );

		return isset( $managers[0] ) ? (int) $managers[0] : 0;
	}

	/**
	 * Turns a list of capabilities into a granted map.
	 *
	 * @param string[] $caps Capability names.
	 * @return array<string, bool>
	 */
	private static function grant( array $caps ): array {
		return array_fill_keys( $caps, true );
	}
}
