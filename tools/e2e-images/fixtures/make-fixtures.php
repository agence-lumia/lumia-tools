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
 *
 * Encoder fixtures (AvifEncoder / JpegMetadata, not imported as media):
 *
 *   photo-16bit.png   800x600, 16 bits per channel, no alpha
 *   photo-exif6.jpg   800x600 stored, EXIF orientation 6 (+ GPS), small Display P3 profile
 *   photo-gps.jpg     800x600, orientation 1, EXIF with GPS + XMP APP1 segments, small P3 profile
 *   photo-cmyk.jpg    600x400, CMYK JPEG without an embedded profile
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

// --- Encoder fixtures ------------------------------------------------------

/**
 * A big-endian EXIF APP1 segment (marker included) with an Orientation tag and a GPS IFD
 * (48 51 24 N, 2 21 7 E: a Paris-like position, drawn here, not a client's).
 */
function fx_exif_segment( int $orientation ): string {
	$entry = static function ( int $tag, int $type, int $count, string $value ): string {
		return pack( 'nnN', $tag, $type, $count ) . $value;
	};

	$ifd0_len = 2 + 2 * 12 + 4;
	$gps_off  = 8 + $ifd0_len;
	$gps_len  = 2 + 4 * 12 + 4;
	$lat_off  = $gps_off + $gps_len;
	$lon_off  = $lat_off + 24;

	$tiff  = 'MM' . pack( 'nN', 0x002A, 8 );
	$tiff .= pack( 'n', 2 );
	$tiff .= $entry( 0x0112, 3, 1, pack( 'nn', $orientation, 0 ) );
	$tiff .= $entry( 0x8825, 4, 1, pack( 'N', $gps_off ) );
	$tiff .= pack( 'N', 0 );
	$tiff .= pack( 'n', 4 );
	$tiff .= $entry( 0x0001, 2, 2, "N\0\0\0" );
	$tiff .= $entry( 0x0002, 5, 3, pack( 'N', $lat_off ) );
	$tiff .= $entry( 0x0003, 2, 2, "E\0\0\0" );
	$tiff .= $entry( 0x0004, 5, 3, pack( 'N', $lon_off ) );
	$tiff .= pack( 'N', 0 );
	$tiff .= pack( 'N6', 48, 1, 51, 1, 24, 1 );
	$tiff .= pack( 'N6', 2, 1, 21, 1, 7, 1 );

	$payload = "Exif\0\0" . $tiff;

	return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
}

/**
 * An XMP APP1 segment (marker included).
 */
function fx_xmp_segment(): string {
	$payload = "http://ns.adobe.com/xap/1.0/\0"
		. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
		. '<rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Lumia bench</dc:creator></rdf:Description>'
		. '</rdf:RDF></x:xmpmeta>';

	return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
}

/**
 * Inserts segments right after SOI and the JFIF APP0 (if any).
 */
function fx_insert_segments( string $jpeg, string $segments ): string {
	$at = 2;
	if ( "\xFF\xE0" === substr( $jpeg, 2, 2 ) ) {
		$at = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1] - 2 + 2;
	}

	return substr( $jpeg, 0, $at ) . $segments . substr( $jpeg, $at );
}

/**
 * @return int Bytes written.
 */
function fx_write_blob( string $blob, string $path ): int {
	file_put_contents( $path, $blob );
	printf( "  %-18s %9d bytes\n", basename( $path ), strlen( $blob ) );

	return strlen( $blob );
}

// photo-16bit.png: 16 bits per channel.
$deep = new Imagick();
$deep->newPseudoImage( 800, 600, 'gradient:#102a43-#f0b429' );
$deep_plasma = new Imagick();
$deep_plasma->newPseudoImage( 800, 600, 'plasma:fractal' );
$deep->compositeImage( $deep_plasma, Imagick::COMPOSITE_OVERLAY, 0, 0 );
$deep_plasma->clear();
$deep->setImageFormat( 'png48' );
$deep->setImageDepth( 16 );
$deep->setOption( 'png:bit-depth', '16' );
fx_write( $deep, "{$out_dir}/photo-16bit.png" );
$deep->clear();

// photo-exif6.jpg: orientation 6 (rotate 90 clockwise), GPS, P3 profile.
$exif6 = fx_photo( 800, 600 );
$exif6->profileImage( 'icc', fx_icc_p3() );
fx_write_blob( fx_insert_segments( $exif6->getImageBlob(), fx_exif_segment( 6 ) ), "{$out_dir}/photo-exif6.jpg" );
$exif6->clear();

// photo-gps.jpg: orientation 1, EXIF + GPS + XMP, P3 profile.
$gps = fx_photo( 800, 600 );
$gps->profileImage( 'icc', fx_icc_p3() );
fx_write_blob( fx_insert_segments( $gps->getImageBlob(), fx_exif_segment( 1 ) . fx_xmp_segment() ), "{$out_dir}/photo-gps.jpg" );
$gps->clear();

// photo-cmyk.jpg: CMYK, no embedded profile.
$cmyk = fx_photo( 600, 400 );
$cmyk->transformImageColorspace( Imagick::COLORSPACE_CMYK );
$cmyk->setImageFormat( 'jpeg' );
$cmyk->setImageCompressionQuality( 90 );
fx_write( $cmyk, "{$out_dir}/photo-cmyk.jpg" );
$cmyk->clear();
