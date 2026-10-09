<?php
/**
 * Module settings template.
 */

defined( 'ABSPATH' ) || exit;

$module_id = substr( $tab, 7 );
$module    = $this->modules->get( $module_id );
$instance  = $this->modules->get_active_instances()[ $module_id ] ?? null;

if ( ! $instance ) {
	return;
}

$module_settings = $instance->get_settings();
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html( $module['name'] ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html( $module['description'] ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-module-form" id="lumia-module-save-btn" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Save', 'lumia-tools' ); ?>
			</button>
		</div>
	</div>

	<?php
	// The module may provide its own settings template
	$module_class_name = str_replace( ' ', '', ucwords( str_replace( '_', ' ', $module_id ) ) );
	$module_template   = LUMIA_PLUGIN_DIR . 'includes/Modules/' . $module_class_name . '/settings-template.php';

	if ( file_exists( $module_template ) ) {
		include $module_template;
	} else {
		// Generic template
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form">
			<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
			<input type="hidden" name="action" value="lumia_save_settings">
			<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

			<div class="lumia-card">
				<div class="lumia-card__header">
					<h2 class="lumia-card__title"><?php echo esc_html__( 'Module settings', 'lumia-tools' ); ?></h2>
				</div>
				<div class="lumia-card__body">
					<?php foreach ( $module_settings as $key => $value ) : ?>
						<div class="lumia-form__group lumia-form__group--toggle">
							<div class="lumia-form__toggle-label">
								<label for="lumia_<?php echo esc_attr( $key ); ?>" class="lumia-form__label"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></label>
							</div>
							<label class="lumia-toggle">
								<input type="checkbox"
										id="lumia_<?php echo esc_attr( $key ); ?>"
										name="lumia_module_settings[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( $value, true ); ?>>
								<span class="lumia-toggle__slider"></span>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="lumia-form__actions">
				<button type="submit" class="lumia-btn lumia-btn--primary">
					<?php echo esc_html__( 'Save settings', 'lumia-tools' ); ?>
				</button>
			</div>
		</form>
	<?php } ?>
</div>