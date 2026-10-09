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

use Lumia\Tools\Modules\Security\ClientIp;

$auth      = $module_settings['authentication'] ?? [];
$hardening = $module_settings['hardening'] ?? [];

$ip_source = ClientIp::sanitize_source( $auth['ip_source'] ?? '' );

// En-têtes réellement présents sur CETTE requête : informe l'administrateur
// sans jamais décider à sa place (leur présence est ce qu'un attaquant contrôle).
$detected_headers = ClientIp::detected_headers();

$ip_sources = [
	ClientIp::SOURCE_REMOTE_ADDR => __( 'Aucun proxy — adresse de connexion directe (recommandé)', 'lumia-tools' ),
	ClientIp::SOURCE_CLOUDFLARE  => __( 'Cloudflare — en-tête CF-Connecting-IP', 'lumia-tools' ),
	ClientIp::SOURCE_FORWARDED   => __( 'Reverse proxy — en-tête X-Forwarded-For', 'lumia-tools' ),
];
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="security" aria-label="<?php esc_attr_e( 'Sections du module Sécurité', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="auth"><?php esc_html_e( 'Authentification', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="hardening"><?php esc_html_e( 'Hardening', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="security" data-lumia-tab-panel="auth">

	<!-- ============================================================
		AUTHENTIFICATION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Authentification', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Gérez les règles d\'accès et de connexion.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Rate Limiting -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_rate_limiting" class="lumia-option__label">
						<?php esc_html_e( 'Limiter les tentatives de connexion', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Bloquer les IPs après trop de tentatives échouées.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_rate_limiting"
							name="lumia_module_settings[rate_limiting]"
							value="1"
							data-security-toggle="rate_limiting"
							<?php checked( $auth['rate_limiting'] ?? true ); ?>
						/>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Rate Limiting Options (sous-options conditionnelles) -->
			<div
				data-depends-on="rate_limiting"
				class="lumia-security-sub"
				<?php echo ( $auth['rate_limiting'] ?? true ) ? '' : 'style="display:none;"'; ?>
			>
				<div class="lumia-form__row">
					<div class="lumia-form__group">
						<label for="rate_limit_attempts" class="lumia-form__label">
							<?php esc_html_e( 'Tentatives autorisées', 'lumia-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_attempts"
							name="lumia_module_settings[rate_limit_attempts]"
							class="lumia-input lumia-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_attempts'] ?? 5 ); ?>"
							min="1"
							max="20"
						/>
					</div>

					<div class="lumia-form__group">
						<label for="rate_limit_window" class="lumia-form__label">
							<?php esc_html_e( 'Fenêtre (secondes)', 'lumia-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_window"
							name="lumia_module_settings[rate_limit_window]"
							class="lumia-input lumia-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_window'] ?? 900 ); ?>"
							min="60"
							step="60"
							data-seconds-field="rate_limit_window_help"
						/>
						<p class="lumia-form__help"><span id="rate_limit_window_help"></span></p>
					</div>

					<div class="lumia-form__group">
						<label for="rate_limit_lockout" class="lumia-form__label">
							<?php esc_html_e( 'Blocage (secondes)', 'lumia-tools' ); ?>
						</label>
						<input
							type="number"
							id="rate_limit_lockout"
							name="lumia_module_settings[rate_limit_lockout]"
							class="lumia-input lumia-input--sm"
							value="<?php echo esc_attr( $auth['rate_limit_lockout'] ?? 1800 ); ?>"
							min="60"
							step="60"
							data-seconds-field="rate_limit_lockout_help"
						/>
						<p class="lumia-form__help"><span id="rate_limit_lockout_help"></span></p>
					</div>
				</div>

				<div class="lumia-form__group">
					<label for="rate_limit_whitelist" class="lumia-form__label">
						<?php esc_html_e( 'IP whitelistées (une par ligne)', 'lumia-tools' ); ?>
					</label>
					<textarea
						id="rate_limit_whitelist"
						name="lumia_module_settings[rate_limit_whitelist]"
						class="lumia-input lumia-security-sub__textarea"
						rows="4"
						placeholder="192.168.1.1&#10;127.0.0.1"
					><?php echo esc_textarea( implode( "\n", $auth['rate_limit_whitelist'] ?? [] ) ); ?></textarea>
					<p class="lumia-form__help"><?php esc_html_e( 'Ces IPs ne seront jamais bloquées par le rate limiting.', 'lumia-tools' ); ?></p>
				</div>

				<div class="lumia-form__group">
					<label for="lumia_ip_source" class="lumia-form__label">
						<?php esc_html_e( 'Origine de l’adresse IP', 'lumia-tools' ); ?>
					</label>
					<select id="lumia_ip_source" name="lumia_module_settings[ip_source]" class="lumia-select">
						<?php foreach ( $ip_sources as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $ip_source, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="lumia-form__help">
						<?php esc_html_e( 'Comment identifier un visiteur pour compter ses tentatives. Les en-têtes de proxy sont envoyés par le client : ne les activez que si le site est réellement derrière ce proxy, sinon le blocage se contourne en changeant simplement d’en-tête.', 'lumia-tools' ); ?>
					</p>
					<?php if ( $detected_headers ) : ?>
						<p class="lumia-form__help">
							<strong><?php esc_html_e( 'Détecté sur cette requête :', 'lumia-tools' ); ?></strong>
							<?php echo esc_html( implode( ', ', array_keys( $detected_headers ) ) ); ?>.
							<?php esc_html_e( 'Leur présence ne prouve pas qu’un proxy les a posés.', 'lumia-tools' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Custom Login URL Toggle + Input -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_enable_custom_login_url" class="lumia-option__label">
						<?php esc_html_e( 'URL personnalisée de connexion', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Remplace /wp-login.php par une URL personnalisée et bloque l\'accès à la page de connexion standard.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_enable_custom_login_url"
							name="lumia_module_settings[enable_custom_login_url]"
							value="1"
							data-security-toggle="enable_custom_login_url"
							<?php checked( $auth['enable_custom_login_url'] ?? true ); ?>
						/>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Custom Login URL Input (Conditional) -->
			<div
				data-depends-on="enable_custom_login_url"
				class="lumia-security-sub"
				<?php echo ( $auth['enable_custom_login_url'] ?? true ) ? '' : 'style="display:none;"'; ?>
			>
				<div class="lumia-form__group">
					<label for="custom_login_url" class="lumia-form__label">
						<?php esc_html_e( 'Slug personnalisé', 'lumia-tools' ); ?>
						<?php
						// Le filet de sécurité est vital mais secondaire : il n'a pas à
						// occuper une ligne sous chaque champ tant que rien n'est perdu.
						echo $this->render_help_tip( __( "Slug oublié ? Poser define( 'LUMIA_DISABLE_LOGIN_URL', true ); dans wp-config.php réactive wp-login.php.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</label>
					<div class="lumia-custom-login-url">
						<span class="lumia-custom-login-url__base"><?php echo esc_html( trailingslashit( site_url() ) ); ?></span>
						<input
							type="text"
							id="custom_login_url"
							name="lumia_module_settings[custom_login_url]"
							class="lumia-input lumia-custom-login-url__input"
							value="<?php echo esc_attr( ltrim( $auth['custom_login_url'] ?? '/connexion', '/' ) ); ?>"
							placeholder="connexion"
						/>
					</div>
					<p class="lumia-form__help">
						<?php esc_html_e( 'Exemples : connexion, login, admin, etc.', 'lumia-tools' ); ?>
					</p>
					<?php if ( \Lumia\Tools\Modules\Security\Module::login_url_disabled() ) : ?>
						<p class="lumia-form__help">
							<strong><?php esc_html_e( 'Actuellement neutralisee par la constante LUMIA_DISABLE_LOGIN_URL : wp-login.php reste accessible.', 'lumia-tools' ); ?></strong>
						</p>
					<?php endif; ?>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="security" data-lumia-tab-panel="hardening" hidden>

	<!-- ============================================================
		HARDENING
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Hardening', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Renforcez la sécurité du site en limitant l\'exposition.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Disable XML-RPC -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_disable_xmlrpc" class="lumia-option__label">
						<?php esc_html_e( 'Désactiver XML-RPC.', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( "XML-RPC sert encore à l'application mobile WordPress, à Jetpack et aux pingbacks : coupez-le seulement si rien ne s'y connecte.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Désactive l\'API XML-RPC', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_disable_xmlrpc"
							name="lumia_module_settings[disable_xmlrpc]"
							value="1"
							data-security-toggle="disable_xmlrpc"
							<?php checked( $hardening['disable_xmlrpc'] ?? false ); ?>
						/>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Prevent User Enumeration -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_prevent_user_enum" class="lumia-option__label">
						<?php esc_html_e( 'Empêcher l\'énumération des utilisateurs', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( "Ferme les deux voies les plus utilisées, pas toutes : le formulaire de connexion distingue toujours un identifiant inconnu d'un mot de passe erroné.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Bloque les requêtes ?author= et l\'accès REST aux utilisateurs.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_prevent_user_enum"
							name="lumia_module_settings[prevent_user_enum]"
							value="1"
							data-security-toggle="prevent_user_enum"
							<?php checked( $hardening['prevent_user_enum'] ?? true ); ?>
						/>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide WordPress Version -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_wp_version" class="lumia-option__label">
						<?php esc_html_e( 'Masquer la version WordPress', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Cosmétique : la version reste déductible des fichiers du cœur et des scripts versionnés. Utile contre les scans automatisés les plus simples, pas contre un examen manuel.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Retire la version WordPress des headers HTTP et du meta generator.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_wp_version"
							name="lumia_module_settings[hide_wp_version]"
							value="1"
							data-security-toggle="hide_wp_version"
							<?php checked( $hardening['hide_wp_version'] ?? true ); ?>
						/>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
