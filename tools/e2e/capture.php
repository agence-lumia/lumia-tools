<?php
/**
 * Captures the visible text of the plugin's admin pages, one .txt file per page.
 * Bench only, never shipped. Plain PHP, run in the `cli` container:
 *
 *   php /e2e/capture.php <output-subdirectory> [admin-page-slug]
 *
 * The slug defaults to studio-kyne-mini-tools ("lumia-tools" after the rename).
 * Files land in /e2e/out/<output-subdirectory>/ (tools/e2e/out/ on the host).
 *
 * Pages are fetched from the `wordpress` service with the X-E2E-User header
 * (see mu-plugins/e2e-auth.php), except the custom login page, which a visitor
 * sees logged out. Each page is reduced to text: script, style, the WP admin
 * menu, the admin bar and the footer are dropped, text is split one line per
 * block, and runs of ASCII whitespace are collapsed. Non-breaking spaces
 * (U+00A0, U+202F) are kept on purpose: they are part of the French typography.
 * Attributes that carry visible text (placeholder, title, aria-label...) come out
 * as "[attribute] text" lines.
 */

const E2E_BASE_URL  = 'http://wordpress';
const E2E_HOST      = 'localhost:8089';
const E2E_OUT_ROOT  = '/e2e/out';
const E2E_MODULES   = [ 'image_optimizer', 'security', 'login', 'files', 'white_label', 'menu_creator', 'database', 'media', 'activity_log', 'smtp' ];
const E2E_LOGIN_URL = '/connexion-e2e/';

/** Elements that start and end a line of text. */
const E2E_BLOCKS = [
	'address', 'article', 'aside', 'blockquote', 'body', 'br', 'button', 'caption', 'dd', 'details', 'div', 'dl', 'dt',
	'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'label',
	'legend', 'li', 'main', 'nav', 'ol', 'optgroup', 'option', 'p', 'pre', 'section', 'select', 'summary', 'table',
	'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'tr', 'ul',
];

/** Attributes whose value is read by the user (tooltips, labels, modal copy). */
const E2E_ATTRIBUTES = [ 'placeholder', 'title', 'aria-label', 'alt', 'data-skmt-tip', 'data-title', 'data-modal-title', 'data-modal-message', 'data-modal-confirm-label' ];

/**
 * @return array{0:int,1:string} HTTP status and body.
 */
function e2e_fetch( string $path, bool $authenticated ): array {
	$headers = [ 'Host: ' . E2E_HOST, 'Accept-Language: fr-FR,fr;q=0.9' ];
	if ( $authenticated ) {
		$headers[] = 'X-E2E-User: admin';
	}

	$context = stream_context_create(
		[
			'http' => [
				'method'          => 'GET',
				'header'          => implode( "\r\n", $headers ),
				'follow_location' => 0,
				'ignore_errors'   => true,
				'timeout'         => 60,
			],
		]
	);

	$body   = file_get_contents( E2E_BASE_URL . $path, false, $context );
	$status = 0;
	// $http_response_header is set by file_get_contents() in this scope.
	if ( isset( $http_response_header[0] ) && preg_match( '#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m ) ) {
		$status = (int) $m[1];
	}

	return [ $status, false === $body ? '' : $body ];
}

function e2e_squash( string $text ): string {
	return trim( (string) preg_replace( '/[ \t\r\n\f]+/', ' ', $text ) );
}

/**
 * @param string[] $lines
 */
function e2e_flush( array &$lines, string &$current ): void {
	$line = e2e_squash( $current );
	if ( '' !== $line ) {
		$lines[] = $line;
	}
	$current = '';
}

/**
 * @param string[] $lines
 */
function e2e_walk( DOMNode $node, array &$lines, string &$current ): void {
	if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
		$current .= $node->nodeValue;
		return;
	}

	if ( XML_ELEMENT_NODE !== $node->nodeType ) {
		return;
	}

	/** @var DOMElement $node */
	$name  = strtolower( $node->nodeName );
	$block = in_array( $name, E2E_BLOCKS, true );

	if ( $block ) {
		e2e_flush( $lines, $current );
	}

	foreach ( E2E_ATTRIBUTES as $attribute ) {
		if ( $node->hasAttribute( $attribute ) && '' !== e2e_squash( $node->getAttribute( $attribute ) ) ) {
			e2e_flush( $lines, $current );
			$lines[] = '[' . $attribute . '] ' . e2e_squash( $node->getAttribute( $attribute ) );
		}
	}

	if ( 'input' === $name && in_array( strtolower( $node->getAttribute( 'type' ) ), [ 'submit', 'button', 'reset' ], true ) ) {
		e2e_flush( $lines, $current );
		$lines[] = e2e_squash( $node->getAttribute( 'value' ) );
	}

	foreach ( iterator_to_array( $node->childNodes ) as $child ) {
		e2e_walk( $child, $lines, $current );
	}

	if ( $block ) {
		e2e_flush( $lines, $current );
	}
}

function e2e_text( string $html ): string {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$lines = [];

	$title = $xpath->query( '//title' )->item( 0 );
	if ( $title && '' !== e2e_squash( $title->textContent ) ) {
		$lines[] = '[title] ' . e2e_squash( $title->textContent );
	}

	$drop = '//script|//style|//noscript|//template|//head|//*[@id="adminmenumain"]|//*[@id="wpadminbar"]|//*[@id="wpfooter"]';
	foreach ( iterator_to_array( $xpath->query( $drop ) ) as $element ) {
		$element->parentNode->removeChild( $element );
	}

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );
	if ( $body ) {
		$current = '';
		e2e_walk( $body, $lines, $current );
		e2e_flush( $lines, $current );
	}

	return implode( "\n", $lines ) . "\n";
}

/* ------------------------------------------------------------------ */

$out_name = $argv[1] ?? '';
$slug     = $argv[2] ?? 'studio-kyne-mini-tools';

if ( '' === $out_name || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $out_name ) || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
	fwrite( STDERR, "Usage: php capture.php <output-subdirectory> [admin-page-slug]\n" );
	exit( 2 );
}

$out_dir = E2E_OUT_ROOT . '/' . $out_name;
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	fwrite( STDERR, "Cannot create {$out_dir}\n" );
	exit( 1 );
}

$page = '/wp-admin/admin.php?page=' . $slug . '&tab=';
$urls = [
	'dashboard' => [ $page . 'dashboard', true ],
	'modules'   => [ $page . 'modules', true ],
	'settings'  => [ $page . 'settings', true ],
];
foreach ( E2E_MODULES as $module_id ) {
	$urls[ 'module_' . $module_id ] = [ $page . 'module_' . $module_id, true ];
}
$urls['login-url'] = [ E2E_LOGIN_URL, false ];

$failed = 0;
foreach ( $urls as $file => [ $path, $authenticated ] ) {
	[ $status, $html ] = e2e_fetch( $path, $authenticated );
	$text              = 200 === $status ? e2e_text( $html ) : '';

	// An unauthenticated admin request lands on the login form instead of the page.
	if ( '' === trim( $text ) || ( $authenticated && false !== strpos( $html, 'id="loginform"' ) ) ) {
		fwrite( STDERR, sprintf( "FAIL %-24s HTTP %d, no usable text (%s)\n", $file, $status, $path ) );
		++$failed;
		continue;
	}

	file_put_contents( "{$out_dir}/{$file}.txt", $text );
	printf( "ok   %-24s %4d lines\n", $file, substr_count( $text, "\n" ) );
}

exit( $failed > 0 ? 1 : 0 );
