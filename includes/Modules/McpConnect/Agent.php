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
 *   permissions come from a preset that never reaches the site settings,
 *   plugins, themes or users.
 * - The account only exists to carry OAuth connections: it cannot sign in with
 *   a password (it has none anyone knows), cannot use application passwords
 *   and never sees the dashboard.
 * - The role is rebuilt from the preset whenever it drifts, so a capability
 *   added by hand or by another plugin does not survive the next sync.
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

	/** Preset used when the setting is unknown. */
	const DEFAULT_PRESET = 'content_bricks';

	/**
	 * Capabilities the role never gets, whatever the preset or the filter says:
	 * each one hands over the site (settings, code, users).
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
		'edit_theme_options',
		'update_core',
		'unfiltered_upload',
		'unfiltered_html',
		'edit_files',
		'export',
		'import',
		'bricks_execute_code',
	];

	/** Maximum `-N` suffix tried when the preferred login or email is taken. */
	const MAX_SUFFIX = 50;

	/**
	 * Permission presets offered in the settings.
	 *
	 * @return array<string, array{label: string, caps: array<string, bool>}>
	 */
	public static function presets(): array {
		$content = self::grant(
			[
				'read',
				'edit_posts',
				'edit_others_posts',
				'edit_published_posts',
				'publish_posts',
				'delete_posts',
				'delete_published_posts',
				'edit_pages',
				'edit_others_pages',
				'edit_published_pages',
				'publish_pages',
				'delete_pages',
				'delete_published_pages',
				'edit_private_posts',
				'read_private_posts',
				'edit_private_pages',
				'read_private_pages',
				'upload_files',
				'manage_categories',
				'bricks_full_access',
			]
		);

		// Rank Math capabilities: harmless where Rank Math is not installed.
		$seo = $content + self::grant(
			[
				'rank_math_titles',
				'rank_math_general',
				'rank_math_sitemap',
				'rank_math_404_monitor',
				'rank_math_redirections',
				'rank_math_link_builder',
				'rank_math_onpage_general',
				'rank_math_onpage_advanced',
				'rank_math_onpage_snippet',
				'rank_math_onpage_social',
				'rank_math_content_ai',
				'rank_math_site_analysis',
				'rank_math_analytics',
			]
		);

		$shop = $seo + self::grant(
			[
				'manage_woocommerce',
				'view_woocommerce_reports',
				'edit_products',
				'edit_others_products',
				'edit_published_products',
				'publish_products',
				'read_private_products',
				'delete_products',
				'delete_published_products',
				'edit_shop_orders',
				'edit_others_shop_orders',
				'read_shop_order',
				'read_private_shop_orders',
				'publish_shop_orders',
				'edit_shop_coupons',
				'edit_others_shop_coupons',
				'publish_shop_coupons',
				'manage_product_terms',
				'edit_product_terms',
				'assign_product_terms',
			]
		);

		return [
			'content_bricks'     => [
				'label' => __( 'Content and Bricks', 'lumia-tools' ),
				'caps'  => $content,
			],
			'content_bricks_seo' => [
				'label' => __( 'Content, Bricks and SEO', 'lumia-tools' ),
				'caps'  => $seo,
			],
			'full_shop'          => [
				'label' => __( 'Full shop', 'lumia-tools' ),
				'caps'  => $shop,
			],
		];
	}

	/**
	 * Capabilities of a preset, after the `lumia_mcp_connect_agent_capabilities`
	 * filter. An unknown preset falls back to the default one; the forbidden
	 * capabilities are dropped after the filter.
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

		$caps = [];
		foreach ( $filtered as $cap => $grant ) {
			if ( ! is_string( $cap ) || '' === $cap || in_array( $cap, self::FORBIDDEN_CAPS, true ) ) {
				continue;
			}
			$caps[ $cap ] = (bool) $grant;
		}

		// Without `read` the MCP Adapter's transport check refuses every call.
		$caps['read'] = true;

		return $caps;
	}

	/**
	 * Creates the role, or resets its capabilities to the preset.
	 *
	 * Called on every admin page load of an administrator (through ensure()):
	 * when nothing changed it only compares two arrays. When something did, the
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
		$preset = Module::settings()['agent_preset'] ?? self::DEFAULT_PRESET;
		self::sync_role( is_string( $preset ) ? $preset : self::DEFAULT_PRESET );

		$user_id = self::user_id();
		if ( $user_id > 0 ) {
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
	 */
	public static function can_delegate( \WP_User $approver ): bool {
		if ( user_can( $approver, 'manage_options' ) ) {
			return true;
		}

		$preset = Module::settings()['agent_preset'] ?? self::DEFAULT_PRESET;

		foreach ( self::capabilities( is_string( $preset ) ? $preset : self::DEFAULT_PRESET ) as $cap => $grant ) {
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
