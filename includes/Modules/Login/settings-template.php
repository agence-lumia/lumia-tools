<?php
/**
 * Settings template of the Login module.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (login)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 * @var string          $tab             Active tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$layout   = $module_settings['layout'] ?? [];
$branding = $module_settings['branding'] ?? [];
$form     = $module_settings['form'] ?? [];

// Current images
$panel_img_id  = absint( $layout['panel_image_id'] ?? 0 );
$panel_img_url = $panel_img_id ? wp_get_attachment_image_url( $panel_img_id, 'medium' ) : '';

$logo_id  = absint( $branding['logo_id'] ?? 0 );
$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
?>

<form id="lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="login" aria-label="<?php esc_attr_e( 'Login module sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="appearance"><?php esc_html_e( 'Appearance', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="options"><?php esc_html_e( 'Options', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="login" data-lumia-tab-panel="appearance">

	<!-- ============================================================
		LAYOUT — IMAGE PANEL
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Layout', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Visual panel displayed to the right of the login form.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Panel image -->
			<div class="lumia-option lumia-option--column">
				<div class="lumia-option__content">
					<span class="lumia-option__label"><?php esc_html_e( 'Panel image', 'lumia-tools' ); ?></span>
					<p class="lumia-option__desc"><?php esc_html_e( 'Background image of the right-hand panel. If empty, the background color is used.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control lumia-option__control--full">
					<div class="lumia-media-picker" data-picker="panel_image">
						<input type="hidden" name="lumia_module_settings[layout][panel_image_id]" id="lumia_panel_image_id" value="<?php echo esc_attr( $panel_img_id ); ?>">
						<div class="lumia-media-preview <?php echo $panel_img_url ? 'has-image' : ''; ?>">
							<?php if ( $panel_img_url ) : ?>
								<img src="<?php echo esc_url( $panel_img_url ); ?>" alt="">
							<?php endif; ?>
						</div>
						<div class="lumia-media-actions">
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-select" data-title="<?php esc_attr_e( 'Choose an image', 'lumia-tools' ); ?>" data-button="<?php esc_attr_e( 'Use this image', 'lumia-tools' ); ?>">
								<?php esc_html_e( 'Choose an image', 'lumia-tools' ); ?>
							</button>
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-remove <?php echo ! $panel_img_url ? 'is-hidden' : ''; ?>">
								<?php esc_html_e( 'Remove', 'lumia-tools' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Panel background color -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_panel_bg_color" class="lumia-option__label">
						<?php esc_html_e( 'Panel background color', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Used as a fallback if no image is set.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input
							type="color"
							id="lumia_panel_bg_color"
							name="lumia_module_settings[layout][panel_bg_color]"
							value="<?php echo esc_attr( $layout['panel_bg_color'] ?? '#eaeaea' ); ?>"
						>
						<span class="lumia-color-field__value"><?php echo esc_html( $layout['panel_bg_color'] ?? '#eaeaea' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#eaeaea" data-lumia-tip="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Reset color', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		BRANDING — LOGO
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Branding', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Replace the WordPress logo with your own.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Custom logo -->
			<div class="lumia-option lumia-option--column">
				<div class="lumia-option__content">
					<span class="lumia-option__label"><?php esc_html_e( 'Custom logo', 'lumia-tools' ); ?></span>
					<p class="lumia-option__desc"><?php esc_html_e( 'Replaces the default WordPress logo. Recommended format: transparent PNG or SVG.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control lumia-option__control--full">
					<div class="lumia-media-picker" data-picker="logo">
						<input type="hidden" name="lumia_module_settings[branding][logo_id]" id="lumia_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
						<div class="lumia-media-preview lumia-media-preview--logo <?php echo $logo_url ? 'has-image' : ''; ?>">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="">
							<?php endif; ?>
						</div>
						<div class="lumia-media-actions">
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-select" data-title="<?php esc_attr_e( 'Choose a logo', 'lumia-tools' ); ?>" data-button="<?php esc_attr_e( 'Use this logo', 'lumia-tools' ); ?>">
								<?php esc_html_e( 'Choose a logo', 'lumia-tools' ); ?>
							</button>
							<button type="button" class="lumia-btn lumia-btn--secondary lumia-btn--sm lumia-media-remove <?php echo ! $logo_url ? 'is-hidden' : ''; ?>">
								<?php esc_html_e( 'Remove', 'lumia-tools' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- Logo width -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_logo_width" class="lumia-option__label">
						<?php esc_html_e( 'Logo width', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Maximum logo width in pixels (between 40 and 600).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-input-unit">
						<input
							type="number"
							id="lumia_logo_width"
							name="lumia_module_settings[branding][logo_width]"
							value="<?php echo esc_attr( $branding['logo_width'] ?? 150 ); ?>"
							min="40"
							max="600"
							step="1"
							class="lumia-input lumia-input--sm"
						>
						<span class="lumia-input-unit__label">px</span>
					</div>
				</div>
			</div>

		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- ============================================================
		COLORS
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Colors', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Customize the login form colors.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Background color -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_bg_color" class="lumia-option__label"><?php esc_html_e( 'Page background', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php esc_html_e( 'Background color of the form area.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_bg_color" name="lumia_module_settings[form][bg_color]" value="<?php echo esc_attr( $form['bg_color'] ?? '#f7f7f7' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['bg_color'] ?? '#f7f7f7' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#f7f7f7" data-lumia-tip="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Reset color', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Button color -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_btn_bg_color" class="lumia-option__label"><?php esc_html_e( 'Button background', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_btn_bg_color" name="lumia_module_settings[form][btn_bg_color]" value="<?php echo esc_attr( $form['btn_bg_color'] ?? '#615FFF' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['btn_bg_color'] ?? '#615FFF' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#615FFF" data-lumia-tip="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Reset color', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Button text color -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_btn_text_color" class="lumia-option__label"><?php esc_html_e( 'Button text', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_btn_text_color" name="lumia_module_settings[form][btn_text_color]" value="<?php echo esc_attr( $form['btn_text_color'] ?? '#ffffff' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['btn_text_color'] ?? '#ffffff' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#ffffff" data-lumia-tip="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Reset color', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

			<!-- Link color -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_link_color" class="lumia-option__label"><?php esc_html_e( 'Link color', 'lumia-tools' ); ?></label>
				</div>
				<div class="lumia-option__control">
					<div class="lumia-color-field">
						<input type="color" id="lumia_link_color" name="lumia_module_settings[form][link_color]" value="<?php echo esc_attr( $form['link_color'] ?? '#615FFF' ); ?>">
						<span class="lumia-color-field__value"><?php echo esc_html( $form['link_color'] ?? '#615FFF' ); ?></span>
						<button type="button" class="lumia-color-reset" data-default="#615FFF" data-lumia-tip="<?php esc_attr_e( 'Reset', 'lumia-tools' ); ?>" aria-label="<?php esc_attr_e( 'Reset color', 'lumia-tools' ); ?>">↩</button>
					</div>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="login" data-lumia-tab-panel="options" hidden>

	<!-- ============================================================
		MISCELLANEOUS OPTIONS
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Options', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Elements to hide on the login page.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Hide language switcher -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_language" class="lumia-option__label">
						<?php esc_html_e( 'Hide the language switcher', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Hides the language selection dropdown at the bottom of the form.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_language"
							name="lumia_module_settings[form][hide_language_switcher]"
							value="1"
							<?php checked( ! empty( $form['hide_language_switcher'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide lost password -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_lost_password" class="lumia-option__label">
						<?php esc_html_e( 'Hide the “Lost your password?” link', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Hides the password recovery link below the form.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_lost_password"
							name="lumia_module_settings[form][hide_lost_password]"
							value="1"
							<?php checked( ! empty( $form['hide_lost_password'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide back-to-site link -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_back_to_blog" class="lumia-option__label">
						<?php esc_html_e( 'Hide the “Go to site” link', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Hides the link back to the site home page at the bottom of the form.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_back_to_blog"
							name="lumia_module_settings[form][hide_back_to_blog]"
							value="1"
							<?php checked( ! empty( $form['hide_back_to_blog'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide the Privacy Policy link -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_hide_privacy_policy" class="lumia-option__label">
						<?php esc_html_e( 'Hide the “Privacy Policy” link', 'lumia-tools' ); ?>
					</label>
					<p class="lumia-option__desc">
						<?php esc_html_e( 'Hides the link WordPress displays when a privacy policy page is set.', 'lumia-tools' ); ?>
					</p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input
							type="checkbox"
							id="lumia_hide_privacy_policy"
							name="lumia_module_settings[form][hide_privacy_policy]"
							value="1"
							<?php checked( ! empty( $form['hide_privacy_policy'] ) ); ?>
						>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
