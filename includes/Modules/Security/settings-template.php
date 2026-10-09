<?php
/**
 * Template des réglages du module Sécurité.
 *
 * Variables disponibles (via module-settings.php):
 * @var string          $module_id       ID du module (security)
 * @var array           $module          Infos du module
 * @var ModuleInterface $instance        Instance du module
 * @var array           $module_settings Settings actuels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use StudioKyne\MiniTools\Modules\Security\ClientIp;

$auth      = $module_settings['authentication'] ?? [];
$hardening = $module_settings['hardening'] ?? [];

$ip_source = ClientIp::sanitize_source( $auth['ip_source'] ?? '' );

// En-têtes réellement présents sur CETTE requête : informe l'administrateur
// sans jamais décider à sa place (leur présence est ce qu'un attaquant contrôle).
$detected_headers = ClientIp::detected_headers();

$ip_sources = [
	ClientIp::SOURCE_REMOTE_ADDR => __( 'Aucun proxy — adresse de connexion directe (recommandé)', 'studio-kyne-mini-tools' ),
	ClientIp::SOURCE_CLOUDFLARE  => __( 'Cloudflare — en-tête CF-Connecting-IP', 'studio-kyne-mini-tools' ),
	ClientIp::SOURCE_FORWARDED   => __( 'Reverse proxy — en-tête X-Forwarded-For', 'studio-kyne-mini-tools' ),
];
?>

<form id="skmt-module-form" class="skmt-form skmt-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'skmt_save_settings', 'skmt_nonce' ); ?>
	<input type="hidden" name="action" value="skmt_save_settings">
	<input type="hidden" name="skmt_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="skmt-tabs" role="tablist" data-skmt-tabs="security" aria-label="<?php esc_attr_e( 'Sections du module Sécurité', 'studio-kyne-mini-tools' ); ?>">
		<button type="button" class="skmt-tabs__tab is-active" role="tab" data-skmt-tab="auth"><?php esc_html_e( 'Authentification', 'studio-kyne-mini-tools' ); ?></button>
		<button type="button" class="skmt-tabs__tab" role="tab" data-skmt-tab="hardening"><?php esc_html_e( 'Hardening', 'studio-kyne-mini-tools' ); ?></button>
	</div>

	<div class="skmt-module-form__scroll">

	<div class="skmt-tabs__panel" role="tabpanel" data-skmt-tabs-group="security" data-skmt-tab-panel="auth">

	<!-- ============================================================
		AUTHENTIFICATION
		============================================================ -->
	<div class="skmt-section">
		<div class="skmt-section__header">
			<h2 class="skmt-section__title"><?php esc_html_e( 'Authentification', 'studio-kyne-mini-tools' ); ?></h2>
			<p class="skmt-section__desc"><?php esc_html_e( 'Gérez les règles d\'accès et de connexion.', 'studio-kyne-mini-tools' ); ?></p>
		</div>
		<div class="skmt-section__content">

			<!-- Rate Limiting -->
			<div class="skmt-option">
				<div class="skmt-option__content">
					<label for="skmt_rate_limiting" class="skmt-option__label">
						<?php esc_html_e( 'Limiter les tentatives de connexion', 'studio-kyne-mini-tools' ); ?>
					</label>
					<p class="skmt-option__desc">
						<?php esc_html_e( 'Bloquer les IPs après trop de tentatives échouées.', 'studio-kyne-mini-tools' ); ?>
					</p>
				</div>
				<div class="skmt-option__control">
					<label class="skmt-toggle">
						<input
							type="checkbox"
							id="skmt_rate_limiting"
							name="skmt_module_settings[rate_limiting]"
							value="1"
							data-security-toggle="rate_limiting"
							<?php checked( $auth['rate_limiting'] ?? true ); ?>
						/>
						<span class="skmt-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Rate Limiting Options (sous-options conditionnelles) -->
			<div
				data-depends-on="rate_limiting"
				class="skmt-security-sub"
				<?php echo ( $auth['rate_limiting'] ?? true ) ? '' : 'style="display:none;"'; ?>
			>
				<div class="skmt-form__row">
					<div class="skmt-form__group">
						<label for="rate_limit_attempts" class="skmt-form__label">
							<?php esc_html_e( 'Tentatives autorisées', 'studio-kyne-mini-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_attempts"
							name="skmt_module_settings[rate_limit_attempts]"
							class="skmt-input skmt-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_attempts'] ?? 5 ); ?>"
							min="1"
							max="20"
						/>
					</div>

					<div class="skmt-form__group">
						<label for="rate_limit_window" class="skmt-form__label">
							<?php esc_html_e( 'Fenêtre (secondes)', 'studio-kyne-mini-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_window"
							name="skmt_module_settings[rate_limit_window]"
							class="skmt-input skmt-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_window'] ?? 900 ); ?>"
							min="60"
							step="60"
							data-seconds-field="rate_limit_window_help"
						/>
						<p class="skmt-form__help"><span id="rate_limit_window_help"></span></p>
					</div>

					<div class="skmt-form__group">
						<label for="rate_limit_lockout" class="skmt-form__label">
							<?php esc_html_e( 'Blocage (secondes)', 'studio-kyne-mini-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_lockout"
							name="skmt_module_settings[rate_limit_lockout]"
							class="skmt-input skmt-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_lockout'] ?? 1800 ); ?>"
							min="60"
							step="60"
							data-seconds-field="rate_limit_lockout_help"
						/>
						<p class="skmt-form__help"><span id="rate_limit_lockout_help"></span></p>
					</div>
				</div>

				<div class="skmt-form__group">
					<label for="rate_limit_whitelist" class="skmt-form__label">
						<?php esc_html_e( 'IP whitelistées (une par ligne)', 'studio-kyne-mini-tools' ); ?>
					</label>
					<textarea
						id="rate_limit_whitelist"
						name="skmt_module_settings[rate_limit_whitelist]"
						class="skmt-input skmt-security-sub__textarea"
						rows="4"
						placeholder="192.168.1.1&#10;127.0.0.1"
					><?php echo esc_textarea( implode( "\n", $auth['rate_limit_whitelist'] ?? [] ) ); ?></textarea>
					<p class="skmt-form__help"><?php esc_html_e( 'Ces IPs ne seront jamais bloquées par le rate limiting.', 'studio-kyne-mini-tools' ); ?></p>
				</div>

				<div class="skmt-form__group">
					<label for="skmt_ip_source" class="skmt-form__label">
						<?php esc_html_e( 'Origine de l’adresse IP', 'studio-kyne-mini-tools' ); ?>
					</label>
					<select id="skmt_ip_source" name="skmt_module_settings[ip_source]" class="skmt-select">
						<?php foreach ( $ip_sources as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $ip_source, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="skmt-form__help">
						<?php esc_html_e( 'Comment identifier un visiteur pour compter ses tentatives. Les en-têtes de proxy sont envoyés par le client : ne les activez que si le site est réellement derrière ce proxy, sinon le blocage se contourne en changeant simplement d’en-tête.', 'studio-kyne-mini-tools' ); ?>
					</p>
					<?php if ( $detected_headers ) : ?>
						<p class="skmt-form__help">
							<strong><?php esc_html_e( 'Détecté sur cette requête :', 'studio-kyne-mini-tools' ); ?></strong>
							<?php echo esc_html( implode( ', ', array_keys( $detected_headers ) ) ); ?>.
							<?php esc_html_e( 'Leur présence ne prouve pas qu’un proxy les a posés.', 'studio-kyne-mini-tools' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Custom Login URL Toggle + Input -->
			<div class="skmt-option">
				<div class="skmt-option__content">
					<label for="skmt_enable_custom_login_url" class="skmt-option__label">
						<?php esc_html_e( 'URL personnalisée de connexion', 'studio-kyne-mini-tools' ); ?>
					</label>
					<p class="skmt-option__desc">
						<?php esc_html_e( 'Remplace /wp-login.php par une URL personnalisée et bloque l\'accès à la page de connexion standard.', 'studio-kyne-mini-tools' ); ?>
					</p>
				</div>
				<div class="skmt-option__control">
					<label class="skmt-toggle">
						<input
							type="checkbox"
							id="skmt_enable_custom_login_url"
							name="skmt_module_settings[enable_custom_login_url]"
							value="1"
							data-security-toggle="enable_custom_login_url"
							<?php checked( $auth['enable_custom_login_url'] ?? true ); ?>
						/>
						<span class="skmt-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Custom Login URL Input (Conditional) -->
			<div
				data-depends-on="enable_custom_login_url"
				class="skmt-security-sub"
				<?php echo ( $auth['enable_custom_login_url'] ?? true ) ? '' : 'style="display:none;"'; ?>
			>
				<div class="skmt-form__group">
					<label for="custom_login_url" class="skmt-form__label">
						<?php esc_html_e( 'Slug personnalisé', 'studio-kyne-mini-tools' ); ?>
						<?php
						// Le filet de sécurité est vital mais secondaire : il n'a pas à
						// occuper une ligne sous chaque champ tant que rien n'est perdu.
						echo $this->render_help_tip( __( "Slug oublié ? Poser define( 'SKMT_DISABLE_LOGIN_URL', true ); dans wp-config.php réactive wp-login.php.", 'studio-kyne-mini-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</label>
					<div class="skmt-custom-login-url">
						<span class="skmt-custom-login-url__base"><?php echo esc_html( trailingslashit( site_url() ) ); ?></span>
						<input
							type="text"
							id="custom_login_url"
							name="skmt_module_settings[custom_login_url]"
							class="skmt-input skmt-custom-login-url__input"
							value="<?php echo esc_attr( ltrim( $auth['custom_login_url'] ?? '/connexion', '/' ) ); ?>"
							placeholder="connexion"
						/>
					</div>
					<p class="skmt-form__help">
						<?php esc_html_e( 'Exemples : connexion, login, admin, etc.', 'studio-kyne-mini-tools' ); ?>
					</p>
					<?php if ( \StudioKyne\MiniTools\Modules\Security\Module::login_url_disabled() ) : ?>
						<p class="skmt-form__help">
							<strong><?php esc_html_e( 'Actuellement neutralisee par la constante SKMT_DISABLE_LOGIN_URL : wp-login.php reste accessible.', 'studio-kyne-mini-tools' ); ?></strong>
						</p>
					<?php endif; ?>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="skmt-tabs__panel" role="tabpanel" data-skmt-tabs-group="security" data-skmt-tab-panel="hardening" hidden>

	<!-- ============================================================
		HARDENING
		============================================================ -->
	<div class="skmt-section">
		<div class="skmt-section__header">
			<h2 class="skmt-section__title"><?php esc_html_e( 'Hardening', 'studio-kyne-mini-tools' ); ?></h2>
			<p class="skmt-section__desc"><?php esc_html_e( 'Renforcez la sécurité du site en limitant l\'exposition.', 'studio-kyne-mini-tools' ); ?></p>
		</div>
		<div class="skmt-section__content">

			<!-- Disable XML-RPC -->
			<div class="skmt-option">
				<div class="skmt-option__content">
					<label for="skmt_disable_xmlrpc" class="skmt-option__label">
						<?php esc_html_e( 'Désactiver XML-RPC.', 'studio-kyne-mini-tools' ); ?>
						<?php echo $this->render_help_tip( __( "XML-RPC sert encore à l'application mobile WordPress, à Jetpack et aux pingbacks : coupez-le seulement si rien ne s'y connecte.", 'studio-kyne-mini-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="skmt-option__desc">
						<?php esc_html_e( 'Désactive l\'API XML-RPC', 'studio-kyne-mini-tools' ); ?>
					</p>
				</div>
				<div class="skmt-option__control">
					<label class="skmt-toggle">
						<input
							type="checkbox"
							id="skmt_disable_xmlrpc"
							name="skmt_module_settings[disable_xmlrpc]"
							value="1"
							data-security-toggle="disable_xmlrpc"
							<?php checked( $hardening['disable_xmlrpc'] ?? false ); ?>
						/>
						<span class="skmt-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Prevent User Enumeration -->
			<div class="skmt-option">
				<div class="skmt-option__content">
					<label for="skmt_prevent_user_enum" class="skmt-option__label">
						<?php esc_html_e( 'Empêcher l\'énumération des utilisateurs', 'studio-kyne-mini-tools' ); ?>
						<?php echo $this->render_help_tip( __( "Ferme les deux voies les plus utilisées, pas toutes : le formulaire de connexion distingue toujours un identifiant inconnu d'un mot de passe erroné.", 'studio-kyne-mini-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="skmt-option__desc">
						<?php esc_html_e( 'Bloque les requêtes ?author= et l\'accès REST aux utilisateurs.', 'studio-kyne-mini-tools' ); ?>
					</p>
				</div>
				<div class="skmt-option__control">
					<label class="skmt-toggle">
						<input
							type="checkbox"
							id="skmt_prevent_user_enum"
							name="skmt_module_settings[prevent_user_enum]"
							value="1"
							data-security-toggle="prevent_user_enum"
							<?php checked( $hardening['prevent_user_enum'] ?? true ); ?>
						/>
						<span class="skmt-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide WordPress Version -->
			<div class="skmt-option">
				<div class="skmt-option__content">
					<label for="skmt_hide_wp_version" class="skmt-option__label">
						<?php esc_html_e( 'Masquer la version WordPress', 'studio-kyne-mini-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Cosmétique : la version reste déductible des fichiers du cœur et des scripts versionnés. Utile contre les scans automatisés les plus simples, pas contre un examen manuel.', 'studio-kyne-mini-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="skmt-option__desc">
						<?php esc_html_e( 'Retire la version WordPress des headers HTTP et du meta generator.', 'studio-kyne-mini-tools' ); ?>
					</p>
				</div>
				<div class="skmt-option__control">
					<label class="skmt-toggle">
						<input
							type="checkbox"
							id="skmt_hide_wp_version"
							name="skmt_module_settings[hide_wp_version]"
							value="1"
							data-security-toggle="hide_wp_version"
							<?php checked( $hardening['hide_wp_version'] ?? true ); ?>
						/>
						<span class="skmt-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .skmt-module-form__scroll -->

</form>
