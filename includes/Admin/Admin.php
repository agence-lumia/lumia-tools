<?php
namespace Lumia\Tools\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Modules;
use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Core\Settings;

/**
 * Handles the plugin's admin interface.
 */
class Admin {

	/**
	 * Maximum size accepted for an imported configuration file (2 MiB).
	 *
	 * The file is read whole then decoded as JSON: two copies in memory.
	 * A full export weighs a few tens of kilobytes.
	 */
	const IMPORT_MAX_BYTES = 2097152;

	/**
	 * Admin page slug.
	 */
	private string $slug = 'lumia-tools';

	/**
	 * Modules manager.
	 */
	private Modules $modules;

	/**
	 * Settings manager.
	 */
	private Settings $settings;

	/**
	 * HTML of the WP notices captured through output buffering.
	 */
	private string $captured_wp_notices = '';

	/**
	 * Data of the Lümia toast to display (message + type).
	 *
	 * @var array<string, string>|null
	 */
	private ?array $lumia_toast = null;

	/**
	 * Constructor.
	 */
	public function __construct( Modules $modules, Settings $settings ) {
		$this->modules  = $modules;
		$this->settings = $settings;

		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_filter( 'parent_file', [ $this, 'filter_parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'filter_submenu_file' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_lumia_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_lumia_toggle_module', [ $this, 'handle_toggle_module' ] );
		add_action( 'wp_ajax_lumia_ajax_toggle_module', [ $this, 'handle_ajax_toggle_module' ] );
		add_action( 'admin_post_lumia_update_modules', [ $this, 'handle_update_modules' ] );
		add_action( 'admin_post_lumia_check_updates', [ $this, 'handle_check_updates' ] );
		add_action( 'admin_post_lumia_reset_settings', [ $this, 'handle_reset_settings' ] );
		add_action( 'admin_post_lumia_export_settings', [ $this, 'handle_export_settings' ] );
		add_action( 'admin_post_lumia_import_settings', [ $this, 'handle_import_settings' ] );
		add_action( 'admin_head', [ $this, 'output_menu_separator_css' ] );
		add_action( 'admin_footer', [ $this, 'render_modal' ] );
		// Maximum priority: the plugin pages have their own footer, which must
		// stay empty even if a module (White label) customizes it.
		add_filter( 'admin_footer_text', [ $this, 'filter_admin_footer_text' ], PHP_INT_MAX );
		add_filter( 'update_footer', [ $this, 'filter_update_footer' ], PHP_INT_MAX );
		add_action( 'admin_notices', [ $this, 'capture_wp_notices_start' ], 0 );
		add_action( 'admin_notices', [ $this, 'capture_wp_notices_end' ], PHP_INT_MAX );
		add_action( 'admin_bar_menu', [ $this, 'register_noindex_indicator' ], 998 );
		add_action( 'admin_bar_menu', [ $this, 'register_notification_center' ], 999 );
		add_action( 'admin_footer', [ $this, 'render_notification_drawer' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_global_notification_assets' ] );
		add_action( 'wp_ajax_lumia_dismiss_notice', [ $this, 'handle_dismiss_notice' ] );
	}

	/* ================================================================
	 * MENU
	 * ================================================================ */

	/**
	 * Adds the main admin page.
	 */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'Lümia Tools', 'lumia-tools' ),
			__( 'Lümia', 'lumia-tools' ),
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ],
			plugins_url( 'assets/admin/images/menu-icon.svg', LUMIA_PLUGIN_FILE ),
			99
		);

		$this->add_submenus();
	}

	/**
	 * Adds the dynamic submenus.
	 */
	private function add_submenus(): void {
		remove_submenu_page( $this->slug, $this->slug );

		add_submenu_page( $this->slug, __( 'Overview', 'lumia-tools' ), __( 'Overview', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=dashboard', [ $this, 'render_page' ] );
		add_submenu_page( $this->slug, __( 'Modules', 'lumia-tools' ), __( 'Modules', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=modules', [ $this, 'render_page' ] );
		add_submenu_page( $this->slug, __( 'Settings', 'lumia-tools' ), __( 'Settings', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=settings', [ $this, 'render_page' ] );

		global $submenu;
		if ( isset( $submenu[ $this->slug ] ) ) {
			$submenu[ $this->slug ][] = [ '', 'manage_options', 'lumia-separator', '', 'lumia-menu-separator' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- visual separator: WordPress offers no API to insert one into a submenu.
		}

		foreach ( $this->modules->get_all() as $module_id => $module ) {
			if ( ! $this->modules->is_active( $module_id ) ) {
				continue;
			}

			$label = ! empty( $module['menu_label'] ) ? $module['menu_label'] : $module['name'];

			// A module can require more than `manage_options` (see
			// AbstractModule::get_required_capability()). WordPress then hides the
			// entry by itself; render_page() redoes the test, as the URL remains
			// guessable.
			add_submenu_page(
				$this->slug,
				esc_html( $label ),
				esc_html( $label ),
				$this->module_capability( $module_id ),
				$this->slug . '&tab=module_' . $module_id,
				[ $this, 'render_page' ]
			);
		}

		$this->deduplicate_submenus();
	}

	/**
	 * Removes duplicate submenus of the plugin.
	 */
	private function deduplicate_submenus(): void {
		global $submenu;

		if ( empty( $submenu[ $this->slug ] ) || ! is_array( $submenu[ $this->slug ] ) ) {
			return;
		}

		$top_label = __( 'Lümia', 'lumia-tools' );
		$seen      = [];
		$filtered  = [];

		foreach ( $submenu[ $this->slug ] as $item ) {
			$label = isset( $item[0] ) ? (string) $item[0] : '';
			$slug  = isset( $item[2] ) ? (string) $item[2] : '';

			if ( $slug === $this->slug || $label === $top_label ) {
				continue;
			}

			$key = $label . '|' . $slug;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$filtered[]   = $item;
		}

		$submenu[ $this->slug ] = $filtered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- submenu deduplication: no WordPress API to rewrite an existing entry.
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	/**
	 * Loads the CSS/JS assets on the plugin pages.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, $this->slug ) === false ) {
			return;
		}

		// tokens.css first: it only holds custom properties, and all the other
		// stylesheets depend on it.
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-reset-css', LUMIA_ASSETS_URL . 'admin/css/reset.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-layout-css', LUMIA_ASSETS_URL . 'admin/css/layout.css', [ 'lumia-reset-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-sidebar-css', LUMIA_ASSETS_URL . 'admin/css/sidebar.css', [ 'lumia-layout-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );

		wp_enqueue_script( 'lumia-admin-js', LUMIA_ASSETS_URL . 'admin/js/admin.js', [], LUMIA_VERSION, true );

		// Shared third-party libraries: registered once under a stable handle,
		// loaded only if a module declares them as a dependency. The Media module
		// uses the same handle: WordPress therefore deduplicates when both are
		// present on the same screen.
		wp_register_script( 'lumia-sortable-js', LUMIA_ASSETS_URL . 'admin/js/vendor/sortable.min.js', [], LUMIA_VERSION, true );

		$this->localize_admin_script( 'lumia-admin-js' );

		$this->enqueue_module_assets();
	}

	/**
	 * Localizes the global data for an admin script (without module-specific i18n).
	 */
	private function localize_admin_script( string $handle ): void {
		wp_localize_script(
			$handle,
			'lumiaAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lumia_admin_nonce' ),
				'i18n'    => [
					// Generic strings modules may reuse (see docs/core.md).
					'saveSuccess'   => __( 'Settings saved successfully.', 'lumia-tools' ),
					'saveError'     => __( 'An error occurred.', 'lumia-tools' ),
					'confirmAction' => __( 'Are you sure?', 'lumia-tools' ),
					'confirm'       => __( 'Confirm', 'lumia-tools' ),
					'cancel'        => __( 'Cancel', 'lumia-tools' ),
					'error'         => __( 'Error', 'lumia-tools' ),
					// Used by the core screens only (admin.js).
					'configure'     => __( 'Configure', 'lumia-tools' ),
					'unsavedTitle'  => __( 'Unsaved changes', 'lumia-tools' ),
					'unsavedText'   => __( 'You have unsaved changes. Leave without saving?', 'lumia-tools' ),
					'unsavedLeave'  => __( 'Leave without saving', 'lumia-tools' ),
					'unsavedStay'   => __( 'Stay on this page', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Loads the notification center assets on the whole WP admin.
	 */
	public function enqueue_global_notification_assets(): void {
		wp_enqueue_style( 'lumia-notifications-css', LUMIA_ASSETS_URL . 'admin/css/notifications.css', [], LUMIA_VERSION );
		wp_enqueue_script( 'lumia-notifications-js', LUMIA_ASSETS_URL . 'admin/js/notifications.js', [], LUMIA_VERSION, true );
	}

	/**
	 * Loads the assets of the active modules on their settings page.
	 */
	private function enqueue_module_assets(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.

		if ( strpos( $tab, 'module_' ) !== 0 ) {
			return;
		}

		$module_id = substr( $tab, 7 );
		$instance  = $this->modules->get_active_instances()[ $module_id ] ?? null;

		if ( ! $instance ) {
			return;
		}

		// Module CSS
		foreach ( $instance->get_admin_css() as $index => $style_url ) {
			if ( empty( $style_url ) ) {
				continue;
			}
			wp_enqueue_style(
				'lumia-module-' . $module_id . '-css-' . $index,
				$style_url,
				[ 'lumia-components-css', 'lumia-buttons-css', 'lumia-layout-css', 'lumia-sidebar-css' ],
				LUMIA_VERSION
			);
		}

		// Module JS (with lumia-admin-js as a dependency so that lumiaAdmin is defined)
		foreach ( $instance->get_admin_js() as $index => $script_url ) {
			if ( empty( $script_url ) ) {
				continue;
			}

			$handle = 'lumia-module-' . $module_id . '-js-' . $index;
			$deps   = array_values( array_filter( array_merge( [ 'lumia-admin-js' ], $instance->get_admin_js_deps() ) ) );
			wp_enqueue_script( $handle, $script_url, $deps, LUMIA_VERSION, true );

			// Inject the module-specific JS data into lumiaAdmin
			$js_data = $instance->get_admin_js_data();
			if ( ! empty( $js_data ) ) {
				$inline = 'window.lumiaAdmin=window.lumiaAdmin||{};';
				if ( ! empty( $js_data['i18n'] ) ) {
					$inline .= 'window.lumiaAdmin.i18n=Object.assign(window.lumiaAdmin.i18n||{},' . wp_json_encode( $js_data['i18n'] ) . ');';
				}
				foreach ( $js_data as $key => $value ) {
					if ( 'i18n' === $key ) {
						continue;
					}
					$inline .= 'window.lumiaAdmin[' . wp_json_encode( $key ) . ']=' . wp_json_encode( $value ) . ';';
				}
				wp_add_inline_script( $handle, $inline, 'before' );
			}
		}
	}

	/* ================================================================
	 * RENDU
	 * ================================================================ */

	/**
	 * Renders the admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have the required permissions.', 'lumia-tools' ) );
		}

		// Some modules require more than `manage_options` (Files, Database under
		// multisite: see AbstractModule). The tab is already absent from the menu,
		// but the URL remains guessable — this is where the refusal matters.
		$required = $this->tab_capability();
		if ( 'manage_options' !== $required && ! current_user_can( $required ) ) {
			wp_die( esc_html__( 'You do not have the required permissions.', 'lumia-tools' ) );
		}

		$this->display_notices();

		include LUMIA_TEMPLATES_DIR . 'admin/layout.php';
	}

	/**
	 * Capability required by the requested tab.
	 *
	 * The menu and the plugin screens run under `manage_options`; only the
	 * modules that declare it ask for more.
	 */
	private function tab_capability(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		if ( 0 !== strpos( $tab, 'module_' ) ) {
			return 'manage_options';
		}

		return $this->module_capability( substr( $tab, strlen( 'module_' ) ) );
	}

	/**
	 * Capability declared by a module, or `manage_options` by default.
	 */
	private function module_capability( string $module_id ): string {
		$definition = $this->modules->get_all()[ $module_id ] ?? null;
		$class      = $definition['class'] ?? '';

		if ( ! $class || ! class_exists( $class ) || ! is_subclass_of( $class, AbstractModule::class ) ) {
			return 'manage_options';
		}

		return (string) $class::get_required_capability();
	}

	/**
	 * Prepares the Lümia feedback toast (via query string) for JS injection.
	 * No longer prints anything directly — the data is consumed by render_notification_drawer().
	 */
	private function display_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['lumia_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = sanitize_key( $_GET['lumia_notice'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = isset( $_GET['lumia_notice_type'] ) ? sanitize_key( $_GET['lumia_notice_type'] ) : 'success';

		$messages = [
			'settings_saved'       => __( 'Settings saved successfully.', 'lumia-tools' ),
			'module_activated'     => __( 'Module activated.', 'lumia-tools' ),
			'module_deactivated'   => __( 'Module deactivated.', 'lumia-tools' ),
			'modules_updated'      => __( 'Modules updated.', 'lumia-tools' ),
			'updates_checked'      => __( 'Update check completed.', 'lumia-tools' ),
			'settings_reset'       => __( 'Configuration reset to default values.', 'lumia-tools' ),
			'settings_imported'    => __( 'Configuration imported successfully.', 'lumia-tools' ),
			'import_error_file'    => __( 'Error while loading the file.', 'lumia-tools' ),
			'import_error_invalid' => __( 'The JSON file is invalid or incompatible.', 'lumia-tools' ),
			'import_error_size'    => __( 'The file exceeds the maximum allowed size (2 MB).', 'lumia-tools' ),
		];

		if ( isset( $messages[ $notice ] ) ) {
			$this->lumia_toast = [
				'message' => $messages[ $notice ],
				'type'    => $type,
			];
		}
	}

	/* ================================================================
	 * CENTRE DE NOTIFICATIONS
	 * ================================================================ */

	/**
	 * Starts capturing the WP notices through output buffering (whole admin).
	 */
	public function capture_wp_notices_start(): void {
		ob_start();
	}

	/**
	 * Ends the capture: the WP notices go into the drawer, everything else is
	 * re-emitted in place.
	 *
	 * Some plugins print something other than a notice on `admin_notices`
	 * (onboarding banner, modal, script). Swallowing the whole buffer made them
	 * vanish from the page without ever reaching the drawer, which only keeps
	 * `.notice` / `.updated` / `.error`.
	 */
	public function capture_wp_notices_end(): void {
		$html  = (string) ob_get_clean();
		$split = $this->split_captured_notices( $html );

		$this->captured_wp_notices = $split['notices'];

		if ( '' !== $split['passthrough'] ) {
			echo $split['passthrough']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML produced by other plugins, re-emitted as is.
		}
	}

	/**
	 * Splits the `admin_notices` buffer in two: the top-level nodes that are
	 * (or contain) a WP notice, and the others.
	 *
	 * @return array{notices: string, passthrough: string}
	 */
	private function split_captured_notices( string $html ): array {
		$result = [
			'notices'     => $html,
			'passthrough' => '',
		];

		if ( '' === trim( $html ) || ! class_exists( '\DOMDocument' ) ) {
			return $result;
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="lumia-notices-root">' . $html . '</div></body></html>',
			LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $loaded ? $dom->getElementById( 'lumia-notices-root' ) : null;
		if ( ! $root ) {
			return $result;
		}

		$notices     = '';
		$passthrough = '';
		$xpath       = new \DOMXPath( $dom );
		$notice_test = "contains(concat(' ', normalize-space(@class), ' '), ' notice ')"
			. " or contains(concat(' ', normalize-space(@class), ' '), ' updated ')"
			. " or contains(concat(' ', normalize-space(@class), ' '), ' error ')";

		foreach ( $root->childNodes as $node ) {
			$is_notice = false;
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$is_notice = $xpath->evaluate( "boolean(self::*[{$notice_test}] | descendant::*[{$notice_test}])", $node );
			}
			if ( $is_notice ) {
				$notices .= $dom->saveHTML( $node );
			} else {
				$passthrough .= $dom->saveHTML( $node );
			}
		}

		return [
			'notices'     => $notices,
			'passthrough' => $passthrough,
		];
	}

	/**
	 * Adds the "Notifications" bell button to the WP admin bar (whole admin).
	 */
	public function register_notification_center( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin() ) {
			return;
		}

		$bell = $this->render_icon( 'bell', 'sm', 'lumia-notif-bell-icon' );

		$wp_admin_bar->add_node(
			[
				'id'     => 'lumia-notif-center',
				'parent' => 'top-secondary',
				'title'  => '<span class="lumia-notif-btn-wrap">' . $bell . '<span class="lumia-notif-badge" id="lumia-notif-badge" style="display:none"></span></span>',
				'href'   => '#lumia-notif-drawer',
				// WP_Admin_Bar escapes meta.title itself: an esc_attr__ here would
				// double-encode (">" rendered as "&gt;").
				'meta'   => [
					'class' => 'lumia-notif-trigger',
					'title' => __( 'Notifications', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Flags in the admin bar that the site asks search engines not to index it
	 * (Settings > Reading). A mere marker, not an alert: it must be readable at
	 * a glance on login without demanding any action.
	 */
	public function register_noindex_indicator( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( '0' !== (string) get_option( 'blog_public', '1' ) ) {
			return;
		}

		$icon = $this->render_icon( 'eye-off', 'sm', 'lumia-noindex-icon' );

		$wp_admin_bar->add_node(
			[
				'id'     => 'lumia-noindex',
				'parent' => 'top-secondary',
				'title'  => '<span class="lumia-noindex-wrap">' . $icon . '<span class="lumia-noindex-label">' . esc_html__( 'No-index', 'lumia-tools' ) . '</span></span>',
				'href'   => admin_url( 'options-reading.php' ),
				'meta'   => [
					'class' => 'lumia-noindex-indicator',
					'title' => __( 'Search engines are asked not to index this site (Settings > Reading).', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Renders the reusable modal on the plugin pages.
	 */
	public function render_modal(): void {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}
		?>
		<div id="lumia-modal-overlay" class="lumia-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">
			<div class="lumia-modal">
				<div class="lumia-modal__header">
					<h2 class="lumia-modal__title"></h2>
				</div>
				<div class="lumia-modal__body">
					<p class="lumia-modal__message"></p>
				</div>
				<div class="lumia-modal__footer">
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal__cancel">
						<?php esc_html_e( 'Cancel', 'lumia-tools' ); ?>
					</button>
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary lumia-modal__confirm">
						<?php esc_html_e( 'Confirm', 'lumia-tools' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the notification drawer + the toast container + the JSON data for JS.
	 * Called through admin_footer on the whole admin, after the WP notices are captured.
	 */
	public function render_notification_drawer(): void {

		$close_icon   = $this->render_icon( 'x', 'sm' );
		$notices_json = wp_json_encode( $this->captured_wp_notices );
		$toast_json   = wp_json_encode( $this->lumia_toast );

		$user_id         = get_current_user_id();
		$raw_notices     = $user_id ? get_user_meta( $user_id, 'lumia_notices', true ) : [];
		$raw_notices     = is_array( $raw_notices ) ? $raw_notices : [];
		$persistent_list = [];
		foreach ( $raw_notices as $notice_id => $notice ) {
			$persistent_list[] = [
				'id'      => $notice_id,
				'message' => $notice['message'] ?? '',
				'type'    => $notice['type'] ?? 'info',
			];
		}
		$persistent_json = wp_json_encode( $persistent_list );
		$notif_data_json = wp_json_encode(
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lumia_admin_nonce' ),
				// notifications.js loads on the whole WP admin, where lumiaAdmin
				// is not defined: its strings travel with this object instead.
				'i18n'    => [
					'close'           => __( 'Close', 'lumia-tools' ),
					'noNotifications' => __( 'No notifications', 'lumia-tools' ),
				],
			]
		);
		?>
		<div id="lumia-notif-drawer" class="lumia-notif-drawer" role="dialog" aria-label="<?php esc_attr_e( 'Notification center', 'lumia-tools' ); ?>" aria-hidden="true">
			<div class="lumia-notif-drawer__header">
				<h2 class="lumia-notif-drawer__title"><?php esc_html_e( 'Notifications', 'lumia-tools' ); ?></h2>
				<button class="lumia-notif-drawer__close" id="lumia-notif-close" type="button" aria-label="<?php esc_attr_e( 'Close', 'lumia-tools' ); ?>">
					<?php echo $close_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<div class="lumia-notif-drawer__body" id="lumia-notif-body"></div>
		</div>
		<div id="lumia-notif-overlay" class="lumia-notif-overlay" aria-hidden="true"></div>
		<div id="lumia-toast-container" class="lumia-toast-container" role="region" aria-live="polite" aria-label="<?php esc_attr_e( 'Notifications', 'lumia-tools' ); ?>"></div>
		<script>
		window.lumiaWpNoticesHtml     = <?php echo $notices_json;    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		window.lumiaToastData         = <?php echo $toast_json;      // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		window.lumiaPersistentNotices = <?php echo $persistent_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		window.lumiaNotifData         = <?php echo $notif_data_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
		</script>
		<?php
	}

	/**
	 * Builds a safe Content-Disposition header for a file name.
	 *
	 * The name used to be injected as is between quotes. Yet on Linux a file
	 * name can contain a quote, and even a line break: the former closes the
	 * value, the latter ends the header and allows adding others — a proper
	 * response header injection, triggered by a simple upload.
	 *
	 * Two parameters are therefore emitted, as RFC 6266 intends:
	 *  - `filename=` as sanitized ASCII, for old clients;
	 *  - `filename*=UTF-8''…` percent-encoded, which carries the real name
	 *    (accents included) and has no quotes to close.
	 *
	 * @param string $filename Raw file name, as it is on disk.
	 */
	public static function content_disposition( string $filename ): string {
		// Strip any path separator, then any control character (including CR
		// and LF) and the quotes.
		$raw_name = basename( $filename );
		$raw_name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $raw_name );

		$ascii = (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $raw_name );
		if ( '' === trim( $ascii, '_.' ) ) {
			$ascii = 'download';
		}

		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $raw_name );
	}

	/**
	 * Adds a persistent notice (survives reloads).
	 * Without $user_id, targets the current user; useful to target a specific
	 * user from a context with no current user (cron).
	 */
	public static function add_persistent_notice( string $id, string $message, string $type = 'info', int $user_id = 0 ): void {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return;
		}
		$notices        = get_user_meta( $user_id, 'lumia_notices', true );
		$notices        = is_array( $notices ) ? $notices : [];
		$notices[ $id ] = [
			'message'   => $message,
			'type'      => $type,
			'timestamp' => time(),
		];
		update_user_meta( $user_id, 'lumia_notices', $notices );
	}

	/**
	 * Removes a persistent notice of the current user.
	 */
	public static function dismiss_persistent_notice( string $id ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$notices = get_user_meta( $user_id, 'lumia_notices', true );
		if ( ! is_array( $notices ) ) {
			return;
		}
		unset( $notices[ $id ] );
		update_user_meta( $user_id, 'lumia_notices', $notices );
	}

	/**
	 * AJAX endpoint: dismiss a persistent Lümia notice.
	 */
	public function handle_dismiss_notice(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}
		$id = isset( $_POST['notice_id'] ) ? sanitize_key( $_POST['notice_id'] ) : '';
		if ( empty( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid ID.', 'lumia-tools' ) ] );
		}
		self::dismiss_persistent_notice( $id );
		wp_send_json_success();
	}

	/* ================================================================
	 * ADMIN ACTIONS HANDLING
	 * ================================================================ */

	/**
	 * Saves the global settings or a module's settings.
	 */
	public function handle_save_settings(): void {
		if ( ! isset( $_POST['lumia_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_nonce'] ) ), 'lumia_save_settings' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		$tab = isset( $_POST['lumia_tab'] ) ? sanitize_key( $_POST['lumia_tab'] ) : 'settings';

		// Global settings
		if ( 'settings' === $tab && isset( $_POST['lumia_global'] ) ) {
			$global = [
				'update_channel' => isset( $_POST['lumia_global']['update_channel'] ) ? sanitize_key( $_POST['lumia_global']['update_channel'] ) : 'stable',
			];
			$this->settings->set( 'global', $global );

			// Unchecked box = absent from POST. Not stored in lumia_settings: it
			// drives the WordPress option, so it enters neither the export nor
			// the reset. Only write if the user changed the box since the page
			// loaded: otherwise, saving the channel alone would undo a setting
			// made in the meantime from the plugins list or WP-CLI.
			$auto_update         = ! empty( $_POST['lumia_global']['auto_update'] );
			$auto_update_initial = ! empty( $_POST['lumia_global']['auto_update_initial'] );

			if ( $auto_update !== $auto_update_initial && wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' ) ) {
				$this->set_auto_update( $auto_update );
			}
		}

		// A module's settings
		if ( strpos( $tab, 'module_' ) === 0 ) {
			$module_id = substr( $tab, 7 );
			$instance  = $this->modules->get_active_instances()[ $module_id ] ?? null;

			// Same rule as render_page(): a module can require more than
			// manage_options (multisite). This test is ADDED to the manage_options
			// checked above, it does not replace it — the capability declared by
			// a module is always stricter, never an alternative.
			if ( ! current_user_can( $this->module_capability( $module_id ) ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
			}

			if ( $instance && isset( $_POST['lumia_module_settings'] ) && is_array( $_POST['lumia_module_settings'] ) ) {
				$instance->save_settings( wp_unslash( $_POST['lumia_module_settings'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce and capability checked above; each module sanitizes its settings in save_settings() (AbstractModule contract).
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => $tab,
					'lumia_notice'      => 'settings_saved',
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Activation/deactivation of a single module (GET or POST).
	 * Nonce read from $_REQUEST to support both HTTP methods.
	 */
	public function handle_toggle_module(): void {
		if ( ! isset( $_REQUEST['lumia_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['lumia_nonce'] ) ), 'lumia_toggle_module' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		$module_id = isset( $_REQUEST['module'] ) ? sanitize_key( $_REQUEST['module'] ) : '';
		$action    = isset( $_REQUEST['lumia_action'] ) ? sanitize_key( $_REQUEST['lumia_action'] ) : '';

		if ( empty( $module_id ) || ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->slug . '&tab=modules' ) );
			exit;
		}

		if ( 'activate' === $action ) {
			$this->modules->activate( $module_id );
			$notice = 'module_activated';
		} else {
			$this->modules->deactivate( $module_id );
			$notice = 'module_deactivated';
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => 'modules',
					'lumia_notice'      => $notice,
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX toggle of a module (JSON response — no redirect).
	 */
	public function handle_ajax_toggle_module(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lumia_admin_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'lumia-tools' ) ], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ], 403 );
		}

		$module_id = isset( $_POST['module'] ) ? sanitize_key( $_POST['module'] ) : '';
		$action    = isset( $_POST['lumia_action'] ) ? sanitize_key( $_POST['lumia_action'] ) : '';

		if ( empty( $module_id ) || ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid parameters.', 'lumia-tools' ) ], 400 );
		}

		if ( 'activate' === $action ) {
			$this->modules->activate( $module_id );
			$notice    = __( 'Module activated.', 'lumia-tools' );
			$new_state = true;
		} else {
			$this->modules->deactivate( $module_id );
			$notice    = __( 'Module deactivated.', 'lumia-tools' );
			$new_state = false;
		}

		$configure_url = add_query_arg(
			[
				'page' => $this->slug,
				'tab'  => 'module_' . $module_id,
			],
			admin_url( 'admin.php' )
		);

		wp_send_json_success(
			[
				'notice'        => $notice,
				'active'        => $new_state,
				'configure_url' => esc_url( $configure_url ),
			]
		);
	}

	/**
	 * Bulk activation/deactivation of the modules.
	 */
	public function handle_update_modules(): void {
		if ( ! isset( $_POST['lumia_modules_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_modules_nonce'] ) ), 'lumia_update_modules' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		$enabled_modules = [];
		if ( isset( $_POST['lumia_modules'] ) && is_array( $_POST['lumia_modules'] ) ) {
			$enabled_modules = array_map( 'sanitize_key', wp_unslash( $_POST['lumia_modules'] ) );
		}

		foreach ( $this->modules->get_all() as $module_id => $module ) {
			if ( in_array( $module_id, $enabled_modules, true ) ) {
				$this->modules->activate( $module_id );
			} else {
				$this->modules->deactivate( $module_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => 'modules',
					'lumia_notice'      => 'modules_updated',
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Enables or disables the plugin's automatic update.
	 *
	 * Writes to `auto_update_plugins`, the option WordPress reads for its
	 * background updates and edits from the plugins list.
	 *
	 * @param bool $enabled Desired state.
	 */
	private function set_auto_update( bool $enabled ): void {
		$plugin_file = plugin_basename( LUMIA_PLUGIN_FILE );
		$current     = (array) get_site_option( 'auto_update_plugins', [] );
		$is_enabled  = in_array( $plugin_file, $current, true );

		if ( $enabled === $is_enabled ) {
			return;
		}

		$updated = $enabled ? array_merge( $current, [ $plugin_file ] ) : array_diff( $current, [ $plugin_file ] );

		update_site_option( 'auto_update_plugins', array_values( array_unique( $updated ) ) );
	}

	/**
	 * Manual update check.
	 */
	public function handle_check_updates(): void {
		if ( ! isset( $_POST['lumia_check_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_check_nonce'] ) ), 'lumia_check_updates' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		delete_site_transient( 'update_plugins' );
		delete_transient( 'lumia_github_update_stable' );
		delete_transient( 'lumia_github_update_dev' );
		wp_update_plugins();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => 'settings',
					'lumia_notice'      => 'updates_checked',
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Resets all the plugin settings to their default values.
	 */
	public function handle_reset_settings(): void {
		if ( ! isset( $_POST['lumia_reset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_reset_nonce'] ) ), 'lumia_reset_settings' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		// Reset the global settings
		$this->settings->set( 'global', [ 'update_channel' => 'stable' ] );

		// Reset the options of each registered module
		foreach ( $this->modules->get_all() as $module_id => $module ) {
			if ( ! empty( $module['class'] ) && class_exists( $module['class'] ) ) {
				$keys = $module['class']::get_uninstall_keys();
				foreach ( $keys['options'] ?? [] as $option_key ) {
					delete_option( $option_key );
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => 'settings',
					'lumia_notice'      => 'settings_reset',
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Exports all the plugin settings as JSON.
	 */
	public function handle_export_settings(): void {
		if ( ! isset( $_POST['lumia_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_export_nonce'] ) ), 'lumia_export_settings' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		$data = [
			'version'  => LUMIA_VERSION,
			'exported' => current_time( 'c' ),
			'global'   => get_option( 'lumia_settings', [] ),
			'modules'  => [],
			'extras'   => [],
		];

		foreach ( $this->modules->get_all() as $module_id => $module ) {
			$data['modules'][ $module_id ] = get_option( 'lumia_module_' . $module_id, [] );

			// Data stored outside lumia_module_{id} (menu profiles, etc.): without
			// this block, the export believes it is complete when it is not.
			// get_all() only returns the definitions: the instance is needed.
			$instance = $this->modules->get_instance( $module_id );
			$extras   = $instance ? $instance->get_export_extras() : [];
			if ( ! empty( $extras ) ) {
				$data['extras'][ $module_id ] = $extras;
			}
		}

		$filename = 'lumia-settings-' . gmdate( 'Y-m-d' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: ' . self::content_disposition( $filename ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Imports the settings from a JSON file.
	 */
	public function handle_import_settings(): void {
		if ( ! isset( $_POST['lumia_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_import_nonce'] ) ), 'lumia_import_settings' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		// Only tmp_name and error are read: a temporary path and a PHP error code, never re-emitted.
		$tmp_name = isset( $_FILES['lumia_import_file']['tmp_name'] ) ? sanitize_text_field( $_FILES['lumia_import_file']['tmp_name'] ) : '';
		$error    = isset( $_FILES['lumia_import_file']['error'] ) ? (int) $_FILES['lumia_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		if ( '' === $tmp_name || UPLOAD_ERR_OK !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'              => $this->slug,
						'tab'               => 'settings',
						'lumia_notice'      => 'import_error_file',
						'lumia_notice_type' => 'error',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// `tmp_name` comes from $_FILES, hence from the client. is_uploaded_file()
		// is the only thing that attests this path designates a file uploaded by
		// THIS request, and not an arbitrary server path slipped into the
		// variable. It is the standard guard before reading any upload.
		if ( ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'              => $this->slug,
						'tab'               => 'settings',
						'lumia_notice'      => 'import_error_file',
						'lumia_notice_type' => 'error',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Size ceiling: the file is read whole then decoded as JSON, two
		// operations held in memory. A full export weighs a few tens of
		// kilobytes; 2 MB leave a comfortable margin without exposing PHP's
		// memory to a file of several hundred.
		if ( filesize( $tmp_name ) > self::IMPORT_MAX_BYTES ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'              => $this->slug,
						'tab'               => 'settings',
						'lumia_notice'      => 'import_error_size',
						'lumia_notice_type' => 'error',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw  = file_get_contents( $tmp_name );
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['global'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'page'              => $this->slug,
						'tab'               => 'settings',
						'lumia_notice'      => 'import_error_invalid',
						'lumia_notice_type' => 'error',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		update_option( 'lumia_settings', $this->sanitize_imported_globals( $data['global'] ) );

		if ( isset( $data['modules'] ) && is_array( $data['modules'] ) ) {
			foreach ( $data['modules'] as $module_id => $module_settings ) {
				$this->import_module_settings( sanitize_key( (string) $module_id ), $module_settings );
			}
		}

		if ( isset( $data['extras'] ) && is_array( $data['extras'] ) ) {
			foreach ( $data['extras'] as $module_id => $extras ) {
				$module_id = sanitize_key( (string) $module_id );
				if ( ! is_array( $extras ) || ! isset( $this->modules->get_all()[ $module_id ] ) ) {
					continue;
				}
				$instance = $this->modules->get_instance( $module_id );
				if ( $instance ) {
					// The module re-sanitizes by itself: same path as its own screens.
					$instance->import_extras( $extras );
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => $this->slug,
					'tab'               => 'settings',
					'lumia_notice'      => 'settings_imported',
					'lumia_notice_type' => 'success',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* ================================================================
	 * IMPORT SANITIZATION
	 *
	 * An imported file goes through no form: it therefore cannot rely on the
	 * modules' save_settings(), written for the form payload (flat) and not for
	 * the stored structure (nested). Writing the JSON as is would nonetheless
	 * bypass ALL the validation — unfiltered HTML in the white label footer,
	 * arbitrary roles, free login slug.
	 *
	 * The value is therefore rebuilt from the module's real schema
	 * (get_settings(), i.e. defaults + stored): unknown keys are discarded,
	 * each value is brought back to the type of its counterpart, and strings
	 * go through wp_kses_post().
	 * ================================================================ */

	/**
	 * Sanitizes the "global" block (full content of the lumia_settings option).
	 *
	 * Strict whitelist: only the keys the plugin knows how to interpret
	 * survive, and the activation state is limited to registered modules.
	 *
	 * @param mixed $raw Imported value.
	 * @return array<string, mixed>
	 */
	private function sanitize_imported_globals( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : [];
		$global  = is_array( $raw['global'] ?? null ) ? $raw['global'] : [];
		$modules = is_array( $raw['modules'] ?? null ) ? $raw['modules'] : [];

		// Start from the existing value: a partial file must not erase the state
		// of the modules it does not mention.
		$current         = get_option( 'lumia_settings', [] );
		$current         = is_array( $current ) ? $current : [];
		$current_global  = is_array( $current['global'] ?? null ) ? $current['global'] : [];
		$current_modules = is_array( $current['modules'] ?? null ) ? $current['modules'] : [];

		$clean = [
			'global'  => $current_global,
			'modules' => $current_modules,
		];

		if ( array_key_exists( 'update_channel', $global ) ) {
			$channel                           = sanitize_key( (string) $global['update_channel'] );
			$clean['global']['update_channel'] = in_array( $channel, [ 'stable', 'dev' ], true ) ? $channel : 'stable';
		}

		// Only the actually registered modules can have their state changed.
		foreach ( array_keys( $this->modules->get_all() ) as $module_id ) {
			if ( array_key_exists( $module_id, $modules ) ) {
				$clean['modules'][ $module_id ] = ! empty( $modules[ $module_id ] );
			}
		}

		return $clean;
	}

	/**
	 * Replays a module's imported settings through its own sanitizer, exactly
	 * as the settings screen would.
	 *
	 * @param string $module_id Module identifier.
	 * @param mixed  $raw       Imported block.
	 * @return bool True if the module was actually updated.
	 */
	private function import_module_settings( string $module_id, $raw ): bool {
		if ( '' === $module_id || ! is_array( $raw ) || ! isset( $this->modules->get_all()[ $module_id ] ) ) {
			return false;
		}

		$instance = $this->modules->get_instance( $module_id );
		if ( ! $instance ) {
			return false;
		}

		// Module without settings (Database, Media, Files…): nothing to import.
		// Going through it would overwrite data for no reason.
		if ( ! $instance->get_settings() ) {
			return false;
		}

		$payload = $instance instanceof AbstractModule
			? $instance->to_form_payload( $raw )
			: $raw;

		return (bool) $instance->save_settings( self::drop_false_values( $payload ) );
	}

	/**
	 * Removes the strictly false values from an associative array.
	 *
	 * An HTML form does not send its unchecked boxes, and some modules rely on
	 * that absence (isset()). A JSON, on the other hand, explicitly carries
	 * "false": without this normalization, a setting disabled at export would
	 * come back enabled at import.
	 *
	 * @param array<string, mixed> $data Payload to normalize.
	 * @return array<string, mixed>
	 */
	private static function drop_false_values( array $data ): array {
		$clean = [];

		foreach ( $data as $key => $value ) {
			if ( false === $value ) {
				continue;
			}

			// Lists (roles, IPs…) are passed through as is: their semantics are
			// positional, not declarative.
			$clean[ $key ] = is_array( $value ) && array_values( $value ) !== $value
				? self::drop_false_values( $value )
				: $value;
		}

		return $clean;
	}

	/* ================================================================
	 * MENU / FOOTER FILTERS
	 * ================================================================ */

	public function filter_parent_file( ?string $parent_file ): string {
		if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), $this->slug ) !== 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.
			return $parent_file ?? '';
		}

		$tab = $this->get_current_tab();

		if ( strpos( $tab, 'module_' ) === 0 || in_array( $tab, [ 'dashboard', 'modules', 'settings' ], true ) ) {
			return $this->slug;
		}

		return $parent_file ?? '';
	}

	public function filter_submenu_file( ?string $submenu_file ): string {
		if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), $this->slug ) !== 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.
			return $submenu_file ?? '';
		}

		$tab = $this->get_current_tab();

		if ( in_array( $tab, [ 'dashboard', 'modules', 'settings' ], true ) || strpos( $tab, 'module_' ) === 0 ) {
			return $this->slug . '&tab=' . $tab;
		}

		return $submenu_file ?? '';
	}

	public function filter_admin_footer_text( string $text ): string {
		return $this->is_plugin_screen() ? '' : $text;
	}

	public function filter_update_footer( string $text ): string {
		return $this->is_plugin_screen() ? '' : $text;
	}

	public function output_menu_separator_css(): void {
		echo '<style>' . $this->get_menu_separator_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal CSS, with no variable data.
	}

	/* ================================================================
	 * SVG ICONS
	 * ================================================================ */

	/**
	 * Returns the inline SVG of an icon.
	 */
	public function render_icon( string $icon, string $size = 'md', string $extra_class = '' ): string {
		$paths = $this->get_icon_paths();
		$path  = $paths[ $icon ] ?? $paths['package'];
		$class = trim( 'lumia-icon lumia-icon--' . $size . ' ' . $extra_class );

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Help marker: Lucide `info` icon carrying a clarification in a tooltip.
	 *
	 * To be kept for secondary caveats: what describes the action stays in the
	 * help text, only the nuance that would lengthen the line goes here.
	 */
	public function render_help_tip( string $text, string $placement = 'top' ): string {
		return '<button type="button" class="lumia-tip-info" tabindex="0"'
			. ' data-lumia-tip="' . esc_attr( $text ) . '"'
			. ( 'top' === $placement ? '' : ' data-lumia-tip-placement="' . esc_attr( $placement ) . '"' )
			. ' aria-label="' . esc_attr( $text ) . '">'
			// The SVG is emitted here rather than by render_icon(): `.lumia-icon`
			// forces 20px with !important (to stand up to wp-admin), which a
			// component rule cannot contradict — the marker would be 20px in a
			// 16px button and overflow the line.
			. '<svg class="lumia-tip-info__i" width="14" height="14" viewBox="0 0 24 24" fill="none"'
			. ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">' . $this->get_icon_paths()['info'] . '</svg>'
			. '</button>';
	}

	/**
	 * @return array<string, string> Lucide name => inner SVG content.
	 */
	private function get_icon_paths(): array {
		return [
			'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
			'package'          => '<path d="m7.5 4.27 9 5.15"></path><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path>',
			'settings'         => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
			'image'            => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="9" cy="9" r="2"></circle><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"></path>',
			'check-circle'     => '<circle cx="12" cy="12" r="10"></circle><path d="m9 12 2 2 4-4"></path>',
			'info'             => '<circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path>',
			'shield'           => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
			'bell'             => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
			'x'                => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
			'log-in'           => '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
			'folder'           => '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
			'chevron-down'     => '<path d="m6 9 6 6 6-6"/>',
			'palette'          => '<path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z"/><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>',
			'menu'             => '<path d="M8 5h13"/><path d="M13 12h8"/><path d="M13 19h8"/><path d="M3 10a2 2 0 0 0 2 2h3"/><path d="M3 5v12a2 2 0 0 0 2 2h3"/>',
			'database'         => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
			'eye-off'          => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
			'mail'             => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
			'history'          => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
			'folder-tree'      => '<path d="M20 10a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-2.5a1 1 0 0 1-.8-.4l-.9-1.2A1 1 0 0 0 15 3h-2a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M20 21a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-2.9a1 1 0 0 1-.88-.55l-.42-.85a1 1 0 0 0-.92-.6H13a1 1 0 0 0-1 1v5a1 1 0 0 0 1 1Z"/><path d="M3 5a2 2 0 0 0 2 2h3"/><path d="M3 3v13a2 2 0 0 0 2 2h3"/>',
		];
	}

	/* ================================================================
	 * PRIVATE HELPERS
	 * ================================================================ */

	private function get_current_tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.
	}

	private function is_plugin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && strpos( $screen->id, $this->slug ) !== false;
	}

	private function get_menu_separator_css(): string {
		return '#adminmenu .lumia-menu-separator{pointer-events:none}'
			. '#adminmenu .lumia-menu-separator a{'
			. 'height:1px;min-height:1px;margin:6px 12px;padding:0!important;'
			. 'background:#c3c4c7;box-shadow:none;text-indent:-9999px;'
			. '}'
			. '#adminmenu .lumia-menu-separator a:hover{background:#c3c4c7}';
	}

	public function get_slug(): string {
		return $this->slug;
	}
}
