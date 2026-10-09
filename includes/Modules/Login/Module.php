<?php
namespace Lumia\Tools\Modules\Login;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;

/**
 * Login module: customizes the WordPress login page.
 */
class Module extends AbstractModule {

	/**
	 * @var array<string, mixed>
	 */
	private array $settings = [];

	/**
	 * Registers the WordPress hooks.
	 */
	public function init(): void {
		$this->settings = $this->get_settings();

		add_action( 'login_enqueue_scripts', [ $this, 'enqueue_login_assets' ] );
		add_action( 'login_head', [ $this, 'inject_css_variables' ] );
		add_filter( 'login_headerurl', [ $this, 'filter_logo_url' ] );
		add_filter( 'login_headertext', [ $this, 'filter_logo_text' ] );
		add_filter( 'login_body_class', [ $this, 'add_body_class' ] );
		add_action( 'login_footer', [ $this, 'render_side_panel' ] );
		add_action( 'login_footer', [ $this, 'render_login_dom_tweaks' ], 20 );

		if ( ! empty( $this->settings['form']['hide_language_switcher'] ) ) {
			add_filter( 'login_display_language_dropdown', '__return_false' );
		}

		if ( ! empty( $this->settings['form']['hide_lost_password'] ) ) {
			add_action( 'login_head', [ $this, 'hide_lost_password_css' ], 99 );
		}

		if ( ! empty( $this->settings['form']['hide_back_to_blog'] ) ) {
			add_action( 'login_head', [ $this, 'hide_back_to_blog_css' ], 99 );
		}

		if ( ! empty( $this->settings['form']['hide_privacy_policy'] ) ) {
			add_action( 'login_head', [ $this, 'hide_privacy_policy_css' ], 99 );
		}

		// Loads the WP media uploader on the module settings page.
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_media' ] );
	}

	/**
	 * Enqueues the login page CSS.
	 */
	public function enqueue_login_assets(): void {
		wp_enqueue_style(
			'lumia-login-css',
			LUMIA_ASSETS_URL . 'login/css/login.css',
			[],
			LUMIA_VERSION
		);
	}

	/**
	 * Prints the custom CSS variables in the <head> of the login page.
	 */
	public function inject_css_variables(): void {
		$s = $this->settings;

		$bg_color   = $this->sanitize_color( $s['form']['bg_color'] ?? '#f7f7f7' );
		$panel_bg   = $this->sanitize_color( $s['layout']['panel_bg_color'] ?? '#eaeaea' );
		$btn_bg     = $this->sanitize_color( $s['form']['btn_bg_color'] ?? '#615FFF' );
		$btn_color  = $this->sanitize_color( $s['form']['btn_text_color'] ?? '#ffffff' );
		$link_color = $this->sanitize_color( $s['form']['link_color'] ?? '#615FFF' );
		$logo_width = absint( $s['branding']['logo_width'] ?? 150 );

		// Panel image
		$panel_img_url = '';
		$panel_img_id  = absint( $s['layout']['panel_image_id'] ?? 0 );
		if ( $panel_img_id > 0 ) {
			$src = wp_get_attachment_image_url( $panel_img_id, 'full' );
			if ( $src ) {
				$panel_img_url = esc_url( $src );
			}
		}

		// Custom logo
		$logo_url = '';
		$logo_id  = absint( $s['branding']['logo_id'] ?? 0 );
		if ( $logo_id > 0 ) {
			$src = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( $src ) {
				$logo_url = esc_url( $src );
			}
		}

		echo '<style id="lumia-login-vars">';
		echo ':root{';
		echo '--lumia-l-bg:' . esc_html( $bg_color ) . ';';
		echo '--lumia-l-panel-bg:' . esc_html( $panel_bg ) . ';';
		echo '--lumia-l-btn-bg:' . esc_html( $btn_bg ) . ';';
		echo '--lumia-l-btn-color:' . esc_html( $btn_color ) . ';';
		echo '--lumia-l-link:' . esc_html( $link_color ) . ';';
		echo '--lumia-l-logo-width:' . $logo_width . 'px;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( $panel_img_url ) {
			echo '--lumia-l-panel-img:url(' . $panel_img_url . ');'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '--lumia-l-panel-img:none;';
		}

		if ( $logo_url ) {
			echo '--lumia-l-logo-url:url(' . $logo_url . ');'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '--lumia-l-logo-display:block;';
		} else {
			echo '--lumia-l-logo-url:none;';
			echo '--lumia-l-logo-display:none;';
		}

		echo '}';
		echo '</style>';
	}

	/**
	 * Prints a style that hides the "Lost your password?" link.
	 */
	public function hide_lost_password_css(): void {
		echo '<style>#nav{display:none!important}</style>';
	}

	/**
	 * Prints a style that hides the "Go to SITE NAME" link.
	 */
	public function hide_back_to_blog_css(): void {
		echo '<style>#backtoblog{display:none!important}</style>';
	}

	/**
	 * Prints a style that hides the "Privacy Policy" link.
	 *
	 * CSS hiding rather than the `the_privacy_policy_link` filter: that filter is global,
	 * it would also remove the link from the theme's public footer.
	 */
	public function hide_privacy_policy_css(): void {
		echo '<style>.privacy-policy-page-link{display:none!important}</style>';
	}

	/**
	 * Replaces the logo URL with the site home page.
	 */
	public function filter_logo_url( string $url ): string {
		return home_url( '/' );
	}

	/**
	 * Replaces the logo alternative text with the site name.
	 */
	public function filter_logo_text( string $text ): string {
		return get_bloginfo( 'name' );
	}

	/**
	 * Adds the CSS class for the split layout.
	 *
	 * @param string[] $classes
	 * @return string[]
	 */
	public function add_body_class( array $classes ): array {
		$classes[] = 'lumia-login-split';
		$logo_id   = absint( $this->settings['branding']['logo_id'] ?? 0 );
		if ( $logo_id > 0 ) {
			$classes[] = 'lumia-has-logo';
		}
		return $classes;
	}

	/**
	 * Prints the image/color panel on the right-hand side after the form.
	 */
	public function render_side_panel(): void {
		echo '<div class="lumia-login-panel" aria-hidden="true"></div>';
	}

	/**
	 * Prints the JS DOM tweaks of the login page:
	 * - "Log in" title between the logo and the form
	 * - Password header (label + lost password link in space-between)
	 * - Reorders the button before "Remember Me"
	 */
	public function render_login_dom_tweaks(): void {
		?>
		<script>
		(function() {
			var loginform    = document.querySelector('#loginform');
			var userPassWrap = document.querySelector('.user-pass-wrap');
			var passwordLabel = userPassWrap ? userPassWrap.querySelector('label[for="user_pass"]') : null;
			var nav          = document.querySelector('#nav');
			var navLink      = nav ? nav.querySelector('a') : null;
			var forgetmenot  = document.querySelector('.forgetmenot');
			var submit       = document.querySelector('#loginform .submit');
			var h1           = document.querySelector('#login h1');

			if (h1) {
				var title = document.createElement('p');
				title.className = 'lumia-login-title';
				title.textContent = '<?php echo esc_js( __( 'Log in', 'lumia-tools' ) ); ?>';
				h1.insertAdjacentElement('afterend', title);
			}

			if (userPassWrap && passwordLabel) {
				var passHeader = document.createElement('div');
				passHeader.className = 'lumia-pass-header';
				passHeader.appendChild(passwordLabel.cloneNode(true));
				var navIsHidden = nav && window.getComputedStyle(nav).display === 'none';
				if (navLink && !navIsHidden) {
					passHeader.appendChild(navLink.cloneNode(true));
					if (nav) nav.classList.add('lumia-nav-hidden');
				}
				userPassWrap.parentElement.insertBefore(passHeader, userPassWrap);
				passwordLabel.style.display = 'none';
			}

			if (loginform && submit && forgetmenot) {
				loginform.insertBefore(submit, forgetmenot);
			}
		})();
		</script>
		<?php
	}

	/**
	 * Loads wp_enqueue_media() only on the module settings page.
	 */
	public function maybe_enqueue_media( string $hook ): void {
		if ( strpos( $hook, 'lumia-tools' ) === false ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		if ( 'module_login' === $tab ) {
			wp_enqueue_media();
		}
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return $this->get_module_settings( static::get_defaults() );
	}

	/**
	 * Validates and saves the settings.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		$current = $this->get_module_settings( static::get_defaults() );

		// Layout
		if ( isset( $settings['layout'] ) && is_array( $settings['layout'] ) ) {
			$current['layout']['panel_image_id'] = absint( $settings['layout']['panel_image_id'] ?? 0 );
			$current['layout']['panel_bg_color'] = $this->sanitize_color( $settings['layout']['panel_bg_color'] ?? '', '#16213e' );
		}

		// Branding
		if ( isset( $settings['branding'] ) && is_array( $settings['branding'] ) ) {
			$current['branding']['logo_id']    = absint( $settings['branding']['logo_id'] ?? 0 );
			$current['branding']['logo_width'] = min( 600, max( 40, absint( $settings['branding']['logo_width'] ?? 150 ) ) );
		}

		// Form
		if ( isset( $settings['form'] ) && is_array( $settings['form'] ) ) {
			$current['form']['hide_language_switcher'] = ! empty( $settings['form']['hide_language_switcher'] );
			$current['form']['hide_lost_password']     = ! empty( $settings['form']['hide_lost_password'] );
			$current['form']['hide_back_to_blog']      = ! empty( $settings['form']['hide_back_to_blog'] );
			$current['form']['hide_privacy_policy']    = ! empty( $settings['form']['hide_privacy_policy'] );
			$current['form']['bg_color']               = $this->sanitize_color( $settings['form']['bg_color'] ?? '', '#f7f7f7' );
			$current['form']['btn_bg_color']           = $this->sanitize_color( $settings['form']['btn_bg_color'] ?? '', '#615FFF' );
			$current['form']['btn_text_color']         = $this->sanitize_color( $settings['form']['btn_text_color'] ?? '', '#ffffff' );
			$current['form']['link_color']             = $this->sanitize_color( $settings['form']['link_color'] ?? '', '#615FFF' );
		}

		$this->settings = $current;
		return $this->save_module_settings( $current );
	}

	/**
	 * Default values of the settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [
			'layout'   => [
				'panel_image_id' => 0,
				'panel_bg_color' => '#eaeaea',
			],
			'branding' => [
				'logo_id'    => 0,
				'logo_width' => 150,
			],
			'form'     => [
				'hide_language_switcher' => true,
				'hide_lost_password'     => false,
				'hide_back_to_blog'      => true,
				'hide_privacy_policy'    => false,
				'bg_color'               => '#f7f7f7',
				'btn_bg_color'           => '#615FFF',
				'btn_text_color'         => '#ffffff',
				'link_color'             => '#615FFF',
			],
		];
	}

	/**
	 * Keys to delete on uninstall.
	 */
	public static function get_uninstall_keys(): array {
		return [
			'options' => [ 'lumia_module_login' ],
			'meta'    => [],
		];
	}

	/**
	 * CSS assets for the admin settings page.
	 */
	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/login.css' ];
	}

	/**
	 * JS assets for the admin settings page.
	 */
	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/login.js' ];
	}

	/* ================================================================
	 * PRIVATE HELPERS
	 * ================================================================ */

	/**
	 * Validates a hex color. Returns the fallback value if invalid.
	 */
	private function sanitize_color( string $color, string $fallback = '' ): string {
		$color = sanitize_hex_color( trim( $color ) );
		return is_string( $color ) && '' !== $color ? $color : $fallback;
	}
}
