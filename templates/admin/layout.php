<?php
/**
 * Main template of the admin interface.
 */

defined( 'ABSPATH' ) || exit;

$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation read (tab or displayed page), no action triggered.
?>
<div class="lumia-admin-wrap">
	<div class="lumia-admin-container">
		<!-- Sidebar -->
		<?php require LUMIA_TEMPLATES_DIR . 'components/sidebar.php'; ?>

		<!-- Main content -->
		<main class="lumia-admin-main">
			<?php
			switch ( $tab ) {
				case 'dashboard':
					include LUMIA_TEMPLATES_DIR . 'admin/dashboard.php';
					break;
				case 'modules':
					include LUMIA_TEMPLATES_DIR . 'admin/modules.php';
					break;
				case 'settings':
					include LUMIA_TEMPLATES_DIR . 'admin/settings.php';
					break;
				default:
					// Check whether it is an active module
					if ( strpos( $tab, 'module_' ) === 0 ) {
						$module_id = substr( $tab, 7 );
						$module    = $this->modules->get( $module_id );

						if ( $module && $this->modules->is_active( $module_id ) ) {
							include LUMIA_TEMPLATES_DIR . 'admin/module-settings.php';
						} else {
							include LUMIA_TEMPLATES_DIR . 'admin/dashboard.php';
						}
					} else {
						include LUMIA_TEMPLATES_DIR . 'admin/dashboard.php';
					}
					break;
			}
			?>
		</main>
	</div>
</div>