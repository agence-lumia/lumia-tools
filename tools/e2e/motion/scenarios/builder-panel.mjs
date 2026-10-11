// The Motion panel in the Bricks builder: the group is there on a selected element, a
// control writes its --lm-* variable on the canvas element in real time, and the
// "Exact duration" tempo reveals the duration field.
import { newPage, login, pageUrl, assert } from '../lib.mjs';

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

	// "Exact duration" reveals the duration field, which is hidden before.
	assert( ! ( await page.locator( '[data-controlkey="_lmDuration"]' ).first().isVisible() ), 'duration field visible before choosing Exact duration' );
	await page.locator( '[data-controlkey="_lmTempo"] .input' ).first().click();
	await page.locator( '[data-controlkey="_lmTempo"]' ).first().getByText( 'Exact duration', { exact: true } ).click();
	await page.locator( '[data-controlkey="_lmDuration"] input' ).first().waitFor( { state: 'visible', timeout: 5000 } );

	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
}
