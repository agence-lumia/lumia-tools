// Shared helpers of the Motion scenarios. Environment:
//   LM_E2E_URL         site under test (default http://localhost:8088)
//   LM_E2E_USER/PASS   administrator account (default local-admin / local-admin)
//   LM_E2E_WP          WP-CLI command, run through a shell (default `wp`)
//   LM_E2E_PLUGIN_DIR  plugin path as seen by WP-CLI (default /var/www/html/wp-content/plugins/lumia-tools)
//   LM_E2E_CHROME      Chrome executable (default: the installed Google Chrome channel)
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const require = createRequire( new URL( './.cache/node_modules/', import.meta.url ) );
const { chromium } = require( 'playwright-core' );

export const BASE = ( process.env.LM_E2E_URL || 'http://localhost:8088' ).replace( /\/$/, '' );
export const USER = process.env.LM_E2E_USER || 'local-admin';
const PASS = process.env.LM_E2E_PASS || 'local-admin';
const WP = process.env.LM_E2E_WP || 'wp';
const PLUGIN_DIR = ( process.env.LM_E2E_PLUGIN_DIR || '/var/www/html/wp-content/plugins/lumia-tools' ).replace( /\/$/, '' );
export const OUT = fileURLToPath( new URL( './out/', import.meta.url ) );
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
 * @param {object} [o] width, height, reducedMotion ('reduce'|'no-preference'), auth (reuse the login state), mobile (touch phone emulation).
 */
export async function newPage( o = {} ) {
	if ( o.auth && ! existsSync( STATE ) ) {
		throw new Error( `no login state at ${ STATE }: run login first` );
	}
	const b = await browser();
	const ctx = await b.newContext( {
		viewport: { width: o.width || 1440, height: o.height || 900 },
		reducedMotion: o.reducedMotion || 'no-preference',
		storageState: o.auth ? STATE : undefined,
		isMobile: !! o.mobile,
		hasTouch: !! o.mobile,
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
	if ( ! page.url().includes( '/wp-admin/' ) || ! ( await page.locator( '#wpadminbar' ).count() ) ) {
		throw new Error( `login failed: still at ${ page.url() } after submitting the form` );
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

/**
 * Runs fn with top-level keys of the Motion settings replaced, then puts the option back
 * exactly as it was (deleted again when it did not exist: the module then uses its defaults).
 * Values are JSON: pass booleans as booleans, the sanitizer only takes true/1/'1'/'on' as on.
 */
export async function withMotionSettings( patch, fn ) {
	const KEY = 'lumia_module_motion';
	let before = null;
	try {
		before = wp( 'option', 'get', KEY, '--format=json' );
	} catch ( e ) {
		if ( ! /Could not get/.test( e.message ) ) {
			throw e;
		}
	}
	const current = before ? JSON.parse( before ) : {};
	wp( 'option', 'update', KEY, JSON.stringify( { ...current, ...patch } ), '--format=json' );
	try {
		return await fn();
	} finally {
		if ( before === null ) {
			wp( 'option', 'delete', KEY );
		} else {
			wp( 'option', 'update', KEY, before, '--format=json' );
		}
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

/**
 * Requests of a page to another host than the site under test. Font files are left out: the lab
 * copy keeps the font URLs of the production site in its theme CSS (lab.dev.agence-lumia.com),
 * which have nothing to do with the module; a CDN script or stylesheet still counts.
 *
 * @param {string[]} urls Requested URLs.
 * @return {string[]}
 */
export function foreignRequests( urls ) {
	const host = new URL( BASE ).host;
	return urls.filter( ( u ) => /^https?:/.test( u ) && new URL( u ).host !== host && ! /\.(woff2?|ttf|otf|eot)(\?|#|$)/i.test( new URL( u ).pathname ) );
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

/** Adds a stylesheet to every HTML document of the page, before any script runs. */
export async function injectCss( page, css ) {
	await page.route( '**/*', async ( route ) => {
		if ( route.request().resourceType() !== 'document' ) {
			return route.continue();
		}
		const res = await route.fetch();
		const body = ( await res.text() ).replace( '</head>', `<style>${ css }</style></head>` );
		return route.fulfill( { response: res, body } );
	} );
}

/** Adds html{scroll-behavior:smooth} to every HTML document of the page (the theme setting that broke reloads). */
export function injectSmoothScroll( page ) {
	return injectCss( page, 'html{scroll-behavior:smooth}' );
}

/** Toggles a FluentSnippets snippet ('published' or 'draft') and rebuilds its index. */
export function snippet( file, status ) {
	wp( 'eval', `$m = new \\FluentSnippets\\App\\Model\\Snippet(); $s = $m->findByFileName( ${ JSON.stringify( file ) } );
		$s['meta']['status'] = ${ JSON.stringify( status ) }; $m->updateSnippet( ${ JSON.stringify( file ) }, $s['code'], $s['meta'] );
		\\FluentSnippets\\App\\Helpers\\Helper::cacheSnippetIndex();` );
}

/**
 * The Style tab of the Bricks element panel, inside its tab bar: a page-wide "Style" text also matched
 * the label of a select control.
 */
export const styleTab = ( page ) => page.locator( '#bricks-panel-tabs > li' ).filter( { has: page.locator( 'span.label', { hasText: /^Style$/ } ) } ).first();

/** Title of the Motion group of the element panel (its control group key, not its translated title). */
export const motionGroupTitle = ( page ) => page.locator( '[data-control-group="lm_motion"] .control-group-title' ).first();
