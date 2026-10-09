<?php
/**
 * Modules page template.
 */

defined( 'ABSPATH' ) || exit;

$modules = $this->modules->get_all();
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Modules', 'lumia-tools' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Enable or disable modules as needed.', 'lumia-tools' ); ?></p>
		</div>
	</div>

	<div class="lumia-page__scroll">
		<form id="lumia-modules-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form">
			<?php wp_nonce_field( 'lumia_update_modules', 'lumia_modules_nonce' ); ?>
			<input type="hidden" name="action" value="lumia_update_modules">

			<div class="lumia-module-grid lumia-page__body">
				<?php
				foreach ( $modules as $module_id => $module ) :
					$is_active = $this->modules->is_active( $module_id );
					$icon      = ! empty( $module['icon'] ) ? $module['icon'] : 'package';
					?>
					<div class="lumia-module-card <?php echo $is_active ? 'lumia-module-card--active' : ''; ?>">
						<div class="lumia-module-card__header">
							<?php echo $this->render_icon( $icon, 'md' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<h3 class="lumia-module-card__title"><?php echo esc_html( $module['name'] ); ?></h3>
							<label class="lumia-toggle">
								<input type="checkbox"
										name="lumia_modules[]"
										value="<?php echo esc_attr( $module_id ); ?>"
										data-module-id="<?php echo esc_attr( $module_id ); ?>"
										<?php checked( $is_active, true ); ?>>
								<span class="lumia-toggle__slider"></span>
							</label>
						</div>
						<p class="lumia-module-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
						<div class="lumia-module-card__actions">

							<?php if ( $is_active ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_slug() . '&tab=module_' . $module_id ) ); ?>" class="lumia-btn lumia-btn--sm lumia-btn--secondary">
									<?php echo esc_html__( 'Configure', 'lumia-tools' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="lumia-page__footer">
				<button type="submit" class="lumia-btn lumia-btn--primary" style="display:none">
					<?php echo esc_html__( 'Save modules', 'lumia-tools' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
