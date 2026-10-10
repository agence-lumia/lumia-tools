<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Server capability detection (Imagick/GD, AVIF encoding in this process) and file utilities.
 *
 * The AVIF encoding itself lives in AvifEncoder. No WordPress hooks, no settings.
 */
class ImageProcessor {

	/**
	 * Cache of the server capabilities (this request).
	 *
	 * @var array<string, bool|string>|null
	 */
	private ?array $capabilities = null;

	/* ================================================================
	 * SERVER CAPABILITIES
	 * ================================================================ */

	/** Lifetime of the capabilities cache (24 h). */
	private const CAPABILITIES_TTL = DAY_IN_SECONDS;

	/**
	 * Detects and caches the server's image capabilities.
	 *
	 * The probes are real encodings: redoing them on every request
	 * (upload, bulk batch, settings screen) cost four encodings
	 * per call. The result lives in a transient, under a key tied to the PHP, GD and
	 * ImageMagick versions and to the SAPI: a build change invalidates the cache by itself, and
	 * so does the SAPI (a shared object cache is read by PHP-FPM and by the CLI, whose Imagick
	 * can differ: the official `cli` image has an Imagick without any codec).
	 *
	 * Keys added by the AVIF rework:
	 * - `avif_engine`     `imagick`, `gd` or '' : the engine that encodes AVIF here.
	 * - `heic_speed`      the `heic:speed` option changes the output (it is honoured).
	 * - `heic_chroma`     `heic:chroma=444` gives a 4:4:4 AVIF.
	 * - `can_encode_here` Imagick encodes AVIF and decodes JPEG, PNG and AVIF in THIS process.
	 *
	 * @return array<string, bool|string>
	 */
	public function get_capabilities(): array {
		if ( null !== $this->capabilities ) {
			return $this->capabilities;
		}

		$cache_key = 'lumia_image_caps_v2_' . md5(
			PHP_VERSION . '|' . (string) phpversion( 'gd' ) . '|' . $this->imagick_version_string() . '|' . PHP_SAPI
		);
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['editor'], $cached['can_encode_here'] ) ) {
			$this->capabilities = $cached;
			return $cached;
		}

		$has_imagick = extension_loaded( 'imagick' );
		$has_gd      = extension_loaded( 'gd' );

		// Encoding capability per engine AND per format. Essential because a
		// format can be *registered* (queryFormats) without a real encoding
		// delegate: the conversion then fails at run time ("Unable to set image
		// format", "no decode delegate"). So we test each pair with a
		// real 1x1 encoding, and then route the conversion to the engine that
		// actually works (one may know how, the other not).
		$imagick_avif = false;
		$imagick_webp = false;
		$gd_avif      = false;
		$gd_webp      = false;
		$heic_speed   = false;
		$heic_chroma  = false;
		$can_here     = false;

		if ( $has_imagick ) {
			$formats      = \Imagick::queryFormats();
			$imagick_avif = in_array( 'AVIF', $formats, true ) && $this->imagick_can_encode( 'avif' );
			$imagick_webp = in_array( 'WEBP', $formats, true ) && $this->imagick_can_encode( 'webp' );

			if ( $imagick_avif ) {
				$heic_chroma = $this->heic_chroma_honoured();
				$heic_speed  = $this->heic_speed_honoured();
				$can_here    = $this->imagick_can_decode_here();
			}
		}

		if ( $has_gd ) {
			// We probe with a real encoding rather than trusting gd_info():
			// imagewebp()/imageavif() always exist in PHP 8.1+ even without the
			// underlying lib, and gd_info() can lie on some builds.
			$gd_avif = $this->gd_can_encode( 'avif' );
			$gd_webp = $this->gd_can_encode( 'webp' );
		}

		$this->capabilities = [
			'imagick'         => $has_imagick,
			'gd'              => $has_gd,
			'avif'            => $imagick_avif || $gd_avif,
			'webp'            => $imagick_webp || $gd_webp,
			'imagick_avif'    => $imagick_avif,
			'imagick_webp'    => $imagick_webp,
			'gd_avif'         => $gd_avif,
			'gd_webp'         => $gd_webp,
			'editor'          => $has_imagick ? 'imagick' : ( $has_gd ? 'gd' : 'none' ),
			'avif_engine'     => $imagick_avif ? 'imagick' : ( $gd_avif ? 'gd' : '' ),
			'heic_speed'      => $heic_speed,
			'heic_chroma'     => $heic_chroma,
			'can_encode_here' => $can_here,
		];

		set_transient( $cache_key, $this->capabilities, self::CAPABILITIES_TTL );

		return $this->capabilities;
	}

	/**
	 * Identity of the ImageMagick build behind Imagick ('' without the extension): its version
	 * string and a digest of the formats it lists. The version alone does not tell a full build
	 * from the `cli` image's, which has the same ImageMagick without any codec.
	 */
	private function imagick_version_string(): string {
		if ( ! extension_loaded( 'imagick' ) ) {
			return '';
		}

		try {
			$version = \Imagick::getVersion();
			$formats = md5( implode( ',', \Imagick::queryFormats() ) );
		} catch ( \Throwable $e ) {
			return '';
		}

		return (string) ( $version['versionString'] ?? '' ) . '|' . $formats;
	}

	/**
	 * A small noisy picture for the option probes: a flat one would give the same bytes whatever
	 * the encoder settings.
	 */
	private function probe_image(): \Imagick {
		$image = new \Imagick();
		$image->newPseudoImage( 96, 96, 'plasma:fractal' );
		$image->setImageFormat( 'avif' );
		$image->setCompressionQuality( 50 );
		$image->setImageCompressionQuality( 50 );

		return $image;
	}

	/**
	 * Encodes the probe picture with the given heic: options.
	 *
	 * @param array<string, string> $options
	 */
	private function probe_blob( array $options ): string {
		$image = null;
		try {
			$image = $this->probe_image();
			foreach ( $options as $name => $value ) {
				$image->setOption( $name, $value );
			}
			$blob = $image->getImageBlob();

			return is_string( $blob ) ? $blob : '';
		} catch ( \Throwable $e ) {
			return '';
		} finally {
			if ( $image instanceof \Imagick ) {
				$image->clear();
			}
		}
	}

	/**
	 * `heic:chroma=444` really gives a 4:4:4 AVIF (av1C: no chroma subsampling).
	 */
	private function heic_chroma_honoured(): bool {
		$blob = $this->probe_blob( [ 'heic:chroma' => '444' ] );
		$pos  = strpos( $blob, 'av1C' );
		if ( false === $pos || strlen( $blob ) < $pos + 7 ) {
			return false;
		}

		$flags = ord( $blob[ $pos + 6 ] );

		// Bits of the third av1C byte: monochrome (4), subsampling x (3) and y (2).
		return 0 === ( $flags & 0b00001100 );
	}

	/**
	 * `heic:speed` changes the output: two very different speeds give different bytes.
	 */
	private function heic_speed_honoured(): bool {
		$slow = $this->probe_blob( [ 'heic:speed' => '2' ] );
		$fast = $this->probe_blob( [ 'heic:speed' => '9' ] );

		return '' !== $slow && '' !== $fast && $slow !== $fast;
	}

	/**
	 * Imagick decodes JPEG, PNG and AVIF in this process (1x1 pictures built in: the CLI image
	 * has an Imagick that lists formats it cannot read or write).
	 */
	private function imagick_can_decode_here(): bool {
		// 1x1 white JPEG and PNG, generated once with ImageMagick and stripped.
		$jpeg = base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed test picture, not obfuscation.
		$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVQI12NoAAAAggCB3UNq9AAAAABJRU5ErkJggg==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed test picture, not obfuscation.
		$avif = $this->probe_blob( [] );

		foreach ( [ $jpeg, $png, $avif ] as $blob ) {
			if ( ! is_string( $blob ) || '' === $blob ) {
				return false;
			}

			$probe = null;
			try {
				$probe = new \Imagick();
				$probe->readImageBlob( $blob );
				if ( $probe->getImageWidth() < 1 ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				return false;
			} finally {
				if ( $probe instanceof \Imagick ) {
					$probe->clear();
				}
			}
		}

		return true;
	}

	/**
	 * Checks that Imagick can really *encode* a given format, by attempting
	 * to encode a 1x1 image. Works around builds where the format is
	 * registered (queryFormats) but whose encoding delegate is missing/broken.
	 */
	private function imagick_can_encode( string $format ): bool {
		try {
			$probe = new \Imagick();
			$probe->newImage( 1, 1, new \ImagickPixel( 'white' ) );
			$probe->setImageFormat( $format );
			$blob = $probe->getImageBlob();
			$probe->clear();
			$probe->destroy();

			return is_string( $blob ) && '' !== $blob;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Checks that GD can really *encode* a format, with a real in-memory
	 * encoding. imagewebp()/imageavif() always exist in PHP 8.1+ even if
	 * the lib (libwebp/libavif) is not compiled in: only a real encoding settles it.
	 */
	private function gd_can_encode( string $format ): bool {
		$fn = 'webp' === $format ? 'imagewebp' : ( 'avif' === $format ? 'imageavif' : '' );

		if ( '' === $fn || ! function_exists( $fn ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return false;
		}

		$image = imagecreatetruecolor( 4, 4 );
		if ( false === $image ) {
			return false;
		}

		try {
			ob_start();
			$ok   = $fn( $image, null, 80 );
			$blob = ob_get_clean();

			return $ok && is_string( $blob ) && '' !== $blob;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/* ================================================================
	 * UTILITIES
	 * ================================================================ */

	/** Formats that can contain several images. */
	private const ANIMATABLE_MIMES = [ 'image/gif', 'image/webp', 'image/png', 'image/apng', 'image/avif' ];

	/**
	 * Detects whether an image is animated (animated GIF, animated WebP, APNG…).
	 *
	 * The test short-circuits by MIME: a JPEG cannot be animated, and
	 * loading it into Imagick to count its frames — once per size —
	 * read every file entirely for nothing.
	 */
	public function is_animated( string $file_path, string $mime_type ): bool {
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return false;
		}

		if ( '' !== $mime_type && ! in_array( $mime_type, self::ANIMATABLE_MIMES, true ) ) {
			return false;
		}

		$cap = $this->get_capabilities();

		if ( $cap['imagick'] ) {
			$imagick = null;
			try {
				$imagick     = new \Imagick( $file_path );
				$is_animated = $imagick->getNumberImages() > 1;
				return $is_animated;
			} catch ( \Throwable $e ) {
				$this->log_error( 'is_animated/imagick', $file_path, $e );
				return false;
			} finally {
				if ( $imagick instanceof \Imagick ) {
					$imagick->clear();
					$imagick->destroy();
				}
			}
		}

		if ( 'image/gif' === $mime_type ) {
			return $this->is_animated_gif( $file_path );
		}

		return false;
	}

	private function is_animated_gif( string $file_path ): bool {
		// Chunked reading of a local file: WP_Filesystem offers no streamed reading.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$frames = 0;
		while ( ! feof( $handle ) && $frames < 2 ) {
			$chunk = fread( $handle, 1024 * 100 );
			if ( false === $chunk ) {
				break;
			}
			$frames += preg_match_all( '/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $chunk );
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $frames > 1;
	}

	/**
	 * Converts a file name into readable alternative text.
	 * E.g. "my-image-1920x1080" → "My image"
	 */
	public function filename_to_alt( string $filename ): string {
		$alt = preg_replace( '/-\d+x\d+$/', '', $filename );
		$alt = str_replace( [ '-', '_', '.' ], ' ', (string) $alt );
		return ucfirst( trim( $alt ) );
	}

	/**
	 * Logs an image processing error in the PHP log.
	 *
	 * Imagick/GD failures used to be swallowed silently: impossible to
	 * know why an image was not compressed/converted. They are now logged
	 * (grep-able prefix). On hosts that redirect error_log
	 * to stderr, the message shows up directly in the container logs,
	 * even with WP_DEBUG disabled.
	 *
	 * @param string                     $context   Step concerned (e.g. "convert/imagick").
	 * @param string                     $file_path File concerned.
	 * @param \Throwable|\WP_Error|string $error    Exception, WP_Error or raw message.
	 */
	private function log_error( string $context, string $file_path, $error ): void {
		if ( $error instanceof \Throwable ) {
			$message = $error->getMessage();
		} elseif ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
		} else {
			$message = (string) $error;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate error log, no UI to display it.
		error_log(
			sprintf(
				'[LUMIA Image Optimizer] %s failed for %s: %s',
				$context,
				$file_path,
				'' !== $message ? $message : 'unknown error'
			)
		);
	}
}
