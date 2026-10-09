<?php
/**
 * Activity Log module screen: filterable list, then settings.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (activity_log)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
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

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="activity_log" aria-label="<?php esc_attr_e( 'Activity log sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="log"><?php esc_html_e( 'Log', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Settings', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="activity_log" data-lumia-tab-panel="log">

	<!-- ============================================================
		LOG
		The filters have no name attribute: they are never sent with the
		settings form.
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Log', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Most recent events first. Click a row to see its details.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-al" id="lumia-al" data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>">

				<div class="lumia-al__filters">
					<div class="lumia-search lumia-search--sm lumia-al__search">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
						<input type="search" class="lumia-search__input" id="lumia-al-search"
							placeholder="<?php esc_attr_e( 'Object, identifier, IP…', 'lumia-tools' ); ?>"
							aria-label="<?php esc_attr_e( 'Search the log', 'lumia-tools' ); ?>">
					</div>

					<select class="lumia-select lumia-select--sm" id="lumia-al-user" aria-label="<?php esc_attr_e( 'User', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'All users', 'lumia-tools' ); ?></option>
						<?php foreach ( $log_users as $log_user ) : ?>
							<option value="<?php echo esc_attr( (string) $log_user['user_id'] ); ?>"><?php echo esc_html( $log_user['user_login'] ); ?></option>
						<?php endforeach; ?>
					</select>

					<select class="lumia-select lumia-select--sm" id="lumia-al-type" aria-label="<?php esc_attr_e( 'Event type', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'All events', 'lumia-tools' ); ?></option>
						<?php foreach ( Events::groups() as $group_key => $group_label ) : ?>
							<optgroup label="<?php echo esc_attr( $group_label ); ?>">
								<option value="group:<?php echo esc_attr( $group_key ); ?>">
									<?php
									/* translators: %s: event group (Logins, Content…). */
									echo esc_html( sprintf( __( '%s: all', 'lumia-tools' ), $group_label ) );
									?>
								</option>
								<?php foreach ( Events::in_group( $group_key ) as $event_key ) : ?>
									<option value="event:<?php echo esc_attr( $event_key ); ?>"><?php echo esc_html( Events::label( $event_key ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>

					<input type="date" class="lumia-input lumia-input--sm" id="lumia-al-from" aria-label="<?php esc_attr_e( 'Since', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Since', 'lumia-tools' ); ?>">
					<input type="date" class="lumia-input lumia-input--sm" id="lumia-al-to" aria-label="<?php esc_attr_e( 'Until', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Until', 'lumia-tools' ); ?>">

					<div class="lumia-al__filter-actions">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-reset"><?php esc_html_e( 'Reset', 'lumia-tools' ); ?></button>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-export" data-lumia-tip="<?php esc_attr_e( 'Exports the events matching the filters', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/></svg>
							<?php esc_html_e( 'Export as CSV', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<div class="lumia-al__table-wrap">
					<table class="lumia-al__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'User', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Event', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Object', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'IP address', 'lumia-tools' ); ?></th>
							</tr>
						</thead>
						<tbody id="lumia-al-rows">
							<tr><td colspan="5" class="lumia-al__state"><?php esc_html_e( 'Loading…', 'lumia-tools' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="lumia-al__footer">
					<span class="lumia-al__total" id="lumia-al-total"></span>
					<div class="lumia-al__pager">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-prev" disabled aria-label="<?php esc_attr_e( 'Previous page', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Previous page', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
						</button>
						<span class="lumia-al__page" id="lumia-al-page"></span>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-next" disabled aria-label="<?php esc_attr_e( 'Next page', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Next page', 'lumia-tools' ); ?>">
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
		RETENTION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Retention', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'A daily purge deletes events that are too old, then the oldest ones beyond the cap. Export what you want to keep as CSV.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_al_retention_days" class="lumia-form__label"><?php esc_html_e( 'Retention period (days)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_al_retention_days" name="lumia_module_settings[retention_days]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['retention_days'] ); ?>" min="1" max="3650">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_al_max_rows" class="lumia-form__label"><?php esc_html_e( 'Maximum number of events', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_al_max_rows" name="lumia_module_settings[max_rows]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['max_rows'] ); ?>" min="100" max="1000000" step="100">
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_al_anonymize_ip" class="lumia-option__label">
						<?php esc_html_e( 'Anonymize IP addresses', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Only applies to new events. A masked address can no longer identify a specific device after an intrusion.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Masks the end of the address (last octet in IPv4) before it is saved, like the WordPress privacy tools.', 'lumia-tools' ); ?></p>
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
		SCOPE
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'What is logged', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Uncheck a group or a role to stop logging its events. Events already logged are kept.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-al__checks-title"><?php esc_html_e( 'Event groups', 'lumia-tools' ); ?></div>
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
				<?php esc_html_e( 'Roles', 'lumia-tools' ); ?>
				<?php echo $this->render_help_tip( __( 'Failed logins are always logged, whichever account is targeted: excluding administrators must not hide attacks against them.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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

<!-- DETAIL MODAL (content generated in JS) -->
<div class="lumia-modal-overlay" id="lumia-al-detail-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-al-detail-title">
	<div class="lumia-modal lumia-modal--lg">
		<div class="lumia-modal__header">
			<h3 id="lumia-al-detail-title" class="lumia-modal__title"></h3>
		</div>
		<div class="lumia-modal__body">
			<dl class="lumia-al__detail" id="lumia-al-detail-body"></dl>
		</div>
		<div class="lumia-modal__footer">
			<a class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-al-detail-link" href="#" hidden><?php esc_html_e( 'Open object', 'lumia-tools' ); ?></a>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary lumia-modal-close"><?php esc_html_e( 'Close', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
