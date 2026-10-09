<?php
/**
 * Template des réglages du module Marque Blanche.
 *
 * Variables disponibles (via module-settings.php) :
 * @var string          $module_id       ID du module (white_label)
 * @var array           $module          Infos du module
 * @var ModuleInterface $instance        Instance du module
 * @var array           $module_settings Settings actuels
 * @var string          $tab             Onglet actif
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ab      = $module_settings['admin_bar'] ?? [];
$footer  = $module_settings['footer'] ?? [];
$profile = $module_settings['profile'] ?? [];
$avatars = $module_settings['avatars'] ?? [];
?>

<form id="lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="white_label" aria-label="<?php esc_attr_e( 'Sections de la marque blanche', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="adminbar"><?php esc_html_e( 'Barre d\'administration', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="avatars"><?php esc_html_e( 'Avatars', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="profile"><?php esc_html_e( 'Page de profil', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="footer"><?php esc_html_e( 'Pied de page', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="adminbar">

	<!-- ============================================================
		BARRE D'ADMINISTRATION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Barre d\'administration', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Masquez les éléments inutiles de la barre d\'administration WordPress.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Logo WordPress -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le logo WordPress', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime le logo WP et son menu déroulant en haut à gauche.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_wp_logo]" value="1"
							<?php checked( ! empty( $ab['hide_wp_logo'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Icône d'accueil / nom du site -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer l\'icône d\'accueil et le nom du site', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime l\'icône maison et le nom du site dans la barre admin.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_site_menu]" value="1"
							<?php checked( ! empty( $ab['hide_site_menu'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Palette de commandes -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer la palette de commandes', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Disponible depuis WordPress 6.7+. Supprime le bouton de palette de commandes.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_command_palette]" value="1"
							<?php checked( ! empty( $ab['hide_command_palette'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Compteur de mises à jour -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le compteur de mises à jour', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime l\'icône et le badge indiquant les mises à jour disponibles.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_updates_counter]" value="1"
							<?php checked( ! empty( $ab['hide_updates_counter'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Compteur de commentaires -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le compteur de commentaires', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime l\'icône et le badge indiquant les commentaires en attente.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_comments_counter]" value="1"
							<?php checked( ! empty( $ab['hide_comments_counter'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Menu Ajouter -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le menu « Ajouter »', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime le bouton « + Ajouter » permettant de créer rapidement du contenu.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_new_content_menu]" value="1"
							<?php checked( ! empty( $ab['hide_new_content_menu'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Bouton Aide -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le bouton Aide', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Masque l\'onglet « Aide » en haut à droite de chaque page admin.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_help_button]" value="1"
							<?php checked( ! empty( $ab['hide_help_button'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Options de l'écran -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le bouton Options de l\'écran', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Masque l\'onglet « Options de l\'écran » en haut à droite de chaque page admin.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_screen_options]" value="1"
							<?php checked( ! empty( $ab['hide_screen_options'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Supprimer Howdy -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Supprimer la salutation', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime « Howdy, » / « Bonjour, » devant le nom de l\'utilisateur connecté, quelle que soit la langue.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][remove_howdy]" value="1"
							<?php checked( ! empty( $ab['remove_howdy'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Masquer barre côté site -->
			<div class="lumia-option lumia-option--frontend-separator">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer la barre d\'administration sur le site', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Cache entièrement la barre d\'administration pour les visiteurs du site (front-end).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_frontend]" value="1"
							<?php checked( ! empty( $ab['hide_frontend'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="avatars" hidden>

	<!-- ============================================================
		AVATARS
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Avatars', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Hébergez les avatars localement plutôt que de dépendre de Gravatar.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Avatars locaux -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Activer les avatars locaux', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Ajoute un champ « Avatar local » sur chaque profil. L\'avatar téléversé est prioritaire ; à défaut, WordPress retombe sur Gravatar (comportement par défaut).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[avatars][local]" value="1"
							<?php checked( ! empty( $avatars['local'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="profile" hidden>

	<!-- ============================================================
		PAGE DE PROFIL
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Page de profil', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "Masquage visuel uniquement : chaque fonctionnalité reste active côté serveur. Ces cases épurent l'écran, elles ne retirent aucun droit.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Épurez la page de profil des utilisateurs (profile.php) en masquant les options superflues.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Jeu de couleurs de l'administration -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le jeu de couleurs de l\'administration', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime le sélecteur de thème de couleurs de l\'admin.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_color_scheme]" value="1"
							<?php checked( ! empty( $profile['hide_color_scheme'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Raccourcis clavier -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer les raccourcis clavier', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime l\'option d\'activation des raccourcis de modération des commentaires.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_keyboard_shortcuts]" value="1"
							<?php checked( ! empty( $profile['hide_keyboard_shortcuts'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Barre d'outils -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer l\'option Barre d\'outils', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime la case « Afficher la barre d\'outils lorsque vous visitez le site ».', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_toolbar_toggle]" value="1"
							<?php checked( ! empty( $profile['hide_toolbar_toggle'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Mots de passe d'application -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer les mots de passe d\'application', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Masque la section « Mots de passe d\'application » (la fonctionnalité reste active côté serveur).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_app_passwords]" value="1"
							<?php checked( ! empty( $profile['hide_app_passwords'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Sélecteur de langue -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer le sélecteur de langue', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime la ligne « Langue » (locale de l\'utilisateur).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_language]" value="1"
							<?php checked( ! empty( $profile['hide_language'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Informations biographiques -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer les informations biographiques', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime le champ « Informations biographiques » de la section « À propos de vous ».', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_bio]" value="1"
							<?php checked( ! empty( $profile['hide_bio'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Sessions -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer les sessions', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime le bouton « Se déconnecter partout ailleurs ».', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_sessions]" value="1"
							<?php checked( ! empty( $profile['hide_sessions'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Options de l'éditeur -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer les options de l\'éditeur', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime « Désactiver l\'éditeur visuel » et « Coloration syntaxique ».', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_editor_options]" value="1"
							<?php checked( ! empty( $profile['hide_editor_options'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="footer" hidden>

	<!-- ============================================================
		PIED DE PAGE
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Pied de page', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Personnalisez les textes affichés dans le pied de page de l\'administration WordPress.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Texte gauche -->
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-wl-left-text"><?php esc_html_e( 'Texte gauche du footer', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "Le HTML est filtré comme un contenu d'article : scripts et attributs d'événement sont retirés à l'enregistrement.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<textarea class="lumia-input" id="lumia-wl-left-text" name="lumia_module_settings[footer][left_text]" rows="2"><?php echo esc_textarea( $footer['left_text'] ?? '' ); ?></textarea>
				<p class="lumia-form__help"><?php esc_html_e( 'Supporte le HTML basique (liens, balises em/strong). Laissez vide pour garder la valeur WordPress par défaut.', 'lumia-tools' ); ?></p>
			</div>

			<!-- Masquer version WordPress -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Masquer la version WordPress (texte droit)', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Supprime l\'indication « Version X.X.X » en bas à droite de chaque page admin.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia-wl-hide-right" name="lumia_module_settings[footer][hide_right_text]" value="1"
							<?php checked( ! empty( $footer['hide_right_text'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Texte droit custom -->
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-wl-right-text"><?php esc_html_e( 'Texte droit du footer (si non masqué)', 'lumia-tools' ); ?></label>
				<input type="text" class="lumia-input" id="lumia-wl-right-text" name="lumia_module_settings[footer][right_text]" value="<?php echo esc_attr( $footer['right_text'] ?? '' ); ?>">
				<p class="lumia-form__help"><?php esc_html_e( 'Remplace « Version X.X.X ». Laissez vide pour garder la valeur WordPress par défaut.', 'lumia-tools' ); ?></p>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
<script>
(function() {
	var toggle = document.getElementById('lumia-wl-hide-right');
	var field  = document.getElementById('lumia-wl-right-text');
	if (!toggle || !field) return;
	function sync() { field.disabled = toggle.checked; field.closest('.lumia-form__group').style.opacity = toggle.checked ? '0.4' : '1'; }
	toggle.addEventListener('change', sync);
	sync();
})();
</script>
