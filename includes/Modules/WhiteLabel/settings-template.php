<?php
/**
 * Settings template of the White Label module.
 *
 * Available variables (via module-settings.php):
 * @var string          $module_id       Module ID (white_label)
 * @var array           $module          Module info
 * @var ModuleInterface $instance        Module instance
 * @var array           $module_settings Current settings
 * @var string          $tab             Active tab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ab      = $module_settings['admin_bar'] ?? [];
$footer  = $module_settings['footer'] ?? [];
$profile = $module_settings['profile'] ?? [];
$avatars = $module_settings['avatars'] ?? [];
?>

<form id="lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lumia-form lumia-module-form">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="white_label" aria-label="<?php esc_attr_e( 'White label sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="adminbar"><?php esc_html_e( 'Admin bar', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="avatars"><?php esc_html_e( 'Avatars', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="profile"><?php esc_html_e( 'Profile page', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="footer"><?php esc_html_e( 'Footer', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="adminbar">

	<!-- ============================================================
		ADMIN BAR
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Admin bar', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Hide the unnecessary items from the WordPress admin bar.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- WordPress logo -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the WordPress logo', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the WP logo and its dropdown menu at the top left.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_wp_logo]" value="1"
							<?php checked( ! empty( $ab['hide_wp_logo'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Home icon / site name -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the home icon and the site name', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the house icon and the site name from the admin bar.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_site_menu]" value="1"
							<?php checked( ! empty( $ab['hide_site_menu'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Command palette -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the command palette', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Available from WordPress 6.7 onward. Removes the command palette button.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_command_palette]" value="1"
							<?php checked( ! empty( $ab['hide_command_palette'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Updates counter -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the updates counter', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the icon and badge showing available updates.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_updates_counter]" value="1"
							<?php checked( ! empty( $ab['hide_updates_counter'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Comments counter -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the comments counter', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the icon and badge showing pending comments.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_comments_counter]" value="1"
							<?php checked( ! empty( $ab['hide_comments_counter'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- New menu -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the "New" menu', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "+ New" button used to quickly create content.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_new_content_menu]" value="1"
							<?php checked( ! empty( $ab['hide_new_content_menu'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Help button -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the Help button', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Hides the "Help" tab at the top right of every admin page.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_help_button]" value="1"
							<?php checked( ! empty( $ab['hide_help_button'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Screen options -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the Screen Options button', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Hides the "Screen Options" tab at the top right of every admin page.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_screen_options]" value="1"
							<?php checked( ! empty( $ab['hide_screen_options'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Remove Howdy -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Remove the greeting', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes "Howdy," / "Hello," in front of the logged-in user name, whatever the language.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][remove_howdy]" value="1"
							<?php checked( ! empty( $ab['remove_howdy'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Hide bar on the front end -->
			<div class="lumia-option lumia-option--frontend-separator">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the admin bar on the site', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Completely hides the admin bar for site visitors (front end).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[admin_bar][hide_frontend]" value="1"
							<?php checked( ! empty( $ab['hide_frontend'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="avatars" hidden>

	<!-- ============================================================
		AVATARS
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Avatars', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Host avatars locally instead of relying on Gravatar.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Local avatars -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Enable local avatars', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Adds a "Local avatar" field to every profile. The uploaded avatar takes priority; otherwise WordPress falls back to Gravatar (default behavior).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[avatars][local]" value="1"
							<?php checked( ! empty( $avatars['local'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="profile" hidden>

	<!-- ============================================================
		PROFILE PAGE
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Profile page', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Visual hiding only: every feature stays active on the server side. These checkboxes declutter the screen, they do not remove any permission.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Declutter the user profile page (profile.php) by hiding unneeded options.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Admin color scheme -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the admin color scheme', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the admin color scheme picker.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_color_scheme]" value="1"
							<?php checked( ! empty( $profile['hide_color_scheme'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Keyboard shortcuts -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide keyboard shortcuts', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the option to enable comment moderation shortcuts.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_keyboard_shortcuts]" value="1"
							<?php checked( ! empty( $profile['hide_keyboard_shortcuts'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Toolbar -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the Toolbar option', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "Show Toolbar when viewing site" checkbox.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_toolbar_toggle]" value="1"
							<?php checked( ! empty( $profile['hide_toolbar_toggle'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Application passwords -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide application passwords', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Hides the "Application Passwords" section (the feature stays active on the server side).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_app_passwords]" value="1"
							<?php checked( ! empty( $profile['hide_app_passwords'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Language selector -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the language selector', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "Language" row (user locale).', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_language]" value="1"
							<?php checked( ! empty( $profile['hide_language'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Biographical info -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the biographical info', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "Biographical Info" field from the "About Yourself" section.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_bio]" value="1"
							<?php checked( ! empty( $profile['hide_bio'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Sessions -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide sessions', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "Log Out Everywhere Else" button.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_sessions]" value="1"
							<?php checked( ! empty( $profile['hide_sessions'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Editor options -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the editor options', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes "Disable the visual editor" and "Syntax Highlighting".', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" name="lumia_module_settings[profile][hide_editor_options]" value="1"
							<?php checked( ! empty( $profile['hide_editor_options'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="white_label" data-lumia-tab-panel="footer" hidden>

	<!-- ============================================================
		FOOTER
		============================================================ -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php esc_html_e( 'Footer', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php esc_html_e( 'Customize the texts displayed in the footer of the WordPress admin.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">

			<!-- Left text -->
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-wl-left-text"><?php esc_html_e( 'Footer left text', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'HTML is filtered like post content: scripts and event attributes are removed when saving.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<textarea class="lumia-input" id="lumia-wl-left-text" name="lumia_module_settings[footer][left_text]" rows="2"><?php echo esc_textarea( $footer['left_text'] ?? '' ); ?></textarea>
				<p class="lumia-form__help"><?php esc_html_e( 'Supports basic HTML (links, em/strong tags). Leave empty to keep the default WordPress value.', 'lumia-tools' ); ?></p>
			</div>

			<!-- Hide WordPress version -->
			<div class="lumia-option">
				<div class="lumia-option__content">
					<p class="lumia-option__label"><?php esc_html_e( 'Hide the WordPress version (right text)', 'lumia-tools' ); ?></p>
					<p class="lumia-option__desc"><?php esc_html_e( 'Removes the "Version X.X.X" notice at the bottom right of every admin page.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox" id="lumia-wl-hide-right" name="lumia_module_settings[footer][hide_right_text]" value="1"
							<?php checked( ! empty( $footer['hide_right_text'] ) ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<!-- Custom right text -->
			<div class="lumia-form__group">
				<label class="lumia-form__label" for="lumia-wl-right-text"><?php esc_html_e( 'Footer right text (if not hidden)', 'lumia-tools' ); ?></label>
				<input type="text" class="lumia-input" id="lumia-wl-right-text" name="lumia_module_settings[footer][right_text]" value="<?php echo esc_attr( $footer['right_text'] ?? '' ); ?>">
				<p class="lumia-form__help"><?php esc_html_e( 'Replaces "Version X.X.X". Leave empty to keep the default WordPress value.', 'lumia-tools' ); ?></p>
			</div>

		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->

</form>
<script>
(function() {
	var toggle = document.getElementById('lumia-wl-hide-right');
	var field  = document.getElementById('lumia-wl-right-text');
	if (!toggle || !field) return;
	function sync() { field.disabled = toggle.checked; field.closest('.lumia-form__group').style.opacity = toggle.checked ? '0.4' : '1'; }
	toggle.addEventListener('change', sync);
	sync();
})();
</script>
