<?php
/**
 * Écran du module SMTP : serveur, expéditeur, test, journal des mails.
 *
 * Variables disponibles (via module-settings.php):
 * @var string          $module_id       ID du module (smtp)
 * @var array           $module          Infos du module
 * @var ModuleInterface $instance        Instance du module
 * @var array           $module_settings Settings actuels
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Lumia\Tools\Modules\Smtp\Crypto;
use Lumia\Tools\Modules\Smtp\Mailer;
use Lumia\Tools\Modules\Smtp\Providers;

$smtp_user_const = defined( 'LUMIA_SMTP_USER' );
$smtp_pass_const = defined( 'LUMIA_SMTP_PASSWORD' );
$smtp_has_pass   = $smtp_pass_const || '' !== (string) get_option( Mailer::PASSWORD_OPTION, '' );
$smtp_pass_ok    = null !== Mailer::password();
$smtp_override   = Mailer::wp_mail_override();
$smtp_mailer     = new Mailer( $module_settings );
$smtp_ready      = $smtp_mailer->smtp_ready();
$smtp_brevo      = $smtp_mailer->brevo_ready();
$smtp_transport  = Mailer::transport( $module_settings );
$smtp_key_const  = defined( 'LUMIA_BREVO_API_KEY' );
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

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="smtp" aria-label="<?php esc_attr_e( 'Sections du module SMTP', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Réglages', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="test"><?php esc_html_e( 'Test', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="log"><?php esc_html_e( 'Journal', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<?php if ( '' !== $smtp_override ) : ?>
		<div class="lumia-notice lumia-notice--error">
			<?php
			/* translators: %s: chemin du fichier qui redéfinit wp_mail(). */
			echo esc_html( sprintf( __( 'Une autre extension remplace la fonction d\'envoi de WordPress (%s). Les réglages ci-dessous risquent de ne pas s\'appliquer : désactivez l\'autre extension SMTP.', 'lumia-tools' ), $smtp_override ) );
			?>
		</div>
	<?php endif; ?>

	<?php if ( ! Crypto::available() && ! ( 'smtp' === $smtp_transport ? $smtp_pass_const : $smtp_key_const ) ) : ?>
		<div class="lumia-notice lumia-notice--error">
			<?php esc_html_e( 'L\'extension PHP OpenSSL est absente : le mot de passe et la clé API ne peuvent pas être chiffrés et ne seront pas enregistrés. Définissez-les dans wp-config.php avec les constantes LUMIA_SMTP_PASSWORD et LUMIA_BREVO_API_KEY.', 'lumia-tools' ); ?>
		</div>
	<?php else : ?>
		<?php if ( ! $smtp_pass_ok ) : ?>
			<div class="lumia-notice lumia-notice--error">
				<?php esc_html_e( 'Le mot de passe enregistré ne se déchiffre plus (les clés de wp-config.php ont changé, après une migration par exemple). Saisissez-le à nouveau.', 'lumia-tools' ); ?>
			</div>
		<?php endif; ?>
		<?php if ( ! $smtp_key_ok ) : ?>
			<div class="lumia-notice lumia-notice--error">
				<?php esc_html_e( 'La clé API Brevo enregistrée ne se déchiffre plus (les clés de wp-config.php ont changé, après une migration par exemple). Saisissez-la à nouveau.', 'lumia-tools' ); ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="settings">

	<!-- ============================================================
		ENVOI : SERVEUR SMTP OU API
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Envoi', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				if ( $smtp_brevo ) {
					esc_html_e( 'Les mails du site partent par l\'API HTTP de Brevo.', 'lumia-tools' );
				} elseif ( $smtp_ready ) {
					/* translators: 1: hôte SMTP, 2: port. */
					echo esc_html( sprintf( __( 'Les mails du site partent par %1$s, port %2$s.', 'lumia-tools' ), $module_settings['host'], $module_settings['port'] ) );
				} else {
					esc_html_e( 'Les mails partent aujourd\'hui par la fonction mail() de PHP, que beaucoup de fournisseurs classent en indésirable. Un serveur SMTP authentifié les fait passer.', 'lumia-tools' );
				}
				?>
			</p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_enabled" class="lumia-option__label"><?php esc_html_e( 'Envoi personnalisé', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Sans hôte (SMTP) ou sans clé (API), l\'envoi reste sur mail() même activé.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia_sm_enabled" name="lumia_module_settings[smtp_enabled]" value="1" <?php checked( ! empty( $module_settings['smtp_enabled'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-form__group">
				<label for="lumia_sm_transport" class="lumia-form__label"><?php esc_html_e( 'Méthode d\'envoi', 'lumia-tools' ); ?></label>
				<select id="lumia_sm_transport" name="lumia_module_settings[transport]" class="lumia-select lumia-select--sm">
					<option value="smtp" <?php selected( $smtp_transport, 'smtp' ); ?>><?php esc_html_e( 'Serveur SMTP', 'lumia-tools' ); ?></option>
					<option value="brevo" <?php selected( $smtp_transport, 'brevo' ); ?>><?php esc_html_e( 'API Brevo', 'lumia-tools' ); ?></option>
				</select>
				<p class="lumia-form__help"><?php esc_html_e( 'L\'API passe par HTTPS : utile quand l\'hébergeur bloque les ports SMTP, et ses erreurs sont plus parlantes.', 'lumia-tools' ); ?></p>
			</div>

			<div class="lumia-form__group lumia-sm__api" id="lumia-sm-api-fields" <?php echo 'brevo' === $smtp_transport ? '' : 'hidden'; ?>>
				<label for="lumia_sm_brevo_key" class="lumia-form__label">
					<?php esc_html_e( 'Clé API Brevo', 'lumia-tools' ); ?>
					<?php
					$smtp_key_tip = $smtp_key_const
						? __( 'Définie dans wp-config.php par LUMIA_BREVO_API_KEY.', 'lumia-tools' )
						: __( 'Brevo › Paramètres › SMTP & API › Clés API (clé « xkeysib-… », pas la clé SMTP). Chiffrée en base, jamais réaffichée ni exportée. Laissez vide pour garder la clé enregistrée. Pour ne pas la stocker en base, définissez LUMIA_BREVO_API_KEY dans wp-config.php.', 'lumia-tools' );
					echo $this->render_help_tip( $smtp_key_tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</label>
				<input type="password" id="lumia_sm_brevo_key" name="lumia_module_settings[brevo_key]" class="lumia-input"
					value="" autocomplete="new-password" spellcheck="false"
					placeholder="<?php echo $smtp_has_key ? esc_attr__( 'Enregistrée — laisser vide pour conserver', 'lumia-tools' ) : 'xkeysib-…'; ?>"
					<?php disabled( $smtp_key_const ); ?>>
				<p class="lumia-form__help"><?php esc_html_e( 'L\'adresse d\'expédition doit appartenir à un expéditeur ou à un domaine validé dans Brevo.', 'lumia-tools' ); ?></p>
			</div>

			<div id="lumia-sm-smtp-fields" <?php echo 'smtp' === $smtp_transport ? '' : 'hidden'; ?>>

			<div class="lumia-form__group lumia-sm__provider">
				<label for="lumia_sm_provider" class="lumia-form__label"><?php esc_html_e( 'Fournisseur', 'lumia-tools' ); ?></label>
				<select id="lumia_sm_provider" name="lumia_module_settings[provider]" class="lumia-select lumia-select--sm">
					<option value="<?php echo esc_attr( Providers::CUSTOM ); ?>" <?php selected( $smtp_provider, Providers::CUSTOM ); ?>><?php esc_html_e( 'Serveur personnalisé', 'lumia-tools' ); ?></option>
					<?php foreach ( $smtp_providers as $provider_key => $provider ) : ?>
						<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $smtp_provider, $provider_key ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="lumia-form__help" id="lumia-sm-provider-hint"><?php echo esc_html( $smtp_providers[ $smtp_provider ]['hint'] ?? __( 'Choisir un fournisseur pré-remplit l\'hôte, le port et le chiffrement ; tout reste modifiable.', 'lumia-tools' ) ); ?></p>
			</div>

			<div class="lumia-form__row">
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_host" class="lumia-form__label"><?php esc_html_e( 'Hôte', 'lumia-tools' ); ?></label>
					<input type="text" id="lumia_sm_host" name="lumia_module_settings[host]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['host'] ); ?>" placeholder="smtp.example.com" autocomplete="off" spellcheck="false">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_sm_encryption" class="lumia-form__label"><?php esc_html_e( 'Chiffrement', 'lumia-tools' ); ?></label>
					<select id="lumia_sm_encryption" name="lumia_module_settings[encryption]" class="lumia-select lumia-select--sm">
						<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>><?php esc_html_e( 'STARTTLS (port 587)', 'lumia-tools' ); ?></option>
						<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>><?php esc_html_e( 'SSL/TLS (port 465)', 'lumia-tools' ); ?></option>
						<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'Aucun (port 25)', 'lumia-tools' ); ?></option>
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
					<label for="lumia_sm_auto_tls" class="lumia-option__label"><?php esc_html_e( 'TLS automatique', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Passe en STARTTLS si le serveur le propose. À couper seulement pour un serveur au certificat invalide.', 'lumia-tools' ); ?></p>
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
					<label for="lumia_sm_auth" class="lumia-option__label"><?php esc_html_e( 'Authentification', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Presque tous les serveurs l\'exigent.', 'lumia-tools' ); ?></p>
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
						<?php esc_html_e( 'Identifiant', 'lumia-tools' ); ?>
						<?php if ( $smtp_user_const ) : ?>
							<?php echo $this->render_help_tip( __( 'Défini dans wp-config.php par LUMIA_SMTP_USER.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</label>
					<input type="text" id="lumia_sm_username" name="lumia_module_settings[username]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $smtp_user_const ? (string) LUMIA_SMTP_USER : (string) $module_settings['username'] ); ?>"
						autocomplete="off" spellcheck="false" <?php disabled( $smtp_user_const ); ?>>
				</div>
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_password" class="lumia-form__label">
						<?php esc_html_e( 'Mot de passe', 'lumia-tools' ); ?>
						<?php
						$smtp_pass_tip = $smtp_pass_const
							? __( 'Défini dans wp-config.php par LUMIA_SMTP_PASSWORD.', 'lumia-tools' )
							: __( 'Chiffré en base avec les clés de wp-config.php, jamais réaffiché ni exporté. Laissez vide pour garder le mot de passe enregistré. Pour ne pas le stocker en base, définissez LUMIA_SMTP_PASSWORD dans wp-config.php.', 'lumia-tools' );
						echo $this->render_help_tip( $smtp_pass_tip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</label>
					<input type="password" id="lumia_sm_password" name="lumia_module_settings[password]" class="lumia-input lumia-input--sm"
						value="" autocomplete="new-password"
						placeholder="<?php echo $smtp_has_pass ? esc_attr__( 'Enregistré — laisser vide pour conserver', 'lumia-tools' ) : ''; ?>"
						<?php disabled( $smtp_pass_const ); ?>>
				</div>
			</div>

			</div><!-- #lumia-sm-smtp-fields -->
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		EXPÉDITEUR
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Expéditeur', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				/* translators: %s: adresse d'expédition par défaut de WordPress. */
				echo esc_html( sprintf( __( 'Remplace l\'expéditeur par défaut de WordPress (%s). Utilisez une adresse du domaine autorisé par le serveur SMTP ou validé dans Brevo, sinon les mails échouent au contrôle SPF/DMARC.', 'lumia-tools' ), Mailer::wp_default_from_email() ) );
				?>
			</p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_from_email" class="lumia-form__label"><?php esc_html_e( 'Adresse d\'expédition', 'lumia-tools' ); ?></label>
					<input type="email" id="lumia_sm_from_email" name="lumia_module_settings[from_email]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['from_email'] ); ?>" placeholder="contact@example.com">
				</div>
				<div class="lumia-form__group lumia-sm__wide">
					<label for="lumia_sm_from_name" class="lumia-form__label"><?php esc_html_e( 'Nom d\'expéditeur', 'lumia-tools' ); ?></label>
					<input type="text" id="lumia_sm_from_name" name="lumia_module_settings[from_name]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_force_email" class="lumia-option__label"><?php esc_html_e( 'Forcer l\'adresse d\'expédition', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Remplace aussi l\'adresse choisie par une extension (formulaire de contact, boutique). Sans ça, seule l\'adresse par défaut de WordPress est remplacée.', 'lumia-tools' ); ?></p>
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
					<label for="lumia_sm_force_name" class="lumia-option__label"><?php esc_html_e( 'Forcer le nom d\'expéditeur', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Sans ça, seul le nom « WordPress » est remplacé.', 'lumia-tools' ); ?></p>
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
						<?php esc_html_e( 'Return-Path sur l\'adresse d\'expédition', 'lumia-tools' ); ?>
						<?php echo $this->render_help_tip( __( 'Adresse d\'enveloppe : c\'est elle que vérifie SPF. Même quand une extension garde son propre expéditeur, l\'enveloppe reste sur l\'adresse configurée ci-dessus, que le serveur SMTP accepte.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Les avis de non-distribution arrivent à l\'adresse d\'expédition configurée.', 'lumia-tools' ); ?></p>
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

	</div><!-- panneau Réglages -->

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="test" hidden>

	<!-- ============================================================
		MAIL DE TEST
		Champ sans attribut name : il ne part pas avec les réglages. En
		type="text" et non "email" : le navigateur valide un champ email même
		sans name, et une adresse incomplète ici bloquait « Enregistrer ».
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Mail de test', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Envoie un mail avec les réglages enregistrés : enregistrez d\'abord vos modifications. En cas d\'échec, l\'échange avec le serveur SMTP (identifiants masqués) ou la réponse de l\'API s\'affiche.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-sm__test">
				<input type="text" inputmode="email" autocomplete="email" spellcheck="false" id="lumia-sm-test-to" class="lumia-input lumia-input--sm" value="<?php echo esc_attr( $smtp_admin_mail ); ?>"
					aria-label="<?php esc_attr_e( 'Destinataire du mail de test', 'lumia-tools' ); ?>">
				<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-sm-test-send"><?php esc_html_e( 'Envoyer un mail de test', 'lumia-tools' ); ?></button>
			</div>
			<div class="lumia-sm__test-result" id="lumia-sm-test-result" hidden>
				<p class="lumia-notice" id="lumia-sm-test-message"></p>
				<pre class="lumia-sm__transcript" id="lumia-sm-test-transcript" hidden></pre>
			</div>
		</div>
	</div>

	</div><!-- panneau Test -->

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="smtp" data-lumia-tab-panel="log" hidden>

	<!-- ============================================================
		JOURNAL
		Filtres sans attribut name : ils ne partent pas avec les réglages.
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Journal des mails', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc">
				<?php
				if ( empty( $module_settings['log_enabled'] ) ) {
					esc_html_e( 'Journalisation désactivée : les mails envoyés ne sont plus enregistrés. Les mails déjà journalisés restent consultables.', 'lumia-tools' );
				} else {
					esc_html_e( 'Chaque mail envoyé par le site, réussi ou non. Cliquez sur une ligne pour voir le message et le renvoyer.', 'lumia-tools' );
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
							placeholder="<?php esc_attr_e( 'Objet, destinataire, expéditeur…', 'lumia-tools' ); ?>"
							aria-label="<?php esc_attr_e( 'Rechercher dans le journal des mails', 'lumia-tools' ); ?>">
					</div>

					<select class="lumia-select lumia-select--sm" id="lumia-sm-status" aria-label="<?php esc_attr_e( 'Statut', 'lumia-tools' ); ?>">
						<option value=""><?php esc_html_e( 'Tous les statuts', 'lumia-tools' ); ?></option>
						<option value="sent"><?php esc_html_e( 'Envoyés', 'lumia-tools' ); ?></option>
						<option value="failed"><?php esc_html_e( 'Échecs', 'lumia-tools' ); ?></option>
					</select>

					<input type="date" class="lumia-input lumia-input--sm" id="lumia-sm-from" aria-label="<?php esc_attr_e( 'Depuis le', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Depuis le', 'lumia-tools' ); ?>">
					<input type="date" class="lumia-input lumia-input--sm" id="lumia-sm-to" aria-label="<?php esc_attr_e( 'Jusqu\'au', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Jusqu\'au', 'lumia-tools' ); ?>">

					<div class="lumia-sm__filter-actions">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-reset"><?php esc_html_e( 'Réinitialiser', 'lumia-tools' ); ?></button>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--danger" id="lumia-sm-clear"><?php esc_html_e( 'Vider le journal', 'lumia-tools' ); ?></button>
					</div>
				</div>

				<div class="lumia-sm__table-wrap">
					<table class="lumia-sm__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Date', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Statut', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Destinataire', 'lumia-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Objet', 'lumia-tools' ); ?></th>
							</tr>
						</thead>
						<tbody id="lumia-sm-rows">
							<tr><td colspan="4" class="lumia-sm__state"><?php esc_html_e( 'Chargement…', 'lumia-tools' ); ?></td></tr>
						</tbody>
					</table>
				</div>

				<div class="lumia-sm__footer">
					<span id="lumia-sm-total"></span>
					<div class="lumia-sm__pager">
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-prev" disabled aria-label="<?php esc_attr_e( 'Page précédente', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Page précédente', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
						</button>
						<span id="lumia-sm-page"></span>
						<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" id="lumia-sm-next" disabled aria-label="<?php esc_attr_e( 'Page suivante', 'lumia-tools' ); ?>" data-lumia-tip="<?php esc_attr_e( 'Page suivante', 'lumia-tools' ); ?>">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		CONSERVATION
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Conservation', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Le journal garde le contenu complet des mails, liens de réinitialisation de mot de passe compris : ne le conservez pas plus que nécessaire. Une purge quotidienne supprime les mails trop anciens, puis les plus anciens au-delà du plafond.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_sm_log_enabled" class="lumia-option__label"><?php esc_html_e( 'Journaliser les mails', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Enregistre chaque mail envoyé par le site, avec son statut et l\'erreur éventuelle.', 'lumia-tools' ); ?></p>
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
					<label for="lumia_sm_retention_days" class="lumia-form__label"><?php esc_html_e( 'Durée de conservation (jours)', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_sm_retention_days" name="lumia_module_settings[log_retention_days]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['log_retention_days'] ); ?>" min="1" max="3650">
				</div>
				<div class="lumia-form__group">
					<label for="lumia_sm_max_rows" class="lumia-form__label"><?php esc_html_e( 'Nombre maximal de mails', 'lumia-tools' ); ?></label>
					<input type="number" id="lumia_sm_max_rows" name="lumia_module_settings[log_max_rows]" class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( (string) $module_settings['log_max_rows'] ); ?>" min="100" max="1000000" step="100">
				</div>
			</div>
		</div>
	</div>

	</div><!-- panneau Journal -->

	</div><!-- .lumia-module-form__scroll -->

</form>

<!-- MODALE DE DÉTAIL (contenu généré en JS) -->
<div class="lumia-modal-overlay" id="lumia-sm-detail-modal" role="dialog" aria-modal="true" aria-labelledby="lumia-sm-detail-title">
	<div class="lumia-modal lumia-modal--lg">
		<div class="lumia-modal__header">
			<h3 id="lumia-sm-detail-title" class="lumia-modal__title"></h3>
		</div>
		<div class="lumia-modal__body">
			<dl class="lumia-sm__detail" id="lumia-sm-detail-meta"></dl>
			<p class="lumia-notice lumia-notice--error" id="lumia-sm-detail-error" hidden></p>
			<!-- sandbox vide : ni script, ni formulaire, ni même origine. Le
				corps d'un mail vient de n'importe qui (formulaire de contact). -->
			<iframe class="lumia-sm__preview" id="lumia-sm-detail-html" sandbox="" referrerpolicy="no-referrer" title="<?php esc_attr_e( 'Aperçu du message', 'lumia-tools' ); ?>" hidden></iframe>
			<pre class="lumia-sm__preview lumia-sm__preview--text" id="lumia-sm-detail-text" hidden></pre>
			<details class="lumia-sm__headers" id="lumia-sm-detail-headers-wrap" hidden>
				<summary><?php esc_html_e( 'En-têtes transmis', 'lumia-tools' ); ?></summary>
				<pre id="lumia-sm-detail-headers"></pre>
			</details>
		</div>
		<div class="lumia-modal__footer">
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary lumia-modal-close"><?php esc_html_e( 'Fermer', 'lumia-tools' ); ?></button>
			<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--primary" id="lumia-sm-detail-resend"><?php esc_html_e( 'Renvoyer', 'lumia-tools' ); ?></button>
		</div>
	</div>
</div>
