// Smooth scroll (Lenis) on every public page when it is on: its instance, the share of the
// clock with GSAP, the wheel, same-page and cross-page anchors under the header, the reload,
// the popups (stop and start, a real Bricks popup, static and fetched), and nothing at all
// when the setting is off. The lab sets --navbar-height to 61 px (its custom CSS uses
// !important): the scenario overrides it with a clamp() in rem, and another value under
// 1000 px, to exercise what the offset has to read.
import { foreignRequests, newPage, pageUrl, assert, near, items, injectCss, withMotionSettings } from '../lib.mjs';

const NAVBAR_CSS = ':root{--navbar-height:clamp(60px,4rem,90px) !important}@media (max-width:1000px){:root{--navbar-height:90px !important}}';

const lenisReady = ( page ) => page.waitForFunction(
	() => document.documentElement.classList.contains( 'lm-ready' ) && !! window.lumiaLenis,
	null,
	{ timeout: 6000 }
).catch( () => {
	throw new Error( `${ page.url() }: Lenis did not start (no window.lumiaLenis or no lm-ready after 6 s)` );
} );

const engineReady = ( page ) => page.waitForFunction(
	() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
	null,
	{ timeout: 6000 }
).catch( () => {
	throw new Error( `${ page.url() }: the engine did not start (no lm-ready or no lumiaMotion._debug after 6 s)` );
} );

/** Resolves with scrollY once it has not moved for 12 frames (the lerp has ended), 6 s at most. */
const stable = ( page ) => page.evaluate( () => new Promise( ( resolve ) => {
	const t0 = performance.now();
	let last = NaN;
	let same = 0;
	const tick = () => {
		const y = window.scrollY;
		same = y === last ? same + 1 : 0;
		last = y;
		if ( same >= 12 || performance.now() - t0 > 6000 ) {
			resolve( y );
		} else {
			requestAnimationFrame( tick );
		}
	};
	requestAnimationFrame( tick );
} ) );

/**
 * Init script: counts the calls of lumiaLenis.scrollTo (window.lmCalls) from the moment the module creates the
 * instance (its scripts run after the first paint, so no earlier hook can see it), and when `global` is given,
 * defines that global right then, as a former snippet showing up after the module. window.lmLoadedFirst: whether
 * the page had already loaded when the instance appeared.
 */
function watchLenis( global ) {
	let value;
	window.lmCalls = 0;
	window.lmLoadedFirst = null;
	Object.defineProperty( window, 'lumiaLenis', {
		configurable: true,
		get: () => value,
		set: ( instance ) => {
			value = instance;
			if ( ! instance || null !== window.lmLoadedFirst ) {
				return;
			}
			window.lmLoadedFirst = document.readyState === 'complete';
			if ( global ) {
				window[ global ] = {};
			}
			const original = instance.scrollTo.bind( instance );
			instance.scrollTo = ( ...args ) => {
				window.lmCalls++;
				return original( ...args );
			};
		},
	} );
}

/** Requests made while a page loads, and the response. */
async function load( page, slug ) {
	const urls = [];
	page.on( 'request', ( r ) => urls.push( r.url() ) );
	const res = await page.goto( pageUrl( slug ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ slug }: status ${ res && res.status() }` );
	return urls;
}

/** Distance of #target from the top of the screen, and the measured --navbar-height. */
const targetAt = ( page ) => page.evaluate( () => {
	const probe = document.createElement( 'div' );
	probe.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:var(--navbar-height, 0px)';
	document.body.appendChild( probe );
	const navbar = probe.getBoundingClientRect().height;
	probe.remove();
	return { navbar, top: document.getElementById( 'target' ).getBoundingClientRect().top, y: window.scrollY };
} );

const noErrors = ( page, label ) => assert( page.errors.length === 0, `${ label }: page errors: ${ page.errors.join( ' | ' ) }` );

/** Fires a click on an element the site header may cover. */
const click = ( page, selector ) => page.locator( selector ).evaluate( ( a ) => a.click() );

// A page with no animation: Lenis alone, on its own clock, no GSAP.
async function plainPage() {
	const page = await newPage();
	const urls = await load( page, 'lm-e2e-none' );
	await lenisReady( page );
	for ( const file of [ 'lenis.min.js', 'motion-scroll.js' ] ) {
		assert( urls.some( ( u ) => u.includes( `/${ file }` ) ), `lm-e2e-none: ${ file } not requested` );
	}
	// The Lenis stylesheet is printed inline, as is, after the motion styles.
	assert( ! urls.some( ( u ) => u.includes( '/lenis.css' ) ), 'lm-e2e-none: lenis.css requested (it is printed inline)' );
	const lenisCss = await page.evaluate( () => {
		const node = document.getElementById( 'lumia-lenis-inline-css' );
		const motion = document.getElementById( 'lumia-motion-inline-css' );
		return node ? { text: node.textContent, after: !! ( motion && ( motion.compareDocumentPosition( node ) & Node.DOCUMENT_POSITION_FOLLOWING ) ) } : null;
	} );
	assert( lenisCss && /html\.lenis/.test( lenisCss.text ) && /lenis-stopped/.test( lenisCss.text ), 'lm-e2e-none: the Lenis styles are not inline' );
	assert( lenisCss.after, 'lm-e2e-none: the Lenis styles come before the motion styles' );
	assert( ! urls.some( ( u ) => /gsap|ScrollTrigger|SplitText|\/motion\.js/i.test( u ) ), 'lm-e2e-none: a GSAP or engine file was requested' );
	assert( foreignRequests( urls ).length === 0, `lm-e2e-none: foreign host: ${ foreignRequests( urls ).join( ', ' ) }` );
	const state = await page.evaluate( () => ( {
		gsap: typeof window.gsap,
		lenis: typeof window.lumiaLenis,
		className: document.documentElement.classList.contains( 'lenis' ),
		autoRaf: window.lumiaLenis.options.autoRaf,
		lerp: window.lumiaLenis.options.lerp,
		syncTouch: window.lumiaLenis.options.syncTouch,
		config: window.lumiaMotionScroll || null,
	} ) );
	assert( state.gsap === 'undefined', `lm-e2e-none: window.gsap is ${ state.gsap }` );
	assert( state.lenis === 'object', `lm-e2e-none: window.lumiaLenis is ${ state.lenis }` );
	assert( state.className, 'lm-e2e-none: <html> has no lenis class' );
	assert( state.autoRaf === false, `lm-e2e-none: autoRaf is ${ state.autoRaf } (motion-scroll.js runs its own loop)` );
	assert( state.lerp === 0.1 && state.syncTouch === false, `lm-e2e-none: lerp=${ state.lerp } syncTouch=${ state.syncTouch }` );
	assert( state.config && state.config.lerp === 0.1, `lm-e2e-none: window.lumiaMotionScroll=${ JSON.stringify( state.config ) }` );

	noErrors( page, 'lm-e2e-none' );
	await page.context().close();
}

// Without GSAP (a tall page with no effect, so no engine): the loop of motion-scroll.js sleeps while nothing
// scrolls, the wheel wakes it and scrolls smoothly (several frames), then it sleeps again.
async function loopSleeps() {
	const page = await newPage();
	await load( page, 'lm-e2e-anchor' );
	await lenisReady( page );
	assert( await page.evaluate( () => typeof window.gsap ) === 'undefined', 'loop: GSAP is on the anchor page' );

	await page.evaluate( () => {
		window.lmFrames = 0;
		const raf = window.requestAnimationFrame;
		window.requestAnimationFrame = function ( fn ) {
			window.lmFrames++;
			return raf.call( window, fn );
		};
	} );
	await page.waitForTimeout( 1000 );
	const idle = await page.evaluate( () => window.lmFrames );
	assert( idle === 0, `loop: ${ idle } frames requested in 1 s with the page untouched (the loop must sleep)` );
	await page.mouse.move( 700, 450 );
	const y0 = await page.evaluate( () => window.scrollY );
	await page.mouse.wheel( 0, 400 );
	const steps = [];
	for ( let i = 0; i < 6; i++ ) {
		await page.waitForTimeout( 50 );
		steps.push( await page.evaluate( () => window.scrollY ) );
	}
	assert( steps[ 0 ] > y0 && steps[ 0 ] < y0 + 400 && steps[ 5 ] > steps[ 0 ], `loop: the wheel did not scroll smoothly from ${ y0 }: ${ steps.join( ', ' ) }` );
	await page.waitForTimeout( 1500 );
	const before = await page.evaluate( () => window.lmFrames );
	await page.waitForTimeout( 1000 );
	const after = await page.evaluate( () => window.lmFrames );
	assert( after === before, `loop: the loop did not sleep again (${ after - before } frames in 1 s after the scroll)` );
	noErrors( page, 'loop' );
	await page.context().close();
}

// An animated page: Lenis shares the GSAP clock, the wheel scrolls, ScrollTrigger follows.
async function animatedPage() {
	const page = await newPage();
	const urls = await load( page, 'lm-e2e-basic' );
	await lenisReady( page );
	await engineReady( page );
	assert( urls.some( ( u ) => u.includes( '/motion-scroll.js' ) ) && urls.some( ( u ) => u.includes( '/lenis.min.js' ) ), 'lm-e2e-basic: Lenis files not requested' );
	const clock = await page.evaluate( () => ( {
		autoRaf: window.lumiaLenis.options.autoRaf,
		gsap: typeof window.gsap,
		lag: window.gsap && window.gsap.ticker ? typeof window.gsap.ticker.lagSmoothing : null,
	} ) );
	assert( clock.gsap === 'object' && clock.autoRaf === false, `lm-e2e-basic: gsap=${ clock.gsap } autoRaf=${ clock.autoRaf } (the GSAP ticker must drive Lenis)` );

	// The wheel scrolls through Lenis (not at once: the lerp spreads it over frames), and the entrances follow.
	const before = ( await items( page ) ).filter( ( i ) => i.state === 'pending' ).map( ( i ) => i.id );
	assert( before.length > 0, 'lm-e2e-basic: no pending item to wait for' );
	await page.mouse.move( 700, 450 );
	const y0 = await page.evaluate( () => window.scrollY );
	await page.mouse.wheel( 0, 400 );
	const samples = [];
	for ( let i = 0; i < 6; i++ ) {
		await page.waitForTimeout( 40 );
		samples.push( await page.evaluate( () => window.scrollY ) );
	}
	for ( let i = 0; i < 4; i++ ) {
		await page.mouse.wheel( 0, 500 );
		await page.waitForTimeout( 150 );
	}
	const y1 = await stable( page );
	assert( y1 > y0 + 1500, `lm-e2e-basic: the wheel scrolled ${ y1 - y0 } px (expected at least 1500)` );
	assert( samples.some( ( y ) => y > y0 && y < y0 + 400 ), `lm-e2e-basic: the first wheel step was not spread over frames (${ samples.join( ', ' ) })` );
	await page.waitForFunction(
		( ids ) => window.lumiaMotion._debug.items().some( ( i ) => ids.includes( i.id ) && ( i.state === 'done' || i.state === 'final' ) ),
		before,
		{ timeout: 4000 }
	).catch( async () => {
		throw new Error( `lm-e2e-basic: scrolled to ${ y1 } but no pending item reached done (${ JSON.stringify( ( await items( page ) ).map( ( i ) => [ i.id, i.state ] ) ) })` );
	} );

	// A reload in the middle of the page keeps the position.
	await page.evaluate( () => window.lumiaLenis.scrollTo( 3000, { immediate: true } ) );
	const mid = await stable( page );
	assert( near( mid, 3000, 2 ), `lm-e2e-basic: could not scroll to 3000 (${ mid })` );
	await page.reload( { waitUntil: 'load' } );
	await lenisReady( page );
	await engineReady( page );
	const after = await stable( page );
	assert( Math.abs( after - 3000 ) <= 5, `lm-e2e-basic: reload moved the page to ${ after } (expected 3000)` );

	// A popup that opens stops the scroll, and starts it again when it closes.
	const stopped = await page.evaluate( () => {
		const out = [];
		document.dispatchEvent( new CustomEvent( 'bricks/popup/open' ) );
		out.push( window.lumiaLenis.isStopped, document.documentElement.classList.contains( 'lenis-stopped' ) );
		document.dispatchEvent( new CustomEvent( 'bricks/popup/close' ) );
		out.push( window.lumiaLenis.isStopped );
		return out;
	} );
	assert( stopped[ 0 ] === true && stopped[ 1 ] === true, `lm-e2e-basic: bricks/popup/open did not stop Lenis (isStopped=${ stopped[ 0 ] }, lenis-stopped=${ stopped[ 1 ] })` );
	assert( stopped[ 2 ] === false, 'lm-e2e-basic: bricks/popup/close did not start Lenis again' );
	noErrors( page, 'lm-e2e-basic' );
	await page.context().close();
}

// Anchors: same page (Lenis scrolls) and from another page (the load lands under the header), at two header heights.
async function anchors() {
	for ( const [ width, expected ] of [ [ 1440, 64 ], [ 900, 90 ] ] ) {
		const label = `anchors at ${ width } px`;
		const page = await newPage( { width, height: 800 } );
		await injectCss( page, NAVBAR_CSS );
		await load( page, 'lm-e2e-anchor' );
		await lenisReady( page );
		await click( page, '#lmlocal' );
		await stable( page );
		const local = await targetAt( page );
		assert( near( local.navbar, expected, 1 ), `${ label }: --navbar-height measures ${ local.navbar } px (expected ${ expected })` );
		assert( Math.abs( local.top - local.navbar ) <= 4, `${ label }: same-page anchor puts #target at ${ local.top } px (expected --navbar-height ${ local.navbar } px, scrollY ${ local.y })` );

		// The header height changes with the screen: the offset follows it, without a reload.
		const other = width === 1440 ? 900 : 1440;
		const otherExpected = width === 1440 ? 90 : 64;
		await page.evaluate( () => window.lumiaLenis.scrollTo( 0, { immediate: true } ) );
		await page.setViewportSize( { width: other, height: 800 } );
		await page.waitForTimeout( 300 );
		await click( page, '#lmlocal' );
		await stable( page );
		const resized = await targetAt( page );
		assert( near( resized.navbar, otherExpected, 1 ), `${ label }: after resizing to ${ other } px, --navbar-height measures ${ resized.navbar } px (expected ${ otherExpected })` );
		assert( Math.abs( resized.top - resized.navbar ) <= 4, `${ label }: after resizing to ${ other } px, #target at ${ resized.top } px (expected ${ resized.navbar } px)` );
		noErrors( page, label );
		await page.context().close();
	}

	// From another page: the browser lands on the target; the header must not cover it.
	const page = await newPage();
	await injectCss( page, NAVBAR_CSS );
	await load( page, 'lm-e2e-basic' );
	await lenisReady( page );
	await Promise.all( [
		page.waitForURL( /\/lm-e2e-anchor\/#target$/, { waitUntil: 'load' } ),
		click( page, '#lmanchor' ),
	] );
	await lenisReady( page );
	await stable( page );
	const cross = await targetAt( page );
	assert( cross.y > 1000, `anchor from another page: the page did not scroll (scrollY ${ cross.y })` );
	assert( Math.abs( cross.top - cross.navbar ) <= 4, `anchor from another page: #target at ${ cross.top } px (expected --navbar-height ${ cross.navbar } px, scrollY ${ cross.y })` );
	noErrors( page, 'anchor from another page' );
	await page.context().close();
}

// Real Bricks popups: the title (an effect of the page) ends visible, Lenis is stopped while it is open.
async function popups() {
	const cases = [
		[ 'lm-e2e-popup', '#brxe-popbs1', '#brxe-pops1h', 'static popup' ],
		[ 'lm-e2e-popup', '#brxe-popba1', '#brxe-popa1h', 'AJAX popup' ],
		[ 'lm-e2e-popup-plain', '#brxe-popba2', '#brxe-popa1h', 'AJAX popup on a page with no animation of its own' ],
	];
	for ( const [ slug, button, title, name ] of cases ) {
		const label = `${ slug }, ${ name }`;
		const page = await newPage();
		await load( page, slug );
		await lenisReady( page );
		await engineReady( page );
		const prevent = await page.evaluate( () => [ ...document.querySelectorAll( '.brx-popup' ) ].map( ( p ) => p.hasAttribute( 'data-lenis-prevent' ) ) );
		assert( prevent.length > 0 && prevent.every( Boolean ), `${ label }: .brx-popup without data-lenis-prevent (${ prevent.join( ',' ) })` );
		await click( page, button );
		const t0 = Date.now();
		await page.waitForFunction(
			( sel ) => {
				const node = document.querySelector( sel );
				return !! node && node.getClientRects().length > 0 && getComputedStyle( node ).opacity === '1';
			},
			title,
			{ timeout: 6000 }
		).catch( async () => {
			throw new Error( `${ label }: the title is not visible 6 s after opening (${ await page.evaluate( ( sel ) => {
				const node = document.querySelector( sel );
				return node ? `opacity ${ getComputedStyle( node ).opacity }, ${ node.getClientRects().length } boxes, lm-ready ${ document.documentElement.classList.contains( 'lm-ready' ) }` : 'not in the page';
			}, title ) })` );
		} );
		console.log( `info lenis: ${ label }: title visible ${ Date.now() - t0 } ms after the click` );
		assert( await page.evaluate( () => window.lumiaLenis.isStopped ), `${ label }: Lenis is not stopped while the popup is open` );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! window.lumiaLenis.isStopped, null, { timeout: 3000 } ).catch( () => {
			throw new Error( `${ label }: Lenis is still stopped after the popup closed` );
		} );
		noErrors( page, label );
		await page.context().close();
	}
}

// Smooth scroll off: no instance, no file, the stylesheet's margin keeps anchors under the header.
async function lenisOff() {
	await withMotionSettings( { lenis: { enabled: false, lerp: 0.1 } }, async () => {
		const page = await newPage();
		await injectCss( page, NAVBAR_CSS );
		const urls = await load( page, 'lm-e2e-basic' );
		await engineReady( page );
		assert( ! urls.some( ( u ) => /lenis|motion-scroll/.test( u ) ), `Lenis off: a Lenis file was requested (${ urls.filter( ( u ) => /lenis|motion-scroll/.test( u ) ).join( ', ' ) })` );
		const state = await page.evaluate( () => ( { lenis: typeof window.lumiaLenis, className: document.documentElement.classList.contains( 'lenis' ), constructor: typeof window.Lenis } ) );
		assert( state.lenis === 'undefined' && ! state.className && state.constructor === 'undefined', `Lenis off: lumiaLenis=${ state.lenis } lenis class=${ state.className } Lenis=${ state.constructor }` );
		await Promise.all( [
			page.waitForURL( /\/lm-e2e-anchor\/#target$/, { waitUntil: 'load' } ),
			click( page, '#lmanchor' ),
		] );
		await page.waitForTimeout( 500 );
		const cross = await targetAt( page );
		assert( cross.y > 1000, `Lenis off: the anchor did not scroll the page (scrollY ${ cross.y })` );
		assert( Math.abs( cross.top - cross.navbar ) <= 4, `Lenis off: #target at ${ cross.top } px (expected scroll-margin-top ${ cross.navbar } px, scrollY ${ cross.y })` );
		noErrors( page, 'Lenis off' );
		await page.context().close();
	} );
}

// The intensity of the settings reaches the instance.
async function intensity() {
	await withMotionSettings( { lenis: { enabled: true, lerp: 0.25 } }, async () => {
		const page = await newPage();
		await load( page, 'lm-e2e-none' );
		await lenisReady( page );
		const lerp = await page.evaluate( () => window.lumiaLenis.options.lerp );
		assert( lerp === 0.25, `lerp 0.25 in the settings, ${ lerp } in Lenis` );
		noErrors( page, 'intensity' );
		await page.context().close();
	} );
}

// Menus that lock the page with body.no-scroll and fire no popup event (an offcanvas with
// "No scroll (body)", the mobile menu of a nested nav): the page behind them must not scroll
// under the wheel, and scrolls again once they close.
async function menus() {
	const cases = [
		[ 'offcanvas', '#brxe-menubo', '.brxe-offcanvas' ],
		[ 'nested nav', '#brxe-menunt', '.brx-nav-nested-items' ],
	];
	for ( const [ name, opener, prevented ] of cases ) {
		const page = await newPage();
		await load( page, 'lm-e2e-menu' );
		await lenisReady( page );
		// At the top: closing a menu gives the focus back to its opener, which scrolls the page to it (Bricks, with or without Lenis).
		await click( page, opener );
		await page.waitForFunction( () => document.body.classList.contains( 'no-scroll' ), null, { timeout: 4000 } ).catch( () => {
			throw new Error( `${ name }: the menu did not lock the page (no body.no-scroll)` );
		} );
		await page.mouse.move( 700, 450 );
		await page.mouse.wheel( 0, 600 );
		await page.waitForTimeout( 900 );
		const locked = await page.evaluate( () => ( { y: window.scrollY, stopped: window.lumiaLenis.isStopped } ) );
		assert( locked.y === 0, `${ name }: the wheel scrolled the page behind the open menu (scrollY ${ locked.y }, expected 0)` );
		assert( locked.stopped, `${ name }: Lenis is not stopped while the menu is open` );
		assert( await page.evaluate( ( sel ) => document.querySelector( sel ).hasAttribute( 'data-lenis-prevent' ), prevented ), `${ name }: ${ prevented } has no data-lenis-prevent` );

		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.body.classList.contains( 'no-scroll' ), null, { timeout: 4000 } ).catch( () => {
			throw new Error( `${ name }: Escape did not close the menu` );
		} );
		await page.waitForFunction( () => ! window.lumiaLenis.isStopped, null, { timeout: 3000 } ).catch( () => {
			throw new Error( `${ name }: Lenis is still stopped after the menu closed` );
		} );
		await page.mouse.wheel( 0, 600 );
		const y = await stable( page );
		assert( y > 400, `${ name }: the wheel does not scroll again after the menu closed (scrollY ${ y })` );
		noErrors( page, name );
		await page.context().close();
	}
}

// A desktop nested nav (a horizontal bar, nothing locked): the wheel over it scrolls the page through Lenis,
// like anywhere else. Marked data-lenis-prevent, it let the browser scroll natively under Lenis.
// The mobile menu of the other nav, once opened, is marked and the page stays locked; closed, it is unmarked.
async function desktopNav() {
	const page = await newPage();
	await load( page, 'lm-e2e-menu' );
	await lenisReady( page );
	const box = await page.locator( '#brxe-menudul' ).boundingBox();
	assert( box && box.width > 0 && box.height > 0, 'desktop nav: the nested nav items are not visible at 1440 px' );
	const marks = await page.evaluate( () => [ ...document.querySelectorAll( '.brx-nav-nested-items' ) ].map( ( n ) => `${ n.id }:${ n.hasAttribute( 'data-lenis-prevent' ) }` ) );
	assert( marks.every( ( m ) => m.endsWith( ':false' ) ), `desktop nav: nav items marked data-lenis-prevent at load (${ marks.join( ', ' ) })` );

	await page.evaluate( () => {
		window.lmModes = [];
		window.lumiaLenis.on( 'scroll', ( l ) => window.lmModes.push( l.isScrolling ) );
	} );
	await page.mouse.move( box.x + Math.min( 20, box.width / 2 ), box.y + box.height / 2 );
	const y0 = await page.evaluate( () => window.scrollY );
	await page.mouse.wheel( 0, 400 );
	const samples = [];
	for ( let i = 0; i < 6; i++ ) {
		await page.waitForTimeout( 40 );
		samples.push( await page.evaluate( () => window.scrollY ) );
	}
	const y1 = await stable( page );
	const modes = await page.evaluate( () => [ ...new Set( window.lmModes ) ] );
	assert( y1 > y0 + 300, `desktop nav: the wheel over the nav scrolled ${ y1 - y0 } px (expected about 400)` );
	assert( modes.includes( 'smooth' ) && ! modes.includes( 'native' ), `desktop nav: the wheel over the nav scrolled ${ modes.join( '/' ) || 'without Lenis' } (expected smooth, through Lenis)` );
	assert( samples.some( ( y ) => y > y0 && y < y0 + 400 ), `desktop nav: the wheel step was not spread over frames (${ samples.join( ', ' ) })` );

	// The mobile menu of the other nav: marked while it locks the page, unmarked once closed.
	await page.evaluate( () => window.lumiaLenis.scrollTo( 0, { immediate: true } ) );
	await click( page, '#brxe-menunt' );
	await page.waitForFunction( () => document.body.classList.contains( 'no-scroll' ), null, { timeout: 4000 } );
	assert( await page.evaluate( () => document.getElementById( 'brxe-menuuls' ).hasAttribute( 'data-lenis-prevent' ) ), 'mobile menu: open but not marked data-lenis-prevent' );
	await page.keyboard.press( 'Escape' );
	await page.waitForFunction( () => ! document.body.classList.contains( 'no-scroll' ), null, { timeout: 4000 } );
	await page.waitForFunction( () => ! window.lumiaLenis.isStopped, null, { timeout: 3000 } );
	const after = await page.evaluate( () => [ ...document.querySelectorAll( '.brx-nav-nested-items' ) ].map( ( n ) => `${ n.id }:${ n.hasAttribute( 'data-lenis-prevent' ) }` ) );
	assert( after.every( ( m ) => m.endsWith( ':false' ) ), `mobile menu closed: nav items still marked (${ after.join( ', ' ) })` );
	noErrors( page, 'desktop nav' );
	await page.context().close();
}

// The former snippet owns smooth scroll: the module does not start Lenis next to it, whether
// its global is there before the module runs or only by the time the page has loaded.
async function formerSnippet() {
	const GLOBAL = 'StudioKyneMotion';
	for ( const [ name, init ] of [
		[ 'global before the module', `window.${ GLOBAL } = {};` ],
		[ 'global after the module, before load', `(${ watchLenis.toString() })( '${ GLOBAL }' );` ],
	] ) {
		for ( const slug of [ 'lm-e2e-none', 'lm-e2e-basic' ] ) {
			const label = `former snippet (${ name }), ${ slug }`;
			const page = await newPage();
			await page.addInitScript( init );
			await load( page, slug );
			await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 5000 } );
			await page.waitForTimeout( 300 );
			const state = await page.evaluate( () => ( {
				lenis: typeof window.lumiaLenis,
				className: [ ...document.documentElement.classList ].filter( ( c ) => c === 'lenis' || c.startsWith( 'lenis-' ) ),
			} ) );
			assert( state.lenis === 'undefined', `${ label }: window.lumiaLenis is ${ state.lenis }` );
			assert( state.className.length === 0, `${ label }: <html> still has ${ state.className.join( ' ' ) }` );
			// The page still scrolls, natively.
			await page.mouse.move( 700, 450 );
			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			noErrors( page, label );
			await page.context().close();
		}
	}

	// Arriving on an anchor while the former snippet shows up as soon as the instance exists: the landing must
	// not drive the instance that stood down. Since the loader injects the scripts after the first paint, the
	// instance usually appears after the load, which the "load" listener alone used to miss.
	const page = await newPage();
	await page.addInitScript( watchLenis, GLOBAL );
	await page.goto( pageUrl( 'lm-e2e-anchor' ) + '#target', { waitUntil: 'load' } );
	await page.waitForTimeout( 600 );
	const late = await page.evaluate( () => ( { calls: window.lmCalls, lenis: typeof window.lumiaLenis, loadedFirst: window.lmLoadedFirst } ) );
	assert( late.loadedFirst !== null, 'former snippet, anchor landing: the instance never appeared (the case is not reproduced)' );
	assert( late.lenis === 'undefined', `former snippet, anchor landing: window.lumiaLenis is ${ late.lenis }` );
	assert( late.calls === 0, `former snippet, anchor landing: the destroyed Lenis was asked to scroll ${ late.calls } time(s)` );
	noErrors( page, 'former snippet, anchor landing' );
	await page.context().close();
}

// prefers-reduced-motion: reduce: no Lenis at all, native scroll, anchors under the header by the stylesheet.
async function reducedMotion() {
	for ( const slug of [ 'lm-e2e-none', 'lm-e2e-basic' ] ) {
		const label = `reduced motion, ${ slug }`;
		const page = await newPage( { reducedMotion: 'reduce' } );
		await injectCss( page, NAVBAR_CSS );
		await load( page, slug );
		await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 5000 } );
		const state = await page.evaluate( () => ( { lenis: typeof window.lumiaLenis, className: document.documentElement.classList.contains( 'lenis' ) } ) );
		assert( state.lenis === 'undefined' && ! state.className, `${ label }: lumiaLenis=${ state.lenis } lenis class=${ state.className }` );
		noErrors( page, label );
		await page.context().close();
	}

	const page = await newPage( { reducedMotion: 'reduce', width: 1440, height: 800 } );
	await injectCss( page, NAVBAR_CSS );
	await load( page, 'lm-e2e-anchor' );
	await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 5000 } );
	await click( page, '#lmlocal' );
	await stable( page );
	const local = await targetAt( page );
	assert( local.y > 1000 && Math.abs( local.top - local.navbar ) <= 4, `reduced motion: same-page anchor puts #target at ${ local.top } px (expected ${ local.navbar } px, scrollY ${ local.y })` );
	await page.goto( pageUrl( 'lm-e2e-basic' ), { waitUntil: 'load' } );
	await Promise.all( [
		page.waitForURL( /\/lm-e2e-anchor\/#target$/, { waitUntil: 'load' } ),
		click( page, '#lmanchor' ),
	] );
	await stable( page );
	const cross = await targetAt( page );
	assert( cross.y > 1000 && Math.abs( cross.top - cross.navbar ) <= 4, `reduced motion: anchor from another page puts #target at ${ cross.top } px (expected ${ cross.navbar } px, scrollY ${ cross.y })` );
	noErrors( page, 'reduced motion anchors' );
	await page.context().close();
}

// The correction after an anchor landing is for targets with a box: a hidden one (display:none) is left alone.
async function hiddenAnchor() {
	for ( const [ hash, calls, name ] of [ [ '#lmhidden', 0, 'a target without a box' ], [ '#target', 1, 'a visible target' ] ] ) {
		const page = await newPage();
		await page.addInitScript( watchLenis, null );
		await page.goto( pageUrl( 'lm-e2e-anchor' ) + hash, { waitUntil: 'load' } );
		await lenisReady( page );
		await page.waitForTimeout( 600 );
		const made = await page.evaluate( () => window.lmCalls );
		assert( ( calls === 0 ) === ( made === 0 ), `landing on ${ name }: Lenis.scrollTo called ${ made } time(s) (expected ${ calls === 0 ? 'none' : 'at least one' })` );
		noErrors( page, `landing on ${ name }` );
		await page.context().close();
	}
}

// A reload in the middle of a page keeps the position when Lenis runs on the loop of motion-scroll.js (no engine on the page), at both widths.
async function reloadWithoutEngine() {
	for ( const width of [ 1440, 390 ] ) {
		const label = `reload without the engine, ${ width } px`;
		const page = await newPage( { width, height: width > 500 ? 900 : 844 } );
		await load( page, 'lm-e2e-anchor' );
		await lenisReady( page );
		assert( await page.evaluate( () => window.lumiaLenis.options.autoRaf === false && typeof window.gsap === 'undefined' ), `${ label }: the page is not on the loop of motion-scroll.js` );
		await page.evaluate( () => window.lumiaLenis.scrollTo( 3000, { immediate: true } ) );
		assert( near( await stable( page ), 3000, 2 ), `${ label }: could not scroll to 3000` );
		await page.reload( { waitUntil: 'load' } );
		await lenisReady( page );
		const after = await stable( page );
		assert( Math.abs( after - 3000 ) <= 5, `${ label }: reload moved the page to ${ after } (expected 3000)` );
		noErrors( page, label );
		await page.context().close();
	}
}

export default async function () {
	await plainPage();
	await loopSleeps();
	await animatedPage();
	await anchors();
	await popups();
	await menus();
	await desktopNav();
	await formerSnippet();
	await reducedMotion();
	await hiddenAnchor();
	await reloadWithoutEngine();
	await lenisOff();
	await intensity();
}
