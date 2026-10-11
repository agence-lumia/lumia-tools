// Entrance effects: per-element settings over a class preset, breakpoints, an animated
// section around a plain heading, two effect classes, invalid values, the former class
// names, content inserted after load, removed elements, reduced motion, and nothing left
// hidden on screen.
import { newPage, pageUrl, assert, near, items, withMotionSettings } from '../lib.mjs';

const SLUG = 'lm-e2e-basic';
const PRESETS = [ 'bpre01', 'bpre30', 'bpremb', 'bprefb' ];

/** Opens lm-e2e-basic and waits for the engine. Console messages are kept in page.logs. */
async function open( o = {}, query = '' ) {
	const page = await newPage( o );
	page.logs = [];
	page.on( 'console', ( m ) => page.logs.push( m.text() ) );
	const res = await page.goto( pageUrl( SLUG, query ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ SLUG }: status ${ res && res.status() }` );
	await page.waitForFunction(
		() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
		null,
		{ timeout: 5000 }
	).catch( () => {
		throw new Error( `${ SLUG }: the engine did not start (no lm-ready or no lumiaMotion._debug after 5 s)` );
	} );
	return page;
}

const byId = ( list, id ) => list.find( ( i ) => i.id === `brxe-${ id }` );

/** Scrolls until the element's top is at 25% of the screen, then waits until its item has played. */
async function reveal( page, id ) {
	await page.evaluate( ( id ) => {
		const node = document.getElementById( id );
		window.scrollTo( 0, node.getBoundingClientRect().top + window.scrollY - window.innerHeight * 0.25 );
	}, id );
	await page.waitForFunction(
		( id ) => {
			const item = window.lumiaMotion._debug.items().find( ( i ) => i.id === id );
			return ! item || item.state === 'done' || item.state === 'final';
		},
		id,
		{ timeout: 5000 }
	).catch( () => {
		throw new Error( `${ id }: its item never finished after scrolling to it` );
	} );
}

/** Computed and inline styles of an element. */
const look = ( page, id ) => page.evaluate( ( id ) => {
	const node = document.getElementById( id );
	const cs = getComputedStyle( node );
	return {
		opacity: cs.opacity,
		effect: cs.getPropertyValue( '--lm-effect' ).trim(),
		inline: { opacity: node.style.opacity, transform: node.style.transform, filter: node.style.filter, willChange: node.style.willChange },
		style: node.getAttribute( 'style' ) || '',
	};
}, id );

/** Marked elements on screen whose computed opacity is under 0.5. */
const hiddenOnScreen = ( page ) => page.evaluate( () => [ ...document.querySelectorAll( '[data-lm]' ) ]
	.filter( ( n ) => {
		const r = n.getBoundingClientRect();
		return r.bottom > 0 && r.top < window.innerHeight && r.width > 0 && parseFloat( getComputedStyle( n ).opacity ) < 0.5;
	} )
	.map( ( n ) => n.id || n.className ) );

async function desktop() {
	const page = await open();

	// Nothing on screen is still hidden 2 s after load.
	await page.waitForTimeout( 2000 );
	const hidden = await hiddenOnScreen( page );
	assert( hidden.length === 0, `1440: hidden on screen 2 s after load: ${ hidden.join( ', ' ) }` );

	// Class preset (reveal at 60%) adjusted per element.
	const list = await items( page );
	const pre = PRESETS.map( ( id ) => byId( list, id ) );
	pre.forEach( ( item, i ) => assert( item && item.effect === 'reveal', `1440: ${ PRESETS[ i ] } has no reveal item (${ JSON.stringify( item ) })` ) );
	assert( pre[ 1 ].options.start === 'top 30%', `1440: bpre30 start=${ pre[ 1 ].options.start }` );
	for ( const i of [ 0, 2, 3 ] ) {
		assert( pre[ i ].options.start === 'top 60%', `1440: ${ PRESETS[ i ] } start=${ pre[ i ].options.start }` );
	}
	assert( pre[ 0 ].signature === pre[ 2 ].signature, '1440: two presets with the same settings have different signatures' );

	const fb = pre[ 3 ];
	assert( near( fb.options.duration, 0.455 ), `fast/bouncy: duration=${ fb.options.duration } (expected 0.455)` );
	assert( fb.options.easeKey === 'bouncy', `fast/bouncy: easeKey=${ fb.options.easeKey }` );
	for ( const i of [ 0, 1, 2 ] ) {
		assert( fb.signature !== pre[ i ].signature, `fast/bouncy: same signature as ${ PRESETS[ i ] }` );
	}
	assert( fb.state === 'pending', `fast/bouncy: state ${ fb.state } before scrolling to it` );

	// Every frame of its animation: opacity never above 1, never a negative blur.
	await page.evaluate( () => {
		const node = document.getElementById( 'brxe-bprefb' );
		window.lmSamples = [];
		const tick = () => {
			window.lmSamples.push( { o: node.style.opacity, f: node.style.filter, t: node.style.transform } );
			if ( ! window.lmStopSampling ) {
				requestAnimationFrame( tick );
			}
		};
		requestAnimationFrame( tick );
	} );
	await reveal( page, 'brxe-bprefb' );
	const samples = await page.evaluate( () => {
		window.lmStopSampling = true;
		return window.lmSamples;
	} );
	const moving = samples.filter( ( s ) => s.o !== '' && parseFloat( s.o ) > 0 && parseFloat( s.o ) < 1 );
	assert( moving.length >= 3, `fast/bouncy: ${ moving.length } frames in motion out of ${ samples.length } (expected an animation)` );
	const over = samples.filter( ( s ) => s.o !== '' && parseFloat( s.o ) > 1 );
	assert( over.length === 0, `fast/bouncy: opacity above 1 (${ over.map( ( s ) => s.o ).join( ', ' ) })` );
	const negative = samples.filter( ( s ) => s.f.includes( 'blur(-' ) );
	assert( negative.length === 0, `fast/bouncy: negative blur (${ negative.map( ( s ) => s.f ).join( ', ' ) })` );
	const fbEnd = await look( page, 'brxe-bprefb' );
	assert( fbEnd.opacity === '1', `fast/bouncy: opacity ${ fbEnd.opacity } at the end` );
	assert( fbEnd.style === '', `fast/bouncy: inline styles left at the end: "${ fbEnd.style }"` );

	// Nested: the section animates, its plain heading has no item and inherits nothing.
	const nest = byId( list, 'bnest' );
	assert( nest && nest.effect === 'fade', `nested: the section has no fade item (${ JSON.stringify( nest ) })` );
	assert( ! byId( list, 'bnesth' ), 'nested: the plain heading has an item' );
	const nested = await look( page, 'brxe-bnesth' );
	assert( nested.effect === '', `nested: --lm-effect of the plain heading is "${ nested.effect }"` );
	await reveal( page, 'brxe-bnest' );
	const nestEnd = [ await look( page, 'brxe-bnest' ), await look( page, 'brxe-bnesth' ) ];
	assert( nestEnd.every( ( l ) => l.opacity === '1' ), `nested: opacity ${ nestEnd.map( ( l ) => l.opacity ).join( ' / ' ) } after scrolling` );

	// Two effect classes: the first one in the class attribute wins.
	const dual = byId( list, 'bdual' );
	assert( dual && dual.effect === 'fade', `double class: effect ${ dual && dual.effect } (expected fade)` );

	// Invalid values: reference values, no error, visible.
	const inval = byId( list, 'binval' );
	assert( inval && inval.effect === 'blur-in', `invalid: no blur-in item (${ JSON.stringify( inval ) })` );
	assert( near( inval.options.duration, 0.8 ), `invalid: duration=${ inval.options.duration } (expected 0.8)` );
	assert( inval.options.start === 'top 98%', `invalid: start=${ inval.options.start } (expected top 98%)` );
	assert( inval.options.easeKey === 'lumia', `invalid: easeKey=${ inval.options.easeKey } (expected lumia)` );
	await reveal( page, 'brxe-binval' );
	assert( ( await look( page, 'brxe-binval' ) ).opacity === '1', 'invalid: not visible after scrolling' );

	// Former class name, while the compatibility setting is on.
	const sk = byId( list, 'bskfd' );
	assert( sk && sk.effect === 'fade', `former class: effect ${ sk && sk.effect } (expected fade)` );
	await reveal( page, 'brxe-bskfd' );
	assert( ( await look( page, 'brxe-bskfd' ) ).opacity === '1', 'former class: not visible after scrolling' );

	// Content inserted by a Bricks AJAX query: scanned, animated, visible.
	await page.evaluate( () => {
		const node = document.createElement( 'div' );
		node.id = 'lm-ajax-new';
		node.setAttribute( 'data-lm', 'enter' );
		node.style.cssText = '--lm-effect: fade; height: 120px';
		node.textContent = 'Inserted after load';
		( document.getElementById( 'brx-content' ) || document.body ).appendChild( node );
		document.dispatchEvent( new CustomEvent( 'bricks/ajax/query_result/displayed' ) );
	} );
	await page.waitForFunction( () => window.lumiaMotion._debug.items().some( ( i ) => i.id === 'lm-ajax-new' ), null, { timeout: 2000 } )
		.catch( () => {
			throw new Error( 'ajax: no item for the inserted element' );
		} );
	await reveal( page, 'lm-ajax-new' );
	assert( ( await look( page, 'lm-ajax-new' ) ).opacity === '1', 'ajax: the inserted element is not visible after scrolling' );

	// An element removed from the page loses its item on refresh.
	await page.evaluate( () => {
		document.getElementById( 'brxe-bblur' ).remove();
		window.lumiaMotion.refresh();
	} );
	assert( ! byId( await items( page ), 'bblur' ), 'removed element: its item survived refresh()' );

	assert( page.errors.length === 0, `1440: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function debugLog() {
	const page = await open( {}, '?lmDebug' );
	const said = page.logs.filter( ( l ) => l.includes( 'lm-fade' ) && l.includes( 'lm-reveal' ) );
	assert( said.length > 0, `?lmDebug: no console message about the two effect classes (${ page.logs.join( ' | ' ) || 'no message' })` );
	assert( page.errors.length === 0, `?lmDebug: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function phone() {
	const page = await open( { width: 390, height: 844 } );
	await page.waitForTimeout( 2000 );
	const hidden = await hiddenOnScreen( page );
	assert( hidden.length === 0, `390: hidden on screen 2 s after load: ${ hidden.join( ', ' ) }` );

	const list = await items( page );
	const start = ( id ) => ( byId( list, id ) || { options: {} } ).options.start;
	assert( start( 'bpremb' ) === 'top 80%', `390: bpremb start=${ start( 'bpremb' ) } (expected top 80%)` );
	assert( start( 'bpre01' ) === 'top 60%' && start( 'bprefb' ) === 'top 60%', `390: presets start=${ start( 'bpre01' ) } / ${ start( 'bprefb' ) }` );
	assert( start( 'bpre30' ) === 'top 30%', `390: bpre30 start=${ start( 'bpre30' ) }` );

	// Effect switched off on phones: no item, visible, nothing inline.
	assert( ! byId( list, 'bblur' ), '390: the element set to none on phones has an item' );
	await page.evaluate( () => document.getElementById( 'brxe-bblur' ).scrollIntoView( { block: 'center' } ) );
	await page.waitForTimeout( 300 );
	const off = await look( page, 'brxe-bblur' );
	assert( off.opacity === '1' && off.inline.opacity === '' && off.inline.transform === '', `390: none element opacity=${ off.opacity } inline=${ JSON.stringify( off.inline ) }` );

	assert( page.errors.length === 0, `390: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function reduced() {
	const page = await open( { reducedMotion: 'reduce' } );
	await page.waitForTimeout( 300 );
	const list = await items( page );
	const pending = list.filter( ( i ) => i.state === 'pending' );
	assert( pending.length === 0, `reduced: pending items ${ pending.map( ( i ) => i.id ).join( ', ' ) }` );
	const marked = await page.evaluate( () => [ ...document.querySelectorAll( '[data-lm]' ) ].map( ( n ) => ( {
		id: n.id,
		opacity: getComputedStyle( n ).opacity,
		inline: n.style.opacity + n.style.transform + n.style.filter,
	} ) ) );
	const moved = marked.filter( ( m ) => m.inline !== '' || m.opacity !== '1' );
	assert( moved.length === 0, `reduced: styled or hidden elements ${ JSON.stringify( moved ) }` );
	assert( page.errors.length === 0, `reduced: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function legacyOff() {
	await withMotionSettings( { legacy_classes: false }, async () => {
		const page = await open();
		const list = await items( page );
		assert( ! byId( list, 'bskfd' ), 'former class, compatibility off: the element still has an item' );
		const mark = await page.evaluate( () => document.getElementById( 'brxe-bskfd' ).getAttribute( 'data-lm' ) );
		assert( mark === null, `former class, compatibility off: data-lm=${ mark }` );
		assert( ( await look( page, 'brxe-bskfd' ) ).opacity === '1', 'former class, compatibility off: hidden' );
		await page.context().close();
	} );
}

export default async function () {
	await desktop();
	await debugLog();
	await phone();
	await reduced();
	await legacyOff();
}
