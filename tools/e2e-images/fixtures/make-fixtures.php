<?php
/**
 * Generates the synthetic image fixtures of the image delivery bench. Bench only, never shipped.
 *
 * Usage (run.sh does it inside the stack's PHP container): php make-fixtures.php <output-dir>
 *
 * Never a client image: everything is drawn here with Imagick.
 *
 *   photo-4000.jpg    4000x3000, gradient + plasma + noise (a camera original; WordPress scales it)
 *   photo-p3.jpg      1600x1200, small Display P3 profile embedded
 *   photo-bigicc.jpg  1600x1200, ICC profile larger than 4 KB (like Apple "Poppy", 60 KB)
 *   visual-alpha.png  1200x800, transparency (soft shapes)
 *   logo-flat.png     300x100, flat colours
 *   anim.gif          200x150, two frames
 *   corrupt.jpg       a JPEG whose data is cut short after the header
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! class_exists( 'Imagick' ) ) {
	fwrite( STDERR, "Imagick is required to generate the fixtures.\n" );
	exit( 1 );
}

$out_dir = $argv[1] ?? '';
if ( '' === $out_dir ) {
	fwrite( STDERR, "usage: php make-fixtures.php <output-dir>\n" );
	exit( 2 );
}
if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	fwrite( STDERR, "cannot create {$out_dir}\n" );
	exit( 1 );
}

/**
 * Big-endian s15Fixed16 number.
 */
function fx_s15( float $value ): string {
	return pack( 'N', (int) round( $value * 65536 ) & 0xFFFFFFFF );
}

/**
 * XYZType tag body.
 */
function fx_xyz( float $x, float $y, float $z ): string {
	return 'XYZ ' . pack( 'N', 0 ) . fx_s15( $x ) . fx_s15( $y ) . fx_s15( $z );
}

/**
 * A valid matrix/TRC RGB ICC v2 profile with Display P3 primaries (D50-adapted, as Apple ships
 * them) and a 2.2 gamma. $pad_bytes adds a private tag of that size, to grow the profile.
 */
function fx_icc_p3( int $pad_bytes = 0 ): string {
	$desc_text = 'Display P3 (bench)';
	$desc      = 'desc' . pack( 'N', 0 ) . pack( 'N', strlen( $desc_text ) + 1 ) . $desc_text . "\0"
		. pack( 'N', 0 ) . pack( 'N', 0 ) . pack( 'n', 0 ) . chr( 0 ) . str_repeat( "\0", 67 );
	$cprt      = 'text' . pack( 'N', 0 ) . 'No copyright, bench fixture' . "\0";
	$curve     = 'curv' . pack( 'N', 0 ) . pack( 'N', 1 ) . pack( 'n', 563 ); // gamma 2.2 = 0x0233 (u8Fixed8).

	$tags = array(
		'desc' => $desc,
		'cprt' => $cprt,
		'wtpt' => fx_xyz( 0.9642, 1.0, 0.8249 ),
		'rXYZ' => fx_xyz( 0.5151, 0.2412, -0.0011 ),
		'gXYZ' => fx_xyz( 0.2920, 0.6922, 0.0419 ),
		'bXYZ' => fx_xyz( 0.1571, 0.0666, 0.7841 ),
		'rTRC' => $curve,
		'gTRC' => $curve,
		'bTRC' => $curve,
	);
	if ( $pad_bytes > 0 ) {
		$tags['bnch'] = 'bnch' . pack( 'N', 0 ) . str_repeat( 'LUMIA-BENCH-PADDING-', (int) ceil( $pad_bytes / 20 ) );
	}

	$table  = pack( 'N', count( $tags ) );
	$data   = '';
	$offset = 128 + 4 + 12 * count( $tags );
	foreach ( $tags as $signature => $body ) {
		$size = strlen( $body );
		while ( 0 !== strlen( $body ) % 4 ) {
			$body .= "\0";
		}
		$table  .= $signature . pack( 'N', $offset ) . pack( 'N', $size );
		$data   .= $body;
		$offset += strlen( $body );
	}

	$header = pack( 'N', $offset )                  // Profile size.
		. "\0\0\0\0"                                // Preferred CMM.
		. pack( 'N', 0x02400000 )                   // Version 2.4.
		. 'mntr' . 'RGB ' . 'XYZ '
		. pack( 'n6', 2026, 10, 10, 0, 0, 0 )       // Creation date.
		. 'acsp' . 'APPL'
		. pack( 'N', 0 )                            // Flags.
		. "\0\0\0\0" . "\0\0\0\0"                   // Manufacturer, model.
		. str_repeat( "\0", 8 )                     // Attributes.
		. pack( 'N', 0 )                            // Rendering intent.
		. fx_s15( 0.9642 ) . fx_s15( 1.0 ) . fx_s15( 0.8249 ) // PCS illuminant (D50).
		. "\0\0\0\0"                                // Creator.
		. str_repeat( "\0", 16 )                    // Profile ID.
		. str_repeat( "\0", 28 );                   // Reserved.

	return $header . $table . $data;
}

/**
 * A photo-like image: gradient, plasma overlay, then sensor-like noise.
 */
function fx_photo( int $width, int $height ): Imagick {
	$image = new Imagick();
	$image->newPseudoImage( $width, $height, 'gradient:#16324f-#f4a259' );

	$plasma = new Imagick();
	$plasma->newPseudoImage( $width, $height, 'plasma:fractal' );
	$image->compositeImage( $plasma, Imagick::COMPOSITE_OVERLAY, 0, 0 );
	$plasma->clear();

	$image->addNoiseImage( Imagick::NOISE_GAUSSIAN );
	$image->setImageFormat( 'jpeg' );
	$image->setImageCompressionQuality( 90 );
	$image->setInterlaceScheme( Imagick::INTERLACE_NO );

	return $image;
}

/**
 * @return int Bytes written.
 */
function fx_write( Imagick $image, string $path ): int {
	$image->writeImage( $path );
	$bytes = (int) filesize( $path );
	printf( "  %-18s %9d bytes\n", basename( $path ), $bytes );

	return $bytes;
}

// --- photo-4000.jpg --------------------------------------------------------

$photo = fx_photo( 4000, 3000 );
fx_write( $photo, "{$out_dir}/photo-4000.jpg" );
$photo->clear();

// --- photo-p3.jpg: small Display P3 profile --------------------------------

$p3 = fx_photo( 1600, 1200 );
$p3->profileImage( 'icc', fx_icc_p3() );
fx_write( $p3, "{$out_dir}/photo-p3.jpg" );
$p3->clear();

// --- photo-bigicc.jpg: profile > 4 KB (60 KB) ------------------------------

$big = fx_photo( 1600, 1200 );
$big->profileImage( 'icc', fx_icc_p3( 60 * 1024 ) );
fx_write( $big, "{$out_dir}/photo-bigicc.jpg" );
$big->clear();

// --- visual-alpha.png: soft transparent shapes -----------------------------

$alpha = new Imagick();
$alpha->newImage( 1200, 800, new ImagickPixel( 'transparent' ) );
$alpha->setImageFormat( 'png' );
$shapes = array(
	array( '#e63946', 0.95, 420, 380, 300 ),
	array( '#2a9d8f', 0.60, 700, 420, 260 ),
	array( '#264653', 0.35, 560, 300, 200 ),
);
foreach ( $shapes as $shape ) {
	$draw = new ImagickDraw();
	$fill = new ImagickPixel( $shape[0] );
	$fill->setColorValue( Imagick::COLOR_ALPHA, $shape[1] );
	$draw->setFillColor( $fill );
	$draw->circle( $shape[2], $shape[3], $shape[2] + $shape[4], $shape[3] );
	$alpha->drawImage( $draw );
}
$alpha->blurImage( 6, 3 );
$alpha->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );
$alpha->setOption( 'png:color-type', '6' );
fx_write( $alpha, "{$out_dir}/visual-alpha.png" );
$alpha->clear();

// --- logo-flat.png: flat colours, 300 px -----------------------------------

$logo = new Imagick();
$logo->newImage( 300, 100, new ImagickPixel( '#ffffff' ) );
$logo->setImageFormat( 'png' );
$draw = new ImagickDraw();
$draw->setFillColor( new ImagickPixel( '#1d3557' ) );
$draw->roundRectangle( 10, 10, 290, 90, 14, 14 );
$draw->setFillColor( new ImagickPixel( '#e63946' ) );
$draw->circle( 60, 50, 60, 78 );
$draw->setFillColor( new ImagickPixel( '#f1faee' ) );
$draw->rectangle( 120, 38, 270, 48 );
$draw->rectangle( 120, 56, 220, 66 );
$logo->drawImage( $draw );
fx_write( $logo, "{$out_dir}/logo-flat.png" );
$logo->clear();

// --- anim.gif: two frames --------------------------------------------------

$gif = new Imagick();
foreach ( array( '#e63946', '#457b9d' ) as $colour ) {
	$frame = new Imagick();
	$frame->newImage( 200, 150, new ImagickPixel( $colour ) );
	$frame->setImageFormat( 'gif' );
	$frame->setImageDelay( 50 );
	$gif->addImage( $frame );
}
$gif->setImageFormat( 'gif' );
$gif->setImageIterations( 0 );
$gif->writeImages( "{$out_dir}/anim.gif", true );
printf( "  %-18s %9d bytes\n", 'anim.gif', filesize( "{$out_dir}/anim.gif" ) );
$gif->clear();

// --- corrupt.jpg: valid header, truncated data -----------------------------

$source = fx_photo( 400, 300 );
$blob   = $source->getImageBlob();
$source->clear();
file_put_contents( "{$out_dir}/corrupt.jpg", substr( $blob, 0, 700 ) );
printf( "  %-18s %9d bytes (truncated)\n", 'corrupt.jpg', filesize( "{$out_dir}/corrupt.jpg" ) );
