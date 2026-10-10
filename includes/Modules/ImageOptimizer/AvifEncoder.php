<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the `<file>.<ext>.avif` sibling of a JPEG/PNG produced by WordPress.
 *
 * The JPEG/PNG stays the served file; the sibling is only handed out to clients that announce
 * `image/avif` (content negotiation, see docs/modules/image-optimizer.md). This class neither
 * resizes nor recompresses the source, and never touches it.
 *
 * Imagick is the engine (4:4:4, speed, ICC handling). GD is a fallback for images that have no
 * colour profile or an sRGB one: it cannot keep a wide-gamut profile, so it refuses those.
 *
 * `encode()` never throws: every outcome is an EncodeResult.
 */
final class AvifEncoder {

	/** An AVIF above this share of its source is not worth serving. */
	private const SIZE_GUARD = 0.9;

	/** Embedded profiles above this many bytes are converted to sRGB and dropped. */
	private const ICC_MAX_BYTES = 4096;

	/** Setting value => `heic:speed`. */
	private const SPEEDS = array(
		'balanced' => 8,
		'fast'     => 9,
	);

	/** MIME types worth an AVIF sibling. */
	private const MIMES = array( 'image/jpeg', 'image/png' );

	private int $quality;

	private string $speed;

	private bool $strip_exif;

	private ImageProcessor $processor;

	/**
	 * @param int            $quality    AVIF quality, 1 to 100.
	 * @param string         $speed      `balanced` or `fast`.
	 * @param bool           $strip_exif Drop EXIF, XMP and IPTC from the AVIF.
	 * @param ImageProcessor $processor  Source of the capabilities (engine, supported options).
	 */
	public function __construct( int $quality, string $speed, bool $strip_exif, ImageProcessor $processor ) {
		$this->quality    = max( 1, min( 100, $quality ) );
		$this->speed      = isset( self::SPEEDS[ $speed ] ) ? $speed : 'balanced';
		$this->strip_exif = $strip_exif;
		$this->processor  = $processor;
	}

	/**
	 * Encodes `<source_path>.avif`.
	 *
	 * The AVIF is written to a hidden temporary file in the same folder, then renamed: the final
	 * name never designates a half-written file. When the result is SKIPPED, an existing sibling
	 * is removed (it no longer matches what the source should produce).
	 *
	 * @param string        $source_path Path of the JPEG/PNG.
	 * @param callable|null $can_commit  Called right before the final rename; returning false
	 *                                   abandons the file (result SKIPPED, error `stale`). The
	 *                                   queue uses it to drop an encode whose source changed.
	 */
	public function encode( string $source_path, ?callable $can_commit = null ): EncodeResult {
		$source_bytes = is_file( $source_path ) ? (int) filesize( $source_path ) : 0;
		$sibling      = $source_path . '.avif';
		$tmp          = '';

		try {
			if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
				return new EncodeResult( EncodeResult::FAILED, 0, null, 'source file not found or unreadable' );
			}

			$mime = wp_get_image_mime( $source_path );
			if ( false === $mime ) {
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, 'not a readable image' );
			}
			if ( ! in_array( $mime, self::MIMES, true ) ) {
				return $this->skip( $sibling, $source_bytes, 'unsupported image type ' . $mime );
			}
			$truncated = $this->truncation( $source_path, $mime );
			if ( '' !== $truncated ) {
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, $truncated );
			}

			$engine = (string) ( $this->processor->get_capabilities()['avif_engine'] ?? '' );
			$note   = '';
			if ( 'imagick' === $engine ) {
				$blob = $this->encode_with_imagick( $source_path, $note );
			} elseif ( 'gd' === $engine ) {
				$blob = $this->encode_with_gd( $source_path, $mime );
			} else {
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, 'no AVIF encoder available on this server' );
			}

			$avif_bytes = strlen( $blob );
			if ( 0 === $avif_bytes ) {
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, 'the encoder returned an empty file' );
			}
			if ( $source_bytes > 0 && $avif_bytes > self::SIZE_GUARD * $source_bytes ) {
				return $this->skip( $sibling, $source_bytes, 'the AVIF is above 90% of the source size' );
			}

			$tmp = dirname( $source_path ) . '/.' . basename( $source_path ) . '.avif.tmp-' . bin2hex( random_bytes( 4 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a hidden temporary next to the source, renamed right after.
			if ( file_put_contents( $tmp, $blob ) !== $avif_bytes ) {
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, 'could not write the temporary AVIF file (disk full or folder not writable)' );
			}
			$perms = fileperms( $source_path );
			if ( false !== $perms ) {
				chmod( $tmp, $perms & 0666 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the source.
			}

			if ( null !== $can_commit && ! $can_commit() ) {
				return $this->skip( $sibling, $source_bytes, 'stale' );
			}

			if ( ! rename( $tmp, $sibling ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic: the final name never designates a partial file.
				return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, 'could not move the AVIF file into place' );
			}
			$tmp = '';

			return new EncodeResult( EncodeResult::DONE, $source_bytes, $avif_bytes, $note );
		} catch ( \DomainException $e ) {
			// A deliberate refusal (the image cannot be served as AVIF faithfully), not an error.
			return $this->skip( $sibling, $source_bytes, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();

			return new EncodeResult( EncodeResult::FAILED, $source_bytes, null, '' !== $message ? $message : get_class( $e ) );
		} finally {
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * SKIPPED result; also removes a sibling left by an earlier run.
	 */
	private function skip( string $sibling, int $source_bytes, string $reason ): EncodeResult {
		if ( file_exists( $sibling ) ) {
			wp_delete_file( $sibling );
		}

		return new EncodeResult( EncodeResult::SKIPPED, $source_bytes, null, $reason );
	}

	/**
	 * Reason why a JPEG/PNG is cut short, '' when it is complete.
	 *
	 * Imagick decodes a truncated JPEG without an exception (the missing part comes out grey):
	 * an AVIF would then immortalise a damaged picture.
	 */
	private function truncation( string $path, string $mime ): string {
		if ( 'image/jpeg' === $mime ) {
			return JpegMetadata::is_complete( $path ) ? '' : 'truncated or corrupt JPEG file';
		}

		// A PNG ends with an empty IEND chunk (length 0, "IEND", CRC).
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the last bytes of a local file.
		if ( false === $handle ) {
			return 'unreadable PNG file';
		}
		fseek( $handle, -12, SEEK_END );
		$tail = (string) fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.

		return "\0\0\0\0IEND" === substr( $tail, 0, 8 ) ? '' : 'truncated or corrupt PNG file';
	}

	/* ================================================================
	 * IMAGICK
	 * ================================================================ */

	/**
	 * Encodes with Imagick and returns the AVIF bytes.
	 *
	 * @param string $note Receives a non-fatal remark (an option the encoder refused).
	 * @throws \DomainException When the image cannot be converted faithfully (skip).
	 */
	private function encode_with_imagick( string $path, string &$note ): string {
		$image  = new \Imagick();
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read through a handle: a path is parsed by ImageMagick as a file specification.
		if ( false === $handle ) {
			throw new \RuntimeException( 'source file unreadable' );
		}

		try {
			$image->readImageFile( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			$handle = false;

			if ( $image->getNumberImages() > 1 ) {
				throw new \DomainException( 'animated image' );
			}

			$oriented = $this->apply_orientation( $image );
			$this->normalize_color( $image );
			$this->strip_metadata( $image, $oriented );

			$image->setImageDepth( 8 );
			$image->setImageFormat( 'avif' );
			$image->setCompressionQuality( $this->quality );
			$image->setImageCompressionQuality( $this->quality );

			return $this->render_with_options( $image, $note );
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			}
			$image->clear();
			$image->destroy();
		}
	}

	/**
	 * Applies the EXIF orientation to the pixels, so that the AVIF shows the same way up as the
	 * JPEG (browsers rotate a JPEG from its EXIF block, but an AVIF has no EXIF to read).
	 *
	 * @return bool Whether the image was turned or mirrored.
	 */
	private function apply_orientation( \Imagick $image ): bool {
		$orientation = $image->getImageOrientation();
		if ( \Imagick::ORIENTATION_UNDEFINED === $orientation || \Imagick::ORIENTATION_TOPLEFT === $orientation ) {
			return false;
		}

		$none = new \ImagickPixel( 'none' );
		switch ( $orientation ) {
			case \Imagick::ORIENTATION_TOPRIGHT:
				$image->flopImage();
				break;
			case \Imagick::ORIENTATION_BOTTOMRIGHT:
				$image->rotateImage( $none, 180 );
				break;
			case \Imagick::ORIENTATION_BOTTOMLEFT:
				$image->flipImage();
				break;
			case \Imagick::ORIENTATION_LEFTTOP:
				$image->flopImage();
				$image->rotateImage( $none, 270 );
				break;
			case \Imagick::ORIENTATION_RIGHTTOP:
				$image->rotateImage( $none, 90 );
				break;
			case \Imagick::ORIENTATION_RIGHTBOTTOM:
				$image->flopImage();
				$image->rotateImage( $none, 90 );
				break;
			case \Imagick::ORIENTATION_LEFTBOTTOM:
				$image->rotateImage( $none, 270 );
				break;
			default:
				return false;
		}
		$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );

		return true;
	}

	/**
	 * Colour handling: a CMYK image, or one with a heavy ICC profile (above 4096 bytes: wide-gamut
	 * profiles of cameras and phones, 60 KB and more, would outweigh the AVIF of a small image),
	 * is converted to sRGB and loses its profile. A small profile (Display P3 is 500 bytes) is
	 * kept as it is: the AVIF carries it and the browser renders the same colours.
	 *
	 * `stripImage()` is never used: it also removes the profile that the colours depend on.
	 *
	 * @throws \DomainException When the conversion is impossible.
	 */
	private function normalize_color( \Imagick $image ): void {
		$profiles = $image->getImageProfiles( 'icc', true );
		$icc      = isset( $profiles['icc'] ) ? (string) $profiles['icc'] : '';
		$cmyk     = \Imagick::COLORSPACE_CMYK === $image->getImageColorspace();

		if ( ! $cmyk && strlen( $icc ) <= self::ICC_MAX_BYTES ) {
			return;
		}

		if ( ! $this->imagick_has_lcms() ) {
			throw new \DomainException( 'Imagick has no lcms: the color profile cannot be converted to sRGB' );
		}

		$srgb = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/icc/srgb.icc' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file shipped with the plugin.
		if ( '' === $srgb ) {
			throw new \RuntimeException( 'sRGB profile missing from the plugin (assets/icc/srgb.icc)' );
		}

		try {
			if ( '' !== $icc ) {
				// With a profile already attached, profileImage() converts from it to the new one
				// (assigning is not converting: `transformImageColorspace` alone does not do P3 -> sRGB).
				$image->profileImage( 'icc', $srgb );
				$image->removeImageProfile( 'icc' );
			} else {
				// CMYK without a profile: ImageMagick's generic conversion.
				$image->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: encode() turns it into a result.
			throw new \DomainException( 'could not convert the color profile to sRGB: ' . $e->getMessage(), 0, $e );
		}

		if ( \Imagick::COLORSPACE_CMYK === $image->getImageColorspace() ) {
			throw new \DomainException( 'could not convert the CMYK image to sRGB' );
		}
	}

	/**
	 * lcms is what makes profileImage() a conversion; without it the profile is only swapped and
	 * the colours would be wrong. Assumed present when the build cannot be asked.
	 */
	private function imagick_has_lcms(): bool {
		try {
			// The WordPress stubs declare getConfigureOptions() without its pattern parameter.
			$options = \Imagick::getConfigureOptions( 'DELEGATES' ); // @phpstan-ignore arguments.count
		} catch ( \Throwable $e ) {
			return true;
		}

		return isset( $options['DELEGATES'] ) ? str_contains( (string) $options['DELEGATES'], 'lcms' ) : true;
	}

	/**
	 * Removes EXIF, XMP and IPTC one by one when `strip_exif` is on (and the EXIF block whenever
	 * the pixels were turned: its Orientation tag no longer holds).
	 */
	private function strip_metadata( \Imagick $image, bool $oriented ): void {
		if ( ! $this->strip_exif && ! $oriented ) {
			return;
		}

		$names = $this->strip_exif ? array( 'exif', 'xmp', 'iptc' ) : array( 'exif' );
		foreach ( $names as $name ) {
			try {
				$image->removeImageProfile( $name );
			} catch ( \Throwable $e ) {
				continue; // Imagick throws when the profile is absent.
			}
		}
	}

	/**
	 * Encodes with `heic:speed` and `heic:chroma=444`, dropping an option the encoder refuses
	 * instead of failing: chroma first (cost: 4:2:0), then speed.
	 *
	 * @param string $note Receives the options that had to be dropped.
	 */
	private function render_with_options( \Imagick $image, string &$note ): string {
		$caps    = $this->processor->get_capabilities();
		$options = array();
		if ( ! empty( $caps['heic_speed'] ) ) {
			$options['heic:speed'] = (string) self::SPEEDS[ $this->speed ];
		}
		if ( ! empty( $caps['heic_chroma'] ) ) {
			$options['heic:chroma'] = '444';
		}

		$dropped  = array();
		$failure  = new \RuntimeException( 'AVIF encoding failed' );
		$attempts = count( $options );
		for ( $attempt = 0; $attempt <= $attempts; $attempt++ ) {
			try {
				foreach ( $options as $name => $value ) {
					$image->setOption( $name, $value );
				}
				$blob = $image->getImageBlob();
				if ( '' !== $blob ) {
					$note = array() === $dropped ? '' : 'option ignored: ' . implode( ', ', $dropped );

					return $blob;
				}
				$failure = new \RuntimeException( 'the encoder returned an empty file' );
			} catch ( \Throwable $e ) {
				$failure = $e;
			}

			// Retry without the last option still set (chroma, then speed).
			$names = array_keys( $options );
			$last  = end( $names );
			if ( false === $last ) {
				break;
			}
			unset( $options[ $last ] );
			$dropped[] = $last;
			if ( method_exists( $image, 'deleteOption' ) ) {
				try {
					$image->deleteOption( $last );
				} catch ( \Throwable $e ) {
					continue; // The option was never set (the refusal came from setOption itself).
				}
			}
		}

		throw $failure; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed: encode() turns it into a result.
	}

	/* ================================================================
	 * GD
	 * ================================================================ */

	/**
	 * Encodes with GD and returns the AVIF bytes. GD reads no colour profile and ignores the
	 * EXIF orientation: whatever depends on them is refused instead of served with wrong colours.
	 *
	 * @throws \DomainException When GD cannot do it faithfully (skip).
	 */
	private function encode_with_gd( string $path, string $mime ): string {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $data ) {
			throw new \RuntimeException( 'source file unreadable' );
		}

		if ( 'image/jpeg' === $mime ) {
			$info = getimagesizefromstring( $data );
			if ( is_array( $info ) && 4 === (int) ( $info['channels'] ?? 3 ) ) {
				throw new \DomainException( 'GD cannot convert a CMYK JPEG' );
			}
			if ( JpegMetadata::orientation( $data ) > 1 ) {
				throw new \DomainException( 'GD cannot apply the EXIF orientation' );
			}
			$icc = JpegMetadata::icc_profile( $data );
		} else {
			$icc = $this->png_icc_profile( $data );
		}
		if ( '' !== $icc && ! $this->is_srgb_profile( $icc ) ) {
			throw new \DomainException( 'GD cannot preserve the color profile' );
		}

		// GD warns on a corrupt image: the failure is handled right after.
		$gd = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $gd ) {
			throw new \RuntimeException( 'GD could not decode the image' );
		}
		unset( $data );

		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $gd );
		}
		imagealphablending( $gd, false );
		imagesavealpha( $gd, true );

		// GD's quality scale runs a little higher than libheif's for the same look.
		ob_start();
		$ok   = imageavif( $gd, null, max( 0, $this->quality - 5 ), self::SPEEDS[ $this->speed ] );
		$blob = ob_get_clean();

		if ( ! $ok || ! is_string( $blob ) || '' === $blob ) {
			throw new \RuntimeException( 'GD failed to encode the AVIF' );
		}

		return $blob;
	}

	/**
	 * ICC profile of a PNG (iCCP chunk), '' when there is none. A bare sRGB chunk is not a profile
	 * to keep: it means sRGB.
	 */
	private function png_icc_profile( string $data ): string {
		$length = strlen( $data );
		$pos    = 8;
		while ( $pos + 12 <= $length ) {
			$unpacked = unpack( 'Nsize', substr( $data, $pos, 4 ) );
			$size     = false === $unpacked ? 0 : (int) $unpacked['size'];
			$type     = substr( $data, $pos + 4, 4 );
			if ( 'IDAT' === $type || 'IEND' === $type ) {
				break;
			}
			if ( 'iCCP' === $type ) {
				$body = substr( $data, $pos + 8, $size );
				$nul  = strpos( $body, "\0" );
				if ( false === $nul ) {
					return '';
				}
				$inflated = @gzuncompress( substr( $body, $nul + 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is treated as "no profile" below.

				return false === $inflated ? '' : $inflated;
			}
			$pos += 12 + $size;
		}

		return '';
	}

	/**
	 * Whether an ICC profile is an sRGB one: an RGB profile whose colorants are the sRGB primaries
	 * (D50-adapted, as every sRGB profile stores them), or whose description says "sRGB".
	 *
	 * The colorants are the reliable test: the compact sRGB profiles are described as "uRGB" or
	 * "nRGB", and a hardware vendor may call its own profile anything.
	 */
	private function is_srgb_profile( string $icc ): bool {
		if ( strlen( $icc ) < 132 || 'RGB ' !== substr( $icc, 16, 4 ) ) {
			return false;
		}

		$primaries = array(
			'rXYZ' => array( 0.4360, 0.2225, 0.0139 ),
			'gXYZ' => array( 0.3851, 0.7169, 0.0971 ),
			'bXYZ' => array( 0.1431, 0.0606, 0.7141 ),
		);
		$matches   = true;
		foreach ( $primaries as $signature => $expected ) {
			$tag = $this->icc_tag( $icc, $signature );
			if ( 20 !== strlen( $tag ) || 'XYZ ' !== substr( $tag, 0, 4 ) ) {
				$matches = false;
				break;
			}
			$values = unpack( 'N3', substr( $tag, 8 ) );
			foreach ( array_values( (array) $values ) as $i => $raw ) {
				// s15Fixed16: a signed 32-bit integer over 65536.
				$value = ( $raw >= 0x80000000 ? $raw - 0x100000000 : $raw ) / 65536;
				if ( abs( $value - $expected[ $i ] ) > 0.01 ) {
					$matches = false;
					break 2;
				}
			}
		}
		if ( $matches ) {
			return true;
		}

		// v2 'desc' holds ASCII, v4 'mluc' UTF-16BE: dropping the NULs of the latter leaves text
		// that a plain search reads the same way.
		return false !== stripos( str_replace( "\0", '', $this->icc_tag( $icc, 'desc' ) ), 'srgb' );
	}

	/**
	 * Raw bytes of a tag of an ICC profile, '' when absent.
	 */
	private function icc_tag( string $icc, string $signature ): string {
		$count = unpack( 'Ncount', substr( $icc, 128, 4 ) );
		$count = false === $count ? 0 : min( 200, (int) $count['count'] );
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = substr( $icc, 132 + 12 * $i, 12 );
			$name  = substr( $entry, 0, 4 );
			if ( 12 !== strlen( $entry ) || $name !== $signature ) {
				continue;
			}

			$where = unpack( 'Noffset/Nsize', substr( $entry, 4 ) );

			return false === $where ? '' : substr( $icc, (int) $where['offset'], (int) $where['size'] );
		}

		return '';
	}
}
