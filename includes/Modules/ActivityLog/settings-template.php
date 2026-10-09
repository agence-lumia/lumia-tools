<?php
/**
 * Écran du module Journal d'activité : liste filtrable, puis réglages.
 *
 * Variables disponibles (via module-settings.php):
 * @var string          $module_id       ID du module (activity_log)
 * @var array           $module          Infos du module
 * @var ModuleInterface $instance        Instance du module
 * @var array           $module_settings Settings actuels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Modules\ActivityLog\Events;
use Lumia\Tools\Modules\ActivityLog\Store;

$excluded_groups = (array) ( $module_settings['excluded_groups'] ?? [] );
$excluded_roles  = (array) ( $module_settings['excluded_roles'] ?? [] );
$log_users       = Store::users();
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="activity_log" aria-label="<?php esc_attr_e( 'Sections du journal d\'activité', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="log"><?php esc_html_e( 'Journal', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Réglages', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="activity_log" data-lumia-tab-panel="log">

	<!-- ============================================================
		JOURNAL
		Les filtres n'ont pas d'attribut name : ils ne partent jamais avec
		le formulaire de réglages.
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Journal', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Les événements les plus récents d\'abord. Cliquez sur une ligne pour en voir le détail.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-al" id="lumia-al" data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>">

				<div class="lumia-al__filters">
					<div class="lumia-search lumia-search--sm lumia-al__search">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
						<input type="search" class="lumia-search__input" id="lumia-al-search"
							placeholder="<?php esc_attr_e( 'Objet, identifiant, IP…', 'lumia-tools' ); ?>"
							aria-label="<?php esc_attr_e( 'Rechercher dans le journal', 'lumia-tools' ); ?>">
					</div>

					<select class="lumia-select lumia-select--sm" id="lumia-al-user" aria-label="<?php esc_attr_e( 'Utilisateur', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'Tous les utilisateurs', 'lumia-tools' ); ?></option>
						<?php foreach ( $log_users as $log_user ) : ?>
							<option value="<?php echo esc_attr( (string) $log_user['user_id'] ); ?>"><?php echo esc_html( $log_user['user_login'] ); ?></option>
						<?php endforeach; ?>
					</select>

					<select class="lumia-select lumia-select--sm" id="lumia-al-type" aria-label="<?php esc_attr_e( 'Type d\'événement', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'Tous les événements', 'lumia-tools' ); ?></option>
						<?php foreach ( Events::groups() as $group_key => $group_label ) : ?>
							<optgroup label="<?php echo esc_attr( $group_label ); ?>">
								<option value="group:<?php echo esc_attr( $group_key ); ?>">
									<?php
									/* translators: %s: famille d'événements (Connexions, Contenus…). */
									echo esc_html( sprintf( __( '%s : tout', 'lumia-tools' ), $group_label ) );
									?>
								</option>
								<?php foreach ( Events::in_group( $group_key ) as $event_key ) : ?>
									<option value="event:<?php echo esc_attr( $event_key ); ?>"><?php echo esc_html( Events::label( $event_key ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>

					<input type="date" class="lumia-input lumia-input--sm" id="lumia-al-from" aria-label="<?php esc_attr_e( 'Depuis le', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Depuis le', 'lumia-tools' ); ?>">
					<input type="date" class="lumia-input lumia-input--sm" id="lumia-al-to" aria-label="<?php esc_attr_e( 'Jusqu\'au', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Jusqu\'au', 'lumia-tools' ); ?>">

					<div class="lumia-al__filter-actions">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-reset"><?php esc_html_e( 'Réinitialiser', 'lumia-tools' ); ?></button>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-export" data-lumia-tip="<?php esc_attr_e( 'Exporte les événements correspondant aux filtres', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
							<?php esc_html_e( 'Exporter en CSV', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<div class="lumia-al__table-wrap">
					<table class="lumia-al__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Utilisateur', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Événement', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Objet', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Adresse IP', 'lumia-tools' ); ?></th>
							</tr>
						</thead>
						<tbody id="lumia-al-rows">
							<tr><td colspan="5" class="lumia-al__state"><?php esc_html_e( 'Chargement…', 'lumia-tools' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="lumia-al__footer">
					<span class="lumia-al__total" id="lumia-al-total"></span>
					<div class="lumia-al__pager">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-prev" disabled aria-label="<?php esc_attr_e( 'Page précédente', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Page précédente', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
						</button>
						<span class="lumia-al__page" id="lumia-al-page"></span>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-next" disabled aria-label="<?php esc_attr_e( 'Page suivante', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Page suivante', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="activity_log" data-lumia-tab-panel="settings" hidden>

	<!-- ============================================================
		CONSERVATION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Conservation', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Une purge quotidienne supprime les événements trop anciens, puis les plus anciens au-delà du plafond. Exportez en CSV ce que vous voulez garder.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_al_retention_days" class="lumia-form__label"><?php esc_html_e( 'Durée de conservation (jours)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_al_retention_days" name="lumia_module_settings[retention_days]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['retention_days'] ); ?>" min="1" max="3650">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_al_max_rows" class="lumia-form__label"><?php esc_html_e( 'Nombre maximal d\'événements', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_al_max_rows" name="lumia_module_settings[max_rows]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['max_rows'] ); ?>" min="100" max="1000000" step="100">
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_al_anonymize_ip" class="lumia-option__label">
						<?php esc_html_e( 'Anonymiser les adresses IP', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Ne s\'applique qu\'aux nouveaux événements. L\'adresse masquée ne permet plus d\'identifier un poste précis face à une intrusion.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Masque la fin de l\'adresse (dernier octet en IPv4) avant l\'enregistrement, comme les outils de confidentialité de WordPress.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_al_anonymize_ip" name="lumia_module_settings[anonymize_ip]" value="1" <?php checked( ! empty( $module_settings['anonymize_ip'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		PÉRIMÈTRE
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Ce qui est journalisé', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Décochez une famille ou un rôle pour ne plus enregistrer ses événements. Les événements déjà enregistrés restent.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-al__checks-title"><?php esc_html_e( 'Familles d\'événements', 'lumia-tools' ); ?></div>
			<div class="lumia-al__checks">
				<?php foreach ( Events::groups() as $group_key => $group_label ) : ?>
					<label class="lumia-al__check">
						<span class="lumia-toggle">
							<input type="checkbox" name="lumia_module_settings[tracked_groups][]" value="<?php echo esc_attr( $group_key ); ?>" <?php checked( ! in_array( $group_key, $excluded_groups, true ) ); ?>>
							<span class="lumia-toggle__slider"></span>
						</span>
						<span class="lumia-al__check-name"><?php echo esc_html( $group_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="lumia-al__checks-title">
				<?php esc_html_e( 'Rôles', 'lumia-tools' ); ?>
				<?php echo $this->render_help_tip( __( 'Les échecs de connexion sont toujours enregistrés, quel que soit le compte visé : exclure les administrateurs ne doit pas masquer les attaques contre eux.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="lumia-al__checks">
				<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
					<label class="lumia-al__check">
						<span class="lumia-toggle">
							<input type="checkbox" name="lumia_module_settings[tracked_roles][]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( ! in_array( $role_slug, $excluded_roles, true ) ); ?>>
							<span class="lumia-toggle__slider"></span>
						</span>
						<span class="lumia-al__check-name"><?php echo esc_html( translate_user_role( $role_name ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>

<!-- MODALE DE DÉTAIL (contenu généré en JS) -->
<div class="lumia-modal-overlay" id="lumia-al-detail-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-al-detail-title">
	<div class="lumia-modal lumia-modal--lg">
		<div class="lumia-modal__header">
			<h3 id="lumia-al-detail-title" class="lumia-modal__title"></h3>
		</div>
		<div class="lumia-modal__body">
			<dl class="lumia-al__detail" id="lumia-al-detail-body"></dl>
		</div>
		<div class="lumia-modal__footer">
			<a class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-detail-link" href="#" hidden><?php esc_html_e( 'Ouvrir l\'objet', 'lumia-tools' ); ?></a>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary lumia-modal-close"><?php esc_html_e( 'Fermer', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
