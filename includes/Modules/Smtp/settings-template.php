<?php
/**
 * SMTP module screen: server, sender, test, email log.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (smtp)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\Smtp\Crypto;
use Lumia\Tools\Modules\Smtp\Mailer;
use Lumia\Tools\Modules\Smtp\Providers;

$smtp_user_const = Compat::has_constant( 'SMTP_USER' );
$smtp_pass_const = Compat::has_constant( 'SMTP_PASSWORD' );
$smtp_has_pass   = $smtp_pass_const || '' !== (string) get_option( Mailer::PASSWORD_OPTION, '' );
$smtp_pass_ok    = null !== Mailer::password();
$smtp_override   = Mailer::wp_mail_override();
$smtp_mailer     = new Mailer( $module_settings );
$smtp_ready      = $smtp_mailer->smtp_ready();
$smtp_brevo      = $smtp_mailer->brevo_ready();
$smtp_transport  = Mailer::transport( $module_settings );
$smtp_key_const  = Compat::has_constant( 'BREVO_API_KEY' );
$smtp_has_key    = Mailer::has_brevo_key();
$smtp_key_ok     = null !== Mailer::brevo_key();
$smtp_encryption = (string) $module_settings['encryption'];
$smtp_admin_mail = (string) wp_get_current_user()->user_email;
$smtp_providers  = Providers::all();
$smtp_provider   = (string) $module_settings['provider'];
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="smtp" aria-label="<?php esc_attr_e( 'SMTP module sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Settings', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="test"><?php esc_html_e( 'Test', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="log"><?php esc_html_e( 'Log', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<?php if ( '' !== $smtp_override ) : ?>
		<div class="lumia-notice lumia-notice--error">
			<?php
			/* translators: %s: path of the file that redefines wp_mail(). */
			echo esc_html( sprintf( __( 'Another plugin replaces the WordPress sending function (%s). The settings below may not apply: deactivate the other SMTP plugin.', 'lumia-tools' ), $smtp_override ) );
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! Crypto::available() && ! ( 'smtp' === $smtp_transport ? $smtp_pass_const : $smtp_key_const ) ) : ?>
		<div class="lumia-notice lumia-notice--error">
			<?php esc_html_e( 'The PHP OpenSSL extension is missing: the password and the API key cannot be encrypted and will not be saved. Define them in wp-config.php with the LUMIA_SMTP_PASSWORD and LUMIA_BREVO_API_KEY constants.', 'lumia-tools' ); ?>
		</div>
	<?php else : ?>
		<?php if ( ! $smtp_pass_ok ) : ?>
			<div class="lumia-notice lumia-notice--error">
				<?php esc_html_e( 'The saved password can no longer be decrypted (the wp-config.php keys changed, after a migration for example). Enter it again.', 'lumia-tools' ); ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $smtp_key_ok ) : ?>
			<div class="lumia-notice lumia-notice--error">
				<?php esc_html_e( 'The saved Brevo API key can no longer be decrypted (the wp-config.php keys changed, after a migration for example). Enter it again.', 'lumia-tools' ); ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="settings">

	<!-- ============================================================
		SENDING: SMTP SERVER OR API
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Sending', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				if ( $smtp_brevo ) {
					esc_html_e( 'Site emails are sent through the Brevo HTTP API.', 'lumia-tools' );
				} elseif ( $smtp_ready ) {
					/* translators: 1: SMTP host, 2: port. */
					echo esc_html( sprintf( __( 'Site emails are sent through %1$s, port %2$s.', 'lumia-tools' ), $module_settings['host'], $module_settings['port'] ) );
				} else {
					esc_html_e( 'Emails currently go out through the PHP mail() function, which many providers classify as spam. An authenticated SMTP server gets them through.', 'lumia-tools' );
				}
				?>
			</p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_enabled" class="lumia-option__label"><?php esc_html_e( 'Custom sending', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Without a host (SMTP) or a key (API), sending stays on mail() even when enabled.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_enabled" name="lumia_module_settings[smtp_enabled]" value="1" <?php checked( ! empty( $module_settings['smtp_enabled'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-form__group">
				<label for="lumia_sm_transport" class="lumia-form__label"><?php esc_html_e( 'Sending method', 'lumia-tools' ); ?></label>
				<select id="lumia_sm_transport" name="lumia_module_settings[transport]" class="lumia-select lumia-select--sm">
					<option value="smtp" <?php selected( $smtp_transport, 'smtp' ); ?>><?php esc_html_e( 'SMTP server', 'lumia-tools' ); ?></option>
					<option value="brevo" <?php selected( $smtp_transport, 'brevo' ); ?>><?php esc_html_e( 'Brevo API', 'lumia-tools' ); ?></option>
				</select>
				<p class="lumia-form__help"><?php esc_html_e( 'The API uses HTTPS: useful when the host blocks SMTP ports, and its errors are more explicit.', 'lumia-tools' ); ?></p>
			</div>

			<div class="lumia-form__group lumia-sm__api" id="lumia-sm-api-fields" <?php echo 'brevo' === $smtp_transport ? '' : 'hidden'; ?>>
				<label for="lumia_sm_brevo_key" class="lumia-form__label">
					<?php esc_html_e( 'Brevo API key', 'lumia-tools' ); ?>
					<?php
					$smtp_key_tip = $smtp_key_const
						? __( 'Set in wp-config.php by LUMIA_BREVO_API_KEY.', 'lumia-tools' )
						: __( 'Brevo › Settings › SMTP & API › API keys ("xkeysib-…" key, not the SMTP key). Encrypted in the database, never displayed or exported again. Leave empty to keep the saved key. To avoid storing it in the database, define LUMIA_BREVO_API_KEY in wp-config.php.', 'lumia-tools' );
					echo $this->render_help_tip( $smtp_key_tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</label>
				<input type="password" id="lumia_sm_brevo_key" name="lumia_module_settings[brevo_key]" class="lumia-input"
					value="" autocomplete="new-password" spellcheck="false"
					placeholder="<?php echo $smtp_has_key ? esc_attr__( 'Saved — leave empty to keep the key', 'lumia-tools' ) : 'xkeysib-…'; ?>"
					<?php disabled( $smtp_key_const ); ?>>
				<p class="lumia-form__help"><?php esc_html_e( 'The sender address must belong to a sender or domain verified in Brevo.', 'lumia-tools' ); ?></p>
			</div>

			<div id="lumia-sm-smtp-fields" <?php echo 'smtp' === $smtp_transport ? '' : 'hidden'; ?>>

			<div class="lumia-form__group lumia-sm__provider">
				<label for="lumia_sm_provider" class="lumia-form__label"><?php esc_html_e( 'Provider', 'lumia-tools' ); ?></label>
				<select id="lumia_sm_provider" name="lumia_module_settings[provider]" class="lumia-select lumia-select--sm">
					<option value="<?php echo esc_attr( Providers::CUSTOM ); ?>" <?php selected( $smtp_provider, Providers::CUSTOM ); ?>><?php esc_html_e( 'Custom server', 'lumia-tools' ); ?></option>
					<?php foreach ( $smtp_providers as $provider_key => $provider ) : ?>
						<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $smtp_provider, $provider_key ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="lumia-form__help" id="lumia-sm-provider-hint"><?php echo esc_html( $smtp_providers[ $smtp_provider ]['hint'] ?? __( 'Choosing a provider pre-fills the host, port and encryption; everything stays editable.', 'lumia-tools' ) ); ?></p>
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_host" class="lumia-form__label"><?php esc_html_e( 'Host', 'lumia-tools' ); ?></label>
					<input type="text" id="lumia_sm_host" name="lumia_module_settings[host]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['host'] ); ?>" placeholder="smtp.example.com" autocomplete="off" spellcheck="false">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_sm_encryption" class="lumia-form__label"><?php esc_html_e( 'Encryption', 'lumia-tools' ); ?></label>
					<select id="lumia_sm_encryption" name="lumia_module_settings[encryption]" class="lumia-select lumia-select--sm">
						<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>><?php esc_html_e( 'STARTTLS (port 587)', 'lumia-tools' ); ?></option>
						<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS (port 465)', 'lumia-tools' ); ?></option>
						<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None (port 25)', 'lumia-tools' ); ?></option>
					</select>
				</div>
				<div class="lumia-form__group lumia-sm__port">
					<label for="lumia_sm_port" class="lumia-form__label"><?php esc_html_e( 'Port', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_sm_port" name="lumia_module_settings[port]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['port'] ); ?>" min="1" max="65535">
				</div>
			</div>

			<div class="lumia-option" id="lumia-sm-autotls-row" <?php echo 'none' === $smtp_encryption ? '' : 'hidden'; ?>>
				<div class="lumia-option__content">
					<label for="lumia_sm_auto_tls" class="lumia-option__label"><?php esc_html_e( 'Automatic TLS', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Switches to STARTTLS if the server offers it. Turn off only for a server with an invalid certificate.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_auto_tls" name="lumia_module_settings[auto_tls]" value="1" <?php checked( ! empty( $module_settings['auto_tls'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_auth" class="lumia-option__label"><?php esc_html_e( 'Authentication', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Almost all servers require it.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_auth" name="lumia_module_settings[auth]" value="1" <?php checked( ! empty( $module_settings['auth'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-form__row" id="lumia-sm-credentials" <?php echo empty( $module_settings['auth'] ) ? 'hidden' : ''; ?>>
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_username" class="lumia-form__label">
						<?php esc_html_e( 'Username', 'lumia-tools' ); ?>
						<?php if ( $smtp_user_const ) : ?>
							<?php echo $this->render_help_tip( __( 'Set in wp-config.php by LUMIA_SMTP_USER.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</label>
					<input type="text" id="lumia_sm_username" name="lumia_module_settings[username]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $smtp_user_const ? (string) Compat::constant( 'SMTP_USER' ) : (string) $module_settings['username'] ); ?>"
						autocomplete="off" spellcheck="false" <?php disabled( $smtp_user_const ); ?>>
				</div>
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_password" class="lumia-form__label">
						<?php esc_html_e( 'Password', 'lumia-tools' ); ?>
						<?php
						$smtp_pass_tip = $smtp_pass_const
							? __( 'Set in wp-config.php by LUMIA_SMTP_PASSWORD.', 'lumia-tools' )
							: __( 'Encrypted in the database with the wp-config.php keys, never displayed or exported again. Leave empty to keep the saved password. To avoid storing it in the database, define LUMIA_SMTP_PASSWORD in wp-config.php.', 'lumia-tools' );
						echo $this->render_help_tip( $smtp_pass_tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</label>
					<input type="password" id="lumia_sm_password" name="lumia_module_settings[password]" class="lumia-input lumia-input--sm"
						value="" autocomplete="new-password"
						placeholder="<?php echo $smtp_has_pass ? esc_attr__( 'Saved — leave empty to keep the password', 'lumia-tools' ) : ''; ?>"
						<?php disabled( $smtp_pass_const ); ?>>
				</div>
			</div>

			</div><!-- #lumia-sm-smtp-fields -->
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		SENDER
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Sender', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				/* translators: %s: default WordPress sender address. */
				echo esc_html( sprintf( __( 'Replaces the default WordPress sender (%s). Use an address on the domain authorized by the SMTP server or verified in Brevo, otherwise emails fail the SPF/DMARC check.', 'lumia-tools' ), Mailer::wp_default_from_email() ) );
				?>
			</p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_from_email" class="lumia-form__label"><?php esc_html_e( 'Sender address', 'lumia-tools' ); ?></label>
					<input type="email" id="lumia_sm_from_email" name="lumia_module_settings[from_email]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['from_email'] ); ?>" placeholder="contact@example.com">
				</div>
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_from_name" class="lumia-form__label"><?php esc_html_e( 'Sender name', 'lumia-tools' ); ?></label>
					<input type="text" id="lumia_sm_from_name" name="lumia_module_settings[from_name]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_force_email" class="lumia-option__label"><?php esc_html_e( 'Force sender address', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Also replaces the address chosen by a plugin (contact form, shop). Without this, only the default WordPress address is replaced.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_force_email" name="lumia_module_settings[force_from_email]" value="1" <?php checked( ! empty( $module_settings['force_from_email'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_force_name" class="lumia-option__label"><?php esc_html_e( 'Force sender name', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Without this, only the name "WordPress" is replaced.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_force_name" name="lumia_module_settings[force_from_name]" value="1" <?php checked( ! empty( $module_settings['force_from_name'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_return_path" class="lumia-option__label">
						<?php esc_html_e( 'Return-Path on the sender address', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Envelope address: this is what SPF checks. Even when a plugin keeps its own sender, the envelope stays on the address configured above, which the SMTP server accepts.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Bounce notices are delivered to the configured sender address.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_return_path" name="lumia_module_settings[set_return_path]" value="1" <?php checked( ! empty( $module_settings['set_return_path'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	</div><!-- Settings panel -->

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="test" hidden>

	<!-- ============================================================
		TEST EMAIL
		Field without a name attribute: it is not submitted with the settings.
		type="text" and not "email": the browser validates an email field even
		without a name, and an incomplete address here blocked "Save".
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Test email', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Sends an email with the saved settings: save your changes first. On failure, the exchange with the SMTP server (credentials masked) or the API response is displayed.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-sm__test">
				<input type="text" inputmode="email" autocomplete="email" spellcheck="false" id="lumia-sm-test-to" class="lumia-input lumia-input--sm" value="<?php echo esc_attr( $smtp_admin_mail ); ?>"
					aria-label="<?php esc_attr_e( 'Test email recipient', 'lumia-tools' ); ?>">
				<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-sm-test-send"><?php esc_html_e( 'Send a test email', 'lumia-tools' ); ?></button>
			</div>
			<div class="lumia-sm__test-result" id="lumia-sm-test-result" hidden>
				<p class="lumia-notice" id="lumia-sm-test-message"></p>
				<pre class="lumia-sm__transcript" id="lumia-sm-test-transcript" hidden></pre>
			</div>
		</div>
	</div>

	</div><!-- Test panel -->

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="log" hidden>

	<!-- ============================================================
		LOG
		Filters without a name attribute: they are not submitted with the settings.
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Email log', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				if ( empty( $module_settings['log_enabled'] ) ) {
					esc_html_e( 'Logging is off: sent emails are no longer recorded. Emails already logged can still be viewed.', 'lumia-tools' );
				} else {
					esc_html_e( 'Every email sent by the site, successful or not. Click a row to view the message and resend it.', 'lumia-tools' );
				}
				?>
			</p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-sm" id="lumia-sm" data-nonce="<?php echo esc_attr( wp_create_nonce( 'lumia_admin_nonce' ) ); ?>">

				<div class="lumia-sm__filters">
					<div class="lumia-search lumia-search--sm lumia-sm__search">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
						<input type="search" class="lumia-search__input" id="lumia-sm-search"
							placeholder="<?php esc_attr_e( 'Subject, recipient, sender…', 'lumia-tools' ); ?>"
							aria-label="<?php esc_attr_e( 'Search the email log', 'lumia-tools' ); ?>">
					</div>

					<select class="lumia-select lumia-select--sm" id="lumia-sm-status" aria-label="<?php esc_attr_e( 'Status', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'All statuses', 'lumia-tools' ); ?></option>
						<option value="sent"><?php esc_html_e( 'Sent emails', 'lumia-tools' ); ?></option>
						<option value="failed"><?php esc_html_e( 'Failed emails', 'lumia-tools' ); ?></option>
					</select>

					<input type="date" class="lumia-input lumia-input--sm" id="lumia-sm-from" aria-label="<?php esc_attr_e( 'Since', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Since', 'lumia-tools' ); ?>">
					<input type="date" class="lumia-input lumia-input--sm" id="lumia-sm-to" aria-label="<?php esc_attr_e( 'Until', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Until', 'lumia-tools' ); ?>">

					<div class="lumia-sm__filter-actions">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-reset"><?php esc_html_e( 'Reset', 'lumia-tools' ); ?></button>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-sm-clear"><?php esc_html_e( 'Clear log', 'lumia-tools' ); ?></button>
					</div>
				</div>

				<div class="lumia-sm__table-wrap">
					<table class="lumia-sm__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Recipient', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Subject', 'lumia-tools' ); ?></th>
							</tr>
						</thead>
						<tbody id="lumia-sm-rows">
							<tr><td colspan="4" class="lumia-sm__state"><?php esc_html_e( 'Loading…', 'lumia-tools' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="lumia-sm__footer">
					<span id="lumia-sm-total"></span>
					<div class="lumia-sm__pager">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-prev" disabled aria-label="<?php esc_attr_e( 'Previous page', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Previous page', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
						</button>
						<span id="lumia-sm-page"></span>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-next" disabled aria-label="<?php esc_attr_e( 'Next page', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Next page', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		RETENTION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Retention', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'The log keeps the full content of emails, password reset links included: do not keep it longer than necessary. A daily purge deletes emails that are too old, then the oldest ones beyond the cap.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_log_enabled" class="lumia-option__label"><?php esc_html_e( 'Log emails', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Records every email sent by the site, with its status and any error.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_log_enabled" name="lumia_module_settings[log_enabled]" value="1" <?php checked( ! empty( $module_settings['log_enabled'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_sm_retention_days" class="lumia-form__label"><?php esc_html_e( 'Retention period (days)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_sm_retention_days" name="lumia_module_settings[log_retention_days]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['log_retention_days'] ); ?>" min="1" max="3650">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_sm_max_rows" class="lumia-form__label"><?php esc_html_e( 'Maximum number of emails', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_sm_max_rows" name="lumia_module_settings[log_max_rows]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['log_max_rows'] ); ?>" min="100" max="1000000" step="100">
				</div>
			</div>
		</div>
	</div>

	</div><!-- Log panel -->

	</div><!-- .lumia-module-form__scroll -->

</form>

<!-- DETAIL MODAL (content generated in JS) -->
<div class="lumia-modal-overlay" id="lumia-sm-detail-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-sm-detail-title">
	<div class="lumia-modal lumia-modal--lg">
		<div class="lumia-modal__header">
			<h3 id="lumia-sm-detail-title" class="lumia-modal__title"></h3>
		</div>
		<div class="lumia-modal__body">
			<dl class="lumia-sm__detail" id="lumia-sm-detail-meta"></dl>
			<p class="lumia-notice lumia-notice--error" id="lumia-sm-detail-error" hidden></p>
			<!-- empty sandbox: no script, no form, not even same origin. An
				email body comes from anyone (contact form). -->
			<iframe class="lumia-sm__preview" id="lumia-sm-detail-html" sandbox="" referrerpolicy="no-referrer" title="<?php esc_attr_e( 'Message preview', 'lumia-tools' ); ?>" hidden></iframe>
			<pre class="lumia-sm__preview lumia-sm__preview--text" id="lumia-sm-detail-text" hidden></pre>
			<details class="lumia-sm__headers" id="lumia-sm-detail-headers-wrap" hidden>
				<summary><?php esc_html_e( 'Headers sent', 'lumia-tools' ); ?></summary>
				<pre id="lumia-sm-detail-headers"></pre>
			</details>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Close', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-sm-detail-resend"><?php esc_html_e( 'Resend', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
