<?php
/**
 * Template des réglages du module Connexion.
 *
 * Variables disponibles (via module-settings.php) :
 * @var string          $module_id       ID du module (login)
 * @var array           $module          Infos du module
 * @var ModuleInterface $instance        Instance du module
 * @var array           $module_settings Settings actuels
 * @var string          $tab             Onglet actif
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout   = $module_settings['layout'] ?? [];
$branding = $module_settings['branding'] ?? [];
$form     = $module_settings['form'] ?? [];

// Images courantes
$panel_img_id  = absint( $layout['panel_image_id'] ?? 0 );
$panel_img_url = $panel_img_id ? wp_get_attachment_image_url( $panel_img_id, 'medium' ) : '';

$logo_id  = absint( $branding['logo_id'] ?? 0 );
$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
?>

<form id="lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="login" aria-label="<?php esc_attr_e( 'Sections du module Connexion', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="appearance"><?php esc_html_e( 'Apparence', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="options"><?php esc_html_e( 'Options', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="login" data-lumia-tab-panel="appearance">

	<!-- ============================================================
		LAYOUT — PANNEAU IMAGE
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Layout', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Panneau visuel affiché à droite du formulaire de connexion.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Image du panneau -->
			<div class="lumia-option lumia-option--column">
				<div class="lumia-option__content">
					<span class="lumia-option__label"><?php esc_html_e( 'Image du panneau', 'lumia-tools' ); ?></span>
					<p class="lumia-option__desc"><?php esc_html_e( 'Image de fond du panneau droit. Si vide, la couleur de fond est utilisée.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control lumia-option__control--full">
					<div class="lumia-media-picker" data-picker="panel_image">
						<input type="hidden" name="lumia_module_settings[layout][panel_image_id]" id="lumia_panel_image_id" value="<?php echo esc_attr( $panel_img_id ); ?>">
						<div class="lumia-media-preview <?php echo $panel_img_url ? 'has-image' : ''; ?>">
							<?php if ( $panel_img_url ) : ?>
								<img src="<?php echo esc_url( $panel_img_url ); ?>" alt="">
							<?php endif; ?>
						</div>
						<div class="lumia-media-actions">
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-select" data-title="<?php esc_attr_e( 'Choisir une image', 'lumia-tools' ); ?>" data-button="<?php esc_attr_e( 'Utiliser cette image', 'lumia-tools' ); ?>">
								<?php esc_html_e( 'Choisir une image', 'lumia-tools' ); ?>
							</button>
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-remove <?php echo ! $panel_img_url ? 'is-hidden' : ''; ?>">
								<?php esc_html_e( 'Supprimer', 'lumia-tools' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Couleur de fond panneau -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_panel_bg_color" class="lumia-option__label">
						<?php esc_html_e( 'Couleur de fond du panneau', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Utilisée comme fallback si aucune image n\'est définie.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input
							type="color"
							id="lumia_panel_bg_color"
							name="lumia_module_settings[layout][panel_bg_color]"
							value="<?php echo esc_attr( $layout['panel_bg_color'] ?? '#eaeaea' ); ?>"
						>
						<span class="lumia-color-field__value"><?php echo esc_html( $layout['panel_bg_color'] ?? '#eaeaea' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#eaeaea" data-lumia-tip="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Réinitialiser la couleur', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		BRANDING — LOGO
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Branding', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Remplacez le logo WordPress par le vôtre.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Logo custom -->
			<div class="lumia-option lumia-option--column">
				<div class="lumia-option__content">
					<span class="lumia-option__label"><?php esc_html_e( 'Logo personnalisé', 'lumia-tools' ); ?></span>
					<p class="lumia-option__desc"><?php esc_html_e( 'Remplace le logo WordPress par défaut. Format recommandé : PNG transparent ou SVG.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control lumia-option__control--full">
					<div class="lumia-media-picker" data-picker="logo">
						<input type="hidden" name="lumia_module_settings[branding][logo_id]" id="lumia_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
						<div class="lumia-media-preview lumia-media-preview--logo <?php echo $logo_url ? 'has-image' : ''; ?>">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
							<?php endif; ?>
						</div>
						<div class="lumia-media-actions">
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-select" data-title="<?php esc_attr_e( 'Choisir un logo', 'lumia-tools' ); ?>" data-button="<?php esc_attr_e( 'Utiliser ce logo', 'lumia-tools' ); ?>">
								<?php esc_html_e( 'Choisir un logo', 'lumia-tools' ); ?>
							</button>
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-remove <?php echo ! $logo_url ? 'is-hidden' : ''; ?>">
								<?php esc_html_e( 'Supprimer', 'lumia-tools' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Largeur du logo -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_logo_width" class="lumia-option__label">
						<?php esc_html_e( 'Largeur du logo', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Largeur maximale du logo en pixels (entre 40 et 600).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-input-unit">
						<input
							type="number"
							id="lumia_logo_width"
							name="lumia_module_settings[branding][logo_width]"
							value="<?php echo esc_attr( $branding['logo_width'] ?? 150 ); ?>"
							min="40"
							max="600"
							step="1"
							class="lumia-input lumia-input--sm"
						>
						<span class="lumia-input-unit__label">px</span>
					</div>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		COULEURS
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Couleurs', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Personnalisez les couleurs du formulaire de connexion.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Couleur de fond -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_bg_color" class="lumia-option__label"><?php esc_html_e( 'Fond de la page', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Couleur de fond de la zone formulaire.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_bg_color" name="lumia_module_settings[form][bg_color]" value="<?php echo esc_attr( $form['bg_color'] ?? '#f7f7f7' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['bg_color'] ?? '#f7f7f7' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#f7f7f7" data-lumia-tip="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Réinitialiser la couleur', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Couleur bouton -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_btn_bg_color" class="lumia-option__label"><?php esc_html_e( 'Fond du bouton', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_btn_bg_color" name="lumia_module_settings[form][btn_bg_color]" value="<?php echo esc_attr( $form['btn_bg_color'] ?? '#615FFF' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['btn_bg_color'] ?? '#615FFF' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#615FFF" data-lumia-tip="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Réinitialiser la couleur', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Couleur texte bouton -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_btn_text_color" class="lumia-option__label"><?php esc_html_e( 'Texte du bouton', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_btn_text_color" name="lumia_module_settings[form][btn_text_color]" value="<?php echo esc_attr( $form['btn_text_color'] ?? '#ffffff' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['btn_text_color'] ?? '#ffffff' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#ffffff" data-lumia-tip="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Réinitialiser la couleur', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Couleur liens -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_link_color" class="lumia-option__label"><?php esc_html_e( 'Couleur des liens', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_link_color" name="lumia_module_settings[form][link_color]" value="<?php echo esc_attr( $form['link_color'] ?? '#615FFF' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['link_color'] ?? '#615FFF' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#615FFF" data-lumia-tip="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Réinitialiser la couleur', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="login" data-lumia-tab-panel="options" hidden>

	<!-- ============================================================
		OPTIONS DIVERSES
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Options', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Éléments à masquer sur la page de connexion.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Masquer sélecteur de langue -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_language" class="lumia-option__label">
						<?php esc_html_e( 'Masquer le sélecteur de langue', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Cache le menu déroulant de sélection de langue en bas du formulaire.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_language"
							name="lumia_module_settings[form][hide_language_switcher]"
							value="1"
							<?php checked( ! empty( $form['hide_language_switcher'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Masquer mot de passe oublié -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_lost_password" class="lumia-option__label">
						<?php esc_html_e( 'Masquer le lien « Mot de passe oublié »', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Cache le lien de récupération de mot de passe sous le formulaire.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_lost_password"
							name="lumia_module_settings[form][hide_lost_password]"
							value="1"
							<?php checked( ! empty( $form['hide_lost_password'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Masquer lien retour au site -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_back_to_blog" class="lumia-option__label">
						<?php esc_html_e( 'Masquer le lien « Aller sur le site »', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Cache le lien de retour vers l\'accueil du site en bas du formulaire.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_back_to_blog"
							name="lumia_module_settings[form][hide_back_to_blog]"
							value="1"
							<?php checked( ! empty( $form['hide_back_to_blog'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Masquer le lien Politique de confidentialité -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_privacy_policy" class="lumia-option__label">
						<?php esc_html_e( 'Masquer le lien « Politique de confidentialité »', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Cache le lien affiché par WordPress quand une page de politique de confidentialité est définie.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_privacy_policy"
							name="lumia_module_settings[form][hide_privacy_policy]"
							value="1"
							<?php checked( ! empty( $form['hide_privacy_policy'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
