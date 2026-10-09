<?php
namespace Lumia\Tools\Modules\MenuCreator;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Core\Compat;
use Lumia\Tools\Modules\ImageOptimizer\SvgHandler;
use Lumia\Tools\Modules\WhiteLabel\MenuProfileManager;

/**
 * Menu Creator module — management of navigation profiles and their application to users.
 */
class Module extends AbstractModule {

	/**
	 * Maximum number of menu entries persisted per level (payload safeguard).
	 */
	private const MAX_ITEMS = 300;

	/**
	 * Copy of the WP admin menu BEFORE any customization by this module
	 * (injected separators, added custom links, removed hidden items).
	 * The editor must show the user the original WP menu, not the already
	 * transformed version — otherwise our own separators / custom links
	 * would show up again as duplicates. Captured at the start of
	 * apply_menu_visibility.
	 *
	 * @var array<int, mixed>|null
	 */
	private static $pristine_menu = null;

	/**
	 * @var array<string, mixed>|null
	 */
	private static $pristine_submenu = null;

	public function init(): void {
		// Engine applying the custom menus
		$has_active = ! empty(
			array_filter(
				MenuProfileManager::get_all(),
				fn( $p ) => ( $p['status'] ?? '' ) === 'active'
			)
		);

		if ( $has_active ) {
			// Only enables the custom order if the current user really has an
			// active profile (otherwise everyone would be pushed through the
			// custom_menu_order path for nothing).
			add_filter( 'custom_menu_order', [ $this, 'maybe_enable_custom_order' ] );
			add_filter( 'menu_order', [ $this, 'apply_menu_order' ], 9999 );
			add_action( 'admin_menu', [ $this, 'apply_menu_visibility' ], 9999 );
			add_action( 'admin_head', [ $this, 'inject_menu_icon_overrides' ] );
			add_action( 'admin_head', [ $this, 'inject_custom_link_targets' ] );
			// Priority 1: deny the page before anything else (screen loading,
			// form handling) runs.
			add_action( 'admin_init', [ $this, 'enforce_blocked_pages' ], 1 );
		}

		// admin_footer: the toast script is already loaded there.
		add_action( 'admin_footer', [ $this, 'render_denied_toast' ] );

		// Makes menu icon opacity uniform (native AND custom): by default WP
		// dims #adminmenu .wp-menu-image img to 60% until the item is hovered or
		// active. Independent of an active profile, so that even native icons
		// (e.g. the plugin's own icon) benefit from it.
		add_action( 'admin_head', [ $this, 'inject_global_icon_opacity_fix' ] );

		// AJAX endpoints
		add_action( 'wp_ajax_lumia_wl_save_profile', [ $this, 'ajax_save_profile' ] );
		add_action( 'wp_ajax_lumia_wl_delete_profile', [ $this, 'ajax_delete_profile' ] );
		add_action( 'wp_ajax_lumia_wl_duplicate_profile', [ $this, 'ajax_duplicate_profile' ] );
		add_action( 'wp_ajax_lumia_wl_search_users', [ $this, 'ajax_search_users' ] );
		add_action( 'wp_ajax_lumia_wl_import_profile', [ $this, 'ajax_import_profile' ] );
		add_action( 'wp_ajax_lumia_wl_sanitize_svg', [ $this, 'ajax_sanitize_svg' ] );

		// WP media library for the icon picker
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_media' ] );
	}

	/* ================================================================
	 * MENU ENGINE
	 * ================================================================ */

	/**
	 * Only enables the custom menu sort for a user really targeted by an active
	 * profile; leaves the value of the other filters untouched otherwise.
	 *
	 * @param bool $enabled Current value of the custom_menu_order filter.
	 */
	public function maybe_enable_custom_order( $enabled ): bool {
		if ( MenuProfileManager::get_active_for_user( get_current_user_id() ) ) {
			return true;
		}
		return (bool) $enabled;
	}

	/**
	 * @param string[] $menu_order
	 * @return string[]
	 */
	public function apply_menu_order( array $menu_order ): array {
		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return $menu_order;
		}

		$slugs = [];
		foreach ( $profile['items'] as $item ) {
			$type = $item['type'] ?? 'wp_item';

			// A custom link is registered in $menu by add_menu_page() under the
			// slug WordPress derives from the URL, NOT the raw URL:
			// add_menu_page applies plugin_basename() to the received menu_slug.
			// The exact same transformation must be reproduced here, otherwise
			// the slug matches no $menu_order entry and the link falls into
			// "remaining" (hence at the very bottom of the menu).
			if ( 'custom_link' === $type ) {
				if ( ! empty( $item['url'] ) ) {
					$slugs[] = $this->custom_link_slug( $item['url'] );
				}
				continue;
			}

			if ( ! empty( $item['slug'] ) ) {
				$slugs[] = $item['slug'];
			}
		}

		$remaining = array_values( array_diff( $menu_order, $slugs ) );
		return array_values( array_merge( $slugs, $remaining ) );
	}

	public function apply_menu_visibility(): void {
		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return;
		}

		global $menu, $submenu;

		// Snapshot of the pristine WP menu, before our modifications: consumed
		// by get_admin_js_data() to feed the editor with the real WP menu (and
		// not the already customized version).
		if ( null === self::$pristine_menu ) {
			self::$pristine_menu    = is_array( $menu ) ? $menu : [];
			self::$pristine_submenu = is_array( $submenu ) ? $submenu : [];
		}

		$next_position = 9000;

		foreach ( $profile['items'] as $item ) {
			$type = $item['type'] ?? 'wp_item';
			$slug = $item['slug'] ?? '';

			if ( 'separator' === $type ) {
				if ( ! empty( $slug ) && is_array( $menu ) ) {
					// The $menu keys must stay integers (WordPress treats them as
					// such): look for the next free integer slot rather than
					// incrementing a float, which PHP would silently truncate
					// (and which causes collisions).
					while ( isset( $menu[ $next_position ] ) ) {
						++$next_position;
					}
					$menu[ $next_position ] = [ '', 'read', $slug, '', 'wp-menu-separator' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the admin menu is the purpose of this module.
					++$next_position;
				}
				continue;
			}

			if ( ! ( $item['visible'] ?? true ) && ! empty( $slug ) ) {
				remove_menu_page( $slug );
				continue;
			}

			if ( 'custom_link' === $type && ! empty( $item['url'] ) ) {
				// Role restriction: the entry is simply not added. Going through
				// add_menu_page's capability would not work — WordPress reasons
				// in capabilities, not roles, and none matches "these roles"
				// exactly.
				if ( ! $this->current_user_has_role( (array) ( $item['roles'] ?? [] ) ) ) {
					continue;
				}
				$label    = sanitize_text_field( $item['label'] ?? __( 'Link', 'lumia-tools' ) );
				$icon_url = $this->resolve_native_icon_url( $item['icon'] ?? null );
				add_menu_page( $label, $label, 'read', esc_url_raw( $item['url'] ), '', $icon_url, 999 );
				continue;
			}

			if ( 'wp_item' === $type && ! empty( $slug ) && is_array( $menu ) ) {
				foreach ( $menu as $key => $menu_item ) {
					if ( ! is_array( $menu_item ) || ( $menu_item[2] ?? '' ) !== $slug ) {
						continue;
					}
					if ( isset( $item['label'] ) ) {
						$menu[ $key ][0] = esc_html( $item['label'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the admin menu is the purpose of this module.
					}
					if ( isset( $item['icon'] ) && strpos( $item['icon'], 'dashicons-' ) === 0 ) {
						$menu[ $key ][6] = esc_attr( $item['icon'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the admin menu is the purpose of this module.
					}
					break;
				}

				if ( ! empty( $item['children'] ) ) {
					$this->apply_submenu( $slug, (array) $item['children'] );
				}
			}
		}
	}

	/**
	 * Is the current user among the allowed roles?
	 *
	 * Empty list = no restriction (everyone who sees this menu sees the
	 * entry). A super admin without a role on the current site is only covered
	 * by the "no restriction" case.
	 *
	 * @param array<int, string> $roles
	 */
	private function current_user_has_role( array $roles ): bool {
		$roles = array_filter( array_map( 'sanitize_key', $roles ) );
		if ( ! $roles ) {
			return true;
		}
		$user = wp_get_current_user();
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Applies the child customizations (order, visibility, label) to the real
	 * WP $submenu: the menu_order filter ONLY handles the first level, the
	 * submenus must be rewritten directly in the $submenu global.
	 *
	 * @param string               $parent_slug Slug of the parent in $submenu.
	 * @param array<int, mixed>    $children    Profile children, in the wanted order.
	 */
	private function apply_submenu( string $parent_slug, array $children ): void {
		global $submenu;
		if ( empty( $submenu[ $parent_slug ] ) || ! is_array( $submenu[ $parent_slug ] ) ) {
			return;
		}

		// Indexes the existing WP entries by their slug ([2]).
		$existing = [];
		foreach ( $submenu[ $parent_slug ] as $sub ) {
			if ( is_array( $sub ) && isset( $sub[2] ) ) {
				$existing[ $sub[2] ] = $sub;
			}
		}

		$reordered = [];
		foreach ( $children as $child ) {
			$child_slug = $child['slug'] ?? '';
			if ( '' === $child_slug || ! isset( $existing[ $child_slug ] ) ) {
				continue;
			}
			if ( ! ( $child['visible'] ?? true ) ) {
				unset( $existing[ $child_slug ] );
				continue;
			}
			$entry = $existing[ $child_slug ];
			if ( isset( $child['label'] ) && '' !== $child['label'] ) {
				$entry[0] = esc_html( $child['label'] );
			}
			$reordered[] = $entry;
			unset( $existing[ $child_slug ] );
		}

		// WP entries not listed in the profile (added after its creation): kept
		// afterwards so that nothing disappears by surprise.
		foreach ( $existing as $entry ) {
			$reordered[] = $entry;
		}

		$submenu[ $parent_slug ] = $reordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the admin menu is the purpose of this module.
	}

	/* ================================================================
	 * ACCESS BLOCKING
	 * ================================================================ */

	/**
	 * Denies direct access to the pages whose item carries `block_access`.
	 *
	 * Hiding a menu entry only removes it from the sidebar: the URL can still be
	 * typed and the page opens normally. This option, ticked item by item (and
	 * only on an already hidden item), adds the server-side denial.
	 *
	 * This is NOT a permissions system: it applies to the user's menu profile,
	 * not to their capabilities. A user who has the required capability keeps
	 * access through the REST API, WP-CLI or admin-ajax.
	 */
	public function enforce_blocked_pages(): void {
		global $pagenow;

		// Never on programmatic entry points: they do not display an admin
		// screen and a denial there would break legitimate requests (uploads,
		// autosave, form actions of other modules).
		if ( wp_doing_ajax() || wp_doing_cron() || ! is_admin() ) {
			return;
		}
		if ( in_array( $pagenow, [ 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ], true ) ) {
			return;
		}

		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return;
		}

		foreach ( $this->blocked_slugs( $profile ) as $slug ) {
			if ( ! $this->request_matches_slug( $slug ) ) {
				continue;
			}

			// index.php is the redirect target: blocking it there would create a
			// loop, so a direct denial is rendered.
			if ( 'index.php' === $pagenow ) {
				wp_die(
					esc_html__( 'You do not have access to this page.', 'lumia-tools' ),
					esc_html__( 'Access denied', 'lumia-tools' ),
					[ 'response' => 403 ]
				);
			}

			wp_safe_redirect( admin_url( 'index.php?lumia_denied=1' ) );
			exit;
		}
	}

	/**
	 * Slugs (first level + sub-items) flagged hidden AND blocked.
	 *
	 * @param array<string, mixed> $profile
	 * @return array<int, string>
	 */
	private function blocked_slugs( array $profile ): array {
		$slugs = [];
		foreach ( (array) $profile['items'] as $item ) {
			$candidates = array_merge( [ $item ], (array) ( $item['children'] ?? [] ) );
			foreach ( $candidates as $candidate ) {
				if ( empty( $candidate['block_access'] ) || ! empty( $candidate['visible'] ) ) {
					continue;
				}
				// A custom link points outside of our control (arbitrary URL, often
				// external): nothing to block on the admin side.
				if ( 'custom_link' === ( $candidate['type'] ?? 'wp_item' ) ) {
					continue;
				}
				if ( ! empty( $candidate['slug'] ) ) {
					$slugs[] = (string) $candidate['slug'];
				}
			}
		}
		return $slugs;
	}

	/**
	 * Does the current admin request match this menu slug?
	 *
	 * A WP menu slug takes three forms: a file (`upload.php`), a file with
	 * parameters (`edit.php?post_type=page`) or a plugin page slug
	 * (`woocommerce`, `wc-admin&path=/analytics/overview`, served by
	 * admin.php). The expected request is rebuilt, then compared to the real
	 * request.
	 *
	 * The slug's parameters must all be present identically; conversely, a slug
	 * that mentions neither post_type, nor taxonomy, nor page must not match a
	 * request that carries one — otherwise blocking `edit.php` (Posts) would
	 * also block `edit.php?post_type=page`.
	 */
	private function request_matches_slug( string $slug ): bool {
		global $pagenow;

		$target = $this->slug_to_request( $slug );
		if ( $target['file'] !== $pagenow ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( $target['args'] as $key => $value ) {
			$actual = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : null;
			if ( (string) $value !== (string) $actual ) {
				return false;
			}
		}

		foreach ( [ 'page', 'taxonomy' ] as $discriminator ) {
			if ( ! isset( $target['args'][ $discriminator ] ) && isset( $_GET[ $discriminator ] ) ) {
				return false;
			}
		}
		if ( ! isset( $target['args']['post_type'] ) && isset( $_GET['post_type'] )
			&& 'post' !== sanitize_key( wp_unslash( $_GET['post_type'] ) ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return true;
	}

	/**
	 * Splits a menu slug into a file + expected parameters.
	 *
	 * @return array{file:string, args:array<string, string>}
	 */
	private function slug_to_request( string $slug ): array {
		$slug = str_replace( '&amp;', '&', $slug );
		$file = $slug;
		$args = [];

		$qpos = strpos( $slug, '?' );
		if ( false !== $qpos ) {
			$file = substr( $slug, 0, $qpos );
			parse_str( substr( $slug, $qpos + 1 ), $args );
		}

		// No PHP file → plugin page slug, served by admin.php. It may carry its
		// own parameters after a & (WooCommerce:
		// "wc-admin&path=/analytics/overview").
		if ( false === strpos( $file, '.php' ) ) {
			$bits         = explode( '&', $file, 2 );
			$args['page'] = $bits[0];
			if ( isset( $bits[1] ) ) {
				$extra = [];
				parse_str( $bits[1], $extra );
				$args += $extra;
			}
			$file = 'admin.php';
		}

		// parse_str() can produce arrays (key[]=…): only scalars are kept.
		$flat = [];
		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$flat[ (string) $key ] = (string) $value;
			}
		}

		return [
			'file' => $file,
			'args' => $flat,
		];
	}

	/**
	 * Message displayed after a blocking redirect.
	 *
	 * A toast rather than an admin_notice: the latter would be captured by the
	 * plugin's notification center and would only appear under the bell, while
	 * the user has just been redirected and must immediately understand why they
	 * are not on the requested page.
	 *
	 * The parameter is removed from the URL right after, so that a plain reload
	 * does not replay the message.
	 */
	public function render_denied_toast(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['lumia_denied'] ) ) {
			return;
		}
		$message = __( 'You do not have access to this page.', 'lumia-tools' );
		?>
		<script>
		( function () {
			var msg = <?php echo wp_json_encode( $message ); ?>;
			var tries = 0;
			( function show() {
				if ( typeof window.lumiaShowToast === 'function' ) {
					window.lumiaShowToast( msg, 'warning' );
				} else if ( tries++ < 20 ) {
					// The toast script is loaded in the footer: a few loop turns
					// are allowed before silently giving up.
					window.setTimeout( show, 100 );
				}
			} )();
			try {
				var url = new URL( window.location.href );
				url.searchParams.delete( 'lumia_denied' );
				window.history.replaceState( null, '', url.toString() );
			} catch ( e ) {}
		} )();
		</script>
		<?php
	}

	/**
	 * Adds target="_blank" / rel="noopener" to the custom links that ask for it:
	 * add_menu_page() cannot set a target attribute, so it is done on the DOM
	 * side after the menu is rendered.
	 */
	public function inject_custom_link_targets(): void {
		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return;
		}

		$slugs = [];
		foreach ( $profile['items'] as $item ) {
			if ( 'custom_link' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			if ( empty( $item['target_blank'] ) || empty( $item['url'] ) ) {
				continue;
			}
			$slugs[] = $this->custom_link_slug( $item['url'] );
		}

		if ( ! $slugs ) {
			return;
		}
		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var slugs = <?php echo wp_json_encode( $slugs ); ?>;
			var links = document.querySelectorAll( '#adminmenu a.menu-top' );
			slugs.forEach( function ( slug ) {
				for ( var i = 0; i < links.length; i++ ) {
					if ( ( links[ i ].getAttribute( 'href' ) || '' ).indexOf( slug ) !== -1 ) {
						links[ i ].setAttribute( 'target', '_blank' );
						links[ i ].setAttribute( 'rel', 'noopener noreferrer' );
					}
				}
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Injects the custom SVG/media icons into the real admin menu.
	 *
	 * Two rendering modes depending on the source (see resolve_icon_render):
	 * - Monochrome SVG (internal library, uploaded Lucide file…): rendered as a
	 *   CSS mask colored through background-color. An <img> cannot inherit a
	 *   text color, so its `currentColor` falls back to black — invisible on the
	 *   dark sidebar. The mask only keeps the shape and applies the dashicons'
	 *   resting color (white on hover, like the native icons).
	 * - Other media (PNG/JPG, already colorized SVG): rendered as an <img>, the
	 *   original colors are preserved.
	 *
	 * Targeting uses the <li> id attribute (index 5 of $menu), not an href:
	 * several plugins (WooCommerce "Marketing", "Analytics") register their page
	 * under a slug and then rewrite the real menu URL, so the href no longer
	 * contains the slug and no icon was applied. The id is unique and remains
	 * derived from the registration slug.
	 */
	public function inject_menu_icon_overrides(): void {
		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return;
		}

		// Sub-items are taken into account in addition to the top-level items:
		// for a role with reduced capabilities (e.g. author), WordPress promotes
		// some submenus to top-level items (profile.php "Profile" replaces
		// users.php "Users"). The JS only targets .menu-top, so on the admin
		// side — where the sub-item stays a submenu — this has no visible
		// effect.
		$candidates = [];
		foreach ( $profile['items'] as $item ) {
			$candidates[] = $item;
			foreach ( (array) ( $item['children'] ?? [] ) as $child ) {
				$candidates[] = $child;
			}
		}

		$icons = [];
		foreach ( $candidates as $item ) {
			if ( empty( $item['icon'] ) ) {
				continue;
			}
			$icon = $item['icon'];

			// A custom link is registered in $menu (hence in the real href) under
			// the slug derived by add_menu_page (plugin_basename of the URL), not
			// under its internal slug generated client-side nor the raw URL (see
			// apply_menu_visibility / apply_menu_order).
			$match_slug = 'custom_link' === ( $item['type'] ?? 'wp_item' )
				? ( ! empty( $item['url'] ) ? $this->custom_link_slug( $item['url'] ) : '' )
				: ( $item['slug'] ?? '' );

			if ( empty( $match_slug ) ) {
				continue;
			}

			$render = $this->resolve_icon_render( (string) $icon );
			if ( null === $render ) {
				continue;
			}

			$icons[] = [
				'slug' => sanitize_text_field( $match_slug ),
				'id'   => $this->menu_dom_id( $match_slug ),
				'src'  => $render['src'],
				'mask' => $render['mask'],
			];
		}

		if ( ! $icons ) {
			return;
		}

		// Immediate CSS (before paint, admin_head): hides the original dashicon
		// until the JS has replaced the content, to avoid the "dashicon then
		// custom icon" flash on load. The inline style set afterwards by the
		// script (opacity:1 !important) takes over, an inline important
		// declaration always beating an important stylesheet rule on the same
		// property.
		$hide_css = '';
		foreach ( $icons as $entry ) {
			// Attribute selector rather than #id: a WP menu id may contain dots
			// (toplevel_page_admin-page-wc-settings…), which "#id" would interpret
			// as a class selector.
			$hide_css .= $entry['id']
				? '#adminmenu li[id="' . str_replace( '"', '', $entry['id'] ) . '"] .wp-menu-image{opacity:0!important}'
				: '#adminmenu a[href*="' . str_replace( [ '"', '<', '>', '\\' ], '', $entry['slug'] ) . '"] .wp-menu-image{opacity:0!important}';
		}

		// Mask rendering of the icons: the shape comes from the SVG, the color
		// from the stylesheet — hence aligned with the native dashicons, hover
		// included.
		$hide_css .= '#adminmenu .lumia-mc-icon{display:block;width:20px;height:20px;margin:7px auto 0;'
			. 'background-color:#f3f1f1;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;'
			. '-webkit-mask-position:center;mask-position:center;-webkit-mask-size:contain;mask-size:contain}'
			. '#adminmenu li.menu-top:hover .lumia-mc-icon,#adminmenu li.current .lumia-mc-icon,'
			. '#adminmenu li.wp-has-current-submenu .lumia-mc-icon,#adminmenu a.current .lumia-mc-icon'
			. '{background-color:#fff}';

		echo '<style>' . $hide_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		?>
		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			var icons = <?php echo wp_json_encode( $icons ); ?>;
			var links = document.querySelectorAll( '#adminmenu a.menu-top' );
			icons.forEach( function ( entry ) {
				// Main targeting: the <li> id, derived from the registration slug
				// and therefore reliable even when the plugin rewrites the menu
				// URL.
				var link = null;
				if ( entry.id ) {
					var li = document.getElementById( entry.id );
					link = li ? li.querySelector( 'a.menu-top' ) : null;
				}
				// Historical fallback on the href for entries without an id (menu
				// built by hand by a plugin, promoted separators…).
				for ( var i = 0; ! link && i < links.length; i++ ) {
					if ( ( links[ i ].getAttribute( 'href' ) || '' ).indexOf( entry.slug ) !== -1 ) {
						link = links[ i ];
					}
				}
				if ( ! link ) {
					return;
				}
				var imgEl = link.querySelector( '.wp-menu-image' );
				if ( ! imgEl ) {
					return;
				}
				// The original dashicon is rendered through a CSS ::before on the
				// dashicons-before/dashicons-xxx classes: emptying innerHTML does
				// not remove it (pseudo-elements are not part of the DOM). For
				// custom links, WP may also have set an inline background-image
				// itself (via add_menu_page + icon_url data:) on that same element:
				// we start again from a totally blank style.
				imgEl.className = 'wp-menu-image';
				imgEl.removeAttribute( 'style' );
				// WP applies opacity:.6 to #adminmenu .wp-menu-image img: opacity:1
				// must be forced with !important to beat that rule, and to reveal
				// the icon temporarily hidden by the <style> above.
				imgEl.style.setProperty( 'opacity', '1', 'important' );
				imgEl.innerHTML = '';
				if ( entry.mask ) {
					var span = document.createElement( 'span' );
					span.className = 'lumia-mc-icon';
					span.style.setProperty( '-webkit-mask-image', 'url("' + entry.src + '")' );
					span.style.setProperty( 'mask-image', 'url("' + entry.src + '")' );
					imgEl.appendChild( span );
					return;
				}
				var img = document.createElement( 'img' );
				img.src = entry.src;
				img.alt = '';
				img.style.cssText = 'width:20px;height:20px;object-fit:contain;';
				img.style.setProperty( 'opacity', '1', 'important' );
				// Vertically aligns with the native dashicons: WP forces
				// padding-top:9px on .wp-menu-image img (dashicons: 7px on each
				// side for a 20px glyph in a 34px container).
				img.style.setProperty( 'padding-top', '7px', 'important' );
				imgEl.appendChild( img );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Forces full opacity on the menu icons, native AND custom: by default WP
	 * dims #adminmenu .wp-menu-image img to 60% until the item is hovered or
	 * active — a behavior judged hard to read, to be made uniform regardless of
	 * an active Menu Creator profile.
	 *
	 * Also fixes the vertical alignment: WP applies padding:9px 0 0 to <img>
	 * icons (e.g. the plugin's own logo) while the dashicons are centered at
	 * 7px — hence a 2px offset. ONLY padding-top is overridden (no shorthand and
	 * no box-sizing:border-box, which would pull the padding INSIDE the 20x20
	 * size set inline on our injected custom icons and shrink/misalign them).
	 */
	public function inject_global_icon_opacity_fix(): void {
		echo '<style>#adminmenu .wp-menu-image img{opacity:1!important;padding-top:7px!important}</style>';
	}

	/**
	 * Cleans a WP menu title for the editor.
	 *
	 * WordPress and plugins stick their counters in the title itself, as a
	 * <span>: "Comments <span class="awaiting-mod">0</span>",
	 * "Plugins <span class="update-plugins">0</span>". wp_strip_all_tags()
	 * removes the markup but keeps the number, hence absurd labels like
	 * "Comments 00 comments in moderation" in the tree. The <span> elements are
	 * therefore removed along with their content: in a menu title they only ever
	 * carry these bubbles.
	 */
	private function clean_menu_label( string $raw ): string {
		$clean = preg_replace( '#<span(?:\s[^>]*)?>.*?</span>#is', '', $raw );
		$clean = wp_strip_all_tags( (string) $clean );
		return trim( (string) preg_replace( '/\s+/u', ' ', $clean ) );
	}

	/**
	 * WP menu slugs never surfaced in the editor (and therefore never applied).
	 *
	 * The Link Manager (link-manager.php + its link_category taxonomy) is a
	 * legacy feature disabled by default since WP 3.5: when it is off, it does
	 * not appear in the real WP menu, so surfacing it in the Creator would
	 * create a confusing ghost item. It is hidden by default. Filterable for the
	 * sites that really use it.
	 *
	 * @return array<int, string>
	 */
	private function editor_excluded_slugs(): array {
		return (array) Compat::apply_filters(
			'mc_editor_excluded_slugs',
			[
				'link-manager.php',
			]
		);
	}

	/**
	 * Reproduces the transformation add_menu_page() applies to a menu_slug:
	 * plugin_basename( esc_url_raw( $url ) ). The custom link really exists in
	 * $menu / $menu_order under THIS slug — matching it this way guarantees that
	 * the chosen order and the icon override target the right entry (instead of
	 * letting the link fall to the bottom of the menu).
	 */
	private function custom_link_slug( string $url ): string {
		return plugin_basename( esc_url_raw( $url ) );
	}

	/**
	 * Decides how to render an icon stored in the real admin menu.
	 *
	 * A monochrome SVG (stroked in `currentColor`) cannot be rendered as an
	 * <img>: outside the inline DOM, `currentColor` inherits no color and falls
	 * back to black — hence invisible on the dark sidebar. These icons are
	 * rendered as a CSS mask (mask=true) and colorized by the stylesheet. Other
	 * media keep their colors through <img>.
	 *
	 * @return array{src:string, mask:bool}|null Null if the value is unusable.
	 */
	private function resolve_icon_render( string $icon ): ?array {
		if ( 0 === strpos( $icon, 'svg:' ) ) {
			$svg_xml = base64_decode( substr( $icon, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- sanitized SVG icon, stored and rendered as a data URI.
			if ( false === $svg_xml ) {
				return null;
			}
			return [
				'src'  => 'data:image/svg+xml;base64,' . base64_encode( $svg_xml ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- sanitized SVG icon, stored and rendered as a data URI.
				'mask' => self::svg_is_monochrome( $svg_xml ),
			];
		}

		if ( 0 === strpos( $icon, 'http' ) ) {
			$local = $this->read_local_svg( $icon );
			return [
				'src'  => esc_url( $icon ),
				'mask' => null !== $local && self::svg_is_monochrome( $local ),
			];
		}

		return null;
	}

	/**
	 * Is an SVG monochrome, hence recolorable through a mask without losing
	 * anything?
	 *
	 * True if the stroke is `currentColor`, if it declares no color (black by
	 * default) or if it uses only one. False as soon as there are several: the
	 * plugin's own logo (white square + black glyph) must not be flattened into
	 * a solid silhouette — it keeps its <img>.
	 */
	public static function svg_is_monochrome( string $xml ): bool {
		if ( false !== stripos( $xml, 'currentcolor' ) ) {
			return true;
		}
		preg_match_all(
			'/(?:fill|stroke|stop-color)\s*[:=]\s*["\']?\s*(#[0-9a-f]{3,8}|rgba?\([^)]*\)|[a-z]+)/i',
			$xml,
			$matches
		);
		$colors = [];
		foreach ( $matches[1] as $color ) {
			$color = strtolower( trim( $color ) );
			if ( in_array( $color, [ 'none', 'transparent', 'inherit', 'currentcolor' ], true ) ) {
				continue;
			}
			$colors[ $color ] = true;
		}
		return count( $colors ) <= 1;
	}

	/**
	 * Returns the id attribute WordPress renders on the <li> of a menu item
	 * (menu-header.php: preg_replace on index 5 of $menu), or '' if the item is
	 * not found / has no hookname.
	 */
	private function menu_dom_id( string $slug ): string {
		global $menu;
		if ( ! is_array( $menu ) ) {
			return '';
		}
		foreach ( $menu as $menu_item ) {
			if ( ! is_array( $menu_item ) || ( $menu_item[2] ?? '' ) !== $slug ) {
				continue;
			}
			if ( empty( $menu_item[5] ) ) {
				return '';
			}
			return (string) preg_replace( '|[^a-zA-Z0-9_:.]|', '-', (string) $menu_item[5] );
		}
		return '';
	}

	/**
	 * Reads the content of an SVG hosted by this site (media library or
	 * wp-content), to know whether it is monochrome or to inline it. Returns
	 * null as soon as the file is not a readable local SVG — a remote resource
	 * is never fetched from an admin page render.
	 *
	 * The result is memoized: the method is called on every admin page load,
	 * once per icon.
	 */
	private function read_local_svg( string $url ): ?string {
		static $cache = [];
		if ( array_key_exists( $url, $cache ) ) {
			return $cache[ $url ];
		}
		$cache[ $url ] = null;

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path || '.svg' !== strtolower( substr( $path, -4 ) ) ) {
			return null;
		}

		// Scheme-insensitive comparison: the stored URL may have been saved as
		// http while the site now answers over https.
		$strip    = static fn( string $u ): string => (string) preg_replace( '#^https?:#i', '', $u );
		$bare_url = $strip( $url );
		$uploads  = wp_upload_dir();
		$file     = '';

		if ( empty( $uploads['error'] ) && 0 === strpos( $bare_url, $strip( $uploads['baseurl'] ) ) ) {
			$file = $uploads['basedir'] . substr( $bare_url, strlen( $strip( $uploads['baseurl'] ) ) );
		} elseif ( 0 === strpos( $bare_url, $strip( content_url() ) ) ) {
			$file = WP_CONTENT_DIR . substr( $bare_url, strlen( $strip( content_url() ) ) );
		}

		if ( '' === $file ) {
			return null;
		}

		$file = strtok( $file, '?#' );
		$real = realpath( $file );
		// realpath + prefix: neutralizes any ../ in the stored URL.
		if ( ! $real || 0 !== strpos( $real, (string) realpath( WP_CONTENT_DIR ) ) || ! is_readable( $real ) ) {
			return null;
		}
		$size = filesize( $real );
		if ( false === $size || $size > 200000 ) {
			return null;
		}

		$contents = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		$cache[ $url ] = $contents;
		return $contents;
	}

	/**
	 * Resolves a native WP icon_url (dashicon, SVG data URI, or media URL) from
	 * the internal format stored client-side.
	 */
	private function resolve_native_icon_url( ?string $icon ): string {
		if ( empty( $icon ) ) {
			return 'dashicons-admin-links';
		}
		if ( strpos( $icon, 'dashicons-' ) === 0 ) {
			return sanitize_text_field( $icon );
		}
		if ( strpos( $icon, 'svg:' ) === 0 ) {
			$svg_b64 = substr( $icon, 4 );
			$svg_xml = base64_decode( $svg_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- sanitized SVG icon, stored and rendered as a data URI.
			if ( false === $svg_xml ) {
				return 'dashicons-admin-links';
			}
			return 'data:image/svg+xml;base64,' . base64_encode( $this->neutralize_svg_color( $svg_xml ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- sanitized SVG icon, stored and rendered as a data URI.
		}
		if ( strpos( $icon, 'http' ) === 0 ) {
			// A monochrome SVG served by URL would display in black (currentColor
			// inherits nothing in a background-image): it is inlined and tinted,
			// like for the internal library.
			$local = $this->read_local_svg( $icon );
			if ( null !== $local && false !== stripos( $local, 'currentColor' ) ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $this->neutralize_svg_color( $local ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- sanitized SVG icon, stored and rendered as a data URI.
			}
			return esc_url_raw( $icon );
		}
		return 'dashicons-admin-links';
	}

	/**
	 * Freezes "currentColor" to the real resting color of the dashicons glyphs
	 * of this admin skin (#f3f1f1, see colors/modern/colors.css): rendered as
	 * <img>/background-image (no inline DOM), currentColor cannot inherit any
	 * surrounding text color and would otherwise resolve to black by default.
	 * Fixed (no white hover variant) — the visual gap with the pure white of the
	 * native hover is minimal.
	 */
	private function neutralize_svg_color( string $svg_xml ): string {
		return str_replace( 'currentColor', '#f3f1f1', $svg_xml );
	}

	/* ================================================================
	 * AJAX
	 * ================================================================ */

	public function ajax_save_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing data.', 'lumia-tools' ) ] );
		}

		$profile = json_decode( $raw, true );
		if ( ! is_array( $profile ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid JSON.', 'lumia-tools' ) ] );
		}

		$sanitized = $this->sanitize_profile( $profile );
		MenuProfileManager::save( $sanitized );
		wp_send_json_success( [ 'profile' => $sanitized ] );
	}

	public function ajax_delete_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		if ( empty( $profile_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing ID.', 'lumia-tools' ) ] );
		}

		MenuProfileManager::delete( $profile_id );
		wp_send_json_success();
	}

	public function ajax_duplicate_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		$original   = MenuProfileManager::get( $profile_id );
		if ( ! $original ) {
			wp_send_json_error( [ 'message' => __( 'Profile not found.', 'lumia-tools' ) ] );
		}

		$copy               = $original;
		$copy['id']         = wp_generate_uuid4();
		$copy['name']       = $original['name'] . ' ' . __( '(copy)', 'lumia-tools' );
		$copy['status']     = 'draft';
		$copy['updated_at'] = time();

		MenuProfileManager::save( $copy );
		wp_send_json_success( [ 'profile' => $copy ] );
	}

	/* ================================================================
	 * EXPORT / IMPORT
	 * ================================================================ */

	/**
	 * The menu profiles live under their own option
	 * (`lumia_wl_menu_profiles`), not in `lumia_module_menu_creator`: without
	 * this block, the plugin configuration export would leave them out.
	 *
	 * @return array<string, mixed>
	 */
	public function get_export_extras(): array {
		$profiles = MenuProfileManager::get_all();
		return $profiles ? [ 'menu_profiles' => $profiles ] : [];
	}

	/**
	 * Replays the profiles of an imported file.
	 *
	 * Each profile goes through sanitize_profile() again — the JSON never goes
	 * straight to the database. Merge by id (MenuProfileManager::save overwrites
	 * the entry with the same id, adds otherwise): a partial file does not
	 * delete any existing menu on the site.
	 *
	 * @param array<string, mixed> $extras
	 */
	public function import_extras( array $extras ): void {
		if ( empty( $extras['menu_profiles'] ) || ! is_array( $extras['menu_profiles'] ) ) {
			return;
		}
		foreach ( $extras['menu_profiles'] as $profile ) {
			if ( is_array( $profile ) ) {
				MenuProfileManager::save( $this->sanitize_profile( $profile ) );
			}
		}
	}

	/**
	 * Import of menus exported from another site.
	 *
	 * Three file forms are accepted: a bare profile, a single-menu envelope
	 * (`{profile: …}`) and the multi-menu envelope (`{profiles: […]}`), so that
	 * the editor's two export buttons are read back by the same endpoint.
	 *
	 * The JSON never goes straight into the option: each menu goes through
	 * sanitize_profile(), exactly like a save from the editor. A menu whose id
	 * already exists updates the existing one, otherwise it is added — a partial
	 * file therefore deletes nothing. All arrive as drafts, so as not to replace
	 * the users' active menu without warning.
	 */
	public function ajax_import_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing data.', 'lumia-tools' ) ] );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => __( 'Unreadable file: invalid JSON.', 'lumia-tools' ) ] );
		}

		if ( isset( $decoded['profiles'] ) && is_array( $decoded['profiles'] ) ) {
			$incoming = $decoded['profiles'];
		} elseif ( isset( $decoded['profile'] ) && is_array( $decoded['profile'] ) ) {
			$incoming = [ $decoded['profile'] ];
		} else {
			$incoming = [ $decoded ];
		}

		$saved   = [];
		$updated = 0;
		foreach ( $incoming as $profile ) {
			if ( ! is_array( $profile ) || ( empty( $profile['items'] ) && empty( $profile['name'] ) ) ) {
				continue;
			}
			if ( ! empty( $profile['id'] ) && null !== MenuProfileManager::get( (string) $profile['id'] ) ) {
				++$updated;
			}
			$sanitized           = $this->sanitize_profile( $profile );
			$sanitized['status'] = 'draft';
			if ( '' === $sanitized['name'] ) {
				$sanitized['name'] = __( 'Imported menu', 'lumia-tools' );
			}
			MenuProfileManager::save( $sanitized );
			$saved[] = $sanitized;
		}

		if ( ! $saved ) {
			wp_send_json_error( [ 'message' => __( 'This file does not contain any menu.', 'lumia-tools' ) ] );
		}

		wp_send_json_success(
			[
				'profiles' => $saved,
				'profile'  => $saved[0],
				'updated'  => $updated,
			]
		);
	}

	/**
	 * Sanitizes an SVG pasted into the icon picker.
	 *
	 * Reuses the Image Optimizer module's sanitizer rather than writing a
	 * second one: it is the same risk (script, external href, XXE) and the same
	 * allowlist. The SVG is only stored once it has gone through this filter.
	 */
	public function ajax_sanitize_svg(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['svg'] ) ? wp_unslash( $_POST['svg'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Paste the code of an SVG.', 'lumia-tools' ) ] );
		}
		if ( strlen( $raw ) > 100000 ) {
			wp_send_json_error( [ 'message' => __( 'SVG too large (100 KB maximum).', 'lumia-tools' ) ] );
		}

		if ( ! class_exists( SvgHandler::class ) ) {
			wp_send_json_error( [ 'message' => __( 'SVG sanitizing unavailable.', 'lumia-tools' ) ] );
		}

		$handler = new SvgHandler( [] );
		$clean   = $handler->sanitize( $raw );
		if ( null === $clean ) {
			wp_send_json_error( [ 'message' => __( 'This SVG is invalid or contains disallowed code.', 'lumia-tools' ) ] );
		}

		wp_send_json_success( [ 'icon' => 'svg:' . base64_encode( $clean ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- sanitized SVG icon, stored and rendered as a data URI.
	}

	public function ajax_search_users(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$users = get_users(
			[
				'search' => '*' . $query . '*',
				'number' => 20,
			]
		);

		$result = array_map(
			fn( \WP_User $u ) => [
				'id'    => $u->ID,
				'label' => $u->display_name . ' (' . $u->user_login . ')',
			],
			$users
		);
		wp_send_json_success( $result );
	}

	/* ================================================================
	 * HELPERS
	 * ================================================================ */

	/**
	 * @param array<string, mixed> $profile
	 * @return array<string, mixed>
	 */
	private function sanitize_profile( array $profile ): array {
		return [
			'id'            => ! empty( $profile['id'] ) ? sanitize_text_field( $profile['id'] ) : wp_generate_uuid4(),
			'name'          => sanitize_text_field( $profile['name'] ?? '' ),
			'status'        => in_array( $profile['status'] ?? '', [ 'draft', 'active' ], true ) ? $profile['status'] : 'draft',
			'apply_to_all'  => ! empty( $profile['apply_to_all'] ),
			'include_roles' => array_map( 'sanitize_key', (array) ( $profile['include_roles'] ?? [] ) ),
			'include_users' => array_map( 'absint', (array) ( $profile['include_users'] ?? [] ) ),
			'exclude_roles' => array_map( 'sanitize_key', (array) ( $profile['exclude_roles'] ?? [] ) ),
			'exclude_users' => array_map( 'absint', (array) ( $profile['exclude_users'] ?? [] ) ),
			'items'         => $this->sanitize_menu_items( (array) ( $profile['items'] ?? [] ) ),
			'updated_at'    => time(),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private function sanitize_menu_items( array $items, int $depth = 0 ): array {
		// Payload safeguard: bounds the number of entries persisted per level, to
		// keep a pathological profile from bloating the option.
		$items     = array_slice( array_values( $items ), 0, self::MAX_ITEMS );
		$sanitized = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type     = in_array( $item['type'] ?? '', [ 'wp_item', 'custom_link', 'separator' ], true )
				? $item['type'] : 'wp_item';
			$children = [];
			if ( 0 === $depth && ! empty( $item['children'] ) ) {
				$children = $this->sanitize_menu_items( (array) $item['children'], 1 );
			}
			$visible     = isset( $item['visible'] ) ? (bool) $item['visible'] : true;
			$sanitized[] = [
				'type'         => $type,
				'slug'         => sanitize_text_field( $item['slug'] ?? '' ),
				'label'        => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : null,
				'icon'         => $this->sanitize_icon_value( $item['icon'] ?? null ),
				'visible'      => $visible,
				// Only makes sense on a hidden item: a visible and blocked item
				// would be a trap (displayed link leading to a denial).
				'block_access' => ! $visible && ! empty( $item['block_access'] ),
				'target_blank' => ! empty( $item['target_blank'] ),
				'url'          => 'custom_link' === $type ? esc_url_raw( $item['url'] ?? '' ) : '',
				// Role restriction, custom links only (WP items are already
				// filtered by their own capabilities).
				'roles'        => 'custom_link' === $type
					? array_values( array_filter( array_map( 'sanitize_key', (array) ( $item['roles'] ?? [] ) ) ) )
					: [],
				'children'     => $children,
			];
		}
		return $sanitized;
	}

	/**
	 * Sanitizes the icon value: dashicon / "svg:<base64>" through
	 * sanitize_text_field, but media URL through esc_url_raw.
	 *
	 * @param mixed $icon
	 */
	private function sanitize_icon_value( $icon ): ?string {
		if ( null === $icon || '' === $icon ) {
			return null;
		}
		$icon = (string) $icon;
		if ( strpos( $icon, 'http' ) === 0 ) {
			return esc_url_raw( $icon );
		}
		return sanitize_text_field( $icon );
	}

	/* ================================================================
	 * SETTINGS
	 * ================================================================ */

	/**
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return [];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function save_settings( array $settings ): bool {
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return [];
	}

	public static function get_uninstall_keys(): array {
		return [
			'options' => [ MenuProfileManager::OPTION_KEY, MenuProfileManager::CACHE_GEN_OPTION ],
			'meta'    => [],
		];
	}

	/* ================================================================
	 * ASSETS
	 * ================================================================ */

	public function get_admin_css(): array {
		return [ LUMIA_ASSETS_URL . 'admin/css/modules/menu-creator.css' ];
	}

	public function get_admin_js(): array {
		return [ LUMIA_ASSETS_URL . 'admin/js/modules/menu-creator.js' ];
	}

	/**
	 * SortableJS is declared as a dependency rather than returned by
	 * get_admin_js(): the Media module loads the same file, and two identical
	 * URLs under two different handles were served twice.
	 */
	public function get_admin_js_deps(): array {
		return [ 'lumia-sortable-js' ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_admin_js_data(): array {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data = [
			'mcProfiles' => MenuProfileManager::get_all(),
			// Strings read by menu-creator.js through lumiaAdmin.i18n.
			'i18n'       => [
				'newMenu'               => __( 'New menu', 'lumia-tools' ),
				'draft'                 => __( 'Draft', 'lumia-tools' ),
				'active'                => __( 'Active', 'lumia-tools' ),
				'unsavedChanges'        => __( 'Unsaved changes', 'lumia-tools' ),
				'leaveConfirm'          => __( 'Your changes will be lost. Continue?', 'lumia-tools' ),
				'deleteConfirmMsg'      => __( 'This action cannot be undone.', 'lumia-tools' ),
				'nothingToUndo'         => __( 'Nothing to undo.', 'lumia-tools' ),
				'changeUndone'          => __( 'Change undone.', 'lumia-tools' ),
				'nothingToRedo'         => __( 'Nothing to redo.', 'lumia-tools' ),
				'changeRedone'          => __( 'Change redone.', 'lumia-tools' ),
				'nothingToSave'         => __( 'No changes to save.', 'lumia-tools' ),
				'continueWithoutSaving' => __( 'Continue without saving', 'lumia-tools' ),
				/* translators: %d: sequence number of a new menu. */
				'menuNumbered'          => __( 'Menu %d', 'lumia-tools' ),
				/* translators: %s: label of the “New menu” button. */
				'noMenuYet'             => __( 'No menu yet. Use “%s” to create one.', 'lumia-tools' ),
				'noResults'             => __( 'No results.', 'lumia-tools' ),
				'menuActive'            => __( 'Active menu', 'lumia-tools' ),
				'draftNotApplied'       => __( 'Draft — not applied', 'lumia-tools' ),
				'unnamedMenu'           => __( 'Untitled menu', 'lumia-tools' ),
				'notSaved'              => __( 'Not saved', 'lumia-tools' ),
				'duplicateMenu'         => __( 'Duplicate this menu', 'lumia-tools' ),
				'deleteMenu'            => __( 'Delete this menu', 'lumia-tools' ),
				'thisMenu'              => __( 'this menu', 'lumia-tools' ),
				'deleteMenuTitle'       => __( 'Delete menu', 'lumia-tools' ),
				/* translators: %s: name of the menu to delete. */
				'deleteMenuPrompt'      => __( 'Delete “%s”?', 'lumia-tools' ),
				'delete'                => __( 'Delete', 'lumia-tools' ),
				'menuDeleted'           => __( 'Menu deleted.', 'lumia-tools' ),
				'deleteError'           => __( 'Error while deleting.', 'lumia-tools' ),
				'menuDuplicated'        => __( 'Menu duplicated.', 'lumia-tools' ),
				'duplicateError'        => __( 'Error while duplicating.', 'lumia-tools' ),
				'resetMenuTitle'        => __( 'Reset menu', 'lumia-tools' ),
				'resetMenuMessage'      => __( 'All unsaved changes will be lost and the menu will be reloaded from the last save.', 'lumia-tools' ),
				'reset'                 => __( 'Reset', 'lumia-tools' ),
				'menuSettings'          => __( 'Menu settings', 'lumia-tools' ),
				'item'                  => __( 'Item', 'lumia-tools' ),
				/* translators: %d: number of stale menu entries (singular). */
				'staleEntriesOne'       => __( '%d stale entry', 'lumia-tools' ),
				/* translators: %d: number of stale menu entries (plural). */
				'staleEntriesMany'      => __( '%d stale entries', 'lumia-tools' ),
				/* translators: %d: number of additional stale entries not listed by name. */
				'staleAndMore'          => __( 'and %d more', 'lumia-tools' ),
				'staleHint'             => __( 'These slugs do not match any current WordPress menu.', 'lumia-tools' ),
				'cleanUp'               => __( 'Clean up', 'lumia-tools' ),
				'cleanStaleTitle'       => __( 'Clean up stale entries', 'lumia-tools' ),
				/* translators: %d: number of stale entries about to be removed. */
				'cleanStaleMessage'     => __( 'Remove %d item(s) from this menu? If the related plugin is reactivated, the item will come back with its default settings.', 'lumia-tools' ),
				'staleRemoved'          => __( 'Stale entries removed. Remember to save.', 'lumia-tools' ),
				'showInMenu'            => __( 'Show in menu', 'lumia-tools' ),
				'hideFromMenu'          => __( 'Hide from menu', 'lumia-tools' ),
				'moveUp'                => __( 'Move up', 'lumia-tools' ),
				'moveDown'              => __( 'Move down', 'lumia-tools' ),
				'hiddenAndBlocked'      => __( 'Hidden and direct access blocked', 'lumia-tools' ),
				'staleTip'              => __( 'Entry missing from the current WordPress menu — plugin deactivated or removed.', 'lumia-tools' ),
				'newLink'               => __( 'New link', 'lumia-tools' ),
				'separatorNoSettings'   => __( 'Separator — no settings.', 'lumia-tools' ),
				'urlField'              => __( 'URL', 'lumia-tools' ),
				'restrictToRoles'       => __( 'Restricted to roles', 'lumia-tools' ),
				'restrictToRolesHelp'   => __( 'Leave empty to show this link to everyone who sees this menu.', 'lumia-tools' ),
				'labelField'            => __( 'Label', 'lumia-tools' ),
				'labelHelp'             => __( 'Leave empty to keep the original label.', 'lumia-tools' ),
				'iconField'             => __( 'Icon', 'lumia-tools' ),
				'iconChildHelp'         => __( 'Only applied when this item becomes a top-level menu (roles with limited capabilities, e.g. “Profile” for authors).', 'lumia-tools' ),
				'visible'               => __( 'Visible', 'lumia-tools' ),
				'blockAccess'           => __( 'Block direct access', 'lumia-tools' ),
				'blockAccessTip'        => __( 'This is not a permissions system: the REST API, WP-CLI and WordPress capabilities are not affected.', 'lumia-tools' ),
				'blockAccessHelp'       => __( 'Hiding only removes the link: the page stays reachable by its URL. Tick to deny it too (redirect to the dashboard).', 'lumia-tools' ),
				'openInNewTab'          => __( 'Open in a new tab', 'lumia-tools' ),
				'resetItem'             => __( 'Reset item', 'lumia-tools' ),
				'itemReset'             => __( 'Item reset.', 'lumia-tools' ),
				'change'                => __( 'Change', 'lumia-tools' ),
				'chooseIcon'            => __( 'Choose an icon', 'lumia-tools' ),
				'menuExported'          => __( 'Menu exported.', 'lumia-tools' ),
				'noMenuToExport'        => __( 'No menu to export.', 'lumia-tools' ),
				'unsavedNotExported'    => __( 'Unsaved changes are not in the export.', 'lumia-tools' ),
				/* translators: %d: number of exported menus (singular). */
				'menusExportedOne'      => __( '%d menu exported.', 'lumia-tools' ),
				/* translators: %d: number of exported menus (plural). */
				'menusExportedMany'     => __( '%d menus exported.', 'lumia-tools' ),
				'importFailed'          => __( 'Import failed.', 'lumia-tools' ),
				/* translators: %d: number of imported menus. */
				'menusImported'         => __( '%d menus imported as drafts.', 'lumia-tools' ),
				'menuImported'          => __( 'Menu imported as a draft.', 'lumia-tools' ),
				/* translators: %d: number of existing menus that were updated by the import. */
				'existingUpdatedMany'   => __( '%d existing menus updated.', 'lumia-tools' ),
				'existingUpdatedOne'    => __( '1 existing menu updated.', 'lumia-tools' ),
				'fileReadFailed'        => __( 'Unable to read the file.', 'lumia-tools' ),
				'libraryEmpty'          => __( 'Empty library.', 'lumia-tools' ),
				'icons'                 => __( 'Icons', 'lumia-tools' ),
				'noIcon'                => __( 'No icon.', 'lumia-tools' ),
				'restoreOriginalIcon'   => __( 'Restore the original icon', 'lumia-tools' ),
				'removeIcon'            => __( 'Remove the icon', 'lumia-tools' ),
				'tabLibrary'            => __( 'Library', 'lumia-tools' ),
				'tabMedia'              => __( 'Media library', 'lumia-tools' ),
				'tabCode'               => __( 'SVG code', 'lumia-tools' ),
				'searchIcon'            => __( 'Search an icon…', 'lumia-tools' ),
				'openMediaLibrary'      => __( 'Open the media library', 'lumia-tools' ),
				'svgHelp'               => __( 'Paste the code of an SVG (Lucide, Heroicons…). It is cleaned on the server: scripts, external links and entities are removed. A stroke set to <code>currentColor</code> automatically takes the color of the menu theme.', 'lumia-tools' ),
				'useThisSvg'            => __( 'Use this SVG', 'lumia-tools' ),
				'pasteSvg'              => _x( 'Paste the code of an SVG.', 'toast message', 'lumia-tools' ),
				'svgRejected'           => __( 'SVG rejected.', 'lumia-tools' ),
				'svgApplied'            => __( 'SVG icon applied.', 'lumia-tools' ),
				'menuSaved'             => __( 'Menu saved.', 'lumia-tools' ),
				'saveFailed'            => __( 'Error while saving.', 'lumia-tools' ),
				'search'                => __( 'Search…', 'lumia-tools' ),
				'role'                  => __( 'Role', 'lumia-tools' ),
				'user'                  => __( 'User', 'lumia-tools' ),
			],
		];

		if ( 'module_menu_creator' === $tab && is_admin() && current_user_can( 'manage_options' ) ) {
			global $menu, $submenu;
			// Original WP menu if available (captured before our modifications),
			// otherwise the global (already pristine when no profile is active).
			$src_menu    = null !== self::$pristine_menu ? self::$pristine_menu : ( is_array( $menu ) ? $menu : [] );
			$src_submenu = null !== self::$pristine_submenu ? self::$pristine_submenu : ( is_array( $submenu ) ? $submenu : [] );
			$excluded    = $this->editor_excluded_slugs();
			$wp_menu     = [];
			foreach ( $src_menu as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( in_array( $item[2] ?? '', $excluded, true ) ) {
					continue;
				}
				$wp_menu[] = [
					'label' => $this->clean_menu_label( $item[0] ?? '' ),
					'cap'   => $item[1] ?? 'read',
					'slug'  => $item[2] ?? '',
					'icon'  => $item[6] ?? '',
				];
			}
			$wp_submenu = [];
			if ( is_array( $src_submenu ) ) {
				foreach ( $src_submenu as $parent => $subs ) {
					if ( in_array( $parent, $excluded, true ) ) {
						continue;
					}
					$wp_submenu[ $parent ] = [];
					foreach ( (array) $subs as $item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}
						$wp_submenu[ $parent ][] = [
							'label' => $this->clean_menu_label( $item[0] ?? '' ),
							'cap'   => $item[1] ?? 'read',
							'slug'  => $item[2] ?? '',
						];
					}
				}
			}
			$recent_users           = get_users(
				[
					'number'  => 30,
					'orderby' => 'registered',
					'order'   => 'DESC',
				]
			);
			$data['wpMenu']         = $wp_menu;
			$data['wpSubmenu']      = $wp_submenu;
			$data['wpRoles']        = wp_roles()->get_names();
			$data['wpRecentUsers']  = array_map(
				function ( \WP_User $u ) {
					return [
						'id'    => (int) $u->ID,
						'label' => $u->display_name . ' (' . $u->user_login . ')',
					];
				},
				$recent_users
			);
			$data['iconLibrary']    = $this->get_lucide_icons();
			$data['iconCategories'] = $this->get_icon_categories();
			$data['iconAliases']    = $this->get_icon_aliases();
		}

		return $data;
	}

	/**
	 * Icon library of the picker, grouped by category (the key order is the
	 * display order). The paths are taken **as is** from lucide-static v1.34.0
	 * (https://lucide.dev, ISC license): none is drawn or approximated by hand.
	 * To add one, copy the content of the icon's official <svg>, without the
	 * wrapper.
	 *
	 * @return array<string, array{label:string, icons:array<string, string>}>
	 */
	private function get_icon_library(): array {
		return [
			'general'  => [
				'label' => __( 'General', 'lumia-tools' ),
				'icons' => [
					'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
					'house'            => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
					'gauge'            => '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
					'compass'          => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z"/>',
					'panels-top-left'  => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
					'panel-left'       => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
					'grid-2x2'         => '<path d="M12 3v18"/><path d="M3 12h18"/><rect x="3" y="3" width="18" height="18" rx="2"/>',
					'list'             => '<path d="M3 5h.01"/><path d="M3 12h.01"/><path d="M3 19h.01"/><path d="M8 5h13"/><path d="M8 12h13"/><path d="M8 19h13"/>',
					'menu'             => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
					'star'             => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
					'heart'            => '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>',
					'bookmark'         => '<path d="M17 3a2 2 0 0 1 2 2v15a1 1 0 0 1-1.496.868l-4.512-2.578a2 2 0 0 0-1.984 0l-4.512 2.578A1 1 0 0 1 5 20V5a2 2 0 0 1 2-2z"/>',
					'flag'             => '<path d="M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 7.333 2q2 0 3.067-.8A1 1 0 0 1 20 4v10a1 1 0 0 1-.4.8A6 6 0 0 1 16 16c-3 0-5-2-8-2a6 6 0 0 0-4 1.528"/>',
					'bell'             => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
					'search'           => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
					'funnel'           => '<path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z"/>',
					'sparkles'         => '<path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/>',
					'zap'              => '<path d="M15.914 4a1.5 1.5 0 00-2.474-1.561l-9 9A1.5 1.5 0 005.5 14h4.002a.5.5 0 01.471.666L8.086 20a1.5 1.5 0 002.475 1.56l9-9A1.5 1.5 0 0018.5 10h-3.997a.5.5 0 01-.472-.667z"/>',
					'rocket'           => '<path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09"/><path d="M9 12a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.4 22.4 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 .05 5 .05"/>',
					'circle-help'      => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
					'info'             => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
					'badge-check'      => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
					'circle-alert'     => '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
					'eye'              => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
					'eye-off'          => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
				],
			],
			'content'  => [
				'label' => _x( 'Content', 'icon category', 'lumia-tools' ),
				'icons' => [
					'file'           => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/>',
					'file-text'      => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
					'files'          => '<path d="M15 2h-4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8"/><path d="M16.706 2.706A2.4 2.4 0 0 0 15 2v5a1 1 0 0 0 1 1h5a2.4 2.4 0 0 0-.706-1.706z"/><path d="M5 7a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h8a2 2 0 0 0 1.732-1"/>',
					'folder'         => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
					'folder-open'    => '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"/>',
					'book'           => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
					'book-open'      => '<path d="M12 5v16"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/>',
					'notebook-pen'   => '<path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4"/><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z"/>',
					'newspaper'      => '<path d="M15 18h-5"/><path d="M18 14h-8"/><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-4 0v-9a2 2 0 0 1 2-2h2"/><rect width="8" height="4" x="10" y="6" rx="1"/>',
					'pen-line'       => '<path d="M13 21h8"/><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
					'pencil'         => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
					'square-pen'     => '<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z"/>',
					'type'           => '<path d="M12 4v16"/><path d="M4 7V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2"/><path d="M9 20h6"/>',
					'quote'          => '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/><path d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>',
					'list-checks'    => '<path d="M13 5h8"/><path d="M13 12h8"/><path d="M13 19h8"/><path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/>',
					'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
					'calendar'       => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/>',
					'calendar-days'  => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/><path d="M8 17h.01"/><path d="M12 17h.01"/><path d="M16 17h.01"/>',
					'clock'          => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
					'tag'            => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
					'tags'           => '<path d="M13.172 2a2 2 0 0 1 1.414.586l6.71 6.71a2.4 2.4 0 0 1 0 3.408l-4.592 4.592a2.4 2.4 0 0 1-3.408 0l-6.71-6.71A2 2 0 0 1 6 9.172V3a1 1 0 0 1 1-1z"/><path d="M2 7v6.172a2 2 0 0 0 .586 1.414l6.71 6.71a2.4 2.4 0 0 0 3.191.193"/><circle cx="10.5" cy="6.5" r=".5" fill="currentColor"/>',
					'link'           => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
					'paperclip'      => '<path d="m16 6-8.414 8.586a2 2 0 0 0 2.829 2.829l8.414-8.586a4 4 0 1 0-5.657-5.657l-8.379 8.551a6 6 0 1 0 8.485 8.485l8.379-8.551"/>',
					'archive'        => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
					'trash-2'        => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
				],
			],
			'media'    => [
				'label' => __( 'Media', 'lumia-tools' ),
				'icons' => [
					'image'                  => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
					'images'                 => '<path d="m22 11-1.296-1.296a2.4 2.4 0 0 0-3.408 0L11 16"/><path d="M4 8a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2"/><circle cx="13" cy="7" r="1" fill="currentColor"/><rect x="8" y="2" width="14" height="14" rx="2"/>',
					'gallery-horizontal'     => '<path d="M2 3v18"/><rect width="12" height="18" x="6" y="3" rx="2"/><path d="M22 3v18"/>',
					'gallery-horizontal-end' => '<path d="M2 7v10"/><path d="M6 5v14"/><rect width="12" height="18" x="10" y="3" rx="2"/>',
					'gallery-vertical'       => '<path d="M3 2h18"/><rect width="18" height="12" x="3" y="6" rx="2"/><path d="M3 22h18"/>',
					'gallery-vertical-end'   => '<path d="M7 2h10"/><path d="M5 6h14"/><rect width="18" height="12" x="3" y="10" rx="2"/>',
					'camera'                 => '<path d="M13.997 4a2 2 0 0 1 1.76 1.05l.486.9A2 2 0 0 0 18.003 7H20a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h1.997a2 2 0 0 0 1.759-1.048l.489-.904A2 2 0 0 1 10.004 4z"/><circle cx="12" cy="13" r="3"/>',
					'video'                  => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
					'film'                   => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/>',
					'music'                  => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
					'mic'                    => '<path d="M12 19v3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><rect x="9" y="2" width="6" height="13" rx="3"/>',
					'play'                   => '<path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>',
					'headphones'             => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
					'upload'                 => '<path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
					'download'               => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
					'cloud-upload'           => '<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>',
				],
			],
			'commerce' => [
				'label' => __( 'Commerce', 'lumia-tools' ),
				'icons' => [
					'shopping-bag'  => '<path d="M16 10a4 4 0 0 1-8 0"/><path d="M3.103 6.034h17.794"/><path d="M3.4 5.467a2 2 0 0 0-.4 1.2V20a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.667a2 2 0 0 0-.4-1.2l-2-2.667A2 2 0 0 0 17 2H7a2 2 0 0 0-1.6.8z"/>',
					'shopping-cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
					'store'         => '<path d="M15 21v-5a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v5"/><path d="M17.774 10.31a1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.451 0 1.12 1.12 0 0 0-1.548 0 2.5 2.5 0 0 1-3.452 0 1.12 1.12 0 0 0-1.549 0 2.5 2.5 0 0 1-3.77-3.248l2.889-4.184A2 2 0 0 1 7 2h10a2 2 0 0 1 1.653.873l2.895 4.192a2.5 2.5 0 0 1-3.774 3.244"/><path d="M4 10.95V19a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8.05"/>',
					'package'       => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
					'package-2'     => '<path d="M12 3v6"/><path d="M16.76 3a2 2 0 0 1 1.8 1.1l2.23 4.479a2 2 0 0 1 .21.891V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9.472a2 2 0 0 1 .211-.894L5.45 4.1A2 2 0 0 1 7.24 3z"/><path d="M3.054 9.013h17.893"/>',
					'package-open'  => '<path d="M12 22v-9"/><path d="M15.17 2.21a1.67 1.67 0 0 1 1.63 0L21 4.57a1.93 1.93 0 0 1 0 3.36L8.82 14.79a1.655 1.655 0 0 1-1.64 0L3 12.43a1.93 1.93 0 0 1 0-3.36z"/><path d="M20 13v3.87a2.06 2.06 0 0 1-1.11 1.83l-6 3.08a1.93 1.93 0 0 1-1.78 0l-6-3.08A2.06 2.06 0 0 1 4 16.87V13"/><path d="M21 12.43a1.93 1.93 0 0 0 0-3.36L8.83 2.2a1.64 1.64 0 0 0-1.63 0L3 4.57a1.93 1.93 0 0 0 0 3.36l12.18 6.86a1.636 1.636 0 0 0 1.63 0z"/>',
					'truck'         => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
					'receipt'       => '<path d="M12 17V7"/><path d="M16 8h-6a2 2 0 0 0 0 4h4a2 2 0 0 1 0 4H8"/><path d="M4 3a1 1 0 0 1 1-1 1.3 1.3 0 0 1 .7.2l.933.6a1.3 1.3 0 0 0 1.4 0l.934-.6a1.3 1.3 0 0 1 1.4 0l.933.6a1.3 1.3 0 0 0 1.4 0l.933-.6a1.3 1.3 0 0 1 1.4 0l.934.6a1.3 1.3 0 0 0 1.4 0l.933-.6A1.3 1.3 0 0 1 19 2a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1 1.3 1.3 0 0 1-.7-.2l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.934.6a1.3 1.3 0 0 1-1.4 0l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-1.4 0l-.934-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-.7.2 1 1 0 0 1-1-1z"/>',
					'credit-card'   => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
					'banknote'      => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/>',
					'dollar-sign'   => '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
					'euro'          => '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
					'percent'       => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
					'gift'          => '<path d="M12 7v14"/><path d="M20 11v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-8"/><path d="M7.5 7a1 1 0 0 1 0-5A4.8 8 0 0 1 12 7a4.8 8 0 0 1 4.5-5 1 1 0 0 1 0 5"/><rect x="3" y="7" width="18" height="4" rx="1"/>',
					'wallet'        => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
					'ticket'        => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
					'boxes'         => '<path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z"/><path d="m7 16.5-4.74-2.85"/><path d="m7 16.5 5-3"/><path d="M7 16.5v5.17"/><path d="M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z"/><path d="m17 16.5-5-3"/><path d="m17 16.5 4.74-2.85"/><path d="M17 16.5v5.17"/><path d="M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z"/><path d="M12 8 7.26 5.15"/><path d="m12 8 4.74-2.85"/><path d="M12 13.5V8"/>',
				],
			],
			'users'    => [
				'label' => __( 'Users', 'lumia-tools' ),
				'icons' => [
					'user-round'       => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
					'users-round'      => '<path d="M18 21a8 8 0 0 0-16 0"/><circle cx="10" cy="8" r="5"/><path d="M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3"/>',
					'user-round-plus'  => '<path d="M2 21a8 8 0 0 1 13.292-6"/><circle cx="10" cy="8" r="5"/><path d="M19 16v6"/><path d="M22 19h-6"/>',
					'user-round-cog'   => '<path d="m14.305 19.53.923-.382"/><path d="m15.228 16.852-.923-.383"/><path d="m16.852 15.228-.383-.923"/><path d="m16.852 20.772-.383.924"/><path d="m19.148 15.228.383-.923"/><path d="m19.53 21.696-.382-.924"/><path d="M2 21a8 8 0 0 1 10.434-7.62"/><path d="m20.772 16.852.924-.383"/><path d="m20.772 19.148.924.383"/><circle cx="10" cy="8" r="5"/><circle cx="18" cy="18" r="3"/>',
					'contact-round'    => '<path d="M16 2v2"/><path d="M17.915 21a6 6 0 10-12 0"/><path d="M8 2v2"/><circle cx="12" cy="11" r="4"/><rect x="3" y="3" width="18" height="18" rx="2"/>',
					'id-card'          => '<path d="M16 10h2"/><path d="M16 14h2"/><path d="M6.17 15a3 3 0 0 1 5.66 0"/><circle cx="9" cy="11" r="2"/><rect x="2" y="5" width="20" height="14" rx="2"/>',
					'mail'             => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
					'message-circle'   => '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/>',
					'message-square'   => '<path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/>',
					'phone'            => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
					'at-sign'          => '<circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-4 8"/>',
					'handshake'        => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
					'user-round-check' => '<path d="M2 21a8 8 0 0 1 13.292-6"/><circle cx="10" cy="8" r="5"/><path d="m16 19 2 2 4-4"/>',
				],
			],
			'data'     => [
				'label' => __( 'Data', 'lumia-tools' ),
				'icons' => [
					'chart-column' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
					'chart-line'   => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m19 9-5 5-4-4-3 3"/>',
					'chart-pie'    => '<path d="M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951c-.55-.055-.998.398-.998.95v8a1 1 0 0 0 1 1z"/><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>',
					'trending-up'  => '<path d="M16 7h6v6"/><path d="m22 7-8.5 8.5-5-5L2 17"/>',
					'activity'     => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
					'database'     => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
					'server'       => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
					'hard-drive'   => '<path d="M10 16h.01"/><path d="M2.212 11.577a2 2 0 0 0-.212.896V18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5.527a2 2 0 0 0-.212-.896L18.55 5.11A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><path d="M21.946 12.013H2.054"/><path d="M6 16h.01"/>',
					'table'        => '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
				],
			],
			'design'   => [
				'label' => __( 'Appearance', 'lumia-tools' ),
				'icons' => [
					'palette'            => '<path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z"/><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>',
					'swatch-book'        => '<path d="M11 17a4 4 0 0 1-8 0V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2Z"/><path d="M16.7 13H19a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2H7"/><path d="M 7 17h.01"/><path d="m11 8 2.3-2.3a2.4 2.4 0 0 1 3.404.004L18.6 7.6a2.4 2.4 0 0 1 .026 3.434L9.9 19.8"/>',
					'paintbrush'         => '<path d="m14.622 17.897-10.68-2.913"/><path d="M18.376 2.622a1 1 0 1 1 3.002 3.002L17.36 9.643a.5.5 0 0 0 0 .707l.944.944a2.41 2.41 0 0 1 0 3.408l-.944.944a.5.5 0 0 1-.707 0L8.354 7.348a.5.5 0 0 1 0-.707l.944-.944a2.41 2.41 0 0 1 3.408 0l.944.944a.5.5 0 0 0 .707 0z"/><path d="M9 8c-1.804 2.71-3.97 3.46-6.583 3.948a.507.507 0 0 0-.302.819l7.32 8.883a1 1 0 0 0 1.185.204C12.735 20.405 16 16.792 16 15"/>',
					'brush'              => '<path d="m11 10 3 3"/><path d="M6.5 21A3.5 3.5 0 1 0 3 17.5a2.62 2.62 0 0 1-.708 1.792A1 1 0 0 0 3 21z"/><path d="M9.969 17.031 21.378 5.624a1 1 0 0 0-3.002-3.002L6.967 14.031"/>',
					'layers'             => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
					'blocks'             => '<path d="M10 22V7a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a1 1 0 0 0-1-1H2"/><rect x="14" y="2" width="8" height="8" rx="1"/>',
					'toy-brick'          => '<rect width="18" height="12" x="3" y="8" rx="1"/><path d="M10 8V5c0-.6-.4-1-1-1H6a1 1 0 0 0-1 1v3"/><path d="M19 8V5c0-.6-.4-1-1-1h-3a1 1 0 0 0-1 1v3"/>',
					'puzzle'             => '<path d="M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z"/>',
					'component'          => '<path d="M15.536 11.293a1 1 0 0 0 0 1.414l2.376 2.377a1 1 0 0 0 1.414 0l2.377-2.377a1 1 0 0 0 0-1.414l-2.377-2.377a1 1 0 0 0-1.414 0z"/><path d="M2.297 11.293a1 1 0 0 0 0 1.414l2.377 2.377a1 1 0 0 0 1.414 0l2.377-2.377a1 1 0 0 0 0-1.414L6.088 8.916a1 1 0 0 0-1.414 0z"/><path d="M8.916 17.912a1 1 0 0 0 0 1.415l2.377 2.376a1 1 0 0 0 1.414 0l2.377-2.376a1 1 0 0 0 0-1.415l-2.377-2.376a1 1 0 0 0-1.414 0z"/><path d="M8.916 4.674a1 1 0 0 0 0 1.414l2.377 2.376a1 1 0 0 0 1.414 0l2.377-2.376a1 1 0 0 0 0-1.414l-2.377-2.377a1 1 0 0 0-1.414 0z"/>',
					'wand-sparkles'      => '<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/>',
					'sliders-horizontal' => '<path d="M10 5H3"/><path d="M12 19H3"/><path d="M14 3v4"/><path d="M16 17v4"/><path d="M21 12h-9"/><path d="M21 19h-5"/><path d="M21 5h-7"/><path d="M8 10v4"/><path d="M8 12H3"/>',
					'sliders-vertical'   => '<path d="M10 8h4"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M17 16h4"/><path d="M19 12V3"/><path d="M19 21v-5"/><path d="M3 14h4"/><path d="M5 10V3"/><path d="M5 21v-7"/>',
					'ruler'              => '<path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/>',
					'frame'              => '<line x1="22" x2="2" y1="6" y2="6"/><line x1="22" x2="2" y1="18" y2="18"/><line x1="6" x2="6" y1="2" y2="22"/><line x1="18" x2="18" y1="2" y2="22"/>',
					'layout-template'    => '<rect width="18" height="7" x="3" y="3" rx="1"/><rect width="9" height="7" x="3" y="14" rx="1"/><rect width="5" height="7" x="16" y="14" rx="1"/>',
				],
			],
			'system'   => [
				'label' => __( 'System', 'lumia-tools' ),
				'icons' => [
					'settings'      => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
					'settings-2'    => '<path d="M14 17H5"/><path d="M19 7h-9"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
					'wrench'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/>',
					'cog'           => '<path d="M11 10.27 7 3.34"/><path d="m11 13.73-4 6.93"/><path d="M12 22v-2"/><path d="M12 2v2"/><path d="M14 12h8"/><path d="m17 20.66-1-1.73"/><path d="m17 3.34-1 1.73"/><path d="M2 12h2"/><path d="m20.66 17-1.73-1"/><path d="m20.66 7-1.73 1"/><path d="m3.34 17 1.73-1"/><path d="m3.34 7 1.73 1"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="12" r="8"/>',
					'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
					'shield-check'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
					'lock'          => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
					'key'           => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
					'plug'          => '<path d="M12 22v-5"/><path d="M15 8V2"/><path d="M17 8a1 1 0 0 1 1 1v4a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1z"/><path d="M9 8V2"/>',
					'power'         => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
					'terminal'      => '<path d="M12 19h8"/><path d="m4 17 6-6-6-6"/>',
					'code'          => '<path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/>',
					'bug'           => '<path d="M12 20v-9"/><path d="M14 7a4 4 0 0 1 4 4v3a6 6 0 0 1-12 0v-3a4 4 0 0 1 4-4z"/><path d="M14.12 3.88 16 2"/><path d="M21 21a4 4 0 0 0-3.81-4"/><path d="M21 5a4 4 0 0 1-3.55 3.97"/><path d="M22 13h-4"/><path d="M3 21a4 4 0 0 1 3.81-4"/><path d="M3 5a4 4 0 0 0 3.55 3.97"/><path d="M6 13H2"/><path d="m8 2 1.88 1.88"/><path d="M9 7.13V6a3 3 0 1 1 6 0v1.13"/>',
					'refresh-cw'    => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
					'hammer'        => '<path d="m15 12-9.373 9.373a1 1 0 0 1-3.001-3L12 9"/><path d="m18 15 4-4"/><path d="m21.5 11.5-1.914-1.914A2 2 0 0 1 19 8.172v-.344a2 2 0 0 0-.586-1.414l-1.657-1.657A6 6 0 0 0 12.516 3H9l1.243 1.243A6 6 0 0 1 12 8.485V10l2 2h1.172a2 2 0 0 1 1.414.586L18.5 14.5"/>',
					'life-buoy'     => '<circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/><circle cx="12" cy="12" r="4"/>',
					'log-out'       => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
					'globe'         => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
					'map'           => '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>',
					'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
					'map-pinned'    => '<path d="M18 8c0 3.613-3.869 7.429-5.393 8.795a1 1 0 0 1-1.214 0C9.87 15.429 6 11.613 6 8a6 6 0 0 1 12 0"/><circle cx="12" cy="8" r="2"/><path d="M8.714 14h-3.71a1 1 0 0 0-.948.683l-2.004 6A1 1 0 0 0 3 22h18a1 1 0 0 0 .948-1.316l-2-6a1 1 0 0 0-.949-.684h-3.712"/>',
					'pin'           => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
					'megaphone'     => '<path d="M11 6a13 13 0 0 0 8.4-2.8A1 1 0 0 1 21 4v12a1 1 0 0 1-1.6.8A13 13 0 0 0 11 14H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/><path d="M6 14a12 12 0 0 0 2.4 7.2 2 2 0 0 0 3.2-2.4A8 8 0 0 1 10 14"/><path d="M8 6v8"/>',
					'rss'           => '<path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>',
					'share-2'       => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" x2="15.42" y1="13.51" y2="17.49"/><line x1="15.41" x2="8.59" y1="6.51" y2="10.49"/>',
					'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
					'monitor'       => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
					'smartphone'    => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
					'languages'     => '<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>',
				],
			],
		];
	}

	/**
	 * Search aliases of the picker: slug -> additional keywords.
	 *
	 * The Lucide slugs are English and rarely guessable from another language's
	 * interface — "funnel" for a filter, "banknote" for a bill, "boxes" for a
	 * stock. Without this table, searching "filter" or "invoice" in a translated
	 * interface returns nothing although the icon exists. Each keyword list is
	 * translatable, so that the search follows the interface language. A slug
	 * missing from here stays searchable by its name; accents are ignored in the
	 * JS comparison, no need to double the entries.
	 *
	 * @return array<string, string>
	 */
	private function get_icon_aliases(): array {
		return [
			// General.
			'layout-dashboard'       => __( 'dashboard home overview widgets', 'lumia-tools' ),
			'house'                  => __( 'house home front page site', 'lumia-tools' ),
			'gauge'                  => __( 'gauge meter performance speed', 'lumia-tools' ),
			'compass'                => __( 'compass exploration navigation discover', 'lumia-tools' ),
			'panels-top-left'        => __( 'panels page layout columns', 'lumia-tools' ),
			'panel-left'             => __( 'side panel bar sidebar column', 'lumia-tools' ),
			'grid-2x2'               => __( 'grid cells squares thumbnails', 'lumia-tools' ),
			'list'                   => __( 'list items rows enumeration', 'lumia-tools' ),
			'menu'                   => __( 'menu navigation burger hamburger lines', 'lumia-tools' ),
			'star'                   => __( 'star favorite rating review featured', 'lumia-tools' ),
			'heart'                  => __( 'heart favorite loved wishlist like', 'lumia-tools' ),
			'bookmark'               => __( 'bookmark saved favorite marker', 'lumia-tools' ),
			'flag'                   => __( 'flag report language country marker', 'lumia-tools' ),
			'bell'                   => __( 'bell notification alert reminder', 'lumia-tools' ),
			'search'                 => __( 'search magnifier find look up', 'lumia-tools' ),
			'funnel'                 => __( 'filter funnel sort refine', 'lumia-tools' ),
			'sparkles'               => __( 'sparkles magic ai new shiny', 'lumia-tools' ),
			'zap'                    => __( 'lightning fast performance bolt cache', 'lumia-tools' ),
			'rocket'                 => __( 'rocket launch startup fast deployment', 'lumia-tools' ),
			'circle-help'            => __( 'help question support faq assistance', 'lumia-tools' ),
			'info'                   => __( 'information detail about details', 'lumia-tools' ),
			'badge-check'            => __( 'badge verified validated certified approved', 'lumia-tools' ),
			'circle-alert'           => __( 'alert attention warning error', 'lumia-tools' ),
			'eye'                    => __( 'eye see visible preview', 'lumia-tools' ),
			'eye-off'                => __( 'crossed eye hidden concealed invisible', 'lumia-tools' ),

			// Content.
			'file'                   => __( 'file document blank page', 'lumia-tools' ),
			'file-text'              => __( 'text file document article page', 'lumia-tools' ),
			'files'                  => __( 'files documents multiple copies', 'lumia-tools' ),
			'folder'                 => __( 'folder directory filing', 'lumia-tools' ),
			'folder-open'            => __( 'open folder directory browse', 'lumia-tools' ),
			'book'                   => __( 'book documentation manual guide', 'lumia-tools' ),
			'book-open'              => __( 'open book reading documentation guide', 'lumia-tools' ),
			'notebook-pen'           => __( 'notebook notes writing journal', 'lumia-tools' ),
			'newspaper'              => __( 'newspaper news articles press blog', 'lumia-tools' ),
			'pen-line'               => __( 'pen write edit compose modify', 'lumia-tools' ),
			'pencil'                 => __( 'pencil edit modify write', 'lumia-tools' ),
			'square-pen'             => __( 'edit modify pencil compose', 'lumia-tools' ),
			'type'                   => __( 'typography font text character', 'lumia-tools' ),
			'quote'                  => __( 'quote quotation marks testimonial', 'lumia-tools' ),
			'list-checks'            => __( 'task list checkboxes todo checklist', 'lumia-tools' ),
			'clipboard-list'         => __( 'clipboard task list form', 'lumia-tools' ),
			'calendar'               => __( 'calendar date schedule event planning', 'lumia-tools' ),
			'calendar-days'          => __( 'calendar days schedule planning dates', 'lumia-tools' ),
			'clock'                  => __( 'clock time hour history scheduling', 'lumia-tools' ),
			'tag'                    => __( 'tag keyword label price', 'lumia-tools' ),
			'tags'                   => __( 'tags keywords labels taxonomy', 'lumia-tools' ),
			'link'                   => __( 'link url hyperlink chain permalink', 'lumia-tools' ),
			'paperclip'              => __( 'paperclip attachment attached file', 'lumia-tools' ),
			'archive'                => __( 'archive box storage backup', 'lumia-tools' ),
			'trash-2'                => __( 'trash delete bin erase', 'lumia-tools' ),

			// Media.
			'image'                  => __( 'image photo illustration visual media', 'lumia-tools' ),
			'images'                 => __( 'images photos gallery media library visuals', 'lumia-tools' ),
			'gallery-horizontal'     => __( 'gallery carousel horizontal slideshow', 'lumia-tools' ),
			'gallery-horizontal-end' => __( 'gallery carousel horizontal end slideshow', 'lumia-tools' ),
			'gallery-vertical'       => __( 'gallery vertical column slideshow', 'lumia-tools' ),
			'gallery-vertical-end'   => __( 'gallery vertical end column slideshow', 'lumia-tools' ),
			'camera'                 => __( 'camera photo shot capture', 'lumia-tools' ),
			'video'                  => __( 'video movie camera playback footage', 'lumia-tools' ),
			'film'                   => __( 'film reel video cinema editing', 'lumia-tools' ),
			'music'                  => __( 'music audio sound note track', 'lumia-tools' ),
			'mic'                    => __( 'microphone podcast recording audio voice', 'lumia-tools' ),
			'play'                   => __( 'play start launch run', 'lumia-tools' ),
			'headphones'             => __( 'headphones listening audio sound podcast', 'lumia-tools' ),
			'upload'                 => __( 'upload send import load', 'lumia-tools' ),
			'download'               => __( 'download export retrieve save', 'lumia-tools' ),
			'cloud-upload'           => __( 'cloud upload backup remote sync', 'lumia-tools' ),

			// Commerce.
			'shopping-bag'           => __( 'bag purchase shop order shopping', 'lumia-tools' ),
			'shopping-cart'          => __( 'cart basket purchase order shop', 'lumia-tools' ),
			'store'                  => __( 'store shop commerce storefront', 'lumia-tools' ),
			'package'                => __( 'parcel package product delivery module plugin', 'lumia-tools' ),
			'package-2'              => __( 'parcel package product stock delivery', 'lumia-tools' ),
			'package-open'           => __( 'open parcel unpacking product delivery', 'lumia-tools' ),
			'truck'                  => __( 'truck delivery shipping transport', 'lumia-tools' ),
			'receipt'                => __( 'receipt invoice ticket note order', 'lumia-tools' ),
			'credit-card'            => __( 'bank card payment credit card settlement', 'lumia-tools' ),
			'banknote'               => __( 'banknote money currency payment cash', 'lumia-tools' ),
			'dollar-sign'            => __( 'dollar currency price money rate', 'lumia-tools' ),
			'euro'                   => __( 'euro currency price money rate', 'lumia-tools' ),
			'percent'                => __( 'percentage discount promotion sale rate', 'lumia-tools' ),
			'gift'                   => __( 'gift offer voucher promotion reward', 'lumia-tools' ),
			'wallet'                 => __( 'wallet balance payment purse', 'lumia-tools' ),
			'ticket'                 => __( 'ticket coupon promo code discount', 'lumia-tools' ),
			'boxes'                  => __( 'stock inventory cartons warehouse products', 'lumia-tools' ),

			// Users.
			'user-round'             => __( 'user account profile person member', 'lumia-tools' ),
			'users-round'            => __( 'users accounts members team group roles', 'lumia-tools' ),
			'user-round-plus'        => __( 'add a user new account registration member', 'lumia-tools' ),
			'user-round-cog'         => __( 'account settings profile permissions role user', 'lumia-tools' ),
			'contact-round'          => __( 'contact address book directory card person', 'lumia-tools' ),
			'id-card'                => __( 'identity card badge profile record', 'lumia-tools' ),
			'mail'                   => __( 'email mail message envelope contact', 'lumia-tools' ),
			'message-circle'         => __( 'message discussion comment chat bubble', 'lumia-tools' ),
			'message-square'         => __( 'message comment discussion chat review', 'lumia-tools' ),
			'phone'                  => __( 'phone call contact number', 'lumia-tools' ),
			'at-sign'                => __( 'at sign email mention handle mail', 'lumia-tools' ),
			'handshake'              => __( 'handshake partnership agreement affiliation', 'lumia-tools' ),
			'user-round-check'       => __( 'user validated account verified approved member', 'lumia-tools' ),

			// Data.
			'chart-column'           => __( 'chart bars statistics report histogram', 'lumia-tools' ),
			'chart-line'             => __( 'chart line statistics evolution trend', 'lumia-tools' ),
			'chart-pie'              => __( 'chart pie sectors breakdown statistics', 'lumia-tools' ),
			'trending-up'            => __( 'trend growth increase progress statistics', 'lumia-tools' ),
			'activity'               => __( 'activity pulse log tracking monitoring', 'lumia-tools' ),
			'database'               => __( 'database sql tables storage', 'lumia-tools' ),
			'server'                 => __( 'server hosting infrastructure machine', 'lumia-tools' ),
			'hard-drive'             => __( 'hard drive storage space backup', 'lumia-tools' ),
			'table'                  => __( 'table spreadsheet grid columns data', 'lumia-tools' ),

			// Appearance.
			'palette'                => __( 'palette colors theme design appearance', 'lumia-tools' ),
			'swatch-book'            => __( 'swatch colors samples brand theme', 'lumia-tools' ),
			'paintbrush'             => __( 'paintbrush painting style customization theme', 'lumia-tools' ),
			'brush'                  => __( 'brush paintbrush style color customization', 'lumia-tools' ),
			'layers'                 => __( 'layers stacking overlay', 'lumia-tools' ),
			'blocks'                 => __( 'blocks editor gutenberg components bricks', 'lumia-tools' ),
			'toy-brick'              => __( 'brick block module plugin component', 'lumia-tools' ),
			'puzzle'                 => __( 'puzzle plugin module add-on piece', 'lumia-tools' ),
			'component'              => __( 'component element block module', 'lumia-tools' ),
			'wand-sparkles'          => __( 'magic wand automatic effects ai beautify', 'lumia-tools' ),
			'sliders-horizontal'     => __( 'settings sliders options filters parameters', 'lumia-tools' ),
			'sliders-vertical'       => __( 'settings sliders equalizer options parameters', 'lumia-tools' ),
			'ruler'                  => __( 'ruler measure dimensions size spacing', 'lumia-tools' ),
			'frame'                  => __( 'frame framing border container', 'lumia-tools' ),
			'layout-template'        => __( 'template layout pattern page structure', 'lumia-tools' ),

			// System.
			'settings'               => __( 'settings parameters configuration options gear', 'lumia-tools' ),
			'settings-2'             => __( 'settings parameters options configuration sliders', 'lumia-tools' ),
			'wrench'                 => __( 'wrench tools maintenance repair troubleshooting', 'lumia-tools' ),
			'cog'                    => __( 'cog gear settings configuration parameters', 'lumia-tools' ),
			'shield'                 => __( 'shield security protection firewall', 'lumia-tools' ),
			'shield-check'           => __( 'security verified protection validated shield', 'lumia-tools' ),
			'lock'                   => __( 'padlock lock security private protected password', 'lumia-tools' ),
			'key'                    => __( 'key password access license identifier token', 'lumia-tools' ),
			'plug'                   => __( 'plug connection plugin integration', 'lumia-tools' ),
			'power'                  => __( 'power on off enable disable', 'lumia-tools' ),
			'terminal'               => __( 'terminal console command shell cli', 'lumia-tools' ),
			'code'                   => __( 'code development html tag snippet', 'lumia-tools' ),
			'bug'                    => __( 'bug error debugging anomaly problem', 'lumia-tools' ),
			'refresh-cw'             => __( 'refresh reload synchronize update renew', 'lumia-tools' ),
			'hammer'                 => __( 'hammer tools construction maintenance', 'lumia-tools' ),
			'life-buoy'              => __( 'lifebuoy support help assistance rescue', 'lumia-tools' ),
			'log-out'                => __( 'logout exit leave session', 'lumia-tools' ),
			'globe'                  => __( 'globe world website international language', 'lumia-tools' ),
			'map'                    => __( 'map plan geography route', 'lumia-tools' ),
			'map-pin'                => __( 'pin location address position place', 'lumia-tools' ),
			'map-pinned'             => __( 'map location address position places', 'lumia-tools' ),
			'pin'                    => __( 'pin attach fix mark', 'lumia-tools' ),
			'megaphone'              => __( 'megaphone announcement marketing communication promotion', 'lumia-tools' ),
			'rss'                    => __( 'feed rss syndication subscription news', 'lumia-tools' ),
			'share-2'                => __( 'share sharing social networks distribution', 'lumia-tools' ),
			'external-link'          => __( 'external link new tab outgoing open', 'lumia-tools' ),
			'monitor'                => __( 'screen desktop computer display', 'lumia-tools' ),
			'smartphone'             => __( 'mobile phone responsive handheld screen', 'lumia-tools' ),
			'languages'              => __( 'languages translation international multilingual localization', 'lumia-tools' ),
		];
	}

	/**
	 * Flattened library: slug → full SVG, consumed by the JS picker.
	 *
	 * @return array<string, string>
	 */
	private function get_lucide_icons(): array {
		$w = 'xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

		$result = [];
		foreach ( $this->get_icon_library() as $group ) {
			foreach ( $group['icons'] as $name => $paths ) {
				$result[ $name ] = '<svg ' . $w . '>' . $paths . '</svg>';
			}
		}
		return $result;
	}

	/**
	 * Picker categories: identifier → label + ordered list of slugs.
	 *
	 * @return array<int, array{id:string, label:string, icons:array<int, string>}>
	 */
	private function get_icon_categories(): array {
		$out = [];
		foreach ( $this->get_icon_library() as $id => $group ) {
			$out[] = [
				'id'    => $id,
				'label' => $group['label'],
				'icons' => array_keys( $group['icons'] ),
			];
		}
		return $out;
	}

	public function maybe_enqueue_media( string $hook ): void {
		if ( false === strpos( $hook, 'lumia-tools' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'module_menu_creator' === $tab ) {
			wp_enqueue_media();
			wp_enqueue_style( 'dashicons' );
		}
	}
}
