// The Motion panel in the Bricks builder: the group is there on a selected element, a
// control writes its --lm-* variable on the canvas element in real time, and the
// "Custom" tempo reveals the duration field. Then, on a page with effects: the sparkles
// icon of the group, the Replay button, the "Show markers" checkbox (kept across a
// reload of the builder) and the inherited values shown as placeholders.
import { newPage, login, pageUrl, assert, OUT } from '../lib.mjs';

const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/** Opens the builder on a page and waits for the engine of the canvas. */
async function openBuilder( page, slug, anchorId ) {
	await page.goto( pageUrl( slug, '?bricks=run' ), { waitUntil: 'networkidle', timeout: 90000 } );
	const canvas = page.frames().find( ( f ) => f !== page.mainFrame() );
	assert( canvas, 'no canvas frame in the builder' );
	await canvas.locator( `#brxe-${ anchorId }` ).waitFor( { timeout: 30000 } );
	await canvas.waitForFunction( () => window.lumiaMotion && window.lumiaMotion._debug.items().length > 0, null, { timeout: 15000 } );
	await sleep( 1500 );
	return canvas;
}

/** Selects an element from the canvas and shows the Motion group of the Style tab. */
async function selectAndOpen( page, canvas, id ) {
	await canvas.evaluate( ( id ) => document.getElementById( `brxe-${ id }` ).scrollIntoView( { block: 'center' } ), id );
	await canvas.locator( `#brxe-${ id }` ).click( { position: { x: 10, y: 6 }, force: true } );
	await page.locator( `#element-${ id }.active` ).waitFor( { timeout: 10000 } );
	await page.getByText( 'Style', { exact: true } ).filter( { visible: true } ).first().click();
	const effect = page.locator( '[data-controlkey="_lmEffect"]' ).first();
	if ( ! ( await effect.isVisible().catch( () => false ) ) ) {
		await page.locator( '[data-control-group="lm_motion"] .control-group-title' ).first().click();
	}
	await effect.waitFor( { state: 'visible', timeout: 10000 } );
}

const markerCount = ( canvas ) => canvas.evaluate( () => document.querySelectorAll( '.gsap-marker-start' ).length );

export default async function () {
	const signIn = await newPage();
	await login( signIn );

	const page = await newPage( { auth: true, width: 1600, height: 1000 } );
	await page.goto( pageUrl( 'lm-e2e-smoke', '?bricks=run' ), { waitUntil: 'networkidle', timeout: 90000 } );

	// The canvas is the only frame that is not the builder itself.
	const canvas = page.frames().find( ( f ) => f !== page.mainFrame() );
	assert( canvas, 'no canvas frame in the builder' );
	const heading = canvas.locator( '#brxe-smokeh' );
	await heading.waitFor( { timeout: 30000 } );
	// The builder script makes Bricks print data-script-id on the elements it renders in the canvas.
	assert( await heading.getAttribute( 'data-script-id' ), 'the canvas heading has no data-script-id' );
	await heading.click();

	await page.getByText( 'Style', { exact: true } ).first().click();
	const group = page.getByText( 'Motion', { exact: true } ).first();
	await group.waitFor( { timeout: 10000 } );
	await group.click();

	const startAt = page.locator( '[data-controlkey="_lmStartAt"] input' ).first();
	await startAt.waitFor( { timeout: 10000 } );
	assert( await page.locator( '[data-controlkey="_lmEffect"]' ).count() === 1, 'effect select not in the panel' );

	// A number control with a % unit: the variable carries the unit, within 500 ms.
	const read = () => canvas.evaluate( () => getComputedStyle( document.getElementById( 'brxe-smokeh' ) ).getPropertyValue( '--lm-start-at' ).trim() );
	assert( ( await read() ) === '', `--lm-start-at is already set: "${ await read() }"` );
	const t0 = Date.now();
	await startAt.fill( '40' );
	await startAt.press( 'Tab' );
	await canvas.waitForFunction(
		() => getComputedStyle( document.getElementById( 'brxe-smokeh' ) ).getPropertyValue( '--lm-start-at' ).trim() === '40%',
		null,
		{ timeout: 500 }
	).catch( async () => {
		throw new Error( `--lm-start-at is "${ await read() }" ${ Date.now() - t0 } ms after typing 40, expected "40%"` );
	} );

	// "Custom" reveals the duration field, which is hidden before.
	assert( ! ( await page.locator( '[data-controlkey="_lmDuration"]' ).first().isVisible() ), 'duration field visible before choosing Custom' );
	await page.locator( '[data-controlkey="_lmTempo"] .input' ).first().click();
	await page.locator( '[data-controlkey="_lmTempo"]' ).first().getByText( 'Custom', { exact: true } ).click();
	await page.locator( '[data-controlkey="_lmDuration"] input' ).first().waitFor( { state: 'visible', timeout: 5000 } );

	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );

	// ---------------------------------------------------------------- a page with effects
	const panel = await newPage( { auth: true, width: 1600, height: 1000 } );
	let canvas2 = await openBuilder( panel, 'lm-e2e-basic', 'bpre01' );
	await selectAndOpen( panel, canvas2, 'bpre01' );

	// The group icon: Bricks' star, covered by the Lucide sparkles (a CSS mask), its own SVG hidden.
	const icon = await panel.evaluate( () => {
		const el = document.querySelector( '[data-control-group="lm_motion"] .control-group-title .icon' );
		if ( ! el ) {
			return null;
		}
		const before = getComputedStyle( el, '::before' );
		const svg = el.querySelector( 'svg' );
		return {
			name: el.getAttribute( 'data-name' ),
			mask: before.maskImage || before.webkitMaskImage || '',
			width: before.width,
			svg: svg ? getComputedStyle( svg ).visibility : 'hidden',
		};
	} );
	assert( icon, 'no icon in the title of the Motion group' );
	assert( icon.name === 'star', `the Motion group declares the icon "${ icon.name }", expected star` );
	assert( /^url\("data:image\/svg\+xml;base64,/.test( icon.mask ), `the Motion group icon has no sparkles mask: ${ icon.mask.slice( 0, 60 ) }` );
	assert( icon.svg === 'hidden', 'the native star SVG is still shown under the sparkles' );
	assert( parseFloat( icon.width ) > 0, 'the sparkles mask has no width' );

	// Live placeholders: the empty Stagger field shows the stagger of the class (0.15).
	const placeholder = ( key ) => panel.locator( `[data-controlkey="${ key }"] input` ).first().getAttribute( 'placeholder' );
	await panel.waitForFunction( () => {
		const input = document.querySelector( '[data-controlkey="_lmStagger"] input' );
		return input && input.getAttribute( 'placeholder' ) === '0.15';
	}, null, { timeout: 5000 } ).catch( async () => {
		throw new Error( `the empty Stagger field shows "${ await placeholder( '_lmStagger' ) }", expected the class value 0.15` );
	} );
	assert( ( await placeholder( '_lmDistance' ) ) === '48', `the empty Distance field shows "${ await placeholder( '_lmDistance' ) }", expected the slide-up value 48` );
	assert( ( await panel.locator( '[data-controlkey="_lmStagger"] input' ).first().inputValue() ) === '', 'the Stagger field is not empty' );

	// Replay: one replay of the selected element.
	const replays = () => canvas2.evaluate( () => window.lumiaMotion._debug.stats().replays );
	const r0 = await replays();
	await panel.locator( '.lm-panel-replay' ).first().click();
	await canvas2.waitForFunction( ( r0 ) => window.lumiaMotion._debug.stats().replays === r0 + 1, r0, { timeout: 1000 } ).catch( async () => {
		throw new Error( `Replay: replays went from ${ r0 } to ${ await replays() }, expected +1` );
	} );
	await sleep( 300 );
	assert( ( await replays() ) === r0 + 1, 'Replay replayed more than once' );

	// Markers: on, off, on again and kept by a reload of the builder.
	const markers = panel.locator( '.lm-panel-markers' ).first();
	assert( ! ( await markers.isChecked() ), 'Show markers is checked on a fresh browser' );
	assert( ( await markerCount( canvas2 ) ) === 0, 'markers in the canvas before Show markers' );
	await markers.check();
	await canvas2.waitForFunction( () => document.querySelectorAll( '.gsap-marker-start' ).length > 0, null, { timeout: 2000 } ).catch( () => {
		throw new Error( 'Show markers: no .gsap-marker-start in the canvas' );
	} );
	// Stable: drawing the markers must not make the canvas scan again and again.
	const s0 = await canvas2.evaluate( () => window.lumiaMotion._debug.stats().scans );
	await sleep( 1000 );
	const s1 = await canvas2.evaluate( () => window.lumiaMotion._debug.stats().scans );
	assert( s1 - s0 <= 1, `the markers keep the canvas scanning: ${ s1 - s0 } scans in 1 s` );
	await panel.screenshot( { path: OUT + 'panel-v2.png' } );
	await markers.uncheck();
	await canvas2.waitForFunction( () => document.querySelectorAll( '.gsap-marker-start' ).length === 0, null, { timeout: 2000 } ).catch( () => {
		throw new Error( 'Show markers off: markers left in the canvas' );
	} );
	await markers.check();
	await canvas2.waitForFunction( () => document.querySelectorAll( '.gsap-marker-start' ).length > 0, null, { timeout: 2000 } );
	await panel.reload( { waitUntil: 'networkidle', timeout: 90000 } );
	canvas2 = panel.frames().find( ( f ) => f !== panel.mainFrame() );
	await canvas2.locator( '#brxe-bpre01' ).waitFor( { timeout: 30000 } );
	await canvas2.waitForFunction( () => document.querySelectorAll( '.gsap-marker-start' ).length > 0, null, { timeout: 15000 } ).catch( () => {
		throw new Error( 'Show markers was not kept across a reload of the builder (no markers in the canvas)' );
	} );
	await selectAndOpen( panel, canvas2, 'bpre01' );
	assert( await panel.locator( '.lm-panel-markers' ).first().isChecked(), 'Show markers is unchecked after a reload' );
	await panel.locator( '.lm-panel-markers' ).first().uncheck();
	assert( ( await panel.evaluate( () => localStorage.getItem( 'lmMotionMarkers' ) ) ) === null, 'Show markers off left lmMotionMarkers in localStorage' );

	assert( panel.errors.length === 0, `page errors: ${ panel.errors.join( ' | ' ) }` );
}
