<?php
namespace Lumia\Tools\Modules\McpConnect;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\McpConnect\Abilities\Registry;

/**
 * MCP Connect module: adds the site to Claude as a custom connector, signed in
 * through OAuth 2.1 (authorization code + PKCE) instead of a password.
 *
 * This class is the glue: settings, hooks, admin screen and its AJAX. The
 * Bearer filter itself is NOT hooked here but from lumia-tools.php through
 * Discovery::boot(), at file load: init() runs too late to win the first
 * wp_get_current_user() (see docs/modules/mcp-connect.md).
 */
final class Module extends AbstractModule {

	/** Option of the module settings (read without an instance by settings()). */
	const OPTION = 'lumia_module_mcp_connect';

	/** Daily purge cron hook: expired tokens, stale connections and clients. */
	const CRON_HOOK = 'lumia_mcp_connect_purge';

	/** Bounds of the token lifetimes and of the DCR purge. */
	const ACCESS_TTL_MIN  = 5;
	const ACCESS_TTL_MAX  = 1440;
	const REFRESH_TTL_MIN = 1;
	const REFRESH_TTL_MAX = 365;
	const DCR_PURGE_MIN   = 1;
	const DCR_PURGE_MAX   = 365;

	/** Rows shown in the connections list. */
	const LIST_LIMIT = 200;

	/** Capabilities an approver may be required to have (whitelist of the setting). */
	const APPROVER_CAPABILITIES = [ 'manage_options', 'edit_others_pages' ];

	public function init(): void {
		Store::maybe_install();

		// With the escape-hatch constant set, nothing of the protocol exists:
		// discovery documents and OAuth routes answer 404 like a module that
		// is off, and the Bearer filter was not added by Discovery::boot().
		if ( Discovery::enabled() ) {
			add_action( 'parse_request', [ Discovery::class, 'serve_well_known' ], 0 );
			add_action( 'rest_api_init', [ AuthorizationServer::class, 'register_routes' ] );
			add_filter( 'rest_authentication_errors', [ BearerAuth::class, 'reject' ], 90 );
			// Late, so a response short-circuited by another plugin (a REST
			// cache) is replaced too.
			add_filter( 'rest_pre_dispatch', [ BearerAuth::class, 'restrict_dispatch' ], 999, 3 );
			// Bracket the MCP route's callbacks: the internal dispatches an
			// ability makes meanwhile are part of the MCP call.
			add_filter( 'rest_request_before_callbacks', [ BearerAuth::class, 'enter_mcp' ], -999, 3 );
			add_filter( 'rest_request_after_callbacks', [ BearerAuth::class, 'leave_mcp' ], 999, 3 );
			add_filter( 'rest_post_dispatch', [ BearerAuth::class, 'leave_mcp' ], 999, 3 );
			add_filter( 'rest_post_dispatch', [ Discovery::class, 'challenge' ], 10, 3 );
		}

		// The agent account must never sign in by itself, whatever the state
		// of the protocol: it only exists to be acted as through a token.
		add_filter( 'wp_authenticate_user', [ Agent::class, 'block_login' ], 10, 2 );
		// The forbidden capabilities are also denied at check time: one written
		// on the role (or the account) since the last resync never takes effect.
		add_filter( 'user_has_cap', [ Agent::class, 'deny_forbidden_caps' ], 999, 4 );
		add_action( 'wp_authenticate_application_password_errors', [ Agent::class, 'block_app_passwords' ], 10, 2 );
		add_action( 'delete_user', [ $this, 'on_delete_user' ] );

		// After an update or a change of the preset constant: token requests
		// never load an admin page, where the role is otherwise resynced.
		Agent::maybe_sync();

		// The lumia/* abilities, whatever the state of the protocol: an
		// application password reaches them through the Adapter too.
		Registry::boot();

		add_action( self::CRON_HOOK, [ Store::class, 'purge_expired' ] );

		// Scheduled here and not only on activation: a module activated by a
		// configuration import does not call on_activate().
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ ConsentPage::class, 'register' ] );
		add_action( 'admin_post_lumia_mcp_consent', [ ConsentPage::class, 'handle' ] );
		add_action( 'admin_init', [ Agent::class, 'block_admin' ] );
		add_action( 'admin_init', [ $this, 'maybe_ensure_agent' ] );

		add_action( 'wp_ajax_lumia_mcp_connect_list', [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_lumia_mcp_connect_revoke', [ $this, 'ajax_revoke' ] );
		add_action( 'wp_ajax_lumia_mcp_connect_revoke_all', [ $this, 'ajax_revoke_all' ] );

		add_filter( 'site_status_tests', [ SiteHealth::class, 'register' ] );
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( self::get_defaults() );
	}

	/**
	 * Every key is rebuilt from the whitelist: the option is diffed into the
	 * activity log on each write, so nothing volatile or secret goes there.
	 * Also runs for an inactive module during a configuration import.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$defaults = self::get_defaults();
		$presets  = array_keys( Agent::presets() );
		// A preset locked by the constant is a disabled select, absent from the
		// form: the stored choice is kept for the day the constant goes.
		$preset   = sanitize_key( (string) ( $settings['agent_preset'] ?? self::settings()['agent_preset'] ) );
		$approver = sanitize_key( (string) ( $settings['approver_capability'] ?? $defaults['approver_capability'] ) );

		$sanitized = [
			'paused'              => ! empty( $settings['paused'] ),
			'access_token_ttl'    => min( self::ACCESS_TTL_MAX, max( self::ACCESS_TTL_MIN, absint( $settings['access_token_ttl'] ?? $defaults['access_token_ttl'] ) ) ),
			'refresh_token_ttl'   => min( self::REFRESH_TTL_MAX, max( self::REFRESH_TTL_MIN, absint( $settings['refresh_token_ttl'] ?? $defaults['refresh_token_ttl'] ) ) ),
			'trusted_only'        => ! empty( $settings['trusted_only'] ),
			'dcr_enabled'         => ! empty( $settings['dcr_enabled'] ),
			'dcr_purge_days'      => min( self::DCR_PURGE_MAX, max( self::DCR_PURGE_MIN, absint( $settings['dcr_purge_days'] ?? $defaults['dcr_purge_days'] ) ) ),
			'agent_preset'        => in_array( $preset, $presets, true ) ? $preset : (string) $defaults['agent_preset'],
			'approver_capability' => in_array( $approver, self::APPROVER_CAPABILITIES, true ) ? $approver : (string) $defaults['approver_capability'],
			'abilities'           => self::sanitize_families( $settings['abilities'] ?? null, self::settings()['abilities'] ?? [] ),
		];

		// The preset is now a choice made on this site (form or import): the
		// pre-2.4 Editor migration of Agent::maybe_sync() must not overturn it.
		Agent::mark_preset_chosen();

		$previous = Agent::current_preset();
		$saved    = $this->save_module_settings( $sanitized );

		// The role carries the capabilities: changing the preset only takes
		// effect once the role is rewritten. The active preset is read again
		// rather than the saved one: LUMIA_MCP_AGENT_PRESET wins over it.
		$current = Agent::current_preset();
		if ( $previous !== $current ) {
			Agent::sync_role( $current );
		}

		return $saved;
	}

	/**
	 * Configuration import: stored abilities are a complete list, but the
	 * import drops the false values before save_settings(); the `_sent`
	 * marker the form posts tells it a missing family means off.
	 *
	 * @param array<string, mixed> $stored Settings as exported.
	 * @return array<string, mixed>
	 */
	public function to_form_payload( array $stored ): array {
		if ( isset( $stored['abilities'] ) && is_array( $stored['abilities'] ) ) {
			$stored['abilities']['_sent'] = true;
		}

		return $stored;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'paused'              => false,
			'access_token_ttl'    => 60,
			'refresh_token_ttl'   => 30,
			'trusted_only'        => true,
			'dcr_enabled'         => false,
			'dcr_purge_days'      => 30,
			'agent_preset'        => Agent::DEFAULT_PRESET,
			'approver_capability' => 'manage_options',
			'abilities'           => array_fill_keys( array_keys( Registry::FAMILIES ), true ),
		];
	}

	/**
	 * Families of lumia/* abilities turned on: a checkbox per family, every
	 * known key rebuilt (an unchecked box is absent from the form). The form
	 * posts `abilities[_sent]`: without it (a configuration exported before
	 * 2.4.0, a payload without the key) the current families are kept rather
	 * than all turned off; a family the stored value does not know is on.
	 *
	 * @param mixed $families Posted families.
	 * @param mixed $current  Stored families.
	 * @return array<string, bool>
	 */
	private static function sanitize_families( $families, $current ): array {
		$current = is_array( $current ) ? $current : [];
		$sent    = is_array( $families ) && ! empty( $families['_sent'] );
		$out     = [];
		foreach ( array_keys( Registry::FAMILIES ) as $key ) {
			if ( $sent ) {
				$out[ $key ] = ! empty( $families[ $key ] );
			} elseif ( is_array( $families ) && array_key_exists( $key, $families ) ) {
				$out[ $key ] = ! empty( $families[ $key ] );
			} else {
				$out[ $key ] = ! array_key_exists( $key, $current ) || ! empty( $current[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * The role and the agent user are removed by uninstall_data(), not here:
	 * they are not options and need WordPress functions to go cleanly.
	 *
	 * @return array{options?: string[], meta?: string[], user_meta?: string[], post_type?: string[], taxonomy?: string[], tables?: string[], cron?: string[]}
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [ self::OPTION, Store::SCHEMA_OPTION, Agent::USER_OPTION, Agent::SYNC_OPTION ],
			'tables'  => [ Store::TABLE_CLIENTS, Store::TABLE_CONNECTIONS, Store::TABLE_TOKENS ],
			'cron'    => [ self::CRON_HOOK ],
		];
	}

	/**
	 * Called by uninstall.php before the declared keys are deleted (it reads
	 * Agent::USER_OPTION): deletes the agent user, reassigning its content,
	 * then the role.
	 */
	public static function uninstall_data(): void {
		Agent::remove();
	}

	/**
	 * Merged settings, readable from the static classes of the module without
	 * an instance (the Bearer check runs before init()).
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, [] );

		return self::merge_defaults( self::get_defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Settings of the Security module merged over its defaults, even when that
	 * module is inactive: its IP source and rate limiting are shared.
	 *
	 * @return array<string, mixed>
	 */
	public static function security_settings(): array {
		$stored = get_option( 'lumia_module_security', [] );

		return self::merge_defaults( \Lumia\Tools\Modules\Security\Module::get_defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Capability required to approve a connection: the whitelisted setting,
	 * then the public filter `lumia_mcp_connect_approver_capability`.
	 */
	public static function approver_capability(): string {
		$capability = (string) self::settings()['approver_capability'];

		if ( ! in_array( $capability, self::APPROVER_CAPABILITIES, true ) ) {
			$capability = 'manage_options';
		}

		$filtered = Compat::apply_filters( 'mcp_connect_approver_capability', $capability );

		// A filter returning nothing usable must not open the consent page to
		// everyone (an empty capability would be granted by nobody, but a
		// non-string would fatal in current_user_can()).
		return is_string( $filtered ) && '' !== $filtered ? $filtered : $capability;
	}

	/* ================================================================
	 * LIFECYCLE
	 * ================================================================ */

	/**
	 * Also turns the Bricks abilities on: without them Claude connects but has
	 * no tool to edit a page. Only here, so a later choice to turn them off in
	 * Bricks > AI stands (the Help tab and Site Health then warn).
	 */
	public function on_activate(): void {
		Store::install();
		Agent::mark_preset_chosen();
		Agent::ensure();
		Agent::enable_bricks_abilities();
	}

	/**
	 * Data, role and agent user stay: deactivating the module stops every
	 * connection (the routes disappear), reactivating it resumes them.
	 */
	public function on_deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * A deleted user can no longer approve nor be acted as: its connections
	 * are revoked. The agent user deleted by hand also forgets its id, so the
	 * next admin visit recreates it.
	 *
	 * @param int $user_id
	 */
	public function on_delete_user( $user_id ): void {
		Agent::on_delete_user( (int) $user_id );
	}

	/**
	 * `admin_init`, administrators only (AJAX left out): recreates the role or
	 * the agent user when missing (activation through a configuration import,
	 * user deleted by hand), otherwise resyncs the role to the preset. Cheap
	 * when nothing drifted: sync_role() compares two arrays and writes nothing.
	 */
	public function maybe_ensure_agent(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( null === get_role( Agent::ROLE ) || Agent::user_id() <= 0 ) {
			Agent::ensure();
			return;
		}

		Agent::sync_role( Agent::current_preset() );
		Agent::reset_account( Agent::user_id() );
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/mcp-connect.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/mcp-connect.js' ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [
			'i18n' => [
				'mcLoading'           => __( 'Loading…', 'lumia-tools' ),
				'mcError'             => __( 'Could not load the connections.', 'lumia-tools' ),
				'mcEmpty'             => __( 'No connection yet. Add this site in Claude: see Help.', 'lumia-tools' ),
				/* translators: %s: number of connections. */
				'mcTotal'             => __( '%s connection(s)', 'lumia-tools' ),
				'mcVerified'          => __( 'Verified', 'lumia-tools' ),
				'mcUnverified'        => __( 'Unverified', 'lumia-tools' ),
				'mcActsAsApprover'    => __( 'Acts as the approver', 'lumia-tools' ),
				'mcNever'             => __( 'Never', 'lumia-tools' ),
				'mcNoLabel'           => __( '(no label)', 'lumia-tools' ),
				'mcStatusActive'      => _x( 'Active', 'MCP connection status', 'lumia-tools' ),
				'mcStatusPending'     => __( 'Pending', 'lumia-tools' ),
				'mcStatusRevoked'     => __( 'Revoked', 'lumia-tools' ),
				'mcRevoke'            => __( 'Revoke', 'lumia-tools' ),
				'mcRevokeTitle'       => __( 'Revoke this connection?', 'lumia-tools' ),
				/* translators: %s: connection label or client name. */
				'mcRevokeMessage'     => __( '"%s" will lose access to this site immediately. Claude will ask to connect again.', 'lumia-tools' ),
				'mcRevokeAllTitle'    => __( 'Revoke every connection?', 'lumia-tools' ),
				'mcRevokeAllMessage'  => __( 'Every Claude connection to this site loses access immediately. Each one will have to be approved again.', 'lumia-tools' ),
				'mcRevokeAllConfirm'  => __( 'Revoke all', 'lumia-tools' ),
				'mcCopied'            => __( 'Copied to the clipboard.', 'lumia-tools' ),
				'mcCopyFailed'        => __( 'Could not copy: select the text and copy it by hand.', 'lumia-tools' ),
				'mcReasonManual'      => __( 'by an administrator', 'lumia-tools' ),
				'mcReasonAll'         => __( 'all connections revoked', 'lumia-tools' ),
				'mcReasonReuse'       => __( 'token reuse detected', 'lumia-tools' ),
				'mcReasonUserDeleted' => __( 'user deleted', 'lumia-tools' ),
				'mcReasonToken'       => __( 'revoked by the client', 'lumia-tools' ),
				'mcReasonExpired'     => __( 'expired', 'lumia-tools' ),
			],
		];
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	/**
	 * Shared nonce + module capability, at the top of every endpoint.
	 */
	private function guard(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( self::get_required_capability() ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ], 403 );
		}
	}

	public function ajax_list(): void {
		$this->guard();

		$trusted = ClientRegistry::trusted_publishers();
		$rows    = array_map(
			function ( array $row ) use ( $trusted ): array {
				return $this->present( $row, $trusted );
			},
			Connections::list_for_admin( self::LIST_LIMIT )
		);

		wp_send_json_success(
			[
				'rows'   => $rows,
				'paused' => ! empty( self::settings()['paused'] ),
			]
		);
	}

	public function ajax_revoke(): void {
		$this->guard();

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by guard().

		if ( ! $id || ! Connections::revoke( $id, 'manual' ) ) {
			wp_send_json_error( [ 'message' => __( 'This connection does not exist or is already revoked.', 'lumia-tools' ) ], 404 );
		}

		wp_send_json_success( [ 'message' => __( 'Connection revoked.', 'lumia-tools' ) ] );
	}

	public function ajax_revoke_all(): void {
		$this->guard();

		$count = Connections::revoke_all( 'all' );

		wp_send_json_success(
			[
				'message' => sprintf(
					/* translators: %s: number of connections revoked. */
					_n( '%s connection revoked.', '%s connections revoked.', $count, 'lumia-tools' ),
					number_format_i18n( $count )
				),
			]
		);
	}

	/**
	 * Row of the list as the JS displays it: dates in the site timezone,
	 * users as display names. The client_id, scope and token data never leave
	 * the server.
	 *
	 * @param array<string, mixed> $row     Row of Connections::list_for_admin().
	 * @param string[]             $trusted Trusted publisher hosts.
	 * @return array<string, mixed>
	 */
	private function present( array $row, array $trusted ): array {
		$publisher = strtolower( (string) ( $row['publisher'] ?? '' ) );
		$type      = (string) ( $row['client_type'] ?? '' );
		$acting_id = (int) ( $row['acting_user_id'] ?? 0 );

		return [
			'id'              => (int) ( $row['id'] ?? 0 ),
			'label'           => (string) ( $row['label'] ?? '' ),
			'client'          => (string) ( $row['client_name'] ?? '' ),
			// A DCR client has no publisher: never verified, whatever its name.
			'verified'        => 'dcr' !== $type && '' !== $publisher && in_array( $publisher, $trusted, true ),
			'acting'          => self::user_name( $acting_id ),
			'acting_approver' => ! empty( $row['act_as_approver'] ),
			'approved_by'     => self::user_name( (int) ( $row['approved_by'] ?? 0 ) ),
			'created'         => self::format_date( $row['created_at'] ?? null ),
			'last_used'       => self::format_date( $row['last_used_at'] ?? null ),
			'last_ip'         => (string) ( $row['last_ip'] ?? '' ),
			'refresh_expires' => self::format_date( $row['refresh_expires_at'] ?? null ),
			'status'          => (string) ( $row['status'] ?? '' ),
			'revoked_reason'  => (string) ( $row['revoked_reason'] ?? '' ),
		];
	}

	/**
	 * Display name of a user, `#id` once the account is gone (the row outlives
	 * it for 90 days).
	 */
	private static function user_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/**
	 * UTC `datetime` of the tables → date and time of the site.
	 *
	 * @param mixed $utc
	 */
	private static function format_date( $utc ): string {
		if ( ! is_string( $utc ) || '' === $utc || 0 === strpos( $utc, '0000' ) ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $timestamp );
	}
}
