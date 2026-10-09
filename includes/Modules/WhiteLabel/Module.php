<?php
namespace Lumia\Tools\Modules\WhiteLabel;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Module Marque Blanche — nettoyage de la barre d'administration WordPress
 * et personnalisation du footer admin.
 */
class Module extends AbstractModule {

	/**
	 * Clé de user meta stockant l'ID de pièce jointe de l'avatar local.
	 */
	private const AVATAR_META = 'lumia_local_avatar';

	/**
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	public function init(): void {
		$s  = $this->get_settings();
		$ab = $s['admin_bar'];

		if ( ! empty( $ab['hide_wp_logo'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_wp_logo' ], 999 );
		}
		if ( ! empty( $ab['hide_site_menu'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_site_menu' ], 999 );
		}
		if ( ! empty( $ab['hide_command_palette'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_command_palette' ], 999 );
		}
		if ( ! empty( $ab['hide_updates_counter'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_updates_menu' ], 999 );
		}
		if ( ! empty( $ab['hide_comments_counter'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_comments_menu' ], 999 );
		}
		if ( ! empty( $ab['hide_new_content_menu'] ) ) {
			add_action( 'admin_bar_menu', [ $this, 'remove_new_content_menu' ], 999 );
		}
		if ( ! empty( $ab['hide_help_button'] ) ) {
			add_action( 'admin_head', [ $this, 'hide_help_button_css' ] );
		}
		if ( ! empty( $ab['hide_screen_options'] ) ) {
			add_action( 'admin_head', [ $this, 'hide_screen_options_css' ] );
		}
		if ( ! empty( $ab['remove_howdy'] ) ) {
			// 9999 : le cœur n'ajoute « Mon compte » qu'à la priorité 9991
			// (wp_admin_bar_my_account_item), bien après ses autres nœuds.
			add_action( 'admin_bar_menu', [ $this, 'remove_howdy' ], 9999 );
		}
		if ( ! empty( $ab['hide_frontend'] ) ) {
			add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_frontend' ] );
		}

		$footer = $s['footer'];
		if ( ! empty( $footer['left_text'] ) ) {
			add_filter( 'admin_footer_text', [ $this, 'filter_footer_left' ], 20 );
		}
		if ( ! empty( $footer['hide_right_text'] ) || ! empty( $footer['right_text'] ) ) {
			add_filter( 'update_footer', [ $this, 'filter_footer_right' ], 20 );
		}

		// Épuration de la page de profil : uniquement si au moins un toggle est actif.
		if ( array_filter( $s['profile'] ) ) {
			add_action( 'admin_head', [ $this, 'clean_profile_page' ] );
		}

		// Avatars locaux : l'avatar téléversé prime, sinon on laisse WordPress
		// retomber sur Gravatar (comportement par défaut).
		if ( ! empty( $s['avatars']['local'] ) ) {
			add_filter( 'get_avatar_data', [ $this, 'apply_local_avatar' ], 10, 2 );
			// personal_options se déclenche en haut du formulaire de profil
			// (dans « Options personnelles », avant la section « Nom »), sur
			// profile.php ET user-edit.php.
			add_action( 'personal_options', [ $this, 'render_avatar_field' ] );
			add_action( 'personal_options_update', [ $this, 'save_avatar_field' ] );
			add_action( 'edit_user_profile_update', [ $this, 'save_avatar_field' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_avatar_media' ] );
			// Masque l'« Illustration du profil » native (Gravatar) au profit
			// de l'avatar local.
			add_action( 'admin_head', [ $this, 'hide_native_profile_picture' ] );
		}
	}

	/* ================================================================
	 * ADMIN BAR
	 * ================================================================ */

	public function remove_wp_logo( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'wp-logo' );
	}

	public function remove_site_menu( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'site-name' );
		$bar->remove_node( 'wpadminbar-home' );
	}

	public function remove_command_palette( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'command-palette' );
	}

	public function remove_updates_menu( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'updates' );
	}

	public function remove_comments_menu( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'comments' );
	}

	public function remove_new_content_menu( \WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'new-content' );
	}

	public function hide_help_button_css(): void {
		echo '<style>#contextual-help-link-wrap{display:none!important}</style>';
	}

	public function hide_screen_options_css(): void {
		echo '<style>#screen-options-link-wrap{display:none!important}</style>';
	}

	/**
	 * Retire la salutation du nœud « Mon compte ».
	 *
	 * Le cœur construit ce titre comme sprintf( __( 'Howdy, %s' ), <span
	 * class="display-name">…</span> ) suivi de l'avatar : on retranche le
	 * préfixe traduit tel qu'il est réellement rendu. Un filtre gettext
	 * faisait la même chose, mais en s'exécutant pour CHAQUE chaîne traduite
	 * de chaque page d'admin.
	 */
	public function remove_howdy( \WP_Admin_Bar $bar ): void {
		$node = $bar->get_node( 'my-account' );
		if ( ! $node || empty( $node->title ) ) {
			return;
		}

		/* translators: %s: user's display name. */
		$prefix = sprintf( __( 'Howdy, %s' ), '' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- chaîne du cœur, à retrouver dans sa traduction.
		if ( '' === $prefix || 0 !== strpos( $node->title, $prefix ) ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => 'my-account',
				'title' => substr( $node->title, strlen( $prefix ) ),
			]
		);
	}

	public function hide_admin_bar_frontend( bool $show ): bool {
		return is_admin() ? $show : false;
	}

	public function filter_footer_left( string $text ): string {
		$custom = $this->settings['footer']['left_text'] ?? '';
		return $custom ? wp_kses_post( $custom ) : $text;
	}

	public function filter_footer_right( string $text ): string {
		if ( ! empty( $this->settings['footer']['hide_right_text'] ) ) {
			return '';
		}
		$custom = $this->settings['footer']['right_text'] ?? '';
		return $custom ? wp_kses_post( $custom ) : $text;
	}

	/* ================================================================
	 * PAGE DE PROFIL (profile.php / user-edit.php)
	 * ================================================================ */

	/**
	 * Masque les sections choisies de la page de profil via CSS.
	 *
	 * On cible les classes de <tr>/section stables de core (profile.php et
	 * user-edit.php) plutôt que de dépendre de remove_action (registrations
	 * variables selon la version WP). Masquage visuel : les fonctionnalités
	 * (ex. mots de passe d'application) restent intactes côté serveur.
	 */
	public function clean_profile_page(): void {
		$pagenow = $GLOBALS['pagenow'] ?? '';
		if ( 'profile.php' !== $pagenow && 'user-edit.php' !== $pagenow ) {
			return;
		}

		$p         = $this->settings['profile'] ?? [];
		$selectors = [];

		if ( ! empty( $p['hide_color_scheme'] ) ) {
			$selectors[] = '.user-admin-color-wrap';
		}
		if ( ! empty( $p['hide_keyboard_shortcuts'] ) ) {
			$selectors[] = '.user-comment-shortcuts-wrap';
		}
		if ( ! empty( $p['hide_toolbar_toggle'] ) ) {
			$selectors[] = '.user-admin-bar-front-wrap';
		}
		if ( ! empty( $p['hide_app_passwords'] ) ) {
			$selectors[] = '#application-passwords-section';
		}
		if ( ! empty( $p['hide_language'] ) ) {
			$selectors[] = '.user-language-wrap';
		}
		if ( ! empty( $p['hide_bio'] ) ) {
			$selectors[] = '.user-description-wrap';
		}
		if ( ! empty( $p['hide_sessions'] ) ) {
			$selectors[] = '.user-sessions-wrap';
		}
		if ( ! empty( $p['hide_editor_options'] ) ) {
			$selectors[] = '.user-rich-editing-wrap';
			$selectors[] = '.user-syntax-highlighting-wrap';
		}

		if ( ! $selectors ) {
			return;
		}

		echo '<style>' . implode( ',', $selectors ) . '{display:none!important}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ================================================================
	 * AVATARS LOCAUX
	 * ================================================================ */

	/**
	 * Remplace l'avatar par l'image locale de l'utilisateur si elle existe.
	 * Sinon on ne touche à rien : WordPress retombe sur Gravatar.
	 *
	 * @param array<string, mixed> $args
	 * @param mixed $id_or_email
	 * @return array<string, mixed>
	 */
	public function apply_local_avatar( array $args, $id_or_email ): array {
		// Respecte une demande explicite de l'avatar par défaut.
		if ( ! empty( $args['force_default'] ) ) {
			return $args;
		}

		$user_id = $this->resolve_avatar_user_id( $id_or_email );
		if ( ! $user_id ) {
			return $args;
		}

		$attachment_id = (int) get_user_meta( $user_id, self::AVATAR_META, true );
		if ( $attachment_id <= 0 ) {
			return $args; // Pas d'avatar local → comportement WordPress (Gravatar).
		}

		$size = isset( $args['size'] ) ? max( 1, (int) $args['size'] ) : 96;
		$src  = wp_get_attachment_image_url( $attachment_id, [ $size, $size ] );
		if ( ! $src ) {
			return $args; // Pièce jointe supprimée → fallback Gravatar.
		}

		$args['url']          = $src;
		$args['found_avatar'] = true;
		return $args;
	}

	/**
	 * Résout un identifiant d'avatar WordPress (ID, e-mail, WP_User,
	 * WP_Post, WP_Comment) vers un ID utilisateur, ou 0 si introuvable.
	 *
	 * @param mixed $id_or_email
	 */
	private function resolve_avatar_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof \WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			$email = (string) $id_or_email->comment_author_email;
			$user  = $email ? get_user_by( 'email', $email ) : false;
			return $user ? (int) $user->ID : 0;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}

	/**
	 * Charge le media uploader WP sur les pages de profil.
	 */
	public function enqueue_avatar_media( string $hook ): void {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}
		wp_enqueue_media();
	}

	/**
	 * Affiche le champ « Avatar local » en haut du formulaire de profil.
	 *
	 * Rendu sous forme de <tr> car branché sur `personal_options`, qui est
	 * appelé à l'intérieur de la table « Options personnelles ».
	 */
	public function render_avatar_field( \WP_User $user ): void {
		$attachment_id = (int) get_user_meta( $user->ID, self::AVATAR_META, true );
		?>
		<tr class="lumia-local-avatar-wrap">
			<th><label for="lumia-local-avatar-choose"><?php esc_html_e( 'Avatar', 'lumia-tools' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'lumia_local_avatar', 'lumia_local_avatar_nonce' ); ?>
				<div class="lumia-local-avatar" style="display:flex;align-items:center;gap:16px">
					<span class="lumia-local-avatar__preview" style="display:inline-flex;border-radius:50%;overflow:hidden;line-height:0">
						<?php echo get_avatar( $user->ID, 96 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
					<span>
						<input type="hidden" id="lumia-local-avatar-input" name="lumia_local_avatar" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
						<button type="button" class="button" id="lumia-local-avatar-choose"><?php esc_html_e( 'Choisir une image', 'lumia-tools' ); ?></button>
						<button type="button" class="button-link delete" id="lumia-local-avatar-remove" style="<?php echo $attachment_id ? '' : 'display:none'; ?>;margin-left:8px"><?php esc_html_e( 'Retirer', 'lumia-tools' ); ?></button>
						<p class="description"><?php esc_html_e( 'Prioritaire sur Gravatar. Laissez vide pour utiliser Gravatar (comportement WordPress par défaut).', 'lumia-tools' ); ?></p>
					</span>
				</div>
				<script>
				( function () {
					function init() {
						var choose  = document.getElementById( 'lumia-local-avatar-choose' );
						var remove  = document.getElementById( 'lumia-local-avatar-remove' );
						var input   = document.getElementById( 'lumia-local-avatar-input' );
						var preview = document.querySelector( '.lumia-local-avatar__preview img' );
						if ( ! choose || ! input ) { return; }
						var frame;
						choose.addEventListener( 'click', function ( e ) {
							e.preventDefault();
							// wp.media est chargé en footer : on le teste au clic, pas au parse.
							if ( ! window.wp || ! window.wp.media ) { return; }
							if ( frame ) { frame.open(); return; }
							frame = window.wp.media( {
								title: <?php echo wp_json_encode( __( 'Choisir un avatar', 'lumia-tools' ) ); ?>,
								button: { text: <?php echo wp_json_encode( __( 'Utiliser cette image', 'lumia-tools' ) ); ?> },
								library: { type: 'image' },
								multiple: false
							} );
							frame.on( 'select', function () {
								var att = frame.state().get( 'selection' ).first().toJSON();
								input.value = att.id;
								var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
								if ( preview ) { preview.src = url; }
								if ( remove ) { remove.style.display = ''; }
							} );
							frame.open();
						} );
						if ( remove ) {
							remove.addEventListener( 'click', function ( e ) {
								e.preventDefault();
								input.value = '';
								remove.style.display = 'none';
							} );
						}
					}
					if ( document.readyState === 'loading' ) {
						document.addEventListener( 'DOMContentLoaded', init );
					} else {
						init();
					}
				} )();
				</script>
			</td>
		</tr>
		<?php
	}

	/**
	 * Masque l'« Illustration du profil » native (aperçu + lien Gravatar)
	 * lorsque les avatars locaux sont activés.
	 */
	public function hide_native_profile_picture(): void {
		$pagenow = $GLOBALS['pagenow'] ?? '';
		if ( 'profile.php' !== $pagenow && 'user-edit.php' !== $pagenow ) {
			return;
		}
		echo '<style>.user-profile-picture{display:none!important}</style>';
	}

	/**
	 * Enregistre l'avatar local choisi sur la page de profil.
	 */
	public function save_avatar_field( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['lumia_local_avatar_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lumia_local_avatar_nonce'] ) ), 'lumia_local_avatar' ) ) {
			return;
		}

		$attachment_id = isset( $_POST['lumia_local_avatar'] ) ? absint( wp_unslash( $_POST['lumia_local_avatar'] ) ) : 0;
		if ( $attachment_id > 0 ) {
			update_user_meta( $user_id, self::AVATAR_META, $attachment_id );
		} else {
			delete_user_meta( $user_id, self::AVATAR_META );
		}
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$this->settings = $this->get_module_settings( static::get_defaults() );
		return $this->settings;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$data = [
			'admin_bar' => [
				'hide_wp_logo'          => (bool) ( $settings['admin_bar']['hide_wp_logo'] ?? false ),
				'hide_site_menu'        => (bool) ( $settings['admin_bar']['hide_site_menu'] ?? false ),
				'hide_command_palette'  => (bool) ( $settings['admin_bar']['hide_command_palette'] ?? false ),
				'hide_updates_counter'  => (bool) ( $settings['admin_bar']['hide_updates_counter'] ?? false ),
				'hide_comments_counter' => (bool) ( $settings['admin_bar']['hide_comments_counter'] ?? false ),
				'hide_new_content_menu' => (bool) ( $settings['admin_bar']['hide_new_content_menu'] ?? false ),
				'hide_help_button'      => (bool) ( $settings['admin_bar']['hide_help_button'] ?? false ),
				'hide_screen_options'   => (bool) ( $settings['admin_bar']['hide_screen_options'] ?? false ),
				'remove_howdy'          => (bool) ( $settings['admin_bar']['remove_howdy'] ?? false ),
				'hide_frontend'         => (bool) ( $settings['admin_bar']['hide_frontend'] ?? false ),
			],
			'footer'    => [
				'left_text'       => wp_kses_post( $settings['footer']['left_text'] ?? '' ),
				'hide_right_text' => ! empty( $settings['footer']['hide_right_text'] ),
				'right_text'      => wp_kses_post( $settings['footer']['right_text'] ?? '' ),
			],
			'profile'   => [
				'hide_color_scheme'       => (bool) ( $settings['profile']['hide_color_scheme'] ?? false ),
				'hide_keyboard_shortcuts' => (bool) ( $settings['profile']['hide_keyboard_shortcuts'] ?? false ),
				'hide_toolbar_toggle'     => (bool) ( $settings['profile']['hide_toolbar_toggle'] ?? false ),
				'hide_app_passwords'      => (bool) ( $settings['profile']['hide_app_passwords'] ?? false ),
				'hide_language'           => (bool) ( $settings['profile']['hide_language'] ?? false ),
				'hide_bio'                => (bool) ( $settings['profile']['hide_bio'] ?? false ),
				'hide_sessions'           => (bool) ( $settings['profile']['hide_sessions'] ?? false ),
				'hide_editor_options'     => (bool) ( $settings['profile']['hide_editor_options'] ?? false ),
			],
			'avatars'   => [
				'local' => (bool) ( $settings['avatars']['local'] ?? false ),
			],
		];

		return $this->save_module_settings( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'admin_bar' => [
				'hide_wp_logo'          => true,
				'hide_site_menu'        => false,
				'hide_command_palette'  => true,
				'hide_updates_counter'  => false,
				'hide_comments_counter' => true,
				'hide_new_content_menu' => true,
				'hide_help_button'      => true,
				'hide_screen_options'   => false,
				'remove_howdy'          => true,
				'hide_frontend'         => true,
			],
			'footer'    => [
				'left_text'       => '',
				'hide_right_text' => false,
				'right_text'      => '',
			],
			'profile'   => [
				'hide_color_scheme'       => true,
				'hide_keyboard_shortcuts' => true,
				'hide_toolbar_toggle'     => true,
				'hide_app_passwords'      => true,
				'hide_language'           => false,
				'hide_bio'                => false,
				'hide_sessions'           => false,
				'hide_editor_options'     => false,
			],
			'avatars'   => [
				'local' => true,
			],
		];
	}

	public static function get_uninstall_keys(): array {
		return [
			'options'   => [ 'lumia_module_white_label' ],
			'meta'      => [],
			// L'avatar local est stocké sur l'UTILISATEUR, pas sur un post :
			// déclaré en 'meta', il n'était jamais supprimé.
			'user_meta' => [ self::AVATAR_META ],
		];
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/white-label.css' ];
	}

	public function get_admin_js(): array {
		return [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		return [];
	}
}
