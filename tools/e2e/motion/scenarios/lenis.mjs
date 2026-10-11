// Smooth scroll (Lenis) on every public page when it is on: its instance, the share of the
// clock with GSAP, the wheel, same-page and cross-page anchors under the header, the reload,
// the popups (stop and start, a real Bricks popup, static and fetched), and nothing at all
// when the setting is off. The lab sets --navbar-height to 61 px (its custom CSS uses
// !important): the scenario overrides it with a clamp() in rem, and another value under
// 1000 px, to exercise what the offset has to read.
import { newPage, pageUrl, assert, near, items, injectCss, withMotionSettings, BASE } from '../lib.mjs';

const NAVBAR_CSS = ':root{--navbar-height:clamp(60px,4rem,90px) !important}@media (max-width:1000px){:root{--navbar-height:90px !important}}';
const HOST = new URL( BASE ).host;

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
	for ( const file of [ 'lenis.min.js', 'motion-scroll.js', 'lenis.css' ] ) {
		assert( urls.some( ( u ) => u.includes( `/${ file }` ) ), `lm-e2e-none: ${ file } not requested` );
	}
	assert( ! urls.some( ( u ) => /gsap|ScrollTrigger|SplitText|\/motion\.js/i.test( u ) ), 'lm-e2e-none: a GSAP or engine file was requested' );
	assert( urls.every( ( u ) => ! u.startsWith( 'http' ) || new URL( u ).host === HOST ), `lm-e2e-none: foreign host: ${ urls.filter( ( u ) => u.startsWith( 'http' ) && new URL( u ).host !== HOST ).join( ', ' ) }` );
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
	assert( state.autoRaf === true, `lm-e2e-none: autoRaf is ${ state.autoRaf } without GSAP (Lenis has no other clock)` );
	assert( state.lerp === 0.1 && state.syncTouch === false, `lm-e2e-none: lerp=${ state.lerp } syncTouch=${ state.syncTouch }` );
	assert( state.config && state.config.lerp === 0.1, `lm-e2e-none: window.lumiaMotionScroll=${ JSON.stringify( state.config ) }` );
	noErrors( page, 'lm-e2e-none' );
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

export default async function () {
	await plainPage();
	await animatedPage();
	await anchors();
	await popups();
	await lenisOff();
	await intensity();
}
