<?php
namespace Lumia\Tools\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress media library UI integration for the Image Optimizer (spec 4, 9.5, 9.12, 9.13):
 * the AVIF status column, the details panel of a media item and its two AJAX actions.
 *
 * The JPEG/PNG WordPress produces stays the served file; the panel only reports the state of
 * its `.avif` siblings and lets the user serve the original format for one image, regenerate
 * the AVIF, or get the URL of the original (`?original`, which skips the negotiation).
 *
 * @phpstan-type State array{status: string, queued_at: int, origin: string, gen: int, sizes: array<string, array{bytes: int|null, mtime: int|null, avif_bytes: int|null}>, error: string, attempts: int, updated: int}
 */
class MediaLibrary {

	/** Formats that get AVIF siblings. */
	private const SOURCE_MIMES = [ 'image/jpeg', 'image/png' ];

	/** Formats an uploaded file can keep when it is not converted (spec 9.13). */
	private const MODERN_MIMES = [ 'image/avif', 'image/webp' ];

	/**
	 * Lucide icon bodies (lucide-static v1.34.0, fetched as is).
	 */
	private const ICONS = [
		'copy'       => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
		'download'   => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
		'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
	];

	private Module $module;
	private ImageProcessor $processor;

	public function __construct( Module $module, ImageProcessor $processor ) {
		$this->module    = $module;
		$this->processor = $processor;
	}

	/**
	 * Registers all the media library hooks.
	 */
	public function init(): void {
		add_filter( 'manage_media_columns', [ $this, 'add_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'attachment_fields_to_edit', [ $this, 'add_optimizer_fields' ], 10, 2 );

		add_action( 'wp_ajax_lumia_image_optimizer_media_toggle_original', [ $this, 'ajax_toggle_original' ] );
		add_action( 'wp_ajax_lumia_image_optimizer_media_regenerate', [ $this, 'ajax_regenerate' ] );
	}

	/* ================================================================
	 * MEDIA LIBRARY COLUMN
	 * ================================================================ */

	/**
	 * Adds the "AVIF" column to the media list, right after the title.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$result = [];
		foreach ( $columns as $key => $value ) {
			$result[ $key ] = $value;
			if ( 'title' === $key ) {
				$result['lumia_avif'] = __( 'AVIF', 'lumia-tools' );
			}
		}
		return $result;
	}

	/**
	 * Displays the AVIF status badge in the column.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( 'lumia_avif' !== $column ) {
			return;
		}

		$mime = (string) get_post_mime_type( $post_id );

		if ( strpos( $mime, 'image/' ) !== 0 ) {
			echo '<span class="lumia-badge lumia-badge--inactive">—</span>';
			return;
		}

		[ $label, $variant, $tip ] = $this->badge( $mime, AvifState::get( $post_id ) );

		echo '<span class="lumia-badge lumia-badge--' . esc_attr( $variant ) . '"';
		if ( '' !== $tip ) {
			// Both attributes: `title` is the fallback where the tooltip script is absent.
			echo ' data-lumia-tip="' . esc_attr( $tip ) . '" title="' . esc_attr( $tip ) . '"';
		}
		echo '>' . esc_html( $label ) . '</span>';
	}

	/**
	 * Label, badge variant and tooltip of a media item's AVIF state.
	 *
	 * @param State $state
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function badge( string $mime, array $state ): array {
		$status = $state['status'];
		$error  = $state['error'];

		switch ( $status ) {
			case AvifState::DONE:
			case AvifState::PARTIAL:
				$served  = $this->served_totals( $state['sizes'] );
				$percent = $this->percent( $served['source'], $served['avif'] );
				$label   = $percent > 0
					/* translators: %d: percentage of weight saved by the AVIF files. */
					? sprintf( __( 'AVIF −%d%%', 'lumia-tools' ), $percent )
					: __( 'AVIF', 'lumia-tools' );

				if ( AvifState::PARTIAL === $status ) {
					return [ $label, 'info', __( 'The AVIF exists for some sizes only; the others are served in their original format.', 'lumia-tools' ) ];
				}
				return [ $label, 'success', '' ];

			case AvifState::PENDING:
				return [ __( 'Pending', 'lumia-tools' ), 'info', '' ];

			case AvifState::PROCESSING:
				return [ __( 'Processing', 'lumia-tools' ), 'info', '' ];

			case AvifState::FAILED:
				return [ __( 'Failed', 'lumia-tools' ), 'danger', '' !== $error ? $error : __( 'The AVIF could not be generated.', 'lumia-tools' ) ];

			case AvifState::EXCLUDED:
				return [ __( 'Original format', 'lumia-tools' ), 'inactive', __( 'This image is served in its original format.', 'lumia-tools' ) ];

			case AvifState::SKIPPED:
				return [ __( 'Not applicable', 'lumia-tools' ), 'inactive', '' !== $error ? $error : __( 'No AVIF is served for this image.', 'lumia-tools' ) ];
		}

		// No state: a JPEG/PNG the queue has not seen yet, or a format that never gets an AVIF.
		if ( in_array( $mime, self::SOURCE_MIMES, true ) ) {
			return [ __( 'Not generated', 'lumia-tools' ), 'inactive', __( 'The AVIF has not been generated for this image yet.', 'lumia-tools' ) ];
		}

		return [ __( 'Not applicable', 'lumia-tools' ), 'inactive', '' ];
	}

	/**
	 * Sums over the sizes that have an AVIF sibling ("served" sizes): the fallback weight of
	 * those files and the weight of their AVIF.
	 *
	 * @param array<string, array{bytes: int|null, mtime: int|null, avif_bytes: int|null}> $sizes
	 * @return array{files: int, source: int, avif: int}
	 */
	private function served_totals( array $sizes ): array {
		$totals = [
			'files'  => 0,
			'source' => 0,
			'avif'   => 0,
		];

		foreach ( $sizes as $entry ) {
			if ( null === $entry['avif_bytes'] || null === $entry['bytes'] ) {
				continue;
			}
			++$totals['files'];
			$totals['source'] += $entry['bytes'];
			$totals['avif']   += $entry['avif_bytes'];
		}

		return $totals;
	}

	/**
	 * Weight saved, in percent: 1 - AVIF / source.
	 */
	private function percent( int $source, int $avif ): int {
		return $source > 0 ? (int) round( 100 * ( 1 - $avif / $source ) ) : 0;
	}

	/* ================================================================
	 * ASSETS (upload.php + attachment editor)
	 * ================================================================ */

	/**
	 * Loads the Image Optimizer JS on the media library and editor screens.
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_upload     = 'upload' === $screen->base;
		$is_attachment = 'post' === $screen->base && 'attachment' === $screen->post_type;

		if ( ! $is_upload && ! $is_attachment ) {
			return;
		}

		// Design system for the panel's buttons, toggle and toasts:
		// tokens + components only, like the Media module (reset.css and
		// layout.css have no business on a native WordPress screen).
		wp_enqueue_style( 'lumia-tokens-css', LUMIA_ASSETS_URL . 'admin/css/tokens.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-components-css', LUMIA_ASSETS_URL . 'admin/css/components.css', [ 'lumia-tokens-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-buttons-css', LUMIA_ASSETS_URL . 'admin/css/buttons.css', [ 'lumia-components-css' ], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-notifications-css', LUMIA_ASSETS_URL . 'admin/css/notifications.css', [], LUMIA_VERSION );
		wp_enqueue_style( 'lumia-image-optimizer-media-css', LUMIA_ASSETS_URL . 'admin/css/modules/image-optimizer.css', [ 'lumia-components-css' ], LUMIA_VERSION );
		wp_enqueue_script( 'lumia-admin-js', LUMIA_ASSETS_URL . 'admin/js/admin.js', [], LUMIA_VERSION, true );
		wp_enqueue_script( 'lumia-notifications-js', LUMIA_ASSETS_URL . 'admin/js/notifications.js', [], LUMIA_VERSION, true );

		foreach ( $this->module->get_admin_js() as $index => $script_url ) {
			if ( empty( $script_url ) ) {
				continue;
			}

			$handle = 'lumia-image-optimizer-media-' . $index;

			wp_enqueue_script( $handle, $script_url, [ 'lumia-admin-js', 'lumia-notifications-js' ], LUMIA_VERSION, true );

			// Global data + module-specific i18n.
			$js_data = $this->module->get_admin_js_data();
			$i18n    = $js_data['i18n'] ?? [];

			wp_localize_script(
				$handle,
				'lumiaAdmin',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'lumia_admin_nonce' ),
					'i18n'    => $i18n,
				]
			);
		}
	}

	/* ================================================================
	 * ATTACHMENT EDITOR
	 * ================================================================ */

	/**
	 * Injects the Image Optimizer section into a media item's edit form.
	 *
	 * @param array<string, mixed> $form_fields
	 * @return array<string, mixed>
	 */
	public function add_optimizer_fields( array $form_fields, \WP_Post $post ): array {
		$html = $this->render_panel( $post->ID );

		if ( '' !== $html ) {
			$form_fields['lumia_image_optimizer'] = [
				'label' => __( 'Image Optimizer', 'lumia-tools' ),
				'input' => 'html',
				'html'  => $html,
			];
		}

		return $form_fields;
	}

	/**
	 * Complete panel of a media item (state, weights, actions), '' if it does not apply.
	 * Returned as is by each AJAX action: the JS replaces the panel instead of recomputing
	 * the display.
	 */
	public function render_panel( int $attachment_id ): string {
		$mime  = (string) get_post_mime_type( $attachment_id );
		$state = AvifState::get( $attachment_id );

		// An uploaded AVIF / WebP left as is (animated, conversion off): only the reason.
		if ( in_array( $mime, self::MODERN_MIMES, true ) ) {
			if ( AvifState::SKIPPED !== $state['status'] ) {
				return '';
			}

			return $this->wrap( $attachment_id, $this->status_block( $mime, $state, 0 ) );
		}

		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			return '';
		}

		$status   = $state['status'];
		$excluded = AvifState::EXCLUDED === $status;

		// An animated PNG is served as uploaded: nothing to switch or regenerate.
		if ( AvifState::SKIPPED === $status && 'image/png' === $mime && $this->processor->is_animated( (string) get_attached_file( $attachment_id ), $mime ) ) {
			return $this->wrap( $attachment_id, $this->status_block( $mime, $state, 0 ) );
		}

		$files = count( $this->module->get_lifecycle()->source_files( $attachment_id ) );
		$html  = $this->status_block( $mime, $state, $files )
			. $this->weights_block( $attachment_id, $state )
			. ( DeliveryProbe::is_serving() ? '' : $this->not_served_notice() )
			. $this->toggle_block( $attachment_id, $excluded, $excluded && $this->is_name_excluded( $attachment_id ) )
			. $this->actions_block( $attachment_id, ! in_array( $status, [ AvifState::PENDING, AvifState::PROCESSING, AvifState::EXCLUDED ], true ) );

		return $this->wrap( $attachment_id, $html );
	}

	private function wrap( int $attachment_id, string $html ): string {
		return '<div class="lumia-media-optimizer" data-attachment="' . esc_attr( (string) $attachment_id ) . '">' . $html . '</div>';
	}

	/**
	 * Badge and one sentence about the state.
	 *
	 * @param State $state
	 */
	private function status_block( string $mime, array $state, int $files ): string {
		[ $label, $variant ] = $this->badge( $mime, $state );
		$error               = $state['error'];

		switch ( $state['status'] ) {
			case AvifState::PENDING:
				$text = __( 'Waiting for the AVIF to be generated.', 'lumia-tools' );
				break;
			case AvifState::PROCESSING:
				$text = __( 'The AVIF is being generated.', 'lumia-tools' );
				break;
			case AvifState::DONE:
				$text = __( 'The AVIF is served to browsers that support it. Everyone else gets the original format.', 'lumia-tools' );
				break;
			case AvifState::PARTIAL:
				$served = $this->served_totals( $state['sizes'] );
				/* translators: 1: number of files that have an AVIF, 2: number of files of the image. */
				$text = sprintf( __( 'AVIF generated for %1$d of %2$d files only; the others stay in their original format.', 'lumia-tools' ), $served['files'], max( $files, $served['files'] ) );
				break;
			case AvifState::FAILED:
				$text = '' !== $error
					/* translators: %s: error message. */
					? sprintf( __( 'Generation failed: %s', 'lumia-tools' ), $error )
					: __( 'Generation failed.', 'lumia-tools' );
				break;
			case AvifState::EXCLUDED:
				$text = __( 'This image is served in its original format.', 'lumia-tools' );
				break;
			case AvifState::SKIPPED:
				$text = '' !== $error ? $error : __( 'No AVIF is served for this image.', 'lumia-tools' );
				break;
			default:
				$text = __( 'AVIF not generated yet.', 'lumia-tools' );
		}

		return '<p class="lumia-media-optimizer__status"><span class="lumia-badge lumia-badge--' . esc_attr( $variant ) . '">' . esc_html( $label ) . '</span> <span>' . esc_html( $text ) . '</span></p>';
	}

	/**
	 * Fallback / AVIF weights of the main file and of every served size.
	 *
	 * @param State $state
	 */
	private function weights_block( int $attachment_id, array $state ): string {
		if ( ! in_array( $state['status'], [ AvifState::DONE, AvifState::PARTIAL ], true ) ) {
			return '';
		}

		$served = $this->served_totals( $state['sizes'] );
		if ( 0 === $served['files'] ) {
			return '';
		}

		$main = $state['sizes'][ AvifState::rel( (string) get_attached_file( $attachment_id ) ) ] ?? null;

		$rows = $this->weights_row( __( 'Main file', 'lumia-tools' ), $main['bytes'] ?? null, $main['avif_bytes'] ?? null )
			. $this->weights_row( __( 'Total (all sizes)', 'lumia-tools' ), $served['source'], $served['avif'] );

		return '<table class="lumia-media-optimizer__weights"><thead><tr><th></th>'
			. '<th>' . esc_html__( 'Fallback', 'lumia-tools' ) . '</th>'
			. '<th>' . esc_html__( 'AVIF', 'lumia-tools' ) . '</th>'
			. '<th>' . esc_html__( 'Saved', 'lumia-tools' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
	}

	private function weights_row( string $label, ?int $source, ?int $avif ): string {
		$dash = '—';

		if ( null === $source || null === $avif ) {
			return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( null === $source ? $dash : (string) size_format( $source, 1 ) ) . '</td><td>' . esc_html( $dash ) . '</td><td>' . esc_html( $dash ) . '</td></tr>';
		}

		$percent = $this->percent( $source, $avif );

		return '<tr><th scope="row">' . esc_html( $label ) . '</th>'
			. '<td>' . esc_html( (string) size_format( $source, 1 ) ) . '</td>'
			. '<td>' . esc_html( (string) size_format( $avif, 1 ) ) . '</td>'
			. '<td>' . esc_html( $percent > 0 ? '−' . $percent . '%' : $dash ) . '</td></tr>';
	}

	/**
	 * Reminder shown when the delivery self-test does not allow serving the AVIF.
	 */
	private function not_served_notice(): string {
		return '<p class="lumia-form__help lumia-media-optimizer__warning">'
			. esc_html__( 'AVIF files are not served on this server (the delivery test did not pass), so visitors receive the original format. See the Delivery tab of the module.', 'lumia-tools' )
			. '</p>';
	}

	/**
	 * The "Serve the original format" switch, with the cache caveat of spec 9.12.
	 */
	private function toggle_block( int $attachment_id, bool $excluded, bool $by_name ): string {
		$help = $by_name
			? __( 'The file name ends with an excluded suffix (see the module settings), so this image always keeps its original format.', 'lumia-tools' )
			: __( 'Deletes the AVIF files of this image. A visitor who already has the AVIF in their cache keeps it for up to one year.', 'lumia-tools' );
		$id   = 'lumia-io-original-' . $attachment_id;

		return '<div class="lumia-option lumia-media-optimizer__toggle">'
			. '<div class="lumia-option__content"><label class="lumia-option__label" for="' . esc_attr( $id ) . '">' . esc_html__( 'Serve the original format', 'lumia-tools' ) . '</label>'
			. '<p class="lumia-option__desc">' . esc_html( $help ) . '</p></div>'
			. '<div class="lumia-option__control"><label class="lumia-toggle">'
			. '<input type="checkbox" id="' . esc_attr( $id ) . '" data-lumia-io-toggle' . ( $excluded ? ' checked' : '' ) . ( $by_name ? ' disabled' : '' ) . '>'
			. '<span class="lumia-toggle__slider"></span></label></div>'
			. '</div>';
	}

	/**
	 * Copy / download the original, regenerate.
	 */
	private function actions_block( int $attachment_id, bool $can_regenerate ): string {
		$url = wp_get_attachment_url( $attachment_id );

		$buttons = '';
		if ( $url ) {
			$original = $url . '?original';
			$buttons .= '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-lumia-io-action="copy-url" data-url="' . esc_attr( $original ) . '">'
				. $this->icon( 'copy' ) . esc_html__( 'Copy original URL', 'lumia-tools' ) . '</button>';
			$buttons .= '<a class="lumia-btn lumia-btn--sm lumia-btn--secondary" href="' . esc_url( $original ) . '" download>'
				. $this->icon( 'download' ) . esc_html__( 'Download original', 'lumia-tools' ) . '</a>';
		}
		if ( $can_regenerate ) {
			$buttons .= '<button type="button" class="lumia-btn lumia-btn--sm lumia-btn--secondary" data-lumia-io-action="regenerate">'
				. $this->icon( 'refresh-cw' ) . esc_html__( 'Regenerate AVIF', 'lumia-tools' ) . '</button>';
		}

		return '<div class="lumia-media-optimizer__actions">' . $buttons . '</div>';
	}

	private function icon( string $name ): string {
		return '<svg class="lumia-icon lumia-icon--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[ $name ] . '</svg>';
	}

	/**
	 * True when the file name carries an excluded suffix: the lifecycle excludes the item
	 * again at every metadata save, so the switch cannot turn the AVIF back on.
	 */
	private function is_name_excluded( int $attachment_id ): bool {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : [];

		return $this->module->get_lifecycle()->is_excluded_by_name( (string) ( $metadata['original_image'] ?? $metadata['file'] ?? '' ) );
	}

	/* ================================================================
	 * AJAX: ACTIONS ON A MEDIA ITEM
	 * ================================================================ */

	/**
	 * Serve the original format (`excluded`) or let the AVIF come back (`pending`). The
	 * `enabled` field says which; without it, the current state is flipped.
	 */
	public function ajax_toggle_original(): void {
		$attachment_id = $this->get_request_attachment();
		$excluded      = AvifState::EXCLUDED === AvifState::get( $attachment_id )['status'];

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by get_request_attachment().
		$enabled = isset( $_POST['enabled'] ) ? '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : ! $excluded;

		if ( $enabled === $excluded ) {
			$this->send_panel(
				$attachment_id,
				$excluded ? __( 'This image is already served in its original format.', 'lumia-tools' ) : __( 'The AVIF is already enabled for this image.', 'lumia-tools' ),
				'info'
			);
		}

		if ( $enabled ) {
			$this->exclude( $attachment_id );
			$this->send_panel( $attachment_id, __( 'The original format is now served for this image.', 'lumia-tools' ) );
		}

		if ( $this->is_name_excluded( $attachment_id ) ) {
			wp_send_json_error( __( 'The file name ends with an excluded suffix (see the module settings).', 'lumia-tools' ) );
		}

		$this->queue( $attachment_id );
		$this->send_panel( $attachment_id, __( 'The AVIF will be generated again.', 'lumia-tools' ) );
	}

	/**
	 * Deletes the AVIF files and queues the media item again (not an excluded one).
	 */
	public function ajax_regenerate(): void {
		$attachment_id = $this->get_request_attachment();

		if ( AvifState::EXCLUDED === AvifState::get( $attachment_id )['status'] ) {
			wp_send_json_error( __( 'This image is served in its original format: turn that off first.', 'lumia-tools' ) );
		}

		$this->queue( $attachment_id );
		$this->send_panel( $attachment_id, __( 'The AVIF will be generated again.', 'lumia-tools' ) );
	}

	/**
	 * `excluded`: the generation moves first (an encode in flight discards its result), then
	 * the siblings go, and the recorded fingerprints with them.
	 */
	private function exclude( int $attachment_id ): void {
		AvifState::set_status( $attachment_id, AvifState::EXCLUDED );
		update_post_meta( $attachment_id, AvifState::GEN, AvifState::gen( $attachment_id ) + 1 );

		$this->reset_files( $attachment_id );
	}

	/**
	 * `pending` (origin `manual`, new generation), siblings deleted and fingerprints cleared:
	 * the queue skips a file whose recorded result is still fresh, so nothing is kept, and a
	 * stale AVIF is never served while the new one is encoded. The queue listens to the
	 * `lumia_image_optimizer_enqueued` action.
	 */
	private function queue( int $attachment_id ): void {
		AvifState::enqueue( $attachment_id, 'manual' );
		$this->reset_files( $attachment_id );

		/** This action is documented in includes/Modules/ImageOptimizer/FileLifecycle.php. */
		do_action( 'lumia_image_optimizer_enqueued', $attachment_id );
	}

	/**
	 * Deletes the siblings and records every source file as not encoded yet.
	 */
	private function reset_files( int $attachment_id ): void {
		$lifecycle = $this->module->get_lifecycle();
		$lifecycle->delete_siblings( $attachment_id );

		$unencoded = [];
		foreach ( $lifecycle->source_files( $attachment_id ) as $path ) {
			$unencoded[ AvifState::rel( $path ) ] = [
				'bytes'      => null,
				'mtime'      => null,
				'avif_bytes' => null,
			];
		}
		AvifState::replace_sizes( $attachment_id, $unencoded );
	}

	/**
	 * Checks nonce, capability and media item; returns the ID or answers with an error.
	 */
	private function get_request_attachment(): int {
		check_ajax_referer( 'lumia_admin_nonce', 'nonce' );

		if ( ! current_user_can( Module::get_required_capability() ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'lumia-tools' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( __( 'Invalid ID.', 'lumia-tools' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			wp_send_json_error( __( 'Unsupported format.', 'lumia-tools' ) );
		}

		if ( 'image/png' === $mime && $this->processor->is_animated( (string) get_attached_file( $attachment_id ), $mime ) ) {
			wp_send_json_error( __( 'Animated images are not supported.', 'lumia-tools' ) );
		}

		return $attachment_id;
	}

	/**
	 * @return never
	 */
	private function send_panel( int $attachment_id, string $message, string $type = 'success' ): void {
		wp_send_json_success(
			[
				'html'    => $this->render_panel( $attachment_id ),
				'message' => $message,
				'type'    => $type,
			]
		);
	}
}
