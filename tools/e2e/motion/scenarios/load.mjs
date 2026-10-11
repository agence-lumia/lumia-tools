// Load animations: the entrance effects of the first section play in CSS from the first frame
// (data-lm-load), never below an opacity of 0.01 (an LCP candidate must be painted), and land on
// their final state; "never" keeps the engine path (pre-hidden, then animated by the engine);
// "always" plays on load anywhere; auto elements below the first section stay with the engine;
// reduced motion plays nothing.
import { newPage, pageUrl, assert, items } from '../lib.mjs';

const SLUG = 'lm-e2e-load';
const HERO = [ 'brxe-ldhead', 'brxe-ldtext', 'brxe-ldtxt2' ];
const WATCHED = [ ...HERO, 'brxe-ldnevr' ];

/**
 * Init script: on every frame from the start of the document, the opacity and the animations of
 * the watched elements. window.lmLoad[id] = { frames, first: { name, opacity }, min }.
 */
function sample( ids ) {
	window.lmLoad = {};
	const tick = () => {
		ids.forEach( ( id ) => {
			const node = document.getElementById( id );
			if ( ! node ) {
				return;
			}
			const cs = getComputedStyle( node );
			const opacity = parseFloat( cs.opacity );
			const seen = window.lmLoad[ id ] || ( window.lmLoad[ id ] = { frames: 0, first: { name: cs.animationName, opacity }, min: opacity } );
			seen.frames++;
			seen.min = Math.min( seen.min, opacity );
		} );
		requestAnimationFrame( tick );
	};
	requestAnimationFrame( tick );
}

const state = ( page, ids ) => page.evaluate( ( list ) => list.map( ( id ) => {
	const node = document.getElementById( id );
	const cs = getComputedStyle( node );
	return { id, load: node.getAttribute( 'data-lm-load' ), opacity: parseFloat( cs.opacity ), transform: cs.transform, filter: cs.filter, name: cs.animationName, style: node.getAttribute( 'style' ) || '' };
} ), ids );

export default async function () {
	{
		const page = await newPage();
		await page.addInitScript( sample, WATCHED );
		await page.goto( pageUrl( SLUG ), { waitUntil: 'commit' } );
		await page.waitForLoadState( 'load' );
		await page.waitForTimeout( 2500 );

		const seen = await page.evaluate( () => window.lmLoad );
		for ( const id of HERO ) {
			const s = seen[ id ];
			assert( s && s.frames > 0, `${ id }: never sampled` );
			assert( /lm-load-/.test( s.first.name ), `${ id }: no load animation on the first sampled frame (${ s.first.name })` );
			assert( s.min >= 0.0099, `${ id }: opacity went down to ${ s.min } (an LCP candidate must stay painted)` );
		}
		// The engine path: pre-hidden until the engine sets its start, then animated.
		assert( seen[ 'brxe-ldnevr' ] && seen[ 'brxe-ldnevr' ].min < 0.01, `never: not pre-hidden (min ${ seen[ 'brxe-ldnevr' ] && seen[ 'brxe-ldnevr' ].min })` );

		const end = await state( page, [ ...WATCHED, 'brxe-ldalws', 'brxe-ldlate' ] );
		const by = Object.fromEntries( end.map( ( e ) => [ e.id, e ] ) );
		assert( by[ 'brxe-ldhead' ].load === 'blur-up', `hero heading: data-lm-load=${ by[ 'brxe-ldhead' ].load }` );
		assert( by[ 'brxe-ldtext' ].load === 'fade' && by[ 'brxe-ldtxt2' ].load === 'fade', 'hero texts: no data-lm-load' );
		// Two of the same lot: the second starts one stagger (fade: 0.06 s) later.
		assert( by[ 'brxe-ldtext' ].style.includes( '--lm-load-delay:0s;' ), `first text: ${ by[ 'brxe-ldtext' ].style }` );
		assert( by[ 'brxe-ldtxt2' ].style.includes( '--lm-load-delay:0.06s;' ), `second text: ${ by[ 'brxe-ldtxt2' ].style }` );
		for ( const id of HERO ) {
			assert( by[ id ].opacity === 1, `${ id }: ends at opacity ${ by[ id ].opacity }` );
			assert( by[ id ].transform === 'none', `${ id }: ends with transform ${ by[ id ].transform }` );
			assert( by[ id ].filter === 'none', `${ id }: ends with filter ${ by[ id ].filter }` );
		}
		assert( by[ 'brxe-ldnevr' ].load === null, 'never: has data-lm-load' );
		assert( by[ 'brxe-ldnevr' ].opacity === 1, `never: ends at opacity ${ by[ 'brxe-ldnevr' ].opacity }` );
		assert( by[ 'brxe-ldalws' ].load === 'slide-up', `always: data-lm-load=${ by[ 'brxe-ldalws' ].load }` );
		assert( by[ 'brxe-ldlate' ].load === null, 'second section, auto: has data-lm-load' );

		// The engine builds the "never" and the second-section auto elements, and none of the load ones.
		const built = ( await items( page ) ).map( ( i ) => i.id );
		assert( built.includes( 'brxe-ldnevr' ), `never: not built by the engine (${ built.join( ', ' ) })` );
		assert( built.includes( 'brxe-ldlate' ), `second section: not built by the engine (${ built.join( ', ' ) })` );
		for ( const id of [ ...HERO, 'brxe-ldalws' ] ) {
			assert( ! built.includes( id ), `${ id }: built by the engine` );
		}
		const never = ( await items( page ) ).find( ( i ) => i.id === 'brxe-ldnevr' );
		assert( never.state === 'done', `never: engine state ${ never.state }` );
		assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
		await page.context().close();
	}

	// Reduced motion: the attribute is there (the page is the same HTML), nothing moves.
	{
		const page = await newPage( { reducedMotion: 'reduce' } );
		await page.addInitScript( sample, WATCHED );
		await page.goto( pageUrl( SLUG ), { waitUntil: 'commit' } );
		await page.waitForLoadState( 'load' );
		await page.waitForTimeout( 300 );
		const seen = await page.evaluate( () => window.lmLoad );
		for ( const id of HERO ) {
			assert( seen[ id ].first.name === 'none', `reduced motion, ${ id }: animation ${ seen[ id ].first.name }` );
			assert( seen[ id ].min === 1, `reduced motion, ${ id }: opacity went down to ${ seen[ id ].min }` );
		}
		await page.context().close();
	}
}
