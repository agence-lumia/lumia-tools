<?php
/**
 * Settings template of the Image Optimizer module.
 *
 * Available variables: $instance, $tab, $module_id, $module
 */

defined( 'ABSPATH' ) || exit;

$module_settings = $instance->get_settings();
?>

<form id="lumia-module-form" class="lumia-form lumia-module-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'lumia_save_settings', 'lumia_nonce' ); ?>
	<input type="hidden" name="action" value="lumia_save_settings">
	<input type="hidden" name="lumia_tab" value="<?php echo esc_attr( $tab ); ?>">

	<div class="lumia-tabs" role="tablist" data-lumia-tabs="image_optimizer" aria-label="<?php esc_attr_e( 'Image Optimizer sections', 'lumia-tools' ); ?>">
		<button type="button" class="lumia-tabs__tab is-active" role="tab" data-lumia-tab="settings"><?php esc_html_e( 'Settings', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="delivery"><?php esc_html_e( 'Delivery', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="svg"><?php esc_html_e( 'SVG', 'lumia-tools' ); ?></button>
		<button type="button" class="lumia-tabs__tab" role="tab" data-lumia-tab="bulk"><?php esc_html_e( 'Bulk optimization', 'lumia-tools' ); ?></button>
	</div>

	<div class="lumia-module-form__scroll">

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="settings">

	<!-- Behavior -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Behavior', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'The JPEG or PNG made by WordPress stays the image file, at the same URL. An AVIF version is generated next to it in the background and served to the browsers that accept it.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_optimize_on_upload" class="lumia-option__label"><?php echo esc_html__( 'Optimize on upload', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Queues every new JPEG or PNG for its AVIF version, right after the upload.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_optimize_on_upload"
								name="lumia_module_settings[optimize_on_upload]"
								value="1"
								<?php checked( $module_settings['optimize_on_upload'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_convert_modern_uploads" class="lumia-option__label"><?php echo esc_html__( 'Convert AVIF and WebP uploads', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Some email clients cannot display AVIF or WebP. Animated images are always kept as uploaded.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Turns an uploaded AVIF or WebP into a PNG (transparency, flat colors) or a JPEG that every client can read; its AVIF version then follows as for any other image.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_convert_modern_uploads"
								name="lumia_module_settings[convert_modern_uploads]"
								value="1"
								<?php checked( $module_settings['convert_modern_uploads'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Quality and dimensions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Quality and dimensions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Adjust the AVIF encoding and the maximum dimension of the uploads.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__row">
				<div class="lumia-form__group">
					<label for="lumia_quality" class="lumia-form__label"><?php echo esc_html__( 'AVIF quality (1-100)', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( '70 recommended: sharper than the JPEG made by WordPress, for about 30% fewer bytes.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<input type="number"
							id="lumia_quality"
							name="lumia_module_settings[quality]"
							class="lumia-input lumia-input--sm"
							value="<?php echo esc_attr( $module_settings['quality'] ); ?>"
							min="1"
							max="100">
				</div>

				<div class="lumia-form__group">
					<label for="lumia_speed" class="lumia-form__label"><?php echo esc_html__( 'Encoding speed', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Fast takes less CPU time per image, for heavier files: about 5% for photos, a lot more for PNG images. Balanced is recommended.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<select id="lumia_speed"
							name="lumia_module_settings[speed]"
							class="lumia-select lumia-select--sm">
						<option value="balanced" <?php selected( $module_settings['speed'], 'balanced' ); ?>>
							<?php echo esc_html__( 'Balanced', 'lumia-tools' ); ?>
						</option>
						<option value="fast" <?php selected( $module_settings['speed'], 'fast' ); ?>>
							<?php echo esc_html__( 'Fast', 'lumia-tools' ); ?>
						</option>
					</select>
				</div>
			</div>

			<div class="lumia-form__group">
				<label for="lumia_max_dimension" class="lumia-form__label"><?php echo esc_html__( 'Maximum dimension (px)', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'WordPress scales down larger uploads to this width or height (the original is kept aside). 0 turns the limit off.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="number"
						id="lumia_max_dimension"
						name="lumia_module_settings[max_dimension]"
						class="lumia-input lumia-input--sm"
						value="<?php echo esc_attr( $module_settings['max_dimension'] ); ?>"
						min="0">
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Exclusions -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Exclusions', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Images that keep their original format only.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-form__group">
				<label for="lumia_exclude_suffixes" class="lumia-form__label"><?php echo esc_html__( 'Excluded name suffixes', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Case-insensitive, before the extension: with -noopt, logo-noopt.png and logo-noopt-2.png get no AVIF version. Separate suffixes with commas.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
				<input type="text"
						id="lumia_exclude_suffixes"
						name="lumia_module_settings[exclude_suffixes]"
						class="lumia-input"
						value="<?php echo esc_attr( implode( ', ', (array) $module_settings['exclude_suffixes'] ) ); ?>">
			</div>
		</div>
	</div>

	<div class="lumia-divider"></div>

	<!-- Advanced options -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Advanced options', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Additional options for image processing.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_strip_exif" class="lumia-option__label"><?php echo esc_html__( 'Remove EXIF metadata', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Irreversible: the copyright notice goes with the rest. The color profile is kept. Leave it enabled unless the site publishes author-credited photos.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Removes GPS data, camera details, etc.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_strip_exif"
								name="lumia_module_settings[strip_exif]"
								value="1"
								<?php checked( $module_settings['strip_exif'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_generate_alt" class="lumia-option__label"><?php echo esc_html__( 'Generate alt text', 'lumia-tools' ); ?><?php echo $this->render_help_tip( __( 'Only applies to new uploads and never overwrites alt text that has already been entered. A file name is only worth so much: review it for images that carry meaning.', 'lumia-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Automatically creates the alt text from the file name.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_generate_alt"
								name="lumia_module_settings[generate_alt]"
								value="1"
								<?php checked( $module_settings['generate_alt'], true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="delivery" hidden>

	<!-- Delivery self-test -->
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Delivery', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'The AVIF version is only generated once a test proves that the web server serves it to the browsers that accept it, and the JPEG or PNG to everyone else (email clients, social networks, feeds). The test runs on activation, on every save, every day, and from your browser when this tab opens.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div id="lumia-delivery-status">
				<?php echo \Lumia\Tools\Modules\ImageOptimizer\DeliveryProbe::render_status(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delivery-status.php. ?>
			</div>
			<div class="lumia-form__actions">
				<button type="button" id="lumia-delivery-retest" class="lumia-btn lumia-btn--secondary">
					<svg class="lumia-icon lumia-icon--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
					<?php echo esc_html__( 'Retest', 'lumia-tools' ); ?>
				</button>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="svg" hidden>

	<!-- SVG uploads -->
	<?php $svg_enabled = ! empty( $module_settings['svg_upload'] ); ?>
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'SVG uploads', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'SVG files are XML and may contain malicious code. Once enabled, every uploaded SVG is automatically sanitized (JavaScript, event handlers and external references are removed).', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-option">
				<div class="lumia-option__content">
					<label for="lumia_svg_upload" class="lumia-option__label"><?php echo esc_html__( 'Allow SVG uploads', 'lumia-tools' ); ?></label>
					<p class="lumia-option__desc"><?php echo esc_html__( 'Enables uploading sanitized .svg files to the media library.', 'lumia-tools' ); ?></p>
				</div>
				<div class="lumia-option__control">
					<label class="lumia-toggle">
						<input type="checkbox"
								id="lumia_svg_upload"
								name="lumia_module_settings[svg_upload]"
								value="1"
								data-svg-master
								<?php checked( $svg_enabled, true ); ?>>
						<span class="lumia-toggle__slider"></span>
					</label>
				</div>
			</div>

			<?php $svg_roles = (array) ( $module_settings['svg_roles'] ?? [] ); ?>
			<div class="lumia-svg-roles<?php echo $svg_enabled ? '' : ' is-disabled'; ?>" id="lumia-svg-roles">
				<div class="lumia-svg-roles__head">
					<span class="lumia-svg-roles__title"><?php echo esc_html__( 'Allowed roles', 'lumia-tools' ); ?></span>
					<span class="lumia-svg-roles__hint"><?php echo esc_html__( 'Only these roles will be able to upload SVG files.', 'lumia-tools' ); ?></span>
				</div>
				<div class="lumia-svg-roles__grid">
					<?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
						<label class="lumia-svg-role">
							<span class="lumia-toggle">
								<input type="checkbox"
										name="lumia_module_settings[svg_roles][]"
										value="<?php echo esc_attr( $role_slug ); ?>"
										<?php checked( in_array( $role_slug, $svg_roles, true ), true ); ?>>
								<span class="lumia-toggle__slider"></span>
							</span>
							<span class="lumia-svg-role__name"><?php echo esc_html( translate_user_role( $role_name ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	</div>

	<div class="lumia-tabs__panel" role="tabpanel" data-lumia-tabs-group="image_optimizer" data-lumia-tab-panel="bulk" hidden>

	<!-- Bulk optimization -->
	<?php
	$lumia_bulk    = $instance->get_bulk_snapshot();
	$lumia_percent = $lumia_bulk['total'] > 0 ? (int) floor( 100 * $lumia_bulk['handled'] / $lumia_bulk['total'] ) : 0;
	if ( $lumia_bulk['total'] > 0 ) {
		/* translators: 1: number of images processed, 2: total number of images that went through the queue. */
		$lumia_message = sprintf( __( '%1$s of %2$s images processed', 'lumia-tools' ), number_format_i18n( $lumia_bulk['handled'] ), number_format_i18n( $lumia_bulk['total'] ) );
	} else {
		$lumia_message = __( 'No image has been queued yet.', 'lumia-tools' );
	}
	$lumia_tiles = [
		'pending'    => [ __( 'Pending', 'lumia-tools' ), __( 'Waiting for the queue.', 'lumia-tools' ) ],
		'processing' => [ __( 'Processing', 'lumia-tools' ), __( 'Being encoded right now.', 'lumia-tools' ) ],
		'done'       => [ __( 'Done', 'lumia-tools' ), __( 'The AVIF exists for every size.', 'lumia-tools' ) ],
		'partial'    => [ __( 'Partial', 'lumia-tools' ), __( 'The AVIF exists for some sizes only; the others are served in their original format.', 'lumia-tools' ) ],
		'skipped'    => [ __( 'Skipped', 'lumia-tools' ), __( 'No AVIF is lighter than the original, or the format cannot be converted (animated images).', 'lumia-tools' ) ],
		'failed'     => [ __( 'Failed', 'lumia-tools' ), __( 'The AVIF could not be generated. Start tries again.', 'lumia-tools' ) ],
		'excluded'   => [ __( 'Excluded', 'lumia-tools' ), __( 'Served in their original format: the switch of the media item, or an excluded name suffix.', 'lumia-tools' ) ],
	];
	?>
	<div class="lumia-section">
		<div class="lumia-section__header">
			<h2 class="lumia-section__title"><?php echo esc_html__( 'Bulk optimization', 'lumia-tools' ); ?></h2>
			<p class="lumia-section__desc"><?php echo esc_html__( 'Generates the AVIF version of the images already in the media library, in the background: you can leave this page, the queue keeps working.', 'lumia-tools' ); ?></p>
		</div>
		<div class="lumia-section__content">
			<div class="lumia-bulk" id="lumia-bulk">

				<p class="lumia-bulk__unserved" data-lumia-bulk-unserved<?php echo $lumia_bulk['serving'] ? ' hidden' : ''; ?>>
					<span class="lumia-badge lumia-badge--warning"><?php echo esc_html__( 'Not served', 'lumia-tools' ); ?></span>
					<?php echo esc_html__( 'The AVIF versions are not served by this server (see the Delivery tab): nothing would be generated.', 'lumia-tools' ); ?>
				</p>

				<div class="lumia-bulk__progress">
					<div class="lumia-progress" id="lumia-bulk-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $lumia_percent ); ?>" aria-label="<?php esc_attr_e( 'Bulk optimization progress', 'lumia-tools' ); ?>">
						<div class="lumia-progress__bar" style="width: <?php echo esc_attr( (string) $lumia_percent ); ?>%"></div>
					</div>
					<span class="lumia-bulk-status__message" id="lumia-bulk-message" aria-live="polite"><?php echo esc_html( $lumia_message ); ?></span>
				</div>

				<div class="lumia-bulk__tiles">
					<?php foreach ( $lumia_tiles as $lumia_status => $lumia_tile ) : ?>
						<div class="lumia-bulk__stat lumia-bulk__stat--<?php echo esc_attr( $lumia_status ); ?>" data-lumia-bulk-status="<?php echo esc_attr( $lumia_status ); ?>" data-lumia-tip="<?php echo esc_attr( $lumia_tile[1] ); ?>">
							<span class="lumia-bulk__stat-value<?php echo $lumia_bulk['counts'][ $lumia_status ] > 0 ? ' is-nonzero' : ''; ?>"><?php echo esc_html( number_format_i18n( $lumia_bulk['counts'][ $lumia_status ] ) ); ?></span>
							<span class="lumia-bulk__stat-label"><?php echo esc_html( $lumia_tile[0] ); ?></span>
						</div>
					<?php endforeach; ?>
					<div class="lumia-bulk__stat lumia-bulk__stat--untouched" data-lumia-bulk-untouched data-lumia-tip="<?php esc_attr_e( 'Images with no AVIF yet. Run a scan to count them.', 'lumia-tools' ); ?>">
						<span class="lumia-bulk__stat-value">&mdash;</span>
						<span class="lumia-bulk__stat-label"><?php echo esc_html__( 'Not queued yet', 'lumia-tools' ); ?></span>
					</div>
				</div>

				<div class="lumia-bulk__actions">
					<button type="button" id="lumia-bulk-scan" class="lumia-btn lumia-btn--secondary">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>
						<span class="lumia-btn__label"><?php echo esc_html__( 'Scan the media library', 'lumia-tools' ); ?></span>
					</button>
					<button type="button" id="lumia-bulk-start" class="lumia-btn lumia-btn--primary"<?php echo ( ! $lumia_bulk['serving'] || $lumia_bulk['active'] ) ? ' disabled' : ''; ?>>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg>
						<span class="lumia-btn__label"><?php echo esc_html( $lumia_bulk['active'] ? __( 'Optimizing…', 'lumia-tools' ) : __( 'Start optimization', 'lumia-tools' ) ); ?></span>
					</button>
					<button type="button" id="lumia-bulk-stop" class="lumia-btn lumia-btn--secondary"<?php echo $lumia_bulk['counts']['pending'] > 0 ? '' : ' disabled'; ?>>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="18" height="18" x="3" y="3" rx="2"/></svg>
						<span class="lumia-btn__label"><?php echo esc_html__( 'Stop', 'lumia-tools' ); ?></span>
					</button>
				</div>
				<p class="lumia-form__help"><?php echo esc_html__( 'Scan first checks every finished image against its file (an image changed by FTP or another tool is queued again), then counts what has no AVIF yet. Start queues those images and the failed ones; Stop takes the waiting ones back out.', 'lumia-tools' ); ?></p>
			</div>
		</div>
	</div>
	</div>

	</div><!-- .lumia-module-form__scroll -->
</form>
