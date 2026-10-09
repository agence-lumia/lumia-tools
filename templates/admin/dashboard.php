<?php
/**
 * Dashboard template.
 */

defined( 'ABSPATH' ) || exit;

$modules      = $this->modules->get_all();
$active_count = count(
	array_filter(
		$modules,
		function ( $id ) {
			return $this->modules->is_active( $id );
		},
		ARRAY_FILTER_USE_KEY
	)
);

// Cache read only: no GitHub call from the dashboard.
$update_status = \Lumia\Tools\Core\Plugin::instance()->updater->get_status();
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Overview', 'lumia-tools' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Main plugin settings', 'lumia-tools' ); ?></p>
		</div>
	</div>

		<div class="lumia-cards">
			<div class="lumia-card lumia-card--stat">
				<div class="lumia-card__icon">
					<?php echo $this->render_icon( 'package', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lumia-card__content">
					<span class="lumia-card__value"><?php echo esc_html( count( $modules ) ); ?></span>
					<span class="lumia-card__label"><?php echo esc_html__( 'Available modules', 'lumia-tools' ); ?></span>
				</div>
			</div>

			<div class="lumia-card lumia-card--stat">
				<div class="lumia-card__icon lumia-card__icon--success">
					<?php echo $this->render_icon( 'check-circle', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lumia-card__content">
					<span class="lumia-card__value"><?php echo esc_html( $active_count ); ?></span>
					<span class="lumia-card__label"><?php echo esc_html__( 'Active modules', 'lumia-tools' ); ?></span>
				</div>
			</div>

			<div class="lumia-card lumia-card--stat">
				<div class="lumia-card__icon lumia-card__icon--info">
					<?php echo $this->render_icon( 'info', 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="lumia-card__content">
					<span class="lumia-card__value"><?php echo esc_html( LUMIA_VERSION ); ?></span>
					<span class="lumia-card__label">
						<?php
						echo esc_html(
							'dev' === $update_status['channel']
								? __( 'Version · dev channel', 'lumia-tools' )
								: __( 'Version · stable channel', 'lumia-tools' )
						);
						?>
					</span>
					<?php if ( $update_status['has_update'] ) : ?>
						<?php /* translators: %s: available version number. */ ?>
						<a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>" class="lumia-badge lumia-badge--warning"><?php echo esc_html( sprintf( __( 'v%s available', 'lumia-tools' ), $update_status['remote'] ) ); ?></a>
					<?php elseif ( null !== $update_status['remote'] ) : ?>
						<span class="lumia-badge lumia-badge--success"><?php echo esc_html__( 'Up to date', 'lumia-tools' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Active modules', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'These are the modules currently active on your site.', 'lumia-tools' ); ?></p>
			</div>
			<div class="lumia-section__content">
				<?php if ( $active_count > 0 ) : ?>
					<div class="lumia-module-list">
						<?php foreach ( $modules as $module_id => $module ) : ?>
							<?php if ( $this->modules->is_active( $module_id ) ) : ?>
								<?php $icon = ! empty( $module['icon'] ) ? $module['icon'] : 'package'; ?>
								<div class="lumia-module-card lumia-module-card--active">
								<div class="lumia-module-card__header">
										<?php echo $this->render_icon( $icon, 'md' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<h3 class="lumia-module-card__title"><?php echo esc_html( $module['name'] ); ?></h3>
										<span class="lumia-badge lumia-badge--success"><?php echo esc_html__( 'Active', 'lumia-tools' ); ?></span>
									</div>
									<p class="lumia-module-card__desc"><?php echo esc_html( $module['description'] ); ?></p>
									<div class="lumia-module-card__actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_slug() . '&tab=module_' . $module_id ) ); ?>" class="lumia-btn lumia-btn--sm lumia-btn--secondary">
											<?php echo esc_html__( 'Configure', 'lumia-tools' ); ?>
										</a>
									</div>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<div class="lumia-empty">
						<?php echo $this->render_icon( 'package', 'xl' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><?php echo esc_html__( 'No active modules. Activate modules from the Modules page.', 'lumia-tools' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_slug() . '&tab=modules' ) ); ?>" class="lumia-btn lumia-btn--primary">
							<?php echo esc_html__( 'View modules', 'lumia-tools' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
</div>
