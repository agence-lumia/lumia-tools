// Edge cases of the load zone and of the engine: a Template element in the header does not take the
// first-root slot; a query loop of the first section still brings the engine (items added later need it);
// a class typed with dynamic data is read as Bricks renders it; an entrance ends at the element's own opacity.
import { newPage, pageUrl, assert } from '../lib.mjs';

/** Requests made while a page loads. */
async function load( page, slug, query = '' ) {
	const urls = [];
	page.on( 'request', ( r ) => urls.push( r.url() ) );
	const res = await page.goto( pageUrl( slug, query ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ slug }: status ${ res && res.status() }` );
	return urls;
}

const attr = ( page, id, name ) => page.evaluate( ( [ id, name ] ) => {
	const node = document.getElementById( `brxe-${ id }` );
	return node ? node.getAttribute( name ) : 'missing';
}, [ id, name ] );

export default async function () {
	// A Template element in the header renders a section template with the area "content".
	{
		const page = await newPage();
		await load( page, 'lm-e2e-header' );
		assert( ( await attr( page, 'hdtplel', 'class' ) ) !== 'missing', 'header: the Template element is not on the page (the header template is not applied)' );
		assert( ( await attr( page, 'tplsh1', 'data-lm-load' ) ) === 'fade', `header: the template heading has data-lm-load="${ await attr( page, 'tplsh1', 'data-lm-load' ) }" (it is in the header)` );
		assert( ( await attr( page, 'hdfrst', 'data-lm-load' ) ) === 'fade', `header: the first section of the page lost its load animation (data-lm-load="${ await attr( page, 'hdfrst', 'data-lm-load' ) }")` );
		assert( ( await attr( page, 'hdbelo', 'data-lm-load' ) ) === null, 'header: an element below the first section plays on load' );
		assert( page.errors.length === 0, `header: page errors: ${ page.errors.join( ' | ' ) }` );
		await page.context().close();
	}

	// The loop items of the first section play in CSS, and the engine still loads for the items added later.
	{
		const page = await newPage();
		const urls = await load( page, 'lm-e2e-loop-first' );
		const loads = await page.evaluate( () => [ ...document.querySelectorAll( '.brxe-lpfrit' ) ].map( ( n ) => n.getAttribute( 'data-lm-load' ) ) );
		assert( loads.length > 0 && loads.every( ( v ) => v === 'fade' ), `loop: the items of the first section are ${ JSON.stringify( loads ) } (expected data-lm-load="fade")` );
		await page.waitForFunction( () => window.lumiaMotion && window.lumiaMotion._debug, null, { timeout: 5000 } ).catch( () => {
			throw new Error( 'loop: the engine did not load for a query loop of the first section' );
		} );
		assert( urls.some( ( u ) => /\/gsap\.min\.js/.test( u ) ), 'loop: gsap.min.js not requested' );
		await page.context().close();
	}

	// With ?fx=fade the typed "lm-{url_parameter:fx}" renders as lm-fade: marked as an entrance.
	{
		const page = await newPage();
		await load( page, 'lm-e2e-edges', '?fx=fade' );
		assert( ( await attr( page, 'edgedy', 'data-lm' ) ) === 'enter', `dynamic class: data-lm="${ await attr( page, 'edgedy', 'data-lm' ) }" with ?fx=fade (class "${ await attr( page, 'edgedy', 'class' ) }")` );
		await page.context().close();
	}
	// Without the parameter the typed class names no effect; the entrance ends at the element's own opacity.
	{
		const page = await newPage();
		await load( page, 'lm-e2e-edges' );
		assert( ( await attr( page, 'edgedy', 'data-lm' ) ) === null, 'dynamic class: marked without the parameter' );
		// The entrance ends at the element's own opacity: it never goes past 0.5, and ends there.
		await page.evaluate( () => {
			window.lmMax = 0;
			const node = document.getElementById( 'brxe-edgeop' );
			const tick = () => {
				window.lmMax = Math.max( window.lmMax, parseFloat( getComputedStyle( node ).opacity ) );
				requestAnimationFrame( tick );
			};
			requestAnimationFrame( tick );
			node.scrollIntoView( { block: 'center' } );
		} );
		await page.waitForTimeout( 2000 );
		const end = await page.evaluate( () => ( { max: window.lmMax, now: parseFloat( getComputedStyle( document.getElementById( 'brxe-edgeop' ) ).opacity ), inline: document.getElementById( 'brxe-edgeop' ).style.opacity } ) );
		assert( end.max <= 0.51, `author opacity: the entrance went up to ${ end.max } (the element is at 0.5)` );
		assert( Math.abs( end.now - 0.5 ) < 0.01 && end.inline === '', `author opacity: ends at ${ end.now } (inline "${ end.inline }")` );
		assert( page.errors.length === 0, `edges: page errors: ${ page.errors.join( ' | ' ) }` );
		await page.context().close();
	}

}
