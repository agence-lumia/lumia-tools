<?php
/**
 * Reusable sidebar component.
 */

defined( 'ABSPATH' ) || exit;

$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.
$modules = $this->modules->get_all();

$core_items = [
	[
		'id'    => 'dashboard',
		'label' => __( 'Overview', 'lumia-tools' ),
		'desc'  => __( 'Dashboard', 'lumia-tools' ),
		'icon'  => 'layout-dashboard',
	],
	[
		'id'    => 'modules',
		'label' => __( 'Modules', 'lumia-tools' ),
		'desc'  => __( 'Manage modules', 'lumia-tools' ),
		'icon'  => 'package',
	],
	[
		'id'    => 'settings',
		'label' => __( 'Settings', 'lumia-tools' ),
		'desc'  => __( 'Global configuration', 'lumia-tools' ),
		'icon'  => 'settings',
	],
];
?>
<aside class="lumia-sidebar">
	<div class="lumia-sidebar__header">
		<h2 class="lumia-sidebar__title"><?php echo esc_html__( 'Navigation', 'lumia-tools' ); ?></h2>
		<div class="lumia-sidebar__actions"></div>
	</div>

	<nav class="lumia-sidebar__nav">
		<ul class="lumia-sidebar__menu">
			<?php foreach ( $core_items as $item ) : ?>
				<li class="lumia-sidebar__item">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_slug() . '&tab=' . $item['id'] ) ); ?>"
						class="lumia-sidebar__link <?php echo $item['id'] === $tab ? 'is-active' : ''; ?>">
						<div class="lumia-sidebar__icon-wrapper">
							<?php echo $this->render_icon( $item['icon'], 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="lumia-sidebar__link-text">
							<span class="lumia-sidebar__link-label"><?php echo esc_html( $item['label'] ); ?></span>
							<span class="lumia-sidebar__link-desc"><?php echo esc_html( $item['desc'] ); ?></span>
						</div>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( ! empty( $modules ) ) : ?>
			<div class="lumia-sidebar__divider"></div>
			<ul class="lumia-sidebar__menu">
				<?php foreach ( $modules as $module_id => $module ) : ?>
					<?php if ( $this->modules->is_active( $module_id ) ) : ?>
						<?php
							$label = ! empty( $module['menu_label'] ) ? $module['menu_label'] : $module['name'];
							$desc  = ! empty( $module['menu_desc'] ) ? $module['menu_desc'] : $module['description'];
							$icon  = ! empty( $module['icon'] ) ? $module['icon'] : 'package';
						?>
						<li class="lumia-sidebar__item">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->get_slug() . '&tab=module_' . $module_id ) ); ?>"
								class="lumia-sidebar__link <?php echo 'module_' . $module_id === $tab ? 'is-active' : ''; ?>">
								<div class="lumia-sidebar__icon-wrapper">
									<?php echo $this->render_icon( $icon, 'sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
								<div class="lumia-sidebar__link-text">
									<span class="lumia-sidebar__link-label"><?php echo esc_html( $label ); ?></span>
									<span class="lumia-sidebar__link-desc"><?php echo esc_html( $desc ); ?></span>
								</div>
							</a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</nav>
</aside>
