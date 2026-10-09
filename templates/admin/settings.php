<?php
/**
 * Global settings page template.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$global         = $this->settings->get( 'global', [] );
$update_channel = $global['update_channel'] ?? 'stable';

// Automatic update: the source of truth remains the WordPress option
// `auto_update_plugins`, shared with the plugins list.
$can_auto_update = wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' );
$auto_update_on  = in_array( plugin_basename( LUMIA_PLUGIN_FILE ), (array) get_site_option( 'auto_update_plugins', [] ), true );

// Image capabilities: the same detection as the Image Optimizer module (real
// trial encoding, cached), rather than queryFormats()/gd_info() which
// sometimes announce a format with no real encoding delegate.
$image_caps = ( new \Lumia\Tools\Modules\ImageOptimizer\ImageProcessor( [] ) )->get_capabilities();
$can_avif   = ! empty( $image_caps['avif'] );
$can_webp   = ! empty( $image_caps['webp'] );
$has_editor = 'none' !== $image_caps['editor'];
$editor     = $has_editor ? ucfirst( $image_caps['editor'] ) : __( 'None', 'lumia-tools' );

// Server info
$php_version     = PHP_VERSION;
$wp_version      = get_bloginfo( 'version' );
$mysql_version   = $wpdb->db_version();
$memory_limit    = ini_get( 'memory_limit' );
$upload_max      = ini_get( 'upload_max_filesize' );
$post_max        = ini_get( 'post_max_size' );
$max_exec_time   = ini_get( 'max_execution_time' );
$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'N/A', 'lumia-tools' );
$ssl_version     = defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null;
$curl_version    = function_exists( 'curl_version' ) ? curl_version()['version'] : null;
$has_zip         = extension_loaded( 'zip' );
$has_mbstring    = extension_loaded( 'mbstring' );
$wp_debug        = defined( 'WP_DEBUG' ) && WP_DEBUG;
$wp_memory_limit = defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'N/A', 'lumia-tools' );
?>
<div class="lumia-page">
	<div class="lumia-page__header">
		<div class="lumia-page__header-content">
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Settings', 'lumia-tools' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Global plugin configuration.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-save-settings-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Save', 'lumia-tools' ); ?>
			</button>
		</div>
	</div>

	<div class="lumia-page__scroll">

	<!-- ================================================================
		UPDATES
		================================================================ -->
	<form id="lumia-save-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
		<input type="hidden" name="action" value="lumia_save_settings">
		<input type="hidden" name="lumia_tab" value="settings">

		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Updates', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'Choose the plugin update channel.', 'lumia-tools' ); ?></p>
			</div>
			<div class="lumia-section__content">

				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php echo esc_html__( 'Current version', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php echo esc_html__( 'Plugin version installed on this site.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control lumia-inline">
						<span class="lumia-badge lumia-badge--info">v<?php echo esc_html( LUMIA_VERSION ); ?></span>
						<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm" form="lumia-check-updates-form">
							<?php echo esc_html__( 'Check for updates', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<div class="lumia-option">
					<div class="lumia-option__content">
						<label for="lumia_update_channel" class="lumia-option__label"><?php echo esc_html__( 'Update channel', 'lumia-tools' ); ?></label>
						<p class="lumia-option__desc"><?php echo esc_html__( 'Choose between stable versions and development pre-releases.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control">
						<select id="lumia_update_channel" name="lumia_global[update_channel]" class="lumia-select lumia-select--sm">
							<option value="stable" <?php selected( $update_channel, 'stable' ); ?>><?php echo esc_html__( 'Stable (main)', 'lumia-tools' ); ?></option>
							<option value="dev" <?php selected( $update_channel, 'dev' ); ?>><?php echo esc_html__( 'Dev (pre-release)', 'lumia-tools' ); ?></option>
						</select>
					</div>
				</div>

				<div class="lumia-option">
					<div class="lumia-option__content">
						<label for="lumia_auto_update" class="lumia-option__label"><?php echo esc_html__( 'Automatic updates', 'lumia-tools' ); ?></label>
						<p class="lumia-option__desc">
							<?php
							echo esc_html(
								$can_auto_update
									? __( 'Installs new versions of the chosen channel without intervention. Same setting as the "Automatic updates" column of the plugins list.', 'lumia-tools' )
									: __( 'Automatic plugin updates are disabled on this site, or your account cannot manage them.', 'lumia-tools' )
							);
							?>
						</p>
					</div>
					<div class="lumia-option__control">
						<input type="hidden" name="lumia_global[auto_update_initial]" value="<?php echo $auto_update_on ? '1' : '0'; ?>">
						<label class="lumia-toggle">
							<input type="checkbox" id="lumia_auto_update" name="lumia_global[auto_update]" value="1" <?php checked( $auto_update_on ); ?> <?php disabled( ! $can_auto_update ); ?>>
							<span class="lumia-toggle__slider"></span>
						</label>
					</div>
				</div>

			</div>
		</div>

	</form>

	<div class="lumia-divider"></div>

	<!-- ================================================================
		CONFIGURATION — export / import / reset
		================================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Configuration', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Export, import or reset the plugin configuration.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-config-grid">

				<!-- Export -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title"><?php echo esc_html__( 'Export', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Downloads a JSON file with all the global settings and the configuration of each module.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lumia_export_settings', 'lumia_export_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_export_settings">
							<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm">
								<?php echo esc_html__( 'Export', 'lumia-tools' ); ?>
							</button>
						</form>
					</div>
				</div>

				<!-- Import -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title"><?php echo esc_html__( 'Import', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Restores the settings from a previously exported JSON file.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form id="lumia-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<?php wp_nonce_field( 'lumia_import_settings', 'lumia_import_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_import_settings">
							<input type="file" name="lumia_import_file" id="lumia_import_file" accept=".json"
									style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)"
									onchange="document.getElementById('lumia-import-form').submit()">
							<label for="lumia_import_file" class="lumia-btn lumia-btn--secondary lumia-btn--sm" style="width:100%;cursor:pointer;">
								<?php echo esc_html__( 'Import', 'lumia-tools' ); ?>
							</label>
						</form>
					</div>
				</div>

				<!-- Reset -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title lumia-config-card__title--danger"><?php echo esc_html__( 'Reset', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Restores all settings to their default values. Generated content is not deleted.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form id="lumia-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lumia_reset_settings', 'lumia_reset_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_reset_settings">
							<button type="button" class="lumia-btn lumia-btn--danger lumia-btn--sm"
								data-modal-confirm
								data-modal-title="<?php esc_attr_e( 'Reset settings', 'lumia-tools' ); ?>"
								data-modal-message="<?php esc_attr_e( 'Reset all plugin settings to their default values?', 'lumia-tools' ); ?>"
								data-modal-confirm-label="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>"
								data-modal-form="lumia-reset-form">
								<?php echo esc_html__( 'Reset', 'lumia-tools' ); ?>
							</button>
						</form>
					</div>
				</div>

			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ================================================================
		SERVER INFORMATION — collapsible table
		================================================================ -->
	<details class="lumia-section lumia-section--collapsible">
		<summary class="lumia-section__header lumia-section__header--summary">
			<div>
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Server information', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'Status and capabilities of the hosting environment.', 'lumia-tools' ); ?></p>
			</div>
			<span class="lumia-section__toggle-icon" aria-hidden="true">
				<?php echo $this->render_icon( 'chevron-down', '16', 'lumia-section__chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</span>
		</summary>
		<div class="lumia-section__content">
			<table class="lumia-server-table">
				<tbody>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WordPress', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--info">v<?php echo esc_html( $wp_version ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'PHP', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo version_compare( $php_version, '8.0', '>=' ) ? 'lumia-badge--success' : 'lumia-badge--warning'; ?>">v<?php echo esc_html( $php_version ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Database', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--info">v<?php echo esc_html( $mysql_version ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Server', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $server_software ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'PHP memory', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $memory_limit ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WordPress memory', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $wp_memory_limit ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Max upload', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $upload_max ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Max post', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $post_max ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Max execution', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $max_exec_time ); ?>s</span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Image editor', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $has_editor ? 'lumia-badge--success' : 'lumia-badge--danger'; ?>"><?php echo esc_html( $editor ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'AVIF', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $can_avif ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $can_avif ? esc_html__( 'Supported', 'lumia-tools' ) : esc_html__( 'Not supported', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WebP', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $can_webp ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $can_webp ? esc_html__( 'Supported', 'lumia-tools' ) : esc_html__( 'Not supported', 'lumia-tools' ); ?></span></td>
					</tr>
					<?php if ( $ssl_version ) : ?>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'OpenSSL', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--success"><?php echo esc_html( $ssl_version ); ?></span></td>
					</tr>
					<?php endif; ?>
					<?php if ( $curl_version ) : ?>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'cURL', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--success">v<?php echo esc_html( $curl_version ); ?></span></td>
					</tr>
					<?php endif; ?>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'ZIP', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $has_zip ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $has_zip ? esc_html__( 'Available', 'lumia-tools' ) : esc_html__( 'Not available', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'mbstring', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $has_mbstring ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $has_mbstring ? esc_html__( 'Available', 'lumia-tools' ) : esc_html__( 'Not available', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WP_DEBUG', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $wp_debug ? 'lumia-badge--warning' : 'lumia-badge--success'; ?>"><?php echo $wp_debug ? esc_html__( 'Enabled', 'lumia-tools' ) : esc_html__( 'Disabled', 'lumia-tools' ); ?></span></td>
					</tr>
				</tbody>
			</table>
		</div>
	</details>

	</div><!-- .lumia-page__scroll -->

	<form id="lumia-check-updates-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'lumia_check_updates', 'lumia_check_nonce' ); ?>
		<input type="hidden" name="action" value="lumia_check_updates">
	</form>
</div>
