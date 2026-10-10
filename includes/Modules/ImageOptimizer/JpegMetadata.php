<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Lossless JPEG metadata removal.
 *
 * WordPress keeps the EXIF block (GPS included) in its sub-sizes and in a main file that was not
 * scaled. Re-encoding to drop it would cost quality, so the APP1 segments (EXIF and XMP) are cut
 * out of the file instead, by walking the JPEG markers: every other byte stays as it is, the ICC
 * profile (APP2) first.
 */
final class JpegMetadata {

	/** Prefix of the APP1 payload of an EXIF block. */
	private const EXIF_ID = "Exif\0\0";

	/** Prefixes of the APP1 payload of an XMP block (standard and extended). */
	private const XMP_IDS = array(
		"http://ns.adobe.com/xap/1.0/\0",
		"http://ns.adobe.com/xmp/extension/\0",
	);

	/**
	 * Removes the EXIF and XMP APP1 segments of a JPEG file, in place.
	 *
	 * Nothing is removed (0 returned, file untouched) when the file is not a JPEG, cannot be
	 * parsed, has no such segment, or carries an EXIF orientation other than 1: the browser reads
	 * the orientation from the EXIF block, so cutting it would turn the image. The new content is
	 * written to a temporary file in the same folder, then renamed over the original.
	 *
	 * @param string $path JPEG file.
	 * @return int Bytes removed.
	 */
	public static function strip_app1( string $path ): int {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return 0;
		}

		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $data ) {
			return 0;
		}

		$cuts = self::app1_segments( $data );
		if ( null === $cuts || array() === $cuts['segments'] || $cuts['orientation'] > 1 ) {
			return 0;
		}

		$kept    = '';
		$removed = 0;
		$cursor  = 0;
		foreach ( $cuts['segments'] as $segment ) {
			$kept    .= substr( $data, $cursor, $segment[0] - $cursor );
			$cursor   = $segment[0] + $segment[1];
			$removed += $segment[1];
		}
		$kept .= substr( $data, $cursor );

		if ( strlen( $kept ) !== strlen( $data ) - $removed || "\xFF\xD8" !== substr( $kept, 0, 2 ) ) {
			return 0;
		}

		$tmp = self::temporary_path( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary next to the file, renamed right after.
		if ( strlen( $kept ) !== file_put_contents( $tmp, $kept ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$perms = fileperms( $path );
		if ( false !== $perms ) {
			chmod( $tmp, $perms & 0777 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions as the source.
		}

		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replacement in the same folder.
			wp_delete_file( $tmp );
			return 0;
		}

		return $removed;
	}

	/**
	 * Tells whether a JPEG file is complete: its entropy-coded data ends with an EOI marker.
	 *
	 * Imagick reads a truncated JPEG without complaining (the missing part comes out grey), so
	 * the encoder checks the file itself. Segments before the scan are walked, then the scan data
	 * is searched for the first marker that is neither a stuffed byte nor a restart marker: a
	 * progressive file has several scans, so markers other than EOI are skipped over.
	 */
	public static function is_complete( string $path ): bool {
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === $data || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return false;
		}

		$length = strlen( $data );
		$pos    = 2;
		while ( $pos + 1 < $length ) {
			if ( "\xFF" !== $data[ $pos ] ) {
				return false;
			}
			$marker = ord( $data[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos; // Fill byte.
				continue;
			}
			if ( 0xD9 === $marker ) {
				return true;
			}
			if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) {
				$pos += 2; // Standalone marker.
				continue;
			}
			if ( $pos + 3 >= $length ) {
				return false;
			}
			$size = ( ord( $data[ $pos + 2 ] ) << 8 ) | ord( $data[ $pos + 3 ] );
			if ( $size < 2 ) {
				return false;
			}
			$pos += 2 + $size;
			if ( 0xDA !== $marker ) {
				continue;
			}
			// Entropy-coded data: up to the next real marker (not 00 stuffing, not RSTn, not fill).
			if ( 1 !== preg_match( '/\xFF[^\x00\xD0-\xD7\xFF]/', $data, $hit, PREG_OFFSET_CAPTURE, $pos ) ) {
				return false;
			}
			$pos = (int) $hit[0][1];
		}

		return false;
	}

	/**
	 * Orientation tag (1 to 8) of the EXIF block of a JPEG, 1 when there is none.
	 *
	 * @param string $data JPEG content.
	 */
	public static function orientation( string $data ): int {
		$parsed = self::app1_segments( $data );

		return null === $parsed ? 1 : max( 1, $parsed['orientation'] );
	}

	/**
	 * Walks the segments located before the first scan.
	 *
	 * @param string $data JPEG content.
	 * @return array<int, array{marker:int,offset:int,size:int}>|null Marker, offset and total size
	 *         (marker and length field included) of each segment; null when the data is not a
	 *         parsable JPEG.
	 */
	private static function segments( string $data ): ?array {
		$length = strlen( $data );
		if ( $length < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return null;
		}

		$segments = array();
		$pos      = 2;
		while ( $pos + 3 < $length ) {
			if ( "\xFF" !== $data[ $pos ] ) {
				return null;
			}
			$marker = ord( $data[ $pos + 1 ] );
			if ( 0xFF === $marker ) {
				++$pos; // Fill byte.
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break; // Scan data (or end of image): no metadata beyond this point.
			}
			if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) {
				$pos += 2;
				continue;
			}

			$size = ( ord( $data[ $pos + 2 ] ) << 8 ) | ord( $data[ $pos + 3 ] );
			if ( $size < 2 || $pos + 2 + $size > $length ) {
				return null; // Truncated or corrupt segment.
			}

			$segments[] = array(
				'marker' => $marker,
				'offset' => $pos,
				'size'   => 2 + $size,
			);
			$pos       += 2 + $size;
		}

		return $segments;
	}

	/**
	 * Lists the EXIF/XMP APP1 segments before the first scan.
	 *
	 * @param string $data JPEG content.
	 * @return array{segments: array<int, array{0:int,1:int}>, orientation: int}|null Offset and
	 *         length of each segment, plus the EXIF orientation (0 when absent); null when the
	 *         data is not a parsable JPEG.
	 */
	private static function app1_segments( string $data ): ?array {
		$all = self::segments( $data );
		if ( null === $all ) {
			return null;
		}

		$found       = array();
		$orientation = 0;
		foreach ( $all as $segment ) {
			if ( 0xE1 !== $segment['marker'] ) {
				continue;
			}

			$payload = substr( $data, $segment['offset'] + 4, $segment['size'] - 4 );
			if ( str_starts_with( $payload, self::EXIF_ID ) ) {
				$found[]     = array( $segment['offset'], $segment['size'] );
				$orientation = max( $orientation, self::exif_orientation( substr( $payload, strlen( self::EXIF_ID ) ) ) );
				continue;
			}

			foreach ( self::XMP_IDS as $id ) {
				if ( str_starts_with( $payload, $id ) ) {
					$found[] = array( $segment['offset'], $segment['size'] );
					break;
				}
			}
		}

		return array(
			'segments'    => $found,
			'orientation' => $orientation,
		);
	}

	/**
	 * Embedded ICC profile of a JPEG (APP2 "ICC_PROFILE" chunks, reassembled), '' when none.
	 *
	 * @param string $data JPEG content.
	 */
	public static function icc_profile( string $data ): string {
		$all = self::segments( $data );
		if ( null === $all ) {
			return '';
		}

		$id     = "ICC_PROFILE\0";
		$chunks = array();
		foreach ( $all as $segment ) {
			if ( 0xE2 !== $segment['marker'] ) {
				continue;
			}
			$payload = substr( $data, $segment['offset'] + 4, $segment['size'] - 4 );
			if ( str_starts_with( $payload, $id ) && strlen( $payload ) > strlen( $id ) + 2 ) {
				$chunks[ ord( $payload[ strlen( $id ) ] ) ] = substr( $payload, strlen( $id ) + 2 );
			}
		}

		ksort( $chunks );

		return implode( '', $chunks );
	}

	/**
	 * Reads the Orientation tag (0x0112) of the first IFD of a TIFF block.
	 *
	 * @param string $tiff TIFF structure of the EXIF block (after "Exif\0\0").
	 * @return int The orientation, 0 when absent or unreadable.
	 */
	private static function exif_orientation( string $tiff ): int {
		if ( strlen( $tiff ) < 8 ) {
			return 0;
		}

		$order = substr( $tiff, 0, 2 );
		if ( 'II' === $order ) {
			$short = 'v';
			$long  = 'V';
		} elseif ( 'MM' === $order ) {
			$short = 'n';
			$long  = 'N';
		} else {
			return 0;
		}

		$offset = unpack( $long . 'v', substr( $tiff, 4, 4 ) );
		if ( false === $offset || ! isset( $offset['v'] ) || $offset['v'] + 2 > strlen( $tiff ) ) {
			return 0;
		}

		$ifd   = (int) $offset['v'];
		$count = unpack( $short . 'n', substr( $tiff, $ifd, 2 ) );
		if ( false === $count ) {
			return 0;
		}

		for ( $i = 0; $i < (int) $count['n']; $i++ ) {
			$entry = $ifd + 2 + 12 * $i;
			if ( $entry + 12 > strlen( $tiff ) ) {
				return 0;
			}
			$tag = unpack( $short . 't', substr( $tiff, $entry, 2 ) );
			if ( false !== $tag && 0x0112 === (int) $tag['t'] ) {
				$value = unpack( $short . 'v', substr( $tiff, $entry + 8, 2 ) );

				return false === $value ? 0 : (int) $value['v'];
			}
		}

		return 0;
	}

	/**
	 * Temporary path next to the file; the leading dot hides it from directory listings.
	 */
	private static function temporary_path( string $path ): string {
		return dirname( $path ) . '/.' . basename( $path ) . '.tmp-' . bin2hex( random_bytes( 4 ) );
	}
}
