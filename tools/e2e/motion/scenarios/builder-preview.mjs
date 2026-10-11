// The Motion preview in the Bricks canvas, and the thumbnails of the effect list.
//
//  - load: Bricks calls lumiaMotionBuilder once per element (about 180 times on a real page);
//    the calls are grouped into a few scans, every animated element is in its final state
//    and nothing is pre-hidden;
//  - a changed setting of a known element replays it once, then it is final again (a replay
//    interrupted by another change ends final too);
//  - an element given an effect in the panel (no data-lm: only the canvas knows it) is found
//    by its computed --lm-effect and replayed; text scrubs replay 0 to 1 in 1.2 s and the
//    counter counts again;
//  - with the "never animate" setting nothing replays, the signature is still kept up to date;
//  - the API of the panel: replay(id) plays one element (false for an unknown id or under
//    reduced motion), inspect(id) gives the inherited values before the tempo factor,
//    markers(on) draws one start marker per item and removes them;
//  - the effect list shows animated thumbnails (14 x 14 px, one animation per effect, none
//    under reduced motion). Capture for a visual check: out/effects-list.png.
//
// The page is never saved: every change lives in the builder session only.
import { newPage, login, pageUrl, assert, withMotionSettings, OUT } from '../lib.mjs';

const EFFECTS = [ 'fade', 'slide-up', 'blur', 'blur-up', 'zoom', 'zoom-blur', 'lines', 'words', 'letters', 'counter', 'underline' ];

const stats = ( canvas ) => canvas.evaluate( () => window.lumiaMotion._debug.stats() );
const itemOf = ( canvas, id ) => canvas.evaluate( ( id ) => window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || null, id );
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/** Opens the builder on a page; counts the calls Bricks makes to lumiaMotionBuilder. */
async function openBuilder( slug, anchorId ) {
	const page = await newPage( { auth: true, width: 1600, height: 1000 } );
	await page.addInitScript( () => {
		let fn;
		window.__lmCalls = 0;
		Object.defineProperty( window, 'lumiaMotionBuilder', {
			configurable: true,
			get: () => fn,
			set: ( v ) => {
				fn = typeof v === 'function' ? function ( ...args ) {
					window.__lmCalls++;
					return v.apply( this, args );
				} : v;
			},
		} );
	} );
	await page.goto( pageUrl( slug, '?bricks=run' ), { waitUntil: 'networkidle', timeout: 90000 } );
	const canvas = page.frames().find( ( f ) => f !== page.mainFrame() );
	assert( canvas, 'no canvas frame in the builder' );
	await canvas.locator( `#brxe-${ anchorId }` ).waitFor( { timeout: 30000 } );
	// Bricks finishes rendering, then the engine and the grouped scans settle.
	await canvas.waitForFunction( () => window.lumiaMotion && window.lumiaMotion._debug.items().length > 0, null, { timeout: 15000 } );
	await sleep( 2000 );
	return { page, canvas };
}

/** Selects an element of the page from the canvas (the structure may be collapsed). */
async function select( page, canvas, id ) {
	await canvas.evaluate( ( id ) => document.getElementById( `brxe-${ id }` ).scrollIntoView( { block: 'center' } ), id );
	await canvas.locator( `#brxe-${ id }` ).click( { position: { x: 10, y: 6 }, force: true } );
	await page.locator( `#element-${ id }.active` ).waitFor( { timeout: 10000 } );
}

/** Shows the fields of the Motion group for the selected element. */
async function openMotion( page ) {
	await page.getByText( 'Style', { exact: true } ).filter( { visible: true } ).first().click();
	const startAt = page.locator( '[data-controlkey="_lmStartAt"] input' ).first();
	if ( ! ( await startAt.isVisible().catch( () => false ) ) ) {
		await page.getByText( 'Motion', { exact: true } ).filter( { visible: true } ).first().click();
	}
	await startAt.waitFor( { state: 'visible', timeout: 10000 } );
}

/** Records the lowest opacity an element goes through, from now on. */
function watchOpacity( canvas, id ) {
	return canvas.evaluate( ( id ) => {
		const el = document.getElementById( id );
		window.__minOpacity = 1;
		const tick = () => {
			window.__minOpacity = Math.min( window.__minOpacity, parseFloat( getComputedStyle( el ).opacity ) );
			requestAnimationFrame( tick );
		};
		tick();
	}, id );
}

const minOpacity = ( canvas ) => canvas.evaluate( () => window.__minOpacity );

/** Waits until the element is back to its final state: opacity 1, no transform, item settled. */
async function waitFinal( canvas, id, what ) {
	await canvas.waitForFunction( ( id ) => {
		const el = document.getElementById( id );
		const item = window.lumiaMotion._debug.items().find( ( i ) => i.id === id );
		const cs = getComputedStyle( el );
		return cs.opacity === '1' && ( cs.transform === 'none' || cs.transform === 'matrix(1, 0, 0, 1, 0, 0)' ) && cs.filter === 'none' && item && ( item.state === 'done' || item.state === 'final' );
	}, id, { timeout: 8000 } ).catch( async () => {
		throw new Error( `${ what }: ${ id } is not back to its final state: ${ JSON.stringify( await itemOf( canvas, id ) ) }` );
	} );
}

export default async function () {
	const signIn = await newPage();
	await login( signIn );

	// ---------------------------------------------------------------- load
	const { page, canvas } = await openBuilder( 'lm-e2e-basic', 'basichd' );

	// Wait for the scans to stop (unchanged for 500 ms, within 15 s): the count then is the whole load.
	let loaded = await stats( canvas );
	for ( let quiet = 0, waited = 0; quiet < 500 && waited < 15000; waited += 100 ) {
		await sleep( 100 );
		const now = await stats( canvas );
		quiet = now.scans === loaded.scans ? quiet + 100 : 0;
		loaded = now;
	}
	const calls = await canvas.evaluate( () => window.__lmCalls );
	assert( calls >= 10, `Bricks called lumiaMotionBuilder ${ calls } times: the builder script is not declared or not loaded` );
	assert( loaded.scans >= 1, 'the canvas never scanned after load' );
	assert( loaded.scans * 10 <= calls && loaded.scans <= 6, `${ calls } calls of lumiaMotionBuilder gave ${ loaded.scans } scans, expected at most one per ten calls and six in all` );
	assert( loaded.replays === 0, `${ loaded.replays } replay(s) on load, expected none` );
	console.log( `load: ${ calls } calls of lumiaMotionBuilder, ${ loaded.scans } scan(s)` );

	assert( ! ( await canvas.evaluate( () => document.documentElement.classList.contains( 'lm-js' ) ) ), 'the canvas has the lm-js pre-hide flag' );
	const listed = await canvas.evaluate( () => window.lumiaMotion._debug.items().map( ( i ) => i.id ) );
	assert( listed.length >= 8, `only ${ listed.length } animated elements found in the canvas` );
	for ( const id of listed ) {
		const shown = await canvas.evaluate( ( id ) => {
			const cs = getComputedStyle( document.getElementById( id ) );
			return { opacity: cs.opacity, transform: cs.transform, filter: cs.filter };
		}, id );
		assert( shown.opacity === '1' && shown.filter === 'none' && ( shown.transform === 'none' || shown.transform === 'matrix(1, 0, 0, 1, 0, 0)' ), `${ id } is not in its final state on load: ${ JSON.stringify( shown ) }` );
	}

	// Grouping, deterministically: 180 calls in one tick make exactly one scan.
	await sleep( 600 );
	const beforeGroup = ( await stats( canvas ) ).scans;
	await canvas.evaluate( () => {
		const els = [ ...document.querySelectorAll( '.brxe-heading' ) ];
		for ( let i = 0; i < 180; i++ ) {
			window.lumiaMotionBuilder( els[ i % els.length ] );
		}
	} );
	await sleep( 400 );
	const grouped = ( await stats( canvas ) ).scans - beforeGroup;
	assert( grouped === 1, `180 synchronous calls of lumiaMotionBuilder made ${ grouped } scan(s), expected exactly 1` );

	// ---------------------------------------------------------------- a setting changes: one replay
	await select( page, canvas, 'bpre30' );
	await openMotion( page );
	const before = await itemOf( canvas, 'brxe-bpre30' );
	assert( before, 'bpre30 is not an item of the canvas' );
	await watchOpacity( canvas, 'brxe-bpre30' );
	const base = ( await stats( canvas ) ).replays;
	const startAt = page.locator( '[data-controlkey="_lmStartAt"] input' ).first();
	await startAt.fill( '50' );
	const t0 = Date.now();
	await startAt.press( 'Tab' );
	await canvas.waitForFunction( ( base ) => window.lumiaMotion._debug.stats().replays === base + 1, base, { timeout: 500 } ).catch( async () => {
		throw new Error( `replays is ${ ( await stats( canvas ) ).replays } ${ Date.now() - t0 } ms after changing the start position, expected ${ base + 1 }` );
	} );
	console.log( `replay: started ${ Date.now() - t0 } ms after the change` );
	await waitFinal( canvas, 'brxe-bpre30', 'after the replay' );
	assert( ( await minOpacity( canvas ) ) < 1, 'the element never left opacity 1: no replay was seen' );
	const after = await itemOf( canvas, 'brxe-bpre30' );
	assert( after.signature !== before.signature, 'the signature of bpre30 did not follow the change' );
	assert( ( await stats( canvas ) ).replays === base + 1, 'more than one replay for one change' );

	// Reduced motion: the canvas shows the change at once, no replay.
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await sleep( 600 );
	{
		const b = ( await stats( canvas ) ).replays;
		await startAt.fill( '45' );
		await startAt.press( 'Tab' );
		await canvas.waitForFunction( ( sig ) => window.lumiaMotion._debug.items().find( ( i ) => i.id === 'brxe-bpre30' ).signature !== sig, ( await itemOf( canvas, 'brxe-bpre30' ) ).signature, { timeout: 2000 } ).catch( () => {
			throw new Error( 'reduced motion: the signature of bpre30 did not follow the change' );
		} );
		await sleep( 800 );
		assert( ( await stats( canvas ) ).replays === b, 'reduced motion: the canvas replayed an element' );
		assert( ( await canvas.evaluate( () => window.lumiaMotion.replay( 'bpre30' ) ) ) === false, 'reduced motion: replay(id) played the element' );
		await waitFinal( canvas, 'brxe-bpre30', 'reduced motion' );
	}
	await page.emulateMedia( { reducedMotion: 'no-preference' } );
	await sleep( 600 );

	// ---------------------------------------------------------------- the API of the panel
	{
		const b = ( await stats( canvas ) ).replays;
		assert( ( await canvas.evaluate( () => window.lumiaMotion.replay( 'nope00' ) ) ) === false, 'replay() of an unknown id returned true' );
		assert( ( await canvas.evaluate( () => window.lumiaMotion.replay( 'bpre30' ) ) ) === true, 'replay() of bpre30 returned false' );
		assert( ( await stats( canvas ) ).replays === b + 1, `replay(id): replays went from ${ b } to ${ ( await stats( canvas ) ).replays }` );
		await waitFinal( canvas, 'brxe-bpre30', 'replay(id)' );

		const seen = await canvas.evaluate( () => [ window.lumiaMotion.inspect( 'bpre01' ), window.lumiaMotion.inspect( 'nope00' ) ] );
		assert( seen[ 1 ] === null, 'inspect() of an unknown id is not null' );
		const o = seen[ 0 ];
		assert( o && o.effect === 'slide-up' && o.tempo === 'normal' && o.easeKey === 'smooth', `inspect(bpre01): ${ JSON.stringify( o ) }` );
		assert( Math.abs( o.stagger - 0.15 ) < 1e-9 && o.distance === 48 && o.delay === 0, `inspect(bpre01) does not give the class stagger and the slide-up values: ${ JSON.stringify( o ) }` );
		// bprefb is "fast": the time values are given as a field holds them, before the tempo factor.
		const fast = await canvas.evaluate( () => window.lumiaMotion.inspect( 'bprefb' ) );
		assert( fast.tempo === 'fast' && Math.abs( fast.stagger - 0.15 ) < 1e-9, `inspect(bprefb) scaled the stagger by the tempo: ${ JSON.stringify( fast ) }` );

		const marks = ( on ) => canvas.evaluate( ( on ) => {
			window.lumiaMotion.markers( on );
			return document.querySelectorAll( '.gsap-marker-start' ).length;
		}, on );
		const items = ( await canvas.evaluate( () => window.lumiaMotion._debug.items().length ) );
		assert( ( await marks( true ) ) === items, `markers(true) drew ${ await canvas.evaluate( () => document.querySelectorAll( '.gsap-marker-start' ).length ) } start markers for ${ items } items` );
		assert( ( await marks( false ) ) === 0, 'markers(false) left markers in the canvas' );
	}

	// ---------------------------------------------------------------- a new element gets an effect
	const known = await canvas.evaluate( () => [ ...document.querySelectorAll( '.brxe-heading' ) ].map( ( e ) => e.id ) );
	await select( page, canvas, 'basichd' );
	await page.locator( '#bricks-toolbar-top li.elements' ).click();
	await page.locator( 'li.bricks-add-element' ).filter( { hasText: /^Heading$/ } ).first().click();
	const fresh = await canvas.waitForFunction( ( known ) => {
		const el = [ ...document.querySelectorAll( '.brxe-heading' ) ].find( ( e ) => ! known.includes( e.id ) );
		return el ? el.id : false;
	}, known, { timeout: 10000 } ).then( ( h ) => h.jsonValue() );
	const freshId = fresh.replace( /^brxe-/, '' );
	assert( ! ( await itemOf( canvas, fresh ) ), 'the new heading is an item before any effect is chosen' );
	await sleep( 600 );
	await page.locator( `#element-${ freshId } .structure-item` ).click();
	await page.locator( `#element-${ freshId }.active` ).waitFor();
	await openMotion( page );

	// The effect list: thumbnails.
	const effect = page.locator( '[data-controlkey="_lmEffect"]' ).first();
	await effect.locator( '.input' ).first().click();
	const list = effect.locator( '.dropdown' );
	await list.waitFor( { state: 'visible' } );
	const thumbs = await page.evaluate( () => {
		const out = {};
		document.querySelectorAll( '[data-controlkey="_lmEffect"] .lm-thumb' ).forEach( ( t ) => {
			const slug = [ ...t.classList ].find( ( c ) => c.startsWith( 'lm-thumb--' ) ).slice( 10 );
			const cs = getComputedStyle( t );
			out[ slug ] = { w: cs.width, h: cs.height, animation: cs.animationName, display: cs.display };
		} );
		return out;
	} );
	const names = new Set();
	for ( const slug of EFFECTS ) {
		const t = thumbs[ slug ];
		assert( t, `no thumbnail for ${ slug } in the effect list` );
		assert( t.w === '14px' && t.h === '14px', `thumbnail ${ slug } is ${ t.w } x ${ t.h }, expected 14px x 14px` );
		assert( t.animation && t.animation !== 'none', `thumbnail ${ slug } has no animation` );
		names.add( t.animation );
	}
	assert( names.size === EFFECTS.length, `the thumbnails share their animations: ${ [ ...names ].join( ', ' ) }` );
	// Capture for a visual check: every thumbnail held at its final state (1.6 s into the loop), then released.
	const holdAll = ( ms ) => page.evaluate( ( ms ) => document.getAnimations().forEach( ( a ) => {
		if ( a.animationName && a.animationName.startsWith( 'lm-thumb-' ) ) {
			a.pause();
			a.currentTime = ms;
		}
	} ), ms );
	await holdAll( 1600 );
	await list.screenshot( { path: OUT + 'effects-list.png' } );
	await page.evaluate( () => document.getAnimations().forEach( ( a ) => a.animationName && a.animationName.startsWith( 'lm-thumb-' ) && a.play() ) );
	// A contact sheet at 6x: one row per effect, one column per moment of the loop (start, entering, final, leaving).
	const moments = [ 200, 650, 1600, 2600 ];
	await page.evaluate( ( [ slugs, moments ] ) => {
		const sheet = document.createElement( 'div' );
		sheet.id = 'lm-sheet';
		sheet.style.cssText = 'position:fixed;left:0;top:0;z-index:99999;display:flex;gap:24px;padding:16px;background:#2b3440;color:#dbe1e8;font:12px sans-serif';
		moments.forEach( ( ms ) => {
			const col = document.createElement( 'div' );
			col.dataset.ms = ms;
			col.innerHTML = `<div style="margin-bottom:8px">${ ms } ms</div>` + slugs.map( ( slug ) => `<div style="height:22px;zoom:6"><span class="lm-thumb lm-thumb--${ slug }"></span></div>` ).join( '' );
			sheet.appendChild( col );
		} );
		document.body.appendChild( sheet );
		sheet.querySelectorAll( 'div[data-ms]' ).forEach( ( col ) => {
			col.getAnimations( { subtree: true } ).forEach( ( a ) => {
				a.pause();
				a.currentTime = Number( col.dataset.ms );
			} );
		} );
	}, [ EFFECTS, moments ] );
	await page.locator( '#lm-sheet' ).screenshot( { path: OUT + 'effects-sheet.png' } );
	await page.evaluate( () => document.getElementById( 'lm-sheet' ).remove() );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	const still = await page.evaluate( () => [ ...document.querySelectorAll( '[data-controlkey="_lmEffect"] .lm-thumb' ) ].map( ( t ) => getComputedStyle( t ).animationName ) );
	assert( still.length === EFFECTS.length && still.every( ( n ) => n === 'none' ), `thumbnails still animate under reduced motion: ${ still.join( ', ' ) }` );
	await page.emulateMedia( { reducedMotion: 'no-preference' } );

	const baseNew = ( await stats( canvas ) ).replays;
	await list.getByText( 'Slide up', { exact: true } ).click();
	await canvas.waitForFunction( ( id ) => window.lumiaMotion._debug.items().some( ( i ) => i.id === id ), fresh, { timeout: 3000 } ).catch( () => {
		throw new Error( `the new heading ${ fresh } is not an item after choosing Slide up` );
	} );
	const created = await itemOf( canvas, fresh );
	assert( created.effect === 'slide-up', `the new heading has effect "${ created.effect }", expected slide-up` );
	await canvas.waitForFunction( ( b ) => window.lumiaMotion._debug.stats().replays === b + 1, baseNew, { timeout: 1000 } ).catch( async () => {
		throw new Error( `choosing Slide up on a new heading: replays is ${ ( await stats( canvas ) ).replays }, expected ${ baseNew + 1 }` );
	} );
	await waitFinal( canvas, fresh, 'new heading' );
	console.log( `new heading: data-lm ${ ( await canvas.evaluate( ( id ) => document.getElementById( id ).hasAttribute( 'data-lm' ), fresh ) ) ? 'present' : 'absent' } after Slide up` );

	// ---------------------------------------------------------------- elements the PHP marker never saw
	// An element added to the canvas, then given an effect by a stylesheet only: no data-lm at all.
	for ( const [ slug, text, css, check ] of [
		[ 'words', 'Words that light up one after the other', '--lm-effect: words', 'words' ],
		[ 'lines', 'Lines of text that rise from a mask as the heading replays after a change in the panel', '--lm-effect: lines; --lm-duration: 0.6', 'lines' ],
		[ 'counter', '1200', '--lm-effect: counter; --lm-tempo: custom; --lm-duration: 1.2', 'counter' ],
	] ) {
		const id = `brxe-lm${ slug.replace( /\W/g, '' ) }`;
		await canvas.evaluate( ( [ id, text ] ) => {
			const p = document.createElement( 'p' );
			p.id = id;
			p.className = 'brxe-text-basic';
			p.style.fontSize = '24px';
			p.style.width = '300px';
			p.textContent = text;
			document.body.appendChild( p );
		}, [ id, text ] );
		await sleep( 500 );
		assert( ! ( await itemOf( canvas, id ) ), `${ id } is an item before it has an effect` );
		const b = ( await stats( canvas ) ).replays;
		await canvas.evaluate( ( [ id, css ] ) => {
			const style = document.createElement( 'style' );
			style.id = `${ id }-css`;
			style.textContent = `#${ id } { ${ css } }`;
			document.head.appendChild( style );
		}, [ id, css ] );
		await canvas.waitForFunction( ( [ id, b ] ) => window.lumiaMotion._debug.stats().replays === b + 1 && window.lumiaMotion._debug.items().some( ( i ) => i.id === id ), [ id, b ], { timeout: 1500 } ).catch( async () => {
			throw new Error( `${ slug }: no replay after the stylesheet gave ${ id } an effect (replays ${ ( await stats( canvas ) ).replays }, item ${ JSON.stringify( await itemOf( canvas, id ) ) })` );
		} );
		assert( ! ( await canvas.evaluate( ( id ) => document.getElementById( id ).hasAttribute( 'data-lm' ), id ) ), `${ id } has a data-lm attribute: the test does not prove the detection` );

		// Sample the element while it replays.
		const seen = await canvas.evaluate( ( [ id, check ] ) => new Promise( ( resolve ) => {
			const el = document.getElementById( id );
			const out = { pieces: 0, dim: false, texts: [], end: 0 };
			const t0 = performance.now();
			const tick = () => {
				const pieces = el.querySelectorAll( check === 'words' ? '.lm-word' : '.lm-line' );
				out.pieces = Math.max( out.pieces, pieces.length );
				if ( check === 'words' ) {
					pieces.forEach( ( w ) => {
						if ( parseFloat( getComputedStyle( w ).opacity ) < 0.99 ) {
							out.dim = true;
						}
					} );
				}
				if ( check === 'counter' ) {
					if ( ! out.texts.includes( el.textContent ) ) {
						out.texts.push( el.textContent );
					}
				}
				const live = el.querySelector( '.lm-word, .lm-line, .lm-char' ) || ( check === 'counter' && el.querySelector( '[aria-hidden]' ) );
				if ( live ) {
					out.end = performance.now() - t0;
				}
				if ( performance.now() - t0 > 4000 ) {
					return resolve( out );
				}
				requestAnimationFrame( tick );
			};
			tick();
		} ), [ id, check ] );
		if ( check === 'words' ) {
			assert( seen.pieces > 3 && seen.dim, `${ slug }: the words never dimmed during the replay (${ JSON.stringify( seen ) })` );
			assert( seen.end >= 1000 && seen.end <= 1900, `${ slug }: the scrub replay lasted ${ Math.round( seen.end ) } ms, expected about 1200` );
		}
		if ( check === 'lines' ) {
			assert( seen.pieces >= 2, `${ slug }: the text was never split into lines (${ JSON.stringify( seen ) })` );
		}
		if ( check === 'counter' ) {
			assert( seen.texts.length > 3, `${ slug }: the counter never counted (${ seen.texts.join( ' ' ) })` );
		}
		const end = await canvas.evaluate( ( id ) => {
			const el = document.getElementById( id );
			return { text: el.textContent, pieces: el.querySelectorAll( '.lm-word, .lm-line, .lm-char, .lm-sr' ).length, opacity: getComputedStyle( el ).opacity };
		}, id );
		assert( end.text === text && end.pieces === 0, `${ slug }: the element is not back as written after the replay: ${ JSON.stringify( end ) }` );
	}

	// A replay interrupted by another change ends in the final state.
	{
		const id = 'brxe-lmcut';
		await canvas.evaluate( ( id ) => {
			const p = document.createElement( 'p' );
			p.id = id;
			p.className = 'brxe-text-basic';
			p.textContent = 'Cut short in the middle of the replay';
			document.body.appendChild( p );
		}, id );
		await sleep( 500 );
		await canvas.evaluate( ( id ) => {
			const style = document.createElement( 'style' );
			style.id = `${ id }-css`;
			style.textContent = `#${ id } { --lm-effect: letters; }`;
			document.head.appendChild( style );
		}, id );
		await canvas.waitForFunction( ( id ) => document.querySelector( `#${ id } .lm-char` ), id, { timeout: 2000 } );
		await canvas.evaluate( ( id ) => {
			document.getElementById( `${ id }-css` ).textContent = `#${ id } { --lm-effect: none; }`;
		}, id );
		await canvas.waitForFunction( ( id ) => ! document.querySelector( `#${ id } .lm-char, #${ id } .lm-sr` ), id, { timeout: 2000 } ).catch( () => {
			throw new Error( 'a replay interrupted by "none" left split text in the canvas' );
		} );
		const end = await canvas.evaluate( ( id ) => {
			const el = document.getElementById( id );
			return { text: el.textContent, opacity: getComputedStyle( el ).opacity };
		}, id );
		assert( end.text === 'Cut short in the middle of the replay' && end.opacity === '1', `an interrupted replay did not end final: ${ JSON.stringify( end ) }` );
		assert( ! ( await itemOf( canvas, id ) ), 'an element set to none is still an item' );
	}

	// Nothing stays hidden anywhere in the canvas.
	await sleep( 1500 );
	const hidden = await canvas.evaluate( () => window.lumiaMotion._debug.items().filter( ( i ) => {
		const cs = getComputedStyle( document.getElementById( i.id ) );
		return cs.opacity !== '1' || i.state === 'pending' || i.state === 'playing';
	} ).map( ( i ) => i.id ) );
	assert( hidden.length === 0, `elements left hidden or playing in the canvas: ${ hidden.join( ', ' ) }` );
	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );

	// destroy(): the builder script lets go of its observers and stops scanning.
	await canvas.evaluate( () => {
		window.__disconnects = 0;
		const original = MutationObserver.prototype.disconnect;
		MutationObserver.prototype.disconnect = function () {
			window.__disconnects++;
			return original.call( this );
		};
		window.lumiaMotion.destroy();
	} );
	assert( ( await canvas.evaluate( () => window.__disconnects ) ) >= 1, 'destroy() did not disconnect the builder observers' );
	const afterDestroy = await stats( canvas );
	await canvas.evaluate( () => window.lumiaMotionBuilder( document.body ) );
	await sleep( 400 );
	assert( ( await stats( canvas ) ).scans === afterDestroy.scans, 'a scan ran after destroy()' );
	await page.context().close();

	// ---------------------------------------------------------------- "never animate"
	await withMotionSettings( { builder_preview: 'off' }, async () => {
		const off = await openBuilder( 'lm-e2e-basic', 'basichd' );
		assert( ( await off.canvas.evaluate( () => window.lumiaMotionConfig.preview ) ) === 'off', 'the preview setting did not reach the canvas' );
		await select( off.page, off.canvas, 'bpre30' );
		await openMotion( off.page );
		const was = await itemOf( off.canvas, 'brxe-bpre30' );
		const input = off.page.locator( '[data-controlkey="_lmStartAt"] input' ).first();
		await input.fill( '50' );
		await input.press( 'Tab' );
		await off.canvas.waitForFunction( ( was ) => {
			const i = window.lumiaMotion._debug.items().find( ( i ) => i.id === 'brxe-bpre30' );
			return i && i.signature !== was;
		}, was.signature, { timeout: 2000 } ).catch( () => {
			throw new Error( 'preview off: the signature of bpre30 did not follow the change' );
		} );
		await sleep( 1000 );
		const s = await stats( off.canvas );
		assert( s.replays === 0, `preview off: ${ s.replays } replay(s), expected none` );
		assert( ( await off.canvas.evaluate( () => getComputedStyle( document.getElementById( 'brxe-bpre30' ) ).opacity ) ) === '1', 'preview off: the element is not shown' );
		assert( off.page.errors.length === 0, `page errors: ${ off.page.errors.join( ' | ' ) }` );
		await off.page.context().close();
	} );
}
