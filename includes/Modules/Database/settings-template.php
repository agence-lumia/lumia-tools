<?php if ( ! defined( 'ABSPATH' ) ) {
	exit;} ?>

<div
	class="lumia-db"
	id="lumia-db-manager"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>"
>

	<!-- LAYOUT DEUX COLONNES : sidebar tables + zone principale -->
	<div class="lumia-db__layout">

		<!-- SIDEBAR TABLES -->
		<div class="lumia-db__sidebar">
			<div class="lumia-db__sidebar-header">
				<div class="lumia-search lumia-search--sm">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
					<input type="search" class="lumia-search__input" id="lumia-db-search-table"
						placeholder="<?php esc_attr_e( 'Rechercher une table…', 'lumia-tools' ); ?>">
				</div>
			</div>
			<button type="button" class="lumia-db__table-item lumia-db__cleanup-link" id="lumia-db-cleanup-link">
				<span class="lumia-db__table-item-name">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 22-1-4"/><path d="M19 14a1 1 0 0 0 1-1v-1a2 2 0 0 0-2-2h-3a1 1 0 0 1-1-1V4a2 2 0 0 0-4 0v5a1 1 0 0 1-1 1H6a2 2 0 0 0-2 2v1a1 1 0 0 0 1 1"/><path d="M19 14H5l-1.973 6.767A1 1 0 0 0 4 22h16a1 1 0 0 0 .973-1.233z"/><path d="m8 22 1-4"/></svg>
					<?php esc_html_e( 'Nettoyage', 'lumia-tools' ); ?>
				</span>
			</button>
			<div class="lumia-db__table-list" id="lumia-db-table-list">
				<div class="lumia-db__loading"><?php esc_html_e( 'Chargement…', 'lumia-tools' ); ?></div>
			</div>
		</div>

		<!-- ZONE PRINCIPALE -->
		<div class="lumia-db__main" id="lumia-db-main">

			<!-- État vide (aucune table sélectionnée) -->
			<div class="lumia-db__empty" id="lumia-db-empty">
				<p><?php esc_html_e( 'Sélectionnez une table dans la barre latérale.', 'lumia-tools' ); ?></p>
			</div>

			<!-- Vue Nettoyage (générée en JS) -->
			<div id="lumia-db-cleanup-view" class="lumia-db__view lumia-db__cleanup" style="display:none"></div>

			<!-- Vue table (masquée jusqu'à sélection) -->
			<div id="lumia-db-table-view" class="lumia-db__view" style="display:none">

				<!-- Header de la table -->
				<div class="lumia-db__table-header">
					<div class="lumia-db__table-title">
						<h2 class="lumia-db__table-name" id="lumia-db-table-name"></h2>
						<span class="lumia-db__table-meta" id="lumia-db-table-meta"></span>
					</div>
					<div class="lumia-db__table-actions">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-db-add-row-btn">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
							<?php esc_html_e( 'Ajouter une ligne', 'lumia-tools' ); ?>
						</button>

						<!-- Menu d'actions (regroupe les opérations dangereuses) -->
						<div class="lumia-db__menu-wrap" id="lumia-db-actions-menu">
							<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-db__menu-btn" id="lumia-db-actions-btn" aria-haspopup="true" aria-expanded="false">
								<?php esc_html_e( 'Outils', 'lumia-tools' ); ?>
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
							</button>
							<div class="lumia-db__menu" id="lumia-db-actions-dropdown" role="menu" hidden>
								<button type="button" class="lumia-db__menu-item" role="menuitem" data-action="query">
									<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
									<?php esc_html_e( 'Éditeur de requête SQL', 'lumia-tools' ); ?>
								</button>
								<button type="button" class="lumia-db__menu-item" role="menuitem" data-action="export">
									<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7,10 12,15 17,10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
									<?php esc_html_e( 'Exporter en .sql', 'lumia-tools' ); ?>
								</button>

								<div class="lumia-db__menu-sep"></div>
								<div class="lumia-db__menu-label"><?php esc_html_e( 'Zone dangereuse', 'lumia-tools' ); ?></div>

								<button type="button" class="lumia-db__menu-item lumia-db__menu-item--danger" role="menuitem" data-action="truncate">
									<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
									<?php esc_html_e( 'Vider la table', 'lumia-tools' ); ?>
								</button>
								<button type="button" class="lumia-db__menu-item lumia-db__menu-item--danger" role="menuitem" data-action="drop">
									<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
									<?php esc_html_e( 'Supprimer la table', 'lumia-tools' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Onglets Données / Structure / Requête SQL : composant lumia-tabs.
					Le chargement de chaque onglet est piloté par database.js
					(événement lumia:tab). -->
				<div class="lumia-tabs" role="tablist" data-lumia-tabs="database" aria-label="<?php esc_attr_e( 'Vues de la table', 'lumia-tools' ); ?>">
					<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="data"><?php esc_html_e( 'Données', 'lumia-tools' ); ?></button>
					<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="structure"><?php esc_html_e( 'Structure', 'lumia-tools' ); ?></button>
					<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="query"><?php esc_html_e( 'Requête SQL', 'lumia-tools' ); ?></button>
				</div>

				<!-- Contenu des onglets (généré en JS) -->
				<div id="lumia-db-tab-data" class="lumia-tabs__panel lumia-db__tab-content" role="tabpanel" data-lumia-tabs-group="database" data-lumia-tab-panel="data"></div>
				<div id="lumia-db-tab-structure" class="lumia-tabs__panel lumia-db__tab-content" role="tabpanel" data-lumia-tabs-group="database" data-lumia-tab-panel="structure" hidden></div>
				<div id="lumia-db-tab-query" class="lumia-tabs__panel lumia-db__tab-content" role="tabpanel" data-lumia-tabs-group="database" data-lumia-tab-panel="query" hidden></div>

			</div><!-- #lumia-db-table-view -->
		</div><!-- .lumia-db__main -->
	</div><!-- .lumia-db__layout -->
</div>

<!-- MODAL SUPPRESSION DE TABLE (confirmation par saisie du nom) -->
<div class="lumia-modal-overlay" id="lumia-db-drop-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-db-drop-title">
	<div class="lumia-modal">
		<div class="lumia-modal__header">
			<h3 id="lumia-db-drop-title" class="lumia-modal__title"><?php esc_html_e( 'Supprimer la table', 'lumia-tools' ); ?></h3>
		</div>
		<div class="lumia-modal__body">
			<p class="lumia-modal__message">
				<?php esc_html_e( 'Cette action supprime définitivement la table et toutes ses données. Elle est irréversible.', 'lumia-tools' ); ?>
			</p>
			<div class="lumia-form__group" style="margin-top:12px">
				<label class="lumia-form__label" for="lumia-db-drop-confirm-input">
					<?php esc_html_e( 'Tapez le nom de la table pour confirmer :', 'lumia-tools' ); ?>
					<code id="lumia-db-drop-name"></code>
				</label>
				<input type="text" class="lumia-input" id="lumia-db-drop-confirm-input" autocomplete="off" spellcheck="false">
			</div>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Annuler', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-db-drop-confirm-btn" disabled><?php esc_html_e( 'Supprimer définitivement', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>

<!-- MODAL AJOUT DE LIGNE (champs générés dynamiquement en JS) -->
<div class="lumia-modal-overlay" id="lumia-db-insert-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-db-insert-title">
	<div class="lumia-modal lumia-modal--lg">
		<div class="lumia-modal__header">
			<h3 id="lumia-db-insert-title" class="lumia-modal__title"><?php esc_html_e( 'Ajouter une ligne', 'lumia-tools' ); ?></h3>
		</div>
		<div class="lumia-modal__body">
			<div class="lumia-db__insert-fields" id="lumia-db-insert-fields"></div>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Annuler', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-db-insert-confirm-btn"><?php esc_html_e( 'Insérer la ligne', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
