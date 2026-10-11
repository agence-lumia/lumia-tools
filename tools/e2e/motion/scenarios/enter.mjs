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

	// Class preset (slide-up at 60%) adjusted per element.
	const list = await items( page );
	const pre = PRESETS.map( ( id ) => byId( list, id ) );
	pre.forEach( ( item, i ) => assert( item && item.effect === 'slide-up', `1440: ${ PRESETS[ i ] } has no slide-up item (${ JSON.stringify( item ) })` ) );
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

	// An element removed from the page loses its item and its trigger on refresh (still pending here).
	const removal = await page.evaluate( () => {
		const count = () => ScrollTrigger.getAll().length;
		const before = count();
		document.getElementById( 'brxe-bblur' ).remove();
		window.lumiaMotion.refresh();
		return {
			before,
			after: count(),
			detached: ScrollTrigger.getAll().filter( ( t ) => t.trigger && ! t.trigger.isConnected ).length,
			item: window.lumiaMotion._debug.items().some( ( i ) => i.id === 'brxe-bblur' ),
		};
	} );
	assert( ! removal.item, 'removed element: its item survived refresh()' );
	assert( removal.detached === 0, `removed element: ${ removal.detached } trigger(s) still watch a detached element` );
	assert( removal.after === removal.before - 1, `removed element: ${ removal.before } triggers before, ${ removal.after } after (expected one less)` );

	// Every frame of two overshooting animations: opacity never above 1, blur never below 0.
	await page.evaluate( () => {
		window.lmSamples = {};
		for ( const id of [ 'brxe-bprefb', 'brxe-bblurb' ] ) {
			const node = document.getElementById( id );
			const list = ( window.lmSamples[ id ] = [] );
			const tick = () => {
				list.push( { o: node.style.opacity, f: node.style.filter, t: node.style.transform } );
				if ( ! window.lmStopSampling ) {
					requestAnimationFrame( tick );
				}
			};
			requestAnimationFrame( tick );
		}
	} );
	await reveal( page, 'brxe-bprefb' );
	await reveal( page, 'brxe-bblurb' );
	const sampled = await page.evaluate( () => {
		window.lmStopSampling = true;
		return window.lmSamples;
	} );
	for ( const [ id, samples ] of Object.entries( sampled ) ) {
		const moving = samples.filter( ( s ) => s.o !== '' && parseFloat( s.o ) > 0 && parseFloat( s.o ) < 1 );
		assert( moving.length >= 3, `${ id }: ${ moving.length } frames in motion out of ${ samples.length } (expected an animation)` );
		const over = samples.filter( ( s ) => s.o !== '' && parseFloat( s.o ) > 1 );
		assert( over.length === 0, `${ id }: opacity above 1 (${ over.map( ( s ) => s.o ).join( ', ' ) })` );
		const negative = samples.filter( ( s ) => s.f.includes( 'blur(-' ) );
		assert( negative.length === 0, `${ id }: negative blur (${ negative.map( ( s ) => s.f ).join( ', ' ) })` );
		const end = await look( page, id );
		assert( end.opacity === '1', `${ id }: opacity ${ end.opacity } at the end` );
		assert( end.style === '', `${ id }: inline styles left at the end: "${ end.style }"` );
	}
	// The bouncy blur really moved through intermediate blur values, and its transform overshot.
	const blurring = sampled[ 'brxe-bblurb' ].filter( ( s ) => /blur\((?!0px)[\d.]+px\)/.test( s.f ) );
	assert( blurring.length >= 3, `brxe-bblurb: ${ blurring.length } frames with a blur in progress` );
	const overshoot = sampled[ 'brxe-bblurb' ].filter( ( s ) => /translate(?:3d)?\(0px, -[\d.]+px/.test( s.t ) );
	assert( overshoot.length > 0, 'brxe-bblurb: the bouncy transform never overshot (the blur check would prove nothing)' );

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
	assert( inval && inval.effect === 'blur-up', `invalid: no blur-up item (${ JSON.stringify( inval ) })` );
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

	// Names typed in the "CSS classes" field: an item each, still waiting below the screen, then played.
	for ( const [ id, effect ] of [ [ 'brawsk', 'fade' ], [ 'brawlm', 'slide-up' ] ] ) {
		const typed = byId( list, id );
		assert( typed && typed.effect === effect, `${ id } (typed in CSS classes): effect ${ typed && typed.effect } (expected ${ effect })` );
		assert( typed.state === 'pending', `${ id } (typed in CSS classes): state ${ typed.state } before scrolling to it (expected pending)` );
		await reveal( page, `brxe-${ id }` );
		const shown = byId( await items( page ), id );
		assert( shown.state === 'done', `${ id } (typed in CSS classes): state ${ shown.state } after scrolling (expected done)` );
		assert( ( await look( page, `brxe-${ id }` ) ).opacity === '1', `${ id } (typed in CSS classes): not visible after scrolling` );
	}

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

	// Reduced motion switched on during the visit: nothing left hidden or styled.
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await page.waitForTimeout( 300 );
	const switched = await page.evaluate( () => ( {
		pending: window.lumiaMotion._debug.items().filter( ( i ) => i.state === 'pending' ).length,
		bad: [ ...document.querySelectorAll( '[data-lm]' ) ]
			.filter( ( n ) => getComputedStyle( n ).opacity !== '1' || n.style.opacity + n.style.transform + n.style.filter !== '' )
			.map( ( n ) => n.id ),
	} ) );
	assert( switched.pending === 0 && switched.bad.length === 0, `reduced motion switched on: ${ switched.pending } pending, hidden or styled: ${ switched.bad.join( ', ' ) }` );

	assert( page.errors.length === 0, `1440: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function breakpointSwitch() {
	const page = await open();
	// The presets have played and are on screen.
	await reveal( page, 'brxe-bprefb' );
	await page.evaluate( () => {
		window.lmBefore = {};
		window.lumiaMotion._debug.items().forEach( ( i ) => {
			window.lmBefore[ i.id ] = { options: i.options, state: i.state };
		} );
		// Every frame of the switch: an element that had shown itself must not be hidden again.
		window.lmDropped = [];
		const shown = window.lumiaMotion._debug.items()
			.filter( ( i ) => i.state === 'done' || i.state === 'final' )
			.map( ( i ) => document.getElementById( i.id ) );
		const tick = () => {
			shown.forEach( ( node ) => {
				const r = node.getBoundingClientRect();
				const opacity = parseFloat( getComputedStyle( node ).opacity );
				if ( r.bottom > 0 && r.top < window.innerHeight && opacity < 0.5 ) {
					window.lmDropped.push( `${ node.id } ${ opacity }` );
				}
			} );
			if ( ! window.lmStopSampling ) {
				requestAnimationFrame( tick );
			}
		};
		requestAnimationFrame( tick );
	} );

	await page.setViewportSize( { width: 390, height: 844 } );
	await page.waitForFunction(
		() => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === 'brxe-bpremb' ) || { options: {} } ).options.start === 'top 80%',
		null,
		{ timeout: 3000 }
	).catch( () => {
		throw new Error( 'breakpoint switch: bpremb was not rebuilt with its phone start (top 80%)' );
	} );
	await page.waitForTimeout( 500 );
	const after = await page.evaluate( () => {
		window.lmStopSampling = true;
		const rebuilt = [];
		const kept = [];
		window.lumiaMotion._debug.items().forEach( ( i ) => {
			const before = window.lmBefore[ i.id ];
			( before && before.options === i.options ? kept : rebuilt ).push( i.id );
		} );
		const gone = Object.keys( window.lmBefore ).filter( ( id ) => ! window.lumiaMotion._debug.items().some( ( i ) => i.id === id ) );
		return { rebuilt, kept, gone, dropped: window.lmDropped };
	} );
	assert( after.rebuilt.join() === 'brxe-bpremb', `breakpoint switch: rebuilt ${ after.rebuilt.join( ', ' ) || 'nothing' } (expected brxe-bpremb only)` );
	assert( after.gone.join() === 'brxe-bblur', `breakpoint switch: removed ${ after.gone.join( ', ' ) || 'nothing' } (expected brxe-bblur, set to none on phones)` );
	assert( after.dropped.length === 0, `breakpoint switch: shown elements hidden again: ${ after.dropped.slice( 0, 5 ).join( ', ' ) }` );
	const bpremb = byId( await items( page ), 'bpremb' );
	assert( bpremb.state === 'final', `breakpoint switch: bpremb had played, its rebuilt item is ${ bpremb.state }` );
	assert( page.errors.length === 0, `breakpoint switch: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** A page too short for its last elements to reach their start (30%, and the preset's 60%). */
async function shortPage() {
	for ( const height of [ 900, 1400 ] ) {
		const page = await newPage( { width: 1440, height } );
		const res = await page.goto( pageUrl( 'lm-e2e-short' ), { waitUntil: 'load' } );
		assert( res && res.status() === 200, `lm-e2e-short: status ${ res && res.status() }` );
		await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug, null, { timeout: 5000 } );
		const geometry = await page.evaluate( () => {
			const max = document.documentElement.scrollHeight - window.innerHeight;
			const reach = ( id, at ) => document.getElementById( id ).getBoundingClientRect().top + window.scrollY - window.innerHeight * at;
			return { max, starts: [ reach( 'brxe-shrt30', 0.3 ), reach( 'brxe-shrtpr', 0.6 ) ] };
		} );
		const label = `short page, ${ height } px high (${ geometry.max > 0 ? `scrollable to ${ geometry.max }` : 'not scrollable' })`;
		assert( height === 1400 ? geometry.max <= 0 : geometry.max > 0, `${ label }: unexpected page height` );
		assert( geometry.starts.every( ( start ) => start > Math.max( geometry.max, 0 ) ), `${ label }: the starts ${ geometry.starts.join( ', ' ) } are reachable, the check would prove nothing` );

		const built = ( await items( page ) ).filter( ( i ) => i.id === 'brxe-shrt30' || i.id === 'brxe-shrtpr' );
		assert( built.length === 2, `${ label }: ${ built.length } item(s) for the last two elements (expected 2)` );
		assert(
			built.map( ( i ) => i.options.start ).join() === 'top 30%,top 60%',
			`${ label }: starts ${ built.map( ( i ) => i.options.start ).join( ', ' ) } (expected top 30%, top 60%)`
		);

		// Scrollable page: nothing plays before the visitor reaches the end, even once loading is over.
		if ( geometry.max > 0 ) {
			await page.waitForFunction( () => document.readyState === 'complete' );
			await page.waitForTimeout( 500 );
			const early = ( await items( page ) ).filter( ( i ) => i.id.startsWith( 'brxe-shrt' ) && i.state !== 'pending' );
			assert( early.length === 0, `${ label }: played before the end of the page was reached: ${ early.map( ( i ) => `${ i.id }:${ i.state }` ).join( ', ' ) }` );
		}

		await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
		const shown = await page.waitForFunction(
			() => [ 'brxe-shrt30', 'brxe-shrtpr' ].every( ( id ) => getComputedStyle( document.getElementById( id ) ).opacity === '1' ),
			null,
			{ timeout: 4000 }
		).then( () => true, () => false );
		const states = ( await items( page ) ).filter( ( i ) => i.id.startsWith( 'brxe-shrt' ) ).map( ( i ) => `${ i.id }:${ i.state }` );
		assert( shown, `${ label }: the last elements are still hidden at the bottom of the page (${ states.join( ', ' ) })` );
		assert( page.errors.length === 0, `${ label }: page errors: ${ page.errors.join( ' | ' ) }` );
		await page.context().close();
	}
}

/**
 * A phone hiding its address bar: the screen gets taller, the page's maximum scroll shorter,
 * and ScrollTrigger (ignoreMobileResize) does not refresh. A start between the two maximums
 * looked reachable at the last refresh and no longer is.
 */
async function addressBar() {
	const GROW = 100;
	const page = await newPage( { width: 390, height: 700, mobile: true } );
	await page.goto( pageUrl( 'lm-e2e-short' ), { waitUntil: 'load' } );
	await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug && document.readyState === 'complete', null, { timeout: 5000 } );

	const before = await page.evaluate( ( grow ) => {
		const max = document.scrollingElement.scrollHeight - window.innerHeight;
		const node = document.getElementById( 'brxe-shrtpr' );
		const top = node.getBoundingClientRect().top + window.scrollY;
		// Start half-way between the maximum scroll now and the one once the screen is taller.
		node.style.setProperty( '--lm-start', `top ${ Math.round( top - ( max - grow / 2 ) ) }px` );
		window.lumiaMotion.refresh();
		const start = ( ScrollTrigger.getAll().find( ( t ) => t.trigger === node ) || {} ).start;
		window.lmRefreshes = 0;
		ScrollTrigger.addEventListener( 'refresh', () => window.lmRefreshes++ );
		return { max, start, touch: !! ScrollTrigger.isTouch };
	}, GROW );
	assert( before.touch, 'address bar: ScrollTrigger does not see a touch device, the case cannot be reproduced' );
	assert( before.start < before.max && before.start > before.max - GROW, `address bar: start ${ before.start } is not between ${ before.max - GROW } and ${ before.max }` );

	await page.setViewportSize( { width: 390, height: 700 + GROW } );
	await page.waitForTimeout( 600 );
	const grown = await page.evaluate( () => ( { refreshes: window.lmRefreshes, max: document.scrollingElement.scrollHeight - window.innerHeight } ) );
	assert( grown.refreshes === 0, `address bar: the taller screen caused ${ grown.refreshes } refresh(es), the case is not reproduced` );
	assert( before.start > grown.max, `address bar: start ${ before.start } is still reachable (max ${ grown.max })` );

	await page.evaluate( () => window.scrollTo( 0, document.scrollingElement.scrollHeight ) );
	const shown = await page.waitForFunction(
		() => [ 'brxe-shrt30', 'brxe-shrtpr' ].every( ( id ) => getComputedStyle( document.getElementById( id ) ).opacity === '1' ),
		null,
		{ timeout: 4000 }
	).then( () => true, () => false );
	const states = ( await items( page ) ).filter( ( i ) => i.id.startsWith( 'brxe-shrt' ) ).map( ( i ) => `${ i.id }:${ i.state }` );
	assert( shown, `address bar: hidden at the bottom of the page once the screen got taller (${ states.join( ', ' ) }, max ${ before.max } -> ${ grown.max })` );
	assert( page.errors.length === 0, `address bar: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

async function debugLog() {
	const page = await open( {}, '?lmDebug' );
	const said = page.logs.filter( ( l ) => l.includes( 'lm-fade' ) && l.includes( 'lm-slide-up' ) );
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
		assert( ! byId( list, 'brawsk' ), 'former name typed in CSS classes, compatibility off: the element still has an item' );
		assert( byId( list, 'brawlm' ), 'name typed in CSS classes, compatibility off: no item' );
		await page.context().close();
	} );
}

export default async function () {
	await shortPage();
	await addressBar();
	await desktop();
	await breakpointSwitch();
	await debugLog();
	await phone();
	await reduced();
	await legacyOff();
}
