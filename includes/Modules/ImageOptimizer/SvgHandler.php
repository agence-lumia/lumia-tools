<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Support SVG sécurisé pour la médiathèque.
 *
 * - Autorise l'upload de .svg uniquement pour les rôles cochés dans les réglages.
 * - Assainit chaque fichier à l'upload (suppression du JS, gestionnaires d'événements,
 *   références externes, etc.) via une passe DOMDocument à liste blanche.
 * - Corrige la détection MIME de WordPress qui rejette sinon le fichier.
 *
 * Rien n'est branché tant que le réglage n'est pas activé (voir Module::init()).
 */
class SvgHandler {

	private const MIME = 'image/svg+xml';

	/** Éléments SVG autorisés (liste blanche). */
	private const ALLOWED_TAGS = [
		'a',
		'circle',
		'clippath',
		'defs',
		'desc',
		'ellipse',
		'feblend',
		'fecolormatrix',
		'fecomponenttransfer',
		'fecomposite',
		'feconvolvematrix',
		'fediffuselighting',
		'fedisplacementmap',
		'fedistantlight',
		'feflood',
		'fefunca',
		'fefuncb',
		'fefuncg',
		'fefuncr',
		'fegaussianblur',
		'feimage',
		'femerge',
		'femergenode',
		'femorphology',
		'feoffset',
		'fepointlight',
		'fespecularlighting',
		'fespotlight',
		'fetile',
		'feturbulence',
		'filter',
		'g',
		'image',
		'line',
		'lineargradient',
		'marker',
		'mask',
		'metadata',
		'path',
		'pattern',
		'polygon',
		'polyline',
		'radialgradient',
		'rect',
		'stop',
		'style',
		'svg',
		'switch',
		'symbol',
		'text',
		'textpath',
		'title',
		'tspan',
		'use',
	];

	/**
	 * Réglages du module (svg_upload, svg_roles).
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * @param array<string, mixed> $settings
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Branche les filtres si le support SVG est activé.
	 */
	public function init(): void {
		if ( empty( $this->settings['svg_upload'] ) ) {
			return;
		}

		add_filter( 'upload_mimes', [ $this, 'allow_mime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ $this, 'fix_filetype' ], 10, 3 );
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'sanitize_on_upload' ] );
	}

	/* ================================================================
	 * AUTORISATIONS
	 * ================================================================ */

	/**
	 * L'utilisateur courant a-t-il un rôle autorisé à uploader des SVG ?
	 */
	private function current_user_can_upload(): bool {
		$allowed = (array) ( $this->settings['svg_roles'] ?? [] );
		if ( empty( $allowed ) ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return (bool) array_intersect( (array) $user->roles, $allowed );
	}

	/**
	 * Ajoute le MIME SVG à la liste autorisée pour les rôles habilités.
	 *
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public function allow_mime( $mimes ) {
		if ( $this->current_user_can_upload() ) {
			$mimes['svg'] = self::MIME;
		}
		return $mimes;
	}

	/**
	 * Corrige la détection type/extension de WordPress pour les .svg.
	 *
	 * @param array<string, mixed> $data
	 * @param string $file
	 * @param string $filename
	 * @return array<string, mixed>
	 */
	public function fix_filetype( $data, $file, $filename ) {
		if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
			return $data;
		}

		if ( 'svg' === strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) && $this->current_user_can_upload() ) {
			$data['ext']  = 'svg';
			$data['type'] = self::MIME;
		}

		return $data;
	}

	/* ================================================================
	 * ASSAINISSEMENT
	 * ================================================================ */

	/**
	 * Filtre wp_handle_upload_prefilter : assainit le SVG avant qu'il ne soit
	 * déplacé dans la médiathèque. Rejette le fichier si l'assainissement échoue.
	 *
	 * @param array<string, mixed> $file
	 * @return array<string, mixed>
	 */
	public function sanitize_on_upload( $file ) {
		$type = $file['type'] ?? '';
		$name = $file['name'] ?? '';

		$is_svg = self::MIME === $type
			|| 'svg' === strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! $is_svg ) {
			return $file;
		}

		if ( ! $this->current_user_can_upload() ) {
			$file['error'] = __( 'Votre rôle n\'est pas autorisé à téléverser des fichiers SVG.', 'lumia-tools' );
			return $file;
		}

		$path = $file['tmp_name'] ?? '';
		if ( ! $path || ! is_readable( $path ) ) {
			return $file;
		}

		// Fichier temporaire local de l'upload PHP : WP_Filesystem, s'il passe par FTP, ne l'atteint pas.
		$dirty = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $dirty || '' === trim( (string) $dirty ) ) {
			$file['error'] = __( 'Le fichier SVG est vide ou illisible.', 'lumia-tools' );
			return $file;
		}

		$clean = $this->sanitize( $dirty );
		if ( null === $clean ) {
			$file['error'] = __( 'Le fichier SVG est invalide ou n\'a pas pu être assaini.', 'lumia-tools' );
			return $file;
		}

		file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $file;
	}

	/**
	 * Assainit une chaîne SVG. Retourne le SVG nettoyé, ou null si invalide.
	 */
	public function sanitize( string $svg ): ?string {
		// Retire une éventuelle BOM et les instructions de traitement PHP.
		$svg = (string) preg_replace( '/<\?php.*?\?>/is', '', $svg );

		// Bloque les définitions de type de document (attaques XXE / entités externes).
		if ( preg_match( '/<!DOCTYPE/i', $svg ) && preg_match( '/<!ENTITY/i', $svg ) ) {
			return null;
		}

		$libxml_previous = libxml_use_internal_errors( true );

		$dom                     = new \DOMDocument();
		$dom->preserveWhiteSpace = false;

		// NB : on n'ajoute jamais LIBXML_NOENT — l'expansion d'entités est un vecteur d'attaque.
		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $libxml_previous );

		$root = $dom->documentElement;
		if ( ! $loaded || ! $root instanceof \DOMElement ) {
			return null;
		}

		if ( 'svg' !== strtolower( $root->nodeName ) ) {
			return null;
		}

		// Supprime les DOCTYPE et nœuds de type doctype.
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				$dom->removeChild( $child );
			}
		}

		// Nettoie les attributs de la racine <svg> elle-même, puis récursivement les enfants.
		$this->clean_attributes( $root );
		$this->clean_node( $root );

		$out = $dom->saveXML( $root, LIBXML_NOEMPTYTAG );
		if ( false === $out ) {
			return null;
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
	}

	/**
	 * Nettoie récursivement un nœud : supprime les balises hors liste blanche
	 * et tout attribut dangereux.
	 */
	private function clean_node( \DOMNode $node ): void {
		// Parcourt une copie : on mute les enfants pendant l'itération.
		if ( ! $node->hasChildNodes() ) {
			return;
		}
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				// Retire commentaires / PI / doctype résiduels.
				if ( in_array( $child->nodeType, [ XML_COMMENT_NODE, XML_PI_NODE ], true ) ) {
					$node->removeChild( $child );
				}
				continue;
			}

			$local = (string) $child->localName;
			$tag   = strtolower( '' !== $local ? $local : $child->nodeName );

			if ( ! in_array( $tag, self::ALLOWED_TAGS, true ) ) {
				$node->removeChild( $child );
				continue;
			}

			$this->clean_attributes( $child );

			// <style> est sur la liste blanche, mais seuls ses ATTRIBUTS étaient
			// nettoyés : le nœud texte à l'intérieur traversait l'assainisseur
			// intact. Un `@import url("//evil.tld/x.css")` survivait donc à
			// l'upload et déclenchait une requête sortante à chaque rendu du
			// SVG — traçage, et CSS arbitraire si le SVG est intégré en ligne.
			if ( 'style' === $tag ) {
				$this->clean_style_element( $child );
				continue;
			}

			$this->clean_node( $child );
		}
	}

	/**
	 * Assainit le contenu textuel d'un élément <style>.
	 *
	 * Le CSS n'a rien d'inerte : `@import` et `url()` sont des requêtes
	 * réseau, `expression()` et `-moz-binding` ont été des vecteurs
	 * d'exécution. On applique aux ressources la MÊME liste blanche que
	 * `is_safe_href()` — ancres internes et images en data: — pour ne pas
	 * entretenir deux définitions du « sûr » qui finiraient par diverger.
	 */
	private function clean_style_element( \DOMElement $el ): void {
		$css = $el->textContent;

		$propre = $this->sanitize_css( (string) $css );

		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}

		if ( '' !== trim( $propre ) && null !== $el->ownerDocument ) {
			$el->appendChild( $el->ownerDocument->createTextNode( $propre ) );
		}
	}

	/**
	 * Retire d'une feuille de style tout ce qui sort du document ou s'exécute.
	 *
	 * Deux précautions d'ordre, comme pour la requête SQL de l'éditeur de base
	 * de données :
	 *  - les commentaires CSS partent EN PREMIER : `@imp/⁎ ⁎/ort` n'est pas un
	 *    at-rule valide, mais un `url(/⁎ ⁎/…)` suffisait à brouiller un motif ;
	 *  - les échappements hexadécimaux sont décodés avant tout test. `\40 import`
	 *    EST `@import` pour le navigateur ; ne pas le décoder, c'est ne
	 *    reconnaître que la forme naïve de l'attaque.
	 */
	private function sanitize_css( string $css ): string {
		$sans_commentaires = preg_replace( '#/\*.*?\*/#s', ' ', $css );
		$css               = ( null === $sans_commentaires ) ? $css : $sans_commentaires;

		if ( function_exists( 'mb_chr' ) ) {
			$decode = preg_replace_callback(
				'/\\\\([0-9a-fA-F]{1,6})[ \t\n]?/',
				static function ( array $m ) {
					$cp = (int) hexdec( $m[1] );
					return ( $cp > 0 && $cp < 0x110000 ) ? (string) mb_chr( $cp, 'UTF-8' ) : '';
				},
				$css
			);
			$css    = ( null === $decode ) ? $css : $decode;
		}

		// At-rules qui chargent une ressource externe.
		$css = (string) preg_replace( '/@\s*(import|namespace)\b[^;{]*(;|\{[^}]*\})?/i', '', $css );

		// Vecteurs d'exécution historiques. On retire la DÉCLARATION entière et
		// pas le seul mot-clé : effacer « expression( » laissait « alert(1)) »
		// derrière soi, c'est-à-dire du CSS invalide dans un fichier qu'on vient
		// de déclarer propre.
		$css = (string) preg_replace( '/[\w-]+\s*:[^;}]*expression\s*\([^;}]*/i', '', $css );
		$css = (string) preg_replace( '/(-moz-binding|behavior)\s*:[^;}]*/i', '', $css );

		// url() : seules les ancres internes et les images en data: passent.
		$css = (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i',
			function ( array $m ) {
				return $this->is_safe_href( $m[2] ) ? $m[0] : 'none';
			},
			$css
		);

		// Filet : si un schéma exécutable ou un vecteur historique subsiste
		// malgré tout (ex. « expression(…) » sans propriété devant, que le
		// motif de déclaration ne couvre pas), on ne cherche pas à réparer la
		// feuille — on la jette.
		if ( preg_match( '/(javascript|vbscript|data\s*:\s*text\/html)\s*:|expression\s*\(|-moz-binding|behavior\s*:/i', $css ) ) {
			return '';
		}

		return $css;
	}

	/**
	 * Supprime les attributs dangereux d'un élément.
	 */
	private function clean_attributes( \DOMElement $el ): void {
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = $attr->nodeValue;

			// Tout gestionnaire d'événement (onload, onclick, …).
			if ( 0 === strpos( $name, 'on' ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// href / xlink:href : n'autorise que les schémas sûrs ou les ancres internes.
			if ( 'href' === $name || 'xlink:href' === $name ) {
				if ( ! $this->is_safe_href( (string) $value ) ) {
					$el->removeAttributeNode( $attr );
				}
				continue;
			}

			// Attributs pouvant embarquer du script.
			$decoded = html_entity_decode( (string) $value, ENT_QUOTES );
			$decoded = (string) preg_replace( '/\s+/', '', $decoded );
			if ( preg_match( '/(javascript|data:text\/html|vbscript):/i', $decoded ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// style : le MÊME nettoyeur que l'élément <style> (commentaires et
			// échappements normalisés, url() sur liste blanche, expression()
			// et -moz-binding retirés) — on n'entretient pas deux définitions
			// du « sûr ». Un style vidé par le nettoyage est retiré.
			if ( 'style' === $name ) {
				$propre = trim( $this->sanitize_css( (string) $value ) );
				if ( '' === $propre ) {
					$el->removeAttributeNode( $attr );
				} else {
					$attr->nodeValue = $propre;
				}
			}
		}
	}

	/**
	 * Un href est-il sûr ? Ancres internes (#id) et data: d'image uniquement.
	 */
	private function is_safe_href( string $value ): bool {
		$value = trim( html_entity_decode( $value, ENT_QUOTES ) );

		if ( '' === $value || 0 === strpos( $value, '#' ) ) {
			return true;
		}

		// data:image/... autorisé ; data:text/html interdit.
		if ( preg_match( '/^data:image\/(png|jpe?g|gif|webp);base64,/i', $value ) ) {
			return true;
		}

		return false;
	}
}
