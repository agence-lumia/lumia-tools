// The visitor stays where they were: reload in the middle of the page, back button, and an
// anchor from another page, at 1440 and 390 px, with and without the theme's
// `scroll-behavior: smooth`. Elements above the screen after a reload are shown at once.
import { newPage, pageUrl, assert, items, injectSmoothScroll } from '../lib.mjs';

const SLUG = 'lm-e2e-basic';
const AT = 3000;

const ready = ( page ) => page.waitForFunction(
	() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
	null,
	{ timeout: 5000 }
).catch( () => {
	throw new Error( `${ page.url() }: the engine did not start (no lm-ready or no lumiaMotion._debug after 5 s)` );
} );

const scrollY = ( page ) => page.evaluate( () => window.scrollY );

/** Resolves with scrollY once it has not moved for 2 frames (3 s at most). */
const stable = ( page ) => page.evaluate( () => new Promise( ( resolve ) => {
	const t0 = performance.now();
	let last = NaN;
	let same = 0;
	const tick = () => {
		const y = window.scrollY;
		same = y === last ? same + 1 : 0;
		last = y;
		if ( same >= 2 || performance.now() - t0 > 3000 ) {
			resolve( y );
		} else {
			requestAnimationFrame( tick );
		}
	};
	requestAnimationFrame( tick );
} ) );

/** Asserts every item above the screen is final and visible, and that there is at least one. */
async function abovePassed( page, label ) {
	const passed = await above( page );
	assert( passed.length > 0, `${ label }: no item above the screen at ${ AT }, the check would prove nothing` );
	const wrong = passed.filter( ( p ) => p.state !== 'final' || p.opacity !== '1' );
	assert( wrong.length === 0, `${ label }: items above the screen not final and visible: ${ JSON.stringify( wrong ) }` );
}

/** Items whose element is above the screen, with their state and computed opacity. */
const above = ( page ) => page.evaluate( () => window.lumiaMotion._debug.items()
	.map( ( i ) => ( { i, node: document.getElementById( i.id ) } ) )
	.filter( ( x ) => x.node && x.node.getBoundingClientRect().bottom < 0 )
	.map( ( x ) => ( { id: x.i.id, state: x.i.state, opacity: getComputedStyle( x.node ).opacity } ) ) );

async function run( width, smooth ) {
	const label = `${ width } px${ smooth ? ', smooth scroll' : '' }`;
	const page = await newPage( { width, height: width > 500 ? 900 : 844 } );
	if ( smooth ) {
		await injectSmoothScroll( page );
	}
	// Tells a page restored from the back-forward cache from a page loaded again.
	await page.addInitScript( () => {
		window.addEventListener( 'pageshow', ( e ) => {
			window.lmRestored = e.persisted;
		} );
	} );

	// Reload in the middle of the page.
	await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
	await ready( page );
	await page.evaluate( ( y ) => window.scrollTo( 0, y ), AT );
	const scrolled = await stable( page );
	assert( Math.abs( scrolled - AT ) <= 1, `${ label }: could not scroll to ${ AT } (${ scrolled })` );
	await page.reload( { waitUntil: 'load' } );
	await ready( page );
	const afterReload = await stable( page );
	assert( Math.abs( afterReload - AT ) <= 5, `${ label }: reload moved the page to ${ afterReload } (expected ${ AT })` );
	assert( ( await items( page ) ).length > 0, `${ label }: the engine built no item` );
	await abovePassed( page, `${ label }, reload` );

	// Another page, then back.
	await page.goto( pageUrl( 'lm-e2e-smoke' ), { waitUntil: 'load' } );
	await page.goBack( { waitUntil: 'load' } );
	await ready( page );
	const afterBack = await stable( page );
	const restored = await page.evaluate( () => window.lmRestored === true );
	console.log( `info scroll-position: ${ label }: back button ${ restored ? 'restored the page from the back-forward cache' : 'loaded the page again' }` );
	assert( Math.abs( afterBack - AT ) <= 5, `${ label }: back button moved the page to ${ afterBack } (expected ${ AT })` );
	await abovePassed( page, `${ label }, back button` );

	// Anchor on another page: the target lands under the header height (--navbar-height).
	await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
	await ready( page );
	await Promise.all( [
		page.waitForURL( /\/lm-e2e-anchor\/#target$/, { waitUntil: 'load' } ),
		// The site header overlaps the link at the top of the page: what is tested is the navigation, not the pointer.
		page.locator( '#lmanchor' ).evaluate( ( a ) => a.click() ),
	] );
	await stable( page );
	const anchor = await page.evaluate( () => {
		// The variable may be a clamp() or rem: measure it.
		const probe = document.createElement( 'div' );
		probe.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:var(--navbar-height, 0px)';
		document.body.appendChild( probe );
		const navbar = probe.getBoundingClientRect().height;
		probe.remove();
		return { navbar, top: document.getElementById( 'target' ).getBoundingClientRect().top, y: window.scrollY };
	} );
	assert( Math.abs( anchor.top - anchor.navbar ) <= 4, `${ label }: #target at ${ anchor.top } px from the top (expected --navbar-height ${ anchor.navbar } px, scrollY ${ anchor.y })` );

	assert( page.errors.length === 0, `${ label }: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

export default async function () {
	for ( const width of [ 1440, 390 ] ) {
		for ( const smooth of [ false, true ] ) {
			await run( width, smooth );
		}
	}
}
