// Text effects: lines revealed under a mask then given back exactly as written, words and
// characters lit by the scroll, what screen readers get while the text is split, a split
// that follows a change of width, scrubs near the bottom of the page, elements already
// passed, and reduced motion.
import { newPage, pageUrl, assert, items } from '../lib.mjs';

const SLUG = 'lm-e2e-text';
const IDS = [ 'brxe-texthd', 'brxe-txlnhd', 'brxe-txlnpp', 'brxe-txword', 'brxe-txchar', 'brxe-txwlat', 'brxe-txllat' ];
const SPLIT = '.lm-line, .lm-word, .lm-char';

/** Opens the page and waits for the engine. */
async function open( o = {} ) {
	const page = await newPage( o );
	const res = await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `${ SLUG }: status ${ res && res.status() }` );
	await page.waitForFunction(
		() => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug,
		null,
		{ timeout: 5000 }
	).catch( () => {
		throw new Error( `${ SLUG }: the engine did not start` );
	} );
	return page;
}

/** Markup of the elements as the server sent it, before the engine touched anything. */
const originals = ( page ) => page.evaluate( async ( ids ) => {
	const html = await ( await fetch( location.href ) ).text();
	const doc = new DOMParser().parseFromString( html, 'text/html' );
	const out = {};
	ids.forEach( ( id ) => {
		const node = doc.getElementById( id );
		out[ id ] = node ? { html: node.innerHTML, text: node.textContent.trim(), attrs: node.getAttributeNames().sort().join() } : null;
	} );
	return out;
}, IDS );

/** What assistive technologies get for each element (Playwright's ARIA snapshot). */
async function aria( page ) {
	const out = {};
	for ( const id of IDS ) {
		out[ id ] = await page.locator( `#${ id }` ).ariaSnapshot();
	}
	return out;
}

const state = async ( page, id ) => ( ( await items( page ) ).find( ( i ) => i.id === id ) || {} ).state;

/** Scroll position of a scrub at a given progress (0 to 1), from its live trigger. */
const scrubAt = ( page, id, progress ) => page.evaluate( ( [ id, progress ] ) => {
	const node = document.getElementById( id );
	const st = ScrollTrigger.getAll().find( ( t ) => t.trigger === node );
	return st ? { y: st.start + ( st.end - st.start ) * progress, start: st.start, end: st.end } : null;
}, [ id, progress ] );

/** Scrolls and lets ScrollTrigger render the scrubs. */
async function scrollTo( page, y ) {
	await page.evaluate( ( y ) => window.scrollTo( 0, y ), y );
	await page.waitForTimeout( 150 );
}

/** Opacity and inline will-change of every split piece of an element. */
const pieces = ( page, id, selector ) => page.evaluate( ( [ id, selector ] ) => [ ...document.getElementById( id ).querySelectorAll( selector ) ].map( ( n ) => ( {
	opacity: parseFloat( getComputedStyle( n ).opacity ),
	willChange: n.style.willChange,
	nested: !! n.parentElement.closest( selector ),
} ) ), [ id, selector ] );

/** Every element's markup and attributes are back to what the server sent. */
async function untouched( page, orig, ids, label ) {
	const now = await page.evaluate( ( ids ) => {
		const out = {};
		ids.forEach( ( id ) => {
			const node = document.getElementById( id );
			out[ id ] = { html: node.innerHTML, attrs: node.getAttributeNames().sort().join() };
		} );
		return out;
	}, ids );
	ids.forEach( ( id ) => {
		assert( now[ id ].html === orig[ id ].html, `${ label }: ${ id } markup changed:\n  was ${ orig[ id ].html }\n  now ${ now[ id ].html }` );
		assert( now[ id ].attrs === orig[ id ].attrs, `${ label }: ${ id } attributes ${ now[ id ].attrs } (was ${ orig[ id ].attrs })` );
	} );
}

/** Reduced motion: nothing split, nothing changed. Returns the ARIA snapshots of the plain page, the reference. */
async function reduced() {
	const page = await open( { reducedMotion: 'reduce' } );
	await page.waitForTimeout( 300 );
	const orig = await originals( page );
	const split = await page.evaluate( ( sel ) => document.querySelectorAll( sel ).length, SPLIT );
	assert( split === 0, `reduced: ${ split } split piece(s) in the page` );
	await untouched( page, orig, IDS, 'reduced' );
	const reference = await aria( page );
	assert( page.errors.length === 0, `reduced: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
	return reference;
}

async function lines( reference ) {
	const page = await open();
	const orig = await originals( page );

	// Split while pending, every line under its mask, and screen readers still get the text as written.
	for ( const id of [ 'brxe-txlnhd', 'brxe-txlnpp' ] ) {
		assert( ( await state( page, id ) ) === 'pending', `${ id }: state ${ await state( page, id ) } before scrolling to it` );
		const hidden = await page.evaluate( ( id ) => [ ...document.getElementById( id ).querySelectorAll( '.lm-line' ) ].map( ( n ) => new DOMMatrix( getComputedStyle( n ).transform ).m42 ), id );
		assert( hidden.length > 0, `${ id }: no .lm-line while pending` );
		assert( hidden.every( ( y ) => y > 0 ), `${ id }: lines not moved under their mask while pending (${ hidden.join( ', ' ) })` );
	}
	const pending = await aria( page );
	for ( const id of [ 'brxe-txlnhd', 'brxe-txlnpp' ] ) {
		assert( pending[ id ] === reference[ id ], `${ id }: screen readers get\n${ pending[ id ] }\ninstead of\n${ reference[ id ] }` );
	}
	assert( await page.getByRole( 'heading', { level: 2, name: orig[ 'brxe-txlnhd' ].text, exact: true } ).count() === 1, 'h2: no heading named after its text while split' );

	// A change of width splits the paragraph again: the new lines are hidden too, then animate.
	const before = await page.evaluate( () => document.querySelectorAll( '#brxe-txlnpp .lm-line' ).length );
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.waitForFunction( ( n ) => document.querySelectorAll( '#brxe-txlnpp .lm-line' ).length > n, before, { timeout: 3000 } ).catch( () => {
		throw new Error( `txlnpp: still ${ before } lines 3 s after the screen got narrower (no new split)` );
	} );
	const narrow = await page.evaluate( () => [ ...document.querySelectorAll( '#brxe-txlnpp .lm-line' ) ].map( ( n ) => new DOMMatrix( getComputedStyle( n ).transform ).m42 ) );
	assert( narrow.every( ( y ) => y > 0 ), `txlnpp: new lines visible before their entrance (${ narrow.join( ', ' ) })` );
	assert( ( await page.evaluate( () => document.querySelectorAll( '#brxe-txlnpp .lm-line .lm-line' ).length ) ) === 0, 'txlnpp: lines split inside lines' );

	// Every frame of both entrances: lines exist and move, then the markup is given back.
	await page.evaluate( () => {
		window.lmFrames = { 'brxe-txlnhd': [], 'brxe-txlnpp': [] };
		const tick = () => {
			Object.keys( window.lmFrames ).forEach( ( id ) => {
				const first = document.querySelector( `#${ id } .lm-line` );
				window.lmFrames[ id ].push( { lines: document.querySelectorAll( `#${ id } .lm-line` ).length, y: first ? new DOMMatrix( getComputedStyle( first ).transform ).m42 : null } );
			} );
			if ( ! window.lmStop ) {
				requestAnimationFrame( tick );
			}
		};
		requestAnimationFrame( tick );
	} );
	for ( const id of [ 'brxe-txlnhd', 'brxe-txlnpp' ] ) {
		await page.evaluate( ( id ) => document.getElementById( id ).scrollIntoView( { block: 'center' } ), id );
		await page.waitForFunction( ( id ) => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || {} ).state === 'done', id, { timeout: 5000 } ).catch( () => {
			throw new Error( `${ id }: its entrance never finished` );
		} );
	}
	const frames = await page.evaluate( () => {
		window.lmStop = true;
		return window.lmFrames;
	} );
	for ( const [ id, list ] of Object.entries( frames ) ) {
		const moving = list.filter( ( f ) => f.lines > 0 && f.y !== null && f.y > 0.5 );
		const landed = list.filter( ( f ) => f.lines > 0 && f.y !== null && Math.abs( f.y ) <= 0.5 );
		assert( moving.length >= 3, `${ id }: ${ moving.length } frame(s) with a line under its mask out of ${ list.length }` );
		assert( landed.length + list.filter( ( f ) => f.lines === 0 ).length > 0, `${ id }: never landed` );
	}
	await untouched( page, orig, [ 'brxe-txlnhd', 'brxe-txlnpp' ], 'lines at the end' );

	assert( page.errors.length === 0, `lines: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** Opacities along the words at a progress follow the scroll: lit from the first word, in order. */
function follows( list, progress, from, label ) {
	const o = list.map( ( p ) => p.opacity );
	for ( let i = 1; i < o.length; i++ ) {
		assert( o[ i ] <= o[ i - 1 ] + 0.001, `${ label } at ${ progress }: word ${ i } brighter than word ${ i - 1 } (${ o[ i ] } > ${ o[ i - 1 ] })` );
	}
	if ( progress === 0 ) {
		assert( o.every( ( v ) => Math.abs( v - from ) < 0.01 ), `${ label } before its start: opacities ${ [ ...new Set( o ) ].join( ', ' ) } (expected ${ from })` );
	} else if ( progress === 1 ) {
		assert( o.every( ( v ) => v >= 0.999 ), `${ label } after its end: opacities ${ [ ...new Set( o ) ].join( ', ' ) } (expected 1)` );
	} else {
		assert( o[ 0 ] > from + 0.05 && o[ o.length - 1 ] < 0.95, `${ label } at ${ progress }: first ${ o[ 0 ] }, last ${ o[ o.length - 1 ] } (expected lit at the start, dim at the end)` );
	}
	return o.filter( ( v ) => v >= 0.999 ).length;
}

async function words( reference ) {
	const page = await open();
	const orig = await originals( page );
	const ID = 'brxe-txword';
	const from = ( ( await items( page ) ).find( ( i ) => i.id === ID ) || { options: {} } ).options.fromOpacity;
	assert( Math.abs( from - 0.08 ) < 1e-6, `words: starting opacity ${ from } (expected 0.08)` );

	// Before its start: every word at the starting opacity, no will-change.
	const count = ( await pieces( page, ID, '.lm-word' ) ).length;
	assert( count > 20, `words: ${ count } words split` );
	const zero = await pieces( page, ID, '.lm-word' );
	follows( zero, 0, from, 'words' );
	assert( zero.every( ( p ) => p.willChange === '' ), 'words: will-change set before the start' );

	// Screen readers get the text as written while it is split.
	const split = await aria( page );
	for ( const id of [ 'brxe-texthd', ID, 'brxe-txchar' ] ) {
		assert( split[ id ] === reference[ id ], `${ id }: screen readers get\n${ split[ id ] }\ninstead of\n${ reference[ id ] }` );
	}

	// A width change and back: the words in the page still follow the scroll, in order.
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.waitForTimeout( 800 );
	await page.setViewportSize( { width: 1440, height: 900 } );
	await page.waitForTimeout( 800 );
	const after = await pieces( page, ID, '.lm-word' );
	assert( after.length === count && after.every( ( p ) => ! p.nested ), `words: ${ after.length } words after the width changes (was ${ count }), nested: ${ after.some( ( p ) => p.nested ) }` );

	let lit = -1;
	for ( const progress of [ 0, 0.3, 0.6, 1 ] ) {
		const at = await scrubAt( page, ID, progress );
		assert( at, 'words: no scrub trigger' );
		await scrollTo( page, progress === 0 ? at.start - 50 : progress === 1 ? at.end + 50 : at.y );
		const list = await pieces( page, ID, '.lm-word' );
		const full = follows( list, progress, from, 'words' );
		assert( full >= lit, `words: fewer lit words at ${ progress } (${ full }) than before (${ lit })` );
		lit = full;
		const will = list.filter( ( p ) => p.willChange !== '' ).length;
		if ( progress === 0 || progress === 1 ) {
			assert( will === 0, `words outside its scrub (${ progress }): will-change on ${ will } word(s)` );
		} else {
			assert( will === list.length && list.every( ( p ) => p.willChange === 'opacity' ), `words inside its scrub: will-change on ${ will } of ${ list.length } words` );
		}
	}

	// Scrolling back dims them again.
	const back = await scrubAt( page, ID, 0 );
	await scrollTo( page, back.start - 50 );
	follows( await pieces( page, ID, '.lm-word' ), 0, from, 'words scrolled back' );

	// Characters, whose end the page cannot reach, and words whose start it cannot reach:
	// both fully lit at the bottom of the page.
	const geometry = await page.evaluate( () => {
		const max = document.scrollingElement.scrollHeight - window.innerHeight;
		const top = ( id ) => document.getElementById( id ).getBoundingClientRect().top + window.scrollY;
		return { max, charsStart: top( 'brxe-txchar' ) - window.innerHeight * 0.98, charsEnd: top( 'brxe-txchar' ) + window.innerHeight * 1.2, lateStart: top( 'brxe-txwlat' ) - window.innerHeight * 0.3 };
	} );
	assert( geometry.charsStart < geometry.max && geometry.charsEnd > geometry.max, `letters: start ${ geometry.charsStart } / end ${ geometry.charsEnd } for a maximum scroll of ${ geometry.max }: the case is not reproduced` );
	assert( geometry.lateStart > geometry.max, `late words: start ${ geometry.lateStart } is reachable (max ${ geometry.max })` );
	const chars = await pieces( page, 'brxe-txchar', '.lm-char' );
	assert( chars.length > 20 && chars.every( ( p ) => Math.abs( p.opacity - from ) < 0.01 ), `letters: ${ chars.length } characters, not all at ${ from } before the start` );

	await scrollTo( page, geometry.max + 100 );
	await page.waitForTimeout( 300 );
	const bottom = {
		chars: await pieces( page, 'brxe-txchar', '.lm-char' ),
		late: await pieces( page, 'brxe-txwlat', '.lm-word' ),
	};
	for ( const [ name, list ] of Object.entries( bottom ) ) {
		const dim = list.filter( ( p ) => p.opacity < 0.999 ).map( ( p ) => p.opacity );
		assert( list.length > 0 && dim.length === 0, `${ name}: ${ dim.length } of ${ list.length } piece(s) still dim at the bottom of the page (${ dim.slice( 0, 5 ).join( ', ' ) })` );
	}
	// The lines entrance at the bottom plays once the end of the page is reached.
	await page.waitForFunction( () => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === 'brxe-txllat' ) || {} ).state === 'done', null, { timeout: 4000 } ).catch( async () => {
		throw new Error( `late lines: state ${ await state( page, 'brxe-txllat' ) } at the bottom of the page` );
	} );
	await untouched( page, orig, [ 'brxe-txllat' ], 'late lines' );

	// The engine destroyed: every text back to how it was written.
	await page.evaluate( () => window.lumiaMotion.destroy() );
	await untouched( page, orig, IDS, 'destroy()' );

	assert( page.errors.length === 0, `words: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** A reload at the bottom of the page: what is already above the screen is never split. */
async function passed() {
	const page = await open();
	const orig = await originals( page );
	await page.evaluate( () => window.scrollTo( 0, document.scrollingElement.scrollHeight ) );
	await page.waitForTimeout( 300 );
	await page.reload( { waitUntil: 'load' } );
	await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug, null, { timeout: 5000 } );
	await page.waitForTimeout( 300 );
	const y = await page.evaluate( () => window.scrollY );
	assert( y > 4000, `passed: the reload did not keep the scroll position (${ y })` );
	const above = [ 'brxe-texthd', 'brxe-txlnhd', 'brxe-txlnpp' ];
	const split = await page.evaluate( ( [ ids, sel ] ) => ids.filter( ( id ) => document.getElementById( id ).querySelector( sel ) ), [ above, SPLIT ] );
	assert( split.length === 0, `passed: split although above the screen: ${ split.join( ', ' ) }` );
	await untouched( page, orig, above, 'passed' );
	assert( page.errors.length === 0, `passed: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** A screen taller than the page: nothing to scroll, every scrub is over and fully lit. */
async function tallScreen() {
	const page = await open( { width: 1440, height: 7000 } );
	await page.waitForTimeout( 800 );
	const seen = await page.evaluate( () => ( {
		max: document.scrollingElement.scrollHeight - window.innerHeight,
		all: document.querySelectorAll( '.lm-word, .lm-char' ).length,
		dim: [ ...document.querySelectorAll( '.lm-word, .lm-char' ) ].filter( ( n ) => parseFloat( getComputedStyle( n ).opacity ) < 0.999 ).length,
	} ) );
	assert( seen.max <= 0, `tall screen: the page still scrolls (${ seen.max } px), the case is not reproduced` );
	assert( seen.all > 0 && seen.dim === 0, `tall screen: ${ seen.dim } of ${ seen.all } piece(s) still dim on a page that cannot scroll` );
	assert( page.errors.length === 0, `tall screen: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** The screen reader copies, styled inline: still hidden and not selectable when an optimiser strips the .lm-sr rule. */
async function strippedCss() {
	const page = await newPage();
	let stripped = 0;
	await page.route( /\/motion\.css(\?|$)/, async ( route ) => {
		const res = await route.fetch();
		const body = ( await res.text() ).replace( /\.lm-sr\s*\{[^}]*\}/g, () => {
			stripped++;
			return '';
		} );
		return route.fulfill( { response: res, body } );
	} );
	const res = await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `stripped CSS: status ${ res && res.status() }` );
	await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion, null, { timeout: 5000 } );
	assert( stripped === 1, `stripped CSS: ${ stripped } .lm-sr rule(s) removed from motion.css (expected 1), the case is not reproduced` );
	const copies = await page.evaluate( () => [ ...document.querySelectorAll( '.lm-sr' ) ].map( ( n ) => {
		const r = n.getBoundingClientRect();
		const cs = getComputedStyle( n );
		return { in: n.parentElement.id, width: r.width, height: r.height, clip: cs.clipPath, select: cs.userSelect };
	} ) );
	assert( copies.length >= 2, `stripped CSS: ${ copies.length } screen reader copies (expected the split paragraphs)` );
	const shown = copies.filter( ( c ) => c.width > 1 || c.height > 1 || c.clip !== 'inset(50%)' || c.select !== 'none' );
	assert( shown.length === 0, `stripped CSS: copies visible or selectable: ${ JSON.stringify( shown ) }` );
	assert( page.errors.length === 0, `stripped CSS: page errors: ${ page.errors.join( ' | ' ) }` );
	await page.context().close();
}

/** An animation that cannot be built, on the first split and on a later one: the text is shown as written. */
async function failedSplit() {
	const LINES = [ 'brxe-txlnhd', 'brxe-txlnpp' ];
	const settled = async ( page, label, orig ) => {
		await page.waitForFunction( ( ids ) => ids.every( ( id ) => ( window.lumiaMotion._debug.items().find( ( i ) => i.id === id ) || {} ).state === 'final' ), LINES, { timeout: 3000 } ).catch( async () => {
			throw new Error( `${ label }: not shown as written (${ ( await items( page ) ).filter( ( i ) => LINES.includes( i.id ) ).map( ( i ) => `${ i.id }:${ i.state }` ).join( ', ' ) })` );
		} );
		await untouched( page, orig, LINES, label );
		const left = await page.evaluate( () => document.querySelectorAll( '.lm-line, .lm-line-mask' ).length );
		assert( left === 0, `${ label }: ${ left } line or mask element(s) left in the page` );
		assert( page.errors.length === 0, `${ label }: page errors: ${ page.errors.join( ' | ' ) }` );
	};

	// First split: gsap.from() throws inside the first onSplit.
	{
		const page = await newPage();
		await page.addInitScript( () => {
			Object.defineProperty( window, 'gsap', {
				configurable: true,
				get() {
					return this.lmGsap;
				},
				set( value ) {
					value.from = () => {
						throw new Error( 'lm-e2e: from() refused' );
					};
					this.lmGsap = value;
				},
			} );
		} );
		await page.goto( pageUrl( SLUG ), { waitUntil: 'load' } );
		await page.waitForFunction( () => document.documentElement.classList.contains( 'lm-ready' ) && window.lumiaMotion && window.lumiaMotion._debug, null, { timeout: 5000 } );
		await settled( page, 'failed first split', await originals( page ) );
		await page.context().close();
	}

	// Later split (narrower screen): gsap.from() starts throwing after the first split.
	{
		const page = await open();
		const orig = await originals( page );
		await page.evaluate( () => {
			window.gsap.from = () => {
				throw new Error( 'lm-e2e: from() refused' );
			};
		} );
		await page.setViewportSize( { width: 390, height: 844 } );
		await settled( page, 'failed new split', orig );
		await page.context().close();
	}
}

export default async function () {
	await strippedCss();
	await failedSplit();
	const reference = await reduced();
	await lines( reference );
	await words( reference );
	await passed();
	await tallScreen();
}
