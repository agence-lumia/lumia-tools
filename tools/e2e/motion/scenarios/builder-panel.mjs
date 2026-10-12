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
async function selectAndOpen( page, canvas, id, position = { x: 10, y: 6 } ) {
	await canvas.evaluate( ( id ) => document.getElementById( `brxe-${ id }` ).scrollIntoView( { block: 'center' } ), id );
	await canvas.locator( `#brxe-${ id }` ).click( { position, force: true } );
	await page.locator( `#element-${ id }.active` ).waitFor( { timeout: 10000 } );
	await page.getByText( 'Style', { exact: true } ).filter( { visible: true } ).first().click();
	const effect = page.locator( '[data-controlkey="_lmEffect"]' ).first();
	if ( ! ( await effect.isVisible().catch( () => false ) ) ) {
		await page.locator( '[data-control-group="lm_motion"] .control-group-title' ).first().click();
	}
	await effect.waitFor( { state: 'visible', timeout: 10000 } );
}

const markerCount = ( canvas ) => canvas.evaluate( () => document.querySelectorAll( '[class*="gsap-marker-"]' ).length );

/**
 * The markers of the canvas, as GSAP's own debug markers show them: per item a start and an end
 * on the element, and the scroller-start and scroller-end lines on the viewport, none of them
 * transparent (a one-shot effect used to get a transparent end).
 */
async function assertFullMarkers( canvas ) {
	const m = await canvas.evaluate( () => {
		const all = ( type ) => Array.from( document.querySelectorAll( `.gsap-marker-${ type }` ) );
		const shown = ( el ) => {
			const cs = getComputedStyle( el );
			return cs.color !== 'rgba(0, 0, 0, 0)' && cs.display !== 'none' && cs.visibility !== 'hidden';
		};
		const types = [ 'start', 'end', 'scroller-start', 'scroller-end' ];
		const out = { items: window.lumiaMotion._debug.items().length };
		types.forEach( ( type ) => {
			out[ type ] = all( type ).length;
			out[ type + '-hidden' ] = all( type ).filter( ( el ) => ! shown( el ) ).length;
		} );
		return out;
	} );
	assert( m.items > 0, 'no item in the canvas' );
	for ( const type of [ 'start', 'end', 'scroller-start', 'scroller-end' ] ) {
		assert( m[ type ] === m.items, `${ m[ type ] } .gsap-marker-${ type } for ${ m.items } items, expected one per item` );
		assert( m[ type + '-hidden' ] === 0, `${ m[ type + '-hidden' ] } .gsap-marker-${ type } drawn invisible` );
	}
}

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

	// The Motion shortcut of the left rail (motion-panel.js): the last item, after Bricks' own.
	const railItem = '#bricks-panel-element-quick-access ul > li.lm-rail-motion';
	const rail = () => panel.evaluate( () => {
		const list = document.querySelector( '#bricks-panel-element-quick-access ul' );
		const item = list && list.querySelector( 'li.lm-rail-motion' );
		if ( ! item ) {
			return null;
		}
		const icon = item.querySelector( '.bricks-svg-wrapper .lm-rail-icon' );
		const before = icon ? getComputedStyle( icon, '::before' ) : null;
		const props = document.querySelector( '[data-v-app]' ).__vue_app__.config.globalProperties;
		return {
			last: list.lastElementChild === item,
			bricksItems: list.querySelectorAll( 'li:not(.lm-rail-motion)' ).length,
			balloon: item.getAttribute( 'data-balloon' ),
			pos: item.getAttribute( 'data-balloon-pos' ),
			active: item.classList.contains( 'active' ),
			hasSettings: item.classList.contains( 'has-settings' ),
			mask: before ? before.maskImage || before.webkitMaskImage || '' : '',
			width: icon ? icon.getBoundingClientRect().width : 0,
			tab: props.$_state.activePanelTab,
			group: props.$_state.activePanelGroup,
		};
	} );
	let r = await rail();
	assert( r, 'no Motion item in the left rail of the element panel' );
	assert( r.last && r.bricksItems > 0, `the Motion item is not after the Bricks items (last: ${ r.last }, Bricks items: ${ r.bricksItems })` );
	assert( r.balloon === 'Motion' && r.pos, `the Motion item has the tooltip "${ r.balloon }" (${ r.pos })` );
	assert( /^url\("data:image\/svg\+xml;base64,/.test( r.mask ) && r.width > 0, 'the Motion item has no sparkles icon' );
	assert( r.active, 'the Motion item is not active while the Motion group is open' );
	assert( ! r.hasSettings, 'the Motion item shows settings on an element with no _lm* setting' );
	// From the Content tab, a click opens the Motion group of the Style tab.
	await panel.locator( '#bricks-panel-element-quick-access ul > li' ).first().click();
	await panel.waitForFunction( () => document.querySelector( '[data-v-app]' ).__vue_app__.config.globalProperties.$_state.activePanelTab === 'content', null, { timeout: 5000 } );
	assert( ! ( await rail() ).active, 'the Motion item is still active on the Content tab' );
	await panel.locator( railItem ).click();
	await panel.locator( '[data-controlkey="_lmEffect"]' ).first().waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {
		throw new Error( 'a click on the Motion rail item did not open the Motion group' );
	} );
	r = await rail();
	assert( r.tab === 'style' && r.group === 'lm_motion', `the Motion rail item opened ${ r.tab } / ${ r.group }, expected style / lm_motion` );
	assert( r.active, 'the Motion item is not active after its click' );
	// Another element: Bricks renders the rail again, the item comes back.
	// Its middle: the toolbar of the selected element covers the top of the next one.
	await selectAndOpen( panel, canvas2, 'bpre30', { x: 60, y: 20 } );
	await panel.locator( railItem ).waitFor( { timeout: 5000 } ).catch( () => {
		throw new Error( 'the Motion rail item is gone after selecting another element' );
	} );
	r = await rail();
	assert( r.last, 'the Motion rail item is not the last item after selecting another element' );
	assert( r.hasSettings, 'the Motion item does not show the _lmStartAt setting of the element' );
	assert( ( await panel.locator( railItem ).count() ) === 1, 'the Motion rail item is there twice' );
	await selectAndOpen( panel, canvas2, 'bpre01' );

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
	await assertFullMarkers( canvas2 );
	// Stable: drawing the markers must not make the canvas scan again and again.
	const s0 = await canvas2.evaluate( () => window.lumiaMotion._debug.stats().scans );
	await sleep( 1000 );
	const s1 = await canvas2.evaluate( () => window.lumiaMotion._debug.stats().scans );
	assert( s1 - s0 <= 1, `the markers keep the canvas scanning: ${ s1 - s0 } scans in 1 s` );
	await panel.screenshot( { path: OUT + 'panel-v2.png' } );
	// The rail with its Motion item, hovered so the tooltip shows, and the markers in the canvas.
	await panel.locator( '#bricks-panel-element-quick-access ul > li.lm-rail-motion' ).hover();
	await sleep( 300 );
	await panel.screenshot( { path: OUT + 'rail-markers.png' } );
	await markers.uncheck();
	await canvas2.waitForFunction( () => document.querySelectorAll( '[class*="gsap-marker-"]' ).length === 0, null, { timeout: 2000 } ).catch( () => {
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
	// The checkbox is a preference of the browser, never a setting of the element.
	const helpSetting = await panel.evaluate( () => {
		const element = document.querySelector( '[data-v-app]' ).__vue_app__.config.globalProperties.$_state.activeElement;
		return Object.keys( ( element && element.settings ) || {} ).filter( ( key ) => key === '_lmHelp' );
	} );
	assert( helpSetting.length === 0, 'Show markers wrote _lmHelp in the settings of the element' );
	assert( ! ( await rail() ).hasSettings, 'the Motion rail item shows settings after Show markers' );

	assert( panel.errors.length === 0, `page errors: ${ panel.errors.join( ' | ' ) }` );
}

