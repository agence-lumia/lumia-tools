// Counter: every format comes back exactly as written, an ambiguous text is never touched,
// a bouncy curve never counts past the value, inner markup is kept, screen readers get the
// written value while it counts, a start the page cannot reach still counts, reduced motion
// shows the values as written.
import { newPage, pageUrl, assert, items } from '../lib.mjs';

const SLUG = 'lm-e2e-counter';
const IDS = [ 'brxe-cnyear', 'brxe-cngrp', 'brxe-cndec', 'brxe-cnpfx', 'brxe-cnamb', 'brxe-cnmark', 'brxe-cnbnc', 'brxe-cnlate' ];
const COUNTING = IDS.filter( ( id ) => id !== 'brxe-cnamb' && id !== 'brxe-cnlate' );

async function open( o = {} ) {
	const page = await newPage( o );
	const res = await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ SLUG }: status ${ res && res.status() }` );
	await page.waitForFunction(
		() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
		null,
		{ timeout: 5000 }
	).catch( () => {
		throw new Error( `${ SLUG }: the engine did not start` );
	} );
	return page;
}

/** Markup of the counters as the server sent it. */
const originals = ( page ) => page.evaluate( async ( ids ) => {
	const html = await ( await fetch( location.href ) ).text();
	const doc = new DOMParser().parseFromString( html, 'text/html' );
	const out = {};
	ids.forEach( ( id ) => {
		out[ id ] = doc.getElementById( id ).innerHTML;
	} );
	return out;
}, IDS );

const markup = ( page ) => page.evaluate( ( ids ) => Object.fromEntries( ids.map( ( id ) => [ id, document.getElementById( id ).innerHTML ] ) ), IDS );

/** Installs a per-frame sampler of the text the eye sees (screen reader copies left out). */
const sample = ( page ) => page.evaluate( ( ids ) => {
	const seen = ( node ) => {
		let out = '';
		node.childNodes.forEach( ( child ) => {
			if ( child.nodeType === 3 ) {
				out += child.nodeValue;
			} else if ( child.nodeType === 1 && ! child.classList.contains( 'lm-sr' ) ) {
				out += seen( child );
			}
		} );
		return out;
	};
	window.lmSeen = seen;
	window.lmSamples = Object.fromEntries( ids.map( ( id ) => [ id, [] ] ) );
	window.lmStrong = document.querySelector( '#brxe-cnmark strong' );
	window.lmStrongLost = 0;
	const tick = () => {
		ids.forEach( ( id ) => window.lmSamples[ id ].push( seen( document.getElementById( id ) ) ) );
		if ( document.querySelector( '#brxe-cnmark strong' ) !== window.lmStrong ) {
			window.lmStrongLost++;
		}
		if ( ! window.lmStop ) {
			requestAnimationFrame( tick );
		}
	};
	requestAnimationFrame( tick );
}, IDS );

const states = async ( page, ids ) => ( await items( page ) ).filter( ( i ) => ids.includes( i.id ) ).map( ( i ) => `${ i.id }:${ i.state }` );

/** What assistive technologies get for each counter (Playwright's ARIA snapshot). */
async function aria( page ) {
	const out = {};
	for ( const id of IDS ) {
		out[ id ] = await page.locator( `#${ id }` ).ariaSnapshot();
	}
	return out;
}

async function animated( reference ) {
	const page = await open();
	const orig = await originals( page );

	// Pending: screen readers get the written value, not the start value on screen.
	const pending = await aria( page );
	for ( const id of IDS ) {
		assert( pending[ id ] === reference[ id ], `${ id } while pending: screen readers get\n${ pending[ id ] }\ninstead of\n${ reference[ id ] }` );
	}

	await sample( page );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	const firstSeen = await page.evaluate( () => window.lmSeen( document.getElementById( 'brxe-cnyear' ) ) );
	assert( firstSeen === '0', `cnyear while pending shows "${ firstSeen }" (expected its start value 0)` );

	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForFunction(
		( ids ) => ids.every( ( id ) => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || {} ).state === 'done' ),
		COUNTING,
		{ timeout: 6000 }
	).catch( async () => {
		throw new Error( `counters never finished: ${ ( await states( page, COUNTING ) ).join( ', ' ) }` );
	} );
	await page.waitForTimeout( 100 );
	const result = await page.evaluate( () => {
		window.lmStop = true;
		return { samples: window.lmSamples, strongLost: window.lmStrongLost };
	} );

	// Every text exactly as written at the end.
	const end = await markup( page );
	for ( const id of IDS.filter( ( i ) => i !== 'brxe-cnlate' ) ) {
		assert( end[ id ] === orig[ id ], `${ id }: "${ end[ id ] }" at the end (expected "${ orig[ id ] }")` );
	}

	// Every counter really counted.
	for ( const id of COUNTING ) {
		const distinct = new Set( result.samples[ id ] );
		assert( distinct.size >= 4, `${ id }: only ${ distinct.size } distinct value(s) seen (${ [ ...distinct ].slice( 0, 6 ).join( ' | ' ) })` );
	}

	// "24/7" never changed, at any frame.
	const ambiguous = [ ...new Set( result.samples[ 'brxe-cnamb' ] ) ];
	assert( ambiguous.length === 1 && ambiguous[ 0 ] === '24/7', `24/7 changed: ${ ambiguous.join( ' | ' ) }` );

	// A bouncy curve never counts past 100.
	const bouncy = result.samples[ 'brxe-cnbnc' ].map( ( s ) => parseFloat( s ) );
	assert( bouncy.every( ( v ) => v <= 100 ), `bouncy counter went past 100: ${ bouncy.filter( ( v ) => v > 100 ).join( ', ' ) }` );
	const bouncyItem = ( await items( page ) ).find( ( i ) => i.id === 'brxe-cnbnc' );
	assert( bouncyItem.options.easeKey === 'smooth', `bouncy counter: curve ${ bouncyItem.options.easeKey } (expected smooth)` );

	// Inner markup: the <strong> node itself was kept, the number counted inside it.
	assert( result.strongLost === 0, `cnmark: the <strong> element was replaced during ${ result.strongLost } frame(s)` );
	const strongSamples = new Set( result.samples[ 'brxe-cnmark' ] );
	assert( [ ...strongSamples ].every( ( s ) => / projets$/.test( s ) ), `cnmark: text outside the number changed (${ [ ...strongSamples ].slice( 0, 5 ).join( ' | ' ) })` );

	// Formats while counting: grouping and decimal separator kept.
	const dec = result.samples[ 'brxe-cndec' ].filter( ( s ) => s !== '98,5 %' );
	assert( dec.every( ( s ) => /^\d+,\d %$/.test( s ) ), `cndec: unexpected format while counting: ${ dec.filter( ( s ) => ! /^\d+,\d %$/.test( s ) ).slice( 0, 5 ).join( ' | ' ) }` );
	const pfx = result.samples[ 'brxe-cnpfx' ];
	assert( pfx.every( ( s ) => /^\+\d+ clients$/.test( s ) ), `cnpfx: unexpected format while counting: ${ pfx.filter( ( s ) => ! /^\+\d+ clients$/.test( s ) ).slice( 0, 5 ).join( ' | ' ) }` );

	// A start the page cannot reach (30% at the very bottom): counts once the end is reached.
	const late = await page.evaluate( () => {
		const max = document.scrollingElement.scrollHeight - window.innerHeight;
		const node = document.getElementById( 'brxe-cnlate' );
		return { max, start: node.getBoundingClientRect().top + window.scrollY - window.innerHeight * 0.3 };
	} );
	assert( late.start > late.max, `cnlate: start ${ late.start } is reachable (max ${ late.max })` );
	await page.evaluate( () => window.scrollTo( 0, document.scrollingElement.scrollHeight ) );
	await page.waitForFunction( () => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === 'brxe-cnlate' ) || {} ).state === 'done', null, { timeout: 5000 } ).catch( async () => {
		throw new Error( `cnlate never counted at the bottom of the page: ${ ( await states( page, [ 'brxe-cnlate' ] ) ).join() }` );
	} );
	assert( ( await markup( page ) )[ 'brxe-cnlate' ] === orig[ 'brxe-cnlate' ], 'cnlate: not back to its written value' );

	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** Destroyed while counting: every text back as written. */
async function destroyed() {
	const page = await open();
	const orig = await originals( page );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 200 );
	await page.evaluate( () => window.lumiaMotion.destroy() );
	const end = await markup( page );
	IDS.forEach( ( id ) => assert( end[ id ] === orig[ id ], `destroy(): ${ id } is "${ end[ id ] }" (expected "${ orig[ id ] }")` ) );
	assert( page.errors.length === 0, `destroy(): page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** Reduced motion: values as written from the load. Returns the ARIA snapshots of the plain page, the reference. */
async function reduced() {
	const page = await open( { reducedMotion: 'reduce' } );
	const orig = await originals( page );
	const now = await markup( page );
	IDS.forEach( ( id ) => assert( now[ id ] === orig[ id ], `reduced: ${ id } is "${ now[ id ] }" at load (expected "${ orig[ id ] }")` ) );
	const pending = ( await items( page ) ).filter( ( i ) => i.state === 'pending' );
	assert( pending.length === 0, `reduced: pending items ${ pending.map( ( i ) => i.id ).join( ', ' ) }` );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 500 );
	const later = await markup( page );
	IDS.forEach( ( id ) => assert( later[ id ] === orig[ id ], `reduced: ${ id } changed to "${ later[ id ] }" after scrolling` ) );
	const reference = await aria( page );
	assert( page.errors.length === 0, `reduced: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
	return reference;
}

export default async function () {
	const reference = await reduced();
	await animated( reference );
	await destroyed();
}
