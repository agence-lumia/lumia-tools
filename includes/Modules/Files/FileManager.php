<?php
namespace Lumia\Tools\Modules\Files;

defined( 'ABSPATH' ) || exit;

use Lumia\Tools\Modules\ImageOptimizer\FileLifecycle;

// File manager: direct PHP calls are intended. WP_Filesystem may go through
// FTP/SSH, under a different user than PHP: displayed permissions (is_writable)
// and actual operations would diverge. See docs/modules/files.md.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * File operations engine, strictly limited to a root directory.
 * Any attempt to leave the root throws an InvalidArgumentException.
 */
class FileManager {

	private string $root;

	public function __construct( string $root ) {
		$real = realpath( $root );
		if ( false === $real ) {
			// Not translated: the only caller passes ABSPATH, outside any try/catch.
			// This is a fatal configuration error for the developer, never a toast.
			throw new \InvalidArgumentException( 'Root path does not exist: ' . esc_html( $root ) );
		}
		$this->root = rtrim( $real, DIRECTORY_SEPARATOR );
	}

	public function get_root(): string {
		return $this->root;
	}

	/* ================================================================
	 * PATH RESOLUTION
	 * ================================================================ */

	/**
	 * Resolves a relative path into a validated absolute one (the file/folder must exist).
	 */
	public function resolve( string $rel ): string {
		$rel = str_replace( "\0", '', $rel );

		if ( '' === $rel || '.' === $rel || '/' === $rel ) {
			return $this->root;
		}

		$norm = $this->normalize( $rel );
		$abs  = realpath( $this->root . DIRECTORY_SEPARATOR . $norm );

		if ( false === $abs ) {
			throw new \InvalidArgumentException( __( 'Path does not exist.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		if ( $abs !== $this->root && strpos( $abs, $this->root . DIRECTORY_SEPARATOR ) !== 0 ) {
			throw new \InvalidArgumentException( __( 'Path is outside root.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		return $abs;
	}

	/**
	 * Resolves a path for a file that does not exist yet (creation/upload).
	 */
	private function resolve_new( string $rel ): string {
		$rel  = str_replace( "\0", '', $rel );
		$norm = $this->normalize( $rel );

		if ( '' === $norm ) {
			throw new \InvalidArgumentException( __( 'Invalid path.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$abs = $this->root . DIRECTORY_SEPARATOR . $norm;

		// Check that the parent exists and is inside the root.
		$parent = realpath( dirname( $abs ) );
		if ( false === $parent || ( $this->root !== $parent && strpos( $parent, $this->root . DIRECTORY_SEPARATOR ) !== 0 ) ) {
			throw new \InvalidArgumentException( __( 'Parent directory is outside root or does not exist.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		return $abs;
	}

	/**
	 * Normalizes a relative path: removes empty segments, '.', '..'.
	 */
	private function normalize( string $rel ): string {
		$parts = preg_split( '#[/\\\\]#', $rel );
		if ( false === $parts ) {
			$parts = [];
		}
		$clean = [];
		foreach ( $parts as $p ) {
			if ( '' === $p || '.' === $p ) {
				continue;
			}
			if ( '..' === $p ) {
				array_pop( $clean );
				continue;
			}
			$clean[] = $p;
		}
		return implode( DIRECTORY_SEPARATOR, $clean );
	}

	/**
	 * Returns the relative path from an absolute path.
	 */
	public function to_relative( string $abs ): string {
		if ( $abs === $this->root ) {
			return '';
		}
		if ( strpos( $abs, $this->root . DIRECTORY_SEPARATOR ) !== 0 ) {
			throw new \InvalidArgumentException( __( 'Path is outside root.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		return ltrim( str_replace( '\\', '/', substr( $abs, strlen( $this->root ) ) ), '/' );
	}

	/* ================================================================
	 * LISTING
	 * ================================================================ */

	/**
	 * Lists a directory's contents. Returns folders first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_directory( string $rel ): array {
		$abs = $this->resolve( $rel );

		if ( ! is_dir( $abs ) ) {
			throw new \InvalidArgumentException( __( 'Not a directory.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$entries = scandir( $abs );
		if ( false === $entries ) {
			throw new \RuntimeException( __( 'Cannot open directory.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$items = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full     = $abs . DIRECTORY_SEPARATOR . $entry;
			$is_dir   = is_dir( $full );
			$ext      = $is_dir ? '' : strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			$rel_item = ( '' === $rel || '/' === $rel )
				? $entry
				: rtrim( str_replace( '\\', '/', $rel ), '/' ) . '/' . $entry;

			$items[] = [
				'name'     => $entry,
				'path'     => $rel_item,
				'type'     => $is_dir ? 'dir' : 'file',
				'ext'      => $ext,
				// A file that became unreadable between scandir() and here only raises a PHP warning: we read "best effort".
				'size'     => $is_dir ? null : @filesize( $full ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'modified' => @filemtime( $full ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'perms'    => substr( sprintf( '%o', @fileperms( $full ) ), -4 ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				'owner'    => $this->get_owner( $full ),
				'writable' => is_writable( $full ),
			];
		}

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $items;
	}

	/* ================================================================
	 * OPERATIONS
	 * ================================================================ */

	public function delete( string $rel ): bool {
		$abs = $this->resolve( $rel );
		if ( is_dir( $abs ) ) {
			return $this->delete_dir( $abs );
		}
		$sibling = $this->avif_sibling( $abs );
		if ( ! unlink( $abs ) ) {
			return false;
		}
		if ( '' !== $sibling ) {
			unlink( $sibling );
		}
		return true;
	}

	/**
	 * The `.avif` sibling the Image Optimizer serves in place of a JPEG/PNG of the uploads
	 * folder ('' when there is none): it follows the file it stands for, otherwise a deleted
	 * or renamed image would keep being served under its old URL, or a new file with that
	 * name would show the old picture.
	 */
	private function avif_sibling( string $abs ): string {
		if ( ! preg_match( '/\.(?:jpe?g|png)$/i', $abs ) || '' === $this->uploads_path( $abs ) ) {
			return '';
		}

		$sibling = FileLifecycle::sibling( $abs );

		return is_file( $sibling ) ? $sibling : '';
	}

	/**
	 * Moves the sibling of a renamed or moved file (deleted when the new name is not a
	 * JPEG/PNG of the uploads folder any more).
	 */
	private function follow_avif_sibling( string $sibling, string $new_abs ): void {
		if ( '' === $sibling ) {
			// The source has no sibling: an orphan one at the new name (left by a file deleted
			// outside WordPress) would be served in place of the moved image.
			$this->drop_avif_sibling( $new_abs );
			return;
		}
		if ( preg_match( '/\.(?:jpe?g|png)$/i', $new_abs ) && '' !== $this->uploads_path( $new_abs ) ) {
			rename( $sibling, FileLifecycle::sibling( $new_abs ) );
			return;
		}
		unlink( $sibling );
	}

	/**
	 * Deletes the `.avif` sibling of a JPEG/PNG of the uploads folder whose content was just
	 * replaced (upload, archive extraction, editor): the AVIF of the former picture would
	 * otherwise be served in its place. The Image Optimizer's fingerprint check queues the
	 * media item again.
	 */
	private function drop_avif_sibling( string $abs ): void {
		$sibling = $this->avif_sibling( $abs );
		if ( '' !== $sibling ) {
			unlink( $sibling );
		}
	}

	/**
	 * $abs when it lies under the uploads folder, '' otherwise.
	 */
	private function uploads_path( string $abs ): string {
		$uploads = realpath( wp_upload_dir()['basedir'] );

		return false !== $uploads && strpos( $abs, $uploads . DIRECTORY_SEPARATOR ) === 0 ? $abs : '';
	}

	private function delete_dir( string $dir ): bool {
		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $item ) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			is_dir( $path ) ? $this->delete_dir( $path ) : unlink( $path );
		}
		return rmdir( $dir );
	}

	public function rename( string $rel, string $new_name ): bool {
		$new_name = sanitize_file_name( $new_name );
		if ( '' === $new_name ) {
			throw new \InvalidArgumentException( __( 'Invalid name.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$abs     = $this->resolve( $rel );
		$new_abs = dirname( $abs ) . DIRECTORY_SEPARATOR . $new_name;

		if ( strpos( $new_abs, $this->root ) !== 0 ) {
			throw new \InvalidArgumentException( __( 'Invalid path.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		if ( file_exists( $new_abs ) ) {
			throw new \RuntimeException( __( 'A file with that name already exists.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$sibling = is_file( $abs ) ? $this->avif_sibling( $abs ) : '';
		if ( ! rename( $abs, $new_abs ) ) {
			return false;
		}
		$this->follow_avif_sibling( $sibling, $new_abs );
		return true;
	}

	public function move( string $src_rel, string $dst_rel ): bool {
		$src     = $this->resolve( $src_rel );
		$dst_dir = $this->resolve( $dst_rel );

		if ( ! is_dir( $dst_dir ) ) {
			throw new \InvalidArgumentException( __( 'Destination is not a directory.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$dst = $dst_dir . DIRECTORY_SEPARATOR . basename( $src );

		if ( strpos( $dst, $this->root ) !== 0 ) {
			throw new \InvalidArgumentException( __( 'Invalid destination.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		if ( file_exists( $dst ) ) {
			throw new \RuntimeException( __( 'A file with that name already exists at destination.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$sibling = is_file( $src ) ? $this->avif_sibling( $src ) : '';
		if ( ! rename( $src, $dst ) ) {
			return false;
		}
		$this->follow_avif_sibling( $sibling, $dst );
		return true;
	}

	public function create_folder( string $rel ): bool {
		$abs = $this->resolve_new( $rel );
		if ( file_exists( $abs ) ) {
			throw new \RuntimeException( __( 'A file or folder with that name already exists.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		return wp_mkdir_p( $abs );
	}

	public function get_content( string $rel ): string {
		$abs = $this->resolve( $rel );
		if ( ! is_file( $abs ) ) {
			throw new \InvalidArgumentException( __( 'Not a file.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		$content = file_get_contents( $abs );
		if ( false === $content ) {
			throw new \RuntimeException( __( 'Cannot read file.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		return $content;
	}

	public function save_content( string $rel, string $content ): bool {
		$abs = $this->resolve( $rel );
		if ( ! is_file( $abs ) ) {
			throw new \InvalidArgumentException( __( 'Not a file.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		if ( ! is_writable( $abs ) ) {
			/* translators: %s: relative file path */
			throw new \RuntimeException( sprintf( __( 'File is read-only: %s', 'lumia-tools' ), $rel ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		// The failure must bubble up: without an exception, the caller announces
		// a successful save although nothing was written to disk.
		if ( file_put_contents( $abs, $content ) === false ) {
			/* translators: %s: relative file path */
			throw new \RuntimeException( sprintf( __( 'Write failed: %s', 'lumia-tools' ), $rel ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		$this->drop_avif_sibling( $abs );

		return true;
	}

	/**
	 * Creates a ZIP archive. Returns the absolute path of the created zip.
	 *
	 * @param string[] $rel_paths Relative paths to archive.
	 */
	public function create_zip( array $rel_paths, string $dest_rel ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( __( 'ZipArchive is not available on this server.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$dest = $this->resolve_new( $dest_rel );
		$zip  = new \ZipArchive();

		if ( $zip->open( $dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			throw new \RuntimeException( __( 'Could not create the archive.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		foreach ( $rel_paths as $rel ) {
			$abs = $this->resolve( $rel );
			if ( is_dir( $abs ) ) {
				$this->zip_add_dir( $zip, $abs, basename( $abs ) );
			} else {
				$zip->addFile( $abs, basename( $abs ) );
			}
		}

		$zip->close();
		return $dest;
	}

	private function zip_add_dir( \ZipArchive $zip, string $dir, string $base ): void {
		$zip->addEmptyDir( $base );
		foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $item ) {
			$abs = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $abs ) ) {
				$this->zip_add_dir( $zip, $abs, $base . '/' . $item );
			} else {
				$zip->addFile( $abs, $base . '/' . $item );
			}
		}
	}

	public function extract_zip( string $rel ): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( __( 'ZipArchive is not available on this server.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		$abs  = $this->resolve( $rel );
		$dest = dirname( $abs );
		$zip  = new \ZipArchive();

		if ( $zip->open( $abs ) !== true ) {
			throw new \RuntimeException( __( 'Could not open the archive.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		// ZipArchive::extractTo() does not neutralize "../x" entries nor absolute
		// paths (outside open_basedir): a forged archive would write outside
		// the root this manager promises never to leave. We reject the whole
		// archive rather than extract part of it.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! $this->is_safe_zip_entry( $name ) ) {
				$zip->close();
				/* translators: %s: archive entry name */
				throw new \RuntimeException( sprintf( __( 'Archive rejected: entry outside the root (%s).', 'lumia-tools' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
			}
			// A symbolic link with an innocuous name can point outside the root;
			// a following entry would then write THROUGH the link. We reject
			// any link rather than follow its target.
			if ( $this->zip_entry_is_symlink( $zip, $i ) ) {
				$zip->close();
				/* translators: %s: archive entry name */
				throw new \RuntimeException( sprintf( __( 'Archive rejected: symbolic link (%s).', 'lumia-tools' ), $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
			}
		}

		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}

		$zip->extractTo( $dest );
		$zip->close();

		// A JPEG/PNG the archive wrote over (or next to an orphan sibling) loses its AVIF.
		foreach ( $names as $name ) {
			$this->drop_avif_sibling( $dest . DIRECTORY_SEPARATOR . str_replace( [ '/', '\\' ], DIRECTORY_SEPARATOR, $name ) );
		}
		return true;
	}

	/**
	 * Is the entry a symbolic link (Unix external attributes, S_IFLNK mode)?
	 */
	private function zip_entry_is_symlink( \ZipArchive $zip, int $index ): bool {
		$opsys = 0;
		$attr  = 0;
		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}
		return \ZipArchive::OPSYS_UNIX === $opsys && 0xA000 === ( ( $attr >> 16 ) & 0xF000 );
	}

	/**
	 * Does an archive entry stay under the extraction folder?
	 */
	private function is_safe_zip_entry( string $name ): bool {
		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			return false;
		}
		// Absolute path (POSIX, Windows) or UNC.
		if ( '/' === $name[0] || '\\' === $name[0] || preg_match( '#^[a-zA-Z]:#', $name ) ) {
			return false;
		}
		$segments = preg_split( '#[/\\\\]#', $name );
		foreach ( false === $segments ? [] : $segments as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $file Entry of $_FILES.
	 */
	public function upload( string $dir_rel, array $file ): string {
		$dir  = $this->resolve( $dir_rel );
		$name = sanitize_file_name( $file['name'] );
		$dest = $dir . DIRECTORY_SEPARATOR . $name;

		if ( strpos( $dest, $this->root ) !== 0 ) {
			throw new \InvalidArgumentException( __( 'Invalid destination.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			throw new \RuntimeException( __( 'Upload failed.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message returned as JSON, escaped on display by the toast.
		}
		$this->drop_avif_sibling( $dest );

		return $this->to_relative( $dest );
	}

	/* ================================================================
	 * UTILITIES
	 * ================================================================ */

	private function get_owner( string $path ): string {
		if ( ! function_exists( 'posix_getpwuid' ) || ! function_exists( 'posix_getgrgid' ) ) {
			return '';
		}
		// fileowner()/filegroup() emit a warning on an inaccessible file: the failure is handled right after.
		$uid = @fileowner( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$gid = @filegroup( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $uid || false === $gid ) {
			return '';
		}
		$uinfo = posix_getpwuid( $uid );
		$ginfo = posix_getgrgid( $gid );
		$u     = $uinfo ? $uinfo['name'] : (string) $uid;
		$g     = $ginfo ? $ginfo['name'] : (string) $gid;
		return $u . ':' . $g;
	}

	public static function format_size( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		if ( $bytes < 1073741824 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}
		return round( $bytes / 1073741824, 2 ) . ' GB';
	}
}
