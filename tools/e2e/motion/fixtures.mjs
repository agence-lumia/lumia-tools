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
	'lm-e2e-preset': { _lmEffect: 'fade', _lmStartAt: '60' },
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
