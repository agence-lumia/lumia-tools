import { newPage, pageUrl, assert, withMotionSettings } from '../lib.mjs';

const START_AT = '--lm-start-at';

/** A page whose engine is blocked: the scenario reads the markup the server rendered, and the engine adds inline styles of its own. */
async function serverMarkup( o ) {
	const page = await newPage( o );
	await page.route( /\/motion\.js(\?|$)/, ( route ) => route.abort() );
	return page;
}

export default async function () {
	const page = await serverMarkup();
	const res = await page.goto( pageUrl( 'lm-e2e-marker' ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `status ${ res && res.status() }` );

	// data-lm: the families, from a class, from the element settings, and none for a plain element.
	const marks = await page.evaluate( () => {
		const out = {};
		for ( const id of [ 'mkfade', 'mkword', 'mkcnt', 'mkplain', 'mkout' ] ) {
			const node = document.getElementById( `brxe-${ id }` );
			out[ id ] = node ? node.getAttribute( 'data-lm' ) : 'MISSING';
		}
		return out;
	} );
	assert( marks.mkfade === 'enter', `lm-fade class: data-lm=${ marks.mkfade }` );
	assert( marks.mkword === 'text', `text-words setting: data-lm=${ marks.mkword }` );
	assert( marks.mkcnt === 'counter', `sk-counter class: data-lm=${ marks.mkcnt }` );
	assert( marks.mkplain === null, `plain element: data-lm=${ marks.mkplain }` );
	assert( marks.mkout === 'enter', `class preset outside the loop: data-lm=${ marks.mkout }` );

	// Outside a loop the element keeps its ID selector: no inline style needed, and the element wins by specificity.
	const out = await page.evaluate( ( prop ) => {
		const node = document.getElementById( 'brxe-mkout' );
		return { value: getComputedStyle( node ).getPropertyValue( prop ).trim(), style: node.getAttribute( 'style' ) };
	}, START_AT );
	assert( out.value === '30%', `outside the loop: ${ START_AT }=${ out.value }` );
	assert( ! out.style, `outside the loop: unexpected style="${ out.style }"` );

	// Inside the loop Bricks gives the item a class selector prefixed with the loop element (.brxe-mkloop .brxe-mkitem.brxe-heading, 0,3,0):
	// it beats the class preset (0,2,0) by itself, so no inline style is added.
	const loop = await page.evaluate( ( prop ) => {
		const read = ( sel ) => [ ...document.querySelectorAll( sel ) ].map( ( node ) => ( {
			value: getComputedStyle( node ).getPropertyValue( prop ).trim(),
			style: node.getAttribute( 'style' ),
			mark: node.getAttribute( 'data-lm' ),
			id: node.id,
		} ) );
		return { items: read( '.brxe-mkitem' ), bare: read( '.brxe-mkbare' ), self: read( '.brxe-mkself' ), bp: read( '.brxe-mkbp' ) };
	}, START_AT );
	assert( loop.items.length === 3, `expected 3 loop items, found ${ loop.items.length }` );
	for ( const item of loop.items ) {
		assert( item.mark === 'enter', `loop item data-lm=${ item.mark }` );
		assert( item.value === '30%', `loop override: ${ START_AT }=${ item.value } (expected 30%)` );
		assert( ! item.style, `loop item style="${ item.style }" (the element rule already wins)` );
		assert( ! item.id, `loop item should have no id, has ${ item.id }` );
	}

	// A loop item with no setting of its own takes the class preset, and gets no inline style.
	assert( loop.bare.length === 3, `expected 3 bare loop items, found ${ loop.bare.length }` );
	for ( const item of loop.bare ) {
		assert( item.mark === 'enter', `bare loop item data-lm=${ item.mark }` );
		assert( item.value === '60%', `class preset: ${ START_AT }=${ item.value } (expected 60%)` );
		assert( ! item.style, `bare loop item style="${ item.style }"` );
	}

	// The element that holds the loop ties with the class (0,2,0) and the class stylesheet comes last: only the inline style saves the override.
	assert( loop.self.length === 3, `expected 3 looping headings, found ${ loop.self.length }` );
	for ( const item of loop.self ) {
		assert( item.mark === 'enter', `looping element data-lm=${ item.mark }` );
		assert( item.value === '30%', `looping element override: ${ START_AT }=${ item.value } (expected 30%)` );
		assert( item.style === '--lm-start-at:30%;', `looping element style="${ item.style }"` );
	}

	// Any other element in the loop already wins by specificity (0,3,0): no inline style, so its breakpoint values still apply.
	assert( loop.bp.length === 3, `expected 3 breakpoint items, found ${ loop.bp.length }` );
	for ( const item of loop.bp ) {
		assert( item.value === '30%', `loop item, desktop: ${ START_AT }=${ item.value }` );
		assert( ! item.style, `loop item with breakpoints should have no inline style, has "${ item.style }"` );
	}
	const phone = await serverMarkup( { width: 400, height: 800 } );
	await phone.goto( pageUrl( 'lm-e2e-marker' ), { waitUntil: 'load' } );
	const small = await phone.evaluate( ( prop ) => [ ...document.querySelectorAll( '.brxe-mkbp' ) ].map( ( node ) => getComputedStyle( node ).getPropertyValue( prop ).trim() ), START_AT );
	assert( small.length === 3 && small.every( ( v ) => v === '10%' ), `loop item, mobile: ${ START_AT }=${ small.join( ',' ) } (expected 10% x3)` );

	// Names typed in the "CSS classes" field: marked like a global class, the former one while the compatibility setting is on.
	const typed = await page.evaluate( () => [ 'mkrwsk', 'mkrwlm' ].map( ( id ) => document.getElementById( `brxe-${ id }` ).getAttribute( 'data-lm' ) ) );
	assert( typed[ 0 ] === 'enter', `sk-fade typed in CSS classes: data-lm=${ typed[ 0 ] }` );
	assert( typed[ 1 ] === 'enter', `lm-reveal typed in CSS classes: data-lm=${ typed[ 1 ] }` );

	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );

	// Compatibility off: the former name typed in CSS classes is no longer an effect, the new one still is.
	await withMotionSettings( { legacy_classes: false }, async () => {
		const off = await serverMarkup();
		await off.goto( pageUrl( 'lm-e2e-marker' ), { waitUntil: 'load' } );
		const marks = await off.evaluate( () => [ 'mkrwsk', 'mkrwlm' ].map( ( id ) => document.getElementById( `brxe-${ id }` ).getAttribute( 'data-lm' ) ) );
		assert( marks[ 0 ] === null, `sk-fade typed, compatibility off: data-lm=${ marks[ 0 ] }` );
		assert( marks[ 1 ] === 'enter', `lm-reveal typed, compatibility off: data-lm=${ marks[ 1 ] }` );
		await off.context().close();
	} );
}
