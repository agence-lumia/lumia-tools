<?php
/**
 * Template des réglages du module Créateur de menu.
 * Rend l'éditeur 3 colonnes intégré dans l'admin du plugin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$svg = [
	'search'    => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
	'plus'      => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
	'grip'      => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/></svg>',
	'eye'       => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>',
	'eye-off'   => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>',
	'trash'     => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
	'copy'      => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>',
	'x'         => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
	'chevron-r' => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
	'chevron-d' => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
	'chevron-u' => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>',
	'chevron-l' => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
	'lock'      => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
	'download'  => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>',
	'upload'    => '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/></svg>',
	'warn'      => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
	'info'      => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
	'menu-ph'   => '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/></svg>',
];
?>
<div class="lumia-mc-editor" id="lumia-mc-editor">

	<!-- ============================================================
		COLONNE GAUCHE — liste des menus
		============================================================ -->
	<aside class="lumia-wl-ep__profiles-col">

		<div class="lumia-wl-ep__profiles-header">
			<span class="lumia-wl-ep__profiles-title"><?php esc_html_e( 'Menus', 'lumia-tools' ); ?></span>
			<!-- Import / export de la totalité des menus : actions de gestion,
				donc en icône seule dans l'en-tête, hors du flux de navigation. -->
			<span class="lumia-mc-hdr-actions">
				<button type="button" class="lumia-mc-hdr-btn" id="lumia-mc-import-btn"
					data-lumia-tip="<?php esc_attr_e( 'Importer un ou plusieurs menus depuis un .json', 'lumia-tools' ); ?>"
					aria-label="<?php esc_attr_e( 'Importer des menus', 'lumia-tools' ); ?>">
					<?php echo $svg['upload']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<button type="button" class="lumia-mc-hdr-btn" id="lumia-mc-export-all-btn"
					data-lumia-tip="<?php esc_attr_e( 'Exporter tous les menus dans un .json', 'lumia-tools' ); ?>"
					aria-label="<?php esc_attr_e( 'Exporter tous les menus', 'lumia-tools' ); ?>">
					<?php echo $svg['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<input type="file" id="lumia-mc-import-file" accept="application/json,.json" style="display:none">
			</span>
		</div>

		<div class="lumia-wl-ep__profiles-search-wrap">
			<?php echo $svg['search']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="search" class="lumia-wl-ep__profiles-search" id="lumia-wl-ep-search" placeholder="<?php esc_attr_e( 'Rechercher…', 'lumia-tools' ); ?>">
		</div>

		<div class="lumia-wl-ep__profiles-tabs">
			<button type="button" class="lumia-wl-ep__profiles-tab is-active" data-filter="all"><?php esc_html_e( 'Tous', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-wl-ep__profiles-tab" data-filter="active"><?php esc_html_e( 'Actifs', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-wl-ep__profiles-tab" data-filter="draft"><?php esc_html_e( 'Brouillons', 'lumia-tools' ); ?></button>
		</div>

		<!-- La création suit immédiatement le dernier menu (et non le bas de la
			colonne) : le bouton occupe la place où le nouveau menu apparaîtra. -->
		<div class="lumia-wl-ep__profiles-scroll">
			<div class="lumia-wl-ep__profiles-list" id="lumia-wl-ep-profiles-list"></div>
			<button type="button" class="lumia-mc-add-placeholder" id="lumia-mc-new-btn">
				<?php echo $svg['plus']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Nouveau menu', 'lumia-tools' ); ?></span>
			</button>
		</div>

	</aside>

	<!-- ============================================================
		COLONNE CENTRALE — placeholder + arbre
		============================================================ -->
	<div class="lumia-wl-ep__tree-col" id="lumia-mc-tree-col">

		<div class="lumia-wl-ep__tree-actions" id="lumia-mc-tree-actions" style="display:none">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-wl-add-sep">
				+ <?php esc_html_e( 'Séparateur', 'lumia-tools' ); ?>
			</button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-wl-add-link">
				+ <?php esc_html_e( 'Lien personnalisé', 'lumia-tools' ); ?>
			</button>
		</div>

		<!-- Bandeau des entrées orphelines : rempli par le JS quand le profil
			référence des slugs absents du menu WP courant. -->
		<div class="lumia-mc-stale-bar" id="lumia-mc-stale-bar" style="display:none"></div>

		<div class="lumia-mc-placeholder" id="lumia-mc-placeholder">
			<span class="lumia-mc-placeholder__icon"><?php echo $svg['menu-ph']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<h3><?php esc_html_e( 'Éditeur de menu', 'lumia-tools' ); ?></h3>
			<p><?php esc_html_e( 'Sélectionnez un menu dans la liste ou créez-en un nouveau pour modifier sa structure et ses paramètres.', 'lumia-tools' ); ?></p>
		</div>

		<div class="lumia-wl-ep__tree" id="lumia-wl-tree" style="display:none"></div>

	</div>

	<!-- ============================================================
		COLONNE DROITE — paramètres (direct switch, no tabs)
		============================================================ -->
	<div class="lumia-wl-ep__settings-col" id="lumia-wl-settings-col" style="display:none">

		<!-- En-tête du panel (titre dynamique + bouton retour) -->
		<div class="lumia-mc-panel-header">
			<button type="button" class="lumia-mc-back-btn" id="lumia-mc-back-btn" style="display:none" data-lumia-tip="<?php esc_attr_e( 'Retour aux paramètres du menu', 'lumia-tools' ); ?>">
				<?php echo $svg['chevron-l']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Menu', 'lumia-tools' ); ?></span>
			</button>
			<span class="lumia-mc-panel-title" id="lumia-mc-panel-title"><?php esc_html_e( 'Paramètres du menu', 'lumia-tools' ); ?></span>
			<!-- Export du menu ouvert : action de gestion du menu, donc dans son
				en-tête et masquée sur la vue d'un élément. -->
			<button type="button" class="lumia-mc-hdr-btn lumia-mc-panel-header__action" id="lumia-mc-export-btn"
				data-lumia-tip="<?php esc_attr_e( 'Exporter ce menu en .json', 'lumia-tools' ); ?>"
				aria-label="<?php esc_attr_e( 'Exporter ce menu', 'lumia-tools' ); ?>">
				<?php echo $svg['download']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<!-- Panel profil -->
		<div id="lumia-wl-profile-settings" class="lumia-wl-settings-panel">

			<div class="lumia-wl-settings-row">
				<div class="lumia-wl-settings-row__label">
					<span><?php esc_html_e( 'Nom du menu', 'lumia-tools' ); ?></span>
					<p class="lumia-form__help"><?php esc_html_e( 'Identifiant interne du profil.', 'lumia-tools' ); ?></p>
				</div>
				<input type="text" class="lumia-input" id="lumia-wl-profile-name"
					placeholder="<?php esc_attr_e( 'Nom du menu…', 'lumia-tools' ); ?>">
			</div>

			<div class="lumia-wl-settings-row">
				<div class="lumia-wl-settings-row__label">
					<span class="lumia-mc-status-label-wrap">
						<?php esc_html_e( 'Statut', 'lumia-tools' ); ?>
						<span class="lumia-mc-status-badge" id="lumia-mc-status-badge"></span>
					</span>
					<p class="lumia-form__help"><?php esc_html_e( 'Activez ce menu pour qu\'il s\'applique aux utilisateurs ciblés.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-mc-status-group">
					<div class="lumia-wl-seg">
						<button type="button" class="lumia-wl-seg__btn" data-value="draft"  id="lumia-wl-status-draft"><?php esc_html_e( 'Brouillon', 'lumia-tools' ); ?></button>
						<button type="button" class="lumia-wl-seg__btn" data-value="active" id="lumia-wl-status-active"><?php esc_html_e( 'Actif', 'lumia-tools' ); ?></button>
					</div>
				</div>
			</div>

			<div class="lumia-wl-settings-row lumia-wl-settings-row--inline">
				<div class="lumia-wl-settings-row__label">
					<span><?php esc_html_e( 'Appliquer à tous les utilisateurs', 'lumia-tools' ); ?></span>
					<p class="lumia-form__help"><?php esc_html_e( 'Ce menu sera appliqué à tous, sans restriction.', 'lumia-tools' ); ?></p>
				</div>
				<label class="lumia-toggle">
					<input type="checkbox" id="lumia-wl-apply-all">
					<span class="lumia-toggle__slider"></span>
				</label>
			</div>

			<div class="lumia-wl-settings-row lumia-wl-settings-row--col" id="lumia-wl-targeting-rows">
				<div class="lumia-wl-settings-row__label">
					<span><?php esc_html_e( 'Inclure — rôles ou utilisateurs', 'lumia-tools' ); ?></span>
					<p class="lumia-form__help"><?php esc_html_e( 'Ce menu s\'applique à ces rôles / utilisateurs.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-wl-multiselect" id="lumia-wl-include-select"></div>
			</div>

			<div class="lumia-wl-settings-row lumia-wl-settings-row--col" id="lumia-wl-targeting-rows-ex">
				<div class="lumia-wl-settings-row__label">
					<span><?php esc_html_e( 'Exclure — rôles ou utilisateurs', 'lumia-tools' ); ?></span>
					<p class="lumia-form__help"><?php esc_html_e( 'Ces rôles / utilisateurs ne verront pas ce menu.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-wl-multiselect" id="lumia-wl-exclude-select"></div>
			</div>

		</div>

		<!-- Panel item -->
		<div id="lumia-wl-item-settings" class="lumia-wl-settings-panel" style="display:none">
			<div id="lumia-wl-item-fields"></div>
		</div>

		<!-- Pied de page ancré en bas : Enregistrer + Réinitialiser -->
		<div class="lumia-mc-panel-footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-mc-reset-menu-btn">
				<?php esc_html_e( 'Réinitialiser', 'lumia-tools' ); ?>
			</button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-mc-save-panel-btn" disabled
				data-lumia-tip="<?php esc_attr_e( 'Enregistrer (Ctrl/Cmd+S) — Ctrl+Z annule, Ctrl+Y rétablit', 'lumia-tools' ); ?>">
				<?php esc_html_e( 'Enregistrer', 'lumia-tools' ); ?>
			</button>
		</div>

	</div>
</div><!-- .lumia-mc-editor -->

<script>
window.lumiaLucide = {
	grip:        <?php echo wp_json_encode( $svg['grip'] ); ?>,
	eye:         <?php echo wp_json_encode( $svg['eye'] ); ?>,
	eyeOff:      <?php echo wp_json_encode( $svg['eye-off'] ); ?>,
	trash:       <?php echo wp_json_encode( $svg['trash'] ); ?>,
	copy:        <?php echo wp_json_encode( $svg['copy'] ); ?>,
	x:           <?php echo wp_json_encode( $svg['x'] ); ?>,
	chevronR:    <?php echo wp_json_encode( $svg['chevron-r'] ); ?>,
	chevronD:    <?php echo wp_json_encode( $svg['chevron-d'] ); ?>,
	chevronU:    <?php echo wp_json_encode( $svg['chevron-u'] ); ?>,
	lock:        <?php echo wp_json_encode( $svg['lock'] ); ?>,
	warn:        <?php echo wp_json_encode( $svg['warn'] ); ?>,
	info:        <?php echo wp_json_encode( $svg['info'] ); ?>,
};
</script>
