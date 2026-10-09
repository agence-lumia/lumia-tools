<?php
/**
 * Template de la page Réglages globaux.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$global         = $this->settings->get( 'global', [] );
$update_channel = $global['update_channel'] ?? 'stable';

// Mise à jour automatique : la source de vérité reste l'option WordPress
// `auto_update_plugins`, partagée avec la liste des extensions.
$can_auto_update = wp_is_auto_update_enabled_for_type( 'plugin' ) && current_user_can( 'update_plugins' );
$auto_update_on  = in_array( plugin_basename( LUMIA_PLUGIN_FILE ), (array) get_site_option( 'auto_update_plugins', [] ), true );

// Capacités image : la même détection que le module Image Optimizer (vrai
// encodage d'essai, mis en cache), et non queryFormats()/gd_info() qui
// annoncent parfois un format sans délégué d'encodage réel.
$image_caps = ( new \Lumia\Tools\Modules\ImageOptimizer\ImageProcessor( [] ) )->get_capabilities();
$can_avif   = ! empty( $image_caps['avif'] );
$can_webp   = ! empty( $image_caps['webp'] );
$has_editor = 'none' !== $image_caps['editor'];
$editor     = $has_editor ? ucfirst( $image_caps['editor'] ) : __( 'Aucun', 'lumia-tools' );

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
			<h1 class="lumia-page__title"><?php echo esc_html__( 'Réglages', 'lumia-tools' ); ?></h1>
			<p class="lumia-page__subtitle"><?php echo esc_html__( 'Configuration globale du plugin.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-page__header-actions">
			<button type="submit" form="lumia-save-settings-form" class="lumia-btn lumia-btn--primary lumia-btn--sm">
				<?php echo esc_html__( 'Enregistrer', 'lumia-tools' ); ?>
			</button>
		</div>
	</div>

	<div class="lumia-page__scroll">

	<!-- ================================================================
		MISES À JOUR
		================================================================ -->
	<form id="lumia-save-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
		<input type="hidden" name="action" value="lumia_save_settings">
		<input type="hidden" name="lumia_tab" value="settings">

		<div class="lumia-section">
			<div class="lumia-section__header">
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Mises à jour', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'Choisissez le canal de mise à jour du plugin.', 'lumia-tools' ); ?></p>
			</div>
			<div class="lumia-section__content">

				<div class="lumia-option">
					<div class="lumia-option__content">
						<span class="lumia-option__label"><?php echo esc_html__( 'Version actuelle', 'lumia-tools' ); ?></span>
						<p class="lumia-option__desc"><?php echo esc_html__( 'Version du plugin installée sur ce site.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-option__control lumia-inline">
						<span class="lumia-badge lumia-badge--info">v<?php echo esc_html( LUMIA_VERSION ); ?></span>
						<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm" form="lumia-check-updates-form">
							<?php echo esc_html__( 'Vérifier les mises à jour', 'lumia-tools' ); ?>
						</button>
					</div>
				</div>

				<div class="lumia-option">
					<div class="lumia-option__content">
						<label for="lumia_update_channel" class="lumia-option__label"><?php echo esc_html__( 'Canal de mise à jour', 'lumia-tools' ); ?></label>
						<p class="lumia-option__desc"><?php echo esc_html__( 'Choisissez entre les versions stables et les pré-versions de développement.', 'lumia-tools' ); ?></p>
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
						<label for="lumia_auto_update" class="lumia-option__label"><?php echo esc_html__( 'Mises à jour automatiques', 'lumia-tools' ); ?></label>
						<p class="lumia-option__desc">
							<?php
							echo esc_html(
								$can_auto_update
									? __( 'Installe les nouvelles versions du canal choisi sans intervention. Même réglage que la colonne « Mises à jour auto » de la liste des extensions.', 'lumia-tools' )
									: __( 'Les mises à jour automatiques des extensions sont désactivées sur ce site, ou votre compte ne peut pas les gérer.', 'lumia-tools' )
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
			<p class="lumia-section__desc"><?php echo esc_html__( 'Exportez, importez ou réinitialisez la configuration du plugin.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-config-grid">

				<!-- Export -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title"><?php echo esc_html__( 'Exporter', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Télécharge un fichier JSON avec tous les réglages globaux et la configuration de chaque module.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lumia_export_settings', 'lumia_export_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_export_settings">
							<button type="submit" class="lumia-btn lumia-btn--secondary lumia-btn--sm">
								<?php echo esc_html__( 'Exporter', 'lumia-tools' ); ?>
							</button>
						</form>
					</div>
				</div>

				<!-- Import -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title"><?php echo esc_html__( 'Importer', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Restaure les réglages depuis un fichier JSON exporté précédemment.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form id="lumia-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<?php wp_nonce_field( 'lumia_import_settings', 'lumia_import_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_import_settings">
							<input type="file" name="lumia_import_file" id="lumia_import_file" accept=".json"
									style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0,0,0,0)"
									onchange="document.getElementById('lumia-import-form').submit()">
							<label for="lumia_import_file" class="lumia-btn lumia-btn--secondary lumia-btn--sm" style="width:100%;cursor:pointer;">
								<?php echo esc_html__( 'Importer', 'lumia-tools' ); ?>
							</label>
						</form>
					</div>
				</div>

				<!-- Réinitialisation -->
				<div class="lumia-config-card">
					<div class="lumia-config-card__body">
						<p class="lumia-config-card__title lumia-config-card__title--danger"><?php echo esc_html__( 'Réinitialiser', 'lumia-tools' ); ?></p>
						<p class="lumia-config-card__desc"><?php echo esc_html__( 'Restaure tous les réglages aux valeurs par défaut. Les contenus générés ne sont pas supprimés.', 'lumia-tools' ); ?></p>
					</div>
					<div class="lumia-config-card__footer">
						<form id="lumia-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lumia_reset_settings', 'lumia_reset_nonce' ); ?>
							<input type="hidden" name="action" value="lumia_reset_settings">
							<button type="button" class="lumia-btn lumia-btn--danger lumia-btn--sm"
								data-modal-confirm
								data-modal-title="<?php esc_attr_e( 'Réinitialiser les réglages', 'lumia-tools' ); ?>"
								data-modal-message="<?php esc_attr_e( 'Réinitialiser tous les réglages du plugin aux valeurs par défaut ?', 'lumia-tools' ); ?>"
								data-modal-confirm-label="<?php esc_attr_e( 'Réinitialiser', 'lumia-tools' ); ?>"
								data-modal-form="lumia-reset-form">
								<?php echo esc_html__( 'Réinitialiser', 'lumia-tools' ); ?>
							</button>
						</form>
					</div>
				</div>

			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ================================================================
		INFORMATIONS SERVEUR — tableau collapsible
		================================================================ -->
	<details class="lumia-section lumia-section--collapsible">
		<summary class="lumia-section__header lumia-section__header--summary">
			<div>
				<h2 class="lumia-section__title"><?php echo esc_html__( 'Informations serveur', 'lumia-tools' ); ?></h2>
				<p class="lumia-section__desc"><?php echo esc_html__( 'État et capacités de l\'environnement d\'hébergement.', 'lumia-tools' ); ?></p>
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
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Base de données', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--info">v<?php echo esc_html( $mysql_version ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Serveur', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $server_software ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Mémoire PHP', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $memory_limit ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Mémoire WordPress', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $wp_memory_limit ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Upload max.', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $upload_max ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Post max.', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $post_max ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Exécution max.', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge lumia-badge--inactive"><?php echo esc_html( $max_exec_time ); ?>s</span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'Éditeur image', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $has_editor ? 'lumia-badge--success' : 'lumia-badge--danger'; ?>"><?php echo esc_html( $editor ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'AVIF', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $can_avif ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $can_avif ? esc_html__( 'Supporté', 'lumia-tools' ) : esc_html__( 'Non supporté', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WebP', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $can_webp ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $can_webp ? esc_html__( 'Supporté', 'lumia-tools' ) : esc_html__( 'Non supporté', 'lumia-tools' ); ?></span></td>
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
						<td><span class="lumia-badge <?php echo $has_zip ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $has_zip ? esc_html__( 'Disponible', 'lumia-tools' ) : esc_html__( 'Non disponible', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'mbstring', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $has_mbstring ? 'lumia-badge--success' : 'lumia-badge--inactive'; ?>"><?php echo $has_mbstring ? esc_html__( 'Disponible', 'lumia-tools' ) : esc_html__( 'Non disponible', 'lumia-tools' ); ?></span></td>
					</tr>
					<tr>
						<td class="lumia-server-table__label"><?php echo esc_html__( 'WP_DEBUG', 'lumia-tools' ); ?></td>
						<td><span class="lumia-badge <?php echo $wp_debug ? 'lumia-badge--warning' : 'lumia-badge--success'; ?>"><?php echo $wp_debug ? esc_html__( 'Activé', 'lumia-tools' ) : esc_html__( 'Désactivé', 'lumia-tools' ); ?></span></td>
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
