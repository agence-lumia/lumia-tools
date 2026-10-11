// Test pages of the Motion scenarios. Each task adds its pages to PAGES and the class
// settings it needs to CLASSES; `run.sh setup` creates or replaces them (idempotent by slug).
import { writeFileSync } from 'node:fs';
import { OUT, wp, pluginPath } from './lib.mjs';

/**
 * Global classes the pages need, by name. An object replaces that class's settings;
 * a class referenced by an element but absent here is created empty when missing,
 * and left untouched when it exists.
 */
export const CLASSES = {};

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
