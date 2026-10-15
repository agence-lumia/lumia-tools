import { newPage, pageUrl, assert } from '../lib.mjs';

export default async function () {
	const page = await newPage();
	const res = await page.goto( pageUrl( 'lm-e2e-smoke' ), { waitUntil: 'load' } );
	assert( res && res.status() === 200, `status ${ res && res.status() }` );
	assert( ( await page.locator( '#brxe-smokeh' ).textContent() ) === 'Smoke test', 'smoke heading not rendered' );
	await page.waitForTimeout( 500 );
	assert( page.errors.length === 0, `page errors: ${ page.errors.join( ' | ' ) }` );
}
