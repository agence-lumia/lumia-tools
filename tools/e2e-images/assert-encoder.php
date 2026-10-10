<?php
/**
 * Assertions for AvifEncoder, JpegMetadata and the capabilities of ImageProcessor (task 3).
 * Bench only, never shipped.
 *
 *   tools/e2e-images/run.sh assert nginx tools/e2e-images/assert-encoder.php
 *
 * Runs under the web PHP of the stack (PHP-FPM image: Imagick with AVIF, JPEG, PNG, lcms), the
 * runtime that encodes in production. The `cli` image has no usable Imagick: its half is
 * assert-encoder-cli.php (`run.sh wp-cron nginx eval-file`-like, see the README).
 *
 * Needs the fixtures (`run.sh import-fixtures nginx`) and the plugin installed
 * (`run.sh install-lumia nginx`).
 */

use Lumia\Tools\Modules\ImageOptimizer\AvifEncoder;
use Lumia\Tools\Modules\ImageOptimizer\EncodeResult;
use Lumia\Tools\Modules\ImageOptimizer\ImageProcessor;
use Lumia\Tools\Modules\ImageOptimizer\JpegMetadata;

$GLOBALS['enc_failures'] = 0;
$GLOBALS['enc_checks']   = 0;

function enc_check( bool $ok, string $label, string $detail = '' ): void {
	++$GLOBALS['enc_checks'];
	if ( ! $ok ) {
		++$GLOBALS['enc_failures'];
	}
	printf( "[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " ({$detail})" : '' );
}

/**
 * Fields of the av1C box of an AVIF: chroma subsampling and bit depth.
 *
 * @return array{x:int,y:int,mono:int,depth:int}|null
 */
function enc_av1c( string $path ): ?array {
	$data = (string) file_get_contents( $path );
	$pos  = strpos( $data, 'av1C' );
	if ( false === $pos || strlen( $data ) < $pos + 8 ) {
		return null;
	}
	$byte2 = ord( $data[ $pos + 4 + 2 ] );
	$high  = ( $byte2 >> 6 ) & 1;
	$twelv = ( $byte2 >> 5 ) & 1;

	return array(
		'mono'  => ( $byte2 >> 4 ) & 1,
		'x'     => ( $byte2 >> 3 ) & 1,
		'y'     => ( $byte2 >> 2 ) & 1,
		'depth' => $high ? ( $twelv ? 12 : 10 ) : 8,
	);
}

/**
 * Names (not paths) of the leftovers of the encoder in a folder: any `.avif` file or temporary.
 *
 * @return string[]
 */
function enc_leftovers( string $dir ): array {
	$out = array();
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		if ( str_contains( $name, '.avif' ) ) {
			$out[] = $name;
		}
	}

	return $out;
}

function enc_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $name ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}
		is_dir( "{$dir}/{$name}" ) ? enc_rrmdir( "{$dir}/{$name}" ) : unlink( "{$dir}/{$name}" );
	}
	rmdir( $dir );
}

/**
 * Mean colour of an image, as [r, g, b] 0-255.
 *
 * @return int[]
 */
function enc_mean_rgb( Imagick $image ): array {
	$probe = clone $image;
	$probe->setImageColorspace( Imagick::COLORSPACE_SRGB );
	$probe->scaleImage( 1, 1 );
	$color = $probe->getImagePixelColor( 0, 0 )->getColor();
	$probe->clear();

	return array( $color['r'], $color['g'], $color['b'] );
}

/**
 * @param int[] $a
 * @param int[] $b
 */
function enc_dist( array $a, array $b ): float {
	return sqrt( ( $a[0] - $b[0] ) ** 2 + ( $a[1] - $b[1] ) ** 2 + ( $a[2] - $b[2] ) ** 2 );
}

$fixtures = '/bench/out/fixtures';
$upload   = wp_upload_dir();
$work     = $upload['basedir'] . '/lumia-encoder-test';
enc_rrmdir( $work );
mkdir( $work, 0755, true );
$GLOBALS['enc_fixtures'] = $fixtures;
$GLOBALS['enc_work']     = $work;

/**
 * Copy of a fixture into a fresh sub-folder of the work folder, so that each case sees only its
 * own leftovers. Returns the copy's path.
 */
function enc_copy( string $fixture, string $case ): string {
	$fixtures = $GLOBALS['enc_fixtures'];
	$dir      = "{$GLOBALS['enc_work']}/{$case}";
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	$dest = "{$dir}/" . basename( $fixture );
	copy( "{$fixtures}/{$fixture}", $dest );

	return $dest;
}

$processor = new ImageProcessor();
$caps      = $processor->get_capabilities();

echo "== Capabilities ==\n";
enc_check( 'imagick' === ( $caps['avif_engine'] ?? null ), 'avif_engine is imagick', (string) ( $caps['avif_engine'] ?? 'missing' ) );
enc_check( true === ( $caps['heic_speed'] ?? null ), 'heic_speed honoured' );
enc_check( true === ( $caps['heic_chroma'] ?? null ), 'heic_chroma honoured' );
enc_check( true === ( $caps['can_encode_here'] ?? null ), 'can_encode_here in the web PHP' );
enc_check( isset( $caps['avif'], $caps['imagick_avif'], $caps['editor'] ), 'previous keys kept' );

echo "== Quality, speed, 4:4:4 (2560 px photo) ==\n";
$big = new Imagick( "{$fixtures}/photo-4000.jpg" );
$big->resizeImage( 2560, 1920, Imagick::FILTER_LANCZOS, 1 );
$big->setImageCompressionQuality( 95 );
$q_dir = "{$work}/quality";
mkdir( $q_dir, 0755, true );
$big->writeImage( "{$q_dir}/photo-2560.jpg" );
$big->clear();

$res = array();
foreach ( array( 30, 90 ) as $q ) {
	$encoder = new AvifEncoder( $q, 'balanced', true, $processor );
	$res[ $q ] = $encoder->encode( "{$q_dir}/photo-2560.jpg" );
	enc_check( EncodeResult::DONE === $res[ $q ]->status, "q{$q} encodes", $res[ $q ]->status . ' ' . $res[ $q ]->error );
	if ( 30 === $q ) {
		copy( "{$q_dir}/photo-2560.jpg.avif", "{$q_dir}/q30.avif" );
	}
}
enc_check(
	null !== $res[30]->avif_bytes && null !== $res[90]->avif_bytes && $res[30]->avif_bytes < $res[90]->avif_bytes,
	'quality is honoured: q30 smaller than q90',
	sprintf( 'q30=%d q90=%d', (int) $res[30]->avif_bytes, (int) $res[90]->avif_bytes )
);
enc_check( $res[90]->source_bytes === filesize( "{$q_dir}/photo-2560.jpg" ), 'source_bytes is the source size' );
enc_check( $res[90]->avif_bytes === filesize( "{$q_dir}/photo-2560.jpg.avif" ), 'avif_bytes is the written size' );
$av1c = enc_av1c( "{$q_dir}/photo-2560.jpg.avif" );
enc_check( null !== $av1c && 0 === $av1c['x'] && 0 === $av1c['y'], 'av1C chroma_subsampling = 4:4:4', json_encode( $av1c ) );
enc_check( null !== $av1c && 8 === $av1c['depth'], 'AVIF is 8 bits', json_encode( $av1c ) );
enc_check( ( fileperms( "{$q_dir}/photo-2560.jpg.avif" ) & 0666 ) === ( fileperms( "{$q_dir}/photo-2560.jpg" ) & 0666 ), 'AVIF has the permissions of its source' );

// Speed 8 and 9 differ in CPU (aom runs several threads, so the wall clock barely moves): the
// user CPU time of the process is what the presets trade, and what the bulk spends.
$times = array( 'balanced' => array(), 'fast' => array() );
$walls = array( 'balanced' => array(), 'fast' => array() );
foreach ( array( 1, 2, 3 ) as $round ) {
	foreach ( array( 'balanced', 'fast' ) as $speed ) {
		$encoder = new AvifEncoder( 70, $speed, true, $processor );
		$usage0  = getrusage();
		$start   = microtime( true );
		$r       = $encoder->encode( "{$q_dir}/photo-2560.jpg" );
		$walls[ $speed ][] = microtime( true ) - $start;
		$usage1  = getrusage();
		$times[ $speed ][] = ( $usage1['ru_utime.tv_sec'] - $usage0['ru_utime.tv_sec'] ) + ( $usage1['ru_utime.tv_usec'] - $usage0['ru_utime.tv_usec'] ) / 1e6;
		unset( $r );
	}
}
enc_check(
	min( $times['fast'] ) < min( $times['balanced'] ),
	'fast uses less CPU than balanced',
	sprintf(
		'cpu fast=%.2fs balanced=%.2fs, wall fast=%.2fs balanced=%.2fs',
		min( $times['fast'] ),
		min( $times['balanced'] ),
		min( $walls['fast'] ),
		min( $walls['balanced'] )
	)
);

echo "== ICC handling ==\n";
$src = enc_copy( 'photo-bigicc.jpg', 'bigicc' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'photo-bigicc.jpg encodes', $r->status . ' ' . $r->error );
$out      = new Imagick( "{$src}.avif" );
$out_prof = $out->getImageProfiles( 'icc', true );
enc_check( ! isset( $out_prof['icc'] ), 'photo-bigicc.jpg: no ICC profile left in the AVIF', isset( $out_prof['icc'] ) ? strlen( $out_prof['icc'] ) . ' bytes' : 'none' );
$origin   = new Imagick( $src );
$raw      = clone $origin;
$raw->removeImageProfile( 'icc' );
$conv     = clone $origin;
$conv->profileImage( 'icc', (string) file_get_contents( LUMIA_PLUGIN_DIR . 'assets/icc/srgb.icc' ) );
$conv->removeImageProfile( 'icc' );
$m_avif = enc_mean_rgb( $out );
$m_raw  = enc_mean_rgb( $raw );
$m_conv = enc_mean_rgb( $conv );
enc_check(
	enc_dist( $m_avif, $m_conv ) < enc_dist( $m_avif, $m_raw ) && enc_dist( $m_avif, $m_conv ) < 3.0,
	'photo-bigicc.jpg: colours are converted to sRGB, not just stripped',
	sprintf( 'avif=%s converted=%s raw=%s', implode( ',', $m_avif ), implode( ',', $m_conv ), implode( ',', $m_raw ) )
);
foreach ( array( $out, $origin, $raw, $conv ) as $im ) {
	$im->clear();
}

$src = enc_copy( 'photo-p3.jpg', 'p3' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'photo-p3.jpg encodes', $r->status . ' ' . $r->error );
$origin   = new Imagick( $src );
$out      = new Imagick( "{$src}.avif" );
$src_prof = $origin->getImageProfiles( 'icc', true )['icc'] ?? '';
$out_prof = $out->getImageProfiles( 'icc', true )['icc'] ?? '';
enc_check( '' !== $out_prof && $out_prof === $src_prof, 'photo-p3.jpg: the small P3 profile is kept as is', strlen( $src_prof ) . ' / ' . strlen( $out_prof ) . ' bytes' );
$origin->clear();
$out->clear();

echo "== EXIF, XMP, orientation ==\n";
$src = enc_copy( 'photo-gps.jpg', 'gps' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'photo-gps.jpg encodes', $r->status . ' ' . $r->error );
$out   = new Imagick( "{$src}.avif" );
$names = $out->getImageProfiles( '*', false );
enc_check( ! array_intersect( array( 'exif', 'xmp', 'iptc' ), $names ), 'strip_exif: no exif/xmp/iptc profile in the AVIF', implode( ',', $names ) );
enc_check( in_array( 'icc', $names, true ), 'strip_exif keeps the (small) ICC profile', implode( ',', $names ) );
$out->clear();

$src = enc_copy( 'photo-exif6.jpg', 'exif6' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'photo-exif6.jpg encodes', $r->status . ' ' . $r->error );
$out = new Imagick( "{$src}.avif" );
enc_check( 600 === $out->getImageWidth() && 800 === $out->getImageHeight(), 'orientation 6: the AVIF is physically rotated (600x800)', $out->getImageWidth() . 'x' . $out->getImageHeight() );
$out->clear();

echo "== Colour spaces and depth ==\n";
$src = enc_copy( 'photo-16bit.png', 'png16' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, '16-bit PNG encodes', $r->status . ' ' . $r->error );
$av1c = enc_av1c( "{$src}.avif" );
enc_check( null !== $av1c && 8 === $av1c['depth'], '16-bit PNG gives an 8-bit AVIF', json_encode( $av1c ) );

$src = enc_copy( 'photo-cmyk.jpg', 'cmyk' );
$r   = ( new AvifEncoder( 50, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'CMYK JPEG encodes', $r->status . ' ' . $r->error );
if ( EncodeResult::DONE === $r->status ) {
	$out = new Imagick( "{$src}.avif" );
	enc_check( Imagick::COLORSPACE_CMYK !== $out->getImageColorspace() && ! isset( $out->getImageProfiles( 'icc', true )['icc'] ), 'CMYK JPEG gives an sRGB AVIF without profile' );
	$out->clear();
}

echo "== Size guard ==\n";
$src = enc_copy( 'logo-flat.png', 'logo' );
$r   = ( new AvifEncoder( 70, 'fast', true, $processor ) )->encode( $src );
$png = (int) filesize( $src );
if ( EncodeResult::SKIPPED === $r->status ) {
	enc_check( ! file_exists( "{$src}.avif" ) && array() === enc_leftovers( dirname( $src ) ), 'logo-flat.png q70: skipped, nothing left on disk', $r->error );
} else {
	enc_check( EncodeResult::DONE === $r->status && $r->avif_bytes <= 0.9 * $png, 'logo-flat.png q70: kept only because below 90% of the PNG', sprintf( 'png=%d avif=%d', $png, (int) $r->avif_bytes ) );
}
echo "  (logo-flat.png: png={$png} avif=" . ( $r->avif_bytes ?? 'none' ) . " status={$r->status})\n";

$tiny_dir = "{$work}/tiny";
mkdir( $tiny_dir, 0755, true );
$tiny = new Imagick();
$tiny->newImage( 8, 8, new ImagickPixel( '#336699' ) );
$tiny->setImageFormat( 'png' );
$tiny->writeImage( "{$tiny_dir}/tiny.png" );
$tiny->clear();
file_put_contents( "{$tiny_dir}/tiny.png.avif", 'stale sibling' );
$r = ( new AvifEncoder( 70, 'fast', true, $processor ) )->encode( "{$tiny_dir}/tiny.png" );
enc_check( EncodeResult::SKIPPED === $r->status && '' !== $r->error, 'tiny flat PNG: AVIF above 90%, skipped', $r->status . ' ' . $r->error );
enc_check( array() === enc_leftovers( $tiny_dir ), 'skipped: the previous .avif and the temporary are gone', implode( ',', enc_leftovers( $tiny_dir ) ) );

echo "== Refused options ==\n";
// An encoder that refuses heic:chroma: the file is encoded without it and the note says so.
$picky = new class() extends Imagick {
	public function setOption( $key, $value ): bool {
		if ( 'heic:chroma' === $key ) {
			throw new ImagickException( 'option refused by the test' );
		}

		return parent::setOption( $key, $value );
	}
};
$picky->newPseudoImage( 64, 64, 'plasma:fractal' );
$picky->setImageFormat( 'avif' );
$method = new ReflectionMethod( AvifEncoder::class, 'render_with_options' );
$note   = '';
$blob   = $method->invokeArgs( new AvifEncoder( 60, 'fast', true, $processor ), array( $picky, &$note ) );
enc_check( '' !== $blob && 'option ignored: heic:chroma' === $note, 'a refused option is dropped, not fatal', $note );

echo "== Failure, unsupported, missing ==\n";
$src = enc_copy( 'corrupt.jpg', 'corrupt' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::FAILED === $r->status && '' !== $r->error, 'corrupt.jpg: FAILED with a message', $r->status . ' ' . $r->error );
enc_check( array() === enc_leftovers( dirname( $src ) ) && 2 === count( (array) scandir( dirname( $src ) ) ) - 1, 'corrupt.jpg: no .avif and no temporary left', implode( ',', (array) scandir( dirname( $src ) ) ) );

$src = enc_copy( 'anim.gif', 'gif' );
$r   = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src );
enc_check( EncodeResult::SKIPPED === $r->status, 'anim.gif: skipped (not JPEG/PNG)', $r->status . ' ' . $r->error );

$r = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( "{$work}/does-not-exist.jpg" );
enc_check( EncodeResult::FAILED === $r->status && '' !== $r->error, 'missing source: FAILED with a message', $r->error );

echo "== Atomic write and can_commit ==\n";
$src       = enc_copy( 'photo-p3.jpg', 'atomic' );
$dir       = dirname( $src );
$seen      = array();
$called    = 0;
$r         = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode(
	$src,
	static function () use ( $dir, $src, &$seen, &$called ): bool {
		++$called;
		$seen = array(
			'final_exists' => file_exists( "{$src}.avif" ),
			'tmp'          => array_values( array_filter( enc_leftovers( $dir ), static fn( $n ) => str_starts_with( $n, '.' ) && str_contains( $n, '.avif.tmp-' ) ) ),
		);

		return true;
	}
);
enc_check( 1 === $called, 'can_commit is called once' );
enc_check( false === ( $seen['final_exists'] ?? null ) && 1 === count( $seen['tmp'] ?? array() ), 'before the rename: only a dot-prefixed temporary exists, no final .avif', json_encode( $seen ) );
enc_check( EncodeResult::DONE === $r->status && file_exists( "{$src}.avif" ) && array( 'photo-p3.jpg.avif' ) === enc_leftovers( $dir ), 'after the rename: final .avif only', implode( ',', enc_leftovers( $dir ) ) );

$src = enc_copy( 'photo-p3.jpg', 'stale' );
file_put_contents( "{$src}.avif", 'old sibling' );
$r = ( new AvifEncoder( 60, 'fast', true, $processor ) )->encode( $src, static fn(): bool => false );
enc_check( EncodeResult::SKIPPED === $r->status && 'stale' === $r->error, 'can_commit false: SKIPPED / stale', $r->status . ' ' . $r->error );
enc_check( array() === enc_leftovers( dirname( $src ) ), 'can_commit false: no .avif and no temporary left', implode( ',', enc_leftovers( dirname( $src ) ) ) );

echo "== GD fallback ==\n";
$gd_processor = new class() extends ImageProcessor {
	/**
	 * @return array<string, bool|string>
	 */
	public function get_capabilities(): array {
		return array_merge( parent::get_capabilities(), array( 'avif_engine' => 'gd' ) );
	}
};
$src = enc_copy( 'photo-p3.jpg', 'gd-p3' );
$r   = ( new AvifEncoder( 60, 'fast', true, $gd_processor ) )->encode( $src );
enc_check( EncodeResult::SKIPPED === $r->status && str_contains( $r->error, 'GD cannot preserve the color profile' ), 'photo-p3.jpg through GD: SKIPPED', $r->status . ' ' . $r->error );
enc_check( array() === enc_leftovers( dirname( $src ) ), 'GD skipped: nothing left on disk' );

$src   = enc_copy( 'photo-p3.jpg', 'gd-plain' );
$plain = new Imagick( $src );
$plain->removeImageProfile( 'icc' );
$plain->writeImage( $src );
$plain->clear();
$r = ( new AvifEncoder( 60, 'fast', true, $gd_processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status && file_exists( "{$src}.avif" ), 'profile-less JPEG through GD: DONE', $r->status . ' ' . $r->error );
if ( EncodeResult::DONE === $r->status ) {
	enc_check( 'image/avif' === wp_get_image_mime( "{$src}.avif" ), 'GD output is a real AVIF', (string) wp_get_image_mime( "{$src}.avif" ) );
}

$src  = enc_copy( 'photo-p3.jpg', 'gd-srgb' );
$srgb = new Imagick( $src );
$srgb->profileImage( 'icc', (string) file_get_contents( LUMIA_PLUGIN_DIR . 'assets/icc/srgb.icc' ) );
$srgb->writeImage( $src );
$srgb->clear();
$r = ( new AvifEncoder( 60, 'fast', true, $gd_processor ) )->encode( $src );
enc_check( EncodeResult::DONE === $r->status, 'sRGB-profiled JPEG through GD: DONE', $r->status . ' ' . $r->error );

echo "== JpegMetadata::strip_app1 ==\n";
$exif_seg = '';
$xmp_seg  = '';
$src      = enc_copy( 'photo-gps.jpg', 'strip' );
$before   = (string) file_get_contents( $src );
// The exact APP1 segments of the fixture, found by marker (independent of the code under test).
preg_match_all( '/\xFF\xE1(..)(Exif\0\0|http:\/\/ns\.adobe\.com\/xap\/1\.0\/\0)/s', $before, $m, PREG_OFFSET_CAPTURE );
foreach ( $m[0] as $i => $hit ) {
	$len = unpack( 'n', $m[1][ $i ][0] )[1];
	$seg = substr( $before, $hit[1], 2 + $len );
	if ( str_starts_with( substr( $seg, 4 ), 'Exif' ) ) {
		$exif_seg = $seg;
	} else {
		$xmp_seg = $seg;
	}
}
enc_check( '' !== $exif_seg && '' !== $xmp_seg, 'fixture carries one EXIF and one XMP APP1', strlen( $exif_seg ) . ' / ' . strlen( $xmp_seg ) );
$exif_before = @exif_read_data( $src );
enc_check( isset( $exif_before['GPSLatitude'] ), 'fixture: GPS readable before' );
$pix_before = new Imagick( $src );
$icc_before = $pix_before->getImageProfiles( 'icc', true )['icc'] ?? '';

$removed = JpegMetadata::strip_app1( $src );
$after   = (string) file_get_contents( $src );
enc_check( strlen( $exif_seg ) + strlen( $xmp_seg ) === $removed, 'returns the removed byte count', (string) $removed );
enc_check( strlen( $before ) - $removed === strlen( $after ), 'file shrank by exactly that amount' );
enc_check( str_replace( array( $exif_seg, $xmp_seg ), '', $before ) === $after, 'every other byte is untouched (byte-exact)' );
$exif_after = @exif_read_data( $src );
enc_check( ! isset( $exif_after['GPSLatitude'] ) && ! isset( $exif_after['Orientation'] ), 'no GPS and no EXIF left (exif_read_data)' );
$pix_after = new Imagick( $src );
enc_check( ( $pix_after->getImageProfiles( 'icc', true )['icc'] ?? '' ) === $icc_before && '' !== $icc_before, 'ICC profile intact' );
$diff = $pix_before->compareImages( $pix_after, Imagick::METRIC_ABSOLUTEERRORMETRIC );
enc_check( 0.0 === (float) $diff[1], 'pixels identical (absolute error metric = 0)', (string) $diff[1] );
$pix_before->clear();
$pix_after->clear();
enc_check( array() === array_filter( (array) scandir( dirname( $src ) ), static fn( $n ) => str_contains( $n, '.tmp-' ) ), 'no temporary left behind' );
enc_check( 0 === JpegMetadata::strip_app1( $src ) && (string) file_get_contents( $src ) === $after, 'second call: nothing to remove, file unchanged' );

$src    = enc_copy( 'photo-exif6.jpg', 'strip6' );
$before = (string) file_get_contents( $src );
enc_check( 0 === JpegMetadata::strip_app1( $src ) && (string) file_get_contents( $src ) === $before, 'orientation 6: nothing removed, file unchanged' );

enc_check( 0 === JpegMetadata::strip_app1( "{$work}/does-not-exist.jpg" ), 'missing file: 0' );
$src = enc_copy( 'visual-alpha.png', 'strip-png' );
$png_before = (string) file_get_contents( $src );
enc_check( 0 === JpegMetadata::strip_app1( $src ) && (string) file_get_contents( $src ) === $png_before, 'not a JPEG: 0, file unchanged' );

enc_rrmdir( $work );

printf( "\n%d checks, %d failure(s)\n", $GLOBALS['enc_checks'], $GLOBALS['enc_failures'] );
if ( $GLOBALS['enc_failures'] > 0 ) {
	WP_CLI::halt( 1 );
}
