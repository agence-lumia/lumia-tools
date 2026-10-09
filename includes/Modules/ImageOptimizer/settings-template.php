<?php
/**
 * Template des réglages du module Image Optimizer.
 *
 * Variables disponibles : $instance, $tab, $module_id, $module
 */

defined( 'ABSPATH' ) || exit;

$module_settings = $instance->get_settings();
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="image_optimizer" aria-label="<?php esc_attr_e( 'Sections de l\'Image Optimizer', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Réglages', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="svg"><?php esc_html_e( 'SVG', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="bulk"><?php esc_html_e( 'Optimisation en masse', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="settings">

	<!-- Comportement -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Comportement', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Configurez comment les images sont traitées lors de l\'upload.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_optimize_on_upload" class="lumia-option__label"><?php echo esc_html__( 'Optimiser à l\'upload', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Redimensionne, compresse et convertit automatiquement les images.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_optimize_on_upload"
								name="lumia_module_settings[optimize_on_upload]"
								value="1"
								<?php checked( $module_settings['optimize_on_upload'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_format_mode" class="lumia-option__label"><?php echo esc_html__( 'Format de sortie', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "« Auto » prend AVIF si le serveur sait l'encoder, sinon WebP. Un format choisi explicitement mais non supporté ne convertit rien du tout : il n'y a pas de repli.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Format de conversion automatique des images.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<select id="lumia_format_mode"
							name="lumia_module_settings[format_mode]"
							class="lumia-select lumia-select--sm">
						<option value="auto" <?php selected( $module_settings['format_mode'], 'auto' ); ?>>
							<?php echo esc_html__( 'Auto', 'lumia-tools' ); ?>
						</option>
						<option value="avif" <?php selected( $module_settings['format_mode'], 'avif' ); ?>>
							<?php echo esc_html__( 'AVIF', 'lumia-tools' ); ?>
						</option>
						<option value="webp" <?php selected( $module_settings['format_mode'], 'webp' ); ?>>
							<?php echo esc_html__( 'WebP', 'lumia-tools' ); ?>
						</option>
					</select>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Qualité et dimensions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Qualité et dimensions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Ajustez la qualité de compression et les dimensions maximales.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__group">
				<label for="lumia_quality" class="lumia-form__label"><?php echo esc_html__( 'Qualité de compression (1-100)', 'lumia-tools' ); ?></label>
				<input type="number"
						id="lumia_quality"
						name="lumia_module_settings[quality]"
						class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $module_settings['quality'] ); ?>"
						min="1"
						max="100">
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_max_width" class="lumia-form__label"><?php echo esc_html__( 'Largeur max (px)', 'lumia-tools' ); ?></label>
					<input type="number"
							id="lumia_max_width"
							name="lumia_module_settings[max_width]"
							class="lumia-input"
							value="<?php echo esc_attr( $module_settings['max_width'] ); ?>"
							min="100">
				</div>

				<div class="lumia-form__group">
					<label for="lumia_max_height" class="lumia-form__label"><?php echo esc_html__( 'Hauteur max (px)', 'lumia-tools' ); ?></label>
					<input type="number"
							id="lumia_max_height"
							name="lumia_module_settings[max_height]"
							class="lumia-input"
							value="<?php echo esc_attr( $module_settings['max_height'] ); ?>"
							min="100">
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Options avancées -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Options avancées', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Options supplémentaires pour le traitement des images.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_strip_exif" class="lumia-option__label"><?php echo esc_html__( 'Supprimer les métadonnées EXIF', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "Irréversible, et le nettoyage emporte tout le bloc : mention de copyright et profil colorimétrique compris. À laisser actif sauf si le site publie des photos d'auteur.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Retire les données GPS, appareil photo, etc.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_strip_exif"
								name="lumia_module_settings[strip_exif]"
								value="1"
								<?php checked( $module_settings['strip_exif'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_generate_alt" class="lumia-option__label"><?php echo esc_html__( 'Générer le texte alternatif', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "Ne s'applique qu'aux nouveaux téléversements et n'écrase jamais un texte alternatif déjà saisi. Le nom du fichier vaut ce qu'il vaut : à relire pour les images porteuses de sens.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Crée automatiquement le alt text depuis le nom du fichier.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_generate_alt"
								name="lumia_module_settings[generate_alt]"
								value="1"
								<?php checked( $module_settings['generate_alt'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_keep_original" class="lumia-option__label"><?php echo esc_html__( 'Conserver l\'original', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( "Double l'espace disque occupé par la médiathèque. À garder tant que la conversion n'a pas été validée sur le site, à couper ensuite.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( "Garde une copie intacte du fichier source, qui permet de restaurer ou de ré-optimiser l'image sans perte depuis sa fiche.", 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_keep_original"
								name="lumia_module_settings[keep_original]"
								value="1"
								<?php checked( $module_settings['keep_original'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="svg" hidden>

	<!-- Téléchargements SVG -->
	<?php $svg_enabled = ! empty( $module_settings['svg_upload'] ); ?>
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Téléchargements SVG', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Les fichiers SVG sont du XML et peuvent contenir du code malveillant. Une fois activés, chaque SVG téléversé est automatiquement assaini (suppression du JavaScript, des gestionnaires d\'événements et des références externes).', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_svg_upload" class="lumia-option__label"><?php echo esc_html__( 'Autoriser l\'upload de SVG', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Active l\'upload de fichiers .svg assainis dans la médiathèque.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_svg_upload"
								name="lumia_module_settings[svg_upload]"
								value="1"
								data-svg-master
								<?php checked( $svg_enabled, true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<?php $svg_roles = (array) ( $module_settings['svg_roles'] ?? [] ); ?>
			<div class="lumia-svg-roles<?php echo $svg_enabled ? '' : ' is-disabled'; ?>" id="lumia-svg-roles">
				<div class="lumia-svg-roles__head">
					<span class="lumia-svg-roles__title"><?php echo esc_html__( 'Rôles autorisés', 'lumia-tools' ); ?></span>
					<span class="lumia-svg-roles__hint"><?php echo esc_html__( 'Seuls ces rôles pourront téléverser des SVG.', 'lumia-tools' ); ?></span>
				</div>
				<div class="lumia-svg-roles__grid">
					<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
						<label class="lumia-svg-role">
							<span class="lumia-toggle">
								<input type="checkbox"
										name="lumia_module_settings[svg_roles][]"
										value="<?php echo esc_attr( $role_slug ); ?>"
										<?php checked( in_array( $role_slug, $svg_roles, true ), true ); ?>>
								<span class="lumia-toggle__slider"></span>
							</span>
							<span class="lumia-svg-role__name"><?php echo esc_html( translate_user_role( $role_name ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="bulk" hidden>

	<!-- Optimisation en masse -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Optimisation en masse', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Optimise les images déjà présentes dans la médiathèque.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-bulk" id="lumia-bulk">

				<!-- État initial : lancer un scan avant d'afficher des chiffres -->
				<div class="lumia-bulk__scan" id="lumia-bulk-scan-intro">
					<p class="lumia-bulk__scan-hint">
						<?php echo esc_html__( 'Analysez la médiathèque pour connaître le nombre d\'images restant à optimiser.', 'lumia-tools' ); ?>
					</p>
					<button type="button" id="lumia-bulk-scan" class="lumia-btn lumia-btn--secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
						<?php echo esc_html__( 'Scanner la médiathèque', 'lumia-tools' ); ?>
					</button>
				</div>

				<!-- Résultat du scan (révélé par le JS) -->
				<div class="lumia-bulk__result" id="lumia-bulk-result" style="display: none;">
					<div class="lumia-bulk__stats">
						<div class="lumia-bulk__stat">
							<span class="lumia-bulk__stat-value" id="lumia-bulk-remaining">0</span>
							<span class="lumia-bulk__stat-label"><?php echo esc_html__( 'images à optimiser', 'lumia-tools' ); ?></span>
						</div>
						<div class="lumia-bulk__stat" id="lumia-bulk-potential-tile" style="display: none;">
							<span class="lumia-bulk__stat-value" id="lumia-bulk-potential">—</span>
							<span class="lumia-bulk__stat-label"><?php echo esc_html__( 'gains potentiels estimés', 'lumia-tools' ); ?></span>
						</div>
					</div>
					<div class="lumia-bulk__action">
						<button type="button" id="lumia-bulk-start" class="lumia-btn lumia-btn--primary" disabled>
							<?php echo esc_html__( 'Lancer l\'optimisation', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<!-- Progression -->
				<div class="lumia-bulk__progress lumia-bulk-status__progress" style="display: none;">
					<div class="lumia-progress">
						<div class="lumia-progress__bar" style="width: 0%"></div>
					</div>
					<span class="lumia-bulk-status__message"></span>
				</div>
			</div>
		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->
</form>
