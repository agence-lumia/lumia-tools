<?php
/**
 * Settings template of the Security module.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (security)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Modules\Security\ClientIp;

$auth      = $module_settings['authentication'] ?? [];
$hardening = $module_settings['hardening'] ?? [];

$ip_source = ClientIp::sanitize_source( $auth['ip_source'] ?? '' );

// Headers actually present on THIS request: informs the administrator
// without ever deciding for them (their presence is what an attacker controls).
$detected_headers = ClientIp::detected_headers();

$ip_sources = [
	ClientIp::SOURCE_REMOTE_ADDR => __( 'No proxy — direct connection address (recommended)', 'lumia-tools' ),
	ClientIp::SOURCE_CLOUDFLARE  => __( 'Cloudflare — CF-Connecting-IP header', 'lumia-tools' ),
	ClientIp::SOURCE_FORWARDED   => __( 'Reverse proxy — X-Forwarded-For header', 'lumia-tools' ),
];
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="security" aria-label="<?php esc_attr_e( 'Security module sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="auth"><?php esc_html_e( 'Authentication', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="hardening"><?php esc_html_e( 'Hardening', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="security" data-lumia-tab-panel="auth">

	<!-- ============================================================
		AUTHENTICATION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Authentication', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Manage access and login rules.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Rate Limiting -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_rate_limiting" class="lumia-option__label">
						<?php esc_html_e( 'Limit login attempts', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Block IPs after too many failed attempts.', 'lumia-tools' ); ?>
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

			<!-- Rate Limiting Options (conditional sub-options) -->
			<div
				data-depends-on="rate_limiting"
				class="lumia-security-sub"
				<?php echo ( $auth['rate_limiting'] ?? true ) ? '' : 'style="display:none;"'; ?>
			>
				<div class="lumia-form__row">
					<div class="lumia-form__group">
						<label for="rate_limit_attempts" class="lumia-form__label">
							<?php esc_html_e( 'Allowed attempts', 'lumia-tools' ); ?>
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
							<?php esc_html_e( 'Window (seconds)', 'lumia-tools' ); ?>
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
							<?php esc_html_e( 'Lockout (seconds)', 'lumia-tools' ); ?>
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
						<?php esc_html_e( 'Whitelisted IPs (one per line)', 'lumia-tools' ); ?>
					</label>
					<textarea
						id="rate_limit_whitelist"
						name="lumia_module_settings[rate_limit_whitelist]"
						class="lumia-input lumia-security-sub__textarea"
						rows="4"
						placeholder="192.168.1.1&#10;127.0.0.1"
					><?php echo esc_textarea( implode( "\n", $auth['rate_limit_whitelist'] ?? [] ) ); ?></textarea>
					<p class="lumia-form__help"><?php esc_html_e( 'These IPs will never be blocked by rate limiting.', 'lumia-tools' ); ?></p>
				</div>

				<div class="lumia-form__group">
					<label for="lumia_ip_source" class="lumia-form__label">
						<?php esc_html_e( 'IP address source', 'lumia-tools' ); ?>
					</label>
					<select id="lumia_ip_source" name="lumia_module_settings[ip_source]" class="lumia-select">
						<?php foreach ( $ip_sources as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $ip_source, $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="lumia-form__help">
						<?php esc_html_e( 'How to identify a visitor to count their attempts. Proxy headers are sent by the client: only enable them if the site is really behind that proxy, otherwise the lockout can be bypassed by simply changing the header.', 'lumia-tools' ); ?>
					</p>
					<?php if ( $detected_headers ) : ?>
						<p class="lumia-form__help">
							<strong><?php esc_html_e( 'Detected on this request:', 'lumia-tools' ); ?></strong>
							<?php echo esc_html( implode( ', ', array_keys( $detected_headers ) ) ); ?>.
							<?php esc_html_e( 'Their presence does not prove that a proxy set them.', 'lumia-tools' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Custom Login URL Toggle + Input -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_enable_custom_login_url" class="lumia-option__label">
						<?php esc_html_e( 'Custom login URL', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Replaces /wp-login.php with a custom URL and blocks access to the standard login page.', 'lumia-tools' ); ?>
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
						<?php esc_html_e( 'Custom slug', 'lumia-tools' ); ?>
						<?php
						// The safety net is vital but secondary: it does not need to take
						// up a line under every field as long as nothing is lost.
						echo $this->render_help_tip( __( "Forgot the slug? Adding define( 'LUMIA_DISABLE_LOGIN_URL', true ); to wp-config.php re-enables wp-login.php.", 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
						<?php esc_html_e( 'Examples: login, signin, admin, etc.', 'lumia-tools' ); ?>
					</p>
					<?php if ( \Lumia\Tools\Modules\Security\Module::login_url_disabled() ) : ?>
						<p class="lumia-form__help">
							<strong><?php esc_html_e( 'Currently disabled by the LUMIA_DISABLE_LOGIN_URL constant: wp-login.php stays accessible.', 'lumia-tools' ); ?></strong>
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
			<p class="lumia-section__desc"><?php esc_html_e( 'Strengthen the site security by limiting its exposure.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Disable XML-RPC -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_disable_xmlrpc" class="lumia-option__label">
						<?php esc_html_e( 'Disable XML-RPC.', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'XML-RPC is still used by the WordPress mobile app, Jetpack and pingbacks: only turn it off if nothing connects to it.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Disables the XML-RPC API', 'lumia-tools' ); ?>
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
						<?php esc_html_e( 'Prevent user enumeration', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Closes the two most used paths, not all of them: the login form still tells an unknown username from a wrong password.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Blocks ?author= requests and REST access to users.', 'lumia-tools' ); ?>
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
						<?php esc_html_e( 'Hide the WordPress version', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Cosmetic: the version can still be deduced from core files and versioned scripts. Useful against the simplest automated scans, not against a manual inspection.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Removes the WordPress version from the HTTP headers and the generator meta tag.', 'lumia-tools' ); ?>
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
