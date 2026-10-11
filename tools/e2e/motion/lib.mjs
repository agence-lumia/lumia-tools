// Shared helpers of the Motion scenarios. Environment:
//   LM_E2E_URL         site under test (default http://localhost:8088)
//   LM_E2E_USER/PASS   administrator account (default local-admin / local-admin)
//   LM_E2E_WP          WP-CLI command, run through a shell (default `wp`)
//   LM_E2E_PLUGIN_DIR  plugin path as seen by WP-CLI (default /var/www/html/wp-content/plugins/lumia-tools)
//   LM_E2E_CHROME      Chrome executable (default: the installed Google Chrome channel)
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const require = createRequire( new URL( './.cache/node_modules/', import.meta.url ) );
const { chromium } = require( 'playwright-core' );

export const BASE = ( process.env.LM_E2E_URL || 'http://localhost:8088' ).replace( /\/$/, '' );
export const USER = process.env.LM_E2E_USER || 'local-admin';
const PASS = process.env.LM_E2E_PASS || 'local-admin';
const WP = process.env.LM_E2E_WP || 'wp';
const PLUGIN_DIR = ( process.env.LM_E2E_PLUGIN_DIR || '/var/www/html/wp-content/plugins/lumia-tools' ).replace( /\/$/, '' );
export const OUT = new URL( './out/', import.meta.url ).pathname;
const STATE = OUT + 'state.json';

let shared = null;

/** One Chrome per scenario process, closed on exit. */
export async function browser() {
	if ( ! shared ) {
		const opts = { headless: true };
		if ( process.env.LM_E2E_CHROME ) {
			opts.executablePath = process.env.LM_E2E_CHROME;
		} else {
			opts.channel = 'chrome';
		}
		shared = await chromium.launch( opts );
		process.on( 'exit', () => shared && shared.close().catch( () => {} ) );
	}
	return shared;
}

/**
 * New page in its own context. Page errors are collected in page.errors.
 *
 * @param {object} [o] width, height, reducedMotion ('reduce'|'no-preference'), auth (reuse the login state).
 */
export async function newPage( o = {} ) {
	const b = await browser();
	const ctx = await b.newContext( {
		viewport: { width: o.width || 1440, height: o.height || 900 },
		reducedMotion: o.reducedMotion || 'no-preference',
		storageState: o.auth && existsSync( STATE ) ? STATE : undefined,
	} );
	const page = await ctx.newPage();
	page.errors = [];
	page.on( 'pageerror', ( e ) => page.errors.push( e.message ) );
	return page;
}

/** Logs in through the login form wp-admin redirects to (wp-login.php or a custom slug) and saves the state. */
export async function login( page ) {
	await page.goto( `${ BASE }/wp-admin/`, { waitUntil: 'domcontentloaded' } );
	if ( await page.locator( '#user_login' ).count() ) {
		await page.fill( '#user_login', USER );
		await page.fill( '#user_pass', PASS );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
	}
	await page.context().storageState( { path: STATE } );
}

/** Runs WP-CLI as the test user and returns its trimmed stdout. Throws with stderr on failure. */
export function wp( ...args ) {
	const quoted = [ ...args, `--user=${ USER }` ].map( ( a ) => `'${ String( a ).replace( /'/g, `'\\''` ) }'` );
	try {
		return execFileSync( 'sh', [ '-c', `${ WP } ${ quoted.join( ' ' ) }` ], {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			maxBuffer: 32 * 1024 * 1024,
		} ).trim();
	} catch ( e ) {
		throw new Error( `wp ${ args[ 0 ] } failed: ${ ( e.stderr || e.message ).toString().trim() }` );
	}
}

/** Path of a repository file as seen by WP-CLI (for eval-file). */
export function pluginPath( rel ) {
	return `${ PLUGIN_DIR }/${ rel.replace( /^\//, '' ) }`;
}

export function pageUrl( slug, query = '' ) {
	return `${ BASE }/${ slug }/${ query }`;
}

/** Engine items of the page (lumiaMotion._debug.items()), [] when the engine is absent. */
export function items( page ) {
	return page.evaluate( () =>
		window.lumiaMotion && window.lumiaMotion._debug ? window.lumiaMotion._debug.items().map( ( i ) => ( {
			id: i.id,
			effect: i.effect,
			signature: i.signature,
			state: i.state,
			options: JSON.parse( JSON.stringify( i.options, ( k, v ) => ( typeof v === 'function' ? undefined : v ) ) ),
		} ) ) : []
	);
}

export function assert( cond, msg ) {
	if ( ! cond ) {
		throw new Error( msg );
	}
}

/** Approximate equality for floats read back from the page. */
export function near( a, b, eps = 1e-3 ) {
	return Math.abs( a - b ) <= eps;
}

/** Adds html{scroll-behavior:smooth} to every HTML document of the page (the theme setting that broke reloads). */
export async function injectSmoothScroll( page ) {
	await page.route( '**/*', async ( route ) => {
		if ( route.request().resourceType() !== 'document' ) {
			return route.continue();
		}
		const res = await route.fetch();
		const body = ( await res.text() ).replace( '</head>', '<style>html{scroll-behavior:smooth}</style></head>' );
		return route.fulfill( { response: res, body } );
	} );
}

/** Toggles a FluentSnippets snippet ('published' or 'draft') and rebuilds its index. */
export function snippet( file, status ) {
	wp( 'eval', `$m = new \\FluentSnippets\\App\\Model\\Snippet(); $s = $m->findByFileName( ${ JSON.stringify( file ) } );
		$s['meta']['status'] = ${ JSON.stringify( status ) }; $m->updateSnippet( ${ JSON.stringify( file ) }, $s['code'], $s['meta'] );
		\\FluentSnippets\\App\\Helpers\\Helper::cacheSnippetIndex();` );
}
