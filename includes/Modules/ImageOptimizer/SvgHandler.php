<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Secure SVG support for the media library.
 *
 * - Allows .svg uploads only for the roles ticked in the settings.
 * - Sanitizes every file on upload (removal of JS, event handlers,
 *   external references, etc.) through a whitelist-based DOMDocument pass.
 * - Fixes WordPress's MIME detection, which would otherwise reject the file.
 *
 * Nothing is hooked until the setting is enabled (see Module::init()).
 */
class SvgHandler {

	private const MIME = 'image/svg+xml';

	/** Allowed SVG elements (whitelist). */
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
	 * Module settings (svg_upload, svg_roles).
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
	 * Hooks the filters if SVG support is enabled.
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
	 * PERMISSIONS
	 * ================================================================ */

	/**
	 * Does the current user have a role allowed to upload SVGs?
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
	 * Adds the SVG MIME type to the allowed list for the authorized roles.
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
	 * Fixes WordPress's type/extension detection for .svg files.
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
	 * SANITIZATION
	 * ================================================================ */

	/**
	 * Filter wp_handle_upload_prefilter: sanitizes the SVG before it is
	 * moved into the media library. Rejects the file if sanitization fails.
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
			$file['error'] = __( 'Your role is not allowed to upload SVG files.', 'lumia-tools' );
			return $file;
		}

		$path = $file['tmp_name'] ?? '';
		if ( ! $path || ! is_readable( $path ) ) {
			return $file;
		}

		// Local temporary file of the PHP upload: WP_Filesystem, if it goes through FTP, cannot reach it.
		$dirty = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $dirty || '' === trim( (string) $dirty ) ) {
			$file['error'] = __( 'The SVG file is empty or unreadable.', 'lumia-tools' );
			return $file;
		}

		$clean = $this->sanitize( $dirty );
		if ( null === $clean ) {
			$file['error'] = __( 'The SVG file is invalid or could not be sanitized.', 'lumia-tools' );
			return $file;
		}

		file_put_contents( $path, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $file;
	}

	/**
	 * Sanitizes an SVG string. Returns the cleaned SVG, or null if invalid.
	 */
	public function sanitize( string $svg ): ?string {
		// Removes a possible BOM and the PHP processing instructions.
		$svg = (string) preg_replace( '/<\?php.*?\?>/is', '', $svg );

		// Blocks document type definitions (XXE / external entity attacks).
		if ( preg_match( '/<!DOCTYPE/i', $svg ) && preg_match( '/<!ENTITY/i', $svg ) ) {
			return null;
		}

		$libxml_previous = libxml_use_internal_errors( true );

		$dom                     = new \DOMDocument();
		$dom->preserveWhiteSpace = false;

		// NB: we never add LIBXML_NOENT — entity expansion is an attack vector.
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

		// Removes DOCTYPE and doctype-type nodes.
		foreach ( iterator_to_array( $dom->childNodes ) as $child ) {
			if ( XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				$dom->removeChild( $child );
			}
		}

		// Cleans the attributes of the <svg> root itself, then the children recursively.
		$this->clean_attributes( $root );
		$this->clean_node( $root );

		$out = $dom->saveXML( $root, LIBXML_NOEMPTYTAG );
		if ( false === $out ) {
			return null;
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out;
	}

	/**
	 * Recursively cleans a node: removes the tags outside the whitelist
	 * and any dangerous attribute.
	 */
	private function clean_node( \DOMNode $node ): void {
		// Walks a copy: we mutate the children during the iteration.
		if ( ! $node->hasChildNodes() ) {
			return;
		}
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				// Removes leftover comments / PIs / doctypes.
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

			// <style> is on the whitelist, but only its ATTRIBUTES were
			// cleaned: the text node inside went through the sanitizer
			// untouched. So an `@import url("//evil.tld/x.css")` survived the
			// upload and triggered an outgoing request on every render of the
			// SVG — tracking, and arbitrary CSS if the SVG is inlined.
			if ( 'style' === $tag ) {
				$this->clean_style_element( $child );
				continue;
			}

			$this->clean_node( $child );
		}
	}

	/**
	 * Sanitizes the text content of a <style> element.
	 *
	 * CSS is not inert: `@import` and `url()` are network
	 * requests, `expression()` and `-moz-binding` have been execution
	 * vectors. We apply to resources the SAME whitelist as
	 * `is_safe_href()` — internal anchors and data: images — so as not to
	 * maintain two definitions of "safe" that would end up diverging.
	 */
	private function clean_style_element( \DOMElement $el ): void {
		$css = $el->textContent;

		$clean = $this->sanitize_css( (string) $css );

		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}

		if ( '' !== trim( $clean ) && null !== $el->ownerDocument ) {
			$el->appendChild( $el->ownerDocument->createTextNode( $clean ) );
		}
	}

	/**
	 * Removes from a stylesheet everything that leaves the document or executes.
	 *
	 * Two ordering precautions, as for the SQL query of the database
	 * editor:
	 *  - CSS comments go FIRST: `@imp/⁎ ⁎/ort` is not a valid
	 *    at-rule, but a `url(/⁎ ⁎/…)` was enough to throw a pattern off;
	 *  - hexadecimal escapes are decoded before any test. `\40 import`
	 *    IS `@import` for the browser; not decoding it means only
	 *    recognizing the naive form of the attack.
	 */
	private function sanitize_css( string $css ): string {
		$without_comments = preg_replace( '#/\*.*?\*/#s', ' ', $css );
		$css              = ( null === $without_comments ) ? $css : $without_comments;

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

		// At-rules that load an external resource.
		$css = (string) preg_replace( '/@\s*(import|namespace)\b[^;{]*(;|\{[^}]*\})?/i', '', $css );

		// Historical execution vectors. We remove the whole DECLARATION and
		// not just the keyword: erasing "expression(" left "alert(1))"
		// behind, i.e. invalid CSS in a file we have just
		// declared clean.
		$css = (string) preg_replace( '/[\w-]+\s*:[^;}]*expression\s*\([^;}]*/i', '', $css );
		$css = (string) preg_replace( '/(-moz-binding|behavior)\s*:[^;}]*/i', '', $css );

		// url(): only internal anchors and data: images get through.
		$css = (string) preg_replace_callback(
			'/url\(\s*([\'"]?)([^)\'"]*)\1\s*\)/i',
			function ( array $m ) {
				return $this->is_safe_href( $m[2] ) ? $m[0] : 'none';
			},
			$css
		);

		// Safety net: if an executable scheme or a historical vector remains
		// anyway (e.g. "expression(…)" with no property in front, which the
		// declaration pattern does not cover), we do not try to repair the
		// stylesheet — we throw it away.
		if ( preg_match( '/(javascript|vbscript|data\s*:\s*text\/html)\s*:|expression\s*\(|-moz-binding|behavior\s*:/i', $css ) ) {
			return '';
		}

		return $css;
	}

	/**
	 * Removes the dangerous attributes of an element.
	 */
	private function clean_attributes( \DOMElement $el ): void {
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = $attr->nodeValue;

			// Any event handler (onload, onclick, …).
			if ( 0 === strpos( $name, 'on' ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// href / xlink:href: only allows safe schemes or internal anchors.
			if ( 'href' === $name || 'xlink:href' === $name ) {
				if ( ! $this->is_safe_href( (string) $value ) ) {
					$el->removeAttributeNode( $attr );
				}
				continue;
			}

			// Attributes that may embed script.
			$decoded = html_entity_decode( (string) $value, ENT_QUOTES );
			$decoded = (string) preg_replace( '/\s+/', '', $decoded );
			if ( preg_match( '/(javascript|data:text\/html|vbscript):/i', $decoded ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}

			// style: the SAME cleaner as the <style> element (comments and
			// escapes normalized, url() whitelisted, expression()
			// and -moz-binding removed) — we do not maintain two definitions
			// of "safe". A style emptied by the cleaning is removed.
			if ( 'style' === $name ) {
				$clean = trim( $this->sanitize_css( (string) $value ) );
				if ( '' === $clean ) {
					$el->removeAttributeNode( $attr );
				} else {
					$attr->nodeValue = $clean;
				}
			}
		}
	}

	/**
	 * Is an href safe? Internal anchors (#id) and image data: URIs only.
	 */
	private function is_safe_href( string $value ): bool {
		$value = trim( html_entity_decode( $value, ENT_QUOTES ) );

		if ( '' === $value || 0 === strpos( $value, '#' ) ) {
			return true;
		}

		// data:image/... allowed; data:text/html forbidden.
		if ( preg_match( '/^data:image\/(png|jpe?g|gif|webp);base64,/i', $value ) ) {
			return true;
		}

		return false;
	}
}
