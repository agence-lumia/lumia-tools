// Test pages of the Motion scenarios. Each task adds its pages to PAGES and the class
// settings it needs to CLASSES; `run.sh setup` creates or replaces them (idempotent by slug).
import { writeFileSync } from 'node:fs';
import { OUT, wp, pluginPath } from './lib.mjs';

/**
 * Global classes the pages need, by name. An object replaces that class's settings;
 * a class referenced by an element but absent here is created empty when missing,
 * and left untouched when it exists.
 */
export const CLASSES = {
	// Bricks stores a number control with a fixed unit as a bare string; the unit is added in the CSS.
	'lm-e2e-preset': { _lmEffect: 'reveal', _lmStartAt: '60' },
};

/** Posts the query loops need: published when missing (by slug), never modified. */
export const POSTS = [ 1, 2, 3 ].map( ( n ) => ( { slug: `lm-e2e-post-${ n }`, title: `LM E2E post ${ n }` } ) );

/** Pages by slug: { title, elements: [node] } (post_type 'page' unless set). */
export const PAGES = {};

/**
 * Bricks element node. Explicit ids keep selectors stable (`#brxe-<id>`).
 *
 * @param {string} name     Bricks element name (heading, text-basic, div, section, container...).
 * @param {object} settings Element settings (`_lm*` controls, text, tag...).
 * @param {Array}  children Child nodes.
 * @param {object} [o]      id (6 lowercase chars recommended), classes (global class names).
 */
export function el( name, settings = {}, children = [], o = {} ) {
	return { name, settings, children, id: o.id || null, classes: o.classes || [] };
}

export const section = ( children, o = {} ) => el( 'section', {}, [ el( 'container', {}, children ) ], o );
export const heading = ( text, settings = {}, o = {} ) => el( 'heading', { text, tag: 'h2', ...settings }, [], o );
export const text = ( content, settings = {}, o = {} ) => el( 'text-basic', { text: content, ...settings }, [], o );
export const spacer = ( px ) => el( 'div', { _height: `${ px }px` } );

PAGES[ 'lm-e2e-smoke' ] = {
	title: 'LM E2E smoke',
	elements: [ section( [ heading( 'Smoke test', { tag: 'h1' }, { id: 'smokeh' } ) ] ) ],
};

PAGES[ 'lm-e2e-marker' ] = {
	title: 'LM E2E marker',
	elements: [
		section( [
			heading( 'Fade by class', {}, { id: 'mkfade', classes: [ 'lm-fade' ] } ),
			heading( 'Words by setting', { _lmEffect: 'text-words' }, { id: 'mkword' } ),
			text( '1200', {}, { id: 'mkcnt', classes: [ 'sk-counter' ] } ),
			heading( 'No effect', {}, { id: 'mkplain' } ),
			// Outside a loop: the element ID selector (1,0,0) beats the class (0,2,0).
			heading( 'Preset outside the loop', { _lmStartAt: '30' }, { id: 'mkout', classes: [ 'lm-e2e-preset' ] } ),
			// Inside a loop: the class selector is all the element has, so the inline style must win.
			el(
				'block',
				{ hasLoop: true, query: { objectType: 'post', post_type: [ 'post' ], posts_per_page: 3 } },
				[
					heading( '{post_title}', { _lmStartAt: '30' }, { id: 'mkitem', classes: [ 'lm-e2e-preset' ] } ),
					heading( '{post_title}', {}, { id: 'mkbare', classes: [ 'lm-e2e-preset' ] } ),
					// Inside a loop the element rule (0,3,0) already beats the class: its breakpoint values must survive.
					heading( '{post_title}', { _lmStartAt: '30', '_lmStartAt:mobile_portrait': '10' }, { id: 'mkbp', classes: [ 'lm-e2e-preset' ] } ),
				],
				{ id: 'mkloop' }
			),
			// The element that holds the loop: its rule (0,2,0) ties with the class and loses by source order.
			el(
				'block',
				{ hasLoop: true, query: { objectType: 'post', post_type: [ 'post' ], posts_per_page: 3 }, _lmStartAt: '30' },
				[ heading( '{post_title}', {}, { id: 'mkselfh' } ) ],
				{ id: 'mkself', classes: [ 'lm-e2e-preset' ] }
			),
		] ),
	],
};

// Loading scenario: a page with no animation, one with a class effect, one with a text effect.
PAGES[ 'lm-e2e-none' ] = {
	title: 'LM E2E none',
	elements: [ section( [ heading( 'No animation', { tag: 'h1' }, { id: 'nonehd' } ), text( 'Plain text', {}, { id: 'nonetx' } ) ] ) ],
};

// Entrance effects (enter, scroll-position, loading): more than 6000 px tall, so a reload at 3000 has room below.
PAGES[ 'lm-e2e-basic' ] = {
	title: 'LM E2E basic',
	elements: [
		section( [
			heading( 'Fade on load', { tag: 'h1' }, { id: 'basichd', classes: [ 'lm-fade' ] } ),
			text( '<a id="lmanchor" href="/lm-e2e-anchor/#target">Go to the target of the anchor page</a>', {}, { id: 'basiclk' } ),
			spacer( 1200 ),
			// Four presets in a row: the class gives reveal at 60%, three of them adjust it.
			heading( 'Preset', {}, { id: 'bpre01', classes: [ 'lm-e2e-preset' ] } ),
			heading( 'Preset at 30%', { _lmStartAt: '30' }, { id: 'bpre30', classes: [ 'lm-e2e-preset' ] } ),
			heading( 'Preset at 80% on phones', { '_lmStartAt:mobile_portrait': '80' }, { id: 'bpremb', classes: [ 'lm-e2e-preset' ] } ),
			heading( 'Preset, fast and bouncy', { _lmTempo: 'fast', _lmEase: 'bouncy' }, { id: 'bprefb', classes: [ 'lm-e2e-preset' ] } ),
			// A bouncy curve overshoots: the blur must still never go below 0.
			heading( 'Blur in, bouncy', { _lmEffect: 'blur-in', _lmEase: 'bouncy' }, { id: 'bblurb' } ),
			spacer( 1200 ),
		] ),
		// An animated section: its heading has no setting and must not inherit any.
		section( [ heading( 'Nested heading, no setting', {}, { id: 'bnesth' } ) ], { id: 'bnest', classes: [ 'lm-fade' ] } ),
		section( [
			spacer( 800 ),
			heading( 'Blur in, none on phones', { _lmEffect: 'blur-in', '_lmEffect:mobile_portrait': 'none' }, { id: 'bblur' } ),
			heading( 'Two effect classes', {}, { id: 'bdual', classes: [ 'lm-fade', 'lm-reveal' ] } ),
			// Values typed straight into the content, as the builder would never write them.
			heading(
				'Invalid values',
				{
					_lmEffect: 'blur-in',
					_lmTempo: 'exact',
					_lmDuration: 'abc',
					_lmStart: 'foo;bar',
					_lmEase: 'custom',
					_lmEaseCustom: 'cubic-bezier(2,0,0,1)',
				},
				{ id: 'binval' }
			),
			heading( 'Former class name', {}, { id: 'bskfd', classes: [ 'sk-fade' ] } ),
			spacer( 2600 ),
		] ),
	],
};

// A page too short for its last elements to reach their start: 30% and the preset's 60%.
PAGES[ 'lm-e2e-short' ] = {
	title: 'LM E2E short',
	elements: [
		section( [
			heading( 'Short page', { tag: 'h1' }, { id: 'shorth' } ),
			spacer( 900 ),
			heading( 'Last but one, preset at 30%', { _lmStartAt: '30' }, { id: 'shrt30', classes: [ 'lm-e2e-preset' ] } ),
			heading( 'Last, preset at 60%', {}, { id: 'shrtpr', classes: [ 'lm-e2e-preset' ] } ),
		] ),
	],
};

// Anchor target 3000 px down, reached from the link of lm-e2e-basic.
PAGES[ 'lm-e2e-anchor' ] = {
	title: 'LM E2E anchor',
	elements: [
		section( [
			heading( 'Anchor page', { tag: 'h1' }, { id: 'anchhd' } ),
			// A link to the target of the same page (a click scrolls; a link from another page navigates).
			text( '<a id="lmlocal" href="#target">Go to the target</a>', {}, { id: 'anchlk' } ),
			spacer( 3000 ),
			el( 'div', { _cssId: 'target', _height: '200px' }, [ heading( 'Target', {}, { id: 'anchtt' } ) ], { id: 'anchtg' } ),
			spacer( 3000 ),
		] ),
	],
};

// Text effects (text, loading). The last two elements sit too close to the bottom for their
// start (30%) to be reached; the h3 reaches its start but not its end (top -120%).
const LINES_TEXT =
	'Motion should feel like a <strong>natural consequence</strong> of reading: each line rises from under its own mask, ' +
	'one after the other, and the paragraph is left <em>exactly</em> as it was written once the last line has landed, ' +
	'so that nothing of the animation remains in the page for the visitor, the screen reader or the next script.';
const WORDS_TEXT =
	'Words light up one by one while the page scrolls, as if the reader was following them with a finger. ' +
	'Scroll back up and they fade again, scroll down and they come back, always in step with the scroll bar, ' +
	'never ahead of it and never behind it, whatever the width of the screen they are read on.';

PAGES[ 'lm-e2e-text' ] = {
	title: 'LM E2E text',
	elements: [
		section( [
			heading( 'Words one by one', { tag: 'h1', _lmEffect: 'text-words' }, { id: 'texthd' } ),
			spacer( 1200 ),
			heading( 'Lines rise from under a mask', {}, { id: 'txlnhd', classes: [ 'lm-text-lines' ] } ),
			spacer( 1200 ),
			text( LINES_TEXT, { tag: 'p' }, { id: 'txlnpp', classes: [ 'lm-text-lines' ] } ),
			spacer( 1200 ),
			text( WORDS_TEXT, { tag: 'p' }, { id: 'txword', classes: [ 'lm-text-words' ] } ),
			spacer( 1200 ),
			heading( 'Characters light up at the end', { tag: 'h3' }, { id: 'txchar', classes: [ 'lm-text-chars' ] } ),
			spacer( 600 ),
			text( 'Last words, started at 30%', { tag: 'p', _lmStartAt: '30' }, { id: 'txwlat', classes: [ 'lm-text-words' ] } ),
			text( 'Last lines, started at 30%', { tag: 'p', _lmStartAt: '30' }, { id: 'txllat', classes: [ 'lm-text-lines' ] } ),
			spacer( 100 ),
		] ),
	],
};

// Counters: one of each format, an ambiguous text, inner markup, a bouncy curve, and a start
// the page cannot reach at the very bottom.
PAGES[ 'lm-e2e-counter' ] = {
	title: 'LM E2E counter',
	elements: [
		section( [
			heading( 'Counters', { tag: 'h1' }, { id: 'cnthd' } ),
			spacer( 1200 ),
			text( '2024', {}, { id: 'cnyear', classes: [ 'lm-counter' ] } ),
			text( '1 200', {}, { id: 'cngrp', classes: [ 'lm-counter' ] } ),
			text( '98,5 %', {}, { id: 'cndec', classes: [ 'lm-counter' ] } ),
			text( '+150 clients', {}, { id: 'cnpfx', classes: [ 'lm-counter' ] } ),
			text( '24/7', {}, { id: 'cnamb', classes: [ 'lm-counter' ] } ),
			text( '<strong>350</strong> projets', {}, { id: 'cnmark', classes: [ 'lm-counter' ] } ),
			text( '100', { _lmEase: 'bouncy' }, { id: 'cnbnc', classes: [ 'lm-counter' ] } ),
			spacer( 1200 ),
			text( '75', { _lmStartAt: '30' }, { id: 'cnlate', classes: [ 'lm-counter' ] } ),
			spacer( 100 ),
		] ),
	],
};

// Bricks popups, real ones: two templates (content rendered in the footer, content fetched
// when it opens) shown on two pages, one with an animated element (the engine is loaded) and
// one without (it is not; only the AJAX popup, whose content is not in the page, is on it).
// A button opens each popup. "@id:<slug>" is resolved by setup.php.
const popupButton = ( label, popup, id, interaction ) => el(
	'button',
	{ text: label, _interactions: [ { id: interaction, trigger: 'click', action: 'show', target: 'popup', templateId: `@id:${ popup }` } ] },
	[],
	{ id }
);

PAGES[ 'lm-e2e-popup' ] = {
	title: 'LM E2E popup',
	elements: [
		section( [
			heading( 'Popup page', { tag: 'h1' }, { id: 'popuhd', classes: [ 'lm-fade' ] } ),
			popupButton( 'Open the static popup', 'lm-e2e-popup-static', 'popbs1', 'popis1' ),
			popupButton( 'Open the AJAX popup', 'lm-e2e-popup-ajax', 'popba1', 'popia1' ),
		] ),
	],
};

PAGES[ 'lm-e2e-popup-plain' ] = {
	title: 'LM E2E popup plain',
	elements: [
		section( [
			heading( 'Popup page without animation', { tag: 'h1' }, { id: 'popphd' } ),
			popupButton( 'Open the AJAX popup', 'lm-e2e-popup-ajax', 'popba2', 'popia2' ),
		] ),
	],
};

const popupOn = ( ...slugs ) => ( { templateConditions: [ { main: 'ids', ids: slugs.map( ( slug ) => `@id:${ slug }` ) } ] } );

PAGES[ 'lm-e2e-popup-static' ] = {
	title: 'LM E2E popup static',
	post_type: 'bricks_template',
	meta: { _bricks_template_type: 'popup', _bricks_template_settings: popupOn( 'lm-e2e-popup' ) },
	elements: [ el( 'block', {}, [ heading( 'Static popup title', { tag: 'h3' }, { id: 'pops1h', classes: [ 'lm-fade' ] } ) ], { id: 'pops1b' } ) ],
};

PAGES[ 'lm-e2e-popup-ajax' ] = {
	title: 'LM E2E popup AJAX',
	post_type: 'bricks_template',
	meta: { _bricks_template_type: 'popup', _bricks_template_settings: { ...popupOn( 'lm-e2e-popup', 'lm-e2e-popup-plain' ), popupAjax: true } },
	elements: [ el( 'block', {}, [ heading( 'AJAX popup title', { tag: 'h3' }, { id: 'popa1h', classes: [ 'lm-fade' ] } ) ], { id: 'popa1b' } ) ],
};

/** Flattens a node tree into Bricks' element list. */
function flatten( nodes, slug ) {
	const out = [];
	let n = 0;
	const walk = ( node, parent ) => {
		const id = node.id || `${ slug.replace( /[^a-z]/g, '' ).slice( -2 ) }${ ( n++ ).toString( 36 ).padStart( 4, 'z' ) }`;
		const item = { id, name: node.name, parent, children: [], settings: node.settings, classes: node.classes };
		out.push( item );
		item.children = node.children.map( ( c ) => walk( c, id ) );
		return id;
	};
	nodes.forEach( ( node ) => walk( node, 0 ) );
	return out;
}

export async function setup() {
	const payload = {
		classes: CLASSES,
		posts: POSTS,
		pages: Object.entries( PAGES ).map( ( [ slug, p ] ) => ( {
			slug,
			title: p.title,
			post_type: p.post_type || 'page',
			meta: p.meta || {},
			elements: flatten( p.elements, slug ),
		} ) ),
	};
	writeFileSync( `${ OUT }fixtures.json`, JSON.stringify( payload ) );
	console.log( wp( 'eval-file', pluginPath( 'tools/e2e/motion/setup.php' ), pluginPath( 'tools/e2e/motion/out/fixtures.json' ) ) );
	wp( 'bricks', 'regenerate_assets' );
}
