<?php
/**
 * Bench checks of `wp lumia images migrate` (spec 5, 9.1, 9.3, 9.9) on the media seeded by
 * seed-legacy.php. Bench only, never shipped. Driven by assert-migration.sh; one phase per call:
 *
 *   run.sh assert <stack> tools/e2e-images/assert-migration.php snapshot <name>
 *       database + uploads fingerprint written to out/<name>.json (dry-run comparison)
 *   run.sh assert <stack> tools/e2e-images/assert-migration.php interrupted
 *       after a run killed (kill -9) at the "files_written" step of one item
 *   run.sh assert <stack> tools/e2e-images/assert-migration.php final
 *       after the complete migration
 *   run.sh assert <stack> tools/e2e-images/assert-migration.php delete
 *       permanent deletion of a migrated item takes its legacy files with it
 *
 * Exit code 1 when a check fails.
 */

use Lumia\Tools\Modules\ImageOptimizer\AvifState;

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

global $am_failures, $wpdb;
$am_failures = 0;

function am_check( bool $ok, string $label ): void {
	global $am_failures;
	WP_CLI::log( ( $ok ? '  ok   ' : '  FAIL ' ) . $label );
	if ( ! $ok ) {
		++$am_failures;
	}
}

function am_manifest(): array {
	$raw = file_get_contents( '/bench/out/mig-manifest.json' );
	$m   = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $m ) ) {
		WP_CLI::error( 'out/mig-manifest.json missing: run seed-legacy.php first.' );
	}
	return $m;
}

function am_abs( string $rel ): string {
	return wp_upload_dir( null, false )['basedir'] . '/' . ltrim( $rel, '/' );
}

function am_url( string $rel ): string {
	return wp_upload_dir( null, false )['baseurl'] . '/' . ltrim( $rel, '/' );
}

/**
 * GET through the site's public URL (the loopback sidecar), returns [status, content-type].
 */
function am_get( string $url, string $accept = '*/*' ): array {
	$response = wp_remote_get(
		$url,
		[
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => [ 'Accept' => $accept ],
		]
	);
	if ( is_wp_error( $response ) ) {
		return [ 0, $response->get_error_message() ];
	}
	$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
	return [ (int) wp_remote_retrieve_response_code( $response ), trim( explode( ';', $type )[0] ) ];
}

function am_type_of( string $path ): string {
	$ext = strtolower( pathinfo( preg_replace( '/\?.*$/', '', $path ), PATHINFO_EXTENSION ) );
	return [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	][ $ext ] ?? 'unknown';
}

/**
 * Upload URLs found in a text (any extension), absolute.
 *
 * @return string[]
 */
function am_upload_urls( string $text ): array {
	$base = preg_quote( wp_upload_dir( null, false )['baseurl'], '#' );
	preg_match_all( '#' . $base . '/[A-Za-z0-9._/-]+\.(?:jpe?g|png|avif|webp)#i', $text, $m );
	return array_values( array_unique( $m[0] ) );
}

function am_legacy_metas( int $id ): array {
	global $wpdb;
	return $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND ( meta_key LIKE %s OR meta_key IN ( '_lumia_backup_file', '_lumia_fallback_files' ) )", $id, $wpdb->esc_like( '_lumia_optimized' ) . '%' ) );
}

$phase = $args[0] ?? '';
$m     = am_manifest();

// ================================================================== snapshot
if ( 'snapshot' === $phase ) {
	$name = preg_replace( '/[^a-z0-9-]/', '', (string) ( $args[1] ?? 'snapshot' ) );

	$db = [];
	foreach ( $wpdb->get_results( "SELECT ID, post_content, post_excerpt, post_mime_type, guid, post_modified FROM {$wpdb->posts} ORDER BY ID", ARRAY_N ) as $row ) {
		$db[] = implode( "\x1f", $row );
	}
	foreach ( $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_N ) as $row ) {
		$db[] = implode( "\x1f", $row );
	}
	// Transients excluded: the capability probe caches its result (not a migration write).
	foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_name", ARRAY_N ) as $row ) {
		$db[] = implode( "\x1f", $row );
	}

	$files = [];
	$base  = wp_upload_dir( null, false )['basedir'];
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$files[] = substr( $file->getPathname(), strlen( $base ) ) . ' ' . $file->getSize() . ' ' . $file->getMTime() . ' ' . md5_file( $file->getPathname() );
		}
	}
	sort( $files );

	file_put_contents(
		'/bench/out/' . $name . '.json',
		wp_json_encode(
			[
				'db'    => md5( implode( "\x1e", $db ) ),
				'rows'  => count( $db ),
				'files' => md5( implode( "\n", $files ) ),
				'count' => count( $files ),
			]
		)
	);
	WP_CLI::success( "snapshot {$name}: " . count( $db ) . ' rows, ' . count( $files ) . ' files' );
	return;
}

$legacy_keys = [ 'big-photo', 'big-lost', 'photo', 'alpha', 'logo', 'webp-photo', 'collide', 'backup', 'seq-a', 'seq-b', 'late', 'late-backup' ];

/**
 * md5 of every file of the attachments that took the reserved names (take-names phase).
 */
function am_taken_files(): array {
	$taken = json_decode( (string) @file_get_contents( '/bench/out/mig-taken.json' ), true );
	$now   = [];
	foreach ( (array) ( $taken['ids'] ?? [] ) as $id ) {
		$meta  = wp_get_attachment_metadata( (int) $id );
		$files = [ (string) get_post_meta( (int) $id, '_wp_attached_file', true ) ];
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			$files[] = dirname( $files[0] ) . '/' . $size['file'];
		}
		foreach ( $files as $rel ) {
			$now[ $rel ] = is_file( am_abs( $rel ) ) ? md5_file( am_abs( $rel ) ) : 'missing';
		}
	}
	return [ (array) ( $taken['files'] ?? [] ), $now ];
}

/**
 * The item is exactly as the former pipeline left it, nothing written for it.
 */
function am_intact( string $key, array $m ): void {
	$id = (int) $m['ids'][ $key ];
	am_check( (bool) am_legacy_metas( $id ), "{$key}: legacy metas still there" );
	am_check( '' === get_post_meta( $id, '_lumia_migration', true ), "{$key}: no journal left" );
	am_check( $m['legacy'][ $key ][0] === get_post_meta( $id, '_wp_attached_file', true ) && 'image/avif' === get_post_mime_type( $id ), "{$key}: attached file and MIME unchanged (" . get_post_meta( $id, '_wp_attached_file', true ) . ')' );
	$meta = wp_get_attachment_metadata( $id );
	am_check( $m['legacy'][ $key ][0] === ( $meta['file'] ?? '' ), "{$key}: metadata unchanged" );
	$gone = array_filter( $m['legacy'][ $key ], static fn( $rel ) => ! is_file( am_abs( $rel ) ) );
	am_check( ! $gone, "{$key}: legacy files on disk" );
}

// ================================================================== interrupted
if ( 'interrupted' === $phase ) {
	WP_CLI::log( 'After a run killed at "files_written"' );

	$journaled = [];
	foreach ( $legacy_keys as $key ) {
		$id      = (int) $m['ids'][ $key ];
		$journal = get_post_meta( $id, '_lumia_migration', true );
		if ( is_array( $journal ) ) {
			$journaled[ $key ] = $journal;
		}
	}
	am_check( 1 === count( $journaled ), 'exactly one item has a journal (' . implode( ', ', array_keys( $journaled ) ) . ')' );

	foreach ( $journaled as $key => $journal ) {
		$id = (int) $m['ids'][ $key ];
		am_check( 'files_written' === ( $journal['step'] ?? '' ), "{$key}: journal at files_written (" . ( $journal['step'] ?? '?' ) . ')' );
		am_check( (bool) am_legacy_metas( $id ), "{$key}: legacy metas still there (not migrated yet)" );
		am_check( (bool) preg_match( '/\.(avif|webp)$/', (string) get_post_meta( $id, '_wp_attached_file', true ) ), "{$key}: attached file still the legacy one" );
		$missing = array_filter( (array) ( $journal['created'] ?? [] ), static fn( $rel ) => ! is_file( am_abs( (string) $rel ) ) );
		am_check( ! empty( $journal['created'] ) && ! $missing, "{$key}: the files written are on disk (" . count( (array) ( $journal['created'] ?? [] ) ) . ')' );

		// Identity of those files: the resumed run must not write them again (final phase).
		$identity = [];
		foreach ( (array) ( $journal['created'] ?? [] ) as $rel ) {
			$identity[ (string) $rel ] = @fileinode( am_abs( (string) $rel ) ) . ':' . @filemtime( am_abs( (string) $rel ) );
		}
		file_put_contents( '/bench/out/mig-interrupted.json', wp_json_encode( [ 'key' => $key, 'files' => $identity ] ) );
	}

	foreach ( $legacy_keys as $key ) {
		foreach ( $m['legacy'][ $key ] as $rel ) {
			if ( ! is_file( am_abs( $rel ) ) ) {
				am_check( false, "{$key}: legacy file {$rel} still on disk" );
			}
		}
	}

	// Every URL the database holds still answers: the old names are still on disk.
	$page = get_post( (int) $m['page'] );
	$urls = am_upload_urls( (string) $page->post_content . ' ' . (string) get_post_meta( (int) $m['page'], 'rank_math_facebook_image', true ) );
	$bad  = [];
	foreach ( $urls as $url ) {
		[ $status, $type ] = am_get( $url );
		if ( 200 !== $status ) {
			$bad[] = "{$url} ({$status})";
		}
	}
	am_check( count( $urls ) >= 10 && ! $bad, count( $urls ) . ' URLs of the page answer 200' . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );

	exit( $am_failures > 0 ? 1 : 0 );
}

// ================================================================== metas-failure
// seq-b resumed at files_written, its database step forced to fail, seq-a migrated just
// before it in the same run: seq-a's files must all survive seq-b's cleanup.
if ( 'metas-failure' === $phase ) {
	WP_CLI::log( 'A database step failing on a resumed item' );

	$id   = (int) $m['ids']['seq-a'];
	$meta = wp_get_attachment_metadata( $id );
	am_check( ! am_legacy_metas( $id ) && 'e2e-legacy/seq-a.jpg' === get_post_meta( $id, '_wp_attached_file', true ), 'seq-a: migrated in the same run' );
	$files = [ $meta['file'] ];
	foreach ( (array) $meta['sizes'] as $size ) {
		$files[] = 'e2e-legacy/' . $size['file'];
	}
	$missing = [];
	foreach ( $files as $rel ) {
		foreach ( [ $rel, $rel . '.avif' ] as $file ) {
			if ( ! is_file( am_abs( $file ) ) ) {
				$missing[] = $file;
			} else {
				[ $status ] = am_get( am_url( $file ) );
				if ( 200 !== $status ) {
					$missing[] = "{$file} ({$status})";
				}
			}
		}
	}
	am_check( ! $missing, 'seq-a: every fallback and sibling still on disk and served (' . ( 2 * count( $files ) ) . ')' . ( $missing ? ': missing ' . implode( ', ', $missing ) : '' ) );

	am_intact( 'seq-b', $m );
	$left = glob( am_abs( 'e2e-legacy/seq-b*.jpg' ) ) ?: [];
	$left = array_merge( $left, glob( am_abs( 'e2e-legacy/seq-b*.jpg.avif' ) ) ?: [] );
	am_check( ! $left, 'seq-b: the files it wrote were removed' . ( $left ? ' (left: ' . implode( ', ', array_map( 'basename', $left ) ) . ')' : '' ) );

	exit( $am_failures > 0 ? 1 : 0 );
}

// ================================================================== take-names
// late and late-backup were killed at "planned": other uploads now take their reserved names.
if ( 'take-names' === $phase ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	foreach ( [ 'late', 'late-backup' ] as $key ) {
		$journal = get_post_meta( (int) $m['ids'][ $key ], '_lumia_migration', true );
		am_check( is_array( $journal ) && 'planned' === $journal['step'], "{$key}: journal at planned (" . ( is_array( $journal ) ? $journal['step'] : 'none' ) . ')' );
	}

	add_filter(
		'upload_dir',
		static function ( array $u ): array {
			$u['subdir'] = '/e2e-legacy';
			$u['path']   = $u['basedir'] . '/e2e-legacy';
			$u['url']    = $u['baseurl'] . '/e2e-legacy';
			return $u;
		}
	);
	$ids = [];
	foreach ( [ 'late.jpg', 'late-backup.jpg' ] as $name ) {
		$tmp = wp_tempnam( $name );
		copy( '/bench/out/fixtures/photo-p3.jpg', $tmp );
		$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], 0 );
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		update_post_meta( (int) $id, '_lumia_e2e_seed', 1 ); // Removed by the next seed.
		am_check( 'e2e-legacy/' . $name === get_post_meta( (int) $id, '_wp_attached_file', true ), "another upload took {$name} (#{$id})" );
		$ids[] = (int) $id;
	}
	file_put_contents( '/bench/out/mig-taken.json', wp_json_encode( [ 'ids' => $ids, 'files' => [] ] ) );
	[ , $now ] = am_taken_files();
	file_put_contents( '/bench/out/mig-taken.json', wp_json_encode( [ 'ids' => $ids, 'files' => $now ] ) );
	WP_CLI::log( 'ids ' . implode( ',', $ids ) );

	exit( $am_failures > 0 ? 1 : 0 );
}

// ================================================================== taken-check
if ( 'taken-check' === $phase ) {
	WP_CLI::log( 'Resume at "planned" after the names were taken' );
	[ $before, $now ] = am_taken_files();
	am_check( count( $before ) >= 8 && $before === $now, 'the other uploads are untouched (' . count( $now ) . ' files, same md5)' );
	am_intact( 'late', $m );
	am_intact( 'late-backup', $m );
	am_check( ! is_file( am_abs( 'e2e-legacy/late-1.jpg' ) ) && ! is_file( am_abs( 'e2e-legacy/late-backup-1.jpg' ) ), 'nothing written under another name yet' );

	exit( $am_failures > 0 ? 1 : 0 );
}

// ================================================================== final
if ( 'final' === $phase ) {
	$serving = class_exists( '\Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe' ) && \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::is_serving();
	am_check( $serving, 'AVIF delivery confirmed on this stack (siblings expected)' );

	$expect = [
		'big-photo'  => [ 'guid' => 'big-photo.jpg', 'main' => 'e2e-legacy/big-photo-scaled.jpg', 'mime' => 'image/jpeg', 'status' => 'pending', 'sibling' => false, 'original' => 'big-photo.jpg' ],
		'big-lost'   => [ 'guid' => 'big-lost.jpg', 'main' => 'e2e-legacy/big-lost-scaled.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'photo'      => [ 'guid' => 'photo.jpg', 'main' => 'e2e-legacy/photo.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'alpha'      => [ 'guid' => 'alpha.png', 'main' => 'e2e-legacy/alpha.png', 'mime' => 'image/png', 'status' => null, 'sibling' => null, 'original' => null ],
		'logo'       => [ 'guid' => 'logo.png', 'main' => 'e2e-legacy/logo.png', 'mime' => 'image/png', 'status' => null, 'sibling' => null, 'original' => null ],
		'webp-photo' => [ 'guid' => 'webp-photo.jpg', 'main' => 'e2e-legacy/webp-photo.jpg', 'mime' => 'image/jpeg', 'status' => 'pending', 'sibling' => false, 'original' => null ],
		'collide'    => [ 'guid' => 'collide-1.jpg', 'main' => 'e2e-legacy/collide-1.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'backup'     => [ 'guid' => 'backup.jpg', 'main' => 'e2e-legacy/backup.jpg', 'mime' => 'image/jpeg', 'status' => 'pending', 'sibling' => false, 'original' => null ],
		'seq-a'      => [ 'guid' => 'seq-a.jpg', 'main' => 'e2e-legacy/seq-a.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'seq-b'      => [ 'guid' => 'seq-b.jpg', 'main' => 'e2e-legacy/seq-b.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'late'       => [ 'guid' => 'late-1.jpg', 'main' => 'e2e-legacy/late-1.jpg', 'mime' => 'image/jpeg', 'status' => 'done', 'sibling' => true, 'original' => null ],
		'late-backup' => [ 'guid' => 'late-backup-1.jpg', 'main' => 'e2e-legacy/late-backup-1.jpg', 'mime' => 'image/jpeg', 'status' => 'pending', 'sibling' => false, 'original' => null ],
	];

	foreach ( $expect as $key => $e ) {
		$id   = (int) $m['ids'][ $key ];
		$meta = wp_get_attachment_metadata( $id );
		WP_CLI::log( "{$key} (#{$id})" );

		am_check( ! am_legacy_metas( $id ), "{$key}: legacy metas removed" );
		am_check( '' === get_post_meta( $id, '_lumia_migration', true ), "{$key}: journal removed" );
		am_check( $e['main'] === get_post_meta( $id, '_wp_attached_file', true ), "{$key}: attached file " . get_post_meta( $id, '_wp_attached_file', true ) );
		am_check( $e['main'] === ( $meta['file'] ?? '' ), "{$key}: metadata file " . ( $meta['file'] ?? '' ) );
		am_check( $e['mime'] === get_post_mime_type( $id ), "{$key}: MIME " . get_post_mime_type( $id ) );
		am_check( is_file( am_abs( $e['main'] ) ) && $e['mime'] === wp_get_image_mime( am_abs( $e['main'] ) ), "{$key}: main file is a real " . $e['mime'] );
		am_check( (int) ( $meta['filesize'] ?? 0 ) === (int) @filesize( am_abs( $e['main'] ) ), "{$key}: metadata filesize matches the file" );
		am_check( str_ends_with( (string) get_post_field( 'guid', $id, 'raw' ), '/' . $e['guid'] ), "{$key}: guid " . get_post_field( 'guid', $id, 'raw' ) );

		if ( null === $e['original'] ) {
			am_check( empty( $meta['original_image'] ), "{$key}: no original_image in the metadata" );
		} else {
			am_check( ( $meta['original_image'] ?? '' ) === $e['original'] && is_file( am_abs( 'e2e-legacy/' . $e['original'] ) ), "{$key}: original_image {$e['original']} kept" );
		}

		// Sizes: real files of the fallback type, mime-type and filesize right.
		$dir      = dirname( $e['main'] ) . '/';
		$bad      = [];
		$siblings = 0;
		$files    = [ $e['main'] ];
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			$rel     = $dir . $size['file'];
			$files[] = $rel;
			if ( ! is_file( am_abs( $rel ) ) || $e['mime'] !== $size['mime-type'] || $e['mime'] !== wp_get_image_mime( am_abs( $rel ) ) || (int) ( $size['filesize'] ?? 0 ) !== filesize( am_abs( $rel ) ) ) {
				$bad[] = $name;
			}
		}
		am_check( count( $meta['sizes'] ?? [] ) >= 1 && ! $bad, "{$key}: " . count( $meta['sizes'] ?? [] ) . ' sizes, files and metadata consistent' . ( $bad ? ' (bad: ' . implode( ', ', $bad ) . ')' : '' ) );
		if ( 'collide' === $key ) {
			$names = array_column( (array) $meta['sizes'], 'file' );
			am_check( ! preg_grep( '/^collide-(?!1-)/', $names ), 'collide: the whole family moved to collide-1 (' . implode( ', ', $names ) . ')' );
		}

		// Siblings: the legacy AVIF under the fallback's name (hard link), only in case 2.
		$state = AvifState::get( $id );
		foreach ( $files as $rel ) {
			if ( is_file( am_abs( $rel ) . '.avif' ) ) {
				++$siblings;
			}
		}
		if ( true === $e['sibling'] ) {
			am_check( count( $files ) === $siblings, "{$key}: every fallback has its .avif sibling ({$siblings}/" . count( $files ) . ')' );
			$legacy_main = $m['legacy'][ $key ][0];
			am_check( @fileinode( am_abs( $e['main'] . '.avif' ) ) === @fileinode( am_abs( $legacy_main ) ), "{$key}: sibling is a hard link to {$legacy_main}" );
		} elseif ( false === $e['sibling'] ) {
			am_check( 0 === $siblings, "{$key}: no sibling (queued for the encoder)" );
		}
		if ( null !== $e['status'] ) {
			am_check( $e['status'] === $state['status'], "{$key}: AVIF status {$state['status']}" );
		} else {
			// Kept only when the legacy AVIF is at most 90 % of the fallback: done, else queued.
			am_check( ( 'done' === $state['status'] && count( $files ) === $siblings ) || ( 'pending' === $state['status'] ), "{$key}: AVIF status {$state['status']} with {$siblings} sibling(s)" );
		}
		if ( 'done' === $state['status'] ) {
			$fresh = array_filter( $files, static fn( $rel ) => AvifState::is_fresh( $id, $rel ) );
			am_check( count( $fresh ) === count( $files ), "{$key}: kept siblings recorded fresh (" . count( $fresh ) . '/' . count( $files ) . ')' );
		}

		// Legacy files: still on disk, listed for the deletion.
		$listed = (array) get_post_meta( $id, AvifState::LEGACY, true );
		$gone   = array_filter( $m['legacy'][ $key ], static fn( $rel ) => ! is_file( am_abs( $rel ) ) );
		am_check( ! $gone, "{$key}: legacy files still on disk" . ( $gone ? ' (missing: ' . implode( ', ', $gone ) . ')' : '' ) );
		$unlisted = array_diff( array_filter( $m['legacy'][ $key ], static fn( $rel ) => is_file( am_abs( $rel ) ) && ! preg_match( '/\.(jpe?g|png)$/', $rel ) ), $listed );
		am_check( ! $unlisted, "{$key}: legacy files listed in _lumia_avif_legacy" . ( $unlisted ? ' (not listed: ' . implode( ', ', $unlisted ) . ')' : '' ) );
		if ( 'backup' === $key ) {
			am_check( in_array( $m['backup'], $listed, true ), 'backup: the former backup copy is listed (leaves with the item)' );
		}
		if ( 'late-backup' === $key ) {
			am_check( in_array( $m['backup_late'], $listed, true ), 'late-backup: the former backup copy is listed (leaves with the item)' );
		}

		// The old URLs still answer, with their own type.
		$bad = [];
		foreach ( $m['legacy'][ $key ] as $rel ) {
			if ( preg_match( '/\.(avif|webp)$/', $rel ) ) {
				[ $status, $type ] = am_get( am_url( $rel ) );
				if ( 200 !== $status || am_type_of( $rel ) !== $type ) {
					$bad[] = "{$rel} {$status} {$type}";
				}
			}
		}
		am_check( ! $bad, "{$key}: every old .avif/.webp URL answers 200" . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );

		// The new URLs: the fallback for */*.
		$bad = [];
		foreach ( $files as $rel ) {
			[ $status, $type ] = am_get( am_url( $rel ) );
			if ( 200 !== $status || $e['mime'] !== $type ) {
				$bad[] = "{$rel} {$status} {$type}";
			}
		}
		am_check( ! $bad, "{$key}: every new URL answers 200 {$e['mime']} to */*" . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );
	}

	// The item killed at files_written resumed at the database step: its files were not rewritten.
	$killed = json_decode( (string) @file_get_contents( '/bench/out/mig-interrupted.json' ), true );
	if ( is_array( $killed ) && ! empty( $killed['files'] ) ) {
		$rewritten = [];
		foreach ( $killed['files'] as $rel => $identity ) {
			if ( @fileinode( am_abs( $rel ) ) . ':' . @filemtime( am_abs( $rel ) ) !== $identity ) {
				$rewritten[] = $rel;
			}
		}
		am_check( ! $rewritten, "{$killed['key']}: resumed at the database step, its " . count( $killed['files'] ) . ' files not written again' . ( $rewritten ? ' (rewritten: ' . implode( ', ', $rewritten ) . ')' : '' ) );
	}

	$photo_meta = wp_get_attachment_metadata( (int) $m['ids']['photo'] );
	am_check( 'C:\\Studio\\photo "x"' === ( $photo_meta['image_meta']['copyright'] ?? '' ), 'photo: a backslash in image_meta survives the journal and the metadata write (' . ( $photo_meta['image_meta']['copyright'] ?? '' ) . ')' );

	// The uploads that took late.jpg / late-backup.jpg are still untouched after the full run.
	if ( is_file( '/bench/out/mig-taken.json' ) ) {
		[ $before, $now ] = am_taken_files();
		am_check( $before && $before === $now, 'late.jpg / late-backup.jpg (other uploads) untouched after the full run' );
	}

	// The upload that took the name collide.jpg is untouched.
	$other = (int) $m['ids']['collide-new'];
	$meta  = wp_get_attachment_metadata( $other );
	am_check( 'e2e-legacy/collide.jpg' === get_post_meta( $other, '_wp_attached_file', true ) && is_file( am_abs( 'e2e-legacy/collide.jpg' ) ) && 'e2e-legacy/collide.jpg' === $meta['file'], 'collide.jpg (another item) untouched' );

	// --- URLs in the database and in Bricks' CSS --------------------------------------
	WP_CLI::log( 'URLs' );
	$legacy_re = '#/e2e-legacy/[A-Za-z0-9._-]+\.(?:avif|webp)#';
	$page_id   = (int) $m['page'];
	$content   = (string) get_post_field( 'post_content', $page_id, 'raw' );
	am_check( ! preg_match( $legacy_re, $content ), 'post_content: no legacy URL left' );
	foreach ( [ 'photo.jpg', 'photo-1024x768.jpg', 'photo-300x225.jpg', 'big-photo-scaled.jpg', 'big-photo-300x225.jpg', 'big-lost-scaled.jpg', 'alpha.png', 'logo.png', 'webp-photo.jpg', 'collide-1.jpg', 'backup.jpg' ] as $name ) {
		am_check( false !== strpos( $content, '/e2e-legacy/' . $name ), "post_content: {$name}" );
	}

	$bricks = get_post_meta( $page_id, '_bricks_page_content_2', true );
	am_check( is_array( $bricks ) && str_ends_with( $bricks[0]['settings']['image']['url'], '/e2e-legacy/logo.png' ) && str_ends_with( $bricks[1]['settings']['_background']['image']['url'], '/e2e-legacy/big-lost-scaled.jpg' ), 'Bricks serialized meta: logo.png, big-lost-scaled.jpg' );
	am_check( str_ends_with( (string) get_post_meta( $page_id, 'rank_math_facebook_image', true ), '/e2e-legacy/photo.jpg' ), 'Rank Math meta: photo.jpg' );
	am_check( false !== strpos( (string) get_post_meta( $page_id, '_e2e_json', true ), '\/e2e-legacy\/webp-photo.jpg' ), 'escaped JSON meta: webp-photo.jpg' );
	$option = get_option( 'lumia_e2e_legacy_hero' );
	am_check( is_array( $option ) && str_ends_with( $option['image'], '/e2e-legacy/collide-1.jpg' ), 'option: collide-1.jpg' );

	$css = (string) file_get_contents( am_abs( $m['css'] ) );
	am_check( ! preg_match( $legacy_re, $css ) && false !== strpos( $css, '/e2e-legacy/photo.jpg)' ) && false !== strpos( $css, '/e2e-legacy/big-lost-scaled.jpg"' ), 'Bricks CSS file rewritten (photo.jpg, big-lost-scaled.jpg)' );
	am_check( false === get_option( 'lumia_module_image_optimizer_migration_pairs' ), 'pending CSS pairs option cleared' );

	$left = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key NOT IN ( '_lumia_avif_legacy', '_lumia_migration' ) AND meta_value REGEXP %s", '/e2e-legacy/[A-Za-z0-9._-]+\\.(avif|webp)' ) );
	am_check( 0 === (int) $left, "postmeta: no legacy URL left ({$left})" );

	exit( $am_failures > 0 ? 1 : 0 );
}

// ================================================================== delete
if ( 'delete' === $phase ) {
	$id    = (int) $m['ids']['photo'];
	$meta  = wp_get_attachment_metadata( $id );
	$dir   = dirname( (string) $meta['file'] ) . '/';
	$files = [ $meta['file'] ];
	foreach ( (array) $meta['sizes'] as $size ) {
		$files[] = $dir . $size['file'];
	}
	$legacy = (array) get_post_meta( $id, AvifState::LEGACY, true );
	am_check( count( $legacy ) >= 3, 'photo: ' . count( $legacy ) . ' legacy files listed before the deletion' );

	wp_delete_attachment( $id, true );

	$left = [];
	foreach ( array_merge( $legacy, $files, array_map( static fn( $rel ) => $rel . '.avif', $files ) ) as $rel ) {
		if ( file_exists( am_abs( $rel ) ) ) {
			$left[] = $rel;
		}
	}
	am_check( ! $left, 'permanent deletion: fallbacks, siblings and legacy files gone' . ( $left ? ' (left: ' . implode( ', ', $left ) . ')' : '' ) );
	am_check( is_file( am_abs( 'e2e-legacy/collide.jpg' ) ), 'other items untouched' );

	exit( $am_failures > 0 ? 1 : 0 );
}

WP_CLI::error( 'usage: assert-migration.php snapshot <name> | interrupted | final | delete' );
