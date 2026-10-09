<?php
namespace Lumia\Tools\Admin;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\Modules;
use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Core\Settings;

/**
 * Gère l'interface d'administration du plugin.
 */
class Admin {

	/**
	 * Taille maximale acceptée pour un fichier de configuration importé (2 Mio).
	 *
	 * Le fichier est lu en entier puis décodé en JSON : deux copies en mémoire.
	 * Un export complet pèse quelques dizaines de kilo-octets.
	 */
	const IMPORT_MAX_BYTES = 2097152;

	/**
	 * Slug de la page admin.
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
	 * HTML des notices WP capturées via output buffering.
	 */
	private string $captured_wp_notices = '';

	/**
	 * Données du toast LUMIA à afficher (message + type).
	 *
	 * @var array<string, string>|null
	 */
	private ?array $lumia_toast = null;

	/**
	 * Constructeur.
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
		// Priorité maximale : les pages du plugin ont leur propre pied de page,
		// il doit rester vide même si un module (Marque blanche) le personnalise.
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
	 * Ajoute la page admin principale.
	 */
	public function add_menu_page(): void {
		add_menu_page(
			__( 'Lümia Tools', 'lumia-tools' ),
			__( 'LUMIA', 'lumia-tools' ),
			'manage_options',
			$this->slug,
			[ $this, 'render_page' ],
			plugins_url( 'assets/admin/images/menu-icon.svg', LUMIA_PLUGIN_FILE ),
			99
		);

		$this->add_submenus();
	}

	/**
	 * Ajoute les sous-menus dynamiques.
	 */
	private function add_submenus(): void {
		remove_submenu_page( $this->slug, $this->slug );

		add_submenu_page( $this->slug, __( 'Vue d\'ensemble', 'lumia-tools' ), __( 'Vue d\'ensemble', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=dashboard', [ $this, 'render_page' ] );
		add_submenu_page( $this->slug, __( 'Modules', 'lumia-tools' ), __( 'Modules', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=modules', [ $this, 'render_page' ] );
		add_submenu_page( $this->slug, __( 'Réglages', 'lumia-tools' ), __( 'Réglages', 'lumia-tools' ), 'manage_options', $this->slug . '&tab=settings', [ $this, 'render_page' ] );

		global $submenu;
		if ( isset( $submenu[ $this->slug ] ) ) {
			$submenu[ $this->slug ][] = [ '', 'manage_options', 'lumia-separator', '', 'lumia-menu-separator' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- séparateur visuel : WordPress n'offre aucune API pour en insérer un dans un sous-menu.
		}

		foreach ( $this->modules->get_all() as $module_id => $module ) {
			if ( ! $this->modules->is_active( $module_id ) ) {
				continue;
			}

			$label = ! empty( $module['menu_label'] ) ? $module['menu_label'] : $module['name'];

			// Un module peut exiger plus que `manage_options` (voir
			// AbstractModule::get_required_capability()). WordPress masque alors
			// l'entrée de lui-même ; render_page() refait le test, l'URL restant
			// devinable.
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
	 * Retire les doublons de sous-menus du plugin.
	 */
	private function deduplicate_submenus(): void {
		global $submenu;

		if ( empty( $submenu[ $this->slug ] ) || ! is_array( $submenu[ $this->slug ] ) ) {
			return;
		}

		$top_label = __( 'LUMIA', 'lumia-tools' );
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

		$submenu[ $this->slug ] = $filtered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- dédoublonnage du sous-menu : aucune API WordPress pour réécrire une entrée existante.
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	/**
	 * Charge les assets CSS/JS sur les pages du plugin.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, $this->slug ) === false ) {
			return;
		}

		// tokens.css d'abord : il ne contient que des custom properties, tous les
		// autres feuilles en dependent.
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-reset-css', LUMIA_ASSETS_URL . 'admin/css/reset.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-layout-css', LUMIA_ASSETS_URL . 'admin/css/layout.css', [ 'lumia-reset-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-sidebar-css', LUMIA_ASSETS_URL . 'admin/css/sidebar.css', [ 'lumia-layout-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );

		wp_enqueue_script( 'lumia-admin-js', LUMIA_ASSETS_URL . 'admin/js/admin.js', [], LUMIA_VERSION, true );

		// Bibliothèques tierces partagées : enregistrées une seule fois sous un
		// handle stable, chargées uniquement si un module les déclare en
		// dépendance. Le module Médias utilise le même handle : WordPress
		// dédoublonne donc quand les deux sont présents sur le même écran.
		wp_register_script( 'lumia-sortable-js', LUMIA_ASSETS_URL . 'admin/js/vendor/sortable.min.js', [], LUMIA_VERSION, true );

		$this->localize_admin_script( 'lumia-admin-js' );

		$this->enqueue_module_assets();
	}

	/**
	 * Localise les données globales pour un script admin (sans i18n spécifiques aux modules).
	 */
	private function localize_admin_script( string $handle ): void {
		wp_localize_script(
			$handle,
			'lumiaAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lumia_admin_nonce' ),
				'i18n'    => [
					'saveSuccess' => __( 'Réglages enregistrés avec succès.', 'lumia-tools' ),
					'saveError'   => __( 'Une erreur est survenue.', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Charge les assets du centre de notifications sur tout l'admin WP.
	 */
	public function enqueue_global_notification_assets(): void {
		wp_enqueue_style( 'lumia-notifications-css', LUMIA_ASSETS_URL . 'admin/css/notifications.css', [], LUMIA_VERSION );
		wp_enqueue_script( 'lumia-notifications-js', LUMIA_ASSETS_URL . 'admin/js/notifications.js', [], LUMIA_VERSION, true );
	}

	/**
	 * Charge les assets des modules actifs sur leur page de réglages.
	 */
	private function enqueue_module_assets(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de navigation (onglet ou page affichée), aucune action déclenchée.

		if ( strpos( $tab, 'module_' ) !== 0 ) {
			return;
		}

		$module_id = substr( $tab, 7 );
		$instance  = $this->modules->get_active_instances()[ $module_id ] ?? null;

		if ( ! $instance ) {
			return;
		}

		// CSS du module
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

		// JS du module (avec lumia-admin-js comme dépendance pour que lumiaAdmin soit défini)
		foreach ( $instance->get_admin_js() as $index => $script_url ) {
			if ( empty( $script_url ) ) {
				continue;
			}

			$handle = 'lumia-module-' . $module_id . '-js-' . $index;
			$deps   = array_values( array_filter( array_merge( [ 'lumia-admin-js' ], $instance->get_admin_js_deps() ) ) );
			wp_enqueue_script( $handle, $script_url, $deps, LUMIA_VERSION, true );

			// Injection des données JS spécifiques au module dans lumiaAdmin
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
	 * Rendu de la page admin.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les permissions nécessaires.', 'lumia-tools' ) );
		}

		// Certains modules exigent davantage que `manage_options` (Fichiers,
		// Base de données sous multisite : voir AbstractModule). L'onglet est
		// déjà absent du menu, mais l'URL reste devinable — c'est ici que le
		// refus compte.
		$requise = $this->tab_capability();
		if ( 'manage_options' !== $requise && ! current_user_can( $requise ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les permissions nécessaires.', 'lumia-tools' ) );
		}

		$this->display_notices();

		include LUMIA_TEMPLATES_DIR . 'admin/layout.php';
	}

	/**
	 * Capacité exigée par l'onglet demandé.
	 *
	 * Le menu et les écrans de l'extension tournent sous `manage_options` ;
	 * seuls les modules qui le déclarent en demandent davantage.
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
	 * Capacité déclarée par un module, ou `manage_options` par défaut.
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
	 * Prépare le toast de feedback LUMIA (via query string) pour injection JS.
	 * N'affiche plus rien directement — les données sont consommées par render_notification_drawer().
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
			'settings_saved'       => __( 'Réglages enregistrés avec succès.', 'lumia-tools' ),
			'module_activated'     => __( 'Module activé.', 'lumia-tools' ),
			'module_deactivated'   => __( 'Module désactivé.', 'lumia-tools' ),
			'modules_updated'      => __( 'Modules mis à jour.', 'lumia-tools' ),
			'updates_checked'      => __( 'Vérification des mises à jour effectuée.', 'lumia-tools' ),
			'settings_reset'       => __( 'Configuration réinitialisée aux valeurs par défaut.', 'lumia-tools' ),
			'settings_imported'    => __( 'Configuration importée avec succès.', 'lumia-tools' ),
			'import_error_file'    => __( 'Erreur lors du chargement du fichier.', 'lumia-tools' ),
			'import_error_invalid' => __( 'Le fichier JSON est invalide ou incompatible.', 'lumia-tools' ),
			'import_error_size'    => __( 'Le fichier dépasse la taille maximale autorisée (2 Mo).', 'lumia-tools' ),
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
	 * Démarre la capture des notices WP via output buffering (tout l'admin).
	 */
	public function capture_wp_notices_start(): void {
		ob_start();
	}

	/**
	 * Termine la capture : les notices WP partent dans le tiroir, tout le
	 * reste est réémis en place.
	 *
	 * Certaines extensions impriment sur `admin_notices` autre chose qu'une
	 * notice (bandeau d'onboarding, modale, script). Avaler tout le tampon
	 * les faisait disparaître de la page sans jamais atteindre le tiroir,
	 * qui ne garde que `.notice` / `.updated` / `.error`.
	 */
	public function capture_wp_notices_end(): void {
		$html  = (string) ob_get_clean();
		$split = $this->split_captured_notices( $html );

		$this->captured_wp_notices = $split['notices'];

		if ( '' !== $split['passthrough'] ) {
			echo $split['passthrough']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML produit par d'autres extensions, réémis tel quel.
		}
	}

	/**
	 * Sépare le tampon `admin_notices` en deux : les nœuds de premier niveau
	 * qui sont (ou contiennent) une notice WP, et les autres.
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
	 * Ajoute le bouton cloche "Notifications" dans la barre d'admin WP (tout l'admin).
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
				// WP_Admin_Bar échappe lui-même meta.title : un esc_attr__ ici
				// double-encoderait (« > » rendu « &gt; »).
				'meta'   => [
					'class' => 'lumia-notif-trigger',
					'title' => __( 'Notifications', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Signale dans la barre d'admin que le site demande aux moteurs de ne pas
	 * l'indexer (Réglages > Lecture). Un simple repère, pas une alerte : il doit
	 * se lire d'un coup d'œil à la connexion sans réclamer d'action.
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
					'title' => __( 'Les moteurs de recherche sont invités à ne pas indexer ce site (Réglages > Lecture).', 'lumia-tools' ),
				],
			]
		);
	}

	/**
	 * Rend la modal réutilisable sur les pages du plugin.
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
						<?php esc_html_e( 'Annuler', 'lumia-tools' ); ?>
					</button>
					<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary lumia-modal__confirm">
						<?php esc_html_e( 'Confirmer', 'lumia-tools' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rend le drawer de notifications + le conteneur de toasts + les données JSON pour JS.
	 * Appelé via admin_footer sur tout l'admin, après capture des notices WP.
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
			]
		);
		?>
		<div id="lumia-notif-drawer" class="lumia-notif-drawer" role="dialog" aria-label="<?php esc_attr_e( 'Centre de notifications', 'lumia-tools' ); ?>" aria-hidden="true">
			<div class="lumia-notif-drawer__header">
				<h2 class="lumia-notif-drawer__title"><?php esc_html_e( 'Notifications', 'lumia-tools' ); ?></h2>
				<button class="lumia-notif-drawer__close" id="lumia-notif-close" type="button" aria-label="<?php esc_attr_e( 'Fermer', 'lumia-tools' ); ?>">
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
	 * Construit un en-tête Content-Disposition sûr pour un nom de fichier.
	 *
	 * Le nom était injecté tel quel entre guillemets. Or sous Linux un nom de
	 * fichier peut contenir un guillemet, et même un retour à la ligne : le
	 * premier referme la valeur, le second termine l'en-tête et permet d'en
	 * ajouter d'autres — une injection d'en-tête de réponse en bonne et due
	 * forme, déclenchée par un simple téléversement.
	 *
	 * On rend donc deux paramètres, comme le veut la RFC 6266 :
	 *  - `filename=` en ASCII assaini, pour les clients anciens ;
	 *  - `filename*=UTF-8''…` percent-encodé, qui porte le nom réel (accents
	 *    compris) et n'a pas de guillemets à refermer.
	 *
	 * @param string $filename Nom de fichier brut, tel qu'il est sur le disque.
	 */
	public static function content_disposition( string $filename ): string {
		// Retire tout séparateur de chemin, puis tout caractère de contrôle
		// (dont CR et LF) et les guillemets.
		$brut = basename( $filename );
		$brut = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $brut );

		$ascii = (string) preg_replace( '/[^A-Za-z0-9._-]/', '_', $brut );
		if ( '' === trim( $ascii, '_.' ) ) {
			$ascii = 'download';
		}

		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $brut );
	}

	/**
	 * Ajoute une notice persistante (survit aux rechargements).
	 * Sans $user_id, cible l'utilisateur courant ; utile pour cibler un
	 * utilisateur précis depuis un contexte sans utilisateur courant (cron).
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
	 * Supprime une notice persistante de l'utilisateur courant.
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
	 * Endpoint AJAX : dismiss d'une notice persistante LUMIA.
	 */
	public function handle_dismiss_notice(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}
		$id = isset( $_POST['notice_id'] ) ? sanitize_key( $_POST['notice_id'] ) : '';
		if ( empty( $id ) ) {
			wp_send_json_error( [ 'message' => __( 'ID invalide.', 'lumia-tools' ) ] );
		}
		self::dismiss_persistent_notice( $id );
		wp_send_json_success();
	}

	/* ================================================================
	 * GESTION DES ACTIONS ADMIN
	 * ================================================================ */

	/**
	 * Sauvegarde des réglages globaux ou d'un module.
	 */
	public function handle_save_settings(): void {
		if ( ! isset( $_POST['lumia_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_nonce'] ) ), 'lumia_save_settings' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		$tab = isset( $_POST['lumia_tab'] ) ? sanitize_key( $_POST['lumia_tab'] ) : 'settings';

		// Réglages globaux
		if ( 'settings' === $tab && isset( $_POST['lumia_global'] ) ) {
			$global = [
				'update_channel' => isset( $_POST['lumia_global']['update_channel'] ) ? sanitize_key( $_POST['lumia_global']['update_channel'] ) : 'stable',
			];
			$this->settings->set( 'global', $global );

			// Case décochée = absente du POST. Pas stockée dans lumia_settings :
			// elle pilote l'option WordPress, donc n'entre ni dans l'export ni
			// dans la réinitialisation. On n'écrit que si l'utilisateur a changé
			// la case depuis le chargement de la page : sinon, enregistrer le
			// seul canal annulerait un réglage fait entre-temps depuis la liste
			// des extensions ou WP-CLI.
			$auto_update         = ! empty( $_POST['lumia_global']['auto_update'] );
			$auto_update_initial = ! empty( $_POST['lumia_global']['auto_update_initial'] );

			if ( $auto_update !== $auto_update_initial && wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' ) ) {
				$this->set_auto_update( $auto_update );
			}
		}

		// Réglages d'un module
		if ( strpos( $tab, 'module_' ) === 0 ) {
			$module_id = substr( $tab, 7 );
			$instance  = $this->modules->get_active_instances()[ $module_id ] ?? null;

			// Même règle que render_page() : un module peut exiger davantage que
			// manage_options (multisite). Ce test s'AJOUTE au manage_options
			// vérifié plus haut, il ne le remplace pas — la capacité déclarée
			// par un module est toujours plus stricte, jamais une alternative.
			if ( ! current_user_can( $this->module_capability( $module_id ) ) ) {
				wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
			}

			if ( $instance && isset( $_POST['lumia_module_settings'] ) && is_array( $_POST['lumia_module_settings'] ) ) {
				$instance->save_settings( wp_unslash( $_POST['lumia_module_settings'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce et capacité vérifiés plus haut ; chaque module assainit ses réglages dans save_settings() (contrat AbstractModule).
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
	 * Activation/désactivation d'un seul module (GET ou POST).
	 * Nonce lu depuis $_REQUEST pour supporter les deux méthodes HTTP.
	 */
	public function handle_toggle_module(): void {
		if ( ! isset( $_REQUEST['lumia_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['lumia_nonce'] ) ), 'lumia_toggle_module' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
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
	 * Toggle AJAX d'un module (réponse JSON — pas de redirect).
	 */
	public function handle_ajax_toggle_module(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'lumia_admin_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Nonce invalide.', 'lumia-tools' ) ], 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ], 403 );
		}

		$module_id = isset( $_POST['module'] ) ? sanitize_key( $_POST['module'] ) : '';
		$action    = isset( $_POST['lumia_action'] ) ? sanitize_key( $_POST['lumia_action'] ) : '';

		if ( empty( $module_id ) || ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Paramètres invalides.', 'lumia-tools' ) ], 400 );
		}

		if ( 'activate' === $action ) {
			$this->modules->activate( $module_id );
			$notice    = __( 'Module activé.', 'lumia-tools' );
			$new_state = true;
		} else {
			$this->modules->deactivate( $module_id );
			$notice    = __( 'Module désactivé.', 'lumia-tools' );
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
	 * Activation/désactivation en masse des modules.
	 */
	public function handle_update_modules(): void {
		if ( ! isset( $_POST['lumia_modules_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_modules_nonce'] ) ), 'lumia_update_modules' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
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
	 * Active ou coupe la mise à jour automatique du plugin.
	 *
	 * Écrit dans `auto_update_plugins`, l'option que WordPress lit pour ses
	 * mises à jour de fond et qu'il modifie depuis la liste des extensions.
	 *
	 * @param bool $enabled État voulu.
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
	 * Vérification manuelle des mises à jour.
	 */
	public function handle_check_updates(): void {
		if ( ! isset( $_POST['lumia_check_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_check_nonce'] ) ), 'lumia_check_updates' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
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
	 * Réinitialisation de tous les réglages du plugin aux valeurs par défaut.
	 */
	public function handle_reset_settings(): void {
		if ( ! isset( $_POST['lumia_reset_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_reset_nonce'] ) ), 'lumia_reset_settings' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		// Reset réglages globaux
		$this->settings->set( 'global', [ 'update_channel' => 'stable' ] );

		// Reset les options de chaque module enregistré
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
	 * Export de tous les réglages du plugin en JSON.
	 */
	public function handle_export_settings(): void {
		if ( ! isset( $_POST['lumia_export_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_export_nonce'] ) ), 'lumia_export_settings' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
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

			// Données rangées hors de lumia_module_{id} (profils de menu, etc.) :
			// sans ce bloc, l'export se croit complet alors qu'il ne l'est pas.
			// get_all() ne renvoie que les définitions : il faut l'instance.
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
	 * Import des réglages depuis un fichier JSON.
	 */
	public function handle_import_settings(): void {
		if ( ! isset( $_POST['lumia_import_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_import_nonce'] ) ), 'lumia_import_settings' ) ) {
			wp_die( esc_html__( 'Nonce invalide.', 'lumia-tools' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'lumia-tools' ) );
		}

		// Seuls tmp_name et error sont lus : un chemin temporaire et un code d'erreur PHP, jamais réémis.
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

		// `tmp_name` vient de $_FILES, donc du client. is_uploaded_file() est la
		// seule chose qui atteste que ce chemin désigne bien un fichier déposé
		// par CETTE requête, et non un chemin arbitraire du serveur glissé dans
		// la variable. C'est la garde standard avant toute lecture d'un upload.
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

		// Plafond de taille : le fichier est lu en entier puis décodé en JSON,
		// deux opérations qui tiennent en mémoire. Un export complet pèse
		// quelques dizaines de kilo-octets ; 2 Mo laissent une marge confortable
		// sans exposer la mémoire de PHP à un fichier de plusieurs centaines.
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
					// Le module réassainit lui-même : même chemin que ses propres écrans.
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
	 * ASSAINISSEMENT DE L'IMPORT
	 *
	 * Un fichier importé ne passe par aucun formulaire : il ne peut donc pas
	 * s'appuyer sur les save_settings() des modules, écrits pour la charge utile
	 * du formulaire (à plat) et non pour la structure stockée (imbriquée).
	 * Écrire le JSON tel quel contournerait pourtant TOUTE la validation —
	 * HTML non filtré dans le pied de page marque blanche, rôles arbitraires,
	 * slug de connexion libre.
	 *
	 * On reconstruit donc la valeur à partir du schéma réel du module
	 * (get_settings(), soit defaults + stocké) : les clés inconnues sont
	 * écartées, chaque valeur est ramenée au type de sa contrepartie, et les
	 * chaînes passent par wp_kses_post().
	 * ================================================================ */

	/**
	 * Assainit le bloc « global » (contenu complet de l'option lumia_settings).
	 *
	 * Whitelist stricte : seules les clés que le plugin sait interpréter
	 * survivent, et l'état d'activation se limite aux modules enregistrés.
	 *
	 * @param mixed $raw Valeur importée.
	 * @return array<string, mixed>
	 */
	private function sanitize_imported_globals( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : [];
		$global  = is_array( $raw['global'] ?? null ) ? $raw['global'] : [];
		$modules = is_array( $raw['modules'] ?? null ) ? $raw['modules'] : [];

		// On part de l'existant : un fichier partiel ne doit pas effacer l'état
		// des modules qu'il ne mentionne pas.
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

		// Seuls les modules réellement enregistrés peuvent voir leur état changer.
		foreach ( array_keys( $this->modules->get_all() ) as $module_id ) {
			if ( array_key_exists( $module_id, $modules ) ) {
				$clean['modules'][ $module_id ] = ! empty( $modules[ $module_id ] );
			}
		}

		return $clean;
	}

	/**
	 * Rejoue les réglages importés d'un module à travers son propre
	 * assainisseur, exactement comme le ferait l'écran de réglages.
	 *
	 * @param string $module_id Identifiant du module.
	 * @param mixed  $raw       Bloc importé.
	 * @return bool Vrai si le module a effectivement été mis à jour.
	 */
	private function import_module_settings( string $module_id, $raw ): bool {
		if ( '' === $module_id || ! is_array( $raw ) || ! isset( $this->modules->get_all()[ $module_id ] ) ) {
			return false;
		}

		$instance = $this->modules->get_instance( $module_id );
		if ( ! $instance ) {
			return false;
		}

		// Module sans réglages (Base de données, Médias, Fichiers…) : rien à
		// importer. Le traverser écraserait des données sans raison.
		if ( ! $instance->get_settings() ) {
			return false;
		}

		$payload = $instance instanceof AbstractModule
			? $instance->to_form_payload( $raw )
			: $raw;

		return (bool) $instance->save_settings( self::drop_false_values( $payload ) );
	}

	/**
	 * Retire les valeurs strictement false d'un tableau associatif.
	 *
	 * Un formulaire HTML n'envoie pas ses cases décochées, et certains modules
	 * s'appuient sur cette absence (isset()). Un JSON, lui, porte explicitement
	 * « false » : sans cette normalisation, un réglage désactivé à l'export
	 * reviendrait activé à l'import.
	 *
	 * @param array<string, mixed> $data Charge utile à normaliser.
	 * @return array<string, mixed>
	 */
	private static function drop_false_values( array $data ): array {
		$clean = [];

		foreach ( $data as $key => $value ) {
			if ( false === $value ) {
				continue;
			}

			// Les listes (rôles, IP…) sont transmises telles quelles : leur
			// sémantique est positionnelle, pas déclarative.
			$clean[ $key ] = is_array( $value ) && array_values( $value ) !== $value
				? self::drop_false_values( $value )
				: $value;
		}

		return $clean;
	}

	/* ================================================================
	 * FILTRES MENU / FOOTER
	 * ================================================================ */

	public function filter_parent_file( ?string $parent_file ): string {
		if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), $this->slug ) !== 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de navigation (onglet ou page affichée), aucune action déclenchée.
			return $parent_file ?? '';
		}

		$tab = $this->get_current_tab();

		if ( strpos( $tab, 'module_' ) === 0 || in_array( $tab, [ 'dashboard', 'modules', 'settings' ], true ) ) {
			return $this->slug;
		}

		return $parent_file ?? '';
	}

	public function filter_submenu_file( ?string $submenu_file ): string {
		if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), $this->slug ) !== 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de navigation (onglet ou page affichée), aucune action déclenchée.
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
		echo '<style>' . $this->get_menu_separator_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS littérale, sans aucune donnée variable.
	}

	/* ================================================================
	 * ICÔNES SVG
	 * ================================================================ */

	/**
	 * Retourne le SVG inline d'une icône.
	 */
	public function render_icon( string $icon, string $size = 'md', string $extra_class = '' ): string {
		$paths = $this->get_icon_paths();
		$path  = $paths[ $icon ] ?? $paths['package'];
		$class = trim( 'lumia-icon lumia-icon--' . $size . ' ' . $extra_class );

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Marqueur d'aide : icône Lucide `info` portant une précision en tooltip.
	 *
	 * À réserver aux réserves secondaires : ce qui décrit l'action reste dans
	 * le texte d'aide, seule la nuance qui allongerait la ligne passe ici.
	 */
	public function render_help_tip( string $text, string $placement = 'top' ): string {
		return '<button type="button" class="lumia-tip-info" tabindex="0"'
			. ' data-lumia-tip="' . esc_attr( $text ) . '"'
			. ( 'top' === $placement ? '' : ' data-lumia-tip-placement="' . esc_attr( $placement ) . '"' )
			. ' aria-label="' . esc_attr( $text ) . '">'
			// Le SVG est émis ici plutôt que par render_icon() : `.lumia-icon`
			// force 20px en !important (pour tenir tête à wp-admin), ce qu'une
			// règle de composant ne peut pas contredire — le marqueur ferait
			// 20px dans un bouton de 16 et déborderait de la ligne.
			. '<svg class="lumia-tip-info__i" width="14" height="14" viewBox="0 0 24 24" fill="none"'
			. ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">' . $this->get_icon_paths()['info'] . '</svg>'
			. '</button>';
	}

	/**
	 * @return array<string, string> Nom Lucide => contenu SVG interne.
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
	 * HELPERS PRIVÉS
	 * ================================================================ */

	private function get_current_tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de navigation (onglet ou page affichée), aucune action déclenchée.
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
