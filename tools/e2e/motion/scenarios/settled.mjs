// Settled text and counter effects: in the first section, a lines heading, a words scrub and a
// counter get data-lm-settled and stay exactly as written from the first paint (never split,
// never hidden, the counter never shows another value), even once the engine (loaded for the
// entrance below the fold) has started; set to "On load: never", the same effects still animate.
import { newPage, pageUrl, assert, items } from '../lib.mjs';

const SLUG = 'lm-e2e-settled';
const SETTLED = { 'brxe-sthead': 'text', 'brxe-stword': 'text', 'brxe-stcntr': 'counter' };
const NEVER = [ 'brxe-stnvln', 'brxe-stnvct' ];

/**
 * Init script: on every frame from the start of the document, for each watched element, whether it
 * was split or mounted, its text, its lowest opacity (pieces included) and whether it was painted.
 * window.lmSettled[id] = { frames, split, texts: [distinct texts], min }.
 */
function sample( ids ) {
	window.lmSettled = {};
	const tick = () => {
		ids.forEach( ( id ) => {
			const node = document.getElementById( id );
			if ( ! node ) {
				return;
			}
			const s = window.lmSettled[ id ] || ( window.lmSettled[ id ] = { frames: 0, split: false, texts: [], min: 1 } );
			s.frames++;
			const pieces = node.querySelectorAll( '.lm-line, .lm-word, .lm-char, lm-number-flow' );
			if ( pieces.length ) {
				s.split = true;
			}
			const text = node.textContent.replace( /\s+/g, ' ' ).trim();
			if ( ! s.texts.includes( text ) ) {
				s.texts.push( text );
			}
			[ node, ...pieces ].forEach( ( n ) => {
				s.min = Math.min( s.min, parseFloat( getComputedStyle( n ).opacity ) );
			} );
		} );
		requestAnimationFrame( tick );
	};
	requestAnimationFrame( tick );
}

export default async function () {
	const page = await newPage();
	const watched = [ ...Object.keys( SETTLED ), ...NEVER ];
	await page.addInitScript( sample, watched );
	const res = await page.goto( pageUrl( SLUG ), { waitUntil: 'commit' } );
	assert( res && res.status() === 200, `status ${ res && res.status() }` );
	await page.waitForFunction( () => window.lumiaMotion && window.lumiaMotion._debug && document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 6000 } ).catch( () => {
		throw new Error( 'the engine did not start (the entrance below the fold needs it)' );
	} );
	// The engine has started: one more second of frames, its first build included.
	await page.waitForTimeout( 1000 );

	const served = await page.evaluate( async ( ids ) => {
		const doc = new DOMParser().parseFromString( await ( await fetch( location.href ) ).text(), 'text/html' );
		return Object.fromEntries( ids.map( ( id ) => [ id, { settled: doc.getElementById( id ).getAttribute( 'data-lm-settled' ), text: doc.getElementById( id ).textContent.replace( /\s+/g, ' ' ).trim() } ] ) );
	}, watched );
	const seen = await page.evaluate( () => window.lmSettled );

	for ( const [ id, family ] of Object.entries( SETTLED ) ) {
		const s = seen[ id ];
		assert( served[ id ].settled === family, `${ id }: data-lm-settled=${ served[ id ].settled } (expected ${ family })` );
		assert( s && s.frames > 30, `${ id }: ${ s ? s.frames : 0 } frames sampled (nothing proved)` );
		assert( ! s.split, `${ id }: split or mounted by the engine` );
		assert( s.texts.length === 1 && s.texts[ 0 ] === served[ id ].text, `${ id }: showed ${ JSON.stringify( s.texts ) } instead of ${ JSON.stringify( served[ id ].text ) }` );
		assert( s.min === 1, `${ id }: opacity went down to ${ s.min }` );
	}

	// "Never": the engine path, as before.
	for ( const id of NEVER ) {
		assert( served[ id ].settled === null, `${ id }: data-lm-settled=${ served[ id ].settled }` );
		assert( seen[ id ] && seen[ id ].split, `${ id }: never split or mounted (the engine did not animate it)` );
	}
	const built = await items( page );
	for ( const id of Object.keys( SETTLED ) ) {
		assert( ! built.some( ( i ) => i.id === id ), `${ id }: built by the engine` );
	}
	for ( const id of [ ...NEVER, 'brxe-stlate' ] ) {
		assert( built.some( ( i ) => i.id === id ), `${ id }: not built by the engine (${ built.map( ( i ) => i.id ).join( ', ' ) })` );
	}
	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}
