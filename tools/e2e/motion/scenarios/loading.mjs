// Conditional loading of the Motion assets: nothing on a page with no animation, GSAP and
// ScrollTrigger on an animated page, SplitText only for a text effect, no foreign host, the
// 3 s safety that makes everything visible when the engine does not start, the builder
// windows, and the former snippet still being active.
import { newPage, login, pageUrl, assert, snippet, BASE } from '../lib.mjs';

const HOST = new URL( BASE ).host;
const GSAP_FILES = /gsap|ScrollTrigger|SplitText|\/motion\.js(\?|$)/i;
const SNIPPET = '4-sk-motion.php';
const FORMER_CDN = 'https://cdn.jsdelivr.net/**';

/** Waits until the pages carry the former snippet (or no longer do): PHP's opcache can serve the old snippet index for a couple of seconds. */
async function waitForSnippet( present ) {
	for ( let i = 0; i < 40; i++ ) {
		const html = await ( await fetch( pageUrl( 'lm-e2e-basic' ) ) ).text();
		if ( html.includes( 'cdn.jsdelivr.net' ) === present ) {
			return;
		}
		await new Promise( ( r ) => setTimeout( r, 500 ) );
	}
	throw new Error( `the former snippet never ${ present ? 'appeared on' : 'left' } the page` );
}

/** Loads a page and returns the URLs requested while it loaded. */
async function load( page, slug, query = '' ) {
	const urls = [];
	page.on( 'request', ( r ) => urls.push( r.url() ) );
	const res = await page.goto( pageUrl( slug, query ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ slug }: status ${ res && res.status() }` );
	return urls;
}

const globals = ( page ) => page.evaluate( () => ( {
	gsap: typeof window.gsap,
	scrollTrigger: typeof window.ScrollTrigger,
	splitText: typeof window.SplitText,
	config: window.lumiaMotionConfig || null,
} ) );

export default async function () {
	// A page with no animation loads none of the engine files, but the stylesheet and the flag are there.
	{
		const page = await newPage();
		const urls = await load( page, 'lm-e2e-none' );
		const engine = urls.filter( ( u ) => GSAP_FILES.test( u ) );
		assert( engine.length === 0, `lm-e2e-none requested engine files: ${ engine.join( ', ' ) }` );
		const g = await globals( page );
		assert( g.gsap === 'undefined', `lm-e2e-none: window.gsap is ${ g.gsap }` );
		assert( await page.locator( 'link[href*="/front/css/motion.css"]' ).count() === 1, 'lm-e2e-none: motion.css is not linked' );
		assert( await page.evaluate( () => document.documentElement.classList.contains( 'lm-js' ) ), 'lm-e2e-none: <html> has no lm-js' );
		assert( page.errors.length === 0, `lm-e2e-none: page errors: ${ page.errors.join( ' | ' ) }` );
		assert( urls.every( ( u ) => ! u.startsWith( 'http' ) || new URL( u ).host === HOST ), `lm-e2e-none: foreign host: ${ urls.filter( ( u ) => u.startsWith( 'http' ) && new URL( u ).host !== HOST ).join( ', ' ) }` );
	}

	// An animated page: GSAP and ScrollTrigger, the engine and its configuration, no SplitText.
	{
		const page = await newPage();
		const urls = await load( page, 'lm-e2e-basic' );
		for ( const file of [ 'gsap.min.js', 'ScrollTrigger.min.js', 'motion-core.js', 'motion.js' ] ) {
			assert( urls.some( ( u ) => u.includes( `/${ file }` ) ), `lm-e2e-basic: ${ file } not requested` );
		}
		assert( ! urls.some( ( u ) => u.includes( 'SplitText' ) ), 'lm-e2e-basic: SplitText requested without a text effect' );
		const g = await globals( page );
		assert( g.gsap === 'object' && g.scrollTrigger === 'function', `lm-e2e-basic: gsap=${ g.gsap } ScrollTrigger=${ g.scrollTrigger }` );
		assert( g.splitText === 'undefined', `lm-e2e-basic: window.SplitText is ${ g.splitText }` );

		const config = g.config;
		assert( config, 'lm-e2e-basic: no window.lumiaMotionConfig' );
		assert( config.builder === false, `config.builder=${ config.builder }` );
		assert( config.preview === 'replay', `config.preview=${ config.preview }` );
		assert( config.tempo === 'normal' && config.ease === 'lumia', `config tempo=${ config.tempo } ease=${ config.ease }` );
		assert( config.effects && config.effects.reveal && config.effects.reveal.distance === 48, 'config.effects.reveal.distance is not 48' );
		assert( config.classes[ 'lm-fade' ] === 'fade' && config.classes[ 'lm-text-words' ] === 'text-words', 'config.classes does not map lm-fade / lm-text-words' );
		assert( config.aliases[ 'sk-fade' ] === 'fade', `config.aliases['sk-fade']=${ config.aliases[ 'sk-fade' ] }` );
		assert( typeof config.legacyGlobal === 'string' && config.legacyGlobal !== '', 'config.legacyGlobal is empty' );
		assert( Array.isArray( config.variables ) && config.variables.length === 18 && config.variables.includes( 'lm-effect' ), 'config.variables is not the 18 engine variables' );

		await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 5000 } );
		assert( page.errors.length === 0, `lm-e2e-basic: page errors: ${ page.errors.join( ' | ' ) }` );
		assert( urls.every( ( u ) => ! u.startsWith( 'http' ) || new URL( u ).host === HOST ), `lm-e2e-basic: foreign host: ${ urls.filter( ( u ) => u.startsWith( 'http' ) && new URL( u ).host !== HOST ).join( ', ' ) }` );
	}

	// A text effect adds SplitText.
	{
		const page = await newPage();
		const urls = await load( page, 'lm-e2e-text' );
		assert( urls.some( ( u ) => u.includes( '/SplitText.min.js' ) ), 'lm-e2e-text: SplitText not requested' );
		const g = await globals( page );
		assert( g.splitText === 'function' || g.splitText === 'object', `lm-e2e-text: window.SplitText is ${ g.splitText }` );
		assert( urls.every( ( u ) => ! u.startsWith( 'http' ) || new URL( u ).host === HOST ), `lm-e2e-text: foreign host: ${ urls.filter( ( u ) => u.startsWith( 'http' ) && new URL( u ).host !== HOST ).join( ', ' ) }` );
	}

	// The engine never starts (script blocked): the element is hidden at first, then the 3 s safety shows everything.
	{
		const page = await newPage();
		await page.route( /\/motion\.js(\?|$)/, ( route ) => route.abort() );
		await load( page, 'lm-e2e-basic' );
		const state = () => page.evaluate( () => ( {
			ready: document.documentElement.classList.contains( 'lm-ready' ),
			// Every marked element at the end; at the start, those with an entrance effect, the only ones the stylesheet hides.
			opacities: [ ...document.querySelectorAll( '[data-lm]' ) ].map( ( n ) => getComputedStyle( n ).opacity ),
			entering: [ ...document.querySelectorAll( '[data-lm~="enter"]' ) ].map( ( n ) => getComputedStyle( n ).opacity ),
		} ) );
		await page.waitForTimeout( 800 );
		const early = await state();
		assert( ! early.ready, 'safety: lm-ready is set before 3 s without the engine' );
		assert( early.entering.length > 0 && early.entering.every( ( o ) => o === '0' ), `safety: the pre-hide is not active (opacity ${ early.entering.join( ',' ) })` );
		await page.waitForTimeout( 2400 );
		const late = await state();
		assert( late.ready, 'safety: <html> has no lm-ready after 3.2 s without the engine' );
		assert( late.opacities.every( ( o ) => o === '1' ), `safety: an element is still hidden (opacity ${ late.opacities.join( ',' ) })` );
	}

	// Builder: the main window gets the builder stylesheet only; the canvas gets the engine.
	{
		const signIn = await newPage();
		await login( signIn );
		const page = await newPage( { auth: true, width: 1600, height: 1000 } );
		const urls = [];
		page.on( 'request', ( r ) => urls.push( r.url() ) );
		await page.goto( pageUrl( 'lm-e2e-smoke', '?bricks=run' ), { waitUntil: 'networkidle', timeout: 90000 } );

		const main = await page.evaluate( () => ( {
			builderCss: document.querySelectorAll( 'link[href*="/front/css/motion-builder.css"]' ).length,
			frontCss: document.querySelectorAll( 'link[href*="/front/css/motion.css"]' ).length,
			engine: document.querySelectorAll( 'script[src*="/front/js/"]' ).length,
			config: typeof window.lumiaMotionConfig,
			gsap: typeof window.gsap,
			lenis: document.querySelectorAll( 'link[href*="lenis"], script[src*="lenis"], script[src*="motion-scroll"]' ).length,
		} ) );
		assert( main.builderCss === 1, `builder main: motion-builder.css linked ${ main.builderCss } times` );
		assert( main.frontCss === 0, 'builder main: motion.css is linked' );
		assert( main.engine === 0 && main.config === 'undefined' && main.gsap === 'undefined', `builder main: engine loaded (scripts=${ main.engine } config=${ main.config } gsap=${ main.gsap })` );

		assert( main.lenis === 0, `builder main: ${ main.lenis } smooth scroll file(s) loaded` );

		const canvas = page.frames().find( ( f ) => f !== page.mainFrame() );
		assert( canvas, 'builder: no canvas frame' );
		await canvas.waitForFunction( () => window.lumiaMotionConfig, null, { timeout: 30000 } );
		const inner = await canvas.evaluate( () => ( {
			builder: window.lumiaMotionConfig.builder,
			gsap: typeof window.gsap,
			splitText: typeof window.SplitText,
			engine: !! window.lumiaMotion,
			lenis: document.querySelectorAll( 'link[href*="lenis"], script[src*="lenis"], script[src*="motion-scroll"]' ).length,
			lenisInstance: typeof window.lumiaLenis,
		} ) );
		assert( inner.builder === true, 'builder canvas: config.builder is not true' );
		assert( inner.gsap === 'object' && inner.splitText !== 'undefined', `builder canvas: gsap=${ inner.gsap } SplitText=${ inner.splitText }` );
		assert( inner.engine, 'builder canvas: the engine did not start' );
		assert( inner.lenis === 0 && inner.lenisInstance === 'undefined', `builder canvas: smooth scroll loaded (files=${ inner.lenis } lumiaLenis=${ inner.lenisInstance })` );
	}

	// The former snippet still active: the module stays out of its way, says so, and shows the content.
	try {
		snippet( SNIPPET, 'published' );
		await waitForSnippet( true );
		const page = await newPage();
		// The former engine comes from a CDN; the stand-in only defines its global, which is what the module looks at.
		await page.route( FORMER_CDN, ( route ) => route.fulfill( { contentType: 'application/javascript', body: 'window.StudioKyneMotion = {};' } ) );
		const warnings = [];
		page.on( 'console', ( m ) => m.type() === 'warning' && warnings.push( m.text() ) );
		await load( page, 'lm-e2e-basic' );
		await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ), null, { timeout: 5000 } );
		const state = await page.evaluate( () => ( {
			former: typeof window.StudioKyneMotion,
			debug: !! ( window.lumiaMotion && window.lumiaMotion._debug && Object.keys( window.lumiaMotion._debug ).length ),
			opacity: getComputedStyle( document.querySelector( '[data-lm~="enter"]' ) ).opacity,
		} ) );
		assert( state.former === 'object', 'snippet: the former engine stand-in was not loaded' );
		assert( ! state.debug, 'snippet: the Motion engine started next to the former one' );
		assert( state.opacity === '1', `snippet: the animated element is hidden (opacity ${ state.opacity })` );
		assert( warnings.some( ( w ) => w.includes( 'Lumia Motion' ) && w.includes( 'StudioKyneMotion' ) ), `snippet: no console warning from the module (${ warnings.join( ' | ' ) || 'none' })` );
	} finally {
		snippet( SNIPPET, 'draft' );
		await waitForSnippet( false ).catch( () => {} );
	}
}
