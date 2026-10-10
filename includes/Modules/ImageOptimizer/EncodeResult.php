<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of AvifEncoder::encode() for one file.
 *
 * - DONE    the `.avif` sibling was written. `error` is empty, or a non-fatal note (for instance
 *           "heic:chroma ignored" when the encoder refused an option and the file was encoded
 *           without it).
 * - SKIPPED nothing to serve: unsupported or animated image, AVIF not small enough, colour
 *           profile that cannot be preserved, or stale source. Any previous sibling is removed.
 *           `error` says why.
 * - FAILED  an error (unreadable or corrupt source, encoder failure). `error` is the message.
 */
final class EncodeResult {

	public const DONE    = 'done';
	public const SKIPPED = 'skipped';
	public const FAILED  = 'failed';

	/**
	 * @param string   $status       One of the constants above.
	 * @param int      $source_bytes Size of the JPEG/PNG source.
	 * @param int|null $avif_bytes   Size of the written AVIF (null unless DONE).
	 * @param string   $error        Reason or message, '' when there is nothing to say.
	 */
	public function __construct(
		public string $status,
		public int $source_bytes,
		public ?int $avif_bytes,
		public string $error = ''
	) {
	}
}
