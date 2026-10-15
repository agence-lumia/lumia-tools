// Counter (NumberFlow): every format ends exactly as written, for the eye; assistive
// technologies get the written text at every moment, never the start value; the line
// never changes height and, in fixed width, the number never changes width; a year never
// gets a group separator; an ambiguous text is never touched; inner markup is kept; a start the page cannot reach still counts. Fallbacks: NumberFlow blocked
// or reduced motion leave the text as written. A page without counter loads none of it, a
// counter that only sits in a footer popup still gets it.
import { newPage, pageUrl, assert, items } from '../lib.mjs';

const SLUG = 'lm-e2e-counter';
const DYNAMIC = [ 'brxe-cnyear', 'brxe-cngrp', 'brxe-cndec', 'brxe-cnpfx' ];
const FIXED = [ 'brxe-cnyerf', 'brxe-cngrpf', 'brxe-cndecf', 'brxe-cnpfxf' ];
const IDS = [ ...DYNAMIC, ...FIXED, 'brxe-cnamb', 'brxe-cnmark', 'brxe-cnbnc', 'brxe-cnlate' ];
const COUNTING = IDS.filter( ( id ) => id !== 'brxe-cnamb' && id !== 'brxe-cnlate' );
// The bundle (motion-counter.js) and the sources it is built from, never loaded as modules.
const FLOW_FILES = /\/(number-flow|esm-env)\/|motion-counter\.m?js/;

async function open( o = {}, before = null ) {
	const page = await newPage( o );
	if ( before ) {
		await before( page );
	}
	const res = await page.goto( pageUrl( o.slug || SLUG ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ o.slug || SLUG }: status ${ res && res.status() }` );
	await page.waitForFunction(
		() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
		null,
		{ timeout: 5000 }
	).catch( () => {
		throw new Error( `${ o.slug || SLUG }: the engine did not start` );
	} );
	return page;
}

/** HTML of the page as the server sent it, and the text of each counter in it. */
const served = ( page ) => page.evaluate( async ( ids ) => {
	const html = await ( await fetch( location.href ) ).text();
	const doc = new DOMParser().parseFromString( html, 'text/html' );
	const out = { html, markup: {}, text: {} };
	ids.forEach( ( id ) => {
		out.markup[ id ] = doc.getElementById( id ).innerHTML;
		out.text[ id ] = doc.getElementById( id ).textContent;
	} );
	return out;
}, IDS );

const markup = ( page ) => page.evaluate( ( ids ) => Object.fromEntries( ids.map( ( id ) => [ id, document.getElementById( id ).innerHTML ] ) ), IDS );

const states = async ( page, ids ) => ( await items( page ) ).filter( ( i ) => ids.includes( i.id ) ).map( ( i ) => `${ i.id }:${ i.state }` );

/** Role, name and ignored state of the NumberFlow element of a counter in Chrome's accessibility tree. */
async function flowName( page, id ) {
	const cdp = await page.context().newCDPSession( page );
	try {
		const { root } = await cdp.send( 'DOM.getDocument', { depth: -1, pierce: true } );
		const { nodeId } = await cdp.send( 'DOM.querySelector', { nodeId: root.nodeId, selector: `#${ id } lm-number-flow` } );
		if ( ! nodeId ) {
			return null;
		}
		const { nodes } = await cdp.send( 'Accessibility.getPartialAXTree', { nodeId, fetchRelatives: false } );
		const node = nodes[ 0 ] || {};
		return { role: node.role && node.role.value, name: node.name && node.name.value, ignored: !! node.ignored };
	} finally {
		await cdp.detach();
	}
}

/** What assistive technologies get for each counter (Playwright's ARIA snapshot, aria-hidden honoured). */
async function aria( page ) {
	const out = {};
	for ( const id of IDS ) {
		out[ id ] = await page.locator( `#${ id }` ).ariaSnapshot();
	}
	return out;
}

/** Screen readers get exactly what they get from the plain page, the written text once. */
async function sameAria( page, reference, when ) {
	const now = await aria( page );
	for ( const id of IDS ) {
		assert( now[ id ] === reference[ id ], `${ id } ${ when }: screen readers get\n${ now[ id ] }\ninstead of\n${ reference[ id ] }` );
	}
}

/** Per-frame sampler: height of each counter, width of its NumberFlow element, symbols shown. */
const sample = ( page ) => page.evaluate( ( ids ) => {
	window.lmSamples = Object.fromEntries( ids.map( ( id ) => [ id, [] ] ) );
	window.lmStrong = document.querySelector( '#brxe-cnmark strong' );
	window.lmStrongLost = 0;
	const tick = () => {
		ids.forEach( ( id ) => {
			const el = document.getElementById( id );
			const flow = el.querySelector( 'lm-number-flow' );
			// Symbols shown (group, decimal, prefix, suffix), the leaving ones (inert) left out.
			const symbols = flow && flow.shadowRoot ? [ ...flow.shadowRoot.querySelectorAll( '.symbol' ) ].filter( ( s ) => ! s.closest( '[inert]' ) ).map( ( s ) => s.textContent ).join( '|' ) : null;
			// Layout width of the element, and visual width of its number (NumberFlow scales it while digits appear).
			const number = flow && flow.shadowRoot ? flow.shadowRoot.querySelector( '.number' ) : null;
			window.lmSamples[ id ].push( {
				height: el.getBoundingClientRect().height,
				width: flow ? flow.getBoundingClientRect().width : null,
				visual: number ? number.getBoundingClientRect().width : null,
				symbols,
				playing: ( window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || {} ).state === 'playing',
			} );
		} );
		if ( document.querySelector( '#brxe-cnmark strong' ) !== window.lmStrong ) {
			window.lmStrongLost++;
		}
		if ( ! window.lmStop ) {
			requestAnimationFrame( tick );
		}
	};
	requestAnimationFrame( tick );
}, IDS );

async function animated( reference ) {
	const page = await open();
	const orig = await served( page );

	// The number is in the HTML the server sends (what a crawler reads without JavaScript).
	for ( const id of COUNTING ) {
		assert( orig.text[ id ].trim() !== '' && /\d/.test( orig.text[ id ] ), `${ id }: no number in the served HTML` );
	}

	// Mounted while pending: a NumberFlow element in place of the number, the ambiguous text untouched.
	await page.waitForFunction( ( ids ) => ids.every( ( id ) => document.querySelector( `#${ id } lm-number-flow` ) ), COUNTING, { timeout: 4000 } ).catch( async () => {
		throw new Error( `counters not mounted: ${ ( await markup( page ) )[ 'brxe-cnyear' ] }` );
	} );
	assert( ! ( await page.locator( '#brxe-cnamb lm-number-flow' ).count() ), 'cnamb (24/7) got a NumberFlow element' );

	// Before the counters start: the written value for screen readers, never the start value.
	await sameAria( page, reference, 'while pending' );

	// Heights before anything plays: the line keeps its height once NumberFlow is in.
	const heightAtRest = await page.evaluate( ( ids ) => Object.fromEntries( ids.map( ( id ) => [ id, document.getElementById( id ).getBoundingClientRect().height ] ) ), COUNTING );

	await sample( page );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'start' } ) );
	// While they roll.
	await page.waitForFunction( () => window.lumiaMotion._debug.items().some( ( i ) => i.id === 'brxe-cnyear' && i.state === 'playing' ), null, { timeout: 3000 } );
	await page.waitForTimeout( 150 );
	await sameAria( page, reference, 'while playing' );
	await page.waitForFunction(
		( ids ) => ids.every( ( id ) => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || {} ).state === 'done' ),
		COUNTING,
		{ timeout: 8000 }
	).catch( async () => {
		throw new Error( `counters never finished: ${ ( await states( page, COUNTING ) ).join( ', ' ) }` );
	} );
	await page.waitForTimeout( 300 );
	const result = await page.evaluate( () => {
		window.lmStop = true;
		return { samples: window.lmSamples, strongLost: window.lmStrongLost };
	} );

	for ( const id of COUNTING ) {
		const frames = result.samples[ id ];
		const played = frames.filter( ( f ) => f.playing ).length;
		assert( played >= 30, `${ id }: only ${ played } frame(s) sampled while playing` );

		// Height constant across the animation (both widths), and equal to the height at rest.
		const heights = [ ...new Set( frames.map( ( f ) => f.height.toFixed( 2 ) ) ) ];
		assert( heights.length === 1 && heights[ 0 ] === heightAtRest[ id ].toFixed( 2 ), `${ id }: height changed: ${ heights.join( ', ' ) } (at rest ${ heightAtRest[ id ] })` );
	}
	assert( COUNTING.every( ( id ) => result.samples[ id ].length >= 60 ), 'fewer than 60 frames sampled' );

	// Fixed width: the NumberFlow element and the number it draws never change width.
	for ( const id of FIXED ) {
		for ( const key of [ 'width', 'visual' ] ) {
			const widths = [ ...new Set( result.samples[ id ].map( ( f ) => f[ key ].toFixed( 1 ) ) ) ];
			assert( widths.length === 1, `${ id }: ${ key } width changed in fixed mode: ${ widths.slice( 0, 6 ).join( ', ' ) }` );
		}
	}
	// Dynamic width: the number grows as its digits appear (the year starts at "0").
	for ( const id of DYNAMIC ) {
		const visual = new Set( result.samples[ id ].map( ( f ) => f.visual.toFixed( 1 ) ) );
		assert( visual.size > 3, `${ id }: the drawn number took only ${ visual.size } width(s) in dynamic mode` );
	}

	// A year never shows a group separator, nor any other symbol.
	for ( const id of [ 'brxe-cnyear', 'brxe-cnyerf' ] ) {
		const bad = result.samples[ id ].filter( ( f ) => f.symbols !== '' );
		assert( bad.length === 0, `${ id }: symbols shown: ${ [ ...new Set( bad.map( ( f ) => f.symbols ) ) ].join( ' / ' ) }` );
	}
	// The other formats keep their separators, prefix and suffix while they count.
	const symbolsOf = ( id ) => new Set( result.samples[ id ].map( ( f ) => f.symbols ) );
	assert( [ ...symbolsOf( 'brxe-cndec' ) ].every( ( s ) => s === ',| %' ), `cndec symbols: ${ [ ...symbolsOf( 'brxe-cndec' ) ].join( ' / ' ) }` );
	assert( [ ...symbolsOf( 'brxe-cnpfx' ) ].every( ( s ) => s === '+| clients' ), `cnpfx symbols: ${ [ ...symbolsOf( 'brxe-cnpfx' ) ].join( ' / ' ) }` );
	// Fixed width: "0 000" from the start, so the group separator is always there.
	assert( [ ...symbolsOf( 'brxe-cngrpf' ) ].every( ( s ) => s === ' ' ), `cngrpf symbols: ${ [ ...symbolsOf( 'brxe-cngrpf' ) ].join( ' / ' ) }` );

	// The end: the NumberFlow element is kept and hidden from assistive technologies (Chrome's
	// own tree, its ElementInternals name included), which get the written text, once.
	await sameAria( page, reference, 'at the end' );
	for ( const id of COUNTING ) {
		assert( await page.locator( `#${ id } lm-number-flow` ).count() === 1, `${ id }: the NumberFlow element is gone at the end` );
		const ax = await flowName( page, id );
		assert( ax && ax.ignored, `${ id }: the NumberFlow element is in the accessibility tree: ${ JSON.stringify( ax ) }` );
	}

	// What a crawler that renders the page reads (innerText leaves the shadow DOM out): the
	// written text, through the visually hidden copy.
	const rendered = await page.evaluate( ( ids ) => Object.fromEntries( ids.map( ( id ) => [ id, document.getElementById( id ).innerText.trim() ] ) ), COUNTING );
	for ( const id of COUNTING ) {
		assert( rendered[ id ] === orig.text[ id ].trim(), `${ id }: innerText "${ rendered[ id ] }" (expected "${ orig.text[ id ].trim() }")` );
	}

	// "24/7" never changed.
	assert( ( await markup( page ) )[ 'brxe-cnamb' ] === orig.markup[ 'brxe-cnamb' ], 'cnamb (24/7) changed' );

	// A bouncy curve becomes the smooth one: a counter never goes past its value.
	const bouncyItem = ( await items( page ) ).find( ( i ) => i.id === 'brxe-cnbnc' );
	assert( bouncyItem.options.easeKey === 'smooth', `bouncy counter: curve ${ bouncyItem.options.easeKey } (expected smooth)` );

	// Inner markup: the <strong> node itself was kept, the number counted inside it.
	assert( result.strongLost === 0, `cnmark: the <strong> element was replaced during ${ result.strongLost } frame(s)` );
	assert( await page.locator( '#brxe-cnmark strong > lm-number-flow' ).count() === 1, 'cnmark: the number is not counted inside <strong>' );

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
	await page.waitForTimeout( 300 );
	await sameAria( page, reference, 'after the late counter' );

	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** Destroyed while counting: every text back as written. */
async function destroyed() {
	const page = await open();
	const orig = await served( page );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 200 );
	await page.evaluate( () => window.lumiaMotion.destroy() );
	const end = await markup( page );
	IDS.forEach( ( id ) => assert( end[ id ] === orig.markup[ id ], `destroy(): ${ id } is "${ end[ id ] }" (expected "${ orig.markup[ id ] }")` ) );
	assert( page.errors.length === 0, `destroy(): page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** Reduced motion: values as written, no NumberFlow element, before and after scrolling. Returns the ARIA snapshots of the plain page, the reference. */
async function reduced() {
	const page = await open( { reducedMotion: 'reduce' } );
	const orig = await served( page );
	const check = async ( when ) => {
		const now = await markup( page );
		IDS.forEach( ( id ) => assert( now[ id ] === orig.markup[ id ], `reduced: ${ id } is "${ now[ id ] }" ${ when } (expected "${ orig.markup[ id ] }")` ) );
		assert( ! ( await page.locator( 'lm-number-flow' ).count() ), `reduced: a NumberFlow element ${ when }` );
	};
	await check( 'at load' );
	const pending = ( await items( page ) ).filter( ( i ) => i.state === 'pending' );
	assert( pending.length === 0, `reduced: pending items ${ pending.map( ( i ) => i.id ).join( ', ' ) }` );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 500 );
	await check( 'after scrolling' );
	const reference = await aria( page );
	assert( page.errors.length === 0, `reduced: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
	return reference;
}

/** NumberFlow blocked: the engine gives up after its wait and every counter stays as written. */
async function blocked() {
	const page = await open( {}, ( p ) => p.route( '**/motion-counter.js*', ( route ) => route.abort() ) );
	const orig = await served( page );
	await page.waitForTimeout( 3500 );
	await page.evaluate( () => document.getElementById( 'brxe-cnyear' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 2500 );
	const now = await markup( page );
	IDS.forEach( ( id ) => assert( now[ id ] === orig.markup[ id ], `blocked: ${ id } is "${ now[ id ] }" (expected "${ orig.markup[ id ] }")` ) );
	assert( ! ( await page.locator( 'lm-number-flow' ).count() ), 'blocked: a NumberFlow element on the page' );
	const pending = ( await items( page ) ).filter( ( i ) => COUNTING.includes( i.id ) && i.state === 'pending' );
	assert( pending.length === 0, `blocked: counters still pending: ${ pending.map( ( i ) => i.id ).join( ', ' ) }` );
	await page.context().close();
}

/** A page without counter requests none of the counter files; a counter in a footer popup gets them. */
async function loading() {
	const page = await newPage();
	const requested = [];
	page.on( 'request', ( r ) => requested.push( r.url() ) );
	await page.goto( pageUrl( 'lm-e2e-basic' ), { waitUntil: 'load' } );
	await page.waitForTimeout( 500 );
	const html = await page.content();
	assert( /lumia-motion-js/.test( html ), 'lm-e2e-basic: the engine is not on the page, the check proves nothing' );
	const flow = requested.filter( ( u ) => FLOW_FILES.test( u ) );
	assert( flow.length === 0, `lm-e2e-basic: counter files requested: ${ flow.join( ', ' ) }` );
	// Tags only: the inline motion styles carry the lm-number-flow rules.
	const tags = ( html.match( /<(script|link)\b[^>]*>/g ) || [] ).join( '\n' );
	assert( ! /number-flow|esm-env|motion-counter/.test( tags ), 'lm-e2e-basic: counter script in the HTML' );
	await page.context().close();

	// A counter page: no script module at all (nginx serves .mjs as application/octet-stream, which
	// browsers refuse for a module), the counter is a classic script the loader injects after the
	// first paint, so it is not printed as a tag either.
	const servedHtml = await ( await fetch( pageUrl( 'lm-e2e-counter' ) ) ).text();
	assert( ! /importmap|type=["']module["']|modulepreload/.test( servedHtml ), 'lm-e2e-counter: a script module, an import map or a preload in the served HTML' );
	assert( ! /<script[^>]*src=["'][^"']*motion-counter/.test( servedHtml ), 'lm-e2e-counter: the counter script is printed as a tag' );
	assert( /lumia-motion-counter-js/.test( servedHtml ), 'lm-e2e-counter: the loader does not list the counter script' );
	{
		const page = await open( { slug: 'lm-e2e-counter' } );
		const mjs = [];
		page.on( 'request', ( r ) => /\.mjs(\?|$)/.test( r.url() ) && mjs.push( r.url() ) );
		await page.waitForFunction( () => window.lumiaMotionCounter, null, { timeout: 4000 } );
		// Against the first paint, not the first contentful one: every element of this page is an
		// entrance, hidden until the engine plays it, so nothing contentful is painted before.
		const t = await page.evaluate( () => {
			const fp = ( performance.getEntriesByType( 'paint' ).find( ( e ) => e.name === 'first-paint' ) || {} ).startTime;
			return {
				fp,
				early: performance.getEntriesByType( 'resource' ).filter( ( e ) => /\/(number-flow|esm-env)\/|motion-counter\.m?js/.test( e.name ) && ! ( e.startTime > fp ) ).map( ( e ) => `${ e.name } ${ Math.round( e.startTime ) } ms` ),
			};
		} );
		assert( typeof t.fp === 'number' && t.early.length === 0, `lm-e2e-counter: counter files requested before the first paint (${ t.fp } ms): ${ t.early.join( ', ' ) }` );
		assert( mjs.length === 0, `lm-e2e-counter: .mjs files requested: ${ mjs.join( ', ' ) }` );
		await page.context().close();
	}

	const popup = await open( { slug: 'lm-e2e-counter-popup' } );
	await popup.waitForFunction( () => window.lumiaMotionCounter, null, { timeout: 4000 } ).catch( () => {
		throw new Error( 'counter in a popup: the counter adapter never started' );
	} );
	assert( popup.errors.length === 0, `counter in a popup: page errors: ${ popup.errors.join( ' | ' ) }` );
	await popup.context().close();
}

export default async function () {
	await loading();
	const reference = await reduced();
	await blocked();
	await animated( reference );
	await destroyed();
}
