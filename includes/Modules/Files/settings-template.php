<?php
/**
 * Template du module Fichiers — Gestionnaire de fichiers.
 *
 * Variables disponibles (injectées par module-settings.php) :
 * @var string          $module_id
 * @var array           $module
 * @var \Lumia\Tools\Modules\Files\Module $instance
 * @var array           $module_settings
 * @var string          $tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div
	class="lumia-files"
	id="lumia-files-manager"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>"
>

	<!-- TOOLBAR ----------------------------------------------------------- -->
	<div class="lumia-files__toolbar">
		<nav class="lumia-files__breadcrumb" id="lumia-files-breadcrumb" aria-label="<?php esc_attr_e( 'Navigation', 'lumia-tools' ); ?>">
			<button type="button" class="lumia-files__bc-item lumia-files__bc-home" data-path="" data-lumia-tip="<?php esc_attr_e( 'Racine WordPress', 'lumia-tools' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>
			</button>
		</nav>

		<div class="lumia-files__toolbar-right">
			<label class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-files__upload-label" data-lumia-tip="<?php esc_attr_e( 'Uploader des fichiers', 'lumia-tools' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
				<?php esc_html_e( 'Uploader', 'lumia-tools' ); ?>
				<input type="file" id="lumia-files-upload-input" multiple style="display:none" aria-hidden="true">
			</label>

			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-files-mkdir-btn">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
				<?php esc_html_e( 'Nouveau dossier', 'lumia-tools' ); ?>
			</button>
		</div>
	</div>

	<!-- SELECTION BAR ----------------------------------------------------- -->
	<div class="lumia-files__selection-bar" id="lumia-files-selection-bar" style="display:none">
		<span class="lumia-files__selection-count" id="lumia-files-selection-count"></span>
		<div class="lumia-files__selection-actions">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-files-zip-btn">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
				<?php esc_html_e( 'Zip & Télécharger', 'lumia-tools' ); ?>
			</button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-files-delete-btn">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3,6 5,6 21,6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
				<?php esc_html_e( 'Supprimer', 'lumia-tools' ); ?>
			</button>
		</div>
	</div>

	<!-- FILE TABLE --------------------------------------------------------- -->
	<div class="lumia-files__table-wrap">
		<table class="lumia-files__table">
			<thead>
				<tr>
					<th class="lumia-files__col-check">
						<input type="checkbox" id="lumia-files-check-all" data-lumia-tip="<?php esc_attr_e( 'Tout sélectionner', 'lumia-tools' ); ?>">
					</th>
					<th class="lumia-files__col-name"><?php esc_html_e( 'Nom', 'lumia-tools' ); ?></th>
					<th class="lumia-files__col-size"><?php esc_html_e( 'Taille', 'lumia-tools' ); ?></th>
					<th class="lumia-files__col-modified"><?php esc_html_e( 'Modifié', 'lumia-tools' ); ?></th>
					<th class="lumia-files__col-perms"><?php esc_html_e( 'Droits', 'lumia-tools' ); ?></th>
					<th class="lumia-files__col-owner"><?php esc_html_e( 'Propriétaire', 'lumia-tools' ); ?></th>
					<th class="lumia-files__col-actions"><?php esc_html_e( 'Actions', 'lumia-tools' ); ?></th>
				</tr>
			</thead>
			<tbody id="lumia-files-tbody">
				<tr class="lumia-files__row-empty">
					<td colspan="7"><?php esc_html_e( 'Chargement...', 'lumia-tools' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- DRAG & DROP OVERLAY ----------------------------------------------- -->
	<div class="lumia-files__drop-overlay" id="lumia-files-drop-overlay" style="display:none" aria-hidden="true">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
		<p><?php esc_html_e( 'Déposez les fichiers ici', 'lumia-tools' ); ?></p>
	</div>

</div><!-- .lumia-files -->

<!-- ÉDITEUR (overlay plein écran) ----------------------------------------- -->
<div class="lumia-files-editor" id="lumia-files-editor" style="display:none" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Éditeur de fichier', 'lumia-tools' ); ?>">
	<div class="lumia-files-editor__panel">
		<div class="lumia-files-editor__header">
			<span class="lumia-files-editor__filename" id="lumia-editor-filename"></span>
			<div class="lumia-files-editor__header-actions">
				<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-editor-close">
					<?php esc_html_e( 'Fermer', 'lumia-tools' ); ?>
				</button>
				<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-editor-save" disabled>
					<?php esc_html_e( 'Enregistrer', 'lumia-tools' ); ?>
				</button>
			</div>
		</div>
		<div class="lumia-files-editor__body">
			<textarea id="lumia-editor-textarea" spellcheck="false"></textarea>
		</div>
	</div>
</div>

<!-- MODAL RENOMMER --------------------------------------------------------- -->
<div class="lumia-modal-overlay" id="lumia-modal-rename" role="dialog" aria-modal="true" aria-labelledby="lumia-rename-title">
	<div class="lumia-modal">
		<div class="lumia-modal__header">
			<h3 id="lumia-rename-title" class="lumia-modal__title"><?php esc_html_e( 'Renommer', 'lumia-tools' ); ?></h3>
		</div>
		<div class="lumia-modal__body">
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-rename-input"><?php esc_html_e( 'Nouveau nom', 'lumia-tools' ); ?></label>
				<input type="text" class="lumia-input" id="lumia-rename-input" autocomplete="off">
			</div>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Annuler', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-rename-confirm"><?php esc_html_e( 'Renommer', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>

<!-- MODAL DÉPLACER --------------------------------------------------------- -->
<div class="lumia-modal-overlay" id="lumia-modal-move" role="dialog" aria-modal="true" aria-labelledby="lumia-move-title">
	<div class="lumia-modal">
		<div class="lumia-modal__header">
			<h3 id="lumia-move-title" class="lumia-modal__title"><?php esc_html_e( 'Déplacer vers', 'lumia-tools' ); ?></h3>
		</div>
		<div class="lumia-modal__body">
			<p class="lumia-modal__message"><?php esc_html_e( 'Chemin relatif du dossier de destination (ex : wp-content/uploads).', 'lumia-tools' ); ?></p>
			<div class="lumia-form__group" style="margin-top:10px">
				<input type="text" class="lumia-input" id="lumia-move-input" autocomplete="off" placeholder="wp-content/uploads">
			</div>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Annuler', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-move-confirm"><?php esc_html_e( 'Déplacer', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>

<!-- MODAL NOUVEAU DOSSIER -------------------------------------------------- -->
<div class="lumia-modal-overlay" id="lumia-modal-mkdir" role="dialog" aria-modal="true" aria-labelledby="lumia-mkdir-title">
	<div class="lumia-modal">
		<div class="lumia-modal__header">
			<h3 id="lumia-mkdir-title" class="lumia-modal__title"><?php esc_html_e( 'Nouveau dossier', 'lumia-tools' ); ?></h3>
		</div>
		<div class="lumia-modal__body">
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-mkdir-input"><?php esc_html_e( 'Nom du dossier', 'lumia-tools' ); ?></label>
				<input type="text" class="lumia-input" id="lumia-mkdir-input" autocomplete="off">
			</div>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Annuler', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-mkdir-confirm"><?php esc_html_e( 'Créer', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
