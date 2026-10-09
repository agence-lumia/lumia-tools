<?php
namespace Lumia\Tools\Modules\ActivityLog;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Modules\Security\ClientIp;

/**
 * Hooks the log into WordPress and writes the events.
 *
 * Each handler only turns the hook arguments into a row; exclusions, IP,
 * author and deduplication live in record().
 */
class Recorder {

	/**
	 * Failed logins logged per IP and per hour. Beyond that, attempts from the
	 * same IP are ignored until the end of the window: a brute-force attack of
	 * ten thousand tries would otherwise fill the row cap in one night, and the
	 * volume purge would erase all the useful history, the opposite of the goal.
	 */
	const FAILED_LOGIN_CAP = 10;

	/** Maximum length of a before/after value kept in the detail. */
	const VALUE_MAX = 200;

	/** Maximum number of paths listed for a Lümia Tools settings change. */
	const PATHS_MAX = 30;

	/** @var string[] */
	private array $excluded_groups;

	/** @var string[] */
	private array $excluded_roles;

	private bool $anonymize_ip;

	/**
	 * Events already written during this request (key => true).
	 *
	 * Gutenberg saves a post in two requests, but Bricks writes several metas
	 * in the same one, and a role update goes through several hooks: a single
	 * row per object and per request is enough.
	 *
	 * @var array<string, bool>
	 */
	private array $seen = [];

	/**
	 * Names captured before deletion (plugin, theme), read back once the
	 * deletion is confirmed: the file header no longer exists at that point.
	 *
	 * @var array<string, string>
	 */
	private array $pending_names = [];

	/**
	 * @param array<string, mixed> $settings Module settings.
	 */
	public function __construct( array $settings ) {
		$this->excluded_groups = array_map( 'strval', (array) ( $settings['excluded_groups'] ?? [] ) );
		$this->excluded_roles  = array_map( 'strval', (array) ( $settings['excluded_roles'] ?? [] ) );
		$this->anonymize_ip    = ! empty( $settings['anonymize_ip'] );
	}

	public function register(): void {
		// Logins.
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_login_failed', [ $this, 'on_login_failed' ], 10, 1 );
		add_action( 'wp_logout', [ $this, 'on_logout' ], 10, 1 );

		// Content.
		add_action( 'transition_post_status', [ $this, 'on_transition_post_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'trashed_post', [ $this, 'on_trashed_post' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'on_untrashed_post' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 1 );
		add_action( 'added_post_meta', [ $this, 'on_post_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ $this, 'on_post_meta' ], 10, 3 );

		// Media.
		add_action( 'add_attachment', [ $this, 'on_add_attachment' ], 10, 1 );
		add_action( 'attachment_updated', [ $this, 'on_attachment_updated' ], 10, 3 );
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ], 10, 1 );

		// Users.
		add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
		add_action( 'set_user_role', [ $this, 'on_set_user_role' ], 10, 3 );
		add_action( 'delete_user', [ $this, 'on_delete_user' ], 10, 2 );
		add_action( 'after_password_reset', [ $this, 'on_password_reset' ], 10, 1 );

		// Plugins and themes.
		add_action( 'activated_plugin', [ $this, 'on_activated_plugin' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_deactivated_plugin' ], 10, 2 );
		add_action( 'delete_plugin', [ $this, 'on_delete_plugin' ], 10, 1 );
		add_action( 'deleted_plugin', [ $this, 'on_deleted_plugin' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'on_switch_theme' ], 10, 3 );
		add_action( 'delete_theme', [ $this, 'on_delete_theme' ], 10, 1 );
		add_action( 'deleted_theme', [ $this, 'on_deleted_theme' ], 10, 2 );

		// Settings.
		add_action( 'updated_option', [ $this, 'on_updated_option' ], 10, 3 );
		add_action( 'added_option', [ $this, 'on_added_option' ], 10, 2 );
	}

	/* ================================================================
	 * LOGINS
	 * ================================================================ */

	/**
	 * `wp_login` passes the user as an argument: the current user is not set
	 * yet when the hook fires.
	 *
	 * @param string   $user_login
	 * @param \WP_User $user
	 */
	public function on_login( $user_login, $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->record( 'login', 'user', $user->ID, $user->user_login, [], $user );
	}

	/**
	 * @param string $username Login name typed in: a value freely chosen by the attacker.
	 */
	public function on_login_failed( $username ): void {
		$ip  = $this->client_ip();
		$key = '_lumia_al_fail_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= self::FAILED_LOGIN_CAP ) {
			return;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$details = [];
		if ( self::FAILED_LOGIN_CAP - 1 === $count ) {
			$details['capped'] = self::FAILED_LOGIN_CAP;
		}

		$this->record( 'login_failed', 'user', 0, sanitize_user( (string) $username ), $details, null, false );
	}

	/**
	 * Since WordPress 5.5, `wp_logout` receives the ID: the current user is
	 * already reset when it fires.
	 *
	 * @param int $user_id
	 */
	public function on_logout( $user_id = 0 ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$this->record( 'logout', 'user', $user->ID, $user->user_login, [], $user );
	}

	/* ================================================================
	 * CONTENT
	 * ================================================================ */

	/**
	 * Creation: the only transition we care about here. The others
	 * (publishing a draft, unpublishing) also arrive through `post_updated`,
	 * which sees the before and the after.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		// The auto-draft WordPress creates when the editor opens is not a
		// creation: the user has not saved anything yet.
		if ( ! in_array( $old_status, [ 'new', 'auto-draft' ], true ) || in_array( $new_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) {
			return;
		}

		$this->record( 'post_created', 'post', $post->ID, $this->post_label( $post ), [ 'status' => $new_status ] );
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public function on_post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || ! $this->is_tracked_post( $after ) ) {
			return;
		}

		// First save of an auto-draft: already logged as a creation. Trash and
		// restore have their own events (wp_trash_post also goes through
		// wp_update_post).
		if ( in_array( $before->post_status, [ 'new', 'auto-draft', 'trash' ], true ) || in_array( $after->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return;
		}

		$changes = $this->post_changes( $before, $after );

		// Gutenberg sends the post a second time for the meta boxes: nothing
		// changed in the fields, so there is nothing to report.
		if ( ! $changes ) {
			return;
		}

		$this->record( 'post_updated', 'post', $after->ID, $this->post_label( $after ), [ 'changes' => $changes ] );
	}

	/**
	 * @param int $post_id
	 */
	public function on_trashed_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && $this->is_tracked_post( $post ) ) {
			$this->record( 'post_trashed', 'post', $post->ID, $this->post_label( $post ) );
		}
	}

	/**
	 * @param int $post_id
	 */
	public function on_untrashed_post( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post && $this->is_tracked_post( $post ) ) {
			$this->record( 'post_restored', 'post', $post->ID, $this->post_label( $post ) );
		}
	}

	/**
	 * `before_delete_post` rather than `deleted_post`: after deletion, the
	 * title is no longer readable.
	 *
	 * @param int $post_id
	 */
	public function on_before_delete_post( $post_id ): void {
		$post = get_post( (int) $post_id );

		// Auto-drafts are purged by the WordPress cron every week.
		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || ! $this->is_tracked_post( $post ) ) {
			return;
		}

		$this->record( 'post_deleted', 'post', $post->ID, $this->post_label( $post ) );
	}

	/**
	 * Page builders: Bricks and Elementor store the content in postmeta and do
	 * not call wp_update_post(); without this hook, a page completely redone in
	 * Bricks showed up nowhere. Same for the alt text of a media item.
	 *
	 * @param int    $meta_id
	 * @param int    $object_id
	 * @param string $meta_key
	 */
	public function on_post_meta( $meta_id, $object_id, $meta_key ): void {
		$meta_key = (string) $meta_key;

		if ( '_wp_attachment_image_alt' === $meta_key ) {
			// The alt text generated on upload (Image Optimizer) is part of the
			// addition, not of an update.
			if ( isset( $this->seen[ 'media_added|attachment|' . (int) $object_id . '|' ] ) ) {
				return;
			}

			$post = get_post( (int) $object_id );
			if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
				$this->record( 'media_updated', 'attachment', $post->ID, $this->post_label( $post ), [ 'changes' => [ 'alt' => true ] ] );
			}
			return;
		}

		/**
		 * Postmeta keys that hold the content of a page.
		 *
		 * @param string[] $keys
		 */
		$keys = (array) apply_filters(
			'lumia_activity_log_content_meta_keys',
			[
				'_bricks_page_content_2',
				'_bricks_page_header_2',
				'_bricks_page_footer_2',
				'_elementor_data',
			]
		);

		if ( ! in_array( $meta_key, $keys, true ) ) {
			return;
		}

		$post = get_post( (int) $object_id );
		if ( ! $post instanceof \WP_Post || ! $this->is_tracked_post( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->record( 'post_updated', 'post', $post->ID, $this->post_label( $post ), [ 'changes' => [ 'builder' => $meta_key ] ] );
	}

	/* ================================================================
	 * MEDIA
	 * ================================================================ */

	/**
	 * @param int $post_id
	 */
	public function on_add_attachment( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->record( 'media_added', 'attachment', $post->ID, $this->post_label( $post ), [ 'mime' => $post->post_mime_type ] );
		}
	}

	/**
	 * @param int      $post_id
	 * @param \WP_Post $after
	 * @param \WP_Post $before
	 */
	public function on_attachment_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}

		$changes = $this->post_changes( $before, $after );
		if ( $changes ) {
			$this->record( 'media_updated', 'attachment', $after->ID, $this->post_label( $after ), [ 'changes' => $changes ] );
		}
	}

	/**
	 * @param int $post_id
	 */
	public function on_delete_attachment( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			$this->record( 'media_deleted', 'attachment', $post->ID, $this->post_label( $post ) );
		}
	}

	/* ================================================================
	 * USERS
	 * ================================================================ */

	/**
	 * @param int $user_id
	 */
	public function on_user_register( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User ) {
			$this->record( 'user_created', 'user', $user->ID, $user->user_login, [ 'roles' => array_values( $user->roles ) ] );
		}
	}

	/**
	 * @param int      $user_id
	 * @param \WP_User $old
	 */
	public function on_profile_update( $user_id, $old ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User || ! $old instanceof \WP_User ) {
			return;
		}

		$changes = [];
		foreach ( [
			'user_email'   => 'email',
			'display_name' => 'display_name',
			'user_url'     => 'url',
		] as $field => $name ) {
			if ( (string) $old->$field !== (string) $user->$field ) {
				$changes[ $name ] = [
					'from' => $this->short( $old->$field ),
					'to'   => $this->short( $user->$field ),
				];
			}
		}

		// Only the hash changes: we note the fact, never the value.
		if ( $old->user_pass !== $user->user_pass ) {
			$changes['password'] = true;
		}

		if ( $changes ) {
			$this->record( 'user_updated', 'user', $user->ID, $user->user_login, [ 'changes' => $changes ] );
		}
	}

	/**
	 * wp_insert_user() sets the role BEFORE `user_register`: on creation, this
	 * hook fires with an empty list of old roles, and the creation is already
	 * logged.
	 *
	 * @param int      $user_id
	 * @param string   $role
	 * @param string[] $old_roles
	 */
	public function on_set_user_role( $user_id, $role, $old_roles ): void {
		$old_roles = array_values( (array) $old_roles );
		if ( ! $old_roles || [ (string) $role ] === $old_roles ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		if ( $user instanceof \WP_User ) {
			$this->record(
				'user_role',
				'user',
				$user->ID,
				$user->user_login,
				[
					'from' => $old_roles,
					'to'   => [ (string) $role ],
				]
			);
		}
	}

	/**
	 * @param int      $user_id
	 * @param int|null $reassign
	 */
	public function on_delete_user( $user_id, $reassign ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$details = [ 'roles' => array_values( $user->roles ) ];
		if ( $reassign ) {
			$target              = get_userdata( (int) $reassign );
			$details['reassign'] = $target instanceof \WP_User ? $target->user_login : (int) $reassign;
		}

		$this->record( 'user_deleted', 'user', $user->ID, $user->user_login, $details );
	}

	/**
	 * The reset happens while logged out: the author is the user concerned,
	 * not the current user (which is 0).
	 *
	 * @param \WP_User $user
	 */
	public function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->record( 'password_reset', 'user', $user->ID, $user->user_login, [], $user );
		}
	}

	/* ================================================================
	 * PLUGINS AND THEMES
	 * ================================================================ */

	/**
	 * @param string $plugin
	 * @param bool   $network_wide
	 */
	public function on_activated_plugin( $plugin, $network_wide = false ): void {
		$this->record( 'plugin_activated', 'plugin', 0, $this->plugin_name( (string) $plugin ), $this->plugin_details( (string) $plugin, (bool) $network_wide ) );
	}

	/**
	 * @param string $plugin
	 * @param bool   $network_wide
	 */
	public function on_deactivated_plugin( $plugin, $network_wide = false ): void {
		$this->record( 'plugin_disabled', 'plugin', 0, $this->plugin_name( (string) $plugin ), $this->plugin_details( (string) $plugin, (bool) $network_wide ) );
	}

	/**
	 * @param string $plugin
	 */
	public function on_delete_plugin( $plugin ): void {
		$this->pending_names[ 'plugin:' . $plugin ] = $this->plugin_name( (string) $plugin );
	}

	/**
	 * @param string $plugin
	 * @param bool   $deleted
	 */
	public function on_deleted_plugin( $plugin, $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		$name = $this->pending_names[ 'plugin:' . $plugin ] ?? (string) $plugin;
		$this->record( 'plugin_deleted', 'plugin', 0, $name, [ 'file' => (string) $plugin ] );
	}

	/**
	 * Installs and updates, plugins and themes alike. Automatic updates go
	 * through here too, run by the cron: they appear without an author, which
	 * is exactly what they are.
	 *
	 * @param \WP_Upgrader         $upgrader
	 * @param array<string, mixed> $extra
	 */
	public function on_upgrader_complete( $upgrader, $extra ): void {
		$type   = (string) ( $extra['type'] ?? '' );
		$action = (string) ( $extra['action'] ?? '' );

		if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! in_array( $action, [ 'install', 'update' ], true ) ) {
			return;
		}

		if ( 'install' === $action ) {
			$this->record_install( $type, $upgrader );
			return;
		}

		$plural = $type . 's';
		$items  = isset( $extra[ $plural ] ) ? (array) $extra[ $plural ] : ( isset( $extra[ $type ] ) ? [ $extra[ $type ] ] : [] );

		foreach ( $items as $item ) {
			$item = (string) $item;

			if ( 'plugin' === $type ) {
				$data = $this->plugin_data( $item );
				$this->record(
					'plugin_updated',
					'plugin',
					0,
					'' !== $data['Name'] ? $data['Name'] : $item,
					[
						'file'    => $item,
						'version' => $data['Version'],
					]
				);
			} else {
				$theme = wp_get_theme( $item );
				$this->record(
					'theme_updated',
					'theme',
					0,
					$theme->exists() ? (string) $theme->get( 'Name' ) : $item,
					[
						'stylesheet' => $item,
						'version'    => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
					]
				);
			}
		}
	}

	/**
	 * @param string    $new_name
	 * @param \WP_Theme $new_theme
	 * @param \WP_Theme $old_theme
	 */
	public function on_switch_theme( $new_name, $new_theme, $old_theme ): void {
		$details = [];
		if ( $old_theme instanceof \WP_Theme ) {
			$details['from'] = (string) $old_theme->get( 'Name' );
		}

		$this->record( 'theme_switched', 'theme', 0, (string) $new_name, $details );
	}

	/**
	 * @param string $stylesheet
	 */
	public function on_delete_theme( $stylesheet ): void {
		$theme = wp_get_theme( (string) $stylesheet );

		$this->pending_names[ 'theme:' . $stylesheet ] = $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $stylesheet;
	}

	/**
	 * @param string $stylesheet
	 * @param bool   $deleted
	 */
	public function on_deleted_theme( $stylesheet, $deleted ): void {
		if ( ! $deleted ) {
			return;
		}

		$name = $this->pending_names[ 'theme:' . $stylesheet ] ?? (string) $stylesheet;
		$this->record( 'theme_deleted', 'theme', 0, $name, [ 'stylesheet' => (string) $stylesheet ] );
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @param string $option
	 * @param mixed  $old
	 * @param mixed  $value
	 */
	public function on_updated_option( $option, $old, $value ): void {
		$option = (string) $option;

		if ( $this->is_lumia_option( $option ) ) {
			$this->record_lumia_option( $option, $old, $value );
			return;
		}

		$labels = self::tracked_options();
		if ( ! isset( $labels[ $option ] ) ) {
			return;
		}

		$this->record(
			'option_updated',
			'option',
			0,
			$option,
			[
				'label' => $labels[ $option ],
				'from'  => $this->short( $old ),
				'to'    => $this->short( $value ),
			]
		);
	}

	/**
	 * First save of a module screen: the option did not exist, so WordPress
	 * fires `added_option` and not `updated_option`.
	 *
	 * @param string $option
	 * @param mixed  $value
	 */
	public function on_added_option( $option, $value ): void {
		$option = (string) $option;

		if ( $this->is_lumia_option( $option ) ) {
			$this->record_lumia_option( $option, [], $value );
		}
	}

	/**
	 * Tracked WordPress options and their label.
	 *
	 * @return array<string, string>
	 */
	public static function tracked_options(): array {
		/**
		 * WordPress options whose changes are logged.
		 *
		 * @param array<string, string> $options Option name => label.
		 */
		return (array) apply_filters(
			'lumia_activity_log_tracked_options',
			[
				'blogname'               => __( 'Site title', 'lumia-tools' ),
				'blogdescription'        => __( 'Tagline', 'lumia-tools' ),
				'siteurl'                => __( 'WordPress address (URL)', 'lumia-tools' ),
				'home'                   => __( 'Site address (URL)', 'lumia-tools' ),
				'admin_email'            => __( 'Administration email', 'lumia-tools' ),
				'users_can_register'     => __( 'Open registration', 'lumia-tools' ),
				'default_role'           => __( 'Default role', 'lumia-tools' ),
				'blog_public'            => __( 'Search engine visibility', 'lumia-tools' ),
				'permalink_structure'    => __( 'Permalink structure', 'lumia-tools' ),
				'WPLANG'                 => __( 'Site language', 'lumia-tools' ),
				'timezone_string'        => __( 'Timezone', 'lumia-tools' ),
				'show_on_front'          => __( 'Homepage displays', 'lumia-tools' ),
				'page_on_front'          => __( 'Homepage', 'lumia-tools' ),
				'page_for_posts'         => __( 'Posts page', 'lumia-tools' ),
				'default_comment_status' => __( 'Comments allowed', 'lumia-tools' ),
				'comment_registration'   => __( 'Comments restricted to registered users', 'lumia-tools' ),
			]
		);
	}

	/* ================================================================
	 * WRITING
	 * ================================================================ */

	/**
	 * Writes an event, unless excluded or duplicated.
	 *
	 * @param array<string, mixed> $details
	 * @param \WP_User|null        $actor           Explicit author; the current user otherwise.
	 * @param bool                 $apply_role_rule False for anonymous events (failed login):
	 *                                              excluding administrators must not hide the
	 *                                              attacks aimed at them.
	 */
	private function record( string $event, string $object_type, int $object_id, string $label, array $details = [], ?\WP_User $actor = null, bool $apply_role_rule = true ): void {
		$group = Events::group_of( $event );

		if ( '' === $group || in_array( $group, $this->excluded_groups, true ) ) {
			return;
		}

		$dedupe_key = $event . '|' . $object_type . '|' . $object_id . '|' . ( $object_id ? '' : $label );
		// An option only fires its hook when its value changes: two rows in the
		// same request are two real changes.
		if ( ! in_array( $event, [ 'login_failed', 'option_updated' ], true ) && isset( $this->seen[ $dedupe_key ] ) ) {
			return;
		}

		if ( null === $actor ) {
			$current = wp_get_current_user();
			$actor   = $current->exists() ? $current : null;
		}

		$roles = $actor ? array_values( (array) $actor->roles ) : [];

		if ( $apply_role_rule && $roles && array_intersect( $roles, $this->excluded_roles ) ) {
			return;
		}

		$details['via'] = self::request_channel();

		/**
		 * Last word before an event is written to the log.
		 *
		 * @param array<string, mixed>|false $row Row to write; false to skip it.
		 */
		$row = apply_filters(
			'lumia_activity_log_record',
			[
				'user_id'      => $actor ? $actor->ID : 0,
				'user_login'   => $actor ? $actor->user_login : '',
				'user_role'    => $roles[0] ?? '',
				'ip'           => $this->client_ip(),
				'event_group'  => $group,
				'event'        => $event,
				'object_type'  => $object_type,
				'object_id'    => $object_id,
				'object_label' => $label,
				'details'      => $details,
			]
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$this->seen[ $dedupe_key ] = true;
		Store::insert( $row );
	}

	/**
	 * Lümia Tools settings: we list the changed paths, without the values, as a
	 * module may store a secret (SMTP password to come). Only the module
	 * on/off switches, which are booleans, are shown.
	 *
	 * @param mixed $old
	 * @param mixed $value
	 */
	private function record_lumia_option( string $option, $old, $value ): void {
		// A write without a user is technical (updater, cron), not a settings
		// choice.
		if ( ! is_user_logged_in() ) {
			return;
		}

		$paths = [];
		$this->diff_paths( is_array( $old ) ? $old : [], is_array( $value ) ? $value : [], '', $paths );

		if ( ! $paths ) {
			return;
		}

		$details = [ 'paths' => array_slice( $paths, 0, self::PATHS_MAX ) ];

		if ( 'lumia_settings' === $option ) {
			$before  = is_array( $old ) && isset( $old['modules'] ) ? (array) $old['modules'] : [];
			$after   = is_array( $value ) && isset( $value['modules'] ) ? (array) $value['modules'] : [];
			$toggled = [];
			foreach ( $after as $module => $active ) {
				if ( (bool) ( $before[ $module ] ?? false ) !== (bool) $active ) {
					$toggled[ (string) $module ] = (bool) $active;
				}
			}
			if ( $toggled ) {
				$details['modules'] = $toggled;
			}
			$label = 'global';
		} else {
			$label = substr( $option, strlen( 'lumia_module_' ) );
		}

		$this->record( 'lumia_settings', 'lumia', 0, $label, $details );
	}

	/**
	 * Dotted paths of the leaves that differ between two arrays.
	 *
	 * @param array<mixed, mixed> $a
	 * @param array<mixed, mixed> $b
	 * @param string[]            $paths
	 */
	private function diff_paths( array $a, array $b, string $prefix, array &$paths ): void {
		foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$va   = $a[ $key ] ?? null;
			$vb   = $b[ $key ] ?? null;

			// Lists (roles, IPs) are compared as a whole: listing every index
			// shifted by an insertion would say nothing useful.
			if ( is_array( $va ) && is_array( $vb ) && ! wp_is_numeric_array( $va ) && ! wp_is_numeric_array( $vb ) ) {
				$this->diff_paths( $va, $vb, $path, $paths );
			} elseif ( $va !== $vb ) {
				$paths[] = $path;
			}
		}
	}

	/**
	 * Installation of a plugin or a theme.
	 *
	 * @param \WP_Upgrader $upgrader
	 */
	private function record_install( string $type, $upgrader ): void {
		if ( 'plugin' === $type ) {
			$file = is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ? (string) $upgrader->plugin_info() : '';
			$data = '' !== $file ? $this->plugin_data( $file ) : [
				'Name'    => '',
				'Version' => '',
			];
			$this->record(
				'plugin_installed',
				'plugin',
				0,
				'' !== $data['Name'] ? $data['Name'] : $file,
				[
					'file'    => $file,
					'version' => $data['Version'],
				]
			);
			return;
		}

		$theme = is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ? $upgrader->theme_info() : false;
		if ( $theme instanceof \WP_Theme ) {
			$this->record(
				'theme_installed',
				'theme',
				0,
				(string) $theme->get( 'Name' ),
				[
					'stylesheet' => $theme->get_stylesheet(),
					'version'    => (string) $theme->get( 'Version' ),
				]
			);
		}
	}

	/* ================================================================
	 * TOOLS
	 * ================================================================ */

	/**
	 * Tracked post types: those with an editing interface, except media (a
	 * group of their own). Revisions, menu items and changesets have none,
	 * which rules them out without a blocklist to maintain.
	 */
	private function is_tracked_post( \WP_Post $post ): bool {
		if ( 'attachment' === $post->post_type || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		static $types = null;
		if ( null === $types ) {
			/**
			 * Post types that are logged.
			 *
			 * @param string[] $types
			 */
			$types = (array) apply_filters( 'lumia_activity_log_post_types', array_values( get_post_types( [ 'show_ui' => true ] ) ) );
		}

		return in_array( $post->post_type, $types, true );
	}

	/**
	 * Fields changed between two versions of a post. Long texts are only noted
	 * as "changed": copying them would double the database.
	 *
	 * @return array<string, mixed>
	 */
	private function post_changes( \WP_Post $before, \WP_Post $after ): array {
		$changes = [];

		foreach ( [
			'post_title'     => 'title',
			'post_status'    => 'status',
			'post_name'      => 'slug',
			'post_parent'    => 'parent',
			'post_date'      => 'date',
			'menu_order'     => 'order',
			'comment_status' => 'comments',
		] as $field => $name ) {
			if ( (string) $before->$field !== (string) $after->$field ) {
				$changes[ $name ] = [
					'from' => $this->short( $before->$field ),
					'to'   => $this->short( $after->$field ),
				];
			}
		}

		if ( (int) $before->post_author !== (int) $after->post_author ) {
			$from              = get_userdata( (int) $before->post_author );
			$to                = get_userdata( (int) $after->post_author );
			$changes['author'] = [
				'from' => $from instanceof \WP_User ? $from->user_login : (string) $before->post_author,
				'to'   => $to instanceof \WP_User ? $to->user_login : (string) $after->post_author,
			];
		}

		foreach ( [
			'post_content'  => 'content',
			'post_excerpt'  => 'excerpt',
			'post_password' => 'password',
		] as $field => $name ) {
			if ( (string) $before->$field !== (string) $after->$field ) {
				$changes[ $name ] = true;
			}
		}

		return $changes;
	}

	private function post_label( \WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

		if ( '' === $title && 'attachment' === $post->post_type ) {
			$title = wp_basename( (string) get_attached_file( $post->ID ) );
		}

		return '' !== $title ? $title : '#' . $post->ID;
	}

	/**
	 * @return array{Name: string, Version: string}
	 */
	private function plugin_data( string $file ): array {
		$path = WP_PLUGIN_DIR . '/' . $file;

		if ( '' === $file || ! is_file( $path ) ) {
			return [
				'Name'    => '',
				'Version' => '',
			];
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $path, false, false );

		return [
			'Name'    => (string) ( $data['Name'] ?? '' ),
			'Version' => (string) ( $data['Version'] ?? '' ),
		];
	}

	private function plugin_name( string $file ): string {
		$name = $this->plugin_data( $file )['Name'];
		return '' !== $name ? $name : $file;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function plugin_details( string $file, bool $network_wide ): array {
		$details = [
			'file'    => $file,
			'version' => $this->plugin_data( $file )['Version'],
		];

		if ( $network_wide ) {
			$details['network'] = true;
		}

		return $details;
	}

	private function is_lumia_option( string $option ): bool {
		return 'lumia_settings' === $option || 0 === strpos( $option, 'lumia_module_' );
	}

	/**
	 * Client address according to the source declared in the Security module,
	 * read even when the module is inactive: that is where the site's proxy
	 * configuration lives, and trusting the headers without it would let anyone
	 * write the IP of their choice into the log.
	 */
	private function client_ip(): string {
		// WP-CLI sets REMOTE_ADDR to 127.0.0.1 itself: a dummy address, not
		// anyone's.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$security = get_option( 'lumia_module_security', [] );
		$source   = is_array( $security ) ? ( $security['authentication']['ip_source'] ?? '' ) : '';
		$ip       = ClientIp::resolve( ClientIp::sanitize_source( $source ) );

		if ( ClientIp::UNKNOWN === $ip ) {
			return '';
		}

		return $this->anonymize_ip ? wp_privacy_anonymize_ip( $ip ) : $ip;
	}

	/**
	 * Request channel: tells a click in the admin from a WP-CLI command, a
	 * scheduled task or an API call.
	 */
	private static function request_channel(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return 'xmlrpc';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		return 'web';
	}

	/**
	 * Short scalar value for the detail; arrays are JSON-encoded.
	 *
	 * @param mixed $value
	 */
	private function short( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		} elseif ( ! is_scalar( $value ) && null !== $value ) {
			$value = (string) wp_json_encode( $value );
		}

		$value = (string) $value;

		return mb_strlen( $value ) > self::VALUE_MAX ? mb_substr( $value, 0, self::VALUE_MAX ) . '…' : $value;
	}
}
