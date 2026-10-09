<?php
namespace Lumia\Tools\Modules\MenuCreator;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Core\AbstractModule;
use Lumia\Tools\Modules\ImageOptimizer\SvgHandler;
use Lumia\Tools\Modules\WhiteLabel\MenuProfileManager;

/**
 * Module Créateur de menu — gestion des profils de navigation et application aux utilisateurs.
 */
class Module extends AbstractModule {

	/**
	 * Nombre maximal d'entrées de menu persistées par niveau (garde-fou payload).
	 */
	private const MAX_ITEMS = 300;

	/**
	 * Copie du menu WP admin AVANT toute personnalisation par ce module
	 * (séparateurs injectés, liens custom ajoutés, items masqués retirés).
	 * L'éditeur doit présenter à l'utilisateur le menu WP d'origine, pas la
	 * version déjà transformée — sinon nos propres séparateurs / liens custom
	 * y réapparaissent en double. Capturé au début de apply_menu_visibility.
	 *
	 * @var array<int, mixed>|null
	 */
	private static $pristine_menu = null;

	/**
	 * @var array<string, mixed>|null
	 */
	private static $pristine_submenu = null;

	public function init(): void {
		// Moteur d'application des menus personnalisés
		$has_active = ! empty(
			array_filter(
				MenuProfileManager::get_all(),
				fn( $p ) => ( $p['status'] ?? '' ) === 'active'
			)
		);

		if ( $has_active ) {
			// N'active l'ordre custom que si l'utilisateur courant a réellement
			// un profil actif (sinon on force tout le monde dans le chemin
			// custom_menu_order pour rien).
			add_filter( 'custom_menu_order', [ $this, 'maybe_enable_custom_order' ] );
			add_filter( 'menu_order', [ $this, 'apply_menu_order' ], 9999 );
			add_action( 'admin_menu', [ $this, 'apply_menu_visibility' ], 9999 );
			add_action( 'admin_head', [ $this, 'inject_menu_icon_overrides' ] );
			add_action( 'admin_head', [ $this, 'inject_custom_link_targets' ] );
			// Priorité 1 : refuser la page avant que quoi que ce soit d'autre
			// (chargement d'écran, traitement de formulaire) ne s'exécute.
			add_action( 'admin_init', [ $this, 'enforce_blocked_pages' ], 1 );
		}

		// admin_footer : le script des toasts y est déjà chargé.
		add_action( 'admin_footer', [ $this, 'render_denied_toast' ] );

		// Uniformise l'opacité des icônes de menu (natives ET personnalisées) :
		// WP atténue par défaut #adminmenu .wp-menu-image img à 60% tant que
		// l'item n'est pas survolé/actif. Indépendant d'un profil actif, pour
		// que même les icônes natives (ex. l'icône du plugin lui-même) en profitent.
		add_action( 'admin_head', [ $this, 'inject_global_icon_opacity_fix' ] );

		// AJAX endpoints
		add_action( 'wp_ajax_lumia_wl_save_profile', [ $this, 'ajax_save_profile' ] );
		add_action( 'wp_ajax_lumia_wl_delete_profile', [ $this, 'ajax_delete_profile' ] );
		add_action( 'wp_ajax_lumia_wl_duplicate_profile', [ $this, 'ajax_duplicate_profile' ] );
		add_action( 'wp_ajax_lumia_wl_search_users', [ $this, 'ajax_search_users' ] );
		add_action( 'wp_ajax_lumia_wl_import_profile', [ $this, 'ajax_import_profile' ] );
		add_action( 'wp_ajax_lumia_wl_sanitize_svg', [ $this, 'ajax_sanitize_svg' ] );

		// Médiathèque WP pour le picker d'icônes
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_media' ] );
	}

	/* ================================================================
	 * MOTEUR DE MENU
	 * ================================================================ */

	/**
	 * N'active le tri de menu personnalisé que pour un utilisateur réellement
	 * ciblé par un profil actif ; laisse la valeur des autres filtres intacte
	 * sinon.
	 *
	 * @param bool $enabled Valeur courante du filtre custom_menu_order.
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

			// Un lien personnalisé est enregistré dans $menu par add_menu_page()
			// sous le slug que WordPress dérive de l'URL, PAS l'URL brute :
			// add_menu_page applique plugin_basename() sur le menu_slug reçu.
			// Il faut reproduire exactement la même transformation ici, sinon
			// le slug ne correspond à aucune entrée de $menu_order et le lien
			// retombe dans "remaining" (donc tout en bas du menu).
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

		// Instantané du menu WP pristine, avant nos modifications : consommé
		// par get_admin_js_data() pour alimenter l'éditeur avec le vrai menu
		// WP (et non la version déjà personnalisée).
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
					// Les clés de $menu doivent rester des entiers (WordPress les
					// traite comme telles) : on cherche le prochain slot entier
					// libre plutôt que d'incrémenter en float, qui serait
					// silencieusement tronqué par PHP (et provoque des collisions).
					while ( isset( $menu[ $next_position ] ) ) {
						++$next_position;
					}
					$menu[ $next_position ] = [ '', 'read', $slug, '', 'wp-menu-separator' ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réordonner le menu d'admin est l'objet du module.
					++$next_position;
				}
				continue;
			}

			if ( ! ( $item['visible'] ?? true ) && ! empty( $slug ) ) {
				remove_menu_page( $slug );
				continue;
			}

			if ( 'custom_link' === $type && ! empty( $item['url'] ) ) {
				// Restriction par rôle : on n'ajoute simplement pas l'entrée.
				// Passer par la capacité d'add_menu_page ne marcherait pas —
				// WordPress raisonne en capacités, pas en rôles, et il n'en
				// existe aucune qui corresponde exactement à « ces rôles-là ».
				if ( ! $this->current_user_has_role( (array) ( $item['roles'] ?? [] ) ) ) {
					continue;
				}
				$label    = sanitize_text_field( $item['label'] ?? __( 'Lien', 'lumia-tools' ) );
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
						$menu[ $key ][0] = esc_html( $item['label'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réordonner le menu d'admin est l'objet du module.
					}
					if ( isset( $item['icon'] ) && strpos( $item['icon'], 'dashicons-' ) === 0 ) {
						$menu[ $key ][6] = esc_attr( $item['icon'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réordonner le menu d'admin est l'objet du module.
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
	 * L'utilisateur courant fait-il partie des rôles autorisés ?
	 *
	 * Liste vide = aucune restriction (tous ceux qui voient ce menu voient
	 * l'entrée). Un super-admin sans rôle sur le site courant reste couvert
	 * par le cas « aucune restriction » uniquement.
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
	 * Applique les personnalisations enfants (ordre, visibilité, label) au
	 * $submenu WP réel : le filtre menu_order ne gère QUE le premier niveau,
	 * les sous-menus doivent être réécrits directement dans le global $submenu.
	 *
	 * @param string               $parent_slug Slug du parent dans $submenu.
	 * @param array<int, mixed>    $children    Enfants du profil, dans l'ordre voulu.
	 */
	private function apply_submenu( string $parent_slug, array $children ): void {
		global $submenu;
		if ( empty( $submenu[ $parent_slug ] ) || ! is_array( $submenu[ $parent_slug ] ) ) {
			return;
		}

		// Indexe les entrées WP existantes par leur slug ([2]).
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

		// Entrées WP non listées dans le profil (ajoutées après sa création) :
		// conservées à la suite pour ne rien faire disparaître par surprise.
		foreach ( $existing as $entry ) {
			$reordered[] = $entry;
		}

		$submenu[ $parent_slug ] = $reordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réordonner le menu d'admin est l'objet du module.
	}

	/* ================================================================
	 * BLOCAGE D'ACCÈS
	 * ================================================================ */

	/**
	 * Refuse l'accès direct aux pages dont l'item porte `block_access`.
	 *
	 * Masquer une entrée de menu ne fait que la retirer de la barre latérale :
	 * l'URL reste tapable et la page s'ouvre normalement. Cette option, à
	 * cocher item par item (et uniquement sur un item déjà masqué), ajoute le
	 * refus côté serveur.
	 *
	 * Ce n'est PAS un système de permissions : il s'applique au profil de menu
	 * de l'utilisateur, pas à ses capacités. Un utilisateur qui a la capacité
	 * requise garde l'accès via l'API REST, WP-CLI ou admin-ajax.
	 */
	public function enforce_blocked_pages(): void {
		global $pagenow;

		// Jamais sur les points d'entrée programmatiques : ils n'affichent pas
		// d'écran d'admin et un refus y casserait des requêtes légitimes
		// (téléversements, autosave, actions de formulaire d'autres modules).
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

			// index.php est la cible de la redirection : le bloquer y créerait
			// une boucle, on rend donc un refus direct.
			if ( 'index.php' === $pagenow ) {
				wp_die(
					esc_html__( 'Vous n’avez pas accès à cette page.', 'lumia-tools' ),
					esc_html__( 'Accès refusé', 'lumia-tools' ),
					[ 'response' => 403 ]
				);
			}

			wp_safe_redirect( admin_url( 'index.php?lumia_denied=1' ) );
			exit;
		}
	}

	/**
	 * Slugs (premier niveau + sous-items) marqués masqués ET bloqués.
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
				// Un lien personnalisé pointe hors de notre contrôle (URL
				// arbitraire, souvent externe) : rien à bloquer côté admin.
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
	 * La requête admin courante correspond-elle à ce slug de menu ?
	 *
	 * Un slug de menu WP prend trois formes : un fichier (`upload.php`), un
	 * fichier avec paramètres (`edit.php?post_type=page`) ou un slug de page
	 * d'extension (`woocommerce`, `wc-admin&path=/analytics/overview`, servi
	 * par admin.php). On reconstruit la requête attendue puis on la compare à
	 * la requête réelle.
	 *
	 * Les paramètres du slug doivent tous être présents à l'identique ; à
	 * l'inverse, un slug qui ne mentionne ni post_type, ni taxonomy, ni page
	 * ne doit pas matcher une requête qui en porte un — sans quoi bloquer
	 * `edit.php` (Articles) bloquerait aussi `edit.php?post_type=page`.
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
	 * Décompose un slug de menu en fichier + paramètres attendus.
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

		// Pas de fichier PHP → slug de page d'extension, servi par admin.php.
		// Il peut embarquer ses propres paramètres après un & (WooCommerce :
		// « wc-admin&path=/analytics/overview »).
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

		// parse_str() peut produire des tableaux (clé[]=…) : on ne garde que les scalaires.
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
	 * Message affiché après une redirection de blocage.
	 *
	 * Un toast plutôt qu'une admin_notice : celle-ci serait capturée par le
	 * centre de notifications du plugin et n'apparaîtrait que sous la cloche,
	 * alors que l'utilisateur vient d'être redirigé et doit comprendre
	 * immédiatement pourquoi il n'est pas sur la page demandée.
	 *
	 * Le paramètre est retiré de l'URL dans la foulée, pour qu'un simple
	 * rechargement ne rejoue pas le message.
	 */
	public function render_denied_toast(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['lumia_denied'] ) ) {
			return;
		}
		$message = __( 'Vous n’avez pas accès à cette page.', 'lumia-tools' );
		?>
		<script>
		( function () {
			var msg = <?php echo wp_json_encode( $message ); ?>;
			var tries = 0;
			( function show() {
				if ( typeof window.lumiaShowToast === 'function' ) {
					window.lumiaShowToast( msg, 'warning' );
				} else if ( tries++ < 20 ) {
					// Le script des toasts est chargé en pied de page : on laisse
					// quelques tours de boucle avant d'abandonner silencieusement.
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
	 * Ajoute target="_blank" / rel="noopener" aux liens personnalisés qui le
	 * demandent : add_menu_page() ne sait pas poser d'attribut target, on le
	 * fait donc côté DOM après rendu du menu.
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
	 * Injecte les icônes SVG/média personnalisées dans le menu admin réel.
	 *
	 * Deux modes de rendu selon la source (cf. resolve_icon_render) :
	 * - SVG monochrome (bibliothèque interne, fichier Lucide uploadé…) : rendu
	 *   en masque CSS coloré par background-color. Un <img> ne peut pas hériter
	 *   d'une couleur de texte, donc son `currentColor` retombe au noir —
	 *   invisible sur la barre latérale sombre. Le masque ne retient que la
	 *   forme et applique la couleur de repos des dashicons (blanc au survol,
	 *   comme les icônes natives).
	 * - Autre média (PNG/JPG, SVG déjà colorisé) : rendu en <img>, les couleurs
	 *   d'origine sont préservées.
	 *
	 * Le ciblage se fait sur l'attribut id du <li> (index 5 de $menu), pas sur
	 * un href : plusieurs extensions (WooCommerce « Marketing », « Statistiques »)
	 * enregistrent leur page sous un slug puis réécrivent l'URL réelle du menu,
	 * si bien que le href ne contient plus le slug et qu'aucune icône n'était
	 * appliquée. L'id est unique et reste dérivé du slug d'enregistrement.
	 */
	public function inject_menu_icon_overrides(): void {
		$profile = MenuProfileManager::get_active_for_user( get_current_user_id() );
		if ( ! $profile || empty( $profile['items'] ) ) {
			return;
		}

		// On tient compte des sous-items en plus des items de premier niveau :
		// pour un rôle à capacités réduites (ex. auteur), WordPress promeut
		// certains sous-menus en items de premier niveau (profile.php « Profil »
		// remplace users.php « Comptes »). Le JS ne cible que les .menu-top,
		// donc côté admin — où le sous-item reste un sous-menu — ceci n'a aucun
		// effet visible.
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

			// Un lien personnalisé est enregistré dans $menu (donc dans le
			// href réel) sous le slug dérivé par add_menu_page (plugin_basename
			// de l'URL), pas sous son slug interne généré côté client ni l'URL
			// brute (voir apply_menu_visibility / apply_menu_order).
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

		// CSS immédiat (avant peinture, admin_head) : masque le dashicon
		// d'origine tant que le JS n'a pas remplacé le contenu, pour éviter
		// le flash "dashicon puis icône custom" au chargement. L'inline style
		// posé ensuite par le script (opacity:1 !important) prend le dessus,
		// une déclaration inline important battant toujours une règle de
		// feuille de style important sur la même propriété.
		$hide_css = '';
		foreach ( $icons as $entry ) {
			// Sélecteur d'attribut plutôt que #id : un id de menu WP peut
			// contenir des points (toplevel_page_admin-page-wc-settings…),
			// que « #id » interpréterait comme un sélecteur de classe.
			$hide_css .= $entry['id']
				? '#adminmenu li[id="' . str_replace( '"', '', $entry['id'] ) . '"] .wp-menu-image{opacity:0!important}'
				: '#adminmenu a[href*="' . str_replace( [ '"', '<', '>', '\\' ], '', $entry['slug'] ) . '"] .wp-menu-image{opacity:0!important}';
		}

		// Rendu des icônes en masque : la forme vient du SVG, la couleur de la
		// feuille de style — donc alignée sur les dashicons natifs, survol inclus.
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
				// Ciblage principal : l'id du <li>, dérivé du slug d'enregistrement
				// et donc fiable même quand l'extension réécrit l'URL du menu.
				var link = null;
				if ( entry.id ) {
					var li = document.getElementById( entry.id );
					link = li ? li.querySelector( 'a.menu-top' ) : null;
				}
				// Repli historique sur le href pour les entrées sans id (menu
				// construit à la main par une extension, séparateurs promus…).
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
				// Le dashicon d'origine est rendu via un ::before CSS sur les
				// classes dashicons-before/dashicons-xxx : vider innerHTML ne
				// le retire pas (les pseudo-éléments ne font pas partie du DOM).
				// Pour les liens personnalisés, WP a aussi pu poser lui-même un
				// background-image inline (via add_menu_page + icon_url data:)
				// sur ce même élément : on repart d'un style totalement vierge.
				imgEl.className = 'wp-menu-image';
				imgEl.removeAttribute( 'style' );
				// WP applique opacity:.6 sur #adminmenu .wp-menu-image img : il
				// faut forcer opacity:1 en !important pour battre cette règle,
				// et révéler l'icône masquée temporairement par le <style> ci-dessus.
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
				// Aligne verticalement avec les dashicons natifs : WP force
				// padding-top:9px sur .wp-menu-image img (dashicons: 7px de
				// chaque côté pour un glyphe de 20px dans un conteneur de 34px).
				img.style.setProperty( 'padding-top', '7px', 'important' );
				imgEl.appendChild( img );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Force l'opacité pleine des icônes de menu, natives ET personnalisées :
	 * WP atténue par défaut #adminmenu .wp-menu-image img à 60% tant que
	 * l'item n'est pas survolé/actif — comportement jugé peu lisible, à
	 * uniformiser indépendamment d'un profil Menu Creator actif.
	 *
	 * Corrige aussi l'alignement vertical : WP applique padding:9px 0 0 aux
	 * icônes <img> (ex. le logo du plugin lui-même) alors que les dashicons
	 * sont centrés à 7px — d'où un décalage de 2px. On n'override QUE le
	 * padding-top (pas de shorthand ni de box-sizing:border-box, qui ferait
	 * rentrer le padding DANS la taille 20×20 fixée en inline sur nos icônes
	 * custom injectées et les réduirait/désaligne­rait).
	 */
	public function inject_global_icon_opacity_fix(): void {
		echo '<style>#adminmenu .wp-menu-image img{opacity:1!important;padding-top:7px!important}</style>';
	}

	/**
	 * Nettoie un titre de menu WP pour l'éditeur.
	 *
	 * WordPress et les extensions collent leurs compteurs dans le titre lui-même,
	 * sous forme de <span> : « Commentaires <span class="awaiting-mod">0</span> »,
	 * « Extensions <span class="update-plugins">0</span> ». wp_strip_all_tags()
	 * retire le balisage mais garde le chiffre, d'où les libellés absurdes du
	 * type « Commentaires 00 commentaire en modération » dans l'arbre. On retire
	 * donc les <span> avec leur contenu : dans un titre de menu ils ne portent
	 * jamais que ces pastilles.
	 */
	private function clean_menu_label( string $raw ): string {
		$clean = preg_replace( '#<span(?:\s[^>]*)?>.*?</span>#is', '', $raw );
		$clean = wp_strip_all_tags( (string) $clean );
		return trim( (string) preg_replace( '/\s+/u', ' ', $clean ) );
	}

	/**
	 * Slugs de menu WP jamais surfacés dans l'éditeur (et donc jamais appliqués).
	 *
	 * Le Gestionnaire de liens (link-manager.php + sa taxonomie link_category)
	 * est une fonctionnalité legacy désactivée par défaut depuis WP 3.5 : quand
	 * elle est off, elle n'apparaît pas dans le menu WP réel, donc la faire
	 * remonter dans le Créateur crée un item fantôme déroutant. On la masque
	 * par défaut. Filtrable pour les sites qui l'utilisent réellement.
	 *
	 * @return array<int, string>
	 */
	private function editor_excluded_slugs(): array {
		return (array) apply_filters(
			'lumia_mc_editor_excluded_slugs',
			[
				'link-manager.php',
			]
		);
	}

	/**
	 * Reproduit la transformation qu'applique add_menu_page() à un menu_slug :
	 * plugin_basename( esc_url_raw( $url ) ). C'est sous CE slug que le lien
	 * personnalisé existe réellement dans $menu / $menu_order — le matcher
	 * ainsi garantit que l'ordre choisi et l'override d'icône ciblent la bonne
	 * entrée (au lieu de laisser le lien retomber en bas du menu).
	 */
	private function custom_link_slug( string $url ): string {
		return plugin_basename( esc_url_raw( $url ) );
	}

	/**
	 * Décide comment rendre une icône stockée dans le menu admin réel.
	 *
	 * Un SVG monochrome (tracé en `currentColor`) ne peut pas être rendu en
	 * <img> : hors DOM inline, `currentColor` n'hérite d'aucune couleur et
	 * retombe au noir — donc invisible sur la barre latérale sombre. Ces
	 * icônes-là sont rendues en masque CSS (mask=true) et colorisées par la
	 * feuille de style. Les autres médias gardent leurs couleurs via <img>.
	 *
	 * @return array{src:string, mask:bool}|null Null si la valeur est inexploitable.
	 */
	private function resolve_icon_render( string $icon ): ?array {
		if ( 0 === strpos( $icon, 'svg:' ) ) {
			$svg_xml = base64_decode( substr( $icon, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- icône SVG assainie, stockée et rendue en data URI.
			if ( false === $svg_xml ) {
				return null;
			}
			return [
				'src'  => 'data:image/svg+xml;base64,' . base64_encode( $svg_xml ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icône SVG assainie, stockée et rendue en data URI.
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
	 * Un SVG est-il monochrome, donc recolorisable par masque sans rien perdre ?
	 *
	 * Vrai si le tracé est en `currentColor`, s'il ne déclare aucune couleur
	 * (noir par défaut) ou s'il n'en utilise qu'une. Faux dès qu'il y en a
	 * plusieurs : le logo du plugin lui-même (carré blanc + glyphe noir) ne
	 * doit pas être aplati en silhouette pleine — il garde son <img>.
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
	 * Retourne l'attribut id que WordPress rend sur le <li> d'un item de menu
	 * (menu-header.php : preg_replace sur l'index 5 de $menu), ou '' si l'item
	 * est introuvable / sans hookname.
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
	 * Lit le contenu d'un SVG hébergé par ce site (médiathèque ou wp-content),
	 * pour savoir s'il est monochrome ou l'inliner. Retourne null dès que le
	 * fichier n'est pas un SVG local lisible — on ne va jamais chercher une
	 * ressource distante depuis un rendu de page admin.
	 *
	 * Le résultat est mémoïsé : la méthode est appelée à chaque chargement de
	 * page admin, une fois par icône.
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

		// Comparaison insensible au schéma : l'URL stockée peut avoir été
		// enregistrée en http alors que le site répond aujourd'hui en https.
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
		// realpath + préfixe : neutralise un éventuel ../ dans l'URL stockée.
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
	 * Résout un icon_url natif WP (dashicon, data-URI SVG, ou URL média)
	 * à partir du format interne stocké côté client.
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
			$svg_xml = base64_decode( $svg_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- icône SVG assainie, stockée et rendue en data URI.
			if ( false === $svg_xml ) {
				return 'dashicons-admin-links';
			}
			return 'data:image/svg+xml;base64,' . base64_encode( $this->neutralize_svg_color( $svg_xml ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icône SVG assainie, stockée et rendue en data URI.
		}
		if ( strpos( $icon, 'http' ) === 0 ) {
			// Un SVG monochrome servi par URL s'afficherait en noir (currentColor
			// n'hérite de rien dans un background-image) : on l'inline en le
			// teintant, comme pour la bibliothèque interne.
			$local = $this->read_local_svg( $icon );
			if ( null !== $local && false !== stripos( $local, 'currentColor' ) ) {
				return 'data:image/svg+xml;base64,' . base64_encode( $this->neutralize_svg_color( $local ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icône SVG assainie, stockée et rendue en data URI.
			}
			return esc_url_raw( $icon );
		}
		return 'dashicons-admin-links';
	}

	/**
	 * Fige "currentColor" à la couleur de repos réelle des glyphes dashicons
	 * de ce skin admin (#f3f1f1, cf. colors/modern/colors.css) : rendu en
	 * <img>/background-image (pas de DOM inline), currentColor ne peut hériter
	 * d'aucune couleur de texte environnante et résoudrait sinon au noir par
	 * défaut. Fixe (pas de variante hover blanche) — l'écart visuel avec le
	 * blanc pur du survol natif est minime.
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
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'lumia-tools' ) ] );
		}

		$profile = json_decode( $raw, true );
		if ( ! is_array( $profile ) ) {
			wp_send_json_error( [ 'message' => __( 'JSON invalide.', 'lumia-tools' ) ] );
		}

		$sanitized = $this->sanitize_profile( $profile );
		MenuProfileManager::save( $sanitized );
		wp_send_json_success( [ 'profile' => $sanitized ] );
	}

	public function ajax_delete_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		if ( empty( $profile_id ) ) {
			wp_send_json_error( [ 'message' => __( 'ID manquant.', 'lumia-tools' ) ] );
		}

		MenuProfileManager::delete( $profile_id );
		wp_send_json_success();
	}

	public function ajax_duplicate_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}

		$profile_id = isset( $_POST['profile_id'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_id'] ) ) : '';
		$original   = MenuProfileManager::get( $profile_id );
		if ( ! $original ) {
			wp_send_json_error( [ 'message' => __( 'Profil introuvable.', 'lumia-tools' ) ] );
		}

		$copy               = $original;
		$copy['id']         = wp_generate_uuid4();
		$copy['name']       = $original['name'] . ' ' . __( '(copie)', 'lumia-tools' );
		$copy['status']     = 'draft';
		$copy['updated_at'] = time();

		MenuProfileManager::save( $copy );
		wp_send_json_success( [ 'profile' => $copy ] );
	}

	/* ================================================================
	 * EXPORT / IMPORT
	 * ================================================================ */

	/**
	 * Les profils de menu vivent sous leur propre option
	 * (`lumia_wl_menu_profiles`), pas dans `lumia_module_menu_creator` : sans ce
	 * bloc, l'export de configuration du plugin les laisserait de côté.
	 *
	 * @return array<string, mixed>
	 */
	public function get_export_extras(): array {
		$profiles = MenuProfileManager::get_all();
		return $profiles ? [ 'menu_profiles' => $profiles ] : [];
	}

	/**
	 * Rejoue les profils d'un fichier importé.
	 *
	 * Chaque profil repasse par sanitize_profile() — le JSON ne va jamais
	 * directement en base. Fusion par id (MenuProfileManager::save écrase
	 * l'entrée de même id, ajoute sinon) : un fichier partiel ne supprime
	 * aucun menu existant sur le site.
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
	 * Import de menus exportés depuis un autre site.
	 *
	 * Trois formes de fichier acceptées : un profil nu, l'enveloppe d'un menu
	 * (`{profile: …}`) et l'enveloppe multi-menus (`{profiles: […]}`), pour que
	 * les deux boutons d'export de l'éditeur relisent le même endpoint.
	 *
	 * Le JSON ne va jamais directement dans l'option : chaque menu repasse par
	 * sanitize_profile(), exactement comme un enregistrement depuis l'éditeur.
	 * Un menu dont l'id existe déjà met à jour l'existant, sinon il s'ajoute —
	 * un fichier partiel ne supprime donc rien. Tous arrivent en brouillon,
	 * pour ne pas remplacer sans prévenir le menu actif des utilisateurs.
	 */
	public function ajax_import_profile(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Données manquantes.', 'lumia-tools' ) ] );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( [ 'message' => __( 'Fichier illisible : JSON invalide.', 'lumia-tools' ) ] );
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
				$sanitized['name'] = __( 'Menu importé', 'lumia-tools' );
			}
			MenuProfileManager::save( $sanitized );
			$saved[] = $sanitized;
		}

		if ( ! $saved ) {
			wp_send_json_error( [ 'message' => __( 'Ce fichier ne contient aucun menu.', 'lumia-tools' ) ] );
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
	 * Assainit un SVG collé dans le picker d'icônes.
	 *
	 * Réutilise le nettoyeur du module Image Optimizer plutôt que d'en écrire
	 * un second : c'est le même risque (script, href externe, XXE) et la même
	 * liste blanche. Le SVG n'est stocké qu'une fois passé par ce filtre.
	 */
	public function ajax_sanitize_svg(): void {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permissions insuffisantes.', 'lumia-tools' ) ] );
		}

		$raw = isset( $_POST['svg'] ) ? wp_unslash( $_POST['svg'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'Collez le code d’un SVG.', 'lumia-tools' ) ] );
		}
		if ( strlen( $raw ) > 100000 ) {
			wp_send_json_error( [ 'message' => __( 'SVG trop volumineux (100 Ko maximum).', 'lumia-tools' ) ] );
		}

		if ( ! class_exists( SvgHandler::class ) ) {
			wp_send_json_error( [ 'message' => __( 'Nettoyage SVG indisponible.', 'lumia-tools' ) ] );
		}

		$handler = new SvgHandler( [] );
		$clean   = $handler->sanitize( $raw );
		if ( null === $clean ) {
			wp_send_json_error( [ 'message' => __( 'Ce SVG est invalide ou contient du code non autorisé.', 'lumia-tools' ) ] );
		}

		wp_send_json_success( [ 'icon' => 'svg:' . base64_encode( $clean ) ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- icône SVG assainie, stockée et rendue en data URI.
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
		// Garde-fou anti-payload : borne le nombre d'entrées persistées par
		// niveau, pour éviter qu'un profil pathologique ne gonfle l'option.
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
				// N'a de sens que sur un item masqué : un item visible et bloqué
				// serait un piège (lien affiché menant à un refus).
				'block_access' => ! $visible && ! empty( $item['block_access'] ),
				'target_blank' => ! empty( $item['target_blank'] ),
				'url'          => 'custom_link' === $type ? esc_url_raw( $item['url'] ?? '' ) : '',
				// Restriction par rôle, liens personnalisés uniquement (les
				// items WP sont déjà filtrés par leurs propres capacités).
				'roles'        => 'custom_link' === $type
					? array_values( array_filter( array_map( 'sanitize_key', (array) ( $item['roles'] ?? [] ) ) ) )
					: [],
				'children'     => $children,
			];
		}
		return $sanitized;
	}

	/**
	 * Sanitise la valeur d'icône : dashicon / "svg:<base64>" via
	 * sanitize_text_field, mais URL média via esc_url_raw.
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
	 * SortableJS est déclaré en dépendance plutôt que renvoyé par
	 * get_admin_js() : le module Médias charge le même fichier, et deux URL
	 * identiques sous deux handles différents étaient servies deux fois.
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
			'i18n'       => [
				'newMenu'          => __( 'Nouveau menu', 'lumia-tools' ),
				'draft'            => __( 'Brouillon', 'lumia-tools' ),
				'active'           => __( 'Actif', 'lumia-tools' ),
				'unsavedChanges'   => __( 'Modifications non sauvegardées', 'lumia-tools' ),
				'leaveConfirm'     => __( 'Vos modifications seront perdues. Continuer ?', 'lumia-tools' ),
				'deleteConfirmMsg' => __( 'Cette action est irréversible.', 'lumia-tools' ),
			],
		];

		if ( 'module_menu_creator' === $tab && is_admin() && current_user_can( 'manage_options' ) ) {
			global $menu, $submenu;
			// Menu WP d'origine si disponible (capturé avant nos modifications),
			// sinon le global (déjà pristine quand aucun profil n'est actif).
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
	 * Bibliothèque d'icônes du picker, groupée par catégorie (l'ordre des clés
	 * fait l'ordre d'affichage). Les tracés sont repris **tels quels** de
	 * lucide-static v1.34.0 (https://lucide.dev, licence ISC) : aucun n'est
	 * dessiné ou approximé à la main. Pour en ajouter, copier le contenu du
	 * <svg> officiel de l'icône, sans le wrapper.
	 *
	 * @return array<string, array{label:string, icons:array<string, string>}>
	 */
	private function get_icon_library(): array {
		return [
			'general'  => [
				'label' => __( 'Général', 'lumia-tools' ),
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
				'label' => __( 'Contenu', 'lumia-tools' ),
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
				'label' => __( 'Médias', 'lumia-tools' ),
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
				'label' => __( 'Utilisateurs', 'lumia-tools' ),
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
				'label' => __( 'Données', 'lumia-tools' ),
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
				'label' => __( 'Apparence', 'lumia-tools' ),
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
				'label' => __( 'Système', 'lumia-tools' ),
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
	 * Alias de recherche du picker : slug -> mots-clés supplémentaires.
	 *
	 * Les slugs Lucide sont anglais et rarement devinables depuis une interface
	 * française — « funnel » pour un filtre, « banknote » pour un billet,
	 * « boxes » pour un stock. Sans cette table, chercher « filtre » ou
	 * « facture » ne renvoie rien alors que l'icône existe. Un slug absent
	 * d'ici reste cherchable par son nom ; les accents sont ignorés à la
	 * comparaison côté JS, inutile de doubler les entrées.
	 *
	 * @return array<string, string>
	 */
	private function get_icon_aliases(): array {
		return [
			// Général.
			'layout-dashboard'       => 'tableau de bord accueil dashboard widgets',
			'house'                  => 'maison accueil home site',
			'gauge'                  => 'jauge compteur performance vitesse',
			'compass'                => 'boussole exploration navigation découvrir',
			'panels-top-left'        => 'panneaux mise en page layout colonnes',
			'panel-left'             => 'panneau latéral barre sidebar colonne',
			'grid-2x2'               => 'grille cases quadrillage vignettes',
			'list'                   => 'liste éléments lignes énumération',
			'menu'                   => 'menu navigation burger hamburger lignes',
			'star'                   => 'étoile favori note avis mise en avant',
			'heart'                  => 'coeur favori aimé souhaits like',
			'bookmark'               => 'signet marque-page favori enregistré',
			'flag'                   => 'drapeau signalement langue pays repère',
			'bell'                   => 'cloche notification alerte rappel',
			'search'                 => 'recherche loupe trouver chercher',
			'funnel'                 => 'filtre entonnoir trier affiner',
			'sparkles'               => 'étincelles magie ia nouveau brillant',
			'zap'                    => 'éclair rapide performance foudre cache',
			'rocket'                 => 'fusée lancement démarrage rapide déploiement',
			'circle-help'            => 'aide question support faq assistance',
			'info'                   => 'information détail à propos renseignement',
			'badge-check'            => 'badge vérifié validé certifié approuvé',
			'circle-alert'           => 'alerte attention avertissement erreur',
			'eye'                    => 'oeil voir visible aperçu prévisualiser',
			'eye-off'                => 'oeil barré masqué caché invisible',

			// Contenu.
			'file'                   => 'fichier document page vide',
			'file-text'              => 'fichier texte document article page',
			'files'                  => 'fichiers documents copies multiples',
			'folder'                 => 'dossier répertoire classement',
			'folder-open'            => 'dossier ouvert répertoire parcourir',
			'book'                   => 'livre documentation manuel guide',
			'book-open'              => 'livre ouvert lecture documentation guide',
			'notebook-pen'           => 'carnet notes rédaction journal',
			'newspaper'              => 'journal actualités articles presse blog news',
			'pen-line'               => 'stylo écrire éditer rédiger modifier',
			'pencil'                 => 'crayon éditer modifier écrire',
			'square-pen'             => 'éditer modifier crayon rédiger',
			'type'                   => 'typographie police texte caractère',
			'quote'                  => 'citation guillemets témoignage',
			'list-checks'            => 'liste de tâches cases à cocher todo checklist',
			'clipboard-list'         => 'presse-papiers liste tâches formulaire',
			'calendar'               => 'calendrier date agenda événement planning',
			'calendar-days'          => 'calendrier jours agenda planning dates',
			'clock'                  => 'horloge heure temps historique planification',
			'tag'                    => 'étiquette mot-clé label tarif',
			'tags'                   => 'étiquettes mots-clés labels taxonomie',
			'link'                   => 'lien url hyperlien chaîne permalien',
			'paperclip'              => 'trombone pièce jointe fichier attaché',
			'archive'                => 'archive boîte rangement sauvegarde stockage',
			'trash-2'                => 'corbeille supprimer poubelle effacer',

			// Médias.
			'image'                  => 'image photo illustration visuel média',
			'images'                 => 'images photos galerie médiathèque visuels',
			'gallery-horizontal'     => 'galerie carrousel horizontal diaporama',
			'gallery-horizontal-end' => 'galerie carrousel horizontal fin diaporama',
			'gallery-vertical'       => 'galerie vertical colonne diaporama',
			'gallery-vertical-end'   => 'galerie vertical fin colonne diaporama',
			'camera'                 => 'appareil photo caméra cliché capture',
			'video'                  => 'vidéo film caméra lecture séquence',
			'film'                   => 'film pellicule vidéo cinéma montage',
			'music'                  => 'musique audio son note piste',
			'mic'                    => 'micro podcast enregistrement audio voix',
			'play'                   => 'lecture jouer démarrer lancer',
			'headphones'             => 'casque écoute audio son podcast',
			'upload'                 => 'téléverser envoyer importer charger upload',
			'download'               => 'télécharger exporter récupérer download',
			'cloud-upload'           => 'nuage téléverser sauvegarde distant cloud',

			// Commerce.
			'shopping-bag'           => 'sac achat boutique commande shopping',
			'shopping-cart'          => 'panier caddie achat commande boutique',
			'store'                  => 'boutique magasin commerce vitrine',
			'package'                => 'colis paquet produit livraison module extension',
			'package-2'              => 'colis paquet produit stock livraison',
			'package-open'           => 'colis ouvert déballage produit livraison',
			'truck'                  => 'camion livraison expédition transport',
			'receipt'                => 'reçu facture ticket note commande',
			'credit-card'            => 'carte bancaire paiement carte de crédit règlement',
			'banknote'               => 'billet argent monnaie paiement espèces',
			'dollar-sign'            => 'dollar devise prix argent tarif',
			'euro'                   => 'euro devise prix argent tarif',
			'percent'                => 'pourcentage remise promotion solde taux',
			'gift'                   => 'cadeau offre bon promotion récompense',
			'wallet'                 => 'portefeuille solde paiement porte-monnaie',
			'ticket'                 => 'billet coupon code promo ticket réduction',
			'boxes'                  => 'stock inventaire cartons entrepôt produits',

			// Utilisateurs.
			'user-round'             => 'utilisateur compte profil personne membre',
			'users-round'            => 'utilisateurs comptes membres équipe groupe rôles',
			'user-round-plus'        => 'ajouter un utilisateur nouveau compte inscription membre',
			'user-round-cog'         => 'réglages du compte profil permissions rôle utilisateur',
			'contact-round'          => 'contact carnet répertoire fiche personne',
			'id-card'                => 'carte identité badge profil fiche',
			'mail'                   => 'e-mail courriel message enveloppe contact',
			'message-circle'         => 'message discussion commentaire chat bulle',
			'message-square'         => 'message commentaire discussion chat avis',
			'phone'                  => 'téléphone appel contact numéro',
			'at-sign'                => 'arobase e-mail mention identifiant courriel',
			'handshake'              => 'poignée de main partenariat accord affiliation',
			'user-round-check'       => 'utilisateur validé compte vérifié approuvé membre',

			// Données.
			'chart-column'           => 'graphique barres statistiques rapport histogramme',
			'chart-line'             => 'graphique courbe statistiques évolution tendance',
			'chart-pie'              => 'graphique camembert secteurs répartition statistiques',
			'trending-up'            => 'tendance croissance hausse progression statistiques',
			'activity'               => 'activité pouls journal suivi monitoring',
			'database'               => 'base de données sql tables stockage',
			'server'                 => 'serveur hébergement infrastructure machine',
			'hard-drive'             => 'disque dur stockage espace sauvegarde',
			'table'                  => 'tableau tableur grille colonnes données',

			// Apparence.
			'palette'                => 'palette couleurs thème design apparence',
			'swatch-book'            => 'nuancier couleurs échantillons charte thème',
			'paintbrush'             => 'pinceau peinture style personnalisation thème',
			'brush'                  => 'brosse pinceau style couleur personnalisation',
			'layers'                 => 'calques couches empilement superposition',
			'blocks'                 => 'blocs éditeur gutenberg composants briques',
			'toy-brick'              => 'brique bloc module extension composant',
			'puzzle'                 => 'puzzle extension module greffon plugin pièce',
			'component'              => 'composant élément bloc module',
			'wand-sparkles'          => 'baguette magique automatique effets ia embellir',
			'sliders-horizontal'     => 'réglages curseurs options filtres paramètres',
			'sliders-vertical'       => 'réglages curseurs égaliseur options paramètres',
			'ruler'                  => 'règle mesure dimensions taille espacement',
			'frame'                  => 'cadre encadrement bordure conteneur',
			'layout-template'        => 'modèle gabarit template mise en page structure',

			// Système.
			'settings'               => 'réglages paramètres configuration options engrenage',
			'settings-2'             => 'réglages paramètres options configuration curseurs',
			'wrench'                 => 'clé outils maintenance réparation dépannage',
			'cog'                    => 'engrenage réglages configuration rouage paramètres',
			'shield'                 => 'bouclier sécurité protection pare-feu',
			'shield-check'           => 'sécurité vérifiée protection validée bouclier',
			'lock'                   => 'cadenas verrou sécurité privé protégé mot de passe',
			'key'                    => 'clé mot de passe accès licence identifiant jeton',
			'plug'                   => 'prise branchement extension connexion intégration',
			'power'                  => 'alimentation marche arrêt activer désactiver',
			'terminal'               => 'terminal console commande shell cli',
			'code'                   => 'code développement html balise snippet',
			'bug'                    => 'bogue erreur débogage anomalie problème',
			'refresh-cw'             => 'actualiser recharger synchroniser mise à jour rafraîchir',
			'hammer'                 => 'marteau outils construction maintenance',
			'life-buoy'              => 'bouée support aide assistance secours',
			'log-out'                => 'déconnexion sortir quitter session',
			'globe'                  => 'globe monde site web international langue',
			'map'                    => 'carte plan géographie itinéraire',
			'map-pin'                => 'épingle localisation adresse position lieu',
			'map-pinned'             => 'carte localisation adresse position lieux',
			'pin'                    => 'épingle épingler fixer marquer',
			'megaphone'              => 'mégaphone annonce marketing communication promotion',
			'rss'                    => 'flux rss syndication abonnement actualités',
			'share-2'                => 'partager partage réseaux sociaux diffusion',
			'external-link'          => 'lien externe nouvel onglet sortant ouvrir',
			'monitor'                => 'écran bureau ordinateur affichage desktop',
			'smartphone'             => 'mobile téléphone responsive portable écran',
			'languages'              => 'langues traduction international multilingue localisation',
		];
	}

	/**
	 * Bibliothèque aplatie : slug → SVG complet, consommé par le picker JS.
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
	 * Catégories du picker : identifiant → libellé + liste ordonnée de slugs.
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
